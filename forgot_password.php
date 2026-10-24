<?php
session_start();
require_once 'config/connect.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ---------- AJAX endpoint ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // -------- helpers --------
    $sendOtpMail = function (string $toEmail, string $toName, string $otp) {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'buyawanongplacer@gmail.com';
        $mail->Password   = 'fsiqtfjcpjjizquc';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->setFrom('buyawanongplacer@gmail.com', 'Buyawanong Placer');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'Password reset code - Buyawanong Placer';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto;'>
                <h2 style='color: #172233;'>Password Reset Verification</h2>
                <p>Hello <strong>" . htmlspecialchars($toName) . "</strong>,</p>
                <p>Use the following one-time password (OTP) to reset your account password:</p>
                <div style='background: #f8f9fa; padding: 15px; text-align: center; font-size: 28px; letter-spacing: 5px; border-radius: 12px; border: 1px solid #172233;'>
                    <strong style='color: #172233;'>$otp</strong>
                </div>
                <p>This code is valid for <strong>2 minutes</strong>.</p>
                <p>If you did not request a password reset, you can safely ignore this email.</p>
                <hr>
                <small>Municipality of Placer, Surigao del Norte</small>
            </div>
        ";
        $mail->AltBody = "Your password reset code is: $otp. Valid for 2 minutes.";
        $mail->send();
    };

    // =========================================================
    // STEP 1 — Send OTP
    // =========================================================
    if ($action === 'send_reset_otp') {
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'errors' => ['Please enter a valid email address.']]);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, firstname, lastname FROM accounts WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows !== 1) {
            echo json_encode(['success' => false, 'errors' => ['No account found with that email address.']]);
            exit;
        }

        $user = $res->fetch_assoc();
        $stmt->close();

        $otp = sprintf("%06d", mt_rand(1, 999999));

        $_SESSION['password_reset'] = [
            'user_id'  => (int)$user['id'],
            'email'    => $email,
            'name'     => $user['firstname'] . ' ' . $user['lastname'],
            'otp'      => $otp,
            'expires'  => time() + 120, // 2 minutes
            'verified' => false,
        ];
        $_SESSION['last_reset_otp_sent'] = time();

        try {
            $sendOtpMail($email, $user['firstname'] . ' ' . $user['lastname'], $otp);
            echo json_encode(['success' => true, 'email' => $email]);
        } catch (Exception $e) {
            unset($_SESSION['password_reset']);
            echo json_encode(['success' => false, 'errors' => ['Failed to send email: ' . $e->getMessage()]]);
        }
        exit;
    }

    // =========================================================
    // STEP 2 — Verify OTP
    // =========================================================
    if ($action === 'verify_reset_otp') {
        $otp = trim($_POST['otp'] ?? '');

        if (!isset($_SESSION['password_reset'])) {
            echo json_encode(['success' => false, 'errors' => ['Session expired. Please start over.']]);
            exit;
        }
        $reset = $_SESSION['password_reset'];

        if (time() > $reset['expires']) {
            unset($_SESSION['password_reset']);
            echo json_encode(['success' => false, 'errors' => ['The code has expired. Please request a new one.']]);
            exit;
        }
        if ($otp !== $reset['otp']) {
            echo json_encode(['success' => false, 'errors' => ['Invalid code. Please try again.']]);
            exit;
        }

        $_SESSION['password_reset']['verified'] = true;
        echo json_encode(['success' => true]);
        exit;
    }

    // =========================================================
    // Resend OTP
    // =========================================================
    if ($action === 'resend_reset_otp') {
        if (!isset($_SESSION['password_reset'])) {
            echo json_encode(['success' => false, 'errors' => ['Session expired. Please start over.']]);
            exit;
        }
        // 30-second cooldown
        if (isset($_SESSION['last_reset_otp_sent']) && (time() - $_SESSION['last_reset_otp_sent']) < 30) {
            $wait = 30 - (time() - $_SESSION['last_reset_otp_sent']);
            echo json_encode(['success' => false, 'errors' => ["Please wait {$wait}s before requesting another code."]]);
            exit;
        }

        $reset = $_SESSION['password_reset'];
        $newOtp = sprintf("%06d", mt_rand(1, 999999));
        $_SESSION['password_reset']['otp']      = $newOtp;
        $_SESSION['password_reset']['expires']  = time() + 120;
        $_SESSION['password_reset']['verified'] = false;
        $_SESSION['last_reset_otp_sent']        = time();

        try {
            $sendOtpMail($reset['email'], $reset['name'], $newOtp);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'errors' => ['Failed to send email: ' . $e->getMessage()]]);
        }
        exit;
    }

    // =========================================================
    // STEP 3 — Update password (only if verified)
    // =========================================================
    if ($action === 'reset_password') {
        if (!isset($_SESSION['password_reset']) || empty($_SESSION['password_reset']['verified'])) {
            echo json_encode(['success' => false, 'errors' => ['You must verify your code first.']]);
            exit;
        }

        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $errors = [];
        if (strlen($password) < 8)     $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)    $errors[] = 'Passwords do not match.';

        if (!empty($errors)) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        $userId   = (int)$_SESSION['password_reset']['user_id'];
        $hashed   = md5($password); // matches existing login.php logic

        $stmt = $conn->prepare("UPDATE accounts SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed, $userId);

        if ($stmt->execute()) {
            $stmt->close();
            unset($_SESSION['password_reset'], $_SESSION['last_reset_otp_sent']);
            echo json_encode(['success' => true]);
        } else {
            $stmt->close();
            echo json_encode(['success' => false, 'errors' => ['Database error. Please try again later.']]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'errors' => ['Invalid request.']]);
    exit;
}

// Direct non-AJAX access not allowed
http_response_code(405);
exit('Method not allowed.');