<?php
session_start();
date_default_timezone_set('Asia/Manila'); // Philippine time

// Only user_type = 5 (Admin) can access
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 5) {
    $_SESSION['error'] = 'Access denied. Admins only.';
    header('Location: ../login.php');
    exit;
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? 'Administrator';

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$message = '';

// ============================================================
// PHPMailer
// ============================================================
function sendAdminMail($toEmail, $toName, $subject, $htmlBody) {
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
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        $mail->send();
        return ['success' => true, 'message' => 'Email sent.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Mailer error: ' . $mail->ErrorInfo];
    }
}

// ============================================================
// EMPLOYEE EMAIL VERIFICATION — token helpers
// ============================================================
define('VERIFY_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING_32CHARS_MIN');
define('VERIFY_TTL',    172800); // 48 hours
define('PORTAL_URL',    'https://buyawanongplacer.online/');

function generateEmployeeVerifyToken($employeeId, $email) {
    $expires = time() + VERIFY_TTL;
    $payload = $employeeId . '|' . $email . '|' . $expires;
    $sig     = hash_hmac('sha256', $payload, VERIFY_SECRET);
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

function verifyEmployeeToken($token) {
    $decoded = base64_decode(strtr($token, '-_', '+/'), true);
    if ($decoded === false) return ['error' => 'malformed'];
    $parts = explode('|', $decoded);
    if (count($parts) !== 4) return ['error' => 'malformed'];
    list($employeeId, $email, $expires, $sig) = $parts;
    if ((int)$expires < time()) return ['error' => 'expired'];
    $expected = hash_hmac('sha256', $employeeId . '|' . $email . '|' . $expires, VERIFY_SECRET);
    if (!hash_equals($expected, $sig)) return ['error' => 'invalid'];
    return ['employee_id' => (int)$employeeId, 'email' => $email];
}

/**
 * Send the employee verification email.
 */
function sendEmployeeVerificationEmail($email, $firstname, $lastname, $user_type, $employeeId, $password = 'admin123') {
    $roleMap = [3 => 'Treasury Staff', 4 => 'BPLO / Licensing Staff', 5 => 'Administrator'];
    $roleLabel = $roleMap[$user_type] ?? 'Treasury Staff';

    $token = generateEmployeeVerifyToken($employeeId, $email);
    $verifyUrl = PORTAL_URL . '/admin/verify_employee.php?token=' . urlencode($token);

    $safeFirst    = htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8');
    $safeLast     = htmlspecialchars($lastname,  ENT_QUOTES, 'UTF-8');
    $safeEmail    = htmlspecialchars($email,     ENT_QUOTES, 'UTF-8');
    $safeRole     = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars((string)$password, ENT_QUOTES, 'UTF-8');

    $htmlBody = "
    <div style='font-family: \"Segoe UI\", Arial, sans-serif; max-width: 620px; margin: 0 auto; background: #ffffff; border: 1px solid #e8ddd3; border-radius: 6px; overflow: hidden;'>

        <div style='background: #2b1810; color: #ffffff; padding: 32px 40px; text-align: center; border-bottom: 3px solid #d4a373;'>
            <div style='font-size: 11px; letter-spacing: 4px; color: #d4a373; font-weight: 600;'>REPUBLIC OF THE PHILIPPINES</div>
            <div style='font-size: 10px; letter-spacing: 3px; color: #cbb8a6; margin-top: 3px;'>PROVINCE OF SURIGAO DEL NORTE</div>
            <div style='font-size: 18px; font-weight: 800; letter-spacing: 2px; margin-top: 10px; color: #ffffff;'>MUNICIPALITY OF PLACER</div>
            <div style='font-size: 11px; margin-top: 5px; color: #d4a373;'>Business Permits and Licensing Office</div>
        </div>

        <div style='padding: 40px 44px; color: #3b2c1f; line-height: 1.75; font-size: 14px;'>
            <p style='margin: 0 0 4px; font-size: 11px; letter-spacing: 2px; color: #8a7561; text-transform: uppercase;'>Account Verification Notice</p>
            <h2 style='font-size: 21px; color: #2b1810; margin: 0 0 20px; font-weight: 800;'>Employee Account Created</h2>

            <p>Dear <strong>{$safeFirst} {$safeLast}</strong>,</p>

            <p>An employee account has been created for you in the <strong>Buyawanong Placer — Municipal Business Permit Portal</strong>. Before you can access the system, you are required to verify your email address.</p>

            <table style='width: 100%; border-collapse: collapse; margin: 22px 0; font-size: 13px;'>
                <tr>
                    <td style='padding: 11px 16px; background: #faf5ef; font-weight: 700; color: #2b1810; width: 38%; border: 1px solid #e8ddd3;'>Employee ID</td>
                    <td style='padding: 11px 16px; border: 1px solid #e8ddd3; font-family: \"Courier New\", monospace;'>#{$employeeId}</td>
                </tr>
                <tr>
                    <td style='padding: 11px 16px; background: #faf5ef; font-weight: 700; color: #2b1810; border: 1px solid #e8ddd3;'>Email Address</td>
                    <td style='padding: 11px 16px; border: 1px solid #e8ddd3;'>{$safeEmail}</td>
                </tr>
                <tr>
                    <td style='padding: 11px 16px; background: #faf5ef; font-weight: 700; color: #2b1810; border: 1px solid #e8ddd3;'>Assigned Role</td>
                    <td style='padding: 11px 16px; border: 1px solid #e8ddd3;'>{$safeRole}</td>
                </tr>
                <tr>
                    <td style='padding: 11px 16px; background: #faf5ef; font-weight: 700; color: #2b1810; border: 1px solid #e8ddd3;'>Password</td>
                    <td style='padding: 11px 16px; border: 1px solid #e8ddd3; font-family: \"Courier New\", monospace; font-weight: 700;'>{$safePassword}</td>
                </tr>
            </table>

            <p>To activate your account, please click the button below. This link is valid for <strong>48 hours</strong> from the time this email was sent.</p>

            <div style='text-align: center; margin: 32px 0;'>
                <a href='{$verifyUrl}'
                   style='background: #6d4c41; color: #ffffff; text-decoration: none; padding: 15px 40px; border-radius: 8px; font-weight: 700; font-size: 14px; display: inline-block; letter-spacing: 0.5px;'>
                    Verify My Account
                </a>
            </div>

            <p style='font-size: 12px; color: #8a7561; margin-top: 24px;'>If the button does not work, copy and paste this link into your browser:</p>
            <p style='font-size: 11px; word-break: break-all; background: #faf5ef; padding: 12px 14px; border-radius: 6px; border-left: 3px solid #d4a373; margin: 6px 0 24px;'>
                <a href='{$verifyUrl}' style='color: #6d4c41; text-decoration: none;'>{$verifyUrl}</a>
            </p>

            <div style='background: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 18px; border-radius: 6px; margin: 22px 0; font-size: 13px;'>
                <strong style='color: #92400e;'>Important:</strong> You will not be able to log in to the portal until your email has been verified. After verifying, you will be redirected automatically to your dashboard.
            </div>

            <p>For your security, please change this password after your first login via <em>Account Settings</em>.</p>

            <p>If you did not expect this email or believe it was sent to you by mistake, please disregard it or contact the system administrator.</p>

            <p style='margin-top: 32px; margin-bottom: 0;'>
                Respectfully yours,<br><br>
                <strong style='color: #2b1810;'>Business Permits and Licensing Office</strong><br>
                <span style='color: #8a7561;'>Municipality of Placer, Surigao del Norte</span>
            </p>
        </div>

        <div style='background: #2b1810; color: #cbb8a6; padding: 22px 40px; text-align: center; font-size: 11px; line-height: 1.7;'>
            This is an automated system message. Please do not reply directly to this email.<br>
            &copy; " . date('Y') . " Municipality of Placer — Buyawanong Placer Portal
        </div>
    </div>";

    return sendAdminMail($email, $firstname . ' ' . $lastname, 'Verify Your Employee Account — Buyawanong Placer', $htmlBody);
}

// ============================================================
// POST HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_employee'])) {
        $firstname = trim($_POST['firstname']);
        $lastname  = trim($_POST['lastname']);
        $email     = trim($_POST['email']);
        $contact   = trim($_POST['contact']);
        $user_type = (int)$_POST['user_type'];
        $raw_password = trim($_POST['password']);
        if ($raw_password === '') $raw_password = 'admin123';
        $password  = md5($raw_password);

        // Check duplicate email
        $dup = $conn->prepare("SELECT id FROM accounts WHERE email = ? LIMIT 1");
        $dup->bind_param("s", $email);
        $dup->execute();
        $dup->store_result();

        if ($dup->num_rows > 0) {
            $message = 'An account with this email already exists.';
            $dup->close();
        } else {
            $dup->close();

            $stmt = $conn->prepare("INSERT INTO accounts (user_type, firstname, lastname, email, contact_number, password, verified) VALUES (?, ?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("isssss", $user_type, $firstname, $lastname, $email, $contact, $password);

            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $mail = sendEmployeeVerificationEmail($email, $firstname, $lastname, $user_type, $new_id, $raw_password);
                if ($mail['success']) {
                    $message = 'Employee added successfully. A verification email has been sent to ' . $email . '.';
                } else {
                    $message = 'Employee added, but the verification email could not be sent: ' . $mail['message'];
                }
            } else {
                $message = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    }

    if (isset($_POST['edit_employee'])) {
        $id = (int)$_POST['id']; $firstname = trim($_POST['firstname']); $lastname = trim($_POST['lastname']);
        $email = trim($_POST['email']); $contact = trim($_POST['contact']); $user_type = (int)$_POST['user_type'];
        $stmt = $conn->prepare("UPDATE accounts SET firstname=?, lastname=?, email=?, contact_number=?, user_type=? WHERE id=? AND user_type IN (2,3,4,5)");
        $stmt->bind_param("ssssii", $firstname, $lastname, $email, $contact, $user_type, $id);
        $message = $stmt->execute() ? 'Employee updated successfully.' : 'Error: ' . $stmt->error;
        $stmt->close();
    }

    if (isset($_POST['delete_employee'])) {
        $id = (int)$_POST['id'];
        if ($id == $admin_id) { $message = 'You cannot delete your own account.'; }
        else {
            $stmt = $conn->prepare("DELETE FROM accounts WHERE id=? AND user_type IN (2,3,4,5)");
            $stmt->bind_param("i", $id);
            $message = $stmt->execute() ? 'Employee deleted successfully.' : 'Error: ' . $stmt->error;
            $stmt->close();
        }
    }

    if (isset($_POST['resend_employee_verification'])) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        $emp_id = (int)$_POST['id'];
        $stmt = $conn->prepare("SELECT id, user_type, firstname, lastname, email, verified FROM accounts WHERE id = ? AND user_type IN (2,3,4,5) LIMIT 1");
        $stmt->bind_param("i", $emp_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Employee not found.']);
            exit;
        }
        if ((int)$row['verified'] === 1) {
            echo json_encode(['success' => false, 'message' => 'This account is already verified.']);
            exit;
        }

        $mail = sendEmployeeVerificationEmail($row['email'], $row['firstname'], $row['lastname'], (int)$row['user_type'], (int)$row['id'], 'admin123');
        echo json_encode($mail);
        exit;
    }

    // ---- toggle employee verified (admin panel) ----
    if (isset($_POST['toggle_employee_verified'])) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        $id = (int)$_POST['id'];
        $verified = (int)$_POST['verified'];
        if (!in_array($verified, [0, 1], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid value.']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE accounts SET verified = ? WHERE id = ? AND user_type IN (2,3,4,5)");
        $stmt->bind_param("ii", $verified, $id);
        $ok = $stmt->execute() && $stmt->affected_rows >= 0;
        echo json_encode($ok ? ['success' => true, 'verified' => $verified] : ['success' => false, 'message' => 'Failed to update.']);
        $stmt->close(); exit;
    }

    if (isset($_POST['add_announcement'])) {
        $title = trim($_POST['title']); $content = trim($_POST['content']);
        $status = 'approved'; $created_by = $admin_id;
        $stmt = $conn->prepare("INSERT INTO announcements (title, content, created_by, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $title, $content, $created_by, $status);
        $message = $stmt->execute() ? 'Announcement added successfully.' : 'Error: ' . $stmt->error;
        $stmt->close();
    }
    if (isset($_POST['edit_announcement'])) {
        $id = (int)$_POST['id']; $title = trim($_POST['title']); $content = trim($_POST['content']);
        $stmt = $conn->prepare("UPDATE announcements SET title=?, content=? WHERE id=?");
        $stmt->bind_param("ssi", $title, $content, $id);
        $message = $stmt->execute() ? 'Announcement updated successfully.' : 'Error: ' . $stmt->error;
        $stmt->close();
    }
    if (isset($_POST['delete_announcement'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id=?");
        $stmt->bind_param("i", $id);
        $message = $stmt->execute() ? 'Announcement deleted successfully.' : 'Error: ' . $stmt->error;
        $stmt->close();
    }
    if (isset($_POST['approve_announcement'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE announcements SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?");
        $stmt->bind_param("ii", $admin_id, $id);
        $message = $stmt->execute() ? 'Announcement approved.' : 'Error: ' . $stmt->error;
        $stmt->close();
    }
    if (isset($_POST['reject_announcement'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE announcements SET status='rejected' WHERE id=?");
        $stmt->bind_param("i", $id);
        $message = $stmt->execute() ? 'Announcement rejected.' : 'Error: ' . $stmt->error;
        $stmt->close();
    }
    if (isset($_POST['toggle_user_verified'])) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        $id = (int)$_POST['id']; $verified = (int)$_POST['verified'];
        $stmt = $conn->prepare("UPDATE accounts SET verified = ? WHERE id = ? AND user_type = 1");
        $stmt->bind_param("ii", $verified, $id);
        echo json_encode(($stmt->execute() && $stmt->affected_rows > 0) ? ['success' => true, 'verified' => $verified] : ['success' => false, 'message' => 'Failed to update.']);
        $stmt->close(); exit;
    }
    if (isset($_POST['update_payment_status'])) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        $app_id = (int)$_POST['application_id']; $new_status = trim($_POST['status']);
        $allowed = ['pending_payment', 'under_review', 'approved', 'rejected', 'claimed', 'verified'];
        if (!in_array($new_status, $allowed)) { echo json_encode(['success' => false, 'message' => 'Invalid status.']); exit; }
        $stmt = $conn->prepare("UPDATE business_applications SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $app_id);
        echo json_encode($stmt->execute() ? ['success' => true, 'status' => $new_status] : ['success' => false, 'message' => 'Failed.']);
        $stmt->close(); exit;
    }
    if (isset($_POST['update_application_status'])) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        $app_id = (int)$_POST['application_id']; $new_status = trim($_POST['status']);
        $allowed = ['draft', 'submitted', 'pending_payment', 'under_review', 'approved', 'rejected', 'claimed', 'verified', 'cancelled'];
        if (!in_array($new_status, $allowed)) { echo json_encode(['success' => false, 'message' => 'Invalid status.']); exit; }
        $stmt = $conn->prepare("UPDATE business_applications SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $app_id);
        echo json_encode($stmt->execute() ? ['success' => true, 'status' => $new_status] : ['success' => false, 'message' => 'Failed.']);
        $stmt->close(); exit;
    }

    // ---- reply to contact message (FIXED) ----
    if (isset($_POST['reply_contact_message'])) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        @set_time_limit(120);

        try {
            $msg_id   = (int)($_POST['message_id'] ?? 0);
            $toEmail  = trim($_POST['to_email'] ?? '');
            $toName   = trim($_POST['to_name'] ?? '');
            $subject  = trim($_POST['subject'] ?? '');
            $bodyText = trim($_POST['body'] ?? '');

            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid recipient email.']);
                exit;
            }
            if ($subject === '' || $bodyText === '') {
                echo json_encode(['success' => false, 'message' => 'Subject and message required.']);
                exit;
            }

            $safeBody = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
            $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
            $htmlBody = "<div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#172233;'><div style='background:#6d4c41;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;'><h2 style='margin:0;font-size:18px;'>Municipality of Placer — Reply</h2></div><div style='border:1px solid #e5e7eb;border-top:none;padding:20px;border-radius:0 0 8px 8px;'><p>Hello <strong>{$safeName}</strong>,</p><div style='background:#faf5ef;border-left:4px solid #d4a373;padding:12px 16px;border-radius:6px;margin:12px 0;'>{$safeBody}</div><p style='margin-top:20px;'>Sincerely,<br><strong>Municipality of Placer</strong><br>Business Permit &amp; Licensing Office<br>Surigao del Norte</p><hr style='border:none;border-top:1px solid #e5e7eb;margin:20px 0;'><small style='color:#6b7280;'>This is a reply to your message submitted on our contact form.</small></div></div>";

            $result = sendAdminMail($toEmail, $toName, $subject, $htmlBody);

            if ($result['success']) {
                // Best-effort: mark replied; ignore if column missing
                try { @$conn->query("UPDATE contact_messages SET replied_at = NOW() WHERE id = " . (int)$msg_id); } catch (Throwable $e) {}
                echo json_encode(['success' => true, 'message' => 'Reply sent successfully to ' . $toEmail]);
            } else {
                echo json_encode(['success' => false, 'message' => $result['message']]);
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---- delete contact message + any related records (NEW) ----
    if (isset($_POST['delete_contact_message'])) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');

        try {
            $msg_id = (int)($_POST['message_id'] ?? 0);
            if ($msg_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid message ID.']);
                exit;
            }

            // Helper: check if a table exists
            $tableExists = function ($t) use ($conn) {
                $t = $conn->real_escape_string($t);
                $r = @$conn->query("SHOW TABLES LIKE '$t'");
                $ok = $r && $r->num_rows > 0;
                if ($r) $r->free();
                return $ok;
            };

            // 1) Make sure the message actually exists first
            $check = $conn->prepare("SELECT id FROM contact_messages WHERE id = ? LIMIT 1");
            if (!$check) {
                echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
                exit;
            }
            $check->bind_param("i", $msg_id);
            $check->execute();
            $check->store_result();
            $exists = $check->num_rows > 0;
            $check->close();

            if (!$exists) {
                echo json_encode(['success' => false, 'message' => 'Message not found or already deleted.']);
                exit;
            }

            // 2) Clean up any related reply rows (best-effort, skip if table missing)
            $relatedTables = [
                'contact_replies'         => 'message_id',
                'contact_message_replies' => 'message_id',
                'message_replies'         => 'message_id',
                'contact_reply'           => 'message_id',
            ];
            foreach ($relatedTables as $tbl => $fk) {
                if ($tableExists($tbl)) {
                    @$conn->query("DELETE FROM `$tbl` WHERE `$fk` = " . (int)$msg_id);
                }
            }

            // 3) Delete the message itself (the user-submitted row)
            $stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ? LIMIT 1");
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param("i", $msg_id);
            $ok = $stmt->execute() && $stmt->affected_rows > 0;
            $stmt->close();

            echo json_encode($ok
                ? ['success' => true,  'message' => 'Message and any related replies were deleted.']
                : ['success' => false, 'message' => 'Delete failed: no rows affected.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }
}

if (isset($_POST['save_system_settings'])) {
    $keys = $_POST['setting_key']   ?? [];
    $vals = $_POST['setting_value'] ?? [];

    if (is_array($keys) && is_array($vals) && count($keys) === count($vals)) {
        $stmt = $conn->prepare(
            "UPDATE system_settings
                SET setting_value = ?, updated_by = ?
              WHERE setting_key = ?"
        );
        if ($stmt) {
            $saved = 0;
            foreach ($keys as $i => $k) {
                $k = trim((string)$k);
                if ($k === '') continue;
                $v = (string)$vals[$i];
                $stmt->bind_param("sis", $v, $admin_id, $k);
                if ($stmt->execute()) $saved++;
            }
            $stmt->close();
            $message = "System settings saved ($saved field" . ($saved === 1 ? '' : 's') . " updated).";
        } else {
            $message = "Error preparing statement: " . $conn->error;
        }
    } else {
        $message = "No settings submitted.";
    }
}

// ============================================================
// FETCH DATA
// ============================================================
$employees = [];
$empResult = $conn->query("SELECT id, user_type, firstname, lastname, email, contact_number, verified, created_at FROM accounts WHERE user_type IN (2,3,4,5) ORDER BY created_at DESC");
if ($empResult) while ($row = $empResult->fetch_assoc()) $employees[] = $row;

$users = [];
$userResult = $conn->query("SELECT id, user_type, firstname, lastname, email, contact_number, verified, created_at, updated_at FROM accounts WHERE user_type = 1 ORDER BY created_at DESC");
if ($userResult) while ($row = $userResult->fetch_assoc()) $users[] = $row;

$announcements = [];
$annResult = $conn->query("SELECT a.*, CONCAT(acc.firstname, ' ', acc.lastname) AS creator_name FROM announcements a LEFT JOIN accounts acc ON a.created_by = acc.id ORDER BY a.created_at DESC");
if ($annResult) while ($row = $annResult->fetch_assoc()) $announcements[] = $row;

$contactMessages = [];
$cmResult = $conn->query("SELECT `id`, `name`, `email`, `topic`, `message`, `created_at` FROM `contact_messages` ORDER BY `created_at` DESC");
if ($cmResult) while ($row = $cmResult->fetch_assoc()) $contactMessages[] = $row;

$payments = [];
$payResult = $conn->query("
    SELECT fa.id AS assessment_id, fa.application_id, fa.gross_sales_tax, fa.mayors_permit_fee,
        fa.garbage_charges, fa.sanitary_inspection_fee, fa.building_inspection_fee,
        fa.electrical_inspection_fee, fa.others, fa.fire_safety_fee_percent, fa.total_amount,
        fa.assessed_by, fa.assessed_at, ba.reference_no, ba.application_no, ba.status AS app_status,
        ba.tax_year, ba.submitted_at, ba.issued_at, ba.expiry_date, ba.created_at AS app_created_at,
        ba.user_id, CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name, acc.email AS owner_email,
        acc.contact_number AS owner_contact,
        CONCAT(assessor.firstname, ' ', assessor.lastname) AS assessor_name
    FROM fee_assessments fa
    LEFT JOIN business_applications ba ON fa.application_id = ba.id
    LEFT JOIN accounts acc ON ba.user_id = acc.id
    LEFT JOIN accounts assessor ON fa.assessed_by = assessor.id
    ORDER BY fa.assessed_at DESC, fa.id DESC
");
if ($payResult) while ($row = $payResult->fetch_assoc()) $payments[] = $row;

$totalAssessed = 0; $totalCollected = 0; $pendingCount = 0; $paidCount = 0;
foreach ($payments as $p) {
    $totalAssessed += (float)($p['total_amount'] ?? 0);
    $st = strtolower($p['app_status'] ?? '');
    if (in_array($st, ['approved', 'claimed', 'verified'])) { $totalCollected += (float)($p['total_amount'] ?? 0); $paidCount++; }
    elseif ($st === 'pending_payment' || $st === 'under_review') { $pendingCount++; }
}

$applications = [];
$appsResult = $conn->query("
    SELECT ba.id, ba.user_id, ba.application_no, ba.reference_no, ba.status, ba.current_step,
        ba.application_date, ba.tax_year, ba.submitted_at, ba.issued_at, ba.expiry_date,
        ba.created_at, ba.updated_at, ba.checklist_data, ba.resubmitted_at, ba.renewed_from_id,
        ba.original_application_id,
        bid.business_name,
        CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
        acc.email AS owner_email, acc.contact_number AS owner_contact,
        atd.application_type,
        fa.total_amount AS assessed_amount,
        (SELECT COUNT(*) FROM document_uploads du WHERE du.application_id = ba.id) AS doc_count,
        (SELECT COUNT(*) FROM application_rejections ar WHERE ar.application_id = ba.id) AS rejection_count
    FROM business_applications ba
    LEFT JOIN accounts acc ON ba.user_id = acc.id
    LEFT JOIN business_info_data bid ON ba.id = bid.application_id
    LEFT JOIN application_type_data atd ON ba.id = atd.application_id
    LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
    WHERE ba.status != 'draft'
    ORDER BY ba.created_at DESC
");
if ($appsResult) while ($row = $appsResult->fetch_assoc()) $applications[] = $row;

$totalApps = count($applications);
$appUnderReview = 0; $appPendingPayment = 0; $appApproved = 0; $appRejected = 0; $appSubmitted = 0;
foreach ($applications as $a) {
    $st = strtolower($a['status']);
    if ($st === 'under_review') $appUnderReview++;
    elseif ($st === 'pending_payment') $appPendingPayment++;
    elseif (in_array($st, ['approved', 'claimed', 'verified'])) $appApproved++;
    elseif ($st === 'rejected') $appRejected++;
    elseif ($st === 'submitted') $appSubmitted++;
}

// ============================================================
// APPOINTMENTS
// ============================================================
$appointments = [];
$apptResult = $conn->query("
    SELECT a.id AS appointment_id, a.application_id, a.user_id,
        a.appointment_date, a.appointment_time, a.status AS appointment_status,
        a.created_at AS appointment_created, a.updated_at AS appointment_updated,
        ba.reference_no, ba.application_no, ba.status AS application_status, ba.tax_year,
        bid.business_name, atd.application_type,
        acc.firstname, acc.lastname, acc.email, acc.contact_number
    FROM appointments a
    LEFT JOIN business_applications ba ON a.application_id = ba.id
    LEFT JOIN business_info_data bid ON ba.id = bid.application_id
    LEFT JOIN application_type_data atd ON ba.id = atd.application_id
    LEFT JOIN accounts acc ON a.user_id = acc.id
    WHERE acc.user_type = 1
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
if ($apptResult) while ($row = $apptResult->fetch_assoc()) $appointments[] = $row;

$todayDate = date('Y-m-d');
$weekAgo   = date('Y-m-d', strtotime('-7 days'));
$weekAhead = date('Y-m-d', strtotime('+7 days'));

$apptStats = ['total' => count($appointments), 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'today' => 0, 'upcoming' => 0];
foreach ($appointments as $a) {
    $s = strtolower($a['appointment_status']);
    if ($s === 'pending') $apptStats['pending']++;
    elseif ($s === 'confirmed') $apptStats['confirmed']++;
    elseif ($s === 'completed') $apptStats['completed']++;
    elseif ($s === 'cancelled') $apptStats['cancelled']++;
    if ($a['appointment_date'] === $todayDate) $apptStats['today']++;
    if ($a['appointment_date'] >= $todayDate && $a['appointment_date'] <= $weekAhead) $apptStats['upcoming']++;
}

$calendarEvents = [];
foreach ($appointments as $a) {
    $fullName = trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
    if ($fullName === '') $fullName = 'Applicant #' . ($a['user_id'] ?? '?');
    $apptStatus = strtolower($a['appointment_status'] ?? '');
    switch ($apptStatus) {
        case 'pending':   $color = '#d4a373'; $textColor = '#2b1810'; break;
        case 'confirmed': $color = '#3b82f6'; $textColor = '#ffffff'; break;
        case 'completed': $color = '#10b981'; $textColor = '#ffffff'; break;
        case 'cancelled': $color = '#ef4444'; $textColor = '#ffffff'; break;
        default:          $color = '#6b7280'; $textColor = '#ffffff';
    }
    $dateDisplay = date('M j', strtotime($a['appointment_date']));
    $rawTime = trim($a['appointment_time'] ?? '');
    $ts = strtotime(trim($a['appointment_date']) . ' ' . $rawTime);
    $isoStart = ($ts === false) ? $a['appointment_date'] . 'T00:00:00' : date('Y-m-d\TH:i:s', $ts);
    $calendarEvents[] = [
        'id' => $a['appointment_id'],
        'title' => $fullName . ' — ' . ($a['business_name'] ?? 'N/A'),
        'start' => $isoStart, 'backgroundColor' => $color, 'borderColor' => $color, 'textColor' => $textColor,
        'extendedProps' => [
            'reference_no' => $a['reference_no'] ?? 'N/A',
            'business_name' => $a['business_name'] ?? 'N/A',
            'application_type' => $a['application_type'] ?? 'N/A',
            'application_status' => $a['application_status'] ?? 'unknown',
            'appointment_status' => $a['appointment_status'] ?? 'pending',
            'appointment_time' => $a['appointment_time'] ?? '',
            'appointment_date' => $a['appointment_date'] ?? '',
            'date_display' => $dateDisplay, 'time_display' => $rawTime,
            'owner_name' => $fullName, 'owner_email' => $a['email'] ?? '',
            'owner_contact' => $a['contact_number'] ?? '',
            'application_id' => $a['application_id'] ?? 0,
        ]
    ];
}

// ============================================================
// ACTIVITY LOG — SAFE (no UNION, avoids collation errors)
// ============================================================
$activityLog = [];
$activityStats = ['total' => 0, 'today' => 0, 'week' => 0, 'reject' => 0, 'bplo' => 0, 'treasury' => 0, 'admin' => 0, 'payment' => 0, 'documents' => 0, 'appointments' => 0, 'submissions' => 0, 'verified' => 0, 'document_actions' => 0];

if (true) {
    $appendRows = function ($sql) use ($conn, &$activityLog) {
        $res = @$conn->query($sql);
        if (!$res) { error_log('[Admin Activity] ' . $conn->error); return; }
        while ($row = $res->fetch_assoc()) $activityLog[] = $row;
        $res->free();
    };
    $tableExists = function ($t) use ($conn) {
        $t = $conn->real_escape_string($t);
        $r = @$conn->query("SHOW TABLES LIKE '$t'");
        return $r && $r->num_rows > 0;
    };

    if ($tableExists('status_history')) {
        $appendRows("SELECT 'status_change' AS event_type, sh.id AS event_id, sh.application_id,
            sh.changed_at AS event_time, sh.changed_by AS actor_id,
            sh.old_status, sh.new_status, sh.notes, NULL AS missing_documents_json,
            ba.reference_no, ba.application_no, bid.business_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
            CONCAT(chg.firstname, ' ', chg.lastname) AS actor_name, chg.user_type AS actor_type
            FROM status_history sh
            LEFT JOIN business_applications ba ON sh.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            LEFT JOIN accounts chg ON sh.changed_by = chg.id
            ORDER BY sh.changed_at DESC LIMIT 500");
    }
    if ($tableExists('fee_assessments')) {
        $appendRows("SELECT 'fee_assessment' AS event_type, fa.id AS event_id, fa.application_id,
            fa.assessed_at AS event_time, fa.assessed_by AS actor_id,
            NULL AS old_status, 'assessed' AS new_status,
            CONCAT('Assessed fees: ₱', FORMAT(fa.total_amount, 2)) AS notes, NULL AS missing_documents_json,
            ba.reference_no, ba.application_no, bid.business_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
            CONCAT(chg.firstname, ' ', chg.lastname) AS actor_name, chg.user_type AS actor_type
            FROM fee_assessments fa
            LEFT JOIN business_applications ba ON fa.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            LEFT JOIN accounts chg ON fa.assessed_by = chg.id
            WHERE fa.assessed_at IS NOT NULL ORDER BY fa.assessed_at DESC LIMIT 500");
    }
    if ($tableExists('application_rejections')) {
        $appendRows("SELECT 'rejection' AS event_type, ar.id AS event_id, ar.application_id,
            ar.rejected_at AS event_time, ar.rejected_by AS actor_id,
            NULL AS old_status, 'rejected' AS new_status, ar.notes,
            ar.missing_documents AS missing_documents_json,
            ba.reference_no, ba.application_no, bid.business_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
            CONCAT(chg.firstname, ' ', chg.lastname) AS actor_name, chg.user_type AS actor_type
            FROM application_rejections ar
            LEFT JOIN business_applications ba ON ar.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            LEFT JOIN accounts chg ON ar.rejected_by = chg.id
            WHERE ar.rejected_at IS NOT NULL ORDER BY ar.rejected_at DESC LIMIT 500");
    }
    if ($tableExists('paypal_transactions')) {
        $appendRows("SELECT 'payment' AS event_type, pt.id AS event_id, pt.application_id,
            COALESCE(pt.paid_at, pt.created_at) AS event_time, pt.user_id AS actor_id,
            NULL AS old_status, pt.payment_status AS new_status,
            CONCAT('PayPal ', pt.currency, ' ', FORMAT(pt.amount, 2),
                   ' · Capture: ', COALESCE(pt.paypal_capture_id, '—')) AS notes,
            NULL AS missing_documents_json,
            pt.reference_no, NULL AS application_no, bid.business_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
            'PayPal' AS actor_name, 0 AS actor_type
            FROM paypal_transactions pt
            LEFT JOIN business_applications ba ON pt.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON pt.user_id = acc.id
            WHERE pt.payment_status IN ('completed','paid','voided','failed')
            ORDER BY COALESCE(pt.paid_at, pt.created_at) DESC LIMIT 500");
    }
    if ($tableExists('appointments')) {
        $appendRows("SELECT 'appointment_created' AS event_type, a.id AS event_id, a.application_id,
            a.created_at AS event_time, a.user_id AS actor_id,
            NULL AS old_status, 'booked' AS new_status,
            CONCAT('Appointment booked for ', DATE_FORMAT(a.appointment_date, '%b %d, %Y'), ' at ', a.appointment_time) AS notes,
            NULL AS missing_documents_json, ba.reference_no, ba.application_no, bid.business_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name, 1 AS actor_type
            FROM appointments a
            LEFT JOIN business_applications ba ON a.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON a.user_id = acc.id
            WHERE a.created_at IS NOT NULL ORDER BY a.created_at DESC LIMIT 500");
        $appendRows("SELECT CONCAT('appointment_', LOWER(a.status)) AS event_type,
            a.id AS event_id, a.application_id, a.updated_at AS event_time, NULL AS actor_id,
            'pending' AS old_status, a.status AS new_status,
            CONCAT('Appointment ', a.status, ' — scheduled ', DATE_FORMAT(a.appointment_date, '%b %d, %Y'), ' at ', a.appointment_time) AS notes,
            NULL AS missing_documents_json, ba.reference_no, ba.application_no, bid.business_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
            'BPLO Staff' AS actor_name, 4 AS actor_type
            FROM appointments a
            LEFT JOIN business_applications ba ON a.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON a.user_id = acc.id
            WHERE a.updated_at IS NOT NULL AND a.updated_at != a.created_at
              AND LOWER(a.status) IN ('confirmed','completed','cancelled')
            ORDER BY a.updated_at DESC LIMIT 500");
    }
    if ($tableExists('document_uploads')) {
        $appendRows("SELECT 'document_upload' AS event_type, du.id AS event_id, du.application_id,
            du.uploaded_at AS event_time, ba.user_id AS actor_id,
            NULL AS old_status, 'uploaded' AS new_status,
            CONCAT('Uploaded: ', COALESCE(du.original_name, du.document_type, 'Document'), ' (', COALESCE(du.document_type, 'General'), ')') AS notes,
            NULL AS missing_documents_json, ba.reference_no, ba.application_no, bid.business_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name, 1 AS actor_type
            FROM document_uploads du
            LEFT JOIN business_applications ba ON du.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            WHERE du.uploaded_at IS NOT NULL ORDER BY du.uploaded_at DESC LIMIT 500");
    }
    if ($tableExists('business_applications')) {
        $appendRows("SELECT 'application_submitted' AS event_type, ba.id AS event_id, ba.id AS application_id,
            ba.submitted_at AS event_time, ba.user_id AS actor_id,
            'draft' AS old_status, ba.status AS new_status,
            CONCAT('Application submitted — ', COALESCE(at.application_type, 'New'), ' application for ', COALESCE(bid.business_name, 'business')) AS notes,
            NULL AS missing_documents_json, ba.reference_no, ba.application_no, bid.business_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name, 1 AS actor_type
            FROM business_applications ba
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN application_type_data at ON ba.id = at.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            WHERE ba.submitted_at IS NOT NULL ORDER BY ba.submitted_at DESC LIMIT 500");
    }
    if ($tableExists('announcements')) {
        $appendRows("SELECT CONCAT('announcement_', LOWER(a.status)) AS event_type,
            a.id AS event_id, NULL AS application_id,
            a.created_at AS event_time, a.created_by AS actor_id,
            NULL AS old_status, a.status AS new_status,
            CONCAT('Announcement: ', a.title) AS notes, NULL AS missing_documents_json,
            NULL AS reference_no, NULL AS application_no, NULL AS business_name, NULL AS owner_name,
            CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name, acc.user_type AS actor_type
            FROM announcements a
            LEFT JOIN accounts acc ON a.created_by = acc.id
            ORDER BY a.created_at DESC LIMIT 500");
    }

    usort($activityLog, function ($a, $b) { return strtotime($b['event_time'] ?? 0) - strtotime($a['event_time'] ?? 0); });
    $activityLog = array_slice($activityLog, 0, 500);

    if (empty($activityLog) && !empty($applications)) {
        foreach ($applications as $a) {
            $activityLog[] = [
                'event_type' => 'status_change', 'event_id' => $a['id'], 'application_id' => $a['id'],
                'old_status' => '—', 'new_status' => $a['status'], 'actor_id' => null, 'notes' => 'Current status',
                'event_time' => $a['updated_at'] ?? $a['created_at'],
                'reference_no' => $a['reference_no'], 'application_no' => $a['application_no'] ?? null,
                'business_name' => $a['business_name'] ?? null, 'owner_name' => $a['owner_name'] ?? 'Unknown',
                'actor_name' => 'System', 'actor_type' => null,
            ];
        }
    }

    $docMap = ['barangay'=>'Barangay Clearance','dti_sec'=>'DTI/SEC','rpt'=>'RPT','engineering'=>'Engineering','zoning'=>'Zoning Cert (MPDC)','treasury'=>'Treasury','sanitary'=>'Sanitary Health Permit','fire_safety'=>'Fire Safety Cert'];
    foreach ($activityLog as &$log) {
        if (($log['event_type'] ?? '') === 'rejection' && !empty($log['missing_documents_json'])) {
            $missing = json_decode($log['missing_documents_json'], true);
            if (is_array($missing) && count($missing) > 0) {
                $labels = []; foreach ($missing as $key) $labels[] = $docMap[$key] ?? $key;
                $log['notes'] = trim(($log['notes'] ?? '') . ' — Missing: ' . implode(', ', $labels));
            }
        }
    }
    unset($log);

    foreach ($activityLog as $log) {
        $logDate = date('Y-m-d', strtotime($log['event_time'] ?? 'now'));
        if ($logDate === $todayDate) $activityStats['today']++;
        if ($logDate >= $weekAgo) $activityStats['week']++;
        $ns = strtolower($log['new_status'] ?? '');
        if ($ns === 'rejected') $activityStats['reject']++;
        if ($ns === 'verified') $activityStats['verified']++;
        $et = $log['event_type'] ?? '';
        if (in_array($et, ['rejection','fee_assessment','document_upload'], true)) $activityStats['document_actions']++;
        if ($et === 'payment') $activityStats['payment']++;
        if (strpos($et, 'appointment') === 0) $activityStats['appointments']++;
        if ($et === 'document_upload') $activityStats['documents']++;
        if ($et === 'application_submitted') $activityStats['submissions']++;
        $t = (int)($log['actor_type'] ?? 0);
        if ($t === 4) $activityStats['bplo']++;
        elseif ($t === 3) $activityStats['treasury']++;
        elseif ($t === 5) $activityStats['admin']++;
    }
    $activityStats['total'] = count($activityLog);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | Municipality of Placer</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <style>
        :root {
            --brown-darkest:  #2b1810;
            --brown-dark:     #3e2723;
            --brown-primary:  #6d4c41;
            --brown-medium:   #8d6e63;
            --brown-light:    #d7ccc8;
            --brown-accent:   #d4a373;
            --brown-accent-2: #e6c9a8;
            --brown-bg:       #faf5ef;
            --brown-border:   #e8ddd3;
            --brown-text:     #3b2c1f;
            --brown-muted:    #8a7561;
            --success:        #10b981;
            --danger:         #ef4444;
            --warn:           #f59e0b;
            --info:           #3b82f6;
            --purple:         #8b5cf6;
        }
        * { font-family: 'Inter', sans-serif; }
        body { background: var(--brown-bg); overflow-x: hidden; color: var(--brown-text); }

        .gov-header {
            background: var(--brown-darkest); color: #fff; padding: 0.7rem 1.5rem;
            border-bottom: 3px solid var(--brown-accent);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            position: sticky; top: 0; z-index: 1030;
        }

        .logo-circle {
            width: 48px; height: 48px; background: #fff; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            margin-right: 0.85rem; flex-shrink: 0; overflow: hidden;
            box-shadow: 0 0 0 2px rgba(212, 163, 115, 0.45);
            transition: box-shadow 0.2s ease;
        }
        .logo-circle:hover { box-shadow: 0 0 0 3px var(--brown-accent); }
        .logo-circle img { width: 100%; height: 100%; object-fit: contain; display: block; }

        .header-title-text { min-width: 0; overflow: hidden; }
        .header-title-text h5 { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .header-title-text small {
            display: block; white-space: nowrap; overflow: hidden;
            text-overflow: ellipsis; color: #cbb8a6;
        }

        .user-greeting { background: rgba(255,255,255,0.12); border-radius: 40px; padding: 0.3rem 1rem; font-weight: 500; cursor: pointer; }
        .user-greeting:hover { background: rgba(255,255,255,0.2); }

        .sidebar { background: var(--brown-darkest); border-right: 1px solid rgba(255,255,255,.05); min-height: calc(100vh - 72px); padding: 1.25rem 0; }
        .sidebar .nav-link { color: #cbb8a6; padding: 0.85rem 1.25rem; border-radius: 0; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; font-size: .9rem; }
        .sidebar .nav-link i { width: 26px; font-size: 1.1rem; margin-right: 0.5rem; }
        .sidebar .nav-link:hover { background: rgba(255,255,255,0.04); border-left-color: var(--brown-accent); color: #fff; }
        .sidebar .nav-link.active { background: rgba(212,163,115,0.12); border-left-color: var(--brown-accent); color: #fff; font-weight: 600; }

        .main-content { padding: 1.5rem; }
        h3.fw-bold { color: var(--brown-darkest) !important; }

        .data-card { background: #fff; border-radius: 1rem; box-shadow: 0 4px 12px rgba(61,42,25,0.06); overflow: hidden; margin-bottom: 1.5rem; border: 1px solid var(--brown-border); }
        .data-card-header { background: transparent; border-bottom: 1px solid var(--brown-border); padding: 1rem 1.5rem; font-weight: 700; font-size: 1.05rem; color: var(--brown-darkest); }

        .badge-status { padding: 0.3rem 0.8rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 600; white-space: nowrap; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-under-review { background: #dbeafe; color: #1e40af; }
        .badge-pending-payment { background: #fef3c7; color: #92400e; }
        .badge-submitted { background: #ede9fe; color: #5b21b6; }
        .badge-draft { background: #e5e7eb; color: #374151; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        .badge-new-msg { background: #dbeafe; color: #1e40af; }
        .badge-topic { background: #f5ebe0; color: #6d4c41; border: 1px solid var(--brown-light); }

        .sidebar-offcanvas { width: 280px; }
        .offcanvas { background: var(--brown-darkest); color: #fff; }
        .offcanvas .nav-link { color: #cbb8a6; padding: .8rem 1.2rem; border-radius: 0; font-weight: 500; }
        .offcanvas .nav-link:hover, .offcanvas .nav-link.active { background: rgba(255,255,255,0.05); color: #fff; border-left: 3px solid var(--brown-accent); }

        footer { background: #fff; border-top: 1px solid var(--brown-border); text-align: center; padding: 1rem; font-size: 0.8rem; color: var(--brown-muted); margin-top: 2rem; }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { padding: 1rem; }
            .logo-circle { width: 40px; height: 40px; margin-right: 0.6rem; }
        }
        @media (min-width: 769px) { .mobile-menu-btn { display: none; } }
        @media (max-width: 480px) {
            .header-title-text h5 { font-size: 0.9rem; }
            .header-title-text small { font-size: 0.7rem; }
        }

        .dataTables_wrapper .dataTables_filter input { border: 1px solid var(--brown-border); border-radius: 0.375rem; padding: 0.375rem 0.75rem; }

        .form-switch .form-check-input { width: 3.2rem; height: 1.6rem; cursor: pointer; border-radius: 2rem; background-color: #ccc; border: none; position: relative; background-image: none; }
        .form-switch .form-check-input:checked { background-color: var(--success); }
        .form-switch .form-check-input::before { content: ''; position: absolute; width: 1.2rem; height: 1.2rem; top: 0.2rem; left: 0.2rem; background: white; border-radius: 50%; transition: transform 0.3s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
        .form-switch .form-check-input:checked::before { transform: translateX(1.6rem); }
        .verified-label { font-size: 0.75rem; font-weight: 600; }
        .verified-label.on { color: var(--success); }
        .verified-label.off { color: var(--brown-muted); }

        .pay-stat-card {
            background: #fff; border-radius: 1rem; padding: 1.25rem;
            box-shadow: 0 4px 12px rgba(61,42,25,0.05);
            height: 100%; border-left: 4px solid var(--brown-primary);
            transition: transform .15s, box-shadow .15s;
        }
        .pay-stat-card.amber  { border-left-color: var(--warn); }
        .pay-stat-card.blue   { border-left-color: var(--info); }
        .pay-stat-card.green  { border-left-color: var(--success); }
        .pay-stat-card.red    { border-left-color: var(--danger); }
        .pay-stat-card.purple { border-left-color: var(--purple); }
        .pay-stat-card.accent { border-left-color: var(--brown-accent); }

        .pay-stat-card.clickable { cursor: pointer; }
        .pay-stat-card.clickable:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(61,42,25,0.12); }
        .pay-stat-card.clickable:focus-visible { outline: 2px solid var(--brown-accent); outline-offset: 2px; }
        .pay-stat-card.clickable .pay-stat-label i.bi-box-arrow-up-right { font-size: .65rem; opacity: .5; margin-left: 4px; }

        .pay-stat-number { font-size: 1.6rem; font-weight: 800; color: var(--brown-darkest); line-height: 1.2; }
        .pay-stat-label { font-size: 0.8rem; color: var(--brown-muted); font-weight: 500; }
        .pay-stat-sub { font-size: 0.7rem; color: #a5907a; }
        .amount-cell { font-weight: 700; color: var(--brown-darkest); }

        .pay-detail-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px dashed var(--brown-border); }
        .pay-detail-row:last-child { border-bottom: none; }
        .pay-detail-label { color: var(--brown-muted); font-size: 0.85rem; }
        .pay-detail-value { font-weight: 600; color: var(--brown-darkest); font-size: 0.9rem; }
        .pay-detail-total { background: #f5ebe0; border-radius: 0.5rem; padding: 0.75rem 1rem; margin-top: 0.5rem; display: flex; justify-content: space-between; align-items: center; }
        .pay-detail-total .label { font-weight: 700; color: var(--brown-primary); }
        .pay-detail-total .value { font-size: 1.3rem; font-weight: 800; color: var(--brown-primary); }

        .track-step-bar { display: flex; gap: 0.25rem; margin-bottom: 1rem; flex-wrap: wrap; }
        .track-step { flex: 1; min-width: 80px; padding: 0.4rem 0.5rem; border-radius: 0.5rem; background: #f3f4f6; font-size: 0.7rem; text-align: center; font-weight: 600; color: var(--brown-muted); border: 1px solid var(--brown-border); }
        .track-step.done { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .track-step.current { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .track-step.rejected { background: #fee2e2; color: #991b1b; border-color: #fecaca; }

        .doc-chip { display: inline-block; padding: 0.2rem 0.55rem; border-radius: 0.375rem; background: #f0fdf4; color: #166534; font-size: 0.7rem; font-weight: 600; margin: 0.15rem; border: 1px solid #bbf7d0; }
        .doc-chip.missing { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .progress-mini { height: 6px; border-radius: 3px; background: var(--brown-border); overflow: hidden; margin-top: 0.3rem; }
        .progress-mini-fill { height: 100%; background: linear-gradient(90deg, var(--brown-accent), var(--brown-primary)); }
        .app-ref-link { color: var(--brown-primary); font-weight: 700; text-decoration: none; }
        .app-ref-link:hover { color: var(--brown-darkest); text-decoration: underline; }

        #adminAppointmentsCalendar { background: white; border-radius: 0.5rem; }
        .fc .fc-toolbar-title { font-size: 1.2rem !important; color: var(--brown-darkest); font-weight: 700; }
        .fc .fc-button-primary { background-color: var(--brown-primary) !important; border-color: var(--brown-primary) !important; font-size: 0.85rem; }
        .fc .fc-button-primary:hover { background-color: var(--brown-darkest) !important; border-color: var(--brown-darkest) !important; }
        .fc .fc-button-primary:not(:disabled).fc-button-active { background-color: var(--brown-darkest) !important; border-color: var(--brown-darkest) !important; }
        .fc .fc-daygrid-day-number { color: var(--brown-darkest); font-weight: 600; }
        .fc .fc-col-header-cell-cushion { color: var(--brown-darkest); font-weight: 700; }
        .fc-event { cursor: pointer; border-radius: 5px !important; padding: 3px 5px !important; }
        .fc .fc-day-today { background: #faf5ef !important; }
        .fc-event-title { white-space: normal !important; word-break: break-word !important; line-height: 1.15 !important; }
        .fc-daygrid-event { white-space: normal !important; align-items: flex-start !important; }
        .fc-daygrid-day-frame { min-height: 130px !important; }

        #activityFilterBtns .btn { font-size: 0.78rem; border-color: var(--brown-border); color: var(--brown-primary); }
        #activityFilterBtns .btn.active { background-color: var(--brown-primary); color: #fff; border-color: var(--brown-primary); }
        #activityFilterBtns .btn:hover { background-color: #f5ebe0; }

        .msg-preview { max-width: 320px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; color: #4b5563; font-size: 0.85rem; }
        .contact-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--brown-accent), var(--brown-primary)); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; margin-right: 0.5rem; flex-shrink: 0; }

        .reply-modal .modal-content { border: none; border-radius: 14px; box-shadow: 0 20px 60px rgba(61,42,25,.25); }
        .reply-modal .modal-header { background: var(--brown-primary); color: #fff; border: none; border-radius: 14px 14px 0 0; }
        .reply-modal .modal-header .btn-close { filter: invert(1) brightness(2); }

        #statCardModal .modal-content { border: none; border-radius: 14px; overflow: hidden; box-shadow: 0 20px 60px rgba(61,42,25,.25); }
        #statCardModal .modal-header { background: var(--brown-darkest); color: #fff; border-bottom: none; }
        #statCardModal .modal-title .badge { background: var(--brown-accent) !important; color: var(--brown-darkest) !important; }
        #statCardModal .modal-body { max-height: 70vh; overflow-y: auto; }
        #statCardModal table thead th { position: sticky; top: 0; background: #faf5ef; z-index: 1; border-bottom: 2px solid var(--brown-border); font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--brown-muted); }
        #statCardModal table tbody td { padding: .85rem; vertical-align: middle; border-bottom: 1px solid var(--brown-border); font-size: .85rem; }
        #statCardModal .btn-outline-brown { background: #fff; color: var(--brown-primary); border: 1px solid var(--brown-border); border-radius: 8px; padding: .5rem 1rem; font-weight: 600; font-size: .82rem; }
        #statCardModal .btn-outline-brown:hover { background: #f5ebe0; }
        #statCardModal .btn-brown { background: var(--brown-primary); color: #fff; border: none; border-radius: 8px; padding: .5rem 1rem; font-weight: 600; font-size: .82rem; }
        #statCardModal .btn-brown:hover { background: var(--brown-darkest); color: #fff; }

        .btn-success { background: var(--brown-primary); border-color: var(--brown-primary); color: #fff; font-weight: 600; }
        .btn-success:hover { background: var(--brown-darkest); border-color: var(--brown-darkest); }
        .btn-outline-success { color: var(--brown-primary); border-color: var(--brown-primary); }
        .btn-outline-success:hover { background: var(--brown-primary); border-color: var(--brown-primary); color: #fff; }
        .btn-outline-primary { color: var(--brown-primary); border-color: var(--brown-primary); }
        .btn-outline-primary:hover { background: var(--brown-primary); color: #fff; border-color: var(--brown-primary); }
        .btn-outline-danger { color: var(--danger); border-color: var(--danger); }

        .table-hover tbody tr:hover { background: #faf5ef; }
        .alert-info { background: #f5ebe0; color: var(--brown-darkest); border-color: var(--brown-light); }
        .bg-info { background: var(--brown-light) !important; color: var(--brown-darkest) !important; }
        .bg-success { background: var(--brown-primary) !important; }
        .text-success { color: var(--brown-primary) !important; }
        .badge.bg-success { background: var(--brown-primary) !important; }

        .modal-content { border: none; border-radius: 12px; }
        .modal-header { background: var(--brown-primary); color: #fff; border-bottom: none; }
        .modal-header .btn-close { filter: invert(1) brightness(2); }
        .form-control:focus, .form-select:focus { border-color: var(--brown-accent); box-shadow: 0 0 0 3px rgba(212,163,115,.25); }
    </style>
</head>
<body>
<header class="gov-header d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center min-w-0">
        <button class="btn mobile-menu-btn me-2 text-white" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
            <i class="bi bi-list fs-4"></i>
        </button>
        <div class="logo-circle">
            <img src="../img/official-logo.png" alt="Municipality of Placer Logo">
        </div>
        <div class="header-title-text">
            <h5 class="mb-0 fw-bold">Admin Panel</h5>
            <small>Municipality of Placer, SDN</small>
        </div>
    </div>
    <div class="dropdown">
        <a class="dropdown-toggle user-greeting text-decoration-none text-white" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($admin_name); ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="account_settings.php"><i class="bi bi-gear"></i> Account Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
        </ul>
    </div>
</header>

<div class="container-fluid p-0">
    <div class="row g-0">
        <div class="col-md-3 col-lg-2 sidebar p-3">
            <nav class="nav flex-column">
                <a class="nav-link active" href="#" data-section="employees"><i class="bi bi-people"></i> Employees</a>
                <a class="nav-link" href="#" data-section="users"><i class="bi bi-person-vcard"></i> User Accounts</a>
                <a class="nav-link" href="#" data-section="appointments"><i class="bi bi-calendar-check"></i> Appointment Monitoring</a>
                <a class="nav-link" href="#" data-section="tracking"><i class="bi bi-diagram-3"></i> Application Tracking</a>
                <a class="nav-link" href="#" data-section="payments"><i class="bi bi-cash-coin"></i> Payment Monitoring</a>
                <a class="nav-link" href="#" data-section="contact-messages"><i class="bi bi-envelope-paper"></i> Contact Messages</a>
                <a class="nav-link" href="#" data-section="activity-log"><i class="bi bi-clock-history"></i> Activity Log</a>
                <a class="nav-link" href="#" data-section="announcements"><i class="bi bi-megaphone"></i> Announcements</a>
                <a class="nav-link" href="#" data-section="system-settings"><i class="bi bi-sliders"></i> System Settings</a>
            </nav>
        </div>

        <div class="col-md-9 col-lg-10 main-content">
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- EMPLOYEES -->
            <div id="employees-section">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                    <h3 class="fw-bold">Manage Employees</h3>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                        <i class="bi bi-plus-circle"></i> Add Employee
                    </button>
                </div>
                <div class="data-card">
                    <div class="data-card-header"><i class="bi bi-person-lines-fill me-2"></i> Employee List</div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover align-middle" id="employeesTable">
                            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Contact</th><th>Role</th><th class="text-center">Verified</th><th>Created</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php if (count($employees) > 0): foreach ($employees as $emp): ?>
                                    <tr>
                                        <td><?php echo $emp['id']; ?></td>
                                        <td><?php echo htmlspecialchars($emp['firstname'] . ' ' . $emp['lastname']); ?></td>
                                        <td><?php echo htmlspecialchars($emp['email']); ?></td>
                                        <td><?php echo htmlspecialchars($emp['contact_number']); ?></td>
                                        <td><?php $rL=[3=>'Treasurer',4=>'BPLO',5=>'Admin']; echo $rL[$emp['user_type']] ?? 'Unknown'; ?></td>
                                        <td class="text-center">
                                            <div class="form-check form-switch d-inline-flex flex-column align-items-center">
                                                <input class="form-check-input verify-toggle-emp" type="checkbox" role="switch"
                                                       id="verifyEmpSwitch<?php echo $emp['id']; ?>"
                                                       data-emp-id="<?php echo $emp['id']; ?>"
                                                       <?php echo ((int)$emp['verified'] === 1) ? 'checked' : ''; ?>>
                                                <label class="verified-label <?php echo ((int)$emp['verified'] === 1) ? 'on' : 'off'; ?>"
                                                       for="verifyEmpSwitch<?php echo $emp['id']; ?>">
                                                    <?php echo ((int)$emp['verified'] === 1) ? 'Verified' : 'Not Verified'; ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($emp['created_at'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary edit-employee-btn"
                                                    data-id="<?php echo $emp['id']; ?>" data-firstname="<?php echo htmlspecialchars($emp['firstname']); ?>"
                                                    data-lastname="<?php echo htmlspecialchars($emp['lastname']); ?>" data-email="<?php echo htmlspecialchars($emp['email']); ?>"
                                                    data-contact="<?php echo htmlspecialchars($emp['contact_number']); ?>" data-user-type="<?php echo $emp['user_type']; ?>"
                                                    data-bs-toggle="modal" data-bs-target="#editEmployeeModal"><i class="bi bi-pencil"></i></button>

                                            <?php if ((int)$emp['verified'] === 0): ?>
                                                <button class="btn btn-sm btn-outline-success resend-verify-btn"
                                                        data-id="<?php echo $emp['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($emp['firstname'] . ' ' . $emp['lastname']); ?>"
                                                        title="Resend verification email">
                                                    <i class="bi bi-envelope-arrow-up"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-success resend-verify-btn" style="display:none;"
                                                        data-id="<?php echo $emp['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($emp['firstname'] . ' ' . $emp['lastname']); ?>"
                                                        title="Resend verification email">
                                                    <i class="bi bi-envelope-arrow-up"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($emp['id'] != $admin_id): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                                                    <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                                    <button type="submit" name="delete_employee" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="8" class="text-center text-muted">No employees.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- USERS -->
            <div id="users-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                    <h3 class="fw-bold">User Accounts</h3>
                    <span class="badge" style="background:#f5ebe0;color:var(--brown-primary);padding:.6rem 1rem;border-radius:2rem;font-weight:600;"><i class="bi bi-people-fill"></i> <?php echo count($users); ?> registered</span>
                </div>
                <div class="data-card">
                    <div class="data-card-header"><i class="bi bi-person-vcard me-2"></i> Registered Business Owners</div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover align-middle" id="usersTable">
                            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Contact</th><th>Registered</th><th class="text-center">Verified</th></tr></thead>
                            <tbody>
                                <?php if (count($users) > 0): foreach ($users as $u): ?>
                                    <tr>
                                        <td><?php echo $u['id']; ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($u['firstname'] . ' ' . $u['lastname']); ?></td>
                                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                                        <td><?php echo htmlspecialchars($u['contact_number'] ?: '—'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                                        <td class="text-center">
                                            <div class="form-check form-switch d-inline-flex flex-column align-items-center">
                                                <input class="form-check-input verify-toggle" type="checkbox" role="switch" id="verifySwitch<?php echo $u['id']; ?>" data-user-id="<?php echo $u['id']; ?>" <?php echo ($u['verified'] == 1) ? 'checked' : ''; ?>>
                                                <label class="verified-label <?php echo ($u['verified'] == 1) ? 'on' : 'off'; ?>" for="verifySwitch<?php echo $u['id']; ?>"><?php echo ($u['verified'] == 1) ? 'Verified' : 'Not Verified'; ?></label>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No user accounts.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- APPOINTMENTS -->
            <div id="appointments-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                    <h3 class="fw-bold"><i class="bi bi-calendar-check"></i> Appointment Monitoring</h3>
                    <span class="badge" style="background:#f5ebe0;color:var(--brown-primary);padding:.6rem 1rem;border-radius:2rem;font-weight:600;"><i class="bi bi-calendar3"></i> <?php echo $apptStats['total']; ?> appointments</span>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-2"><div class="pay-stat-card amber clickable" data-admin-appt-card="pending" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $apptStats['pending']; ?></div><div class="pay-stat-label">Pending</i></div><div class="pay-stat-sub">Awaiting confirmation</div></div></div>
                    <div class="col-6 col-lg-2"><div class="pay-stat-card blue clickable" data-admin-appt-card="confirmed" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $apptStats['confirmed']; ?></div><div class="pay-stat-label">Confirmed</div><div class="pay-stat-sub">Ready to claim</div></div></div>
                    <div class="col-6 col-lg-2"><div class="pay-stat-card green clickable" data-admin-appt-card="completed" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $apptStats['completed']; ?></div><div class="pay-stat-label">Completed</div><div class="pay-stat-sub">Claimed</div></div></div>
                    <div class="col-6 col-lg-2"><div class="pay-stat-card red clickable" data-admin-appt-card="cancelled" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $apptStats['cancelled']; ?></div><div class="pay-stat-label">Cancelled</div><div class="pay-stat-sub">No-show</div></div></div>
                    <div class="col-6 col-lg-2"><div class="pay-stat-card purple clickable" data-admin-appt-card="today" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $apptStats['today']; ?></div><div class="pay-stat-label">Today</div><div class="pay-stat-sub">Scheduled today</div></div></div>
                    <div class="col-6 col-lg-2"><div class="pay-stat-card accent clickable" data-admin-appt-card="upcoming" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $apptStats['upcoming']; ?></div><div class="pay-stat-label">Upcoming</div><div class="pay-stat-sub">Next 7 days</div></div></div>
                </div>
                <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i> Legend: <span class="badge ms-2" style="background:#d4a373;color:#2b1810;">Pending</span> <span class="badge ms-2" style="background:#3b82f6;">Confirmed</span> <span class="badge ms-2" style="background:#10b981;">Completed</span> <span class="badge ms-2" style="background:#ef4444;">Cancelled</span></div>
                <div class="data-card">
                    <div class="data-card-header d-flex justify-content-between align-items-center flex-wrap">
                        <span><i class="bi bi-calendar3 me-2"></i> Calendar View</span>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-success" onclick="changeAdminCalendarView('dayGridMonth')">Month</button>
                            <button type="button" class="btn btn-outline-success" onclick="changeAdminCalendarView('timeGridWeek')">Week</button>
                            <button type="button" class="btn btn-outline-success" onclick="changeAdminCalendarView('timeGridDay')">Day</button>
                            <button type="button" class="btn btn-outline-success" onclick="changeAdminCalendarView('listWeek')">List</button>
                        </div>
                    </div>
                    <div class="p-3"><div id="adminAppointmentsCalendar"></div></div>
                </div>
            </div>

            <!-- TRACKING -->
            <div id="tracking-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                    <h3 class="fw-bold">Application Tracking</h3>
                    <span class="badge" style="background:#f5ebe0;color:var(--brown-primary);padding:.6rem 1rem;border-radius:2rem;font-weight:600;"><i class="bi bi-file-earmark-text"></i> <?php echo $totalApps; ?> total</span>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3"><div class="pay-stat-card purple clickable" data-admin-track-card="submitted" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $appSubmitted; ?></div><div class="pay-stat-label"></div><div class="pay-stat-sub">Awaiting review</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card blue clickable" data-admin-track-card="under_review" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $appUnderReview; ?></div><div class="pay-stat-label">Under Review</div><div class="pay-stat-sub">Processing</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card amber clickable" data-admin-track-card="pending_payment" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $appPendingPayment; ?></div><div class="pay-stat-label">Pending Payment</div><div class="pay-stat-sub">Awaiting fees</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card green clickable" data-admin-track-card="approved" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $appApproved; ?></div><div class="pay-stat-label">Approved / Verified</div><div class="pay-stat-sub">Completed</div></div></div>
                </div>
                <div class="data-card">
                    <div class="data-card-header"><i class="bi bi-diagram-3 me-2"></i> All Applications Progress</div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover align-middle" id="trackingTable">
                            <thead><tr><th>Reference</th><th>Business / Owner</th><th>Type</th><th>Step</th><th>Documents</th><th>Progress</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php if (count($applications) > 0): foreach ($applications as $app):
                                    $st = strtolower($app['status']); $bc = 'badge-pending'; $bl = ucfirst(str_replace('_', ' ', $st));
                                    if ($st === 'submitted') $bc = 'badge-submitted';
                                    elseif ($st === 'under_review') $bc = 'badge-under-review';
                                    elseif ($st === 'pending_payment') $bc = 'badge-pending-payment';
                                    elseif (in_array($st, ['approved', 'claimed', 'verified'])) $bc = 'badge-approved';
                                    elseif ($st === 'rejected') $bc = 'badge-rejected';
                                    elseif ($st === 'cancelled') $bc = 'badge-cancelled';
                                    $so = ['submitted'=>1,'under_review'=>2,'pending_payment'=>3,'approved'=>4,'claimed'=>4,'verified'=>4];
                                    $cs = $so[$st] ?? 1; $prog = min(100, ($cs/4)*100);
                                    if ($st === 'rejected' || $st === 'cancelled') $prog = 100;
                                    $docC = (int)($app['doc_count'] ?? 0);
                                ?>
                                    <tr>
                                        <td><a href="../permit.php?ref=<?php echo urlencode($app['reference_no']); ?>" target="_blank" class="app-ref-link"><?php echo htmlspecialchars($app['reference_no']); ?> <i class= style="font-size:0.7rem;"></i></a></td>
                                        <td><span class="fw-semibold"><?php echo htmlspecialchars($app['business_name'] ?? '—'); ?></span><div class="small text-muted"><i class="bi bi-person"></i> <?php echo htmlspecialchars($app['owner_name'] ?? 'Unknown'); ?></div></td>
                                        <td><span class="badge" style="background:#f5ebe0;color:var(--brown-primary);padding:.35rem .7rem;border-radius:2rem;font-weight:600;"><?php echo htmlspecialchars($app['application_type'] ?? 'New'); ?></span></td>
                                        <td><span class="fw-semibold">Step <?php echo (int)($app['current_step'] ?? 1); ?>/8</span><div class="small text-muted"><?php echo $bl; ?></div></td>
                                        <td><?php if ($docC > 0): ?><span class="doc-chip"><i class="bi bi-check-circle"></i> <?php echo $docC; ?> uploaded</span><?php else: ?><span class="doc-chip missing"><i class="bi bi-x-circle"></i> No docs</span><?php endif; ?></td>
                                        <td style="min-width:120px;"><div class="d-flex justify-content-between" style="font-size:0.7rem;"><span class="text-muted">Progress</span><span class="fw-bold"><?php echo round($prog); ?>%</span></div><div class="progress-mini"><div class="progress-mini-fill" style="width: <?php echo $prog; ?>%;<?php if ($st === 'rejected' || $st === 'cancelled'): ?>background: linear-gradient(90deg, #ef4444, #f87171);<?php endif; ?>"></div></div></td>
                                        <td><?php echo !empty($app['submitted_at']) ? date('M d, Y', strtotime($app['submitted_at'])) : '<span class="text-muted">Not submitted</span>'; ?></td>
                                        <td><span class="badge-status <?php echo $bc; ?>"><?php echo $bl; ?></span></td>
                                        <td><button class="btn btn-sm btn-outline-primary view-app-btn" data-id="<?php echo $app['id']; ?>" data-ref="<?php echo htmlspecialchars($app['reference_no']); ?>" data-business="<?php echo htmlspecialchars($app['business_name'] ?? ''); ?>" data-owner="<?php echo htmlspecialchars($app['owner_name'] ?? ''); ?>" data-email="<?php echo htmlspecialchars($app['owner_email'] ?? ''); ?>" data-contact="<?php echo htmlspecialchars($app['owner_contact'] ?? ''); ?>" data-type="<?php echo htmlspecialchars($app['application_type'] ?? 'New'); ?>" data-step="<?php echo (int)($app['current_step'] ?? 1); ?>" data-status="<?php echo htmlspecialchars($app['status']); ?>" data-submitted="<?php echo htmlspecialchars($app['submitted_at'] ?? ''); ?>" data-issued="<?php echo htmlspecialchars($app['issued_at'] ?? ''); ?>" data-expiry="<?php echo htmlspecialchars($app['expiry_date'] ?? ''); ?>" data-tax-year="<?php echo htmlspecialchars($app['tax_year'] ?? ''); ?>" data-doc-count="<?php echo (int)($app['doc_count'] ?? 0); ?>" data-rejections="<?php echo (int)($app['rejection_count'] ?? 0); ?>" data-amount="<?php echo (float)($app['assessed_amount'] ?? 0); ?>" data-bs-toggle="modal" data-bs-target="#appTrackModal"><i class="bi bi-eye"></i></button></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No applications.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- PAYMENTS -->
            <div id="payments-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                    <h3 class="fw-bold">Payment Monitoring</h3>
                    <span class="badge" style="background:#f5ebe0;color:var(--brown-primary);padding:.6rem 1rem;border-radius:2rem;font-weight:600;"><i class="bi bi-receipt"></i> <?php echo count($payments); ?> assessments</span>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3"><div class="pay-stat-card accent clickable" data-admin-pay-card="assessed" role="button" tabindex="0"><div class="pay-stat-number">₱<?php echo number_format($totalAssessed, 2); ?></div><div class="pay-stat-label">Total Assessed</div><div class="pay-stat-sub">All assessments</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card green clickable" data-admin-pay-card="collected" role="button" tabindex="0"><div class="pay-stat-number">₱<?php echo number_format($totalCollected, 2); ?></div><div class="pay-stat-label">Total Collected</div><div class="pay-stat-sub">Approved & verified</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card amber clickable" data-admin-pay-card="pending" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $pendingCount; ?></div><div class="pay-stat-label">Pending Payments</div><div class="pay-stat-sub">Awaiting settlement</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card blue clickable" data-admin-pay-card="paid" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $paidCount; ?></div><div class="pay-stat-label">Paid Accounts</div><div class="pay-stat-sub">Cleared</div></div></div>
                </div>
                <div class="data-card">
                    <div class="data-card-header"><i class="bi bi-cash-coin me-2"></i> Fee Assessments & Payments</div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover align-middle" id="paymentsTable">
                            <thead><tr><th>ID</th><th>Reference</th><th>Business Owner</th><th>Tax Year</th><th>Total Amount</th><th>Assessed By</th><th>Assessed At</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php if (count($payments) > 0): foreach ($payments as $p):
                                    $st = strtolower($p['app_status'] ?? ''); $bc = 'badge-pending'; $bl = 'Pending';
                                    if (in_array($st, ['approved', 'claimed', 'verified'])) { $bc = 'badge-approved'; $bl = ucfirst($st); }
                                    elseif ($st === 'rejected') { $bc = 'badge-rejected'; $bl = 'Rejected'; }
                                    elseif ($st === 'under_review') { $bc = 'badge-under-review'; $bl = 'Under Review'; }
                                    elseif ($st === 'pending_payment') { $bc = 'badge-pending-payment'; $bl = 'Pending Payment'; }
                                ?>
                                    <tr>
                                        <td><?php echo $p['assessment_id']; ?></td>
                                        <td><span class="fw-semibold"><?php echo htmlspecialchars($p['reference_no'] ?? '—'); ?></span></td>
                                        <td><span class="fw-semibold"><?php echo htmlspecialchars($p['owner_name'] ?? 'Unknown'); ?></span><div class="small text-muted"><?php echo htmlspecialchars($p['owner_email'] ?? ''); ?></div></td>
                                        <td><?php echo htmlspecialchars($p['tax_year'] ?? '—'); ?></td>
                                        <td class="amount-cell">₱<?php echo number_format((float)($p['total_amount'] ?? 0), 2); ?></td>
                                        <td><?php echo htmlspecialchars($p['assessor_name'] ?? '—'); ?></td>
                                        <td><?php echo !empty($p['assessed_at']) ? date('M d, Y g:i A', strtotime($p['assessed_at'])) : '—'; ?></td>
                                        <td><span class="badge-status <?php echo $bc; ?>"><?php echo $bl; ?></span></td>
                                        <td><button class="btn btn-sm btn-outline-primary view-payment-btn" data-id="<?php echo $p['assessment_id']; ?>" data-ref="<?php echo htmlspecialchars($p['reference_no'] ?? ''); ?>" data-owner="<?php echo htmlspecialchars($p['owner_name'] ?? ''); ?>" data-gst="<?php echo (float)($p['gross_sales_tax'] ?? 0); ?>" data-mayor="<?php echo (float)($p['mayors_permit_fee'] ?? 0); ?>" data-garbage="<?php echo (float)($p['garbage_charges'] ?? 0); ?>" data-sanitary="<?php echo (float)($p['sanitary_inspection_fee'] ?? 0); ?>" data-building="<?php echo (float)($p['building_inspection_fee'] ?? 0); ?>" data-electrical="<?php echo (float)($p['electrical_inspection_fee'] ?? 0); ?>" data-others="<?php echo (float)($p['others'] ?? 0); ?>" data-fire="<?php echo (float)($p['fire_safety_fee_percent'] ?? 0); ?>" data-total="<?php echo (float)($p['total_amount'] ?? 0); ?>" data-status="<?php echo htmlspecialchars($p['app_status'] ?? ''); ?>" data-app-id="<?php echo (int)($p['application_id'] ?? 0); ?>" data-bs-toggle="modal" data-bs-target="#paymentDetailModal"><i class="bi bi-eye"></i></button></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No assessments.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- CONTACT MESSAGES -->
            <div id="contact-messages-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                    <h3 class="fw-bold"><i class="bi bi-envelope-paper"></i> Contact Messages</h3>
                    <span class="badge" style="background:#f5ebe0;color:var(--brown-primary);padding:.6rem 1rem;border-radius:2rem;font-weight:600;"><i class="bi bi-inbox-fill"></i> <?php echo count($contactMessages); ?> messages</span>
                </div>
                <div class="row g-3 mb-4">
                    <?php
                        $todayMsg = 0; $thisWeekMsg = 0; $uniqueTopics = [];
                        foreach ($contactMessages as $m) {
                            if (date('Y-m-d', strtotime($m['created_at'])) === $todayDate) $todayMsg++;
                            if (date('Y-m-d', strtotime($m['created_at'])) >= $weekAgo) $thisWeekMsg++;
                            $uniqueTopics[$m['topic']] = true;
                        }
                    ?>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card blue clickable" data-admin-msg-card="all" role="button" tabindex="0"><div class="pay-stat-number"><?php echo count($contactMessages); ?></div><div class="pay-stat-label">Total Messages</div><div class="pay-stat-sub">All time</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card purple clickable" data-admin-msg-card="today" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $todayMsg; ?></div><div class="pay-stat-label">Today</div><div class="pay-stat-sub">Received today</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card green clickable" data-admin-msg-card="topics" role="button" tabindex="0"><div class="pay-stat-number"><?php echo count($uniqueTopics); ?></div><div class="pay-stat-label">Unique Topics</div><div class="pay-stat-sub">Distinct categories</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card amber clickable" data-admin-msg-card="week" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $thisWeekMsg; ?></div><div class="pay-stat-label">This Week</div><div class="pay-stat-sub">Last 7 days</div></div></div>
                </div>
                <div class="data-card">
                    <div class="data-card-header"><i class="bi bi-inbox me-2"></i> Inbox</div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover align-middle" id="contactMessagesTable">
                            <thead><tr><th>ID</th><th>Sender</th><th>Email</th><th>Topic</th><th>Message</th><th>Received</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php if (count($contactMessages) > 0): foreach ($contactMessages as $m):
                                    $initial = strtoupper(substr(trim($m['name'] ?: '?'), 0, 1));
                                    $shortMsg = mb_strlen($m['message']) > 120 ? mb_substr($m['message'], 0, 120) . '…' : $m['message'];
                                ?>
                                    <tr>
                                        <td><?php echo $m['id']; ?></td>
                                        <td><div class="d-flex align-items-center"><div class="contact-avatar"><?php echo htmlspecialchars($initial); ?></div><span class="fw-semibold"><?php echo htmlspecialchars($m['name']); ?></span></div></td>
                                        <td><a href="mailto:<?php echo htmlspecialchars($m['email']); ?>" class="text-decoration-none"><?php echo htmlspecialchars($m['email']); ?></a></td>
                                        <td><span class="badge-status badge-topic"><?php echo htmlspecialchars($m['topic']); ?></span></td>
                                        <td><div class="msg-preview" title="<?php echo htmlspecialchars($m['message']); ?>"><?php echo htmlspecialchars($shortMsg); ?></div></td>
                                        <td><div class="fw-semibold"><?php echo date('M d, Y', strtotime($m['created_at'])); ?></div><small class="text-muted"><i class="bi bi-clock"></i> <?php echo date('g:i A', strtotime($m['created_at'])); ?></small></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-success view-msg-btn"
                                                    data-id="<?php echo $m['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($m['name']); ?>"
                                                    data-email="<?php echo htmlspecialchars($m['email']); ?>"
                                                    data-topic="<?php echo htmlspecialchars($m['topic']); ?>"
                                                    data-message="<?php echo htmlspecialchars($m['message']); ?>"
                                                    data-date="<?php echo date('M d, Y g:i A', strtotime($m['created_at'])); ?>"
                                                    title="View"><i class="bi bi-eye"></i></button>

                                            <button class="btn btn-sm btn-success reply-msg-btn"
                                                    data-id="<?php echo $m['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($m['name']); ?>"
                                                    data-email="<?php echo htmlspecialchars($m['email']); ?>"
                                                    data-topic="<?php echo htmlspecialchars($m['topic']); ?>"
                                                    data-message="<?php echo htmlspecialchars($m['message']); ?>"
                                                    data-bs-toggle="modal" data-bs-target="#replyMessageModal"
                                                    title="Reply"><i class="bi bi-reply"></i> Reply</button>

                                            <button class="btn btn-sm btn-outline-danger delete-msg-btn"
                                                    data-id="<?php echo $m['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($m['name']); ?>"
                                                    title="Delete"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">No contact messages yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ACTIVITY LOG -->
            <div id="activity-log-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                    <h3 class="fw-bold">Activity Log</h3>
                    <span class="badge" style="background:#f5ebe0;color:var(--brown-primary);padding:.6rem 1rem;border-radius:2rem;font-weight:600;"><i class="bi bi-clock-history"></i> <?php echo $activityStats['total']; ?> records</span>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3"><div class="pay-stat-card accent clickable" data-admin-act-card="total" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['total']; ?></div><div class="pay-stat-label">Total Activities</div><div class="pay-stat-sub">All sources</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card blue clickable" data-admin-act-card="today" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['today']; ?></div><div class="pay-stat-label">Today</div><div class="pay-stat-sub">Actions today</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card purple clickable" data-admin-act-card="week" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['week']; ?></div><div class="pay-stat-label">This Week</div><div class="pay-stat-sub">Last 7 days</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card red clickable" data-admin-act-card="reject" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['reject']; ?></div><div class="pay-stat-label">Rejections</div><div class="pay-stat-sub">Rejected</div></div></div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3"><div class="pay-stat-card green clickable" data-admin-act-card="bplo" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['bplo']; ?></div><div class="pay-stat-label">BPLO Actions</div><div class="pay-stat-sub">Licensing / Approval</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card amber clickable" data-admin-act-card="treasury" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['treasury']; ?></div><div class="pay-stat-label">Treasury Actions</div><div class="pay-stat-sub">Payment / Fees</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card clickable" data-admin-act-card="admin" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['admin']; ?></div><div class="pay-stat-label">Admin Actions</div><div class="pay-stat-sub">System admin</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card blue clickable" data-admin-act-card="payments" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['payment']; ?></div><div class="pay-stat-label">PayPal Payments</div><div class="pay-stat-sub">Payment events</div></div></div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3"><div class="pay-stat-card purple clickable" data-admin-act-card="appointments" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['appointments']; ?></div><div class="pay-stat-label">Appointment Events</div><div class="pay-stat-sub">Bookings & updates</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card blue clickable" data-admin-act-card="documents" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['documents']; ?></div><div class="pay-stat-label">Document Uploads</div><div class="pay-stat-sub">Files uploaded</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card amber clickable" data-admin-act-card="submissions" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['submissions']; ?></div><div class="pay-stat-label">Submissions</div><div class="pay-stat-sub">New applications</div></div></div>
                    <div class="col-6 col-lg-3"><div class="pay-stat-card green clickable" data-admin-act-card="verified" role="button" tabindex="0"><div class="pay-stat-number"><?php echo $activityStats['verified']; ?></div><div class="pay-stat-label">Verified</div><div class="pay-stat-sub">Verified applications</div></div></div>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap mb-3 gap-2">
                    <div class="btn-group btn-group-sm flex-wrap" role="group" id="activityFilterBtns">
                        <button type="button" class="btn btn-outline-success active" data-role="all"><i class="bi bi-list-ul"></i> All</button>
                        <button type="button" class="btn btn-outline-success" data-role="payment"><i class="bi bi-paypal"></i> Payments</button>
                        <button type="button" class="btn btn-outline-success" data-role="4"><i class="bi bi-file-earmark-check"></i> BPLO</button>
                        <button type="button" class="btn btn-outline-success" data-role="3"><i class="bi bi-cash-coin"></i> Treasury</button>
                        <button type="button" class="btn btn-outline-success" data-role="5"><i class="bi bi-shield-lock"></i> Admin</button>
                        <button type="button" class="btn btn-outline-success" data-role="0"><i class="bi bi-gear"></i> System</button>
                    </div>
                    <div class="badge" style="background:#f5ebe0;color:var(--brown-primary);padding:.4rem .8rem;border-radius:2rem;font-size:.72rem;"><i class="bi bi-info-circle"></i> Click reference to view application</div>
                </div>

                <div class="data-card">
                    <div class="data-card-header"><i class="bi bi-clock-history me-2"></i> All Transaction Records</div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover align-middle" id="activityLogTable">
                            <thead><tr><th>#</th><th>Date & Time</th><th>Event</th><th>Reference</th><th>Business / Owner</th><th>Previous</th><th>New Status</th><th>Performed By</th><th>Notes</th></tr></thead>
                            <tbody>
                                <?php if (count($activityLog) > 0): ?>
                                    <?php $counter = 1; foreach ($activityLog as $log):
                                        $newSt = strtolower($log['new_status'] ?? '');
                                        $oldSt = strtolower($log['old_status'] ?? '');
                                        $eventType = $log['event_type'] ?? 'status_change';
                                        $newBadge = 'badge-pending';
                                        if ($newSt === 'submitted') $newBadge = 'badge-submitted';
                                        elseif ($newSt === 'under_review') $newBadge = 'badge-under-review';
                                        elseif ($newSt === 'pending_payment') $newBadge = 'badge-pending-payment';
                                        elseif ($newSt === 'payment_confirmed') $newBadge = 'badge-under-review';
                                        elseif ($newSt === 'completed' || $newSt === 'paid') $newBadge = 'badge-approved';
                                        elseif (in_array($newSt, ['approved','claimed','verified','assessed','uploaded','booked'])) $newBadge = 'badge-approved';
                                        elseif (in_array($newSt, ['rejected','voided','failed'])) $newBadge = 'badge-rejected';
                                        elseif ($newSt === 'cancelled') $newBadge = 'badge-cancelled';
                                        $oldBadge = 'badge-draft';
                                        if (in_array($oldSt, ['approved','claimed','verified'])) $oldBadge = 'badge-approved';
                                        elseif ($oldSt === 'rejected') $oldBadge = 'badge-rejected';
                                        elseif ($oldSt === 'under_review') $oldBadge = 'badge-under-review';
                                        elseif ($oldSt === 'pending_payment') $oldBadge = 'badge-pending-payment';
                                        elseif ($oldSt === 'submitted') $oldBadge = 'badge-submitted';
                                        $eventIcon = 'bi-arrow-repeat'; $eventLabel = 'Status Change'; $eventColor = '#6366f1';
                                        switch ($eventType) {
                                            case 'fee_assessment': $eventIcon='bi-cash-coin'; $eventLabel='Fee Assessment'; $eventColor='#f59e0b'; break;
                                            case 'rejection': case 'document_rejection': $eventIcon='bi-file-earmark-x'; $eventLabel='Rejection'; $eventColor='#ef4444'; break;
                                            case 'payment': $eventIcon='bi-paypal'; $eventLabel='PayPal Payment'; $eventColor='#0070ba'; break;
                                            case 'appointment_created': $eventIcon='bi-calendar-plus'; $eventLabel='Appointment Booked'; $eventColor='#8b5cf6'; break;
                                            case 'appointment_confirmed': $eventIcon='bi-calendar-check'; $eventLabel='Appointment Confirmed'; $eventColor='#3b82f6'; break;
                                            case 'appointment_completed': $eventIcon='bi-calendar-check-fill'; $eventLabel='Appointment Completed'; $eventColor='#10b981'; break;
                                            case 'appointment_cancelled': $eventIcon='bi-calendar-x'; $eventLabel='Appointment Cancelled'; $eventColor='#ef4444'; break;
                                            case 'document_upload': $eventIcon='bi-cloud-upload'; $eventLabel='Document Uploaded'; $eventColor='#06b6d4'; break;
                                            case 'application_submitted': $eventIcon='bi-file-earmark-plus'; $eventLabel='Application Submitted'; $eventColor='#10b981'; break;
                                            default: if (strpos($eventType,'announcement_')===0) { $eventIcon='bi-megaphone'; $eventLabel='Announcement'; $eventColor='#f59e0b'; }
                                        }
                                        $actorT = (int)($log['actor_type'] ?? 0);
                                        $roleLabel = 'System'; $roleBadge = 'background:#e5e7eb; color:#374151;';
                                        if ($actorT === 3) { $roleLabel='Treasury'; $roleBadge='background:#fef3c7; color:#92400e;'; }
                                        elseif ($actorT === 4) { $roleLabel='BPLO'; $roleBadge='background:#d1fae5; color:#065f46;'; }
                                        elseif ($actorT === 5) { $roleLabel='Admin'; $roleBadge='background:#e9d5ff; color:#6b21a8;'; }
                                    ?>
                                        <tr data-actor-role="<?php echo $actorT; ?>" data-event-type="<?php echo htmlspecialchars($eventType, ENT_QUOTES); ?>">
                                            <td><?php echo $counter++; ?></td>
                                            <td data-order="<?php echo (int)strtotime($log['event_time']); ?>"><div class="fw-semibold"><?php echo date('M d, Y', strtotime($log['event_time'])); ?></div><small class="text-muted"><i class="bi bi-clock"></i> <?php echo date('g:i A', strtotime($log['event_time'])); ?></small></td>
                                            <td><span style="display:inline-flex; align-items:center; gap:0.3rem; background:<?php echo $eventColor; ?>22; color:<?php echo $eventColor; ?>; padding:0.25rem 0.6rem; border-radius:2rem; font-size:0.72rem; font-weight:600; white-space:nowrap;"><i class="bi <?php echo $eventIcon; ?>"></i> <?php echo $eventLabel; ?></span></td>
                                            <td><?php if (!empty($log['reference_no'])): ?><a href="../permit.php?ref=<?php echo urlencode($log['reference_no']); ?>" target="_blank" class="app-ref-link"><?php echo htmlspecialchars($log['reference_no']); ?> <i class= style="font-size:0.7rem;"></i></a><?php else: ?><span class="text-muted">—</span><?php endif; ?><?php if (!empty($log['application_no'])): ?><div class="small text-muted"><?php echo htmlspecialchars($log['application_no']); ?></div><?php endif; ?></td>
                                            <td><div class="fw-semibold"><?php echo htmlspecialchars($log['business_name'] ?? '—'); ?></div><small class="text-muted"><i class="bi bi-person"></i> <?php echo htmlspecialchars($log['owner_name'] ?? 'Unknown'); ?></small></td>
                                            <td><?php if ($oldSt && $oldSt !== '—'): ?><span class="badge-status <?php echo $oldBadge; ?>"><?php echo ucfirst(str_replace('_',' ', $oldSt)); ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                                            <td><span class="badge-status <?php echo $newBadge; ?>"><?php echo ucfirst(str_replace('_',' ', $newSt)); ?></span></td>
                                            <td><div class="fw-semibold"><?php echo htmlspecialchars($log['actor_name'] ?? 'System'); ?></div><small style="display:inline-block; padding:0.15rem 0.5rem; border-radius:1rem; font-size:0.65rem; font-weight:700; <?php echo $roleBadge; ?>"><?php echo $roleLabel; ?></small></td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($log['notes'] ?? '—'); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No activity records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ANNOUNCEMENTS -->
            <div id="announcements-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                    <h3 class="fw-bold">Manage Announcements</h3>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal"><i class="bi bi-plus-circle"></i> Add Announcement</button>
                </div>
                <div class="data-card">
                    <div class="data-card-header"><i class="bi bi-megaphone me-2"></i> All Announcements</div>
                    <div class="table-responsive p-3">
                        <table class="table table-hover align-middle" id="announcementsTable">
                            <thead><tr><th>ID</th><th>Title</th><th>Content</th><th>Created By</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php if (count($announcements) > 0): foreach ($announcements as $ann): ?>
                                    <tr>
                                        <td><?php echo $ann['id']; ?></td>
                                        <td><?php echo htmlspecialchars($ann['title']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($ann['content'], 0, 50)) . (strlen($ann['content']) > 50 ? '...' : ''); ?></td>
                                        <td><?php echo htmlspecialchars($ann['creator_name'] ?? 'Unknown'); ?></td>
                                        <td><?php $sc = 'badge-pending'; if ($ann['status'] == 'approved') $sc = 'badge-approved'; elseif ($ann['status'] == 'rejected') $sc = 'badge-rejected'; ?><span class="badge-status <?php echo $sc; ?>"><?php echo ucfirst($ann['status']); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></td>
                                        <td>
                                            <?php if ($ann['status'] == 'pending'): ?>
                                                <form method="POST" style="display:inline-block;">
                                                    <input type="hidden" name="id" value="<?php echo $ann['id']; ?>">
                                                    <button type="submit" name="approve_announcement" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i></button>
                                                    <button type="submit" name="reject_announcement" class="btn btn-sm btn-danger"><i class="bi bi-x-lg"></i></button>
                                                </form>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-outline-primary edit-announcement-btn" data-id="<?php echo $ann['id']; ?>" data-title="<?php echo htmlspecialchars($ann['title']); ?>" data-content="<?php echo htmlspecialchars($ann['content']); ?>" data-bs-toggle="modal" data-bs-target="#editAnnouncementModal"><i class="bi bi-pencil"></i></button>
                                            <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete?');"><input type="hidden" name="id" value="<?php echo $ann['id']; ?>"><button type="submit" name="delete_announcement" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="7" class="text-center text-muted">No announcements.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- SYSTEM SETTINGS -->
            <div id="system-settings-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
                    <h3 class="fw-bold"><i class="bi bi-sliders"></i> System Settings</h3>
                    <span class="badge" style="background:#f5ebe0;color:var(--brown-primary);padding:.6rem 1rem;border-radius:2rem;font-weight:600;">
                        <i class="bi bi-pencil-square"></i> Edit public page content
                    </span>
                </div>
            
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Edits here update the live text on the public pages
                    <strong>about.php</strong>, <strong>contact.php</strong>,
                    <strong>index.php</strong>, and <strong>permit.php</strong>.
                </div>
            
                <?php
                $groups = [
                    'home'    => ['label' => 'Home Page',    'icon' => 'bi-house-door'],
                    'about'   => ['label' => 'About Page',   'icon' => 'bi-info-circle'],
                    'contact' => ['label' => 'Contact Page', 'icon' => 'bi-envelope-paper'],
                    'permit'  => ['label' => 'Permit Page',  'icon' => 'bi-file-earmark-text'],
                ];
            
                foreach ($groups as $groupKey => $groupMeta):
                    $stmt = $conn->prepare(
                        "SELECT setting_key, setting_value, setting_type,
                                setting_label, setting_hint
                           FROM system_settings
                          WHERE setting_group = ?
                       ORDER BY display_order ASC, id ASC"
                    );
                    $stmt->bind_param("s", $groupKey);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
            
                    if (empty($rows)) continue;
                ?>
            
                <div class="data-card mb-4">
                    <div class="data-card-header">
                        <i class="bi <?= $groupMeta['icon'] ?> me-2"></i>
                        <?= htmlspecialchars($groupMeta['label']) ?>
                        <span class="badge ms-2" style="background:#f5ebe0;color:var(--brown-primary);">
                            <?= count($rows) ?> field<?= count($rows) === 1 ? '' : 's' ?>
                        </span>
                    </div>
            
                    <form method="POST" class="p-3">
                        <input type="hidden" name="save_system_settings" value="1">
            
                        <div class="row g-3">
                            <?php foreach ($rows as $r):
                                $type  = $r['setting_type'];
                                $key   = htmlspecialchars($r['setting_key'], ENT_QUOTES);
                                $value = (string)$r['setting_value'];
                                $colClass = in_array($type, ['textarea','html'], true) ? 'col-12' : 'col-md-6';
                            ?>
                            <div class="<?= $colClass ?>">
                                <label class="form-label fw-semibold small">
                                    <?= htmlspecialchars($r['setting_label']) ?>
                                </label>
                                <input type="hidden" name="setting_key[]" value="<?= $key ?>">
            
                                <?php if ($type === 'textarea'): ?>
                                    <textarea class="form-control" name="setting_value[]" rows="3"><?= htmlspecialchars($value) ?></textarea>
                                <?php elseif ($type === 'html'): ?>
                                    <textarea class="form-control font-monospace" name="setting_value[]" rows="3"><?= htmlspecialchars($value) ?></textarea>
                                    <small class="text-muted">HTML allowed (e.g. <code>&lt;br&gt;</code>).</small>
                                <?php elseif ($type === 'email'): ?>
                                    <input type="email" class="form-control" name="setting_value[]" value="<?= htmlspecialchars($value) ?>">
                                <?php elseif ($type === 'url'): ?>
                                    <input type="url" class="form-control" name="setting_value[]" value="<?= htmlspecialchars($value) ?>">
                                <?php elseif ($type === 'number'): ?>
                                    <input type="number" class="form-control" name="setting_value[]" value="<?= htmlspecialchars($value) ?>">
                                <?php else: ?>
                                    <input type="text" class="form-control" name="setting_value[]" value="<?= htmlspecialchars($value) ?>">
                                <?php endif; ?>
            
                                <?php if (!empty($r['setting_hint'])): ?>
                                    <small class="text-muted d-block mt-1"><?= htmlspecialchars($r['setting_hint']) ?></small>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
            
                        <div class="mt-3 text-end">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save"></i> Save <?= htmlspecialchars($groupMeta['label']) ?>
                            </button>
                        </div>
                    </form>
                </div>
            
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<footer><p class="mb-0">© <?php echo date('Y'); ?> Municipality of Placer, Surigao del Norte. All rights reserved. | Admin Panel v1.0</p></footer>

<!-- Offcanvas -->
<div class="offcanvas offcanvas-start sidebar-offcanvas" tabindex="-1" id="sidebarOffcanvas">
    <div class="offcanvas-header"><h5 class="offcanvas-title">Menu</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div>
    <div class="offcanvas-body p-0">
        <nav class="nav flex-column">
            <a class="nav-link active" href="#" data-section="employees" data-bs-dismiss="offcanvas"><i class="bi bi-people"></i> Employees</a>
            <a class="nav-link" href="#" data-section="users" data-bs-dismiss="offcanvas"><i class="bi bi-person-vcard"></i> User Accounts</a>
            <a class="nav-link" href="#" data-section="appointments" data-bs-dismiss="offcanvas"><i class="bi bi-calendar-check"></i> Appointment Monitoring</a>
            <a class="nav-link" href="#" data-section="tracking" data-bs-dismiss="offcanvas"><i class="bi bi-diagram-3"></i> Application Tracking</a>
            <a class="nav-link" href="#" data-section="payments" data-bs-dismiss="offcanvas"><i class="bi bi-cash-coin"></i> Payment Monitoring</a>
            <a class="nav-link" href="#" data-section="contact-messages" data-bs-dismiss="offcanvas"><i class="bi bi-envelope-paper"></i> Contact Messages</a>
            <a class="nav-link" href="#" data-section="activity-log" data-bs-dismiss="offcanvas"><i class="bi bi-clock-history"></i> Activity Log</a>
            <a class="nav-link" href="#" data-section="announcements" data-bs-dismiss="offcanvas"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="#" data-section="system-settings" data-bs-dismiss="offcanvas"><i class="bi bi-sliders"></i> System Settings</a>
        </nav>
    </div>
</div>

<!-- MODALS -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Add Employee</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><div class="modal-body">
        <div class="mb-3"><label class="form-label">First Name</label><input type="text" name="firstname" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Last Name</label><input type="text" name="lastname" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Contact</label><input type="text" name="contact" class="form-control"></div>
        <div class="mb-3"><label class="form-label">Role</label><select name="user_type" class="form-select" required><option value="3">Treasury Staff</option><option value="4">BPLO Staff</option><option value="5"></option></select></div>
        <div class="mb-3" style="display:none;"><label class="form-label">Password</label><input type="text" name="password" class="form-control" value="admin123" required minlength="6"><small class="text-muted">This password will be shown in the verification email.</small></div>
        <div class="alert alert-info mb-0" style="background:#f5ebe0;border:none;color:var(--brown-darkest);font-size:0.85rem;border-radius:8px;">
            <i class="bi bi-envelope-check"></i>
            A verification email (with the password) will be sent. The employee must click the link before they can log in.
        </div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="add_employee" class="btn btn-success">Add & Send Verification</button></div></form>
</div></div></div>

<div class="modal fade" id="editEmployeeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Edit Employee</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="id" id="editEmpId"><div class="modal-body">
        <div class="mb-3"><label class="form-label">First Name</label><input type="text" name="firstname" id="editEmpFirstname" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Last Name</label><input type="text" name="lastname" id="editEmpLastname" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" id="editEmpEmail" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Contact</label><input type="text" name="contact" id="editEmpContact" class="form-control"></div>
        <div class="mb-3"><label class="form-label">Role</label><select name="user_type" id="editEmpUserType" class="form-select" required><option value="3">Treasury Staff</option><option value="4">BPLO Staff</option><option value="5">Admin</option></select></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="edit_employee" class="btn btn-success">Update</button></div></form>
</div></div></div>

<div class="modal fade" id="addAnnouncementModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Add Announcement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><div class="modal-body">
        <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Content</label><textarea name="content" class="form-control" rows="4" required></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="add_announcement" class="btn btn-success">Add</button></div></form>
</div></div></div>

<div class="modal fade" id="editAnnouncementModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Edit Announcement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="id" id="editAnnId"><div class="modal-body">
        <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" id="editAnnTitle" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Content</label><textarea name="content" id="editAnnContent" class="form-control" rows="4" required></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="edit_announcement" class="btn btn-success">Update</button></div></form>
</div></div></div>

<div class="modal fade reply-modal" id="viewMessageModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-envelope-open me-2"></i> Message Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label text-muted small text-uppercase fw-semibold">From</label><div class="fw-bold" id="vmName">—</div><a href="#" id="vmEmailLink" class="text-decoration-none small"><span id="vmEmail">—</span></a></div>
            <div class="col-md-6"><label class="form-label text-muted small text-uppercase fw-semibold">Received</label><div class="fw-semibold" id="vmDate">—</div></div>
            <div class="col-12"><label class="form-label text-muted small text-uppercase fw-semibold">Topic</label><div><span class="badge-status badge-topic" id="vmTopic">—</span></div></div>
            <div class="col-12"><label class="form-label text-muted small text-uppercase fw-semibold">Message</label><div class="p-3" style="background:#faf5ef;border-radius:8px;border-left:4px solid var(--brown-accent);white-space:pre-wrap;" id="vmMessage">—</div></div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="button" class="btn btn-success" id="vmReplyBtn"><i class="bi bi-reply"></i> Reply</button></div>
</div></div></div>

<div class="modal fade reply-modal" id="replyMessageModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-reply-all me-2"></i> Reply to Message</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="replyMessageForm">
        <input type="hidden" id="rmMessageId" name="message_id"><input type="hidden" id="rmToEmail" name="to_email">
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label small fw-semibold">To</label><input type="text" class="form-control" id="rmToName" name="to_name" readonly></div>
                <div class="col-md-6"><label class="form-label small fw-semibold">Email</label><input type="email" class="form-control" id="rmEmailDisplay" readonly></div>
                <div class="col-12"><label class="form-label small fw-semibold">Subject</label><input type="text" class="form-control" id="rmSubject" name="subject" required></div>
                <div class="col-12"><label class="form-label small fw-semibold">Original Message</label><div class="p-3 small text-muted" style="background:#faf5ef;border-radius:8px;max-height:140px;overflow-y:auto;white-space:pre-wrap;border-left:4px solid var(--brown-light);" id="rmOriginal">—</div></div>
                <div class="col-12"><label class="form-label small fw-semibold">Your Reply <span class="text-danger">*</span></label><textarea class="form-control" id="rmBody" name="body" rows="6" required></textarea><small class="text-muted">This will be sent as an HTML email via PHPMailer.</small></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success" id="rmSubmitBtn"><i class="bi bi-send"></i> Send Reply</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="paymentDetailModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Payment Assessment Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row mb-3"><div class="col-md-6"><div class="pay-detail-label">Reference No.</div><div class="pay-detail-value" id="pdRef">—</div></div><div class="col-md-6"><div class="pay-detail-label">Business Owner</div><div class="pay-detail-value" id="pdOwner">—</div></div></div>
        <hr>
        <h6 class="fw-bold mb-3" style="color:var(--brown-darkest);"><i class="bi bi-list-ul me-2"></i>Fee Breakdown</h6>
        <div class="pay-detail-row"><span class="pay-detail-label">Gross Sales Tax</span><span class="pay-detail-value" id="pdGst">₱0.00</span></div>
        <div class="pay-detail-row"><span class="pay-detail-label">Mayor's Permit Fee</span><span class="pay-detail-value" id="pdMayor">₱0.00</span></div>
        <div class="pay-detail-row"><span class="pay-detail-label">Garbage Charges</span><span class="pay-detail-value" id="pdGarbage">₱0.00</span></div>
        <div class="pay-detail-row"><span class="pay-detail-label">Sanitary Inspection Fee</span><span class="pay-detail-value" id="pdSanitary">₱0.00</span></div>
        <div class="pay-detail-row"><span class="pay-detail-label">Building Inspection Fee</span><span class="pay-detail-value" id="pdBuilding">₱0.00</span></div>
        <div class="pay-detail-row"><span class="pay-detail-label">Electrical Inspection Fee</span><span class="pay-detail-value" id="pdElectrical">₱0.00</span></div>
        <div class="pay-detail-row"><span class="pay-detail-label">Others</span><span class="pay-detail-value" id="pdOthers">₱0.00</span></div>
        <div class="pay-detail-row"><span class="pay-detail-label">Fire Safety Fee (%)</span><span class="pay-detail-value" id="pdFire">0%</span></div>
        <div class="pay-detail-total"><span class="label"><i class="bi bi-cash-stack me-2"></i>Total Amount</span><span class="value" id="pdTotal">₱0.00</span></div>
        <hr class="mt-4">
        <h6 class="fw-bold mb-3" style="color:var(--brown-darkest);"><i class="bi bi-gear me-2"></i>Update Application Status</h6>
        <div class="row g-2 align-items-end">
            <div class="col-md-8"><select class="form-select" id="pdStatusSelect"><option value="pending_payment">Pending Payment</option><option value="under_review">Under Review</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="claimed">Claimed</option><option value="verified">Verified</option></select></div>
            <div class="col-md-4"><button type="button" class="btn btn-success w-100" id="pdSaveStatusBtn"><i class="bi bi-check-lg"></i> Save</button></div>
        </div>
        <input type="hidden" id="pdAppId">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
</div></div></div>

<div class="modal fade" id="appTrackModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i>Application Tracking Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row mb-3"><div class="col-md-6"><div class="pay-detail-label">Reference No.</div><div class="pay-detail-value" id="atRef">—</div></div><div class="col-md-6"><div class="pay-detail-label">Business / Owner</div><div class="pay-detail-value" id="atBusiness">—</div></div></div>
        <div class="track-step-bar" id="atStepBar">
            <div class="track-step" data-step="submitted"><i class="bi bi-file-earmark-check"></i> Submitted</div>
            <div class="track-step" data-step="under_review"><i class="bi bi-search"></i> Review</div>
            <div class="track-step" data-step="pending_payment"><i class="bi bi-credit-card"></i> Payment</div>
            <div class="track-step" data-step="approved"><i class="bi bi-check-circle"></i> Approved</div>
        </div>
        <hr>
        <h6 class="fw-bold mb-3" style="color:var(--brown-darkest);"><i class="bi bi-info-circle me-2"></i>Application Info</h6>
        <div class="row">
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Owner Email</span><span class="pay-detail-value" id="atEmail">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Contact</span><span class="pay-detail-value" id="atContact">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Application Type</span><span class="pay-detail-value" id="atType">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Tax Year</span><span class="pay-detail-value" id="atTaxYear">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Current Step</span><span class="pay-detail-value" id="atStep">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Documents Uploaded</span><span class="pay-detail-value" id="atDocCount">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Rejection Count</span><span class="pay-detail-value" id="atRejections">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Assessed Amount</span><span class="pay-detail-value" id="atAmount">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Submitted</span><span class="pay-detail-value" id="atSubmitted">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Issued</span><span class="pay-detail-value" id="atIssued">—</span></div></div>
            <div class="col-md-6"><div class="pay-detail-row"><span class="pay-detail-label">Expiry</span><span class="pay-detail-value" id="atExpiry">—</span></div></div>
        </div>
        <hr class="mt-4">
        <h6 class="fw-bold mb-3" style="color:var(--brown-darkest);"><i class="bi bi-gear me-2"></i>Update Application Status</h6>
        <div class="row g-2 align-items-end">
            <div class="col-md-8"><select class="form-select" id="atStatusSelect"><option value="submitted">Submitted</option><option value="under_review">Under Review</option><option value="pending_payment">Pending Payment</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="claimed">Claimed</option><option value="verified">Verified</option><option value="cancelled">Cancelled</option></select></div>
            <div class="col-md-4"><button type="button" class="btn btn-success w-100" id="atSaveStatusBtn"><i class="bi bi-check-lg"></i> Save</button></div>
        </div>
        <input type="hidden" id="atAppId">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
</div></div></div>

<!-- UNIVERSAL STAT-CARD MODAL -->
<div class="modal fade" id="statCardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width:1200px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold d-flex align-items-center flex-wrap gap-2">
                    <i class="bi bi-bar-chart-line-fill" id="statCardModalIcon"></i>
                    <span id="statCardModalTitle">Details</span>
                    <span class="badge" id="statCardModalCount"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="background:#fff;">
                <div id="statCardModalEmpty" class="text-center py-5 d-none"><i class="bi bi-inbox" style="font-size:2.5rem;color:#cbd5e1;"></i><p class="mt-3 text-muted mb-0">No records match this filter.</p></div>
                <div class="table-responsive" id="statCardModalTableWrap" style="display:none;">
                    <table class="table" id="statCardModalTable" style="margin:0;">
                        <thead id="statCardModalThead"></thead>
                        <tbody id="statCardModalTbody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--brown-border);">
                <button type="button" class="btn-outline-brown" data-bs-dismiss="modal">Close</button>
                <a href="#" id="statCardModalOpenSection" class="btn-brown" style="display:none;"><i class="bi bi-arrow-right-circle"></i> <span>Open in section</span></a>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
// Sort date columns by the real date/time (not alphabetically) so the latest is always on top
(function ($) {
    if (!$ || !$.fn || !$.fn.dataTable) return;
    var WD = '(?:(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\\s+)?';
    var re = new RegExp('^' + WD + '((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\\.?\\s+\\d{1,2},\\s+\\d{4})(?:\\s+(\\d{1,2}:\\d{2}(?:\\s*[AaPp][Mm])?))?', 'i');
    var blank = /^(?:|—|-|–|n\/a|not submitted|not assessed)$/i;
    function clean(d) { return String(d == null ? '' : d).replace(/<[^>]*>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim(); }
    $.fn.dataTable.ext.type.detect.unshift(function (d) {
        if (typeof d !== 'string') return null;
        var t = clean(d);
        return (re.test(t) || blank.test(t)) ? 'ph-date' : null;
    });
    $.fn.dataTable.ext.type.order['ph-date-pre'] = function (d) {
        var m = clean(d).match(re);
        if (!m) return 0;
        var ms = Date.parse(m[1] + (m[2] ? ' ' + m[2] : ''));
        return isNaN(ms) ? 0 : ms;
    };
})(window.jQuery);
</script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// SECTION SWITCHING
const sections = [ 'employees', 'users', 'appointments', 'tracking', 'payments', 'contact-messages', 'activity-log', 'announcements', 'system-settings' ];
function showSection(sectionId) {
    sections.forEach(sec => { const el = document.getElementById(sec + '-section'); if (el) el.style.display = 'none'; });
    const active = document.getElementById(sectionId + '-section');
    if (active) active.style.display = 'block';
    document.querySelectorAll('.sidebar .nav-link, .offcanvas .nav-link').forEach(link => {
        if (link.dataset.section === sectionId) link.classList.add('active'); else link.classList.remove('active');
    });
    if (sectionId === 'appointments') { setTimeout(() => initAdminAppointmentsCalendar(), 100); }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
document.querySelectorAll('.sidebar .nav-link, .offcanvas .nav-link').forEach(link => {
    link.addEventListener('click', (e) => { e.preventDefault(); const s = link.dataset.section; if (s && sections.includes(s)) showSection(s); });
});
showSection('employees');

// EMPLOYEE / ANNOUNCEMENT EDIT
document.querySelectorAll('.edit-employee-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('editEmpId').value = this.dataset.id;
        document.getElementById('editEmpFirstname').value = this.dataset.firstname;
        document.getElementById('editEmpLastname').value = this.dataset.lastname;
        document.getElementById('editEmpEmail').value = this.dataset.email;
        document.getElementById('editEmpContact').value = this.dataset.contact;
        document.getElementById('editEmpUserType').value = this.dataset.userType;
    });
});
document.querySelectorAll('.edit-announcement-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('editAnnId').value = this.dataset.id;
        document.getElementById('editAnnTitle').value = this.dataset.title;
        document.getElementById('editAnnContent').value = this.dataset.content;
    });
});

// RESEND EMPLOYEE VERIFICATION
document.querySelectorAll('.resend-verify-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const empId = this.dataset.id;
        const empName = this.dataset.name;
        const confirm = await Swal.fire({
            icon: 'question',
            title: 'Resend verification?',
            html: `Send a new verification email to <strong>${empName}</strong>?`,
            showCancelButton: true,
            confirmButtonText: 'Yes, send',
            confirmButtonColor: '#6d4c41',
            cancelButtonColor: '#6b7280'
        });
        if (!confirm.isConfirmed) return;

        Swal.fire({ title: 'Sending…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        try {
            const res = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: 'resend_employee_verification=1&id=' + encodeURIComponent(empId)
            });
            const data = await res.json();
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Sent!', text: 'Verification email resent.', timer: 1800, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Could not send', text: data.message || 'Unknown error.', confirmButtonColor: '#6d4c41' });
            }
        } catch (e) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Network error', text: 'Please try again.', confirmButtonColor: '#6d4c41' });
        }
    });
});

// ---- EMPLOYEE VERIFIED TOGGLE ----
document.querySelectorAll('.verify-toggle-emp').forEach(toggle => {
    toggle.addEventListener('change', function () {
        const empId = this.dataset.empId;
        const isChecked = this.checked ? 1 : 0;
        const label = document.querySelector(`label[for="verifyEmpSwitch${empId}"]`);
        this.disabled = true;

        if (label) {
            label.textContent = isChecked ? 'Verified' : 'Not Verified';
            label.classList.toggle('on', isChecked === 1);
            label.classList.toggle('off', isChecked === 0);
        }

        fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `toggle_employee_verified=1&id=${encodeURIComponent(empId)}&verified=${isChecked}`
        })
        .then(res => res.json())
        .then(data => {
            this.disabled = false;
            if (data.success) {
                const row = this.closest('tr');
                const resendBtn = row ? row.querySelector('.resend-verify-btn') : null;
                if (resendBtn) resendBtn.style.display = isChecked ? 'none' : '';
                Swal.fire({
                    icon: 'success',
                    title: isChecked ? 'Employee Verified' : 'Verification Removed',
                    timer: 1500, showConfirmButton: false, toast: true, position: 'top-end'
                });
            } else {
                this.checked = !this.checked;
                if (label) {
                    label.textContent = this.checked ? 'Verified' : 'Not Verified';
                    label.classList.toggle('on', this.checked);
                    label.classList.toggle('off', !this.checked);
                }
                Swal.fire({ icon: 'error', title: 'Update Failed', confirmButtonColor: 'var(--brown-primary)' });
            }
        })
        .catch(() => {
            this.disabled = false;
            this.checked = !this.checked;
            Swal.fire({ icon: 'error', title: 'Network Error', confirmButtonColor: 'var(--brown-primary)' });
        });
    });
});

// VERIFY USER TOGGLE (business owner)
document.querySelectorAll('.verify-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const userId = this.dataset.userId;
        const isChecked = this.checked ? 1 : 0;
        const label = document.querySelector(`label[for="verifySwitch${userId}"]`);
        this.disabled = true;
        if (label) { label.textContent = isChecked ? 'Verified' : 'Not Verified'; label.classList.toggle('on', isChecked === 1); label.classList.toggle('off', isChecked === 0); }
        fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `toggle_user_verified=1&id=${encodeURIComponent(userId)}&verified=${isChecked}` })
        .then(res => res.json())
        .then(data => {
            this.disabled = false;
            if (data.success) { Swal.fire({ icon: 'success', title: isChecked ? 'User Verified' : 'Verification Removed', timer: 1500, showConfirmButton: false, toast: true, position: 'top-end' }); }
            else { this.checked = !this.checked; if (label) { label.textContent = this.checked ? 'Verified' : 'Not Verified'; label.classList.toggle('on', this.checked); label.classList.toggle('off', !this.checked); } Swal.fire({ icon: 'error', title: 'Update Failed', confirmButtonColor: 'var(--brown-primary)' }); }
        })
        .catch(() => { this.disabled = false; this.checked = !this.checked; Swal.fire({ icon: 'error', title: 'Network Error', confirmButtonColor: 'var(--brown-primary)' }); });
    });
});

// HELPERS
function formatCurrency(n) { return '₱' + parseFloat(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function adminStatusPill(st) {
    st = (st || '').toLowerCase();
    const map = { 'submitted':'badge-submitted','under_review':'badge-under-review','pending_payment':'badge-pending-payment','payment_confirmed':'badge-under-review','approved':'badge-approved','issued':'badge-approved','verified':'badge-approved','claimed':'badge-approved','rejected':'badge-rejected','cancelled':'badge-cancelled','completed':'badge-approved','paid':'badge-approved','pending':'badge-pending','confirmed':'badge-under-review','booked':'badge-approved','uploaded':'badge-approved','assessed':'badge-approved' };
    return '<span class="badge-status ' + (map[st] || 'badge-pending') + '">' + st.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) + '</span>';
}

// UNIVERSAL STAT-CARD MODAL
function openStatCardModal(opts) {
    const { title, icon='bi-bar-chart-line-fill', headers=[], rows=[], section=null, sectionFilter=null, emptyText='No records match this filter.' } = opts;
    document.getElementById('statCardModalIcon').className = 'bi ' + icon;
    document.getElementById('statCardModalTitle').textContent = title;
    document.getElementById('statCardModalCount').textContent = rows.length + (rows.length === 1 ? ' record' : ' records');
    const theadEl = document.getElementById('statCardModalThead');
    const tbodyEl = document.getElementById('statCardModalTbody');
    const wrapEl  = document.getElementById('statCardModalTableWrap');
    const emptyEl = document.getElementById('statCardModalEmpty');
    const linkEl  = document.getElementById('statCardModalOpenSection');
    theadEl.innerHTML = '<tr>' + headers.map((h, i) => { const pad = i === 0 ? 'padding-left:1.5rem;' : (i === headers.length - 1 ? 'padding-right:1.5rem;' : ''); return `<th style="${pad}">${h}</th>`; }).join('') + '</tr>';
    tbodyEl.innerHTML = '';
    if (rows.length === 0) { emptyEl.querySelector('p').textContent = emptyText; emptyEl.classList.remove('d-none'); wrapEl.style.display = 'none'; }
    else {
        emptyEl.classList.add('d-none'); wrapEl.style.display = 'block';
        rows.forEach(cells => {
            const tr = document.createElement('tr');
            tr.innerHTML = cells.map((c, i) => { const pad = i === 0 ? 'padding-left:1.5rem;' : (i === cells.length - 1 ? 'padding-right:1.5rem;' : ''); return `<td style="${pad}">${c}</td>`; }).join('');
            tbodyEl.appendChild(tr);
        });
    }
    if (section) {
        linkEl.style.display = '';
        linkEl.onclick = function (e) {
            e.preventDefault();
            const inst = bootstrap.Modal.getInstance(document.getElementById('statCardModal'));
            if (inst) inst.hide();
            showSection(section);
            if (sectionFilter) { setTimeout(() => { const btn = document.querySelector(`#activityFilterBtns button[data-role="${sectionFilter}"]`); if (btn) btn.click(); }, 150); }
        };
    } else { linkEl.style.display = 'none'; linkEl.onclick = null; }
    new bootstrap.Modal(document.getElementById('statCardModal')).show();
}
function bindStatCard(selector, handler) {
    document.querySelectorAll(selector).forEach(card => {
        card.addEventListener('click', handler);
        card.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handler.call(card); } });
    });
}

// DATA SERIALIZATION
const adminAppointmentsData = <?php echo json_encode(array_map(function ($a) {
    $fullName = trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
    if ($fullName === '') $fullName = 'Applicant #' . ($a['user_id'] ?? '?');
    return [
        'appointment_id'   => (int)($a['appointment_id'] ?? 0),
        'application_id'   => (int)($a['application_id'] ?? 0),
        'appointment_date' => $a['appointment_date'] ?? '',
        'appointment_time' => $a['appointment_time'] ?? '',
        'status'           => strtolower($a['appointment_status'] ?? 'pending'),
        'applicant_name'   => $fullName,
        'applicant_email'  => $a['email'] ?? '',
        'applicant_contact'=> $a['contact_number'] ?? '',
        'business_name'    => $a['business_name'] ?? 'N/A',
        'reference_no'     => $a['reference_no'] ?? 'N/A',
        'application_type' => $a['application_type'] ?? 'N/A',
    ];
}, $appointments), JSON_UNESCAPED_UNICODE); ?>;

const adminApplicationsData = <?php echo json_encode(array_map(function ($a) {
    return [
        'id'               => (int)$a['id'],
        'reference_no'     => $a['reference_no'] ?? '',
        'business_name'    => $a['business_name'] ?? '',
        'owner_name'       => $a['owner_name'] ?? '',
        'owner_email'      => $a['owner_email'] ?? '',
        'application_type' => $a['application_type'] ?? 'New',
        'status'           => strtolower($a['status']),
        'submitted_at'     => $a['submitted_at'] ?? '',
        'assessed_amount'  => (float)($a['assessed_amount'] ?? 0),
    ];
}, $applications), JSON_UNESCAPED_UNICODE); ?>;

const adminPaymentsData = <?php echo json_encode(array_map(function ($p) {
    return [
        'assessment_id' => (int)$p['assessment_id'],
        'application_id' => (int)($p['application_id'] ?? 0),
        'reference_no' => $p['reference_no'] ?? '',
        'owner_name' => $p['owner_name'] ?? '',
        'owner_email' => $p['owner_email'] ?? '',
        'tax_year' => $p['tax_year'] ?? '',
        'total_amount' => (float)($p['total_amount'] ?? 0),
        'assessor_name' => $p['assessor_name'] ?? '',
        'assessed_at' => $p['assessed_at'] ?? '',
        'app_status' => strtolower($p['app_status'] ?? ''),
    ];
}, $payments), JSON_UNESCAPED_UNICODE); ?>;

const adminMessagesData = <?php echo json_encode($contactMessages, JSON_UNESCAPED_UNICODE); ?>;
const adminActivityData = <?php echo json_encode($activityLog, JSON_UNESCAPED_UNICODE); ?>;

const TODAY_ADMIN = '<?php echo $todayDate; ?>';
const WEEK_AGO_ADMIN = '<?php echo $weekAgo; ?>';
const WEEK_AHEAD_ADMIN = '<?php echo $weekAhead; ?>';

// APPOINTMENT CARDS
function filterAdminAppt(kind) {
    return adminAppointmentsData.filter(a => {
        switch (kind) {
            case 'pending':   return a.status === 'pending';
            case 'confirmed': return a.status === 'confirmed';
            case 'completed': return a.status === 'completed';
            case 'cancelled': return a.status === 'cancelled';
            case 'today':     return a.appointment_date === TODAY_ADMIN;
            case 'upcoming':  return a.appointment_date >= TODAY_ADMIN && a.appointment_date <= WEEK_AHEAD_ADMIN;
            default:          return true;
        }
    });
}
function adminApptRows(arr) {
    return arr.map(a => {
        const dateStr = a.appointment_date ? new Date(a.appointment_date + 'T00:00:00').toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric', year:'numeric' }) : '—';
        return [
            '<div class="fw-semibold">' + dateStr + '</div><small class="text-muted"><i class="bi bi-clock"></i> ' + (a.appointment_time || '—') + '</small>',
            '<div class="fw-semibold">' + a.applicant_name + '</div><small class="text-muted">' + (a.applicant_email ? '<i class="bi bi-envelope"></i> ' + a.applicant_email : '') + (a.applicant_contact ? '<br><i class="bi bi-telephone"></i> ' + a.applicant_contact : '') + '</small>',
            a.business_name,
            '<code class="small">' + a.reference_no + '</code>',
            a.application_type,
            adminStatusPill(a.status),
            '<a href="index.php" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-eye"></i></a>'
        ];
    });
}
bindStatCard('.pay-stat-card.clickable[data-admin-appt-card]', function () {
    const kind = this.dataset.adminApptCard;
    const titles = { pending:['Pending appointments','bi-hourglass-split'], confirmed:['Confirmed appointments','bi-check2-circle'], completed:['Completed appointments','bi-check-all'], cancelled:['Cancelled appointments','bi-x-circle'], today:["Today's appointments",'bi-calendar-day'], upcoming:['Upcoming (7 days)','bi-calendar-week'] };
    const t = titles[kind] || ['Appointments','bi-calendar-check'];
    openStatCardModal({
        title: t[0], icon: t[1],
        headers: ['Date & time', 'Applicant', 'Business', 'Reference', 'Type', 'Status', 'Action'],
        rows: adminApptRows(filterAdminAppt(kind)),
        section: 'appointments'
    });
});

// APPLICATION TRACKING CARDS
function filterAdminApps(kind) {
    return adminApplicationsData.filter(a => {
        switch (kind) {
            case 'submitted':       return a.status === 'submitted';
            case 'under_review':    return a.status === 'under_review';
            case 'pending_payment': return a.status === 'pending_payment';
            case 'approved':        return ['approved','claimed','verified'].includes(a.status);
            default:                return true;
        }
    });
}
function adminAppsRows(arr) {
    return arr.map(a => {
        const sub = a.submitted_at ? new Date(a.submitted_at).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : '—';
        return [
            '<code class="small">' + a.reference_no + '</code>',
            '<div class="fw-semibold">' + a.business_name + '</div><small class="text-muted"><i class="bi bi-person"></i> ' + a.owner_name + '</small>',
            a.application_type,
            sub,
            '<strong>' + formatCurrency(a.assessed_amount) + '</strong>',
            adminStatusPill(a.status),
            '<a href="../permit.php?ref=' + encodeURIComponent(a.reference_no) + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i></a>'
        ];
    });
}
bindStatCard('.pay-stat-card.clickable[data-admin-track-card]', function () {
    const kind = this.dataset.adminTrackCard;
    const titles = { submitted:['Submitted applications','bi-file-earmark-plus'], under_review:['Under review','bi-search'], pending_payment:['Pending payment','bi-credit-card'], approved:['Approved / Verified','bi-patch-check'] };
    const t = titles[kind] || ['Applications','bi-diagram-3'];
    openStatCardModal({
        title: t[0], icon: t[1],
        headers: ['Reference', 'Business / Owner', 'Type', 'Submitted', 'Amount', 'Status', 'Action'],
        rows: adminAppsRows(filterAdminApps(kind)),
        section: 'tracking'
    });
});

// PAYMENT MONITORING CARDS
function filterAdminPayments(kind) {
    return adminPaymentsData.filter(p => {
        switch (kind) {
            case 'assessed':  return true;
            case 'collected': return ['approved','claimed','verified'].includes(p.app_status);
            case 'pending':   return ['pending_payment','under_review'].includes(p.app_status);
            case 'paid':      return ['approved','claimed','verified'].includes(p.app_status);
            default:          return true;
        }
    });
}
function adminPaymentsRows(arr) {
    return arr.map(p => {
        const at = p.assessed_at ? new Date(p.assessed_at).toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' }) : '—';
        return [
            '<code class="small">' + p.reference_no + '</code>',
            '<div class="fw-semibold">' + p.owner_name + '</div><small class="text-muted">' + p.owner_email + '</small>',
            p.tax_year || '—',
            '<strong>' + formatCurrency(p.total_amount) + '</strong>',
            p.assessor_name || '—',
            at,
            adminStatusPill(p.app_status),
            '<a href="treasury_record.php?id=' + p.application_id + '" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-eye"></i></a>'
        ];
    });
}
bindStatCard('.pay-stat-card.clickable[data-admin-pay-card]', function () {
    const kind = this.dataset.adminPayCard;
    const titles = { assessed:['All assessments','bi-file-earmark-text'], collected:['Collected payments','bi-cash-stack'], pending:['Pending payments','bi-hourglass-split'], paid:['Paid accounts','bi-check-circle'] };
    const t = titles[kind] || ['Payments','bi-cash-coin'];
    openStatCardModal({
        title: t[0], icon: t[1],
        headers: ['Reference', 'Owner', 'Tax year', 'Total amount', 'Assessor', 'Assessed at', 'Status', 'Action'],
        rows: adminPaymentsRows(filterAdminPayments(kind)),
        section: 'payments'
    });
});

// CONTACT MESSAGES CARDS
function filterAdminMessages(kind) {
    return adminMessagesData.filter(m => {
        const d = m.created_at ? new Date(m.created_at.replace(' ', 'T')) : null;
        const day = d ? d.toISOString().slice(0, 10) : '';
        switch (kind) {
            case 'all':    return true;
            case 'today':  return day === TODAY_ADMIN;
            case 'week':   return day >= WEEK_AGO_ADMIN;
            case 'topics': return true;
            default:       return true;
        }
    });
}
function adminMessagesRows(arr) {
    return arr.map(m => {
        const shortMsg = (m.message || '').length > 100 ? m.message.substring(0, 100) + '…' : (m.message || '');
        const d = m.created_at ? new Date(m.created_at.replace(' ', 'T')) : null;
        const dateStr = d ? d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' }) : '—';
        return [
            '<div class="fw-semibold">' + (m.name || '—') + '</div><a href="mailto:' + m.email + '" class="text-decoration-none small">' + (m.email || '—') + '</a>',
            '<span class="badge-status badge-topic">' + (m.topic || '—') + '</span>',
            '<div class="small text-muted">' + shortMsg.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</div>',
            dateStr
        ];
    });
}
bindStatCard('.pay-stat-card.clickable[data-admin-msg-card]', function () {
    const kind = this.dataset.adminMsgCard;
    const titles = { all:['All contact messages','bi-inbox'], today:["Today's messages",'bi-calendar-day'], week:['This week','bi-calendar-week'], topics:['Messages grouped by topic','bi-tags'] };
    const t = titles[kind] || ['Messages','bi-envelope-paper'];
    openStatCardModal({
        title: t[0], icon: t[1],
        headers: ['Sender', 'Topic', 'Message', 'Received'],
        rows: adminMessagesRows(filterAdminMessages(kind)),
        section: 'contact-messages'
    });
});

// ACTIVITY LOG CARDS
function filterAdminActivity(kind) {
    return adminActivityData.filter(log => {
        const d = log.event_time ? new Date(log.event_time.replace(' ', 'T')) : null;
        const day = log.event_time ? String(log.event_time).slice(0, 10) : '';
        const ns = (log.new_status || '').toLowerCase();
        const at = parseInt(log.actor_type || 0, 10);
        const et = log.event_type || '';
        switch (kind) {
            case 'total':       return true;
            case 'today':       return day === TODAY_ADMIN;
            case 'week':        return day >= WEEK_AGO_ADMIN;
            case 'reject':      return ns === 'rejected';
            case 'bplo':        return at === 4;
            case 'treasury':    return at === 3;
            case 'admin':       return at === 5;
            case 'payments':    return et === 'payment';
            case 'appointments':return et.indexOf('appointment_') === 0;
            case 'documents':   return et === 'document_upload';
            case 'submissions': return et === 'application_submitted';
            case 'verified':    return ns === 'verified';
            default:            return true;
        }
    });
}
function adminActEventBadge(et) {
    const map = {
        'status_change':         ['bi-arrow-repeat', 'Status Change', '#6366f1'],
        'fee_assessment':        ['bi-cash-coin', 'Fee Assessment', '#f59e0b'],
        'rejection':             ['bi-file-earmark-x', 'Rejection', '#ef4444'],
        'document_rejection':    ['bi-file-earmark-x', 'Document Rejection', '#ef4444'],
        'payment':               ['bi-paypal', 'PayPal Payment', '#0070ba'],
        'appointment_created':   ['bi-calendar-plus', 'Appointment Booked', '#8b5cf6'],
        'appointment_confirmed': ['bi-calendar-check', 'Appointment Confirmed', '#3b82f6'],
        'appointment_completed': ['bi-calendar-check-fill', 'Appointment Completed', '#10b981'],
        'appointment_cancelled': ['bi-calendar-x', 'Appointment Cancelled', '#ef4444'],
        'document_upload':       ['bi-cloud-upload', 'Document Uploaded', '#06b6d4'],
        'application_submitted': ['bi-file-earmark-plus', 'Application Submitted', '#10b981'],
        'announcement_pending':  ['bi-megaphone', 'Announcement Pending', '#f59e0b'],
        'announcement_approved': ['bi-megaphone-fill', 'Announcement Approved', '#10b981'],
        'announcement_rejected': ['bi-megaphone', 'Announcement Rejected', '#ef4444']
    };
    const [icon, label, color] = map[et] || ['bi-arrow-repeat', 'Status Change', '#6366f1'];
    return '<span style="display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .6rem;border-radius:2rem;font-size:.72rem;font-weight:600;background:' + color + '22;color:' + color + ';"><i class="bi ' + icon + '"></i> ' + label + '</span>';
}
function adminActRows(arr) {
    return arr.map(log => {
        const d = log.event_time ? new Date(log.event_time.replace(' ', 'T')) : new Date();
        const dateStr = d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
        return [
            dateStr,
            adminActEventBadge(log.event_type || 'status_change'),
            log.reference_no ? '<code class="small">' + log.reference_no + '</code>' : '<span class="text-muted">—</span>',
            '<div class="fw-semibold">' + (log.business_name || '—') + '</div><small class="text-muted"><i class="bi bi-person"></i> ' + (log.owner_name || 'Unknown') + '</small>',
            log.old_status ? adminStatusPill(log.old_status) : '<span class="text-muted">—</span>',
            adminStatusPill(log.new_status),
            '<div class="fw-semibold">' + (log.actor_name || 'System') + '</div>',
            '<small class="text-muted">' + (log.notes || '—') + '</small>'
        ];
    });
}
bindStatCard('.pay-stat-card.clickable[data-admin-act-card]', function () {
    const kind = this.dataset.adminActCard;
    const titles = {
        total:['All activities','bi-list-ul'], today:["Today's activities",'bi-calendar-day'], week:['This week','bi-calendar-week'],
        reject:['Rejections','bi-x-circle'], bplo:['BPLO actions','bi-file-earmark-check'], treasury:['Treasury actions','bi-cash-coin'],
        admin:['Admin actions','bi-shield-lock'], payments:['PayPal payments','bi-paypal'], appointments:['Appointment events','bi-calendar-check'],
        documents:['Document uploads','bi-cloud-upload'], submissions:['Applications submitted','bi-file-earmark-plus'], verified:['Verified applications','bi-patch-check']
    };
    const t = titles[kind] || ['Activity Log','bi-clock-history'];
    const sectionFilterMap = { payments:'payment', bplo:'4', treasury:'3', admin:'5' };
    openStatCardModal({
        title: t[0], icon: t[1],
        headers: ['Date & time', 'Event', 'Reference', 'Business / owner', 'Previous', 'New status', 'By', 'Notes'],
        rows: adminActRows(filterAdminActivity(kind)),
        section: 'activity-log',
        sectionFilter: sectionFilterMap[kind] || null
    });
});

// MESSAGE VIEW/REPLY
let currentViewMsg = null;
document.querySelectorAll('.view-msg-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        currentViewMsg = d;
        document.getElementById('vmName').textContent = d.name || '—';
        document.getElementById('vmEmail').textContent = d.email || '—';
        document.getElementById('vmEmailLink').href = 'mailto:' + (d.email || '');
        document.getElementById('vmDate').textContent = d.date || '—';
        document.getElementById('vmTopic').textContent = d.topic || '—';
        document.getElementById('vmMessage').textContent = d.message || '—';
        new bootstrap.Modal(document.getElementById('viewMessageModal')).show();
    });
});
document.getElementById('vmReplyBtn')?.addEventListener('click', function() {
    if (!currentViewMsg) return;
    bootstrap.Modal.getInstance(document.getElementById('viewMessageModal'))?.hide();
    setTimeout(() => openReplyModal(currentViewMsg), 250);
});
function openReplyModal(d) {
    document.getElementById('rmMessageId').value = d.id || '';
    document.getElementById('rmToEmail').value = d.email || '';
    document.getElementById('rmToName').value = d.name || '';
    document.getElementById('rmEmailDisplay').value = d.email || '';
    document.getElementById('rmSubject').value = 'Re: ' + (d.topic || 'Your message');
    document.getElementById('rmOriginal').textContent = d.message || '—';
    document.getElementById('rmBody').value = '';
    new bootstrap.Modal(document.getElementById('replyMessageModal')).show();
    setTimeout(() => document.getElementById('rmBody').focus(), 300);
}
document.querySelectorAll('.reply-msg-btn').forEach(btn => { btn.addEventListener('click', function() { openReplyModal(this.dataset); }); });

// ---- REPLY SUBMIT (FIXED) ----
document.getElementById('replyMessageForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = this;
    const btn = document.getElementById('rmSubmitBtn');
    const formData = new FormData(form);
    formData.append('reply_contact_message', '1');

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending...';

    try {
        const res  = await fetch('index.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });

        const raw = await res.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (parseErr) {
            console.error('[reply] Non-JSON server response:', raw);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send"></i> Send Reply';
            Swal.fire({
                icon: 'error',
                title: 'Server returned unexpected response',
                html: 'The email may still have been sent. Server said:<br><small class="text-muted d-block mt-2" style="max-height:150px;overflow:auto;text-align:left;">'
                       + raw.substring(0, 400).replace(/</g,'&lt;') + '</small>',
                confirmButtonColor: '#6d4c41',
                width: 600
            });
            return;
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Send Reply';

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('replyMessageModal'))?.hide();
            Swal.fire({
                icon: 'success', title: 'Reply Sent!',
                text: data.message, timer: 2000, showConfirmButton: false
            });
        } else {
            Swal.fire({
                icon: 'error', title: 'Send Failed',
                html: (data.message || 'Error').replace(/\n/g, '<br>'),
                confirmButtonColor: '#6d4c41'
            });
        }
    } catch (err) {
        console.error('[reply] network:', err);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Send Reply';
        Swal.fire({
            icon: 'error', title: 'Network Error',
            text: 'Could not reach the server.',
            confirmButtonColor: '#6d4c41'
        });
    }
});

// ---- DELETE CONTACT MESSAGE (message + related replies) ----
document.querySelectorAll('.delete-msg-btn').forEach(btn => {
    btn.addEventListener('click', async function () {
        const id   = this.dataset.id;
        const name = this.dataset.name || 'this sender';

        const confirm = await Swal.fire({
            icon: 'warning',
            title: 'Delete this message?',
            html: `This will permanently remove the message from <strong>${name}</strong>.<br>Any related replies will also be deleted.<br>This action cannot be undone.`,
            showCancelButton: true,
            confirmButtonText: 'Yes, delete',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280'
        });
        if (!confirm.isConfirmed) return;

        const row = this.closest('tr');
        const originalHtml = this.innerHTML;
        this.disabled = true;
        this.innerHTML = '<i class="bi bi-hourglass-split"></i>';

        const fd = new FormData();
        fd.append('delete_contact_message', '1');
        fd.append('message_id', id);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const raw = await res.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (parseErr) {
                console.error('[delete-msg] Non-JSON response:', raw);
                this.disabled = false;
                this.innerHTML = originalHtml;
                Swal.fire({
                    icon: 'error', title: 'Server Error',
                    text: 'Unexpected server response. See console.',
                    confirmButtonColor: '#6d4c41'
                });
                return;
            }

            if (data.success) {
                Swal.fire({
                    icon: 'success', title: 'Deleted!',
                    text: data.message, timer: 1400, showConfirmButton: false
                });
                if (row) {
                    row.style.transition = 'opacity .3s';
                    row.style.opacity = '0';
                    setTimeout(() => {
                        if ($.fn.DataTable && $.fn.DataTable.isDataTable('#contactMessagesTable')) {
                            $('#contactMessagesTable').DataTable().row(row).remove().draw(false);
                        } else if (row.parentNode) {
                            row.parentNode.removeChild(row);
                        }
                    }, 300);
                }
            } else {
                this.disabled = false;
                this.innerHTML = originalHtml;
                Swal.fire({
                    icon: 'error', title: 'Delete Failed',
                    text: data.message || 'Unknown error.',
                    confirmButtonColor: '#6d4c41'
                });
            }
        } catch (err) {
            console.error('[delete-msg] network:', err);
            this.disabled = false;
            this.innerHTML = originalHtml;
            Swal.fire({
                icon: 'error', title: 'Network Error',
                text: 'Could not reach the server.',
                confirmButtonColor: '#6d4c41'
            });
        }
    });
});

// PAYMENT DETAIL MODAL
document.querySelectorAll('.view-payment-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        document.getElementById('pdRef').textContent = d.ref || '—';
        document.getElementById('pdOwner').textContent = d.owner || '—';
        document.getElementById('pdGst').textContent = formatCurrency(d.gst);
        document.getElementById('pdMayor').textContent = formatCurrency(d.mayor);
        document.getElementById('pdGarbage').textContent = formatCurrency(d.garbage);
        document.getElementById('pdSanitary').textContent = formatCurrency(d.sanitary);
        document.getElementById('pdBuilding').textContent = formatCurrency(d.building);
        document.getElementById('pdElectrical').textContent = formatCurrency(d.electrical);
        document.getElementById('pdOthers').textContent = formatCurrency(d.others);
        document.getElementById('pdFire').textContent = parseFloat(d.fire || 0).toFixed(2) + '%';
        document.getElementById('pdTotal').textContent = formatCurrency(d.total);
        document.getElementById('pdStatusSelect').value = (d.status || 'pending_payment').toLowerCase();
        document.getElementById('pdAppId').value = d.appId || '';
    });
});
document.getElementById('pdSaveStatusBtn').addEventListener('click', function() {
    const appId = document.getElementById('pdAppId').value;
    const newStatus = document.getElementById('pdStatusSelect').value;
    if (!appId) { Swal.fire({ icon: 'error', title: 'Missing Application ID', confirmButtonColor: 'var(--brown-primary)' }); return; }
    const btn = this;
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `update_payment_status=1&application_id=${encodeURIComponent(appId)}&status=${encodeURIComponent(newStatus)}` })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i> Save';
        if (data.success) Swal.fire({ icon: 'success', title: 'Status Updated', timer: 1500, showConfirmButton: false }).then(() => location.reload());
        else Swal.fire({ icon: 'error', title: 'Update Failed', confirmButtonColor: 'var(--brown-primary)' });
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i> Save'; Swal.fire({ icon: 'error', title: 'Network Error', confirmButtonColor: 'var(--brown-primary)' }); });
});

// APPLICATION TRACK MODAL
const stepOrder = ['submitted', 'under_review', 'pending_payment', 'approved'];
const stepMap = { submitted: 'submitted', under_review: 'under_review', pending_payment: 'pending_payment', approved: 'approved', claimed: 'approved', verified: 'approved' };
function updateStepBar(status) {
    const mapped = stepMap[status] || status;
    const currentIdx = stepOrder.indexOf(mapped);
    document.querySelectorAll('#atStepBar .track-step').forEach(el => {
        const idx = stepOrder.indexOf(el.dataset.step);
        el.classList.remove('done', 'current', 'rejected');
        if (status === 'rejected' || status === 'cancelled') { if (el.dataset.step === 'submitted' || el.dataset.step === 'under_review') el.classList.add('done'); if (idx === 1) el.classList.add('rejected'); }
        else { if (idx < currentIdx) el.classList.add('done'); else if (idx === currentIdx) el.classList.add('current'); }
    });
}
document.querySelectorAll('.view-app-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        document.getElementById('atRef').textContent = d.ref || '—';
        document.getElementById('atBusiness').textContent = (d.business || '—') + ' / ' + (d.owner || '—');
        document.getElementById('atEmail').textContent = d.email || '—';
        document.getElementById('atContact').textContent = d.contact || '—';
        document.getElementById('atType').textContent = d.type || 'New';
        document.getElementById('atTaxYear').textContent = d.taxYear || '—';
        document.getElementById('atStep').textContent = 'Step ' + (d.step || 1) + '/8';
        document.getElementById('atDocCount').textContent = (d.docCount || 0) + ' file(s)';
        document.getElementById('atRejections').textContent = (d.rejections || 0) + ' time(s)';
        document.getElementById('atAmount').textContent = formatCurrency(d.amount);
        document.getElementById('atSubmitted').textContent = d.submitted ? new Date(d.submitted).toLocaleString('en-PH') : '—';
        document.getElementById('atIssued').textContent = d.issued ? new Date(d.issued).toLocaleString('en-PH') : '—';
        document.getElementById('atExpiry').textContent = d.expiry ? new Date(d.expiry).toLocaleDateString('en-PH') : '—';
        const status = (d.status || 'submitted').toLowerCase();
        document.getElementById('atStatusSelect').value = status;
        document.getElementById('atAppId').value = d.id;
        updateStepBar(status);
    });
});
document.getElementById('atStatusSelect').addEventListener('change', function() { updateStepBar(this.value); });
document.getElementById('atSaveStatusBtn').addEventListener('click', function() {
    const appId = document.getElementById('atAppId').value;
    const newStatus = document.getElementById('atStatusSelect').value;
    if (!appId) { Swal.fire({ icon: 'error', title: 'Missing Application ID', confirmButtonColor: 'var(--brown-primary)' }); return; }
    const btn = this;
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `update_application_status=1&application_id=${encodeURIComponent(appId)}&status=${encodeURIComponent(newStatus)}` })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i> Save';
        if (data.success) Swal.fire({ icon: 'success', title: 'Status Updated', timer: 1500, showConfirmButton: false }).then(() => location.reload());
        else Swal.fire({ icon: 'error', title: 'Update Failed', confirmButtonColor: 'var(--brown-primary)' });
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i> Save'; Swal.fire({ icon: 'error', title: 'Network Error', confirmButtonColor: 'var(--brown-primary)' }); });
});

// ADMIN APPOINTMENT CALENDAR
let adminAppointmentsCalendar = null;
function initAdminAppointmentsCalendar() {
    if (adminAppointmentsCalendar) { setTimeout(() => adminAppointmentsCalendar.updateSize(), 100); return; }
    const el = document.getElementById('adminAppointmentsCalendar');
    if (!el || typeof FullCalendar === 'undefined') return;
    const events = <?php echo json_encode($calendarEvents); ?>;
    adminAppointmentsCalendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
        height: 'auto', navLinks: true, editable: false, selectable: false, dayMaxEvents: 3, events,
        eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
        dayHeaderFormat: { weekday: 'short' },
        eventContent: function(arg) {
            const p = arg.event.extendedProps;
            const nodes = [];
            const d = document.createElement('div'); d.innerHTML = '<i class="bi bi-calendar-event"></i> ' + (p.date_display || '');
            d.style.fontWeight = '700'; d.style.fontSize = '0.72rem'; d.style.marginBottom = '1px'; nodes.push(d);
            const n = document.createElement('div'); n.textContent = p.owner_name || arg.event.title;
            n.style.fontWeight = 'bold'; n.style.fontSize = '0.8rem'; n.style.whiteSpace = 'normal'; n.style.wordBreak = 'break-word'; nodes.push(n);
            if (p.time_display) { const t = document.createElement('div'); t.innerHTML = '<i class="bi bi-clock"></i> ' + p.time_display; t.style.fontSize = '0.7rem'; t.style.marginTop = '2px'; nodes.push(t); }
            return { domNodes: nodes };
        },
        eventDidMount: function(info) {
            const s = (info.event.extendedProps.appointment_status || '').toLowerCase();
            let bg = '#6b7280', fg = '#fff';
            if (s === 'pending') { bg = '#d4a373'; fg = '#2b1810'; }
            else if (s === 'confirmed') { bg = '#3b82f6'; fg = '#fff'; }
            else if (s === 'completed') { bg = '#10b981'; fg = '#fff'; }
            else if (s === 'cancelled') { bg = '#ef4444'; fg = '#fff'; }
            info.el.style.backgroundColor = bg; info.el.style.borderColor = bg; info.el.style.color = fg;
        },
        eventClick: function (info) {
            const p = info.event.extendedProps;
            const apptDate = info.event.start.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            const s = (p.appointment_status || '').toLowerCase();
            let apptBadge = '<span class="badge bg-secondary">' + p.appointment_status + '</span>';
            if (s === 'pending') apptBadge = '<span class="badge" style="background:#d4a373;color:#2b1810;">Pending</span>';
            else if (s === 'confirmed') apptBadge = '<span class="badge" style="background:#3b82f6;">Confirmed</span>';
            else if (s === 'completed') apptBadge = '<span class="badge" style="background:#10b981;">Completed</span>';
            else if (s === 'cancelled') apptBadge = '<span class="badge" style="background:#ef4444;">Cancelled</span>';
            Swal.fire({
                title: '<i class="bi bi-calendar-check" style="color:var(--brown-primary);"></i> Appointment Details',
                html: `<div class="text-start"><p><strong>Applicant:</strong> ${p.owner_name}</p><p><strong>Contact:</strong> ${p.owner_contact || '—'}</p><p><strong>Email:</strong> ${p.owner_email || '—'}</p><hr><p><strong>Business:</strong> ${p.business_name}</p><p><strong>Reference:</strong> <code>${p.reference_no}</code></p><p><strong>Application Type:</strong> ${p.application_type}</p><p><strong>Application Status:</strong> <span class="badge bg-secondary">${p.application_status.replace(/_/g,' ').toUpperCase()}</span></p><hr><p><strong>Scheduled Date:</strong> ${apptDate}</p><p><strong>Scheduled Time:</strong> ${p.appointment_time}</p><p><strong>Appointment Status:</strong> ${apptBadge}</p></div>`,
                confirmButtonColor: '#6d4c41',
                width: 600
            });
        },
        eventDisplay: 'block'
    });
    adminAppointmentsCalendar.render();
}
function changeAdminCalendarView(viewName) { if (adminAppointmentsCalendar) adminAppointmentsCalendar.changeView(viewName); }

// DATATABLES + FILTERS
let activityLogTable = null;
$(document).ready(function() {
    if (document.querySelectorAll('#activityLogTable tbody tr[data-actor-role]').length > 0) {
        activityLogTable = $('#activityLogTable').DataTable({
            pageLength: 25, order: [[1, 'desc']],
            drawCallback: function () {
                // Renumber # so it always runs 1,2,3... in the order currently shown
                var api = this.api();
                var start = api.page.info().start;
                api.column(0, { page: 'current' }).nodes().each(function (cell, i) {
                    cell.innerHTML = start + i + 1;
                });
            },
            language: { search: 'Search log:', lengthMenu: 'Show _MENU_ entries', info: 'Showing _START_ to _END_ of _TOTAL_', emptyTable: 'No activity logged yet', paginate: { previous: '‹', next: '›' } },
            columnDefs: [{ orderable: false, targets: [0, 2, 8] }]
        });
    }
});
document.querySelectorAll('#activityFilterBtns button').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('#activityFilterBtns button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const role = this.dataset.role;
        if (!activityLogTable) return;
        $.fn.dataTable.ext.search.pop();
        if (role === 'all') { activityLogTable.draw(); }
        else if (role === 'payment') {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'activityLogTable') return true;
                const row = activityLogTable.row(dataIndex).node();
                return row.getAttribute('data-event-type') === 'payment';
            });
            activityLogTable.draw();
        }
        else if (role === '0') {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'activityLogTable') return true;
                const row = activityLogTable.row(dataIndex).node();
                const t = row.getAttribute('data-actor-role');
                const et = row.getAttribute('data-event-type');
                return et === 'payment' || !t || t === '0' || t === '';
            });
            activityLogTable.draw();
        }
        else {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'activityLogTable') return true;
                const row = activityLogTable.row(dataIndex).node();
                return row.getAttribute('data-actor-role') === role;
            });
            activityLogTable.draw();
        }
    });
});

$(document).ready(function() {
    if (document.querySelectorAll('#employeesTable tbody tr').length > 0 && !document.querySelector('#employeesTable tbody td[colspan]')) {
        if (!$.fn.DataTable.isDataTable('#employeesTable')) $('#employeesTable').DataTable({ pageLength: 10, order: [[6, 'desc'], [0, 'desc']], columnDefs: [{ orderable: false, targets: [7] }] });
    }
    if (document.querySelectorAll('#usersTable tbody tr').length > 0 && !document.querySelector('#usersTable tbody td[colspan]')) {
        if (!$.fn.DataTable.isDataTable('#usersTable')) $('#usersTable').DataTable({ pageLength: 10, order: [[4, 'desc'], [0, 'desc']] });
    }
    if (document.querySelectorAll('#trackingTable tbody tr').length > 0 && !document.querySelector('#trackingTable tbody td[colspan]')) {
        if (!$.fn.DataTable.isDataTable('#trackingTable')) $('#trackingTable').DataTable({ pageLength: 15, order: [[6, 'desc']], columnDefs: [{ orderable: false, targets: [8] }] });
    }
    if (document.querySelectorAll('#paymentsTable tbody tr').length > 0 && !document.querySelector('#paymentsTable tbody td[colspan]')) {
        if (!$.fn.DataTable.isDataTable('#paymentsTable')) $('#paymentsTable').DataTable({ pageLength: 15, order: [[6, 'desc']], columnDefs: [{ orderable: false, targets: [8] }] });
    }
    if (document.querySelectorAll('#announcementsTable tbody tr').length > 0 && !document.querySelector('#announcementsTable tbody td[colspan]')) {
        if (!$.fn.DataTable.isDataTable('#announcementsTable')) $('#announcementsTable').DataTable({ pageLength: 10, order: [[5, 'desc'], [0, 'desc']] });
    }
    if (document.querySelectorAll('#contactMessagesTable tbody tr').length > 0 && !document.querySelector('#contactMessagesTable tbody td[colspan]')) {
        if (!$.fn.DataTable.isDataTable('#contactMessagesTable')) $('#contactMessagesTable').DataTable({ pageLength: 10, order: [[5, 'desc'], [0, 'desc']], columnDefs: [{ orderable: false, targets: [6] }] });
    }
});
</script>
</body>
</html>