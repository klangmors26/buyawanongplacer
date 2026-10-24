<?php
// mail_helper.php — reusable PHPMailer wrapper + email templates
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ================================================================
 *  SITE URL — production domain
 *  ================================================================ */
define('SITE_URL', 'https://buyawanongplacer.online');

/**
 * Send a single HTML email via Gmail SMTP.
 * Returns true on success, false on failure (never throws).
 */
function sendMail(string $to, string $toName, string $subject, string $htmlBody, string $altBody = ''): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'buyawanongplacer@gmail.com';
        $mail->Password   = 'fsiqtfjcpjjizquc';   // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('buyawanongplacer@gmail.com', 'Buyawanong Placer');
        $mail->addAddress($to, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[PHPMailer] to=' . $to . ' error=' . $mail->ErrorInfo);
        return false;
    }
}

/* ================================================================
 *  SHARED SHELL
 *  ================================================================ */
function _emailShell(string $titleColor, string $heading, string $bodyHtml): string
{
    return "
    <div style='font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;
                background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;'>
        <div style='background:#1e4b2e;color:#fff;padding:20px 24px;'>
            <h2 style='margin:0;font-size:1.15rem;'>Business Permit Portal</h2>
            <small style='opacity:.8;'>Municipality of Placer, Surigao del Norte</small>
        </div>
        <div style='padding:24px;color:#1e293b;line-height:1.55;'>
            <h3 style='color:{$titleColor};margin-top:0;'>{$heading}</h3>
            {$bodyHtml}
        </div>
        <div style='background:#f8fafc;padding:14px 24px;font-size:12px;color:#64748b;text-align:center;'>
            This is an automated message — please do not reply directly.
        </div>
    </div>";
}

/* ================================================================
 *  TEMPLATES
 *  ================================================================ */

/** Email to the applicant confirming their payment. */
function paymentReceivedApplicantEmail(string $applicantName, string $businessName, string $ref, string $amount): string
{
    $body = "
        <p>Hello <strong>" . htmlspecialchars($applicantName) . "</strong>,</p>
        <p>We have successfully received your payment for your business permit application.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px;background:#f1f5f9;'><strong>Business</strong></td>
                <td style='padding:8px;'>" . htmlspecialchars($businessName) . "</td></tr>
            <tr><td style='padding:8px;background:#f1f5f9;'><strong>Reference No.</strong></td>
                <td style='padding:8px;'>" . htmlspecialchars($ref) . "</td></tr>
            <tr><td style='padding:8px;background:#f1f5f9;'><strong>Amount Paid</strong></td>
                <td style='padding:8px;color:#198754;font-weight:700;'>₱" . htmlspecialchars($amount) . "</td></tr>
            <tr><td style='padding:8px;background:#f1f5f9;'><strong>Status</strong></td>
                <td style='padding:8px;'>Under review by BPLO</td></tr>
        </table>
        <p><strong>What happens next?</strong></p>
        <ul>
            <li>Verification of submitted documents (1–2 working days).</li>
            <li>Issuance of official receipt and assessment notice.</li>
            <li>Final approval and release of the digital permit.</li>
        </ul>
        <p>You will receive another email once your permit is ready for download.</p>
        <p>You can also track your application anytime on your dashboard.</p>
    ";
    return _emailShell('#198754', 'Payment Received', $body);
}

/** Email to staff (types 3, 4, 5) notifying them of a new payment. */
function paymentReceivedStaffEmail(string $staffName, string $applicantName, string $businessName, string $ref, string $amount): string
{
    $body = "
        <p>Hello <strong>" . htmlspecialchars($staffName) . "</strong>,</p>
        <p>A payment has just been received and requires your attention.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px;background:#f1f5f9;'><strong>Applicant</strong></td>
                <td style='padding:8px;'>" . htmlspecialchars($applicantName) . "</td></tr>
            <tr><td style='padding:8px;background:#f1f5f9;'><strong>Business</strong></td>
                <td style='padding:8px;'>" . htmlspecialchars($businessName) . "</td></tr>
            <tr><td style='padding:8px;background:#f1f5f9;'><strong>Reference No.</strong></td>
                <td style='padding:8px;'>" . htmlspecialchars($ref) . "</td></tr>
            <tr><td style='padding:8px;background:#f1f5f9;'><strong>Amount</strong></td>
                <td style='padding:8px;color:#198754;font-weight:700;'>₱" . htmlspecialchars($amount) . "</td></tr>
        </table>
        <p>Please log in to the admin portal to verify the payment and process the application.</p>
    ";
    return _emailShell('#1e4b2e', 'Payment Received — Action Required', $body);
}

