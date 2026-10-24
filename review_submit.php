<?php
session_start();
require_once 'config/connect.php';
require_once 'includes/functions.php';

// Enable error logging (not display)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/review_submit_errors.log');

if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

$application = getOrCreateDraftApplication($user_id);
$app_id = $application['id'];

// --- Validation: ensure all steps 2-7 are complete ---
if (function_exists('getMissingStep')) {
    $missingStep = getMissingStep($app_id, $conn);
    if ($missingStep > 0) {
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
}

// Load all related data for summary display
$typeData       = $conn->query("SELECT * FROM application_type_data WHERE application_id = $app_id")->fetch_assoc();
$businessInfo   = $conn->query("SELECT * FROM business_info_data WHERE application_id = $app_id")->fetch_assoc();
$addressData    = $conn->query("SELECT * FROM business_address_data WHERE application_id = $app_id")->fetch_assoc();
$ownerData      = $conn->query("SELECT * FROM owner_info_data WHERE application_id = $app_id")->fetch_assoc();
$additionalData = $conn->query("SELECT * FROM additional_info_data WHERE application_id = $app_id")->fetch_assoc();

$docsCount = $conn->query("SELECT COUNT(*) as cnt FROM document_uploads WHERE application_id = $app_id")->fetch_assoc()['cnt'] ?? 0;

// Prepare summary — values fall back to 'Not provided' when empty
function showOrNotProvided($value) {
    return (isset($value) && trim((string)$value) !== '') ? htmlspecialchars($value) : 'Not provided';
}

$applicantName = trim(($ownerData['first_name'] ?? '') . ' ' . ($ownerData['last_name'] ?? ''));

$summary = [
    'Application type'   => showOrNotProvided($typeData['application_type'] ?? 'New'),
    'Mode of payment'    => showOrNotProvided($typeData['payment_mode'] ?? ''),
    'Business name'      => showOrNotProvided($businessInfo['business_name'] ?? ''),
    'Type of business'   => showOrNotProvided($typeData['business_type'] ?? ''),
    'Business address'   => showOrNotProvided($addressData['business_address'] ?? ''),
    'Applicant'          => showOrNotProvided($applicantName),
    'Documents uploaded' => $docsCount . ' of 7',
];

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = null;

    try {
        // --------------------------------------------------------------
        // 1. Ensure the required columns exist
        // --------------------------------------------------------------
        $colCheck = $conn->query("SHOW COLUMNS FROM business_applications LIKE 'application_no'");
        if ($colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE business_applications ADD COLUMN application_no INT NULL");
        }

        $colCheck = $conn->query("SHOW COLUMNS FROM business_applications LIKE 'renewed_from_id'");
        if ($colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE business_applications ADD COLUMN renewed_from_id INT NULL");
        }

        $colCheck = $conn->query("SHOW COLUMNS FROM business_applications LIKE 'expiry_date'");
        if ($colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE business_applications ADD COLUMN expiry_date DATE NULL");
        }

        $colCheck = $conn->query("SHOW COLUMNS FROM business_applications LIKE 'issued_at'");
        if ($colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE business_applications ADD COLUMN issued_at DATETIME NULL");
        }

        // --------------------------------------------------------------
        // 2. Ensure the 'status' column can accept 'submitted' and 'renewed'
        // --------------------------------------------------------------
        $colInfo = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                                  WHERE TABLE_NAME='business_applications' AND COLUMN_NAME='status'");
        if ($colInfo && $row = $colInfo->fetch_assoc()) {
            $colType = $row['COLUMN_TYPE'];
            $isEnum = strpos($colType, 'enum') !== false;
            if ($isEnum) {
                preg_match("/^enum\((.*)\)$/", $colType, $matches);
                if (isset($matches[1])) {
                    $values = array_map(function($v) { return trim($v, "'"); }, explode(',', $matches[1]));
                    $needsUpdate = false;
                    if (!in_array('submitted', $values)) { $values[] = 'submitted'; $needsUpdate = true; }
                    if (!in_array('renewed', $values))   { $values[] = 'renewed';   $needsUpdate = true; }
                    if (!in_array('verified', $values))  { $values[] = 'verified';  $needsUpdate = true; }
                    if ($needsUpdate) {
                        $newEnum = "enum('" . implode("','", $values) . "')";
                        $alterSQL = "ALTER TABLE business_applications MODIFY COLUMN status $newEnum DEFAULT 'draft'";
                        if (!$conn->query($alterSQL)) {
                            throw new Exception("Failed to alter status column: " . $conn->error);
                        }
                    }
                }
            } else {
                $alterSQL = "ALTER TABLE business_applications MODIFY COLUMN status VARCHAR(50) DEFAULT 'draft'";
                if (!$conn->query($alterSQL)) {
                    throw new Exception("Failed to alter status column: " . $conn->error);
                }
            }
        }

        // --------------------------------------------------------------
        // 3. Start transaction
        // --------------------------------------------------------------
        $conn->begin_transaction();

        // --------------------------------------------------------------
        // 4. Assign reference number (handles new vs renewal)
        // --------------------------------------------------------------
        if (!function_exists('assignReferenceNumber')) {
            throw new Exception("Helper function 'assignReferenceNumber' is missing. Please add it to includes/functions.php.");
        }

        $refNo = assignReferenceNumber($app_id, $conn);
        if (!$refNo) {
            throw new Exception("assignReferenceNumber returned null.");
        }

        // --------------------------------------------------------------
        // 5. Set application_no = id
        // --------------------------------------------------------------
        $stmt = $conn->prepare("UPDATE business_applications SET application_no = id WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed for application_no update: " . $conn->error);
        }
        $stmt->bind_param('i', $app_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed for application_no update: " . $stmt->error);
        }
        $stmt->close();

        // --------------------------------------------------------------
        // 6. Calculate expiration date — Dec 31 of current year
        // --------------------------------------------------------------
        $currentYear = (int)date('Y');
        $expiryYear  = $currentYear;

        // Renewal: expires Dec 31 of the year AFTER the permit being renewed
        // (permits always expire on Dec 31, no matter which month they were filed)
        $rfStmt = $conn->prepare("SELECT o.expiry_date
                                  FROM business_applications ba
                                  JOIN business_applications o ON o.id = ba.renewed_from_id
                                  WHERE ba.id = ? LIMIT 1");
        if ($rfStmt) {
            $rfStmt->bind_param('i', $app_id);
            $rfStmt->execute();
            $rfRow = $rfStmt->get_result()->fetch_assoc();
            $rfStmt->close();
            if ($rfRow && !empty($rfRow['expiry_date'])) {
                $expiryYear = (int)date('Y', strtotime($rfRow['expiry_date'])) + 1;
            }
        }
        $expiryDate  = $expiryYear . '-12-31';

        error_log("Application ID: $app_id, Current Year: $currentYear, Expiry Date: $expiryDate");

        // --------------------------------------------------------------
        // 7. Update status to 'submitted', set ALL dates, and step to 9
        // --------------------------------------------------------------
        $colCheckSubmittedAt = $conn->query("SHOW COLUMNS FROM business_applications LIKE 'submitted_at'");
        $hasSubmittedAt = $colCheckSubmittedAt->num_rows > 0;

        $colCheckIssuedAt = $conn->query("SHOW COLUMNS FROM business_applications LIKE 'issued_at'");
        $hasIssuedAt = $colCheckIssuedAt->num_rows > 0;

        $colCheckExpiryDate = $conn->query("SHOW COLUMNS FROM business_applications LIKE 'expiry_date'");
        $hasExpiryDate = $colCheckExpiryDate->num_rows > 0;

        $colCheckTaxYear = $conn->query("SHOW COLUMNS FROM business_applications LIKE 'tax_year'");
        $hasTaxYear = $colCheckTaxYear->num_rows > 0;

        $updateSQL = "UPDATE business_applications SET 
                      status = 'submitted', 
                      current_step = 9";

        if ($hasTaxYear)    $updateSQL .= ", tax_year = ?";
        if ($hasSubmittedAt) $updateSQL .= ", submitted_at = NOW()";
        if ($hasIssuedAt)    $updateSQL .= ", issued_at = NOW()";
        if ($hasExpiryDate)  $updateSQL .= ", expiry_date = ?";

        $updateSQL .= " WHERE id = ?";

        $stmt = $conn->prepare($updateSQL);

        if ($hasTaxYear && $hasExpiryDate) {
            $stmt->bind_param('isi', $currentYear, $expiryDate, $app_id);
        } elseif ($hasTaxYear) {
            $stmt->bind_param('ii', $currentYear, $app_id);
        } elseif ($hasExpiryDate) {
            $stmt->bind_param('si', $expiryDate, $app_id);
        } else {
            $stmt->bind_param('i', $app_id);
        }

        if (!$stmt->execute()) {
            throw new Exception("Failed to update status: " . $stmt->error);
        }
        $stmt->close();

        // --------------------------------------------------------------
        // 8. Also update the additional_info_data with the application date if needed
        // --------------------------------------------------------------
        $checkDate = $conn->query("SELECT application_date FROM additional_info_data WHERE application_id = $app_id");
        if ($checkDate && $checkDate->num_rows > 0) {
            $dateRow = $checkDate->fetch_assoc();
            if (empty($dateRow['application_date'])) {
                $updateDate = $conn->prepare("UPDATE additional_info_data SET application_date = ? WHERE application_id = ?");
                $today = date('Y-m-d');
                $updateDate->bind_param('si', $today, $app_id);
                $updateDate->execute();
                $updateDate->close();
            }
        }

        // --------------------------------------------------------------
        // 9. Commit transaction
        // --------------------------------------------------------------
        $conn->commit();

        // --------------------------------------------------------------
        // 10. Redirect to confirmation page
        // --------------------------------------------------------------
        header('Location: application_submitted.php?ref=' . urlencode($refNo));
        exit;

    } catch (Exception $e) {
        if (isset($conn) && $conn->connect_errno === 0) {
            $conn->rollback();
        }
        $error = $e->getMessage();
        error_log("Submission error: " . $error);
    }

    $errorMsg = $error ?? 'Unknown error occurred.';
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
    <title>Review & Submit | New Business Permit | Buyawanong Placer</title>
    
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

        /* Sidebar steps */
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
        .step-list li.active { color: var(--brand-dark); font-weight: 700; }
        .step-list li.active .num {
            background: var(--brand-yellow);
            border-color: var(--brand-yellow);
            color: var(--brand-dark);
        }

        /* Info banner (light green) */
        .info-banner {
            background: #ecfdf5;
            border-left: 4px solid var(--green);
            border-radius: 6px;
            padding: 14px 18px;
            font-size: 0.875rem;
            color: var(--brand-dark);
            margin-bottom: 28px;
            line-height: 1.55;
        }

        /* Section heading */
        .section-heading {
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--brand-dark);
            margin-bottom: 16px;
        }

        /* Summary table */
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .summary-table tr {
            border-bottom: 1px solid var(--border-color);
        }
        .summary-table tr:last-child {
            border-bottom: none;
        }
        .summary-table td {
            padding: 16px 0;
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .summary-table td:first-child {
            color: var(--text-muted);
            font-weight: 500;
            width: 38%;
            padding-right: 16px;
        }
        .summary-table td:last-child {
            color: var(--brand-dark);
            font-weight: 500;
        }
        .summary-table td .not-provided {
            color: var(--text-muted);
            font-weight: 400;
        }

        /* Declaration checkboxes */
        .declaration-block {
            margin: 32px 0 40px;
        }
        .form-check {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 0;
            margin-bottom: 18px;
        }
        .form-check:last-child { margin-bottom: 0; }
        .form-check-input {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            margin: 0;
            border: 1.5px solid #cbd5e1;
            border-radius: 4px;
            cursor: pointer;
            background-color: #ffffff;
            position: relative;
            transition: all 0.15s ease;
        }
        .form-check-input:checked {
            background-color: var(--brand-dark);
            border-color: var(--brand-dark);
        }
        .form-check-input:focus {
            box-shadow: 0 0 0 3px rgba(30, 41, 59, 0.1);
            border-color: var(--brand-dark);
        }
        .form-check-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            line-height: 1.5;
            margin: 0;
            cursor: pointer;
            padding-top: 1px;
        }

        /* Buttons */
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
        .btn-submit {
            background-color: var(--brand-dark);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 12px 32px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .btn-submit:hover {
            background-color: var(--brand-dark-hover);
            color: #ffffff;
            transform: translateY(-2px);
        }

        .alert { border-radius: 8px; font-size: 0.9rem; }

        /* Responsive */
        @media (max-width: 991px) {
            .step-list { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 28px; }
            .step-list li { padding: 4px 10px 4px 4px; background: #ffffff; border: 1px solid var(--border-color); border-radius: 2rem; }
            .step-list li.completed { background: #ecfdf5; border-color: var(--green); }
            .step-list li.active { background: #fffbeb; border-color: var(--brand-yellow); }
            .step-list li span:not(.num) { font-size: 0.78rem; }
        }
        @media (max-width: 576px) {
            .btn-back, .btn-submit { width: 100%; }
            .summary-table td:first-child { width: 45%; font-size: 0.85rem; }
            .summary-table td:last-child { font-size: 0.85rem; }
        }

        /* ============================================================
           Custom SweetAlert2 styling to match the design
           ============================================================ */
        .swal-custom-popup {
            border-radius: 16px !important;
            padding: 32px 28px 24px !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08) !important;
        }
        .swal-custom-title {
            font-family: 'Inter', sans-serif !important;
            font-weight: 700 !important;
            font-size: 1.25rem !important;
            color: #1e293b !important;
            padding: 0 !important;
            margin: 0 0 12px 0 !important;
        }
        .swal-custom-html {
            font-family: 'Inter', sans-serif !important;
            font-size: 0.95rem !important;
            color: #64748b !important;
            line-height: 1.55 !important;
            padding: 0 !important;
            margin: 0 0 24px 0 !important;
        }
        .swal-custom-actions {
            display: flex !important;
            gap: 12px !important;
            justify-content: center !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        .swal-btn-confirm-custom {
            background-color: #1e293b !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 8px !important;
            padding: 12px 28px !important;
            font-weight: 600 !important;
            font-size: 0.9rem !important;
            font-family: 'Inter', sans-serif !important;
            transition: all 0.2s ease !important;
            box-shadow: none !important;
            margin: 0 !important;
            flex: 0 0 auto !important;
        }
        .swal-btn-confirm-custom:hover {
            background-color: #0f172a !important;
            color: #ffffff !important;
        }
        .swal-btn-confirm-custom:focus {
            box-shadow: none !important;
            outline: none !important;
        }
        .swal-btn-cancel-custom {
            background-color: #ffffff !important;
            color: #1e293b !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            padding: 12px 28px !important;
            font-weight: 600 !important;
            font-size: 0.9rem !important;
            font-family: 'Inter', sans-serif !important;
            transition: all 0.2s ease !important;
            box-shadow: none !important;
            margin: 0 !important;
            flex: 0 0 auto !important;
        }
        .swal-btn-cancel-custom:hover {
            background-color: #f1f5f9 !important;
            color: #1e293b !important;
        }
        .swal-btn-cancel-custom:focus {
            box-shadow: none !important;
            outline: none !important;
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
        <h1 class="page-title">New business permit application</h1>
        <p class="page-subtitle">Tax year <?php echo date('Y'); ?>, Municipality of Placer</p>
    </div>

    <div class="row g-4 g-lg-5">

        <!-- LEFT: Sidebar -->
        <div class="col-lg-4 col-xl-3">
            <ul class="step-list">
                <?php foreach ($steps as $num => $label):
                    $cls = '';
                    if ($num < 8) $cls = 'completed';
                    elseif ($num === 8) $cls = 'active';
                ?>
                    <li class="<?php echo $cls; ?>">
                        <span class="num">
                            <?php if ($num < 8): ?>
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

            <!-- Info banner -->
            <div class="info-banner">
                Check everything. After you submit, changes need the BPLO.
            </div>

            <!-- Error alert -->
            <?php if (isset($errorMsg)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo htmlspecialchars($errorMsg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Heading -->
            <h2 class="section-heading">Review and submit</h2>

            <!-- Summary table -->
            <table class="summary-table">
                <?php foreach ($summary as $label => $value): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($label); ?></td>
                        <td>
                            <?php if ($value === 'Not provided'): ?>
                                <span class="not-provided">Not provided</span>
                            <?php else: ?>
                                <?php echo htmlspecialchars($value); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <!-- Declarations -->
            <div class="declaration-block">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="declaration1" required>
                    <label class="form-check-label" for="declaration1">
                        I declare under penalty of perjury that this information is true and accurate.
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="declaration2" required>
                    <label class="form-check-label" for="declaration2">
                        I agree to meet the regulatory requirements within 30 days of the permit release.
                    </label>
                </div>
            </div>

            <!-- Back + Submit -->
            <div class="d-flex justify-content-between mt-4 flex-wrap gap-2">
                <button type="button" class="btn-back" id="backBtn">Back</button>
                <button type="button" class="btn-submit" id="submitBtn">Submit application</button>
            </div>

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
       BACK BUTTON
       ============================================================ */
    document.getElementById('backBtn').addEventListener('click', function () {
        window.location.href = 'documents.php';
    });

    /* ============================================================
       SUBMIT BUTTON
       ============================================================ */
    document.getElementById('submitBtn').addEventListener('click', async function () {
        const decl1 = document.getElementById('declaration1').checked;
        const decl2 = document.getElementById('declaration2').checked;

        if (!decl1 || !decl2) {
            await Swal.fire({
                icon: 'warning',
                title: 'Declaration required',
                text: 'Please check both declaration boxes to confirm your agreement.',
                confirmButtonColor: '#1e293b'
            });
            return;
        }

        const result = await Swal.fire({
            title: 'Submit application?',
            html: 'You will not be able to edit it afterwards.<br>Changes need the BPLO.',
            showCancelButton: true,
            confirmButtonText: 'Yes, submit',
            cancelButtonText: 'Cancel',
            buttonsStyling: false,
            reverseButtons: false,
            customClass: {
                popup: 'swal-custom-popup',
                title: 'swal-custom-title',
                htmlContainer: 'swal-custom-html',
                actions: 'swal-custom-actions',
                confirmButton: 'swal-btn-confirm-custom',
                cancelButton: 'swal-btn-cancel-custom'
            },
            showClass: {
                popup: 'swal2-show',
                backdrop: 'swal2-backdrop-show',
                icon: 'swal2-icon-show'
            },
            hideClass: {
                popup: 'swal2-hide',
                backdrop: 'swal2-backdrop-hide',
                icon: 'swal2-icon-hide'
            }
        });

        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            document.body.appendChild(form);
            form.submit();
        }
    });
</script>

</body>
</html>