<?php
session_start();

// Restrict to user_type = 3 only
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 3) {
    $_SESSION['error'] = 'Access denied. Only treasury staff can access this page.';
    header('Location: index.php');
    exit;
}

require_once '../config/connect.php';

$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($application_id <= 0) {
    die('Invalid application ID.');
}

// Fetch application data
$app = [];
$sql = "SELECT * FROM business_applications WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    die('Application not found.');
}
$app = $result->fetch_assoc();
$stmt->close();

// Check if user_type 3 can view this (only pending_payment)
if (!in_array(strtolower($app['status']), ['pending_payment', 'payment_confirmed', 'verified', 'approved', 'claimed', 'renewed'])) {
    die('Access denied. You can only view pending payment or payment confirmed applications.');
}

// Fetch business info
$business_info = [];
$sql = "SELECT * FROM business_info_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $business_info = $row;
}
$stmt->close();

// Fetch owner info
$owner_info = [];
$sql = "SELECT * FROM owner_info_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $owner_info = $row;
}
$stmt->close();

// Fetch application type
$app_type = [];
$sql = "SELECT * FROM application_type_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $app_type = $row;
}
$stmt->close();

// Fetch address data
$address_data = [];
$sql = "SELECT * FROM business_address_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $address_data = $row;
}
$stmt->close();

// Fetch additional info
$additional_info = [];
$sql = "SELECT * FROM additional_info_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $additional_info = $row;
}
$stmt->close();

// Fetch fee assessment
$assessment = null;
$sql = "SELECT * FROM fee_assessments WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $assessment = $row;
}
$stmt->close();

// Fetch rejection details (if any)
$rejection = null;
$sql = "SELECT missing_documents, notes FROM application_rejections WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $rejection = $row;
}
$stmt->close();

function val($data, $key, $default = '') {
    return isset($data[$key]) ? $data[$key] : $default;
}

function fmtDate($date) {
    return $date ? date('M d, Y', strtotime($date)) : '';
}

// Parse business activities
$activities = [];
if (!empty($business_info['business_activities'])) {
    $decoded = json_decode($business_info['business_activities'], true);
    if (is_array($decoded)) {
        if (isset($decoded['line_of_business'])) {
            $activities[] = $decoded;
        } else {
            $activities = $decoded;
        }
    }
}

// Get status badge class
function getStatusBadgeClass($status) {
    $s = strtolower($status);
    if ($s === 'submitted') return 'badge-pending';
    if ($s === 'under_review') return 'badge-review';
    if ($s === 'pending_payment') return 'badge-warning';
    if ($s === 'payment_confirmed') return 'badge-info';
    if ($s === 'approved' || $s === 'issued' || $s === 'verified' || $s === 'claimed' || $s === 'renewed') return 'badge-success';
    if ($s === 'rejected' || $s === 'cancelled') return 'badge-danger';
    return 'badge-secondary';
}

function getStatusLabel($status) {
    $map = [
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'pending_payment' => 'Pending Payment',
        'payment_confirmed' => 'Payment Confirmed',
        'approved' => 'Approved',
        'issued' => 'Issued',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'verified' => 'Verified',
        'claimed' => 'Claimed',
        'renewed' => 'Renewed'
    ];
    $s = strtolower($status);
    return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
}

// --------------------------------------------------------------
// EMPLOYEE COUNT RESOLVER
// --------------------------------------------------------------
$totalEmployees    = null;
$maleEmployees     = null;
$femaleEmployees   = null;
$residingEmployees = null;

if (isset($address_data['total_employees']) && $address_data['total_employees'] !== '' && $address_data['total_employees'] !== null) {
    $totalEmployees = (int)$address_data['total_employees'];
}
if (isset($address_data['employees_residing']) && $address_data['employees_residing'] !== '' && $address_data['employees_residing'] !== null) {
    $residingEmployees = (int)$address_data['employees_residing'];
}

$sources = [$address_data, $additional_info, $owner_info, $app_type, $activities[0] ?? []];

