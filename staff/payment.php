<?php
session_start();

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_type'] !== 3) {
    $_SESSION['error'] = 'Access denied. Only payment staff can access this page.';
    header('Location: ../index.php');
    exit;
}

require_once '../config/connect.php';
require_once __DIR__ . '/../mail_helper.php';

// ============================================================
// NOTIFICATIONS (staff bell)
// ============================================================
$staff_unreadCount   = 0;
$staff_notifications = [];

$notifHelperPath = __DIR__ . '/../notifications_helper.php';
if (file_exists($notifHelperPath)) {
    require_once $notifHelperPath;
    if (function_exists('countUnreadNotifications')) {
        $staff_unreadCount   = countUnreadNotifications($conn, (int)$_SESSION['user_id']);
        $staff_notifications = getUnreadNotifications($conn, (int)$_SESSION['user_id'], 5);
    }
}

$ref = $_GET['ref'] ?? '';
if (empty($ref)) {
    $_SESSION['error'] = 'No reference number provided.';
    header('Location: ../index.php');
    exit;
}

$staff_id   = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'LGU Staff';
$user_type  = (int)$_SESSION['user_type'];
$role_label = 'Treasury Staff';

$headerLogo    = 'treasury-logo.png';
$headerLogoAlt = 'Treasury Office Logo';

// ============================================================
// FETCH APPLICATION
// ============================================================
$stmt = $conn->prepare("
    SELECT
        ba.id AS application_id,
        ba.reference_no,
        ba.status,
        ba.submitted_at,
        ba.user_id,
        ba.renewed_from_id,
        bi.business_name,
        at.application_type,
        CONCAT(u.firstname, ' ', u.lastname) AS owner_name,
        u.email AS owner_email
    FROM business_applications ba
    LEFT JOIN business_info_data bi    ON ba.id = bi.application_id
    LEFT JOIN application_type_data at ON ba.id = at.application_id
    LEFT JOIN accounts u               ON ba.user_id = u.id
    WHERE ba.reference_no = ?
");
$stmt->bind_param("s", $ref);
$stmt->execute();
$result = $stmt->get_result();
$app = $result->fetch_assoc();
$stmt->close();

if (!$app) {
    $_SESSION['error'] = 'Application not found.';
    header('Location: ../index.php');
    exit;
}

if (strtolower($app['status']) !== 'pending_payment') {
    $_SESSION['error'] = 'This application is not ready for payment.';
    header('Location: ../index.php');
    exit;
}

$application_id = (int)$app['application_id'];
$applicant_id   = (int)$app['user_id'];

// ============================================================
// NEW vs RENEWAL
// ============================================================
$appTypeRaw = strtolower(trim($app['application_type'] ?? 'new'));
$isRenewal  = ($appTypeRaw === 'renewal') || !empty($app['renewed_from_id']);
$typeLabel  = $isRenewal ? 'Renewal' : 'New';

// ============================================================
// FEE FIELDS
// ============================================================
$feeFieldsNew = [
    'mayors_permit_fee'       => "Mayor's Permit",
    'gross_sales_tax'         => 'Gross Sales Tax Computed',
    'permit_on_calling'       => 'Permit on Calling',
    'health_certificate'      => 'Health Certificate',
    'sanitary_inspection_fee' => 'Sanitary Inspection Fee',
    'garbage_charges'         => 'Sanitary Garbage Fee',
    'weight_measure'          => 'Weight & Measure',
    'misc_fee'                => 'Misc. Fee',
    'surcharge_fee'           => 'Surcharge Fee',
    'interest_fee'            => 'Interest Fee',
    'late_payment'            => 'Late Payment',
];

$feeFieldsRenewal = [
    'mayors_permit_fee'       => "Mayor's Permit",
    'gross_sales_tax'         => 'Gross Sales Tax Computed',
    'permit_on_calling'       => 'Permit on Calling',
    'health_certificate'      => 'Health Certificate',
    'sanitary_inspection_fee' => 'Sanitary Inspection Fee',
    'garbage_charges'         => 'Sanitary Garbage Fee',
    'weight_measure'          => 'Weight & Measure',
    'misc_fee'                => 'Misc. Fee',
    'surcharge_fee'           => 'Surcharge Fee',
    'interest_fee'            => 'Interest Fee',
    'late_payment'            => 'Late Payment ' . ((int)date('Y') - 1),
    'penalty_lost_permit'     => 'Penalty (Lost Business Permit)',
];

$feeFields = $isRenewal ? $feeFieldsRenewal : $feeFieldsNew;

// Only these fields are editable, and only on RENEWALS.
$editableFields = $isRenewal
    ? ['gross_sales_tax', 'late_payment', 'penalty_lost_permit']
    : [];

// ============================================================
// DEFAULTS
// ============================================================
$defaultsNew = [
    'mayors_permit_fee'       => 500.00,
    'gross_sales_tax'         => 0.00,
    'permit_on_calling'       => 220.00,
    'health_certificate'      => 200.00,
    'sanitary_inspection_fee' => 250.00,
    'garbage_charges'         => 250.00,
    'weight_measure'          => 0.00,
    'misc_fee'                => 200.00,
    'surcharge_fee'           => 0.00,
    'interest_fee'            => 0.00,
    'late_payment'            => 0.00,
];

$defaultsRenewal = [
    'mayors_permit_fee'       => 400.00,
    'gross_sales_tax'         => 2250.00,
    'permit_on_calling'       => 250.00,
    'health_certificate'      => 250.00,
    'sanitary_inspection_fee' => 100.00,
    'garbage_charges'         => 200.00,
    'weight_measure'          => 0.00,
    'misc_fee'                => 1925.00,
    'surcharge_fee'           => 0.00,
    'interest_fee'            => 0.00,
    'late_payment'            => 0.00,
    'penalty_lost_permit'     => 0.00,
];

$defaults = $isRenewal ? $defaultsRenewal : $defaultsNew;

// Fetch existing assessment
$assessment = null;
$stmt = $conn->prepare("SELECT * FROM fee_assessments WHERE application_id = ?");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) $assessment = $row;
$stmt->close();