/* ================================================================
 *  FEE ASSESSMENT EMAIL (sent by Treasury staff after assessment)
 *  ================================================================ */
function feeAssessmentEmail(
    string $applicantName,
    string $businessName,
    string $ref,
    string $amount,
    string $payLink
): string {
    $body = "
        <p>Hello <strong>" . htmlspecialchars($applicantName) . "</strong>,</p>
        <p>The <strong>Treasury Office</strong> has completed the fee assessment for your business permit application. Please review the details below and proceed with payment.</p>

        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:10px;background:#f1f5f9;width:40%;'><strong>Business</strong></td>
                <td style='padding:10px;'>" . htmlspecialchars($businessName) . "</td></tr>
            <tr><td style='padding:10px;background:#f1f5f9;'><strong>Reference No.</strong></td>
                <td style='padding:10px;'>" . htmlspecialchars($ref) . "</td></tr>
            <tr><td style='padding:10px;background:#f1f5f9;'><strong>Total Amount Due</strong></td>
                <td style='padding:10px;color:#198754;font-weight:800;font-size:1.2rem;'>₱" . htmlspecialchars($amount) . "</td></tr>
        </table>

        <p><strong>What to do next?</strong></p>
        <ol style='padding-left:20px;'>
            <li>Log in to your Business Permit Portal account.</li>
            <li>Go to your <strong>Dashboard</strong>.</li>
            <li>Click <strong>Pay Now</strong> and complete the payment via <strong>PayPal</strong>.</li>
        </ol>

        <p style='text-align:center;margin:28px 0;'>
            <a href='" . htmlspecialchars($payLink) . "'
               style='background:#198754;color:#fff;text-decoration:none;padding:14px 32px;border-radius:28px;font-weight:700;display:inline-block;font-size:15px;'>
               💳 Pay Now
            </a>
        </p>

        <p style='color:#64748b;font-size:13px;'>
            If the button above doesn't work, copy and paste this link into your browser:<br>
            <a href='" . htmlspecialchars($payLink) . "' style='color:#2563eb;word-break:break-all;'>" . htmlspecialchars($payLink) . "</a>
        </p>

        <p style='color:#64748b;font-size:13px;margin-top:20px;'>
            ⏱️ Please complete your payment within <strong>30 days</strong> to avoid delays in processing your permit.
        </p>

        <p style='color:#64748b;font-size:13px;'>
            If you have questions, you may visit the Municipal Treasurer's Office during business hours.
        </p>
    ";
    return _emailShell('#f59e0b', 'Fee Assessment Ready', $body);
}

/* ================================================================
 *  DATA HELPERS
 *  ================================================================ */

/**
 * Fetch email + name of all active accounts of given user_type(s).
 * Returns array of ['email' => ..., 'name' => ...]
 */
function getStaffRecipients(mysqli $conn, array $types): array
{
    if (empty($types)) return [];

    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $sql = "SELECT email, CONCAT(firstname,' ',lastname) AS name
              FROM accounts
             WHERE user_type IN ($placeholders)
               AND verified = 1
               AND email IS NOT NULL AND email <> ''";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    $types_str = str_repeat('i', count($types));
    $stmt->bind_param($types_str, ...$types);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    $stmt->close();
    return $out;
}

/**
 * Get applicant name, email, and business name for a given reference_no.
 * Returns null if not found.
 */
function getApplicantByRef(mysqli $conn, string $ref): ?array
{
    $stmt = $conn->prepare("
        SELECT a.email,
               CONCAT(a.firstname,' ',a.lastname) AS applicant_name,
               bi.business_name
          FROM business_applications ba
          JOIN accounts a                 ON a.id = ba.user_id
          LEFT JOIN business_info_data bi ON bi.application_id = ba.id
         WHERE ba.reference_no = ?
         LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}