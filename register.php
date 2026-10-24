<?php
session_start();
require_once 'config/connect.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    // ---------- Send OTP ----------
    if ($action === 'send_otp') {
        $firstName = trim($_POST['firstName'] ?? '');
        $lastName  = trim($_POST['lastName'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $contact   = trim($_POST['contact'] ?? '');
        $password  = $_POST['password'] ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';
        
        $errors = [];
        if (empty($firstName)) $errors[] = 'First name is required.';
        if (empty($lastName)) $errors[] = 'Last name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (empty($contact)) $errors[] = 'Contact number is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';
        
        $stmt = $conn->prepare("SELECT id FROM accounts WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = 'Email already registered. Please log in.';
        }
        $stmt->close();
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }
        
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $_SESSION['pending_registration'] = [
            'firstname' => $firstName,
            'lastname'  => $lastName,
            'email'     => $email,
            'contact'   => $contact,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'otp'       => $otp,
            'expires'   => time() + 120 // 2 minutes, aligned with JS timer
        ];
        $_SESSION['last_otp_sent'] = time();
        
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'buyawanongplacer@gmail.com';
            $mail->Password   = 'fsiqtfjcpjjizquc';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom('buyawanongplacer@gmail.com', 'Buyawanong Placer');
            $mail->addAddress($email, "$firstName $lastName");
            $mail->isHTML(true);
            $mail->Subject = 'Verify your account - Buyawanong Placer';
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto;'>
                    <h2 style='color: #00c389;'>Email Verification</h2>
                    <p>Hello <strong>$firstName $lastName</strong>,</p>
                    <p>Your one-time password (OTP) for registering at <strong>Buyawanong Placer</strong> is:</p>
                    <div style='background: #f0fcf7; padding: 15px; text-align: center; font-size: 28px; letter-spacing: 5px; border-radius: 12px; border: 1px solid #00c389;'>
                        <strong style='color: #00c389;'>$otp</strong>
                    </div>
                    <p>This code is valid for <strong>2 minutes</strong>.</p>
                    <p>If you did not request this, please ignore this email.</p>
                    <hr>
                    <small>Municipality of Placer, Surigao del Norte</small>
                </div>
            ";
            $mail->AltBody = "Your OTP is: $otp. Valid for 2 minutes.";
            $mail->send();
            echo json_encode(['success' => true, 'message' => 'OTP sent to your email.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'errors' => ['Failed to send email: ' . $mail->ErrorInfo]]);
        }
        exit;
    }
    
    // ---------- Resend OTP ----------
    if ($action === 'resend_otp') {
        if (!isset($_SESSION['pending_registration'])) {
            echo json_encode(['success' => false, 'errors' => ['No pending registration. Please start over.']]);
            exit;
        }
        
        $pending = $_SESSION['pending_registration'];
        
        // 30-second cooldown check
        if (isset($_SESSION['last_otp_sent']) && (time() - $_SESSION['last_otp_sent']) < 30) {
            $wait = 30 - (time() - $_SESSION['last_otp_sent']);
            echo json_encode(['success' => false, 'errors' => ["Please wait {$wait} seconds before requesting another OTP."]]);
            exit;
        }
        
        // Generate new OTP and reset expiry
        $newOtp = sprintf("%06d", mt_rand(1, 999999));
        $_SESSION['pending_registration']['otp'] = $newOtp;
        $_SESSION['pending_registration']['expires'] = time() + 120;
        $_SESSION['last_otp_sent'] = time();
        
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'buyawanongplacer@gmail.com';
            $mail->Password   = 'fsiqtfjcpjjizquc';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom('buyawanongplacer@gmail.com', 'Buyawanong Placer');
            $mail->addAddress($pending['email'], $pending['firstname'] . ' ' . $pending['lastname']);
            $mail->isHTML(true);
            $mail->Subject = 'Your new OTP - Buyawanong Placer';
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto;'>
                    <h2 style='color: #00c389;'>New Verification Code</h2>
                    <p>Hello <strong>{$pending['firstname']} {$pending['lastname']}</strong>,</p>
                    <p>Here is your new one-time password (OTP):</p>
                    <div style='background: #f0fcf7; padding: 15px; text-align: center; font-size: 28px; letter-spacing: 5px; border-radius: 12px; border: 1px solid #00c389;'>
                        <strong style='color: #00c389;'>$newOtp</strong>
                    </div>
                    <p>This code is valid for <strong>2 minutes</strong>.</p>
                    <hr>
                    <small>Municipality of Placer, Surigao del Norte</small>
                </div>
            ";
            $mail->AltBody = "Your new OTP is: $newOtp. Valid for 2 minutes.";
            $mail->send();
            echo json_encode(['success' => true, 'message' => 'A new OTP has been sent to your email.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'errors' => ['Failed to send email: ' . $mail->ErrorInfo]]);
        }
        exit;
    }
    
    // ---------- Verify OTP ----------
    if ($action === 'verify_otp') {
        $userOtp = trim($_POST['otp'] ?? '');
        if (!isset($_SESSION['pending_registration'])) {
            echo json_encode(['success' => false, 'errors' => ['No pending registration. Please start over.']]);
            exit;
        }
        $pending = $_SESSION['pending_registration'];
        if (time() > $pending['expires']) {
            unset($_SESSION['pending_registration']);
            echo json_encode(['success' => false, 'errors' => ['OTP expired. Please register again.']]);
            exit;
        }
        if ($userOtp !== $pending['otp']) {
            echo json_encode(['success' => false, 'errors' => ['Invalid OTP. Please try again.']]);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO accounts (firstname, lastname, email, contact_number, password, user_type) VALUES (?, ?, ?, ?, ?, ?)");
        $user_type = 1;
        $stmt->bind_param("sssssi", $pending['firstname'], $pending['lastname'], $pending['email'], $pending['contact'], $pending['password'], $user_type);
        if ($stmt->execute()) {
            $_SESSION['user_id'] = $stmt->insert_id;
            $_SESSION['user_name'] = $pending['firstname'] . ' ' . $pending['lastname'];
            $_SESSION['user_email'] = $pending['email'];
            unset($_SESSION['pending_registration']);
            unset($_SESSION['last_otp_sent']);
            echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
        } else {
            echo json_encode(['success' => false, 'errors' => ['Database error. Please try again later.']]);
        }
        $stmt->close();
        exit;
    }
    echo json_encode(['success' => false, 'errors' => ['Invalid request.']]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Buyawanong Placer | Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-green: #00c389;
            --primary-green-dark: #00996e;
            --primary-green-light: #e6f9f2;
            --primary-green-pale: #f0fcf7;
            --dark-blue: #172233;
            --dark-blue-light: #1e2d41;
            --white: #ffffff;
            --bs-primary: var(--primary-green);
            --bs-primary-rgb: 0, 195, 137;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .register-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .register-card {
            border-radius: 1.25rem;
            background: white;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0,0,0,0.04);
            padding: 2rem;
            width: 100%;
            max-width: 540px;
            margin: 0 auto;
            border-top: 6px solid var(--primary-green);
        }

        .logo-img {
            max-height: 70px;
            width: auto;
        }

        .btn-register {
            background: var(--primary-green);
            border: none;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 2rem;
            color: white;
            transition: all 0.2s ease;
            width: 100%;
        }
        .btn-register:hover {
            background: var(--primary-green-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(0, 195, 137, 0.25);
        }

        .btn-verify {
            background: var(--primary-green);
            border: none;
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            font-size: 0.9rem;
            border-radius: 2rem;
            color: white;
            transition: all 0.2s ease;
        }
        .btn-verify:hover {
            background: var(--primary-green-dark);
        }

        .btn-outline-verify {
            background: white;
            border: 1px solid var(--primary-green);
            color: var(--primary-green);
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            font-size: 0.9rem;
            border-radius: 2rem;
            transition: all 0.2s ease;
        }
        .btn-outline-verify:hover {
            background: var(--primary-green);
            color: white;
        }

        .form-control {
            border-radius: 0.75rem;
            padding: 0.7rem 1rem;
            border: 1px solid #cfdfea;
            background: #fefefe;
            font-size: 0.95rem;
        }
        .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 195, 137, 0.2);
            outline: none;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--dark-blue);
            margin-bottom: 0.4rem;
        }

        .municipal-badge {
            background: var(--primary-green-pale);
            color: var(--dark-blue);
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            display: inline-block;
        }

        .login-link {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 600;
        }
        .login-link:hover {
            text-decoration: underline;
            color: var(--primary-green-dark);
        }

        .info-note {
            background: var(--primary-green-pale);
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            color: var(--dark-blue);
            border-left: 4px solid var(--primary-green);
        }

        .divider-leaf {
            width: 50px;
            height: 3px;
            background: var(--primary-green);
            opacity: 0.6;
            margin: 0.5rem auto 0;
            border-radius: 3px;
        }

        .terms-text {
            font-size: 0.75rem;
            color: #5e7e9c;
        }
        .terms-link {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 500;
        }
        .terms-link:hover {
            text-decoration: underline;
            color: var(--primary-green-dark);
        }

        .otp-input-group {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            margin: 1.5rem 0;
        }
        .otp-digit {
            width: 55px;
            height: 65px;
            text-align: center;
            font-size: 1.8rem;
            font-weight: 700;
            border: 2px solid #cfdfea;
            border-radius: 1rem;
            background: white;
            transition: all 0.2s;
        }
        .otp-digit:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 195, 137, 0.2);
            outline: none;
        }

        .timer-text {
            font-size: 0.85rem;
            color: #5e7e9c;
        }
        .resend-link {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
            padding: 0.35rem 0.9rem;
            border-radius: 2rem;
            border: 1px solid var(--primary-green);
            transition: all 0.2s ease;
        }
        .resend-link:hover {
            background: var(--primary-green);
            color: white;
        }
        .resend-link.disabled {
            pointer-events: none;
            opacity: 0.55;
            border-color: #cfdfea;
            color: #8aa3b8;
        }

        /* Terms modal content */
        .terms-content {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }
        .terms-content h5 {
            color: var(--dark-blue);
            margin-top: 0.5rem;
        }
        .terms-content ol {
            color: #5e7e9c;
            line-height: 1.8;
            padding-left: 1.2rem;
        }
        .terms-content ol li {
            margin-bottom: 0.3rem;
        }

        .footer-bg {
            background: var(--dark-blue);
            color: #fff;
        }
        .footer-bg a {
            color: rgba(255,255,255,.7);
            text-decoration: none;
        }
        .footer-bg a:hover {
            color: var(--primary-green);
        }

        @media (max-width: 576px) {
            .register-card {
                padding: 1.5rem;
            }
            .register-wrapper {
                padding: 1rem;
            }
            .otp-digit {
                width: 45px;
                height: 55px;
                font-size: 1.5rem;
            }
            .otp-input-group {
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>

<script>
    let timerInterval = null;
    let timeLeft = 120;
    let resendCooldownInterval = null;
    let currentEmail = '';

    async function postJSON(action, formDataObj) {
        const formBody = new URLSearchParams();
        formBody.append('action', action);
        for (const [key, value] of Object.entries(formDataObj)) {
            formBody.append(key, value);
        }
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formBody
        });
        return await response.json();
    }

    function setupOtpInputs() {
        const inputs = ['otp1','otp2','otp3','otp4','otp5','otp6'];
        inputs.forEach((id, idx) => {
            const inp = document.getElementById(id);
            inp.addEventListener('input', function(e) {
                if (this.value.length === 1 && idx < 5) document.getElementById(inputs[idx+1]).focus();
            });
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && this.value.length === 0 && idx > 0) document.getElementById(inputs[idx-1]).focus();
            });
        });
    }

    function startTimer() {
        if (timerInterval) clearInterval(timerInterval);
        timeLeft = 120;
        updateTimerDisplay();
        timerInterval = setInterval(() => {
            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                timerInterval = null;
                document.getElementById('timerText').innerHTML = '<i class="bi bi-hourglass-split"></i> Code expired. Please request a new one.';
                document.querySelectorAll('.otp-digit').forEach(inp => inp.disabled = true);
            } else {
                timeLeft--;
                updateTimerDisplay();
            }
        }, 1000);
    }

    function updateTimerDisplay() {
        const minutes = Math.floor(timeLeft / 60);
        const seconds = timeLeft % 60;
        document.getElementById('timerCountdown').innerText = `${minutes.toString().padStart(2,'0')}:${seconds.toString().padStart(2,'0')}`;
    }

    function getEnteredOTP() {
        let otp = '';
        for (let i=1; i<=6; i++) {
            const val = document.getElementById(`otp${i}`).value;
            if (!val.match(/[0-9]/)) return '';
            otp += val;
        }
        return otp;
    }

    // Cooldown timer for the Resend link (30s)
    function startResendCooldown(seconds = 30) {
        const link = document.getElementById('resendOtpLink');
        if (resendCooldownInterval) clearInterval(resendCooldownInterval);
        let remaining = seconds;
        link.classList.add('disabled');
        link.innerText = `Resend available in ${remaining}s`;
        resendCooldownInterval = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(resendCooldownInterval);
                resendCooldownInterval = null;
                link.classList.remove('disabled');
                link.innerText = "Didn't receive the code? Resend";
            } else {
                link.innerText = `Resend available in ${remaining}s`;
            }
        }, 1000);
    }

    function resetOtpModalState() {
        document.querySelectorAll('.otp-digit').forEach(inp => {
            inp.value = '';
            inp.disabled = false;
        });
        document.getElementById('otpError').style.display = 'none';
        document.getElementById('timerText').innerHTML =
            '<i class="bi bi-clock-history"></i> Code expires in <span id="timerCountdown">02:00</span>';
        startTimer();
    }

    // ----- Collect and validate form data -----
    function collectFormData() {
        const firstName = document.getElementById('firstName').value.trim();
        const lastName = document.getElementById('lastName').value.trim();
        const email = document.getElementById('emailAddress').value.trim();
        const contact = document.getElementById('contactNumber').value.trim();
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('confirmPassword').value;
        const errors = [];
        if (!firstName) errors.push('First name is required.');
        if (!lastName) errors.push('Last name is required.');
        if (!email || !email.includes('@')) errors.push('Valid email is required.');
        if (!contact) errors.push('Contact number is required.');
        if (password.length < 8) errors.push('Password must be at least 8 characters.');
        if (password !== confirm) errors.push('Passwords do not match.');
        if (errors.length > 0) {
            return { valid: false, errors };
        }
        return { valid: true, data: { firstName, lastName, email, contact, password, confirm_password: confirm } };
    }

    // ----- Show terms modal on "Create account" click -----
    document.getElementById('showOtpBtn').addEventListener('click', function() {
        const result = collectFormData();
        if (!result.valid) {
            Swal.fire('Validation Error', result.errors.join('\n'), 'error');
            return;
        }
        window._registrationData = result.data;
        const termsModal = new bootstrap.Modal(document.getElementById('termsModal'));
        termsModal.show();
        document.getElementById('termsCheckbox').checked = false;
        document.getElementById('robotCheckbox').checked = false;
    });

    // ----- Handle "Register" button in terms modal -----
    document.getElementById('acceptTermsBtn').addEventListener('click', async function() {
        const termsChecked = document.getElementById('termsCheckbox').checked;
        const robotChecked = document.getElementById('robotCheckbox').checked;
        if (!termsChecked) {
            Swal.fire('Error', 'You must accept the Terms and Conditions to continue.', 'error');
            return;
        }
        if (!robotChecked) {
            Swal.fire('Error', 'Please confirm that you are not a robot.', 'error');
            return;
        }

        bootstrap.Modal.getInstance(document.getElementById('termsModal')).hide();

        const data = window._registrationData;
        if (!data) {
            Swal.fire('Error', 'No registration data found. Please try again.', 'error');
            return;
        }

        Swal.fire({ title: 'Sending OTP...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const result = await postJSON('send_otp', data);
        Swal.close();

        if (result.success) {
            currentEmail = data.email;
            document.getElementById('otpEmailDisplay').innerText = data.email;
            resetOtpModalState();
            startResendCooldown(30);
            const otpModal = new bootstrap.Modal(document.getElementById('otpModal'));
            otpModal.show();
            setTimeout(() => document.getElementById('otp1').focus(), 300);
        } else {
            Swal.fire('Registration Error', result.errors.join('\n'), 'error');
        }
    });

    // ----- OTP verification -----
    document.getElementById('verifyOtpBtn').addEventListener('click', async function() {
        const otp = getEnteredOTP();
        if (otp.length !== 6) {
            document.getElementById('otpError').innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Please enter the complete 6-digit code.';
            document.getElementById('otpError').style.display = 'block';
            return;
        }
        const btn = this;
        btn.disabled = true;
        btn.innerText = 'Verifying...';
        const result = await postJSON('verify_otp', { otp });
        btn.disabled = false;
        btn.innerText = 'Verify & Register';
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('otpModal')).hide();
            Swal.fire({
                icon: 'success',
                title: 'Registration Successful!',
                text: 'Your account has been created. Redirecting to dashboard...',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                window.location.href = result.redirect;
            });
        } else {
            document.getElementById('otpError').innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> ${result.errors.join('<br>')}`;
            document.getElementById('otpError').style.display = 'block';
            for (let i=1;i<=6;i++) document.getElementById(`otp${i}`).value = '';
            document.getElementById('otp1').focus();
        }
    });

    // ----- Resend OTP -----
    document.getElementById('resendOtpLink').addEventListener('click', async function(e) {
        e.preventDefault();
        if (this.classList.contains('disabled')) return;
        
        const data = window._registrationData;
        if (!data) {
            Swal.fire('Error', 'No registration data. Please start over.', 'error');
            return;
        }
        Swal.fire({ title: 'Resending OTP...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const result = await postJSON('resend_otp', {});
        Swal.close();
        if (result.success) {
            resetOtpModalState();
            startResendCooldown(30);
            document.getElementById('otp1').focus();
            Swal.fire('Sent!', 'A new OTP has been sent to your email.', 'success');
        } else {
            Swal.fire('Error', result.errors.join('\n'), 'error');
        }
    });

    setupOtpInputs();
    const confirmPass = document.getElementById('confirmPassword');
    const passwordField = document.getElementById('password');
    function checkMatch() { confirmPass.setCustomValidity(passwordField.value !== confirmPass.value ? 'Passwords do not match' : ''); }
    passwordField.addEventListener('change', checkMatch);
    confirmPass.addEventListener('keyup', checkMatch);
</script>

</body>
</html>