$formValues = [];
foreach ($feeFields as $key => $label) {
    if ($assessment && isset($assessment[$key])) {
        $formValues[$key] = (float)$assessment[$key];
    } else {
        $formValues[$key] = (float)($defaults[$key] ?? 0);
    }
}

$totalAmount = $assessment['total_amount'] ?? array_sum($formValues);

// ============================================================
// HANDLE POST
// ============================================================
$success           = false;
$error             = '';
$sentAmount        = 0;
$applicantNotified = false;
$emailSent         = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assessment'])) {

    $postedValues = [];
    foreach ($feeFields as $key => $label) {
        if (in_array($key, $editableFields, true)) {
            $postedValues[$key] = floatval($_POST[$key] ?? 0);
        } else {
            $postedValues[$key] = (float)($defaults[$key] ?? 0);
        }
    }

    $gross_sales_tax         = $postedValues['gross_sales_tax']         ?? 0;
    $mayors_permit_fee       = $postedValues['mayors_permit_fee']       ?? 0;
    $garbage_charges         = $postedValues['garbage_charges']         ?? 0;
    $sanitary_inspection_fee = $postedValues['sanitary_inspection_fee'] ?? 0;
    $permit_on_calling       = $postedValues['permit_on_calling']       ?? 0;
    $health_certificate      = $postedValues['health_certificate']      ?? 0;
    $weight_measure          = $postedValues['weight_measure']          ?? 0;
    $misc_fee                = $postedValues['misc_fee']                ?? 0;
    $surcharge_fee           = $postedValues['surcharge_fee']           ?? 0;
    $interest_fee            = $postedValues['interest_fee']            ?? 0;
    $late_payment            = $postedValues['late_payment']            ?? 0;
    $penalty_lost_permit     = $postedValues['penalty_lost_permit']     ?? 0;

    $buildingFee   = 0;
    $electricalFee = 0;
    $others        = 0;
    $fireSafety    = 0;

    $assessed_by = (int)$_SESSION['user_id'];
    $totalAmount = array_sum($postedValues);
    $sentAmount  = $totalAmount;

    $conn->begin_transaction();
    try {
        // ------------------------------------------------------------
        // 1. Replace fee_assessments row
        // ------------------------------------------------------------
        $delStmt = $conn->prepare("DELETE FROM fee_assessments WHERE application_id = ?");
        $delStmt->bind_param("i", $application_id);
        $delStmt->execute();
        $delStmt->close();

        $stmt = $conn->prepare("
            INSERT INTO fee_assessments
            (application_id,
             gross_sales_tax, mayors_permit_fee, garbage_charges, sanitary_inspection_fee,
             building_inspection_fee, electrical_inspection_fee,
             permit_on_calling, health_certificate, weight_measure,
             misc_fee, surcharge_fee, interest_fee,
             late_payment, penalty_lost_permit,
             others, fire_safety_fee_percent, total_amount,
             assessed_by, assessed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $typeStr = 'i' . str_repeat('d', 17) . 'i';

        $stmt->bind_param(
            $typeStr,
            $application_id,
            $gross_sales_tax,
            $mayors_permit_fee,
            $garbage_charges,
            $sanitary_inspection_fee,
            $buildingFee,
            $electricalFee,
            $permit_on_calling,
            $health_certificate,
            $weight_measure,
            $misc_fee,
            $surcharge_fee,
            $interest_fee,
            $late_payment,
            $penalty_lost_permit,
            $others,
            $fireSafety,
            $totalAmount,
            $assessed_by
        );

        if (!$stmt->execute()) {
            throw new Exception('Insert failed: ' . $stmt->error);
        }
        $stmt->close();

        // ------------------------------------------------------------
        // 2. UPSERT payments record
        // ------------------------------------------------------------
        $feeBreakdownArr = [];
        foreach ($feeFields as $key => $label) {
            $feeBreakdownArr[] = [
                'key'    => $key,
                'label'  => $label,
                'amount' => (float)($postedValues[$key] ?? 0),
            ];
        }
        $feeBreakdownJson = json_encode(
            $feeBreakdownArr,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $checkPay = $conn->prepare("SELECT id FROM payments WHERE application_id = ? LIMIT 1");
        $checkPay->bind_param('i', $application_id);
        $checkPay->execute();
        $payRow = $checkPay->get_result()->fetch_assoc();
        $checkPay->close();

        if ($payRow) {
            $updPay = $conn->prepare("
                UPDATE payments
                   SET amount_due    = ?,
                       user_id       = ?,
                       reference_no  = ?,
                       status        = 'pending',
                       fee_breakdown = ?
                 WHERE application_id = ?
            ");
            $updPay->bind_param(
                'dissi',
                $totalAmount,
                $applicant_id,
                $ref,
                $feeBreakdownJson,
                $application_id
            );
            if (!$updPay->execute()) {
                throw new Exception('Failed to update payments: ' . $updPay->error);
            }
            $updPay->close();
        } else {
            $insPay = $conn->prepare("
                INSERT INTO payments
                    (application_id, user_id, reference_no, amount_due,
                     status, fee_breakdown)
                VALUES (?, ?, ?, ?, 'pending', ?)
            ");
            $insPay->bind_param(
                'iisds',
                $application_id,
                $applicant_id,
                $ref,
                $totalAmount,
                $feeBreakdownJson
            );
            if (!$insPay->execute()) {
                throw new Exception('Failed to insert payments: ' . $insPay->error);
            }
            $insPay->close();
        }

        // ------------------------------------------------------------
        // 3. Commit
        // ------------------------------------------------------------
        $conn->commit();
        $success = true;

        // ------------------------------------------------------------
        // 4. Reload assessment into form values
        // ------------------------------------------------------------
        $stmt = $conn->prepare("SELECT * FROM fee_assessments WHERE application_id = ?");
        $stmt->bind_param("i", $application_id);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        foreach ($feeFields as $key => $label) {
            $formValues[$key] = (float)($assessment[$key] ?? 0);
        }
        $totalAmount = $assessment['total_amount'] ?? 0;

        // ------------------------------------------------------------
        // 5. Notify the APPLICANT (in-app bell)
        // ------------------------------------------------------------
        $applicantNotified = false;
        $bizName     = $app['business_name'] ?: 'your business';
        $fmtTotal    = '₱' . number_format($totalAmount, 2);
        $notifyTitle = 'Fee Assessment Ready — Total ' . $fmtTotal;

        $notifyMsg =
            "The Treasury Office has assessed the fees for \"{$bizName}\" " .
            "(Ref: {$app['reference_no']}).\n\n" .
            "Total Amount Due: {$fmtTotal}\n\n" .
            "Please proceed to PayPal payment to continue your application.";

        $payLink = 'payment.php?ref=' . urlencode($app['reference_no']);

        if ($applicant_id > 0) {
            if (function_exists('createNotification')) {
                $ok = @createNotification($conn, $applicant_id, $notifyTitle, $notifyMsg, $payLink, 'warning');
                $applicantNotified = (bool)$ok;
            }

            if (!$applicantNotified) {
                $notifyStmt = @$conn->prepare("
                    INSERT INTO notifications (user_id, type, title, message, link)
                    VALUES (?, 'warning', ?, ?, ?)
                ");
                if ($notifyStmt) {
                    $notifyStmt->bind_param("isss", $applicant_id, $notifyTitle, $notifyMsg, $payLink);
                    if ($notifyStmt->execute()) $applicantNotified = true;
                    $notifyStmt->close();
                }
            }
        }

        // ------------------------------------------------------------
        // 5b. 📧 EMAIL the applicant about the assessment
        // ------------------------------------------------------------
        $emailSent = false;

        if (!empty($app['owner_email']) && filter_var($app['owner_email'], FILTER_VALIDATE_EMAIL)) {

            // Use the fixed production domain from mail_helper.php
            $absolutePayLink = SITE_URL . '/payment.php?ref=' . urlencode($app['reference_no']);

            $html = feeAssessmentEmail(
                $app['owner_name']    ?: 'Applicant',
                $bizName,
                $app['reference_no'],
                number_format((float)$totalAmount, 2),
                $absolutePayLink
            );

            $emailSent = sendMail(
                $app['owner_email'],
                $app['owner_name'] ?: 'Applicant',
                'Fee Assessment Ready — ₱' . number_format((float)$totalAmount, 2)
                    . ' (Ref: ' . $app['reference_no'] . ')',
                $html,
                "The Treasury Office has assessed your fees for \"{$bizName}\" (Ref: {$app['reference_no']}). "
                . "Total amount due: PHP " . number_format((float)$totalAmount, 2) . ". "
                . "Please pay via PayPal: {$absolutePayLink}"
            );
        }

        // ------------------------------------------------------------
        // 6. Inform other treasury staff (in-app bell)
        // ------------------------------------------------------------
        if (function_exists('notifyByUserType')) {
            @notifyByUserType(
                $conn,
                [3],
                'Assessment Released',
                "Fee assessment ({$fmtTotal}) for \"{$bizName}\" (Ref: {$app['reference_no']}) has been sent to the applicant.",
                null,
                'info'
            );
        }

    } catch (Throwable $e) {
        $conn->rollback();
        $error = 'Failed to save assessment: ' . $e->getMessage();
        error_log('[staff/payment.php] ' . $e->getMessage());
    }
}

// Safe truncate helper
function safeTruncate($str, $len = 80) {
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($str, 0, $len, '…');
    }
    return strlen($str) > $len ? substr($str, 0, $len - 1) . '…' : $str;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Assessment | Payment | Placer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy-dark:   #0f1f3d;
            --navy-hover:  #253d68;
            --blue-accent: #2563eb;
            --blue-hover:  #1d4ed8;
            --content-bg:  #f1f5f9;
            --border:      #e2e8f0;
            --text-dark:   #1e293b;
            --text-muted:  #64748b;
            --green:       #10b981;
            --yellow:      #f59e0b;
            --red:         #ef4444;
            --purple:      #8b5cf6;
        }
        * { font-family: 'Inter', sans-serif; box-sizing: border-box; }
        html, body { width: 100%; max-width: 100vw; }
        body { background: var(--content-bg); color: var(--text-dark); overflow-x: hidden; margin: 0; }

        .gov-header {
            background: var(--navy-dark); color: white;
            padding: 0.75rem 1.25rem;
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1030;
            gap: 8px;
        }
        .brand-wrap { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1; }
        .brand-logo { height: 36px; width: 36px; border-radius: 50%; background: white; padding: 2px; object-fit: contain; flex-shrink: 0; }
        .brand-text { min-width: 0; overflow: hidden; }
        .brand-text h5 { font-size: 0.9rem; font-weight: 700; margin: 0; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .brand-text small { font-size: 0.65rem; color: #94a3b8; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .header-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

        .user-greeting {
            background: rgba(255,255,255,0.08); border-radius: 40px;
            padding: 0.35rem 0.85rem; font-weight: 500; font-size: 0.8rem;
            cursor: pointer; color: #fff !important;
            display: inline-flex; align-items: center; gap: 5px;
            white-space: nowrap; text-decoration: none;
        }
        .user-greeting:hover { background: rgba(255,255,255,0.15); color: #fff; }

        .staff-notif-bell {
            position: relative; width: 36px; height: 36px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; color: #fff; text-decoration: none;
            flex-shrink: 0;
        }
        .staff-notif-bell:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .staff-notif-bell i { font-size: 1.1rem; }
        .staff-notif-badge {
            position: absolute; top: 0; right: 0;
            min-width: 16px; height: 16px; padding: 0 4px;
            background: #dc3545; color: #fff;
            font-size: 0.6rem; font-weight: 700;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid var(--navy-dark);
            line-height: 1;
        }

        .payment-container { max-width: 1400px; margin: 0 auto; padding: 1.25rem 1rem 2rem; }
        .page-title { font-size: 1.35rem; font-weight: 700; margin: 0; }
        .page-subtitle { font-size: 0.85rem; color: var(--text-muted); margin: 3px 0 0; }

        .back-link {
            display: inline-flex; align-items: center; gap: 4px;
            color: var(--navy-dark); text-decoration: none;
            font-weight: 600; font-size: 0.85rem;
            padding: 0.4rem 0.85rem;
            border-radius: 8px;
            background: #fff; border: 1px solid var(--border);
            transition: all 0.15s;
        }
        .back-link:hover { background: #f8fafc; border-color: #cbd5e1; color: var(--navy-dark); }
        .back-link i { font-size: 1.1rem; }

        .card { background: #fff; }

        .type-badge {
            display: inline-flex; align-items: center; gap: 4px;
            background: #dbeafe; color: #1e40af;
            padding: 0.3rem 0.75rem; border-radius: 20px;
            font-size: 0.72rem; font-weight: 700;
            line-height: 1;
        }
        .type-badge i { font-size: 0.75rem; }
        .status-badge {
            display: inline-flex; align-items: center; gap: 4px;
            background: #fef3c7; color: #92400e;
            padding: 0.3rem 0.75rem; border-radius: 20px;
            font-size: 0.72rem; font-weight: 700;
            line-height: 1;
        }
        .status-badge i { font-size: 0.75rem; }

        .fee-table { width: 100%; border-collapse: collapse; background: #fff; }
        .fee-table thead th {
            background: var(--navy-dark); color: #fff;
            font-size: 0.75rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.04em;
            padding: 0.75rem 0.85rem;
            border-bottom: 2px solid var(--navy-dark);
        }
        .fee-table tbody td {
            padding: 0.55rem 0.85rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.85rem;
        }
        .fee-table tbody tr:last-child td { border-bottom: none; }
        .fee-table tbody tr:hover { background: #f8fafc; }
        .fee-table .form-control {
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.35rem 0.6rem;
            font-size: 0.82rem;
            text-align: right;
        }
        .fee-table .form-control:focus {
            border-color: var(--blue-accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }
        .fee-table .form-control.editable-field {
            background: #fffbeb;
            border-color: #fbbf24;
        }
        .fee-table .form-control.editable-field:focus {
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245,158,11,0.15);
        }
        .fee-table .form-control[readonly] {
            background: #f1f5f9;
            color: #475569;
            border-color: #e2e8f0;
            cursor: not-allowed;
        }
        .fee-table .form-control[readonly]:focus {
            box-shadow: none;
            border-color: #e2e8f0;
        }
        .fee-table .total-col {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            color: var(--navy-dark);
        }
        .fee-table tr.total-row { background: #ecfdf5; }
        .fee-table tr.total-row td {
            padding: 0.85rem;
            font-size: 0.95rem;
            border-top: 2px solid var(--green);
            border-bottom: none;
        }
        .fee-table tr.total-row #grandTotal {
            font-size: 1.15rem;
            font-weight: 800;
            color: #065f46;
        }

        .list-unstyled i.text-success { margin-right: 4px; }

        @media (max-width: 992px) {
            .payment-container { padding: 1rem; }
            .page-title { font-size: 1.2rem; }
        }
        @media (max-width: 768px) {
            .gov-header { padding: 0.6rem 0.9rem; }
            .brand-logo { height: 32px; width: 32px; }
            .brand-text h5 { font-size: 0.82rem; }
            .brand-text small { font-size: 0.6rem; }
            .user-greeting { padding: 0.3rem 0.7rem; font-size: 0.75rem; }
            .user-greeting span { display: none; }
            .staff-notif-bell { width: 32px; height: 32px; }
            .staff-notif-bell i { font-size: 1rem; }
            .fee-table thead th,
            .fee-table tbody td { padding: 0.5rem 0.55rem; font-size: 0.78rem; }
            .fee-table .form-control { font-size: 0.78rem; padding: 0.3rem 0.5rem; }
        }
        @media (max-width: 480px) {
            .payment-container { padding: 0.75rem; }
            .page-title { font-size: 1.1rem; }
            .fee-table thead th,
            .fee-table tbody td { padding: 0.4rem 0.45rem; font-size: 0.72rem; }
            .fee-table tr.total-row td { padding: 0.65rem; font-size: 0.85rem; }
            .fee-table tr.total-row #grandTotal { font-size: 1rem; }
        }

        .table-responsive { -webkit-overflow-scrolling: touch; }
        .table-responsive::-webkit-scrollbar { height: 6px; }
        .table-responsive::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    </style>
</head>
<body>

<div class="payment-container">

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <h6 class="fw-bold mb-1"><i class="bi bi-check-circle-fill"></i> Assessment sent to applicant</h6>
            <div class="small">
                Total amount due:
                <strong>₱<?php echo number_format($sentAmount, 2); ?></strong>
                &nbsp;•&nbsp;
                Applicant:
                <strong><?php echo htmlspecialchars($app['owner_name'] ?: 'N/A'); ?></strong>
                &nbsp;•&nbsp;
                Reference: <strong><?php echo htmlspecialchars($app['reference_no']); ?></strong>
                <br>

                <?php if ($applicantNotified): ?>
                    <i class="bi bi-bell-fill text-warning"></i>
                    The applicant has been notified and can now pay via
                    <i class="bi bi-paypal text-primary"></i> <strong>PayPal</strong> from their dashboard.
                <?php else: ?>
                    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                    Assessment saved, but the in-app notification could not be delivered.
                    Please inform the applicant manually to visit
                    <code>payment.php?ref=<?php echo htmlspecialchars($app['reference_no']); ?></code>.
                <?php endif; ?>

                <?php if (!empty($emailSent)): ?>
                    <br><i class="bi bi-envelope-check-fill text-success"></i>
                    Email notification sent to
                    <strong><?php echo htmlspecialchars($app['owner_email']); ?></strong>.
                <?php elseif (!empty($app['owner_email'])): ?>
                    <br><i class="bi bi-envelope-x-fill text-danger"></i>
                    Email could not be sent (check SMTP settings / server logs).
                <?php else: ?>
                    <br><i class="bi bi-envelope-slash-fill text-secondary"></i>
                    No email on file for this applicant.
                <?php endif; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
                    <div>
                        <h4 class="mb-0 fw-bold" style="font-size:1.05rem;">2. ASSESSMENT OF APPLICABLE FEES</h4>
                        <p class="small mb-0 text-muted">Reference: <?php echo htmlspecialchars($ref); ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="type-badge"><i class="bi bi-tag"></i> <?php echo $typeLabel; ?> Application</span>
                        <span class="status-badge"><i class="bi bi-hourglass-split"></i> <?php echo ucfirst($app['status']); ?></span>
                    </div>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small">
                        Fixed values are pre-filled based on the application type.
                        <?php if ($isRenewal): ?>
                            <br><i class="bi bi-info-circle text-warning"></i>
                            <strong>Renewal:</strong> the highlighted fields
                            (<em>Gross Sales Tax Computed</em>, <em>Late Payment <?php echo (int)date('Y') - 1; ?></em>, <em>Penalty (Lost Business Permit)</em>)
                            are fully editable — enter any value.
                            All other fields are locked to the standard schedule.
                        <?php else: ?>
                            <br><i class="bi bi-lock-fill text-secondary"></i>
                            <strong>New application:</strong> standard fixed fee structure.
                            Amounts <strong>cannot be edited</strong>.
                        <?php endif; ?>
                    </p>

                    <form method="POST" id="assessmentForm" autocomplete="off">
                        <div class="table-responsive">
                            <table class="fee-table w-100" id="feeTable">
                                <thead>
                                    <tr>
                                        <th style="width:55%;">Particular</th>
                                        <th style="width:25%; text-align:right;">Amount</th>
                                        <th style="width:20%; text-align:right;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($feeFields as $key => $label):
                                        $isEditable = in_array($key, $editableFields, true);
                                        $extraClass = $isEditable ? ' editable-field' : '';
                                        $readonly   = $isEditable ? '' : ' readonly tabindex="-1"';
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($label); ?></td>
                                            <td>
                                                <input type="number" step="0.01" min="0"
                                                       class="form-control form-control-sm amount-input<?php echo $extraClass; ?>"
                                                       name="<?php echo htmlspecialchars($key); ?>"
                                                       value="<?php echo number_format($formValues[$key], 2, '.', ''); ?>"
                                                       <?php echo $readonly; ?>>
                                            </td>
                                            <td class="total-col" id="total-<?php echo htmlspecialchars($key); ?>">
                                                ₱<?php echo number_format($formValues[$key], 2); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <tr class="total-row">
                                        <td><strong>TOTAL</strong></td>
                                        <td></td>
                                        <td class="text-end" id="grandTotal">
                                            ₱<?php echo number_format($totalAmount, 2); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="border rounded p-3">
                                        <p class="mb-1"><strong>Assessed by:</strong> CTO</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2 justify-content-end">
                            <button type="submit" name="save_assessment" class="btn btn-success rounded-pill px-4"
                                    onclick="return confirm('Send this assessment to the applicant? They will be notified by email and in-app with the total amount and a PayPal payment link.');">
                                <i class="bi bi-send-fill"></i> Send Assessment Fee
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4 small">
                    <strong>Application summary</strong><br>
                    Reference: <?php echo htmlspecialchars($app['reference_no']); ?><br>
                    Business Name: <?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?><br>
                    Applicant: <?php echo htmlspecialchars($app['owner_name'] ?? 'N/A'); ?><br>
                    Email: <?php echo htmlspecialchars($app['owner_email'] ?? '—'); ?><br>
                    Type: <?php echo htmlspecialchars($app['application_type'] ?? 'New'); ?><br>
                    Tax year: <?php echo date('Y'); ?><br>
                    Date submitted: <?php echo $app['submitted_at'] ? date('M d, Y', strtotime($app['submitted_at'])) : '—'; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 sticky-top" style="top: 1rem;">
                <div class="card-body p-4">
                    <h5 class="fw-bold">Payment Methods</h5>
                    <p class="small text-muted">After sending the assessment, the applicant will be notified by email and can pay via PayPal.</p>
                    <ul class="list-unstyled small">
                        <li><i class="bi bi-paypal text-primary"></i> <strong>PayPal</strong> (online — primary)</li>
                        <li><i class="bi bi-check-circle-fill text-success"></i> Over-the-counter at Municipal Treasurer</li>
                        <li><i class="bi bi-check-circle-fill text-success"></i> Bank deposit (Land Bank: Placer LGU - 1234-5678-90)</li>
                    </ul>
                    <hr>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle"></i> The <strong>TOTAL</strong> is the amount the applicant needs to pay.
                        It will be sent to their dashboard, notification bell, and <strong>Gmail</strong>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const amountInputs = document.querySelectorAll('.amount-input');
    const grandTotalEl = document.getElementById('grandTotal');

    function calculateAll() {
        let grandTotal = 0;
        amountInputs.forEach((input) => {
            const name   = input.name;
            const amount = parseFloat(input.value) || 0;
            const totalCell = document.getElementById('total-' + name);
            if (totalCell) totalCell.textContent = '₱' + amount.toFixed(2);
            grandTotal += amount;
        });
        if (grandTotalEl) grandTotalEl.textContent = '₱' + grandTotal.toFixed(2);
    }

    amountInputs.forEach(input => input.addEventListener('input', calculateAll));
    calculateAll();
</script>
</body>
</html>