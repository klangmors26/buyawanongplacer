<?php
// ───── TEMP DEBUG (remove these 3 lines once it works) ─────
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ───────────────────────────────────────────────────────────

session_start();
require_once 'config/connect.php';

// Embedded mode (opened inside the dashboard modal iframe)
$isEmbedded = isset($_GET['embed']) && $_GET['embed'] == '1';

// ── Auth check (defensive) ────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Default to 1 (business owner) if user_type was never set in the session.
// This lets existing user_type=1 accounts keep working even before they re-login.
$user_type = isset($_SESSION['user_type']) ? (int)$_SESSION['user_type'] : 1;

if (!in_array($user_type, [1, 3, 4], true)) {
    // Unknown role → safest default is business owner.
    $user_type = 1;
}

$app_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$dashboardUrl = 'dashboard.php';
if ($user_type == 3) $dashboardUrl = 'dashboard_treasury.php';
elseif ($user_type == 4) $dashboardUrl = 'dashboard_bplo.php';

// ============================================================
// Support lookup by reference number (?ref=...)
// ============================================================
$ref_param = isset($_GET['ref']) ? trim($_GET['ref']) : '';
if ($ref_param !== '' && (strpos($ref_param, 'ref=') !== false || preg_match('#^https?://#i', $ref_param))) {
    if (preg_match('/[?&]ref=([^&]+)/', $ref_param, $m)) $ref_param = urldecode($m[1]);
}

if (!$app_id && $ref_param !== '') {
    if ($user_type == 1) {
        $stmt = $conn->prepare("SELECT id FROM business_applications WHERE reference_no = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param('si', $ref_param, $user_id);
    } else {
        $stmt = $conn->prepare("SELECT id FROM business_applications WHERE reference_no = ? LIMIT 1");
        $stmt->bind_param('s', $ref_param);
    }
    $stmt->execute();
    $refRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($refRow) $app_id = (int)$refRow['id'];
}

if (!$app_id) die('Invalid application ID or reference number.');

// ─── Helpers ────────────────────────────────────────────────
function getExpiryStatus($expiry_date) {
    if (empty($expiry_date)) return ['label'=>'N/A','class'=>'text-muted'];
    $now = new DateTime(); $expiry = new DateTime($expiry_date);
    $days = (int)$now->diff($expiry)->format('%r%a');
    if ($days < 0)   return ['label'=>'Expired','class'=>'text-danger'];
    if ($days <= 30) return ['label'=>'Expires soon','class'=>'text-warning'];
    return ['label'=>'Valid','class'=>'text-success'];
}
function getFeeAmount($assessment, $fieldNames) {
    if (!$assessment) return 0;
    if (is_string($fieldNames)) $fieldNames = [$fieldNames];
    foreach ($fieldNames as $f) {
        if (isset($assessment[$f]) && $assessment[$f] !== null && $assessment[$f] !== '')
            return (float)$assessment[$f];
    }
    return 0;
}

// ============================================================
// FETCH APPLICATION  ← owner fields come from owner_info_data
// ============================================================
$sql = "
    SELECT 
        ba.id, ba.reference_no, ba.status, ba.created_at, ba.submitted_at,
        ba.application_date, ba.expiry_date, ba.tax_year,
        ba.renewed_from_id, ba.resubmitted_at,
        atd.application_type, atd.tin_no, atd.business_type,
        bid.business_name, bid.trade_name, bid.business_activities,
        oid.first_name  AS owner_first_name,
        oid.last_name   AS owner_last_name,
        oid.middle_name AS owner_middle_name,
        bad.business_address, bad.total_employees, bad.email, bad.mobile
    FROM business_applications ba
    LEFT JOIN application_type_data   atd ON ba.id = atd.application_id
    LEFT JOIN business_info_data      bid ON ba.id = bid.application_id
    LEFT JOIN owner_info_data         oid ON ba.id = oid.application_id
    LEFT JOIN business_address_data   bad ON ba.id = bad.application_id
    WHERE ba.id = ?
";
if ($user_type == 1) $sql .= " AND ba.user_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) die('Query prepare failed: ' . $conn->error);
if ($user_type == 1) $stmt->bind_param('ii', $app_id, $user_id);
else                 $stmt->bind_param('i',  $app_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$app) die('Application not found or access denied.');

// ─── Application type (New vs Renewal) ─────────────────────
$appTypeRaw = strtolower(trim($app['application_type'] ?? 'new'));
$isRenewal  = ($appTypeRaw === 'renewal') || !empty($app['renewed_from_id']);
$typeLabel  = $isRenewal ? 'Renewal' : 'New';

// ─── Annex 2 fee structure ─────────────────────────────────
$prevYear         = (int)date('Y') - 1;
$latePaymentLabel = 'Late Payment ' . $prevYear;

if ($isRenewal) {
    $annex2Fees = [
        ["Mayor's Permit",                 ['mayors_permit_fee','mayors_permit']],
        ['Gross Sales Tax Computed',       ['gross_sales_tax','gross_sales_tax_computed']],
        ['Permit on Calling',              ['permit_on_calling']],
        ['Health Certificate',             ['health_certificate']],
        ['Sanitary Inspection Fee',        ['sanitary_inspection_fee']],
        ['Sanitary Garbage Fee',           ['garbage_charges','sanitary_garbage_fee']],
        ['Weight & Measure',               ['weight_measure']],
        ['Misc. Fee',                      ['misc_fee','others']],
        ['Surcharge Fee',                  ['surcharge_fee']],
        ['Interest Fee',                   ['interest_fee']],
        [$latePaymentLabel,                ['late_payment']],
        ['Penalty (Lost Business Permit)', ['penalty_lost_permit','penalty']],
    ];
} else {
    $annex2Fees = [
        ["Mayor's Permit",          ['mayors_permit_fee','mayors_permit']],
        ['Gross Sales Tax Computed',['gross_sales_tax','gross_sales_tax_computed']],
        ['Permit on Calling',       ['permit_on_calling']],
        ['Health Certificate',      ['health_certificate']],
        ['Sanitary Inspection Fee', ['sanitary_inspection_fee']],
        ['Sanitary Garbage Fee',    ['garbage_charges','sanitary_garbage_fee']],
        ['Weight & Measure',        ['weight_measure']],
        ['Misc. Fee',               ['misc_fee','others']],
        ['Surcharge Fee',           ['surcharge_fee']],
        ['Interest Fee',            ['interest_fee']],
        [$latePaymentLabel,         ['late_payment']],
    ];
}

// ─── Expired & renewed detection ───────────────────────────
$isExpiredRenewed = false; $isRenewedButValid = false; $newReference = '';
$originalRef = $app['reference_no'] ?? '';
$findNewRef = $conn->prepare("SELECT reference_no FROM business_applications WHERE renewed_from_id = ? ORDER BY id DESC LIMIT 1");
$findNewRef->bind_param('i', $app['id']); $findNewRef->execute();
$renewalRow = $findNewRef->get_result()->fetch_assoc();
$findNewRef->close();
$hasRenewal = !empty($renewalRow);
if ($hasRenewal) $newReference = $renewalRow['reference_no'] ?? '';
$expiryTs   = !empty($app['expiry_date']) ? strtotime($app['expiry_date']) : null;
$hasExpired = ($expiryTs !== null && $expiryTs < time());
if ($hasRenewal && $hasExpired)      $isExpiredRenewed  = true;
elseif ($hasRenewal && !$hasExpired) $isRenewedButValid = true;
if (!$isExpiredRenewed && !$isRenewedButValid && strpos($originalRef,'-OLD') !== false) {
    if ($hasExpired) $isExpiredRenewed = true; else $isRenewedButValid = true;
    $newReference = str_replace('-OLD','',$originalRef);
}

// ─── Fee assessment & payment ──────────────────────────────
$feeAssessment = null;
$stmt = $conn->prepare("SELECT * FROM fee_assessments WHERE application_id = ?");
$stmt->bind_param('i', $app_id); $stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) $feeAssessment = $row;
$stmt->close();

