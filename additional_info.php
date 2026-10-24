<?php
session_start();
require_once 'includes/functions.php';
require_once 'config/connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

$appType = $_SESSION['application_type'] ?? 'new';
$isNew = ($appType === 'new');
$isRenewal = ($appType === 'renewal');

$application = getOrCreateDraftApplication($user_id);
$app_id = $application['id'];

// --- Validation: ensure steps 2,3,4,5 are complete ---
$missingStep = getMissingStep($app_id, $conn);
$currentStep = 6;
if ($missingStep > 0 && $missingStep < $currentStep) {
    $stepPages = [
        2 => 'application_type.php',
        3 => 'business_info.php',
        4 => 'business_address.php',
        5 => 'owner_info.php',
        6 => 'additional_info.php',
        7 => 'documents.php'
    ];
    if (isset($stepPages[$missingStep])) {
        header('Location: ' . $stepPages[$missingStep]);
        exit;
    }
}

// Load existing data from additional_info_data table
$existing = null;
$sql = "SELECT * FROM additional_info_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $app_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- Get business type/category from application_type_data (for display only) ---
$businessType = '';
$sql = "SELECT business_type FROM application_type_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $app_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $businessType = $row['business_type'];
}
$stmt->close();

// --- Philippine Holidays 2026 ---
$holidays2026 = [
    "2026-01-01", "2026-04-09", "2026-05-01", "2026-06-12", "2026-08-21",
    "2026-08-31", "2026-11-30", "2026-12-25", "2026-12-30",
    "2026-04-01", "2026-04-02", "2026-04-03",
    "2026-02-25", "2026-04-10", "2026-11-02", "2026-12-24", "2026-12-31"
];

function isWeekendOrHoliday($date, $holidays) {
    $timestamp = strtotime($date);
    $dayOfWeek = date('N', $timestamp);
    if ($dayOfWeek == 6 || $dayOfWeek == 7) return true;
    return in_array($date, $holidays);
}

function getNextValidDate($startDate, $holidays) {
    $next = strtotime($startDate);
    do {
        $next = strtotime('+1 day', $next);
        $nextDate = date('Y-m-d', $next);
    } while (isWeekendOrHoliday($nextDate, $holidays));
    return $nextDate;
}

function getOriginalApplicationId($renewalAppId, $conn) {
    $sql = "SELECT original_application_id FROM business_applications WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("i", $renewalAppId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['original_application_id'];
    }
    return null;
}

