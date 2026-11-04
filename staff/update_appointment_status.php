<?php
session_start();
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

require_once '../config/connect.php';
require_once '../notifications_helper.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_type'] !== 4) {
    echo json_encode(['success' => false, 'message' => 'BPLO staff only.']);
    exit;
}

$input      = json_decode(file_get_contents('php://input'), true);
$appt_id    = (int)($input['appointment_id'] ?? 0);
$new_status = trim($input['status'] ?? '');
$remarks    = trim($input['remarks'] ?? '');

if (!$appt_id || !in_array($new_status, ['confirmed','cancelled','completed'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

// ------------------------------------------------------------
// LOAD APPOINTMENT + APPLICANT + APPLICATION DETAILS
// ------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT a.*,
           ba.reference_no, ba.application_no, ba.tax_year,
           bi.business_name,
           at.application_type,
           acc.firstname, acc.lastname, acc.email, acc.contact_number
    FROM appointments a
    LEFT JOIN business_applications ba ON a.application_id = ba.id
    LEFT JOIN business_info_data    bi ON ba.id = bi.application_id
    LEFT JOIN application_type_data at ON ba.id = at.application_id
    LEFT JOIN accounts              acc ON a.user_id = acc.id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $appt_id);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt) {
    echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
    exit;
}

// ------------------------------------------------------------
// UPDATE APPOINTMENT STATUS
// ------------------------------------------------------------
$upd = $conn->prepare("UPDATE appointments SET status = ?, updated_at = NOW() WHERE id = ?");
$upd->bind_param('si', $new_status, $appt_id);

if (!$upd->execute()) {
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $conn->error]);
    $upd->close();
    exit;
}
$upd->close();

// ------------------------------------------------------------
// COMPLETED (permit claimed) -> mark the linked application as CLAIMED
// so the owner's dashboard no longer asks to schedule an appointment again
// ------------------------------------------------------------
if ($new_status === 'completed' && !empty($appt['application_id'])) {
    $claimApp = $conn->prepare("UPDATE business_applications SET status = 'claimed', updated_at = NOW() WHERE id = ? AND status = 'verified'");
    if ($claimApp) {
        $claimApp->bind_param('i', $appt['application_id']);
        $claimApp->execute();
        $claimApp->close();
    }
}

// ------------------------------------------------------------
// BUILD APPLICANT / APPOINTMENT DISPLAY DATA
// ------------------------------------------------------------
$applicantId     = (int)$appt['user_id'];
$applicantName   = trim(($appt['firstname'] ?? '') . ' ' . ($appt['lastname'] ?? ''));
if ($applicantName === '') $applicantName = 'Applicant';
$applicantEmail  = trim($appt['email'] ?? '');
$applicantPhone  = trim($appt['contact_number'] ?? '');

$businessName    = $appt['business_name']    ?? 'your business';
$referenceNo     = $appt['reference_no']     ?? 'N/A';
$applicationNo   = $appt['application_no']   ?? '';
$appTypeDisplay  = $appt['application_type'] ?? 'New';
$taxYear         = $appt['tax_year']         ?? date('Y');

$apptDateRaw  = $appt['appointment_date'] ?? null;
$apptTimeRaw  = $appt['appointment_time'] ?? null;
$apptDateLong = $apptDateRaw ? date('F j, Y (l)', strtotime($apptDateRaw)) : 'N/A';
$apptTimeLong = $apptTimeRaw ? date('g:i A', strtotime($apptTimeRaw)) : 'N/A';
$apptShort    = $apptDateRaw ? date('M j, Y', strtotime($apptDateRaw)) : 'N/A';

// ------------------------------------------------------------
// IN-APP NOTIFICATION
// ------------------------------------------------------------
$title = ''; $msg = ''; $type = 'info';
if ($new_status === 'confirmed') {
    $title = 'Appointment Confirmed';
    $msg   = "Your appointment on {$apptDateLong} at {$apptTimeLong} has been confirmed by BPLO.";
    $type  = 'success';
} elseif ($new_status === 'cancelled') {
    $title = 'Appointment Cancelled';
    $msg   = "Your appointment on {$apptDateLong} at {$apptTimeLong} was cancelled.";
    if ($remarks) $msg .= " Reason: {$remarks}";
    $type  = 'error';
} elseif ($new_status === 'completed') {
    $title = 'Appointment Completed';
    $msg   = "Your appointment on {$apptDateLong} has been marked as completed. Your permit has been claimed.";
    $type  = 'success';
}