$payment = null;
$stmt = $conn->prepare("SELECT * FROM payments WHERE application_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i', $app_id); $stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) $payment = $row;
$stmt->close();

$payment_status = $payment['status'] ?? null;

// ─── Business activities ───────────────────────────────────
$line_of_business = 'Not specified'; $capitalization = 0;
if (!empty($app['business_activities'])) {
    $activities = json_decode($app['business_activities'], true);
    if (is_array($activities) && !empty($activities)) {
        $first_activity   = $activities[0];
        $line_of_business = $first_activity['line_of_business'] ?? 'Not specified';
        $capitalization   = (float)($first_activity['capitalization'] ?? 0);
    }
}

// ─── Fee line items ────────────────────────────────────────
$feeLineItems = []; $totalLGU = 0; $fireSafety = 0; $grandTotal = 0;
$assessed_by = null; $assessed_at = null;

if ($feeAssessment) {
    foreach ($annex2Fees as $fi) {
        $amt = getFeeAmount($feeAssessment, $fi[1]);
        if ($amt > 0) { $feeLineItems[] = [$fi[0], $amt]; $totalLGU += $amt; }
    }
    $fireSafety  = (float)($feeAssessment['fire_safety_fee_percent'] ?? 0);
    $grandTotal  = (float)($feeAssessment['total_amount'] ?? ($totalLGU + $fireSafety));
    $assessed_by = $feeAssessment['assessed_by'] ?? null;
    $assessed_at = $feeAssessment['assessed_at'] ?? null;
}

// ─── Payment confirmed logic ───────────────────────────────
$payment_paid = ($payment_status === 'paid');
$status       = $app['status'];
$paymentConfirmed = false;
if ($payment_paid)                                                                                $paymentConfirmed = true;
elseif (in_array($status, ['payment_confirmed','under_review','approved','claimed','verified'])) $paymentConfirmed = true;

// ─── Tracker steps ─────────────────────────────────────────
$steps = [
    'submitted'         => !empty($app['submitted_at']),
    'docs_verified'     => !in_array($status, ['submitted']),
    'payment_confirmed' => $paymentConfirmed,
    'under_review'      => in_array($status, ['under_review','approved','claimed','verified']),
    'permit_issued'     => in_array($status, ['approved','claimed','verified']),
];
if ($status === 'cancelled' || $status === 'rejected') $steps['permit_issued'] = false;

$step_keys = ['submitted','docs_verified','payment_confirmed','under_review','permit_issued'];
$current_step_index = 0;
foreach ($step_keys as $idx => $key) { if ($steps[$key]) $current_step_index = $idx + 1; else break; }

// ─── Tracker message ───────────────────────────────────────
if ($isExpiredRenewed)      $tracker_message = '⏰ This permit has EXPIRED and has been RENEWED. Please check the new permit record.';
elseif ($isRenewedButValid) $tracker_message = '🔁 This permit has been RENEWED. Your new permit ' . $newReference . ' is now active.';
elseif ($status === 'verified')                          $tracker_message = 'Your application has been VERIFIED and approved by the BPLO.';
elseif ($status === 'approved' || $status === 'claimed') $tracker_message = 'Your business permit has been approved and issued.';
elseif ($status === 'under_review')                      $tracker_message = 'Your application is under review by the BPLO.';
elseif ($status === 'rejected')                          $tracker_message = '❌ Your application has been REJECTED. Please contact the BPLO.';
elseif ($status === 'cancelled')                         $tracker_message = '❌ Your application has been CANCELLED.';
elseif ($status === 'payment_confirmed')                 $tracker_message = '✅ Payment confirmed. Your application is now being reviewed.';
elseif ($payment_paid)                                   $tracker_message = 'Payment confirmed. Your documents are now being verified.';
elseif ($status === 'pending_payment' && $feeAssessment) $tracker_message = 'Please review the fee assessment below and proceed with payment.';
elseif ($status === 'pending_payment')                   $tracker_message = 'Your documents have been verified. Please wait for the Treasury to release your fee assessment.';
elseif (!empty($app['submitted_at']))                    $tracker_message = 'Application submitted. Please wait for verification.';
else                                                     $tracker_message = 'Application in progress.';

// ─── Permit details ────────────────────────────────────────
$issue_date           = !empty($app['submitted_at']) ? $app['submitted_at'] : ($app['application_date'] ?? $app['created_at']);
$issue_date_formatted = date('M j, Y', strtotime($issue_date));
$expiry_date          = !empty($app['expiry_date']) ? $app['expiry_date'] : date('Y-m-d', strtotime('December 31, ' . date('Y')));

$ref_no_full   = $app['reference_no'];
$ref_no_suffix = substr($ref_no_full, 3);
$mp_ref_no     = "MP-" . $ref_no_suffix;
$bp_ref_no     = $ref_no_full;
$qr_url        = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($bp_ref_no);

$or_number      = 'OR-' . date('Y') . '-' . str_pad($app['id'], 6, '0', STR_PAD_LEFT);
$payment_method = $payment['payment_method'] ?? 'GCash';
$paid_at        = $payment['paid_at'] ?? date('Y-m-d H:i:s');
$amount_paid    = $payment['amount_due'] ?? $grandTotal;

$feeLineItemsJs = [];
foreach ($feeLineItems as $item) $feeLineItemsJs[] = [$item[0], number_format((float)$item[1], 2)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title><?php echo ($user_type == 3 || $user_type == 4) ? 'Staff View' : 'Permit & Receipt'; ?> | Buyawanong Placer</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--brand-dark:#1e293b;--brand-dark-hover:#0f172a;--brand-yellow:#fbbf24;--bg-light:#f8fafc;--text-muted:#64748b;--border-color:#e2e8f0;--green:#10b981;--green-dark:#065f46;--red:#ef4444;--red-dark:#991b1b;--gray:#6b7280;}
        *{font-family:'Inter',sans-serif;}
        body{background:var(--bg-light);color:var(--brand-dark);}
        .permit-card,.receipt-card,.tracker-card{background:#fff;border-radius:16px;box-shadow:0 4px 20px -4px rgba(15,23,42,.06);border:1px solid var(--border-color);overflow:hidden;margin-bottom:1.5rem;}
        .permit-header{background:var(--brand-dark);color:#fff;padding:20px 24px;}
        .permit-header.rejected-header{background:#991b1b;}
        .permit-header.verified-header{background:var(--brand-dark);}
        .permit-header.cancelled-header{background:#4b5563;}
        .permit-header.expired-header{background:#c2410c;}
        .permit-header h3,.permit-header h4{font-weight:700;color:#fff;margin:0;}
        .permit-header small{color:#94a3b8;font-size:.8rem;}
        .permit-body{padding:28px;}
        .back-link{color:var(--brand-dark);text-decoration:underline;text-decoration-color:#cbd5e1;text-underline-offset:3px;font-weight:500;font-size:.9rem;}
        .back-link:hover{color:var(--brand-dark);text-decoration-color:var(--brand-dark);}
        .staff-badge{background:#f1f5f9;color:var(--brand-dark);padding:.35rem .85rem;border-radius:2rem;font-size:.75rem;font-weight:600;border:1px solid var(--border-color);}
        .qr-code{max-width:150px;margin:0 auto;}
        .badge-status,.badge-verified,.badge-rejected,.badge-cancelled,.badge-expired-renewed{padding:.4rem .9rem;border-radius:2rem;font-weight:600;font-size:.78rem;}
        .badge-status{background:#d1fae5;color:var(--green-dark);}
        .badge-verified{background:#d1fae5;color:var(--green-dark);}
        .badge-rejected{background:#fee2e2;color:#991b1b;}
        .badge-cancelled{background:#f1f5f9;color:#4b5563;}
        .badge-expired-renewed{background:#ffedd5;color:#c2410c;}
        hr{opacity:.15;}
        .progress-tracker-horizontal{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;position:relative;margin:1rem 0;}
        .step-item{flex:1;text-align:center;position:relative;min-width:100px;}
        .step-circle{width:48px;height:48px;background:#fff;border:1.5px solid var(--border-color);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:1.3rem;margin-bottom:.5rem;transition:all .2s;color:var(--text-muted);}
        .step-item.completed .step-circle{background:var(--green);border-color:var(--green);color:#fff;}
        .step-item.current .step-circle{background:var(--brand-yellow);border-color:var(--brand-yellow);color:var(--brand-dark);}
        .step-item.verified .step-circle{background:var(--green);border-color:var(--green);color:#fff;}
        .step-item.rejected .step-circle{background:var(--red);border-color:var(--red);color:#fff;}
        .step-item.cancelled .step-circle{background:var(--gray);border-color:var(--gray);color:#fff;}
        .step-item.expired .step-circle{background:#ea580c;border-color:#ea580c;color:#fff;}
        .step-label{font-size:.75rem;font-weight:500;color:var(--text-muted);}
        .step-item.completed .step-label,.step-item.current .step-label{color:var(--brand-dark);font-weight:600;}
        .step-item.verified .step-label{color:var(--green-dark);font-weight:600;}
        .step-item.rejected .step-label{color:var(--red-dark);font-weight:600;}
        .step-item.cancelled .step-label{color:var(--gray);font-weight:600;}
        .step-item.expired .step-label{color:#c2410c;font-weight:600;}
        .step-connector{position:absolute;top:24px;left:50%;width:100%;height:2px;background:var(--border-color);z-index:0;}
        .step-connector.completed{background:var(--green);}
        .step-connector.verified{background:var(--green);}
        .step-connector.rejected{background:var(--red);}
        .step-connector.cancelled{background:var(--gray);}
        .step-connector.expired{background:#ea580c;}
        .step-item:last-child .step-connector{display:none;}
        @media(max-width:768px){.step-circle{width:36px;height:36px;font-size:1rem;}.step-connector{top:18px;}.step-label{font-size:.65rem;}}
        @media print{.no-print,.tracker-card,.back-link,.permit-card .no-print,.receipt-card .no-print,#reminderBtn,.alert-warning,.permit-card .alert-warning{display:none !important;}body{background:#fff;padding:0;margin:0;}.permit-card,.receipt-card{box-shadow:none;border:1px solid #ddd;margin:0;page-break-after:avoid;}.permit-header{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}
        .fee-breakdown td{padding:.5rem;}
        .receipt-card .fee-breakdown td,.receipt-card .fee-breakdown th{font-size:.85rem;vertical-align:middle;}
        .receipt-card .fee-breakdown thead th{background:#f1f5f9;color:var(--brand-dark);font-weight:600;border-color:var(--border-color);}
        .receipt-card .table-success td,.permit-card .table-success td{background-color:#d1fae5 !important;color:var(--green-dark) !important;}
        .status-icon-large{font-size:4rem;}
        .status-expired{color:#ea580c;}.status-rejected{color:var(--red);}.status-cancelled{color:var(--gray);}
        .alert-verified{background:#d1fae5;border-color:var(--green);color:var(--green-dark);}
        .alert-rejected{background:#fee2e2;border-color:var(--red);color:var(--red-dark);}
        .alert-cancelled{background:#f1f5f9;border-color:var(--gray);color:#4b5563;}
        .alert-expired{background:#ffedd5;border-color:#ea580c;color:#c2410c;}
        .expired-notice{background:#ffedd5;border-left:4px solid #ea580c;border-radius:8px;padding:1rem 1.25rem;margin:1rem 0;}
        .btn-brand{background-color:var(--brand-dark);color:#fff !important;border:1px solid var(--brand-dark);border-radius:8px;padding:8px 20px;font-weight:600;font-size:.85rem;transition:all .2s ease;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
        .btn-brand:hover{background-color:var(--brand-dark-hover);border-color:var(--brand-dark-hover);color:#fff !important;transform:translateY(-1px);}
        .btn-outline-brand{background-color:transparent;color:var(--brand-dark) !important;border:1px solid var(--border-color);border-radius:8px;padding:8px 20px;font-weight:600;font-size:.85rem;transition:all .2s ease;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
        .btn-outline-brand:hover{background-color:#f1f5f9;border-color:var(--brand-dark);color:var(--brand-dark) !important;}
        .btn-success{background-color:var(--brand-dark);border-color:var(--brand-dark);color:#fff;}
        .btn-success:hover,.btn-success:focus{background-color:var(--brand-dark-hover);border-color:var(--brand-dark-hover);color:#fff;}
        .text-label{font-size:.75rem;color:var(--text-muted);font-weight:500;margin-bottom:2px;display:block;}
        .business-name-title{color:var(--brand-dark);font-weight:700;}
        .permit-body h5,.permit-body h6{color:var(--brand-dark);}
        .app-type-chip{display:inline-block;background:#e0e7ff;color:#3730a3;padding:.2rem .7rem;border-radius:2rem;font-size:.72rem;font-weight:600;margin-left:.35rem;}
        .app-type-chip.renewal{background:#fef3c7;color:#92400e;}
        .no-assessment-note{background:#f8fafc;border:1px dashed var(--border-color);border-radius:12px;padding:1.25rem 1.5rem;color:var(--text-muted);font-size:.9rem;}
    </style>
</head>
<body>

<?php if (!$isEmbedded): ?>
<?php include 'header/nav.php'; ?>
<?php endif; ?>

<div class="container" style="max-width: 1200px;">
    <?php if (!$isEmbedded): ?>
    <div class="mb-3 mt-4">
        <a href="<?php echo $dashboardUrl; ?>" class="back-link">
            <i class="bi bi-arrow-left-short"></i> Back to Dashboard
        </a>
        <?php if ($user_type == 3 || $user_type == 4): ?>
            <span class="staff-badge ms-2"><i class="bi bi-person-badge"></i> Staff view</span>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="height: 16px;"></div>
    <?php endif; ?>

    
    <!-- TRACKER -->
    <div class="tracker-card">
        <div class="permit-header <?php echo $isExpiredRenewed ? 'expired-header' : ($isRenewedButValid ? 'expired-header' : ($status === 'verified' ? 'verified-header' : ($status === 'rejected' ? 'rejected-header' : ($status === 'cancelled' ? 'cancelled-header' : '')))); ?> d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="fw-bold mb-0"> Application Progress</h4>
            <?php if ($isExpiredRenewed): ?>
                <span class="badge-expired-renewed"><i class="bi bi-clock-history"></i> Expired / Renewed</span>
            <?php elseif ($isRenewedButValid): ?>
                <span class="badge bg-info text-dark px-3 py-2 rounded-pill"><i class="bi bi-arrow-repeat"></i> Renewed (still valid)</span>
            <?php elseif ($status === 'verified'): ?>
                <span class="badge-verified"><i class="bi bi-trophy"></i> Verified</span>
            <?php elseif ($status === 'rejected'): ?>
                <span class="badge-rejected"><i class="bi bi-x-circle"></i> Rejected</span>
            <?php elseif ($status === 'cancelled'): ?>
                <span class="badge-cancelled"><i class="bi bi-x-circle"></i> Cancelled</span>
            <?php elseif ($status === 'approved' || $status === 'claimed'): ?>
                <span class="badge-status"><i class="bi bi-check-circle-fill"></i> Approved &amp; issued</span>
            <?php elseif ($status === 'under_review'): ?>
                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><i class="bi bi-hourglass-split"></i> Under review</span>
            <?php elseif ($status === 'payment_confirmed'): ?>
                <span class="badge bg-info text-dark px-3 py-2 rounded-pill"><i class="bi bi-credit-card"></i> Payment confirmed</span>
            <?php elseif ($payment_paid): ?>
                <span class="badge bg-info text-dark px-3 py-2 rounded-pill"><i class="bi bi-credit-card"></i> Payment confirmed</span>
            <?php elseif ($status === 'pending_payment'): ?>
                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><i class="bi bi-cash"></i> Pending payment</span>
            <?php elseif (!empty($app['submitted_at'])): ?>
                <span class="badge bg-secondary text-white px-3 py-2 rounded-pill"><i class="bi bi-send"></i> Submitted</span>
            <?php else: ?>
                <span class="badge bg-secondary text-white px-3 py-2 rounded-pill"><i class="bi bi-pencil"></i> Draft</span>
            <?php endif; ?>
        </div>
        <div class="permit-body">
            <?php if ($isExpiredRenewed): ?>
                <div class="expired-notice"><div class="d-flex align-items-center gap-3">
                    <i class="bi bi-clock-history fs-2 text-warning"></i>
                    <div>
                        <h5 class="fw-bold mb-0 text-warning">⏰ Expired Record — Already Renewed</h5>
                        <p class="mb-0 small">This permit has expired and was renewed as <strong><?php echo htmlspecialchars($newReference); ?></strong>.</p>
                    </div>
                </div></div>
            <?php elseif ($isRenewedButValid): ?>
                <div class="expired-notice" style="background:#e0f2fe;border-left-color:#0284c7;"><div class="d-flex align-items-center gap-3">
                    <i class="bi bi-arrow-repeat fs-2" style="color:#0284c7;"></i>
                    <div>
                        <h5 class="fw-bold mb-0" style="color:#075985;">🔁 Renewed — Still Valid</h5>
                        <p class="mb-0 small">Renewed as <strong><?php echo htmlspecialchars($newReference); ?></strong>. Valid until <strong><?php echo date('M j, Y', strtotime($app['expiry_date'])); ?></strong>.</p>
                    </div>
                </div></div>
            <?php endif; ?>

            <div class="progress-tracker-horizontal">
                <?php
                $steps_horizontal = [
                    'submitted'        => ['label'=>'Submitted','icon'=>'bi-send'],
                    'docs_verified'    => ['label'=>'Docs verified','icon'=>'bi-file-check'],
                    'payment_confirmed'=> ['label'=>'Payment confirmed','icon'=>'bi-credit-card'],
                    'under_review'     => ['label'=>'Under review','icon'=>'bi-hourglass-split'],
                    'permit_issued'    => ['label'=>'Permit issued','icon'=>'bi-trophy'],
                ];
                if ($isExpiredRenewed) { $steps_horizontal['permit_issued']['icon']='bi-clock-history'; $steps_horizontal['permit_issued']['label']='Expired / Renewed'; }
                elseif ($isRenewedButValid) { $steps_horizontal['permit_issued']['icon']='bi-arrow-repeat'; $steps_horizontal['permit_issued']['label']='Renewed (still valid)'; }
                elseif ($status === 'rejected') { $steps_horizontal['permit_issued']['icon']='bi-x-circle'; $steps_horizontal['permit_issued']['label']='Rejected'; }
                elseif ($status === 'cancelled') { $steps_horizontal['permit_issued']['icon']='bi-x-circle'; $steps_horizontal['permit_issued']['label']='Cancelled'; }
                elseif ($status === 'verified') { $steps_horizontal['permit_issued']['icon']='bi-trophy'; $steps_horizontal['permit_issued']['label']='Verified'; }

                $step_count = count($steps_horizontal); $idx = 0;
                foreach ($steps_horizontal as $key => $step):
                    $completed = $steps[$key];
                    $is_current = ($current_step_index == ($idx + 1) && !$completed);
                    $is_expired = ($isExpiredRenewed && $key === 'permit_issued');
                    $is_renewed_valid = ($isRenewedButValid && $key === 'permit_issued');
                    $is_rejected = ($status === 'rejected' && $key === 'permit_issued');
                    $is_cancelled = ($status === 'cancelled' && $key === 'permit_issued');
                    $is_verified = ($status === 'verified' && $key === 'permit_issued');
                    if ($status === 'rejected' || $status === 'cancelled') $completed = ($idx < $step_count - 1);
                    if ($isExpiredRenewed) $completed = ($idx < $step_count - 1);
                    $icon = $step['icon']; $step_class = '';
                    if ($is_expired) $step_class='expired';
                    elseif ($is_renewed_valid) $step_class='completed';
                    elseif ($is_rejected) $step_class='rejected';
                    elseif ($is_cancelled) $step_class='cancelled';
                    elseif ($is_verified) $step_class='verified';
                    elseif ($completed) $step_class='completed';
                    elseif ($is_current) $step_class='current';
                ?>
                <div class="step-item <?php echo $step_class; ?>">
                    <div class="step-circle">
                        <?php if ($completed && !$is_rejected && !$is_cancelled && !$is_expired && !$is_verified && !$is_renewed_valid): ?>
                            <i class="bi bi-check-lg"></i>
                        <?php elseif ($is_expired): ?><i class="bi bi-clock-history"></i>
                        <?php elseif ($is_renewed_valid): ?><i class="bi bi-arrow-repeat"></i>
                        <?php elseif ($is_rejected): ?><i class="bi bi-x-lg"></i>
                        <?php elseif ($is_cancelled): ?><i class="bi bi-x-lg"></i>
                        <?php elseif ($is_verified): ?><i class="bi bi-trophy"></i>
                        <?php else: ?><i class="bi <?php echo $icon; ?>"></i><?php endif; ?>
                    </div>
                    <div class="step-label"><?php echo $step['label']; ?></div>
                    <?php if ($idx < $step_count - 1): ?><div class="step-connector <?php echo $step_class; ?>"></div><?php endif; ?>
                </div>
                <?php $idx++; endforeach; ?>
            </div>

            <?php if ($isExpiredRenewed): ?>
                <div class="alert alert-expired mt-4 mb-0"><i class="bi bi-clock-history me-2"></i> <?php echo $tracker_message; ?></div>
            <?php elseif ($isRenewedButValid): ?>
                <div class="alert alert-info mt-4 mb-0"><i class="bi bi-arrow-repeat me-2"></i> <?php echo $tracker_message; ?></div>
            <?php elseif ($status === 'rejected'): ?>
                <div class="alert alert-rejected mt-4 mb-0"><i class="bi bi-x-circle-fill me-2"></i> <?php echo $tracker_message; ?></div>
            <?php elseif ($status === 'cancelled'): ?>
                <div class="alert alert-cancelled mt-4 mb-0"><i class="bi bi-x-circle-fill me-2"></i> <?php echo $tracker_message; ?></div>
            <?php elseif ($status === 'verified'): ?>
                <div class="alert alert-verified mt-4 mb-0"><i class="bi bi-trophy-fill me-2"></i> <?php echo $tracker_message; ?></div>
            <?php else: ?>
                <div class="alert alert-success mt-4 mb-0"><i class="bi bi-info-circle-fill me-2"></i> <?php echo $tracker_message; ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- FEE ASSESSMENT -->
    <?php if ($feeAssessment && !in_array($status, ['rejected','cancelled']) && !$isExpiredRenewed): ?>
    <div class="permit-card">
        <div class="permit-header d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="fw-bold mb-0">
                <i class="bi bi-calculator"></i> Fee Assessment
                <span class="app-type-chip <?php echo $isRenewal ? 'renewal' : ''; ?>"><?php echo $typeLabel; ?> Application</span>
            </h4>
            <span class="badge bg-light text-dark">Assessed</span>
        </div>
        <div class="permit-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered fee-breakdown">
                    <thead><tr><th>Description</th><th class="text-end" style="width:35%;">Amount</th></tr></thead>
                    <tbody>
                        <?php if (!empty($feeLineItems)): foreach ($feeLineItems as $item): ?>
                            <tr><td><?php echo htmlspecialchars($item[0]); ?></td><td class="text-end">₱ <?php echo number_format((float)$item[1], 2); ?></td></tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="2" class="text-center text-muted py-3">No fee items recorded.</td></tr>
                        <?php endif; ?>
                        <tr class="table-light"><td><strong>Subtotal — LGU Fees</strong></td><td class="text-end"><strong>₱ <?php echo number_format($totalLGU, 2); ?></strong></td></tr>
                        <tr><td>Fire Safety Inspection Fee (FSIF)</td><td class="text-end">₱ <?php echo number_format($fireSafety, 2); ?></td></tr>
                        <tr class="table-success"><td><strong>GRAND TOTAL</strong></td><td class="text-end"><strong>₱ <?php echo number_format($grandTotal, 2); ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
            <?php if ($assessed_by): ?><p class="small text-muted">Assessed by: <?php echo htmlspecialchars($assessed_by); ?> on <?php echo date('M d, Y H:i', strtotime($assessed_at)); ?></p><?php endif; ?>

            <?php if ($user_type == 1 && $status === 'pending_payment'): ?>
                <div class="mt-3">
                    <?php if ($payment_status === 'pending' || $payment_status === 'paid'): ?>
                        <div class="alert alert-warning"><i class="bi bi-clock-history"></i> <?php echo ($payment_status === 'pending') ? 'Your payment is being processed.' : 'Your payment has already been confirmed.'; ?></div>
                    <?php else: ?>
                        <div class="alert alert-info"><i class="bi bi-info-circle"></i> Your assessment is ready. Please proceed to payment.</div>
                        <a href="payment.php?ref=<?php echo urlencode($ref_no_full); ?>" class="btn-brand"><i class="bi bi-credit-card"></i> Pay Now (₱<?php echo number_format($grandTotal, 2); ?>)</a>
                    <?php endif; ?>
                </div>
            <?php elseif (($user_type == 3 || $user_type == 4) && $status === 'pending_payment'): ?>
                <div class="mt-3"><a href="payment.php?ref=<?php echo urlencode($ref_no_full); ?>" class="btn-brand"><i class="bi bi-credit-card"></i> Process Payment (Staff)</a></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- AWAITING ASSESSMENT NOTE -->
    <?php if (!$feeAssessment && !in_array($status, ['rejected','cancelled']) && !$isExpiredRenewed && $status === 'pending_payment' && ($user_type == 3 || $user_type == 4)): ?>
    <div class="permit-card">
        <div class="permit-header d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="fw-bold mb-0"><i class="bi bi-calculator"></i> Fee Assessment</h4>
            <span class="badge bg-light text-dark">Not yet assessed</span>
        </div>
        <div class="permit-body">
            <div class="no-assessment-note">
                <i class="bi bi-info-circle me-2"></i>
                No fee assessment has been saved for this application yet.
                <?php if ($user_type == 3): ?>
                    Click <strong>Process Payment</strong> to compute and save the fees.
                <?php else: ?>
                    The Treasury Office will release the fee assessment shortly.
                <?php endif; ?>
            </div>
            <?php if ($user_type == 3): ?>
                <div class="mt-3">
                    <a href="payment.php?ref=<?php echo urlencode($ref_no_full); ?>" class="btn-brand">
                        <i class="bi bi-calculator"></i> Process Payment (Staff)
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- MAIN PERMIT -->
    <?php if ($isExpiredRenewed): ?>
    <div class="permit-card">
        <div class="permit-header expired-header"><h3 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i> PERMIT EXPIRED &amp; RENEWED</h3></div>
        <div class="permit-body text-center py-5">
            <i class="bi bi-clock-history status-expired" style="font-size:4rem;"></i>
            <h4 class="mt-3 text-warning">This permit has expired and was renewed</h4>
            <p class="text-muted">Reference No: <?php echo htmlspecialchars($bp_ref_no); ?></p>
            <p>Renewed as <strong><?php echo htmlspecialchars($newReference); ?></strong>.</p>
            <div class="mt-3">
                <a href="<?php echo $dashboardUrl; ?>" class="btn btn-secondary rounded-pill"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
                <?php if (!empty($newReference)): ?><a href="view_application.php?id=<?php echo $app_id; ?>" class="btn-brand rounded-pill"><i class="bi bi-arrow-right"></i> View Renewed Permit</a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php elseif ($status === 'rejected' || $status === 'cancelled'): ?>
    <div class="permit-card">
        <div class="permit-header <?php echo ($status === 'rejected') ? 'rejected-header' : 'cancelled-header'; ?>"><h3 class="fw-bold mb-0"><i class="bi bi-x-circle me-2"></i> PERMIT <?php echo strtoupper($status); ?></h3></div>
        <div class="permit-body text-center py-5">
            <i class="bi bi-x-circle-fill <?php echo ($status === 'rejected') ? 'status-rejected' : 'status-cancelled'; ?>" style="font-size:4rem;"></i>
            <h4 class="mt-3 <?php echo ($status === 'rejected') ? 'text-danger' : 'text-secondary'; ?>">This application has been <?php echo $status; ?></h4>
            <p class="text-muted">Reference No: <?php echo htmlspecialchars($bp_ref_no); ?></p>
            <div class="mt-3"><a href="<?php echo $dashboardUrl; ?>" class="btn btn-secondary rounded-pill"><i class="bi bi-arrow-left"></i> Back to Dashboard</a></div>
        </div>
    </div>
    <?php else: ?>
    <div class="permit-card">
        <div class="permit-header <?php echo ($status === 'verified') ? 'verified-header' : ''; ?> d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h3 class="fw-bold mb-0"><i class="bi bi-building-check me-2"></i> MAYOR'S BUSINESS PERMIT</h3>
                <small>Republic of the Philippines – Municipality of Placer, Surigao del Norte</small>
            </div>
            <div class="mt-2 mt-md-0">
                <?php if ($status === 'verified'): ?><span class="badge-verified"><i class="bi bi-trophy"></i> Verified</span>
                <?php elseif ($status === 'approved' || $status === 'claimed'): ?><span class="badge-status"><i class="bi bi-check-circle-fill"></i> Active</span>
                <?php elseif ($status === 'payment_confirmed'): ?><span class="badge bg-info text-dark">Payment confirmed</span>
                <?php else: ?><span class="badge bg-secondary text-white">Pending</span><?php endif; ?>
                <span class="badge bg-light text-dark ms-2">Tax Year <?php echo htmlspecialchars($app['tax_year'] ?? date('Y')); ?></span>
            </div>
        </div>
        <div class="permit-body">
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-4">
                        <h5 class="business-name-title"><?php echo htmlspecialchars($app['business_name']); ?> <span class="app-type-chip <?php echo $isRenewal ? 'renewal' : ''; ?>"><?php echo $typeLabel; ?></span></h5>
                        <p class="text-muted small">Permit No. <?php echo htmlspecialchars($mp_ref_no); ?></p>
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6"><span class="text-label">Owner / registrant</span><div><?php echo htmlspecialchars(trim(($app['owner_first_name'] ?? '') . ' ' . ($app['owner_last_name'] ?? ''))); ?></div></div>
                        <div class="col-sm-6"><span class="text-label">TIN</span><div><?php echo htmlspecialchars($app['tin_no'] ?? 'N/A'); ?></div></div>
                        <div class="col-sm-6"><span class="text-label">Business type</span><div><?php echo htmlspecialchars($app['business_type'] ?? 'Single'); ?></div></div>
                        <div class="col-sm-6"><span class="text-label">Line of business</span><div><?php echo htmlspecialchars($line_of_business); ?></div></div>
                        <div class="col-12"><span class="text-label">Business address</span><div><?php echo htmlspecialchars($app['business_address'] ?? 'Not provided'); ?></div></div>
                        <div class="col-sm-6"><span class="text-label">Capitalization</span><div>₱ <?php echo number_format($capitalization, 2); ?></div></div>
                        <div class="col-sm-6"><span class="text-label">No. of employees</span><div><?php echo (int)($app['total_employees'] ?? 0); ?></div></div>
                        <?php if (!empty($app['expiry_date'])): $expStatus = getExpiryStatus($app['expiry_date']); ?>
                        <div class="col-sm-6"><span class="text-label">Expiry Date</span><div><?php echo date('M j, Y', strtotime($app['expiry_date'])); ?> <span class="small <?php echo $expStatus['class']; ?> ms-1">(<?php echo $expStatus['label']; ?>)</span></div></div>
                        <?php endif; ?>
                    </div>
                    <hr class="my-4">
                    <div class="row">
                        <div class="col-sm-6"><span class="text-label">Reference no.</span><div><?php echo htmlspecialchars($bp_ref_no); ?></div></div>
                        <div class="col-sm-6"><span class="text-label">Date issued</span><div><?php echo $issue_date_formatted; ?></div></div>
                        <div class="col-sm-6"><span class="text-label">Valid until</span><div><?php echo date('M j, Y', strtotime($expiry_date)); ?></div></div>
                        <div class="col-sm-6"><span class="text-label">Permit type</span><div><?php echo htmlspecialchars($app['application_type'] ?? 'New'); ?> — business permit</div></div>
                    </div>
                    <?php if ($status === 'verified'): ?>
                        <div class="alert alert-verified mt-3"><i class="bi bi-trophy-fill me-2"></i> <strong>This permit has been VERIFIED and approved by the BPLO.</strong></div>
                    <?php elseif ($status === 'payment_confirmed'): ?>
                        <div class="alert alert-info mt-3"><i class="bi bi-credit-card me-2"></i> <strong>Payment confirmed. Your application is now under review by the BPLO.</strong></div>
                    <?php else: ?>
                        <div class="alert alert-warning mt-3 small no-print"><i class="bi bi-info-circle"></i> This permit expires on <strong><?php echo date('F j, Y', strtotime($expiry_date)); ?></strong>.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-center border-start">
                    <div class="qr-code mb-3"><img src="<?php echo $qr_url; ?>" alt="QR Code" class="img-fluid"></div>
                    <p class="small text-muted">Scan to verify permit authenticity</p>
                    <div class="mt-2"><code class="bg-light p-1 rounded"><?php echo $bp_ref_no; ?></code></div>
                    <hr>
                    <div class="mt-3 no-print">
                        <?php if ($status === 'verified'): ?>
                            <a href="generate_certificate.php?id=<?php echo (int)$app['id']; ?>" target="_blank" class="btn-outline-brand w-100 mb-2 justify-content-center"><i class="bi bi-printer"></i> Print Permit</a>
                            <a href="generate_certificate.php?id=<?php echo (int)$app['id']; ?>" target="_blank" class="btn-brand w-100 mb-2 justify-content-center"><i class="bi bi-download"></i> Download permit (PDF)</a>
                            <a href="download_application_form.php?id=<?php echo (int)$app['id']; ?>" class="btn-outline-brand w-100 justify-content-center"><i class="bi bi-file-earmark-arrow-down"></i> Download Application Form</a>
                        <?php endif; ?>
                        <?php if (in_array($status, ['approved','claimed','verified']) && !$isRenewedButValid): ?>
                            <hr><a href="renew_permit.php?renew=<?php echo $app['id']; ?>" class="btn btn-sm btn-outline-warning rounded-pill w-100"><i class="bi bi-arrow-repeat"></i> Renew This Permit</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="mt-4 text-center small text-muted border-top pt-3">
                This permit is issued pursuant to existing laws and ordinances of the Municipality of Placer, SDN.<br>
                <strong>Municipal Mayor</strong> — Municipality of Placer, SDN
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- OFFICIAL RECEIPT -->
    <?php if ($feeAssessment && ($payment_paid || $status === 'payment_confirmed' || in_array($status, ['approved','claimed','verified'])) && !in_array($status, ['rejected','cancelled']) && !$isExpiredRenewed): ?>
    <div class="receipt-card">
        <div class="permit-header"><h4 class="fw-bold mb-0"><i class="bi bi-receipt"></i> Official Receipt</h4></div>
        <div class="permit-body">
            <div class="row mb-3">
                <div class="col-md-6"><table class="table table-sm table-borderless mb-0">
                    <tr><td style="width:45%;">OR no.</td><td><strong><?php echo htmlspecialchars($or_number); ?></strong></td></tr>
                    <tr><td>Reference no.</td><td><strong><?php echo htmlspecialchars($bp_ref_no); ?></strong></td></tr>
                    <tr><td>Business name</td><td><?php echo htmlspecialchars($app['business_name']); ?></td></tr>
                    <tr><td>Owner / registrant</td><td><?php echo htmlspecialchars(trim(($app['owner_first_name'] ?? '') . ' ' . ($app['owner_last_name'] ?? ''))); ?></td></tr>
                </table></div>
                <div class="col-md-6"><table class="table table-sm table-borderless mb-0">
                    <tr><td style="width:45%;">Payment method</td><td><?php echo htmlspecialchars($payment_method); ?></td></tr>
                    <tr><td>Date paid</td><td><?php echo date('M j, Y g:i A', strtotime($paid_at)); ?></td></tr>
                    <tr><td>Tax year</td><td><?php echo htmlspecialchars($app['tax_year'] ?? date('Y')); ?></td></tr>
                    <tr><td>Verified by</td><td>Municipal Treasurer</td></tr>
                </table></div>
            </div>
            <h6 class="fw-bold mb-2"><i class="bi bi-list-ul me-1"></i> Details of Fees Paid</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered fee-breakdown mb-2">
                    <thead><tr><th style="width:60%;">Description</th><th class="text-end" style="width:40%;">Amount</th></tr></thead>
                    <tbody>
                        <?php if (!empty($feeLineItems)): foreach ($feeLineItems as $item): ?>
                            <tr><td><?php echo htmlspecialchars($item[0]); ?></td><td class="text-end">₱ <?php echo number_format((float)$item[1], 2); ?></td></tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="2" class="text-center text-muted py-3">No fee items.</td></tr>
                        <?php endif; ?>
                        <tr class="table-light"><td><strong>Subtotal — LGU Fees</strong></td><td class="text-end"><strong>₱ <?php echo number_format($totalLGU, 2); ?></strong></td></tr>
                        <tr><td>Fire Safety Inspection Fee (FSIF)</td><td class="text-end">₱ <?php echo number_format($fireSafety, 2); ?></td></tr>
                        <tr class="table-success"><td><strong>GRAND TOTAL</strong></td><td class="text-end"><strong>₱ <?php echo number_format($amount_paid, 2); ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="alert alert-success text-center small mt-2"><i class="bi bi-check-circle-fill"></i> Payment confirmed &amp; verified</div>
            <div class="text-end no-print"><button class="btn-outline-brand" id="downloadReceiptBtn"><i class="bi bi-download"></i> Download receipt (PDF)</button></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- VERIFICATION -->
    <?php if (!in_array($status, ['rejected','cancelled']) && !$isExpiredRenewed): ?>
    <div class="permit-card"><div class="permit-body">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h6><i class="bi bi-shield-lock-fill" style="color:var(--brand-dark);"></i> Permit verification</h6>
                <p class="small">Share this reference number so anyone can verify this permit publicly.</p>
                <div class="input-group">
                    <input type="text" class="form-control form-control-sm" id="refNoCopy" value="<?php echo $bp_ref_no; ?>" readonly>
                    <button class="btn-outline-brand btn-sm" id="copyRefBtn">Copy</button>
                </div>
            </div>
            <div class="col-md-6 text-md-end">
                <?php if ($isRenewedButValid): ?><span class="badge bg-info text-dark p-2"><i class="bi bi-arrow-repeat"></i> Renewed (still valid)</span>
                <?php elseif ($status === 'verified'): ?><span class="badge-verified p-2">Status: Verified</span>
                <?php elseif ($status === 'approved' || $status === 'claimed'): ?><span class="badge-status p-2"><i class="bi bi-calendar-check"></i> Active</span>
                <?php elseif ($status === 'payment_confirmed'): ?><span class="badge bg-info text-dark p-2"><i class="bi bi-credit-card"></i> Payment confirmed</span>
                <?php else: ?><span class="badge bg-secondary text-white p-2"><i class="bi bi-clock"></i> Pending</span><?php endif; ?>
                <p class="mt-2 small text-muted">Expires: <?php echo date('F j, Y', strtotime($expiry_date)); ?></p>
                <button class="btn btn-sm btn-outline-warning no-print" id="reminderBtn">Set renewal reminder</button>
            </div>
        </div>
    </div></div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const receiptData = {
    or_number: <?php echo json_encode($or_number); ?>,
    payment_method: <?php echo json_encode($payment_method); ?>,
    paid_at: <?php echo json_encode(date('M j, Y g:i A', strtotime($paid_at))); ?>,
    fire_safety: <?php echo json_encode(number_format($fireSafety, 2)); ?>,
    total_paid: <?php echo json_encode(number_format($amount_paid, 2)); ?>,
    business_name: <?php echo json_encode($app['business_name']); ?>,
    fee_items: <?php echo json_encode($feeLineItemsJs); ?>
};

function escapeHtml(str){if(str===undefined||str===null)return '';return String(str).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function numberToWords(num){
    const a=['','ONE ','TWO ','THREE ','FOUR ','FIVE ','SIX ','SEVEN ','EIGHT ','NINE ','TEN ','ELEVEN ','TWELVE ','THIRTEEN ','FOURTEEN ','FIFTEEN ','SIXTEEN ','SEVENTEEN ','EIGHTEEN ','NINETEEN '];
    const b=['','','TWENTY ','THIRTY ','FORTY ','FIFTY ','SIXTY ','SEVENTY ','EIGHTY ','NINETY '];
    let n=Math.floor(num),cents=Math.round((num-n)*100),str='';
    if(n===0)str='ZERO ';
    else{
        if(n>=1000000){str+=numberToWords(Math.floor(n/1000000)).replace(' PESOS ONLY','')+' MILLION ';n%=1000000;}
        if(n>=1000){str+=numberToWords(Math.floor(n/1000)).replace(' PESOS ONLY','')+' THOUSAND ';n%=1000;}
        if(n>=100){str+=a[Math.floor(n/100)]+'HUNDRED ';n%=100;}
        if(n>0){if(str!=='')str+='AND ';if(n<20)str+=a[n];else str+=b[Math.floor(n/10)]+a[n%10];}
    }
    str+='PESOS ';if(cents>0)str+='AND '+cents+' CENTAVOS ';str+='ONLY';return str;
}
function printReceipt(){
    let feeRows='';
    receiptData.fee_items.forEach(item=>{
        const amt=parseFloat(item[1])||0;
        feeRows+=`<tr><td>${escapeHtml(item[0]).toUpperCase()}</td><td style="text-align:center;"></td><td style="text-align:right;font-weight:bold;">P ${amt.toFixed(2)}</td></tr>`;
    });
    const fsAmt=parseFloat(receiptData.fire_safety)||0;
    if(fsAmt>0)feeRows+=`<tr><td>FIRE SAFETY INSPECTION FEE (FSIF)</td><td style="text-align:center;"></td><td style="text-align:right;font-weight:bold;">P ${fsAmt.toFixed(2)}</td></tr>`;
    let dateStr='__________';
    if(receiptData.paid_at){
        const d=new Date(receiptData.paid_at);
        if(!isNaN(d.getTime()))dateStr=String(d.getMonth()+1).padStart(2,'0')+String(d.getDate()).padStart(2,'0')+d.getFullYear();
        else dateStr=receiptData.paid_at;
    }
    const grandTotal=parseFloat(receiptData.total_paid)||0;
    const html=`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Official Receipt</title>
    <style>
    body{font-family:'Courier New',monospace;padding:20px;background:#fff;}
    .official-receipt-template{width:100%;max-width:750px;margin:0 auto;border:2px solid #000;padding:20px;}
    .header{display:flex;align-items:center;border-bottom:2px solid #000;padding-bottom:15px;margin-bottom:15px;}
    .header-logo{width:80px;margin-right:20px;text-align:center;}
    .header-text{flex:1;text-align:center;padding-right:80px;}
    .header-text h2{font-size:24px;font-weight:bold;margin:0;font-family:'Times New Roman',serif;letter-spacing:2px;}
    .header-text p{font-size:14px;margin:5px 0 0 0;font-family:'Times New Roman',serif;}
    .form-no{text-align:center;font-size:12px;margin-bottom:10px;border-bottom:1px solid #000;padding-bottom:5px;}
    .row{display:flex;border-bottom:1px solid #000;margin:0;}
    .row:last-child{border-bottom:none;}
    .col{flex:1;padding:5px 10px;border-right:1px solid #000;font-size:14px;display:flex;align-items:center;}
    .col:last-child{border-right:none;}
    .col.label{font-weight:bold;min-width:80px;border-right:none;padding-right:0;}
    .col.value{flex:2;font-weight:bold;}
    .table-container{border:1px solid #000;margin-top:15px;}
    table{width:100%;border-collapse:collapse;font-size:14px;}
    th{border:1px solid #000;padding:8px;text-align:left;font-weight:normal;font-size:13px;}
    td{border:1px solid #000;padding:5px 8px;height:25px;}
    .total-row td{border-top:2px solid #000;font-weight:bold;}
    .words-row td{font-weight:bold;text-align:center;font-size:13px;border-top:1px solid #000;padding:10px;}
    .bottom-section{display:flex;margin-top:15px;border:1px solid #000;}
    .payment-options{flex:1;padding:10px;border-right:1px solid #000;}
    .payment-options div{margin-bottom:15px;display:flex;align-items:center;font-size:14px;}
    .bank-details{flex:2;}
    .signature-block{display:flex;justify-content:flex-end;margin-top:30px;}
    .signature-line{width:250px;border-top:1px solid #000;text-align:center;padding-top:5px;font-weight:bold;font-size:14px;}
    .note-text{font-size:9px;text-align:center;margin-top:20px;color:#333;}
    @media print{body{padding:0;} .official-receipt-template{border:none;margin:0;}}
    </style></head><body>
    <div class="official-receipt-template">
        <div class="header">
            <div class="header-logo"><img src="img/official-logo.png" alt="Logo" style="max-height:100px;"></div>
            <div class="header-text"><h2>OFFICIAL RECEIPT</h2><p>Republic of the Philippines</p></div>
        </div>
        <div class="form-no"><span>Accountable Form No. 51</span><br><span>Revised January 1992</span></div>
        <div class="row" style="border-top:1px solid #000;">
            <div class="col"><span class="label">DATE</span><span class="value" style="margin-left:10px;">${dateStr}</span></div>
            <div class="col"><span class="label">NO.</span><span class="value" style="margin-left:10px;">${escapeHtml(receiptData.or_number)}</span></div>
        </div>
        <div class="row">
            <div class="col"><span class="label">AGENCY</span><span class="value" style="margin-left:10px;">TREASURY OFFICE</span></div>
            <div class="col"><span class="label">FUND</span><span class="value" style="margin-left:10px;">GENERAL FUND</span></div>
        </div>
        <div class="row"><div class="col"><span class="label">PAYOR</span><span class="value" style="margin-left:10px;">${escapeHtml(receiptData.business_name)}</span></div></div>
        <div class="table-container"><table>
            <thead><tr><th style="width:60%;">NATURE OF COLLECTION</th><th style="width:20%;text-align:center;">ACCOUNT CODE</th><th style="width:20%;text-align:right;">AMOUNT</th></tr></thead>
            <tbody>${feeRows}<tr><td>&nbsp;</td><td></td><td></td></tr><tr class="total-row"><td colspan="2" style="text-align:right;font-weight:bold;">TOTAL</td><td style="text-align:right;font-weight:bold;">P ${grandTotal.toFixed(2)}</td></tr><tr class="words-row"><td colspan="3">${numberToWords(grandTotal)}</td></tr></tbody>
        </table></div>
        <div class="bottom-section">
            <div class="payment-options"><div><input type="checkbox" checked> CASH</div><div><input type="checkbox"> CHECK</div><div><input type="checkbox"> MONEY ORDER</div></div>
            <div class="bank-details"><table><thead><tr><th>DRAWEE BANK</th><th>NUMBER</th><th>DATE</th></tr></thead><tbody><tr><td></td><td></td><td></td></tr></tbody></table></div>
        </div>
        <div style="margin-top:15px;font-size:12px;">Received the amount stated above</div>
        <div class="signature-block"><div class="signature-line">Collecting Officer</div></div>
        <div class="note-text">NOTE: Write the number and date of this receipt on the back of check or money order received.</div>
    </div>
    <script>window.onload=function(){window.print();}<\/script>
    </body></html>`;
    const win=window.open('','_blank');
    if(win){win.document.write(html);win.document.close();}
    else alert("Please allow pop-ups to generate the receipt.");
}
document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('downloadReceiptBtn')?.addEventListener('click',printReceipt);
    document.getElementById('copyRefBtn')?.addEventListener('click',function(){
        const t=document.getElementById('refNoCopy');t.select();t.setSelectionRange(0,99999);
        navigator.clipboard.writeText(t.value);alert('Reference number copied: '+t.value);
    });
    document.getElementById('reminderBtn')?.addEventListener('click',function(){
        alert('You will be notified 30 days before expiry. You can also renew early from your dashboard.');
    });
});
</script>
</body>
</html>