function generateApplicationNumber($referenceNo, $conn, $app_id = null) {
    if (!empty($referenceNo)) {
        return $referenceNo;
    }

    $year = date('Y');
    $month = date('m');
    $prefix = $year . '-' . $month . '-';

    $sql = "SELECT MAX(CAST(SUBSTRING_INDEX(reference_no, '-', -1) AS UNSIGNED)) as last_num
            FROM business_applications
            WHERE reference_no LIKE ? AND status != 'draft'";

    if ($app_id) {
        $sql .= " AND id != ?";
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $like = $prefix . '%';
        if ($app_id) {
            $stmt->bind_param("si", $like, $app_id);
        } else {
            $stmt->bind_param("s", $like);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $last = $row['last_num'] ?? 0;
        $next = $last + 1;
        $suffix = str_pad($next, 3, '0', STR_PAD_LEFT);
        return $prefix . $suffix;
    }

    $rand = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    return $year . '-' . $month . '-' . $rand;
}

$referenceNo = $application['reference_no'] ?? '';

if ($existing && !empty($existing['application_no_applicant'])) {
    $applicationNo = $existing['application_no_applicant'];
} else {
    if ($isRenewal) {
        if (!empty($referenceNo)) {
            $applicationNo = $referenceNo;
        } else {
            $originalAppId = getOriginalApplicationId($app_id, $conn);
            if ($originalAppId) {
                $sql = "SELECT reference_no FROM business_applications WHERE id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $originalAppId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $applicationNo = $row['reference_no'];
                    } else {
                        $applicationNo = generateApplicationNumber($referenceNo, $conn, $app_id);
                    }
                    $stmt->close();
                } else {
                    $applicationNo = generateApplicationNumber($referenceNo, $conn, $app_id);
                }
            } else {
                $applicationNo = generateApplicationNumber($referenceNo, $conn, $app_id);
            }
        }
    } else {
        $applicationNo = generateApplicationNumber($referenceNo, $conn, $app_id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'application_no' => trim($_POST['application_no'] ?? ''),
        'application_date' => $_POST['application_date'] ?? '',
        'owner_fullname' => trim($_POST['owner_fullname'] ?? ''),
        'business_name' => trim($_POST['business_name'] ?? ''),
        'floor_area' => floatval($_POST['floor_area'] ?? 0),
        'contact_no' => trim($_POST['contact_no'] ?? ''),
        'establishment_address' => trim($_POST['establishment_address'] ?? ''),
        'position_title' => trim($_POST['position_title'] ?? ''),
        'declaration_agreed' => isset($_POST['declaration']) ? 1 : 0
    ];

    $errors = [];
    if (empty($data['application_date'])) {
        $errors[] = 'Application date is required.';
    } else {
        if (isWeekendOrHoliday($data['application_date'], $holidays2026)) {
            $nextValid = getNextValidDate($data['application_date'], $holidays2026);
            $errors[] = 'The selected date is a weekend or holiday. The next available date is ' . date('F j, Y', strtotime($nextValid)) . '. Please choose that date.';
        }
    }
    if (empty($data['owner_fullname'])) $errors[] = 'Owner full name is required.';
    if (empty($data['business_name'])) $errors[] = 'Business name is required.';
    if (empty($data['contact_no'])) $errors[] = 'Contact number is required.';
    if (empty($data['establishment_address'])) $errors[] = 'Establishment address is required.';
    if (empty($data['position_title'])) $errors[] = 'Position / title is required.';
    if ($data['declaration_agreed'] != 1) $errors[] = 'You must agree to the declaration.';

    if (empty($errors)) {
        if (empty($data['application_no'])) {
            $data['application_no'] = $applicationNo;
        }

        if (saveAdditionalInfo($app_id, $data)) {
            updateApplicationStep($app_id, 7);
            header('Location: documents.php');
            exit;
        } else {
            $errorMsg = "Database error. Please try again.";
        }
    } else {
        $errorMsg = implode('<br>', $errors);
    }
}

$today = date('Y-m-d');
$defaultDate = $existing['application_date'] ?? $today;
if (isWeekendOrHoliday($defaultDate, $holidays2026)) {
    $defaultDate = getNextValidDate($defaultDate, $holidays2026);
}

