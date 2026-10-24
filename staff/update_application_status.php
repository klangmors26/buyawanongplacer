<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/connect.php';
require_once '../vendor/autoload.php';
require_once '../notifications_helper.php';

if (!function_exists('insertNotif')) {
    function insertNotif($conn, $userId, $type, $title, $message, $link = null) {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param('issss', $userId, $type, $title, $message, $link);
            $stmt->execute();
            $stmt->close();
        }
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$user_type  = (int)$_SESSION['user_type'];
$staff_id   = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'LGU Staff';

$appId       = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
$newStatus   = isset($_POST['status']) ? trim($_POST['status']) : '';
$notes       = isset($_POST['notes']) ? trim($_POST['notes']) : '';
$missingDocs = isset($_POST['missing_docs']) ? $_POST['missing_docs'] : [];

if (!$appId || empty($newStatus)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

// ✅ FIX #1: allow BPLO to also set 'submitted'
$allowedStatuses = [];
if ($user_type == 4) {
    $allowedStatuses = ['submitted', 'verified', 'rejected', 'pending_payment'];
} elseif ($user_type == 3) {
    $allowedStatuses = ['payment_confirmed', 'cancelled'];
}

if (!in_array($newStatus, $allowedStatuses)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status value. Allowed: ' . implode(', ', $allowedStatuses)
    ]);
    exit;
}

$check_stmt = $conn->prepare("SELECT id, status FROM business_applications WHERE id = ?");
if (!$check_stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}
$check_stmt->bind_param("i", $appId);
$check_stmt->execute();
$result = $check_stmt->get_result();
if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Application not found']);
    $check_stmt->close();
    exit;
}
$current_data = $result->fetch_assoc();
$current_status = $current_data['status'];
$check_stmt->close();

