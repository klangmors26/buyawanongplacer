<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'config/connect.php';
require_once 'includes/functions.php';
require_once 'notifications_helper.php';

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

$user_id = (int)$_SESSION['user_id'];

// Get application ID
$app_id = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
if (!$app_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing application ID']);
    exit;
}

// Verify ownership + rejected state
$check = $conn->prepare("SELECT id, status, reference_no FROM business_applications WHERE id = ? AND user_id = ? AND status = 'rejected'");
$check->bind_param("ii", $app_id, $user_id);
$check->execute();
$result = $check->get_result();
if ($result->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Application not found or not in rejected state.']);
    exit;
}
$appRow = $result->fetch_assoc();
$refDisplay = $appRow['reference_no'] ?? 'N/A';
$check->close();

// Fetch business name for notifications
$bizName = 'your business';
$biz = $conn->prepare("SELECT business_name FROM business_info_data WHERE application_id = ? LIMIT 1");
if ($biz) {
    $biz->bind_param("i", $app_id);
    $biz->execute();
    $bizRow = $biz->get_result()->fetch_assoc();
    if ($bizRow && !empty($bizRow['business_name'])) $bizName = $bizRow['business_name'];
    $biz->close();
}

// Fetch missing documents list
$rej = $conn->prepare("SELECT missing_documents FROM application_rejections WHERE application_id = ?");
$rej->bind_param("i", $app_id);
$rej->execute();
$rej_result = $rej->get_result();
$missing = [];
if ($row = $rej_result->fetch_assoc()) {
    $missing = json_decode($row['missing_documents'], true);
    if (!is_array($missing)) $missing = [];
}
$rej->close();

if (empty($missing)) $missing = ['general'];

$uploaded = 0;
$errors = [];

// Process each uploaded file
foreach ($_FILES as $key => $file) {
    if (strpos($key, 'doc_') === 0) {
        $doc_type = substr($key, 4);

        if (!empty($missing) && !in_array($doc_type, $missing) && $doc_type !== 'general') {
            continue;
        }

        if ($file['error'] === UPLOAD_ERR_OK && $file['size'] > 0) {
            $result = saveUploadedFile($file, $user_id, $app_id, $doc_type);
            if (isset($result['success']) && $result['success']) {
                $uploaded++;
                $stmt = $conn->prepare("INSERT INTO document_uploads (application_id, document_type, file_path, original_name, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("isss", $app_id, $doc_type, $result['path'], $result['original_name']);
                $stmt->execute();
                $stmt->close();
            } else {
                $errors[] = "Failed to upload $doc_type: " . ($result['error'] ?? 'unknown error');
            }
        }
    }
}

if ($uploaded === 0 && empty($errors)) {
    echo json_encode(['success' => false, 'message' => 'No files were uploaded. Please select at least one file.']);
    exit;
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => 'Some files failed: ' . implode('; ', $errors)]);
    exit;
}

// ============================================================
// ✅ NEW: Detect whether the applicant already paid
// If they paid before the rejection, they should NOT have to
// pay again — send them straight back to 'payment_confirmed'
// so BPLO just re-reviews the new documents.
// ============================================================
$hasPayment = false;
$payCheck = $conn->prepare("
    SELECT id FROM paypal_transactions
    WHERE application_id = ?
      AND payment_status IN ('completed','paid')
    LIMIT 1
");
if ($payCheck) {
    $payCheck->bind_param("i", $app_id);
    $payCheck->execute();
    $hasPayment = $payCheck->get_result()->num_rows > 0;
    $payCheck->close();
}

// Decide the target status
$newStatus = $hasPayment ? 'payment_confirmed' : 'submitted';

$conn->begin_transaction();
try {
    // 1) Update application status
    $update = $conn->prepare("
        UPDATE business_applications
        SET status = ?, resubmitted_at = NOW(), updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $update->bind_param("sii", $newStatus, $app_id, $user_id);
    if (!$update->execute()) {
        throw new Exception('Failed to update application status: ' . $update->error);
    }
    $update->close();

    // 2) Clear stale rejection record
    $del = $conn->prepare("DELETE FROM application_rejections WHERE application_id = ?");
    $del->bind_param("i", $app_id);
    $del->execute();
    $del->close();

    // 3) ✅ NEW: Restore appointment to 'confirmed'
    // The rejection set it to 'pending'; now the applicant has
    // re-uploaded, so the appointment should be active again.
    $apptUpd = $conn->prepare("
        UPDATE appointments
        SET status = 'confirmed', updated_at = NOW()
        WHERE application_id = ? AND status = 'pending'
    ");
    if ($apptUpd) {
        $apptUpd->bind_param("i", $app_id);
        $apptUpd->execute();
        $apptUpd->close();
    }

    // 4) Log status change
    $chk = $conn->query("SHOW TABLES LIKE 'status_history'");
    if ($chk && $chk->num_rows > 0) {
        $noteText = $hasPayment
            ? 'Applicant re-submitted documents (payment already confirmed — no re-payment required)'
            : 'Applicant re-submitted missing documents';
        $log = $conn->prepare("
            INSERT INTO status_history
                (application_id, old_status, new_status, changed_by, notes, changed_at)
            VALUES (?, 'rejected', ?, ?, ?, NOW())
        ");
        $log->bind_param("iis", $app_id, $newStatus, $user_id, $noteText);
        $log->execute();
        $log->close();
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Resubmit failed for app $app_id: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

// Notify BPLO staff (message differs depending on whether they need to re-assess fees)
$notifMsg = $hasPayment
    ? "\"$bizName\" (Ref: $refDisplay) re-uploaded missing documents. Payment was already confirmed — please re-review the documents."
    : "\"$bizName\" (Ref: $refDisplay) has re-submitted the missing documents.";

$staffRes = $conn->query("SELECT id FROM accounts WHERE user_type = 4");
if ($staffRes) {
    while ($s = $staffRes->fetch_assoc()) {
        insertNotif(
            $conn, (int)$s['id'],
            'permit',
            'Application Resubmitted — Ready for Re-review',
            $notifMsg,
            'dashboard_bplo.php'
        );
    }
}

// Notify applicant (different message depending on their next step)
$applicantMsg = $hasPayment
    ? "Your application for \"$bizName\" (Ref: $refDisplay) has been re-submitted. Since you already paid, no further payment is needed — BPLO will re-review your documents."
    : "Your application for \"$bizName\" (Ref: $refDisplay) has been set back to Submitted. Please wait for BPLO to re-assess the fees.";

insertNotif(
    $conn, $user_id,
    'info',
    'Application Resubmitted',
    $applicantMsg,
    'dashboard.php'
);

echo json_encode([
    'success'        => true,
    'message'        => $hasPayment
        ? 'Documents re-uploaded successfully. No additional payment needed — BPLO will re-review your documents.'
        : 'Documents re-uploaded successfully. Your application is now back in Submitted status and will be re-assessed by BPLO.',
    'new_status'     => $newStatus,
    'already_paid'   => $hasPayment,
    'application_id' => $app_id
]);

$conn->close();
?>