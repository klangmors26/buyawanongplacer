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

// --- Validation: ensure step 2 is complete ---
$missingStep = getMissingStep($app_id, $conn);
$currentStep = 3;
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

// Load existing data from business_info_data table
$existing = null;
$sql = "SELECT * FROM business_info_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $app_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();

// Load activities (stored as JSON)
$activities = [];
if ($existing && !empty($existing['business_activities'])) {
    $activities = json_decode($existing['business_activities'], true);
}
if (empty($activities)) {
    $activities = [[]];
}

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'last_name'    => trim($_POST['last_name'] ?? ''),
        'first_name'   => trim($_POST['first_name'] ?? ''),
        'middle_name'  => trim($_POST['middle_name'] ?? ''),
        'business_name'=> trim($_POST['business_name'] ?? ''),
        'trade_name'   => trim($_POST['trade_name'] ?? '')
    ];

    $activitiesPost = [];
    $lines  = $_POST['line_of_business'] ?? [];
    $units  = $_POST['num_units'] ?? [];
    $caps   = $_POST['capitalization'] ?? [];
    $grossEss = $_POST['gross_essential'] ?? [];
    $grossNon = $_POST['gross_nonessential'] ?? [];

    for ($i = 0; $i < count($lines); $i++) {
        if (!empty(trim($lines[$i]))) {
            $activitiesPost[] = [
                'line_of_business'    => trim($lines[$i]),
                'num_units'           => $units[$i] ?? 1,
                'capitalization'      => $caps[$i] ?? '',
                'gross_essential'     => $grossEss[$i] ?? '',
                'gross_nonessential'  => $grossNon[$i] ?? ''
            ];
        }
    }

    $errors = [];
    if (empty($data['last_name']))     $errors[] = 'Last name is required.';
    if (empty($data['first_name']))    $errors[] = 'First name is required.';
    if (empty($data['business_name'])) $errors[] = 'Business name is required.';
    if (empty($activitiesPost))        $errors[] = 'At least one business activity is required.';

    if (empty($errors)) {
        if (saveBusinessInfo($app_id, $data, $activitiesPost)) {
            updateApplicationStep($app_id, 4);
            header('Location: business_address.php');
            exit;
        } else {
            $errorMsg = "Database error. Please try again.";
        }
    } else {
        $errorMsg = implode('<br>', $errors);
    }
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
    <title>Business Information | <?php echo $isRenewal ? 'Renew' : 'New'; ?> Business Permit | Buyawanong Placer</title>
    
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

        /* Title */
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
        .step-list li.active {
            color: var(--brand-dark);
            font-weight: 700;
        }
        .step-list li.active .num {
            background: var(--brand-yellow);
            border-color: var(--brand-yellow);
            color: var(--brand-dark);
        }

        /* Section heading */
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

        /* Business activity table */
        .activity-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .activity-table thead th {
            font-size: 0.78rem;
            font-weight: 500;
            color: var(--text-muted);
            padding: 0 10px 12px 10px;
            border-bottom: 1px solid var(--brand-dark);
            text-align: left;
            white-space: nowrap;
            vertical-align: bottom;
        }
        .activity-table thead th:first-child { padding-left: 0; }
        .activity-table thead th:last-child { padding-right: 0; width: 40px; }
        .activity-table tbody td {
            padding: 14px 10px 0 10px;
            vertical-align: top;
        }
        .activity-table tbody td:first-child { padding-left: 0; }
        .activity-table tbody td:last-child {
            padding-right: 0;
            text-align: center;
            width: 40px;
        }
        .activity-table .remove-row {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            color: var(--text-muted);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
            margin-top: 8px;
        }
        .activity-table .remove-row:hover {
            background: #fee2e2;
            color: #ef4444;
        }

        /* Add another line button */
        .btn-add-row {
            background: #ffffff;
            border: 1px solid var(--border-color);
            color: var(--brand-dark);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.15s ease;
        }
        .btn-add-row:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: var(--brand-dark);
        }

        /* Back + Continue buttons */
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

        /* Responsive */
        @media (max-width: 991px) {
            .step-list { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 28px; }
            .step-list li { padding: 4px 10px 4px 4px; background: #ffffff; border: 1px solid var(--border-color); border-radius: 2rem; }
            .step-list li.completed { background: #ecfdf5; border-color: var(--green); }
            .step-list li.active { background: #fffbeb; border-color: var(--brand-yellow); }
            .step-list li span:not(.num) { font-size: 0.78rem; }
        }
        @media (max-width: 768px) {
            .activity-table { font-size: 0.78rem; }
            .activity-table thead th { font-size: 0.7rem; padding-bottom: 8px; }
            .form-control { padding: 10px 12px; font-size: 0.85rem; }
        }
        @media (max-width: 576px) {
            .btn-back, .btn-continue { width: 100%; }
            .activity-table thead { display: none; }
            .activity-table, .activity-table tbody, .activity-table tr, .activity-table td { display: block; width: 100%; }
            .activity-table tbody tr {
                border: 1px solid var(--border-color);
                border-radius: 10px;
                padding: 12px;
                margin-bottom: 12px;
                background: #ffffff;
            }
            .activity-table tbody td {
                padding: 6px 0;
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .activity-table tbody td:last-child { width: 100%; text-align: right; padding: 6px 0 0 0; }
            .activity-table tbody td::before {
                content: attr(data-label);
                font-size: 0.72rem;
                font-weight: 600;
                color: var(--text-muted);
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .activity-table .remove-row { margin-top: 0; }
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

        <!-- LEFT: Step sidebar -->
        <div class="col-lg-4 col-xl-3">
            <ul class="step-list">
                <?php foreach ($steps as $num => $label):
                    $cls = '';
                    if ($num < 3) $cls = 'completed';
                    elseif ($num === 3) $cls = 'active';
                ?>
                    <li class="<?php echo $cls; ?>">
                        <span class="num">
                            <?php if ($num < 3): ?>
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

            <form method="POST" action="" id="businessInfoForm">

                <!-- Business information -->
                <h2 class="section-heading">Business information</h2>
                <p class="section-subheading">Enter the details exactly as registered with DTI/SEC/CDA.</p>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label for="lastName" class="field-label">Last name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="lastName" name="last_name"
                               value="<?php echo htmlspecialchars($existing['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="firstName" class="field-label">First name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="firstName" name="first_name"
                               value="<?php echo htmlspecialchars($existing['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="middleName" class="field-label">Middle name</label>
                        <input type="text" class="form-control" id="middleName" name="middle_name"
                               value="<?php echo htmlspecialchars($existing['middle_name'] ?? ''); ?>">
                    </div>
                </div>

                <div class="row g-3 mb-5">
                    <div class="col-md-6">
                        <label for="businessName" class="field-label">Business name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="businessName" name="business_name"
                               value="<?php echo htmlspecialchars($existing['business_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="tradeName" class="field-label">Trade name / franchise</label>
                        <input type="text" class="form-control" id="tradeName" name="trade_name"
                               placeholder="If applicable"
                               value="<?php echo htmlspecialchars($existing['trade_name'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Business activity -->
                <h2 class="section-heading mb-3">Business activity</h2>

                <div class="table-responsive">
                    <table class="activity-table" id="activityTable">
                        <thead>
                            <tr>
                                <th style="min-width: 180px;">Line of business</th>
                                <th style="width: 90px;">Units</th>
                                <th style="width: 130px;">Capitalization</th>
                                <th style="width: 150px;">Gross receipts, essential</th>
                                <th style="width: 160px;">Gross receipts, non-essential</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="activityRows">
                            <?php if (empty($activities) || (count($activities) === 1 && empty($activities[0]['line_of_business']))): ?>
                                <tr class="activity-row">
                                    <td data-label="Line of business"><input type="text" name="line_of_business[]" class="form-control line_of_business" placeholder="e.g. Retail store"></td>
                                    <td data-label="Units"><input type="number" name="num_units[]" class="form-control num_units" value="1" step="1" min="1"></td>
                                    <td data-label="Capitalization"><input type="text" name="capitalization[]" class="form-control capitalization" placeholder="₱ 0.00"></td>
                                    <td data-label="Gross receipts, essential"><input type="text" name="gross_essential[]" class="form-control gross_essential" placeholder="₱ 0.00"></td>
                                    <td data-label="Gross receipts, non-essential"><input type="text" name="gross_nonessential[]" class="form-control gross_nonessential" placeholder="₱ 0.00"></td>
                                    <td><span class="remove-row"><i class="bi bi-trash3"></i></span></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activities as $act): ?>
                                    <tr class="activity-row">
                                        <td data-label="Line of business"><input type="text" name="line_of_business[]" class="form-control line_of_business" value="<?php echo htmlspecialchars($act['line_of_business'] ?? ''); ?>"></td>
                                        <td data-label="Units"><input type="number" name="num_units[]" class="form-control num_units" value="<?php echo htmlspecialchars($act['num_units'] ?? 1); ?>" step="1" min="1"></td>
                                        <td data-label="Capitalization"><input type="text" name="capitalization[]" class="form-control capitalization" value="<?php echo htmlspecialchars($act['capitalization'] ?? ''); ?>" placeholder="₱ 0.00"></td>
                                        <td data-label="Gross receipts, essential"><input type="text" name="gross_essential[]" class="form-control gross_essential" value="<?php echo htmlspecialchars($act['gross_essential'] ?? ''); ?>" placeholder="₱ 0.00"></td>
                                        <td data-label="Gross receipts, non-essential"><input type="text" name="gross_nonessential[]" class="form-control gross_nonessential" value="<?php echo htmlspecialchars($act['gross_nonessential'] ?? ''); ?>" placeholder="₱ 0.00"></td>
                                        <td><span class="remove-row"><i class="bi bi-trash3"></i></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 mb-5">
                    <button type="button" class="btn-add-row" id="addRowBtn">Add another line</button>
                </div>

                <!-- Back + Continue -->
                <div class="d-flex justify-content-between flex-wrap gap-2">
                    <button type="button" class="btn-back" id="backBtn">Back</button>
                    <button type="submit" class="btn-continue" id="continueBtn">Continue</button>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- Footer at page level -->
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
    // Add new activity row
    document.getElementById('addRowBtn').addEventListener('click', function() {
        const tbody = document.getElementById('activityRows');
        const newRow = document.createElement('tr');
        newRow.className = 'activity-row';
        newRow.innerHTML = `
            <td data-label="Line of business"><input type="text" name="line_of_business[]" class="form-control line_of_business" placeholder="e.g. Retail store"></td>
            <td data-label="Units"><input type="number" name="num_units[]" class="form-control num_units" value="1" step="1" min="1"></td>
            <td data-label="Capitalization"><input type="text" name="capitalization[]" class="form-control capitalization" placeholder="₱ 0.00"></td>
            <td data-label="Gross receipts, essential"><input type="text" name="gross_essential[]" class="form-control gross_essential" placeholder="₱ 0.00"></td>
            <td data-label="Gross receipts, non-essential"><input type="text" name="gross_nonessential[]" class="form-control gross_nonessential" placeholder="₱ 0.00"></td>
            <td><span class="remove-row"><i class="bi bi-trash3"></i></span></td>
        `;
        tbody.appendChild(newRow);
        attachRemoveEvent(newRow.querySelector('.remove-row'));
    });

    function attachRemoveEvent(element) {
        element.addEventListener('click', function() {
            const row = this.closest('.activity-row');
            if (document.querySelectorAll('.activity-row').length > 1) {
                row.remove();
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cannot remove',
                    text: 'At least one business activity is required.',
                    confirmButtonColor: '#1e293b'
                });
            }
        });
    }

    document.querySelectorAll('.remove-row').forEach(btn => attachRemoveEvent(btn));

    // Back button
    document.getElementById('backBtn').addEventListener('click', function() {
        window.location.href = 'application_type.php';
    });

    // Client-side validation
    document.getElementById('businessInfoForm').addEventListener('submit', function(e) {
        const lastName = document.getElementById('lastName').value.trim();
        const firstName = document.getElementById('firstName').value.trim();
        const businessName = document.getElementById('businessName').value.trim();

        if (!lastName || !firstName || !businessName) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Missing fields',
                text: 'Please fill in all required fields.',
                confirmButtonColor: '#1e293b'
            });
            return false;
        }

        const rows = document.querySelectorAll('.activity-row');
        let hasValid = false;
        for (let row of rows) {
            const lob = row.querySelector('.line_of_business').value.trim();
            if (lob !== '') { hasValid = true; break; }
        }
        if (!hasValid) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Business activity required',
                text: 'Please add at least one line of business.',
                confirmButtonColor: '#1e293b'
            });
            return false;
        }
    });
</script>

</body>
</html>