// Handle rejection details
if ($newStatus === 'rejected' && $user_type == 4) {
    $missing_json = json_encode($missingDocs);

    $check_rej = $conn->prepare("SELECT id FROM application_rejections WHERE application_id = ?");
    $check_rej->bind_param("i", $appId);
    $check_rej->execute();
    $rej_exists = $check_rej->get_result()->num_rows > 0;
    $check_rej->close();

    if ($rej_exists) {
        $rej_sql = "UPDATE application_rejections
                    SET missing_documents = ?, notes = ?, rejected_by = ?, rejected_at = NOW()
                    WHERE application_id = ?";
        $rej_stmt = $conn->prepare($rej_sql);
        if (!$rej_stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        $rej_stmt->bind_param("ssii", $missing_json, $notes, $staff_id, $appId);
    } else {
        $rej_sql = "INSERT INTO application_rejections (application_id, missing_documents, notes, rejected_by, rejected_at)
                    VALUES (?, ?, ?, ?, NOW())";
        $rej_stmt = $conn->prepare($rej_sql);
        if (!$rej_stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        $rej_stmt->bind_param("issi", $appId, $missing_json, $notes, $staff_id);
    }

    if (!$rej_stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save rejection details: ' . $rej_stmt->error]);
        $rej_stmt->close();
        exit;
    }
    $rej_stmt->close();
}

// ✅ FIX #2: clear stale rejection row when moving forward
if (in_array($newStatus, ['submitted', 'pending_payment', 'payment_confirmed'], true)) {
    $clearRej = $conn->prepare("DELETE FROM application_rejections WHERE application_id = ?");
    if ($clearRej) {
        $clearRej->bind_param("i", $appId);
        $clearRej->execute();
        $clearRej->close();
    }
}

$stmt = $conn->prepare("UPDATE business_applications SET status = ?, updated_at = NOW() WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}
$stmt->bind_param("si", $newStatus, $appId);

$email_sent     = false;
$email_msg      = '';
$appt_completed = 0;
$appt_cancelled = 0;
$appt_confirmed = 0;
$appt_pending   = 0;

$redirect_url = ($user_type == 3) ? 'dashboard_treasury.php' : 'dashboard_bplo.php';

// Notify applicant + cross-notify staff
$notif_stmt = $conn->prepare("
    SELECT ba.user_id, bi.business_name, ba.reference_no, at.application_type
    FROM business_applications ba
    LEFT JOIN business_info_data    bi ON ba.id = bi.application_id
    LEFT JOIN application_type_data at ON ba.id = at.application_id
    WHERE ba.id = ?
    LIMIT 1
");
if ($notif_stmt) {
    $notif_stmt->bind_param('i', $appId);
    $notif_stmt->execute();
    $notif_data = $notif_stmt->get_result()->fetch_assoc();
    $notif_stmt->close();

    if ($notif_data && !empty($notif_data['user_id'])) {
        $applicantId = (int)$notif_data['user_id'];
        $bizName     = $notif_data['business_name'] ?? 'your business';
        $refDisplay  = $notif_data['reference_no']  ?? 'N/A';

        switch ($newStatus) {
            // ✅ FIX #3: new branch for 'submitted'
            case 'submitted':
                insertNotif($conn, $applicantId, 'info', 'Application Resubmitted — Under Review',
                    "Your application for \"$bizName\" (Ref: $refDisplay) has been reset to Submitted and is awaiting re-assessment.",
                    'dashboard.php');
                break;
            case 'payment_confirmed':
                insertNotif($conn, $applicantId, 'success', 'Payment Confirmed',
                    "Your payment for \"$bizName\" (Ref: $refDisplay) has been confirmed. Your application is now under review.", null);
                break;
            case 'pending_payment':
                insertNotif($conn, $applicantId, 'warning', 'Payment Required',
                    "Please complete the payment for \"$bizName\" (Ref: $refDisplay) to proceed.", null);
                break;
            case 'verified':
                insertNotif($conn, $applicantId, 'success', 'Permit Verified — Ready for Claiming',
                    "Your permit for \"$bizName\" (Ref: $refDisplay) has been verified. Please check your email for claiming details.",
                    'dashboard.php');
                break;
            case 'rejected':
                insertNotif($conn, $applicantId, 'danger', 'Application Rejected — Action Required',
                    "Your application for \"$bizName\" (Ref: $refDisplay) was rejected. Please check your email for missing documents.",
                    'dashboard.php');
                break;
            case 'cancelled':
                insertNotif($conn, $applicantId, 'danger', 'Application Cancelled',
                    "Your application for \"$bizName\" (Ref: $refDisplay) has been cancelled.",
                    'dashboard.php');
                break;
        }

        // Cross-notify staff
        if ($newStatus === 'payment_confirmed' && $user_type == 3) {
            $staffRes = $conn->query("SELECT id FROM accounts WHERE user_type = 4");
            if ($staffRes) {
                while ($row = $staffRes->fetch_assoc()) {
                    insertNotif($conn, (int)$row['id'], 'permit',
                        'Payment Confirmed — Ready for Verification',
                        "\"$bizName\" (Ref: $refDisplay) has been confirmed by Treasury. Please review the documents.",
                        'dashboard_bplo.php');
                }
            }
        }

        if (($newStatus === 'verified' || $newStatus === 'rejected') && $user_type == 4) {
            $verb = ($newStatus === 'verified') ? 'verified' : 'rejected';
            $type = ($newStatus === 'verified') ? 'success'  : 'danger';
            $staffRes = $conn->query("SELECT id FROM accounts WHERE user_type = 3");
            if ($staffRes) {
                while ($row = $staffRes->fetch_assoc()) {
                    insertNotif($conn, (int)$row['id'], $type,
                        'Application ' . ucfirst($verb),
                        "\"$bizName\" (Ref: $refDisplay) has been $verb by BPLO.",
                        'dashboard_treasury.php');
                }
            }
        }

        if ($newStatus === 'cancelled') {
            $staffRes = $conn->query("SELECT id, user_type FROM accounts WHERE user_type IN (3,4)");
            if ($staffRes) {
                while ($row = $staffRes->fetch_assoc()) {
                    $link = ((int)$row['user_type'] === 3)
                        ? 'dashboard_treasury.php'
                        : 'dashboard_bplo.php';
                    insertNotif($conn, (int)$row['id'], 'warning',
                        'Application Cancelled',
                        "\"$bizName\" (Ref: $refDisplay) has been cancelled.",
                        $link);
                }
            }
        }
    }
}

if ($stmt->execute()) {

    // Auto-update appointments
    if ($newStatus === 'verified') {
        $apptUpd = $conn->prepare("UPDATE appointments SET status = 'completed', updated_at = NOW() WHERE application_id = ? AND status IN ('pending','confirmed')");
        if ($apptUpd) { $apptUpd->bind_param("i", $appId); $apptUpd->execute(); $appt_completed = $apptUpd->affected_rows; $apptUpd->close(); }
    } elseif ($newStatus === 'rejected') {
        $apptUpd = $conn->prepare("UPDATE appointments SET status = 'pending', updated_at = NOW() WHERE application_id = ? AND status IN ('pending','confirmed')");
        if ($apptUpd) { $apptUpd->bind_param("i", $appId); $apptUpd->execute(); $appt_pending = $apptUpd->affected_rows; $apptUpd->close(); }
    } elseif (in_array($newStatus, ['submitted', 'pending_payment', 'payment_confirmed'])) {
        $apptUpd = $conn->prepare("UPDATE appointments SET status = 'confirmed', updated_at = NOW() WHERE application_id = ? AND status IN ('pending','confirmed')");
        if ($apptUpd) { $apptUpd->bind_param("i", $appId); $apptUpd->execute(); $appt_confirmed = $apptUpd->affected_rows; $apptUpd->close(); }
    } elseif ($newStatus === 'cancelled') {
        $apptUpd = $conn->prepare("UPDATE appointments SET status = 'cancelled', updated_at = NOW() WHERE application_id = ? AND status IN ('pending','confirmed')");
        if ($apptUpd) { $apptUpd->bind_param("i", $appId); $apptUpd->execute(); $appt_cancelled = $apptUpd->affected_rows; $apptUpd->close(); }
    }

    // Log status change
    $table_check = $conn->query("SHOW TABLES LIKE 'status_history'");
    if ($table_check && $table_check->num_rows > 0) {
        $log_stmt = $conn->prepare("INSERT INTO status_history (application_id, old_status, new_status, changed_by, notes, changed_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($log_stmt) {
            $log_stmt->bind_param("issis", $appId, $current_status, $newStatus, $staff_id, $notes);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }

    // Send email if user_type = 4
    if ($user_type == 4) {
        $fetch_sql = "
            SELECT a.email, a.firstname, a.lastname,
                   bi.business_name, ba.reference_no, at.application_type,
                   app.appointment_date, app.appointment_time
            FROM business_applications ba
            LEFT JOIN business_info_data    bi  ON ba.id = bi.application_id
            LEFT JOIN accounts              a   ON ba.user_id = a.id
            LEFT JOIN application_type_data at  ON ba.id = at.application_id
            LEFT JOIN appointments          app ON ba.id = app.application_id
            WHERE ba.id = ?
            ORDER BY app.appointment_date DESC, app.appointment_time DESC
            LIMIT 1
        ";
        $fetch_stmt = $conn->prepare($fetch_sql);
        if ($fetch_stmt) {
            $fetch_stmt->bind_param("i", $appId);
            $fetch_stmt->execute();
            $app_data = $fetch_stmt->get_result()->fetch_assoc();
            $fetch_stmt->close();

            if ($app_data && !empty($app_data['email'])) {
                $user_name  = $app_data['firstname'] . ' ' . $app_data['lastname'];
                $business   = $app_data['business_name'] ?? 'your business';
                $ref_no     = $app_data['reference_no']  ?? 'N/A';
                $appTypeDisplay = $app_data['application_type'] ?? 'New';
                $appt_date  = $app_data['appointment_date'] ?? null;
                $appt_time  = $app_data['appointment_time'] ?? null;

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'buyawanongplacer@gmail.com';
                    $mail->Password   = 'fsiqtfjcpjjizquc';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->setFrom('buyawanongplacer@gmail.com', 'Buyawanong Placer - LGU');
                    $mail->addAddress($app_data['email'], $user_name);
                    $mail->isHTML(true);

                    if ($newStatus === 'verified') {
                        $schedule_text = ($appt_date && $appt_time)
                            ? "<p><strong>Appointment Schedule:</strong> " . date('F j, Y', strtotime($appt_date)) . " at " . date('g:i A', strtotime($appt_time)) . "</p>"
                            : "<p><strong>Appointment Schedule:</strong> You may claim your certificate during office hours (Monday–Friday, 8:00 AM – 5:00 PM).</p>";

                        $mail->Subject = 'Your Business Permit Certificate is Ready for Claiming';
                        $mail->Body = "
                            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                                <h2 style='color: #00c389;'>Certificate Ready for Collection</h2>
                                <p>Dear <strong>$user_name</strong>,</p>
                                <p>Your <strong>$appTypeDisplay</strong> application for <strong>$business</strong> (Reference: <strong>$ref_no</strong>) has been <strong>verified</strong>.</p>
                                <p>Your business permit certificate is now available for claiming at the <strong>Municipality of Placer</strong> office.</p>
                                $schedule_text
                                <p>Please bring a valid government-issued ID and your reference number.</p>
                                <hr><small>Municipality of Placer, Surigao del Norte</small>
                            </div>
                        ";
                        $mail->AltBody = "Your $appTypeDisplay application for $business has been verified. Reference: $ref_no.";
                    } elseif ($newStatus === 'rejected') {
                        $rej_fetch = $conn->prepare("SELECT missing_documents, notes FROM application_rejections WHERE application_id = ?");
                        $rej_fetch->bind_param("i", $appId);
                        $rej_fetch->execute();
                        $rej_data = $rej_fetch->get_result()->fetch_assoc();
                        $rej_fetch->close();

                        $missing_list = '';
                        if ($rej_data && !empty($rej_data['missing_documents'])) {
                            $missing = json_decode($rej_data['missing_documents'], true);
                            if (is_array($missing) && count($missing) > 0) {
                                $missing_list = "<ul>";
                                $doc_map = [
                                    'barangay' => 'Barangay Clearance',
                                    'dti_sec'  => 'DTI/SEC – Xerox Copy',
                                    'rpt'      => 'RPT – Current Year 2026 Payment',
                                    'engineering' => 'Engineering',
                                    'zoning'   => 'Zoning Certification (MPDC)',
                                    'treasury' => 'Treasury',
                                    'sanitary' => 'Sanitary Health Permit (Health Center)',
                                    'fire_safety' => 'Fire Safety Certificate 2026'
                                ];
                                foreach ($missing as $doc_key) {
                                    if (isset($doc_map[$doc_key])) $missing_list .= "<li>" . $doc_map[$doc_key] . "</li>";
                                }
                                $missing_list .= "</ul>";
                            }
                        }
                        if (empty($missing_list)) {
                            $missing_list = "<p>Please review the requirements and submit all necessary documents.</p>";
                        }

                        $mail->Subject = 'Action Required: Missing Documents for Your Business Permit Application';
                        $mail->Body = "
                            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                                <h2 style='color: #dc3545;'>Application Rejected – Missing Documents</h2>
                                <p>Dear <strong>$user_name</strong>,</p>
                                <p>We have reviewed your <strong>$appTypeDisplay</strong> application for <strong>$business</strong> (Reference: <strong>$ref_no</strong>) and found the following documents <strong>missing or incomplete</strong>:</p>
                                $missing_list
                                <p><strong>Remarks:</strong> $notes</p>
                                <p>Please prepare the missing documents and re‑submit your application.</p>
                                <hr><small>Municipality of Placer, Surigao Del Norte</small>
                            </div>
                        ";
                        $mail->AltBody = "Your $appTypeDisplay application for $business has been rejected. Reference: $ref_no.";
                    }
                    // ✅ FIX #4: email branch for 'submitted'
                    elseif ($newStatus === 'submitted') {
                        $mail->Subject = 'Your Application is Back Under Review';
                        $mail->Body = "
                            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                                <h2 style='color: #f59e0b;'>Application Re-Queued</h2>
                                <p>Dear <strong>$user_name</strong>,</p>
                                <p>Your <strong>$appTypeDisplay</strong> application for <strong>$business</strong>
                                   (Reference: <strong>$ref_no</strong>) has been moved back to <strong>Submitted</strong>
                                   and is awaiting re-assessment by BPLO.</p>
                                <p>You will receive another email once the fees have been re-assessed.</p>
                                <hr><small>Municipality of Placer, Surigao del Norte</small>
                            </div>
                        ";
                        $mail->AltBody = "Your application $ref_no is back under review.";
                    }

                    $mail->send();
                    $email_sent = true;
                    $email_msg  = 'An email notification has been sent to the applicant.';
                } catch (Exception $e) {
                    error_log("Email sending failed for application $appId: " . $mail->ErrorInfo);
                    $email_msg = 'Status updated but email could not be sent.';
                }
            } else {
                $email_msg = 'Applicant has no email address on file.';
            }
        }
    }

    // Redirect target
    if ($user_type == 4) {
        $redirect_url = 'view_application.php?id=' . $appId;
    } else {
        $redirect_url = 'dashboard_treasury.php';
    }

    echo json_encode([
        'success'        => true,
        'message'        => 'Status updated successfully',
        'old_status'     => $current_status,
        'new_status'     => $newStatus,
        'application_id' => $appId,
        'appt_completed' => $appt_completed,
        'appt_confirmed' => $appt_confirmed,
        'appt_pending'   => $appt_pending,
        'appt_cancelled' => $appt_cancelled,
        'email_sent'     => $email_sent,
        'email_msg'      => $email_msg,
        'redirect'       => $redirect_url
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>