$maleKeys   = ['male_employees', 'no_male_employees', 'male_count', 'employees_male', 'male'];
$femaleKeys = ['female_employees', 'no_female_employees', 'female_count', 'employees_female', 'female'];

foreach ($sources as $src) {
    if (!is_array($src)) continue;
    if ($maleEmployees === null) {
        foreach ($maleKeys as $k) {
            if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null) {
                $maleEmployees = (int)$src[$k];
                break;
            }
        }
    }
    if ($femaleEmployees === null) {
        foreach ($femaleKeys as $k) {
            if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null) {
                $femaleEmployees = (int)$src[$k];
                break;
            }
        }
    }
    if ($totalEmployees === null) {
        foreach (['total_employees','no_of_employees','num_employees','employees_total'] as $k) {
            if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null) {
                $totalEmployees = (int)$src[$k];
                break;
            }
        }
    }
}

if ($totalEmployees === null && ($maleEmployees !== null || $femaleEmployees !== null)) {
    $totalEmployees = (int)($maleEmployees ?? 0) + (int)($femaleEmployees ?? 0);
}

$totalEmployees    = $totalEmployees    ?? 0;
$maleEmployees     = $maleEmployees     ?? 0;
$femaleEmployees   = $femaleEmployees   ?? 0;
$residingEmployees = $residingEmployees ?? 0;