// Sidebar steps
$steps = [
    1 => 'Requirements',
    2 => 'Application type',
    3 => 'Business info',
    4 => 'Business address',
    5 => 'Owner info',
    6 => 'Additional info',
    7 => 'Documents',
    8 => 'Review and submit'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Additional Information | <?php echo $isRenewal ? 'Renew' : 'New'; ?> Business Permit | Buyawanong Placer</title>
    
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

    <style>
        :root {
            --brand-dark: #1e293b;
            --brand-dark-hover: #0f172a;
            --brand-yellow: #fbbf24;
            --bg-light: #f8fafc;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --green: #10b981;
        }

        * { font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-light); color: var(--brand-dark); }

        /* Back link */
        .back-link {
            color: var(--brand-dark);
            text-decoration: underline;
            text-decoration-color: #cbd5e1;
            text-underline-offset: 3px;
            text-decoration-thickness: 1px;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        .back-link:hover {
            color: var(--brand-dark);
            text-decoration-color: var(--brand-dark);
        }

        /* Page title */
        .page-title {
            font-weight: 800;
            font-size: clamp(1.9rem, 4vw, 2.6rem);
            letter-spacing: -0.02em;
            line-height: 1.1;
            color: var(--brand-dark);
            margin-bottom: 6px;
        }
        .page-subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        /* Sidebar step list */
        .step-list { list-style: none; padding: 0; margin: 0; }
        .step-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 7px 0;
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        .step-list li .num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #ffffff;
            border: 1.5px solid var(--border-color);
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .step-list li.completed .num {
            background: var(--green);
            border-color: var(--green);
            color: #ffffff;
        }
        .step-list li.active {
            color: var(--brand-dark);
            font-weight: 700;
        }
        .step-list li.active .num {
            background: var(--brand-yellow);
            border-color: var(--brand-yellow);
            color: var(--brand-dark);
        }

        /* Section headings */
        .section-heading {
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--brand-dark);
            margin-bottom: 4px;
        }
        .section-subheading {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 22px;
        }

        /* Field labels */
        .field-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--brand-dark);
            margin-bottom: 6px;
            display: block;
        }
        .field-label .req { color: #ef4444; }

        /* Text inputs */
        .form-control {
            border-radius: 8px;
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            font-size: 0.9rem;
            color: var(--brand-dark);
            transition: all 0.15s ease;
        }
        .form-control:focus {
            background: #ffffff;
            border-color: var(--brand-dark);
            box-shadow: 0 0 0 3px rgba(30, 41, 59, 0.08);
        }
        .form-control::placeholder { color: #94a3b8; }
        .form-control[readonly] {
            background-color: #f1f5f9;
            color: var(--text-muted);
            cursor: not-allowed;
        }

        /* Note text */
        .field-note {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 8px;
            display: block;
        }

        /* Fire safety notice (yellow box) */
        .fire-notice {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
            padding: 14px 18px;
            font-size: 0.85rem;
            color: #78350f;
            margin: 24px 0;
            line-height: 1.5;
        }
        .fire-notice strong { color: #78350f; }

        /* Declaration */
        .declaration-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 22px;
        }
        .form-check-input {
            width: 18px;
            height: 18px;
            border: 1.5px solid var(--border-color);
            border-radius: 4px;
            margin-top: 0.15em;
        }
        .form-check-input:checked {
            background-color: var(--brand-dark);
            border-color: var(--brand-dark);
        }
        .form-check-label {
            font-size: 0.875rem;
            color: var(--brand-dark);
            margin-left: 6px;
            line-height: 1.55;
        }

        /* Back + Continue */
        .btn-back {
            background: #ffffff;
            color: var(--brand-dark);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 32px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .btn-back:hover {
            background: #f1f5f9;
            border-color: var(--brand-dark);
            color: var(--brand-dark);
        }
        .btn-continue {
            background-color: var(--brand-dark);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 12px 32px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .btn-continue:hover {
            background-color: var(--brand-dark-hover);
            color: #ffffff;
            transform: translateY(-2px);
        }

        .alert { border-radius: 8px; font-size: 0.9rem; }

        /* Date input with icon */
        .date-input-wrapper { position: relative; }
        .date-input-wrapper .date-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
            font-size: 1rem;
        }

        /* Responsive */
        @media (max-width: 991px) {
            .step-list { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 28px; }
            .step-list li { padding: 4px 10px 4px 4px; background: #ffffff; border: 1px solid var(--border-color); border-radius: 2rem; }
            .step-list li.completed { background: #ecfdf5; border-color: var(--green); }
            .step-list li.active { background: #fffbeb; border-color: var(--brand-yellow); }
            .step-list li span:not(.num) { font-size: 0.78rem; }
        }
        @media (max-width: 576px) {
            .btn-back, .btn-continue { width: 100%; }
        }
    </style>
</head>
<body>

<?php include 'header/nav.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 1080px;">

    <!-- Back link -->
    <div class="mb-3">
        <a href="dashboard.php" class="back-link">Back to dashboard</a>
    </div>

    <!-- Title -->
    <div class="mb-4">
        <h1 class="page-title">
            <?php echo $isRenewal ? 'Renew business permit application' : 'New business permit application'; ?>
        </h1>
        <p class="page-subtitle">Tax year 2026, Municipality of Placer</p>
    </div>

    <div class="row g-4 g-lg-5">

        <!-- LEFT: Sidebar -->
        <div class="col-lg-4 col-xl-3">
            <ul class="step-list">
                <?php foreach ($steps as $num => $label):
                    $cls = '';
                    if ($num < 6) $cls = 'completed';
                    elseif ($num === 6) $cls = 'active';
                ?>
                    <li class="<?php echo $cls; ?>">
                        <span class="num">
                            <?php if ($num < 6): ?>
                                <i class="bi bi-check-lg" style="font-size:0.7rem;"></i>
                            <?php else: ?>
                                <?php echo $num; ?>
                            <?php endif; ?>
                        </span>
                        <span><?php echo htmlspecialchars($label); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- RIGHT: Content -->
        <div class="col-lg-8 col-xl-9">

            <?php if (isset($errorMsg)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <?php echo $errorMsg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="additionalInfoForm">

                <!-- Heading -->
                <h2 class="section-heading">Additional information</h2>
                <p class="section-subheading">Fire station section. The owner fills this in.</p>

                <!-- Row 1: Application no + Date -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="applicationNo" class="field-label">Application no.</label>
                        <input type="text" class="form-control" id="applicationNo" name="application_no"
                               value="<?php echo htmlspecialchars($applicationNo); ?>"
                               placeholder="Auto-generated" readonly>
                    </div>
                    <div class="col-md-6">
                        <label for="applicationDate" class="field-label">Date <span class="req">*</span></label>
                        <div class="date-input-wrapper">
                            <input type="date" class="form-control" id="applicationDate" name="application_date"
                                   value="<?php echo $defaultDate; ?>"
                                   min="<?php echo $today; ?>" required>
                            <i class="bi bi-calendar3 date-icon"></i>
                        </div>
                    </div>
                </div>

                <!-- Row 2: Applicant name + Business name -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="ownerFullName" class="field-label">Applicant / owner name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="ownerFullName" name="owner_fullname"
                               value="<?php echo htmlspecialchars($existing['owner_fullname'] ?? ''); ?>"
                               placeholder="Full name" required>
                    </div>
                    <div class="col-md-6">
                        <label for="businessName" class="field-label">Business name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="businessName" name="business_name"
                               value="<?php echo htmlspecialchars($existing['business_name'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- Row 3: Floor area + Contact -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="totalFloorArea" class="field-label">Total floor area (sq.m.)</label>
                        <input type="number" class="form-control" id="totalFloorArea" name="floor_area"
                               placeholder="0"
                               value="<?php echo htmlspecialchars($existing['floor_area'] ?? 0); ?>"
                               step="any" min="0">
                    </div>
                    <div class="col-md-6">
                        <label for="contactNo" class="field-label">Contact no. <span class="req">*</span></label>
                        <input type="tel" class="form-control" id="contactNo" name="contact_no"
                               placeholder="+63"
                               value="<?php echo htmlspecialchars($existing['contact_no'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- Establishment address (full width) -->
                <div class="mb-3">
                    <label for="establishmentAddress" class="field-label">Establishment address <span class="req">*</span></label>
                    <input type="text" class="form-control" id="establishmentAddress" name="establishment_address"
                           value="<?php echo htmlspecialchars($existing['establishment_address'] ?? ''); ?>" required>
                </div>

                <!-- Note -->
                <span class="field-note">The application number is final once you submit. Weekends and holidays are not allowed as dates.</span>

                <!-- Fire safety notice -->
                <div class="fire-notice">
                    <strong>Fire safety notice.</strong>
                    Under Section 12 of the IRR of the Fire Code of 2008, some establishments pay extra charges besides the inspection fee.
                    These are collected during inspection or announced by the BFP.
                </div>

                <!-- Declaration -->
                <div class="declaration-box">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="declarationCheckbox" name="declaration" value="1"
                               <?php echo ($existing && $existing['declaration_agreed']) ? 'checked' : ''; ?> required>
                        <label class="form-check-label" for="declarationCheckbox">
                            I declare under penalty of perjury that this information is true. I will fix any deficiencies within 30 days of the permit release.
                        </label>
                    </div>
                </div>

                <!-- Position / title -->
                <div class="mb-4">
                    <label for="positionTitle" class="field-label">Position / title <span class="req">*</span></label>
                    <input type="text" class="form-control" id="positionTitle" name="position_title"
                           value="<?php echo htmlspecialchars($existing['position_title'] ?? ''); ?>"
                           placeholder="e.g. Owner, Manager, Representative" required>
                </div>

                <!-- Back + Continue -->
                <div class="d-flex justify-content-between mt-5 flex-wrap gap-2">
                    <button type="button" class="btn-back" id="backBtn">Back</button>
                    <button type="submit" class="btn-continue" id="continueBtn">Continue</button>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- Footer -->
<footer class="border-top py-4 mt-5">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <small class="text-muted">© <?php echo date('Y'); ?> Municipality of Placer, Surigao del Norte.</small>
        <div>
            <a href="#" class="text-decoration-none text-muted small me-3">Privacy Policy</a>
            <a href="#" class="text-decoration-none text-muted small">Contact Us</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    /* ============================================================
       PHILIPPINE HOLIDAYS 2026
       ============================================================ */
    const holidays2026 = [
        "2026-01-01", "2026-04-09", "2026-05-01", "2026-06-12", "2026-08-21",
        "2026-08-31", "2026-11-30", "2026-12-25", "2026-12-30",
        "2026-04-01", "2026-04-02", "2026-04-03",
        "2026-02-25", "2026-04-10", "2026-11-02", "2026-12-24", "2026-12-31"
    ];

    function isWeekendOrHoliday(dateString) {
        const date = new Date(dateString);
        const day = date.getDay();
        if (day === 0 || day === 6) return true;
        return holidays2026.includes(dateString);
    }

    function getNextValidDate(startDateStr) {
        let nextDate = new Date(startDateStr);
        do {
            nextDate.setDate(nextDate.getDate() + 1);
            const nextStr = nextDate.toISOString().split('T')[0];
            if (!isWeekendOrHoliday(nextStr)) return nextStr;
        } while (true);
    }

    /* ============================================================
       DATE FORMAT FOR DISPLAY (MM/DD/YYYY)
       ============================================================ */
    // Ensure the date input shows mm/dd/yyyy format (native browser behavior varies)
    // No override needed — the browser handles it.

    /* ============================================================
       BACK BUTTON
       ============================================================ */
    document.getElementById('backBtn').addEventListener('click', function () {
        window.location.href = 'owner_info.php';
    });

    /* ============================================================
       CLIENT-SIDE VALIDATION
       ============================================================ */
    document.getElementById('additionalInfoForm').addEventListener('submit', async function (e) {
        const appDateInput = document.getElementById('applicationDate');
        let appDate = appDateInput.value;
        const today = new Date().toISOString().split('T')[0];

        const ownerFullName = document.getElementById('ownerFullName').value.trim();
        const businessName = document.getElementById('businessName').value.trim();
        const contactNo = document.getElementById('contactNo').value.trim();
        const establishmentAddress = document.getElementById('establishmentAddress').value.trim();
        const declarationChecked = document.getElementById('declarationCheckbox').checked;
        const positionTitle = document.getElementById('positionTitle').value.trim();

        if (!appDate || !ownerFullName || !businessName || !contactNo || !establishmentAddress || !positionTitle || !declarationChecked) {
            e.preventDefault();
            await Swal.fire({
                icon: 'warning',
                title: 'Missing fields',
                text: 'Please fill in all required fields and agree to the declaration.',
                confirmButtonColor: '#1e293b'
            });
            return;
        }

        if (appDate < today) {
            e.preventDefault();
            await Swal.fire({
                icon: 'warning',
                title: 'Past date not allowed',
                text: 'Please select today or a future date.',
                confirmButtonColor: '#1e293b'
            });
            return;
        }

        if (isWeekendOrHoliday(appDate)) {
            e.preventDefault();
            const nextValid = getNextValidDate(appDate);
            const result = await Swal.fire({
                icon: 'warning',
                title: 'Weekend or holiday',
                text: `${appDate} is a weekend or holiday. The next available date is ${nextValid}. Use that date instead?`,
                showCancelButton: true,
                confirmButtonColor: '#1e293b',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, use that date',
                cancelButtonText: 'Cancel'
            });
            if (result.isConfirmed) {
                appDateInput.value = nextValid;
                document.getElementById('additionalInfoForm').submit();
            }
            return;
        }
    });
</script>

</body>
</html>