if ($title && function_exists('createNotification')) {
    createNotification($conn, $applicantId, $title, $msg, null, $type);
}

// ------------------------------------------------------------
// EMAIL VIA PHPMAILER
// ------------------------------------------------------------
$email_sent = false;
$email_msg  = '';

if ($applicantEmail !== '') {
    $mail = new PHPMailer(true);
    try {
        // ---------- SMTP ----------
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'buyawanongplacer@gmail.com';
        $mail->Password   = 'fsiqtfjcpjjizquc';                 // App password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('buyawanongplacer@gmail.com', 'Business Permits and Licensing Office');
        $mail->addAddress($applicantEmail, $applicantName);
        $mail->addReplyTo('buyawanongplacer@gmail.com', 'BPLO - Municipality of Placer');
        $mail->isHTML(true);

        // ---------- Shared building blocks ----------
        $escName     = htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8');
        $escBusiness = htmlspecialchars($businessName,  ENT_QUOTES, 'UTF-8');
        $escRef      = htmlspecialchars($referenceNo,   ENT_QUOTES, 'UTF-8');
        $escType     = htmlspecialchars($appTypeDisplay, ENT_QUOTES, 'UTF-8');
        $escDate     = htmlspecialchars($apptDateLong,  ENT_QUOTES, 'UTF-8');
        $escTime     = htmlspecialchars($apptTimeLong,  ENT_QUOTES, 'UTF-8');
        $escShort    = htmlspecialchars($apptShort,     ENT_QUOTES, 'UTF-8');
        $escTaxYear  = htmlspecialchars((string)$taxYear, ENT_QUOTES, 'UTF-8');

        // Reusable header (crest + LGU wordmark)
        $headerHtml = "
            <div style='background:#0f2038; padding:24px 28px; text-align:center;'>
                <div style='color:#ffffff; font-size:11px; letter-spacing:3px; font-weight:600;'>
                    REPUBLIC OF THE PHILIPPINES
                </div>
                <div style='color:#94a3b8; font-size:10px; letter-spacing:2px; margin-top:2px;'>
                    PROVINCE OF SURIGAO DEL NORTE
                </div>
                <div style='color:#fbbf24; font-size:16px; font-weight:800; margin-top:10px; letter-spacing:1px;'>
                    MUNICIPALITY OF PLACER
                </div>
                <div style='color:#ffffff; font-size:11px; margin-top:4px;'>
                    Business Permits and Licensing Office
                </div>
            </div>
        ";

        // Reusable footer
        $footerHtml = "
            <div style='background:#f1f5f9; padding:18px 28px; text-align:center; border-top:1px solid #e2e8f0;'>
                <div style='font-size:11px; color:#64748b; line-height:1.6;'>
                    This is an automated notification. Please do not reply directly to this email.
                </div>
                <div style='font-size:11px; color:#64748b; margin-top:8px;'>
                    <strong>Municipality of Placer</strong><br>
                    Surigao del Norte, Philippines<br>
                    For inquiries, contact: buyawanongplacer@gmail.com
                </div>
            </div>
        ";

        // Reusable detail table
        $detailsHtml = "
            <table style='width:100%; border-collapse:collapse; margin:18px 0; font-size:13px;'>
                <tr>
                    <td style='padding:10px 14px; background:#f8fafc; font-weight:600; color:#0f2038; width:40%; border:1px solid #e2e8f0;'>Reference Number</td>
                    <td style='padding:10px 14px; border:1px solid #e2e8f0; font-family:monospace;'>$escRef</td>
                </tr>
                <tr>
                    <td style='padding:10px 14px; background:#f8fafc; font-weight:600; color:#0f2038; border:1px solid #e2e8f0;'>Business / Trade Name</td>
                    <td style='padding:10px 14px; border:1px solid #e2e8f0;'>$escBusiness</td>
                </tr>
                <tr>
                    <td style='padding:10px 14px; background:#f8fafc; font-weight:600; color:#0f2038; border:1px solid #e2e8f0;'>Application Type</td>
                    <td style='padding:10px 14px; border:1px solid #e2e8f0;'>$escType</td>
                </tr>
                <tr>
                    <td style='padding:10px 14px; background:#f8fafc; font-weight:600; color:#0f2038; border:1px solid #e2e8f0;'>Scheduled Date</td>
                    <td style='padding:10px 14px; border:1px solid #e2e8f0;'>$escDate</td>
                </tr>
                <tr>
                    <td style='padding:10px 14px; background:#f8fafc; font-weight:600; color:#0f2038; border:1px solid #e2e8f0;'>Scheduled Time</td>
                    <td style='padding:10px 14px; border:1px solid #e2e8f0;'>$escTime</td>
                </tr>
            </table>
        ";

        // -------------------------------------------------
        // 1) APPOINTMENT CONFIRMED
        // -------------------------------------------------
        if ($new_status === 'confirmed') {
            $mail->Subject = 'Appointment Confirmation — ' . $businessName . ' (' . $referenceNo . ')';

            $mail->Body = "
                <div style='font-family: Arial, Helvetica, sans-serif; max-width: 640px; margin: 0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;'>
                    $headerHtml

                    <div style='padding: 28px 32px; color:#1e293b; line-height:1.65;'>
                        <p style='margin:0 0 6px 0; font-size:12px; color:#64748b; letter-spacing:1px; text-transform:uppercase;'>Appointment Confirmation</p>
                        <h1 style='margin:0 0 18px 0; font-size:20px; color:#0f2038;'>Your appointment has been confirmed</h1>

                        <p>Dear <strong>$escName</strong>,</p>

                        <p>This is to formally confirm your scheduled appointment with the
                           <strong>Business Permits and Licensing Office (BPLO)</strong> of the
                           Municipality of Placer in connection with your
                           <strong>$escType</strong> business permit application for the tax year
                           <strong>$escTaxYear</strong>.</p>

                        $detailsHtml

                        <div style='background:#ecfdf5; border-left:4px solid #10b981; padding:14px 18px; border-radius:4px; margin:20px 0;'>
                            <div style='font-weight:700; color:#065f46; font-size:13px; margin-bottom:4px;'>
                                ✓ Status: CONFIRMED
                            </div>
                            <div style='font-size:12px; color:#065f46;'>
                                Please arrive on time at the BPLO office. Bring all original
                                documents submitted online and a valid government-issued ID
                                for verification.
                            </div>
                        </div>

                        <p style='font-size:13px; color:#475569;'><strong>Reminders:</strong></p>
                        <ul style='font-size:13px; color:#475569; padding-left:20px; margin-top:0;'>
                            <li>Arrive at least 10 minutes before the scheduled time.</li>
                            <li>Bring the printed copy of this email or your reference number.</li>
                            <li>Present a valid government-issued ID and the originals of your uploaded requirements.</li>
                            <li>If you are unable to attend, please notify our office as soon as possible.</li>
                        </ul>

                        <p style='font-size:13px; color:#475569; margin-top:24px;'>
                            Thank you for your cooperation.
                        </p>

                        <p style='font-size:13px; color:#475569; margin-top:24px; margin-bottom:0;'>
                            Respectfully yours,<br>
                            <strong>Business Permits and Licensing Office</strong><br>
                            Municipality of Placer, Surigao del Norte
                        </p>
                    </div>

                    $footerHtml
                </div>
            ";
            $mail->AltBody = "Dear $applicantName,\n\nYour appointment on $apptDateLong at $apptTimeLong for $businessName (Ref: $referenceNo) has been CONFIRMED.\n\nPlease arrive on time and bring your original documents and valid ID.\n\nBusiness Permits and Licensing Office\nMunicipality of Placer";
        }

        // -------------------------------------------------
        // 2) APPOINTMENT CANCELLED
        // -------------------------------------------------
        elseif ($new_status === 'cancelled') {
            $mail->Subject = 'Appointment Cancellation — ' . $businessName . ' (' . $referenceNo . ')';

            $reasonBlock = '';
            if ($remarks !== '') {
                $escReason = htmlspecialchars($remarks, ENT_QUOTES, 'UTF-8');
                $reasonBlock = "
                    <div style='background:#fef2f2; border-left:4px solid #ef4444; padding:14px 18px; border-radius:4px; margin:20px 0;'>
                        <div style='font-weight:700; color:#991b1b; font-size:13px; margin-bottom:4px;'>
                            Reason for Cancellation
                        </div>
                        <div style='font-size:13px; color:#991b1b;'>
                            $escReason
                        </div>
                    </div>
                ";
            }

            $mail->Body = "
                <div style='font-family: Arial, Helvetica, sans-serif; max-width: 640px; margin: 0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;'>
                    $headerHtml

                    <div style='padding: 28px 32px; color:#1e293b; line-height:1.65;'>
                        <p style='margin:0 0 6px 0; font-size:12px; color:#64748b; letter-spacing:1px; text-transform:uppercase;'>Appointment Cancellation</p>
                        <h1 style='margin:0 0 18px 0; font-size:20px; color:#0f2038;'>Your appointment has been cancelled</h1>

                        <p>Dear <strong>$escName</strong>,</p>

                        <p>Please be advised that your scheduled appointment with the
                           <strong>Business Permits and Licensing Office (BPLO)</strong> of the
                           Municipality of Placer has been <strong>cancelled</strong>.</p>

                        $detailsHtml

                        <div style='background:#fef2f2; border-left:4px solid #ef4444; padding:14px 18px; border-radius:4px; margin:20px 0;'>
                            <div style='font-weight:700; color:#991b1b; font-size:13px;'>
                                ✗ Status: CANCELLED
                            </div>
                        </div>

                        $reasonBlock

                        <p style='font-size:13px; color:#475569;'>
                            You may book a new appointment through the online portal at your
                            convenience. If you believe this cancellation was made in error,
                            please contact the BPLO office immediately.
                        </p>

                        <p style='font-size:13px; color:#475569; margin-top:24px; margin-bottom:0;'>
                            Respectfully yours,<br>
                            <strong>Business Permits and Licensing Office</strong><br>
                            Municipality of Placer, Surigao del Norte
                        </p>
                    </div>

                    $footerHtml
                </div>
            ";
            $mail->AltBody = "Dear $applicantName,\n\nYour appointment on $apptDateLong at $apptTimeLong for $businessName (Ref: $referenceNo) has been CANCELLED." . ($remarks ? "\nReason: $remarks" : "") . "\n\nBusiness Permits and Licensing Office\nMunicipality of Placer";
        }

        // -------------------------------------------------
        // 3) APPOINTMENT COMPLETED
        // -------------------------------------------------
        elseif ($new_status === 'completed') {
            $mail->Subject = 'Appointment Completed — ' . $businessName . ' (' . $referenceNo . ')';

            $mail->Body = "
                <div style='font-family: Arial, Helvetica, sans-serif; max-width: 640px; margin: 0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;'>
                    $headerHtml

                    <div style='padding: 28px 32px; color:#1e293b; line-height:1.65;'>
                        <p style='margin:0 0 6px 0; font-size:12px; color:#64748b; letter-spacing:1px; text-transform:uppercase;'>Appointment Completed</p>
                        <h1 style='margin:0 0 18px 0; font-size:20px; color:#0f2038;'>Your appointment has been completed</h1>

                        <p>Dear <strong>$escName</strong>,</p>

                        <p>We are pleased to inform you that your scheduled appointment with the
                           <strong>Business Permits and Licensing Office (BPLO)</strong> of the
                           Municipality of Placer has been successfully <strong>completed</strong>.</p>

                        $detailsHtml

                        <div style='background:#eff6ff; border-left:4px solid #3b82f6; padding:14px 18px; border-radius:4px; margin:20px 0;'>
                            <div style='font-weight:700; color:#1e40af; font-size:13px;'>
                                ✓ Status: COMPLETED
                            </div>
                        </div>

                        <p style='font-size:13px; color:#475569;'>
                            Thank you for visiting the BPLO office. You may monitor the status of
                            your application through the online portal at any time.
                        </p>

                        <p style='font-size:13px; color:#475569; margin-top:24px; margin-bottom:0;'>
                            Respectfully yours,<br>
                            <strong>Business Permits and Licensing Office</strong><br>
                            Municipality of Placer, Surigao del Norte
                        </p>
                    </div>

                    $footerHtml
                </div>
            ";
            $mail->AltBody = "Dear $applicantName,\n\nYour appointment on $apptDateLong for $businessName (Ref: $referenceNo) has been COMPLETED.\n\nThank you for visiting the BPLO office.\n\nBusiness Permits and Licensing Office\nMunicipality of Placer";
        }

        $mail->send();
        $email_sent = true;
        $email_msg  = 'A confirmation email has been sent to the applicant.';

    } catch (Exception $e) {
        error_log("Appointment email failed for appt #{$appt_id}: " . $mail->ErrorInfo);
        $email_msg = 'Appointment updated, but the email could not be sent.';
    }
} else {
    $email_msg = 'Applicant has no email address on file.';
}

// ------------------------------------------------------------
// RESPONSE
// ------------------------------------------------------------
echo json_encode([
    'success'    => true,
    'message'    => "Appointment marked as $new_status.",
    'email_sent' => $email_sent,
    'email_msg'  => $email_msg,
]);

$conn->close();