$hasGenderBreakdown = ($maleEmployees > 0 || $femaleEmployees > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treasury Record - Application #<?php echo $application_id; ?></title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f0f4f8; padding: 20px; }

        .record-container { max-width: 1000px; margin: 0 auto; }

        .action-bar {
            background: white; border-radius: 12px; padding: 15px 20px; margin-bottom: 20px;
            display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
            justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .record-wrapper {
            background: white; border-radius: 12px; padding: 30px 35px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }

        .header { text-align: center; font-size: 12px; font-weight: bold; line-height: 1.6; margin-bottom: 12px; }
        .header .country { font-size: 14px; letter-spacing: 1px; }
        .header .province { font-size: 13px; }
        .header .municipality { font-size: 13px; margin-bottom: 4px; }

        .divider { border-top: 1px solid #333; margin: 6px 0 8px 0; }
        .divider-thick { border-top: 2px solid #333; margin: 6px 0 8px 0; }

        .form-row { display: flex; flex-wrap: wrap; align-items: baseline; margin-bottom: 3px; font-size: 11px; }
        .form-row .label { font-weight: bold; flex-shrink: 0; }
        .form-row .value { border-bottom: 1px solid #333; padding: 0 4px; min-height: 20px; flex: 1; min-width: 40px; }

        .form-row .checkbox-group { display: flex; align-items: center; gap: 2px; margin-right: 10px; }
        .form-row .checkbox-group .box {
            display: inline-block; width: 16px; height: 16px; border: 1px solid #333;
            text-align: center; font-size: 12px; font-weight: bold; line-height: 16px;
            background: white; margin-right: 2px;
        }
        .form-row .checkbox-group .box.filled { background: #333; color: white; }

        .data-table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 4px 0; }
        .data-table th, .data-table td { border: 1px solid #333; padding: 4px 5px; text-align: left; }
        .data-table th { text-align: center; font-weight: bold; background: #f5f5f5; }
        .data-table td.right { text-align: right; }
        .data-table td.center { text-align: center; }
        .data-table td.totals-cell { text-align: right; font-weight: bold; font-size: 12px; }

        .declaration {
            font-size: 9px; font-style: italic; margin: 6px 0 8px 0;
            padding: 4px 6px; border: 1px solid #ccc; background: #fafafa;
        }

        .badge-status { padding: 0.3rem 0.8rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 600; }
        .badge-pending { background: #fff3e0; color: #cc7b00; }
        .badge-review { background: #e2e3ff; color: #4339ca; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #f8d7da; color: #b02a37; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }

        .btn-secondary-custom {
            border-radius: 2rem; padding: 0.5rem 1.5rem;
            border: 1px solid #1e4b2e; color: #1e4b2e; background: transparent;
            text-decoration: none; display: inline-block;
        }
        .btn-secondary-custom:hover { background: #1e4b2e; color: white; }

        .flex { display: flex; flex-wrap: wrap; align-items: baseline; gap: 2px 4px; }
        .flex-center { align-items: center; }
        .gap-1 { gap: 2px 8px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }

        @media (max-width: 768px) {
            .action-bar { flex-direction: column; align-items: stretch; }
            .action-bar > div { text-align: center; }
            .action-bar .btn { width: 100%; margin-bottom: 5px; }
            .record-wrapper { padding: 15px; }
            .form-row { font-size: 10px; }
            .data-table { font-size: 8px; }
            .data-table th, .data-table td { padding: 2px 3px; }
        }

        @media print {
            body { background: white; padding: 10px; }
            .action-bar { display: none; }
            .record-wrapper { box-shadow: none; padding: 10px; }
            .btn, .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="record-container">

    <!-- Action Bar -->
    <div class="action-bar">
        <div>
            <h4 class="fw-bold" style="color: #1e4b2e; display: inline;">
                <i class="bi bi-file-earmark-text"></i> Treasury Record #<?php echo $application_id; ?>
            </h4>
            <span class="badge-status <?php echo getStatusBadgeClass($app['status']); ?> ms-2">
                <?php echo getStatusLabel($app['status']); ?>
            </span>
            <div class="mt-1">
                <small class="text-muted">Ref: <?php echo htmlspecialchars($app['reference_no']); ?></small>
                <small class="text-muted ms-3">Filed: <?php echo fmtDate($app['application_date']); ?></small>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center no-print">
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
            <!--<a href="dashboard_treasury.php" class="btn btn-secondary-custom">-->
            <!--    <i class="bi bi-arrow-left"></i> Back-->
            <!--</a>-->
        </div>
    </div>

    <!-- Main Record -->
    <div class="record-wrapper">

        <!-- HEADER -->
        <div class="header">
            <div class="country">Republic of the Philippines</div>
            <div class="province">Province of Surigao del Norte</div>
            <div class="municipality">Municipality of Placer</div>
        </div>

        <div class="divider-thick"></div>

        <!-- TOP SECTION -->
        <div class="form-row">
            <span class="label" style="width:140px;">Business/Trade Name:</span>
            <span class="value" style="width:200px;"><?php echo htmlspecialchars(val($business_info, 'business_name', 'N/A')); ?></span>
            <span class="label" style="width:90px; margin-left:10px;">LESSOR TAX:</span>
            <span class="value" style="width:100px;"><?php echo htmlspecialchars(val($address_data, 'lessor_name', 'N/A')); ?></span>
        </div>

        <div class="form-row">
            <span class="label" style="width:100px;">Owners Name:</span>
            <span class="value" style="width:200px;"><?php echo htmlspecialchars(val($owner_info, 'first_name', '') . ' ' . val($owner_info, 'last_name', '')); ?></span>
            <span class="label" style="width:70px; margin-left:10px;">Address:</span>
            <span class="value" style="width:180px;"><?php echo htmlspecialchars(val($owner_info, 'owner_address', 'N/A')); ?></span>
            <span class="label" style="width:80px; margin-left:10px;">Contact No.:</span>
            <span class="value" style="width:80px;"><?php echo htmlspecialchars(val($owner_info, 'mobile', 'N/A')); ?></span>
        </div>

        <!-- TYPE OF APPLICATION -->
        <div class="form-row flex flex-center gap-1">
            <span class="label">Type of Application:</span>
            <?php $appType = val($app_type, 'application_type', ''); ?>
            <span class="checkbox-group"><span class="box <?php echo strcasecmp($appType, 'New') === 0 ? 'filled' : ''; ?>"><?php echo strcasecmp($appType, 'New') === 0 ? '✓' : ''; ?></span> New</span>
            <span class="checkbox-group"><span class="box <?php echo strcasecmp($appType, 'Renewal') === 0 ? 'filled' : ''; ?>"><?php echo strcasecmp($appType, 'Renewal') === 0 ? '✓' : ''; ?></span> Renewal</span>
            <?php $paymentMode = val($app_type, 'payment_mode', ''); ?>
            <span class="checkbox-group"><span class="box <?php echo $paymentMode == 'Annually' ? 'filled' : ''; ?>"><?php echo $paymentMode == 'Annually' ? '✓' : ''; ?></span> Annual</span>
            <span class="checkbox-group"><span class="box <?php echo $paymentMode == 'Quarterly' ? 'filled' : ''; ?>"><?php echo $paymentMode == 'Quarterly' ? '✓' : ''; ?></span> Quarterly</span>
        </div>

        <!-- PAYMENT TERM & LOT OWNERSHIP -->
        <div class="form-row flex flex-center gap-1">
            <span class="label">Payment Term:</span>
            <span class="value" style="width:100px;"><?php echo htmlspecialchars(val($app_type, 'payment_mode', 'N/A')); ?></span>
            <span class="label" style="margin-left:10px;">Lot Ownership:</span>
            <?php $lotOwnership = val($address_data, 'lot_ownership', ''); ?>
            <span class="checkbox-group"><span class="box <?php echo $lotOwnership == 'Yes' ? 'filled' : ''; ?>"><?php echo $lotOwnership == 'Yes' ? '✓' : ''; ?></span> Yes</span>
            <span class="checkbox-group"><span class="box <?php echo $lotOwnership == 'No' ? 'filled' : ''; ?>"><?php echo $lotOwnership == 'No' ? '✓' : ''; ?></span> No</span>
        </div>

        <!-- TOTAL EMPLOYEES -->
        <div class="form-row flex flex-center gap-1">
            <span class="label">Total No. Of Employees:</span>
            <span class="value" style="width:60px;"><?php echo $totalEmployees; ?></span>
            <?php if ($hasGenderBreakdown): ?>
                <span style="margin-left:8px;">Male:</span>
                <span class="value" style="width:40px;"><?php echo $maleEmployees; ?></span>
                <span style="margin-left:8px;">Female:</span>
                <span class="value" style="width:40px;"><?php echo $femaleEmployees; ?></span>
                <span style="margin-left:8px;">Total:</span>
                <span class="value" style="width:40px;"><?php echo $totalEmployees; ?></span>
            <?php endif; ?>
            <?php if ($residingEmployees > 0): ?>
                <span style="margin-left:8px;">Residing in LGU:</span>
                <span class="value" style="width:40px;"><?php echo $residingEmployees; ?></span>
            <?php endif; ?>
        </div>

        <div class="divider"></div>

        <!-- BUSINESS NAME SECTION -->
        <div class="text-center" style="font-size:13px; font-weight:bold; margin:6px 0;">
            Republic of the Philippines
        </div>
        <div class="text-center" style="font-size:12px; font-weight:bold; margin:2px 0;">
            <?php echo htmlspecialchars(strtoupper(val($business_info, 'business_name', 'BUSINESS NAME'))); ?>
        </div>
        <div class="text-center" style="font-size:11px; margin:2px 0 8px 0;">
            Deposits
        </div>

        <?php
        // ============================================================
        // PREPARE FEE VALUES (used by the table below)
        // ============================================================
        $appTypeRaw = strtolower(trim(val($app_type, 'application_type', '')));
        if ($appTypeRaw === '') {
            $appTypeRaw = strtolower(trim($app['application_type'] ?? 'new'));
        }
        $isRenewalRecord = ($appTypeRaw === 'renewal');

        // Assessment year comes from tax_year, fall back to current year
        $taxYear          = (int)($app['tax_year'] ?? date('Y'));
        $prevYear         = $taxYear - 1;
        $latePaymentLabel = 'Late Payment ' . $taxYear;

        // Numeric helpers
        $getFee = function ($key) use ($assessment) {
            if (!is_array($assessment)) return 0.0;
            if (!isset($assessment[$key])) return 0.0;
            if ($assessment[$key] === '' || $assessment[$key] === null) return 0.0;
            return (float)$assessment[$key];
        };

        $mayorsPermit      = $getFee('mayors_permit_fee');
        $grossSalesTax     = $getFee('gross_sales_tax');
        $permitOnCalling   = $getFee('permit_on_calling');
        $healthCertificate = $getFee('health_certificate');
        $sanitaryInspection= $getFee('sanitary_inspection_fee');
        $sanitaryGarbage   = $getFee('garbage_charges');
        $weightMeasure     = $getFee('weight_measure');
        $miscFee           = $getFee('misc_fee');
        $othersFee         = $getFee('others');
        $surchargeFee      = $getFee('surcharge_fee');
        $interestFee       = $getFee('interest_fee');
        $latePayment       = $getFee('late_payment');
        $penaltyLostPermit = $getFee('penalty_lost_permit');
        $fireSafety        = $getFee('fire_safety_fee_percent');

        // Grand total = sum of every populated fee
        $totalAmount = 0.0;
        foreach ([
            $mayorsPermit, $grossSalesTax, $permitOnCalling, $healthCertificate,
            $sanitaryInspection, $sanitaryGarbage, $weightMeasure, $miscFee,
            $othersFee, $surchargeFee, $interestFee, $latePayment, $fireSafety
        ] as $v) { $totalAmount += $v; }

        if ($isRenewalRecord) {
            $totalAmount += $penaltyLostPermit;
        }

        // Fallback — no populated fees at all → use stored total
        $anyFeePopulated = ($totalAmount > 0);
        if (!$anyFeePopulated) {
            $storedTotal = (float)($assessment['total_amount'] ?? 0);
            $totalAmount = $storedTotal;
        }

        // Row renderer: prints either amount or "-"
        $renderAmount = function ($v) {
            return $v > 0 ? number_format($v, 2) : '-';
        };
        ?>

        <!-- ============================================================ -->
        <!-- LOCAL TAXES TABLE — matches the LGU printed form            -->
        <!-- 3 rows each for Mayor's Permit + Gross Sales Tax            -->
        <!-- ============================================================ -->
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:40%;">Local Taxes</th>
                    <th style="width:30%;">Particular</th>
                    <th style="width:30%; text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <!-- === Mayor's Permits (3 rows) === -->
                <tr>
                    <td>Mayors Permits</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($mayorsPermit); ?></td>
                </tr>
                <tr>
                    <td>Mayors Permits</td>
                    <td class="center">-</td>
                    <td class="right">-</td>
                </tr>
                <tr>
                    <td>Mayors Permits</td>
                    <td class="center">-</td>
                    <td class="right">-</td>
                </tr>

                <!-- === Gross Sales Tax Computed (3 rows) === -->
                <tr>
                    <td>Gross Sales Tax Computed</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($grossSalesTax); ?></td>
                </tr>
                <tr>
                    <td>Gross Sales Tax Computed</td>
                    <td class="center">-</td>
                    <td class="right">-</td>
                </tr>
                <tr>
                    <td>Gross Sales Tax Computed</td>
                    <td class="center">-</td>
                    <td class="right">-</td>
                </tr>

                <!-- === Single-row fees === -->
                <tr>
                    <td>Permit on Calling</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($permitOnCalling); ?></td>
                </tr>
                <tr>
                    <td>Health Certificate</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($healthCertificate); ?></td>
                </tr>
                <tr>
                    <td>Sanitary Inspection Fee</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($sanitaryInspection); ?></td>
                </tr>
                <tr>
                    <td>Sanitary Garbage Fee</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($sanitaryGarbage); ?></td>
                </tr>
                <tr>
                    <td>Weight &amp; Measure</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($weightMeasure); ?></td>
                </tr>
                <tr>
                    <td>Misc. Fee</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($miscFee); ?></td>
                </tr>
                <tr>
                    <td>Surcharge Fee</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($surchargeFee); ?></td>
                </tr>
                <tr>
                    <td>Interest Fee</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($interestFee); ?></td>
                </tr>
                <tr>
                    <td><?php echo htmlspecialchars($latePaymentLabel); ?></td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($latePayment); ?></td>
                </tr>

                <?php if ($isRenewalRecord): ?>
                <tr>
                    <td>Penalty (Lost Business Permit)</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo $renderAmount($penaltyLostPermit); ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($fireSafety > 0): ?>
                <tr>
                    <td>Fire Safety Inspection Fee (FSIF)</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo number_format($fireSafety, 2); ?></td>
                </tr>
                <?php endif; ?>

                <?php if (!$anyFeePopulated && isset($storedTotal)): ?>
                <tr>
                    <td>Business Permit Fee (from stored total)</td>
                    <td class="center">-</td>
                    <td class="right"><?php echo number_format($storedTotal, 2); ?></td>
                </tr>
                <?php endif; ?>

                <!-- === TOTAL ROW === -->
                <tr style="background:#f5f5f5;">
                    <td colspan="2" style="text-align:right; font-weight:bold; font-size:12px;">TOTAL</td>
                    <td class="right totals-cell"><?php echo number_format($totalAmount, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- CEDULA -->
        <div class="form-row" style="margin-top:6px;">
            <span class="label" style="width:80px;">CEDULA:</span>
            <span class="value" style="width:200px;"><?php echo htmlspecialchars(val($app_type, 'cedula_no', 'N/A')); ?></span>
        </div>

        <!-- SIGNATURE & DECLARATION -->
        <div class="form-row" style="margin-top:6px;">
            <span class="label" style="width:250px;">Printed Name and Signature of Applicant/Representative:</span>
            <span class="value" style="width:200px;"><?php echo htmlspecialchars(val($owner_info, 'first_name', '') . ' ' . val($owner_info, 'last_name', '')); ?></span>
        </div>

        <div class="declaration">
            I DECLARE that all information in this application are true and correct based on my personal knowledge and authentic records reported to the LSPC Placer.
        </div>

        <!-- ============================================================ -->
        <!-- QUARTERLY PAYMENT TABLE - BLANKED (fill in manually)         -->
        <!-- ============================================================ -->
        <table class="data-table" style="font-size:9px;">
            <thead>
                <tr>
                    <th>ANNUAL/1ST QUARTER</th>
                    <th>2ND QUARTER</th>
                    <th>3RD QUARTER</th>
                    <th>4TH QUARTER</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Qtr Payment: ________</td>
                    <td>Qtr Payment: ________</td>
                    <td>Qtr Payment: ________</td>
                    <td>Qtr Payment: ________</td>
                </tr>
                <tr>
                    <td>Surcharge/Interest: ________</td>
                    <td>Surcharge/Interest: ________</td>
                    <td>Surcharge/Interest: ________</td>
                    <td>Surcharge/Interest: ________</td>
                </tr>
                <tr>
                    <td><strong>Total: ________</strong></td>
                    <td><strong>Total: ________</strong></td>
                    <td><strong>Total: ________</strong></td>
                    <td><strong>Total: ________</strong></td>
                </tr>
                <tr>
                    <td>OR Number: ________</td>
                    <td>OR Number: ________</td>
                    <td>OR Number: ________</td>
                    <td>OR Number: ________</td>
                </tr>
                <tr>
                    <td>Date: ________</td>
                    <td>Date: ________</td>
                    <td>Date: ________</td>
                    <td>Date: ________</td>
                </tr>
            </tbody>
        </table>

        <!-- FOOTER -->
        <div style="text-align:center; font-size:8px; color:#999; margin-top:12px; border-top:1px solid #ddd; padding-top:6px;">
            LSPC Placer · Treasury Record
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>