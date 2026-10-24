<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$application = getOrCreateDraftApplication($user_id);
$app_id = $application['id'];

// --- Determine application type from session (set in new_permit.php) or GET ---
$appType = $_SESSION['application_type'] ?? $_GET['type'] ?? 'new';
$isNew = ($appType === 'new');
$isRenewal = ($appType === 'renewal');
$showNew = $isNew;
$showRenewal = $isRenewal;
$showAmendment = false;

// Forced active type (will override any existing DB value)
$activeType = $isNew ? 'New' : ($isRenewal ? 'Renewal' : 'New');

// --- Validation: Step 2 is the first, no previous steps to check ---
$missingStep = getMissingStep($app_id, $conn);
$currentStep = 2;
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

// Check if we came from a renewal
$renewalSuccess = isset($_GET['renewal']) && $_GET['renewal'] == 1;

// Load existing application type data
$existing = null;
$sql = "SELECT * FROM application_type_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $app_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();

// Handle form submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'application_type' => $activeType,
        'payment_mode' => $_POST['payment_mode'] ?? '',
        'registration_no' => $_POST['registration_no'] ?? '',
        'tin_no' => $_POST['tin_no'] ?? '',
        'business_type' => $_POST['business_type'] ?? '',
        'tax_incentive' => $_POST['tax_incentive'] ?? ''
    ];

    $errors = [];
    if (empty($data['payment_mode'])) $errors[] = 'Payment mode is required.';
    if (empty($data['registration_no'])) $errors[] = 'DTI/SEC/CDA registration number is required.';
    if (empty($data['tin_no'])) $errors[] = 'TIN number is required.';
    if (!preg_match('/^\d{3}-\d{3}-\d{3}$/', $data['tin_no'])) $errors[] = 'TIN format must be 000-000-000.';
    if (empty($data['business_type'])) $errors[] = 'Business type is required.';
    if (empty($data['tax_incentive'])) $errors[] = 'Tax incentive selection is required.';

    if (empty($errors)) {
        if (saveApplicationType($app_id, $data)) {
            updateApplicationStep($app_id, 3);
            header('Location: business_info.php');
            exit;
        } else {
            $errorMsg = "Database error. Please try again.";
        }
    } else {
        $errorMsg = implode('<br>', $errors);
    }
}

// Step sidebar labels
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
    <title><?php echo $isRenewal ? 'Renew' : 'New'; ?> Business Permit | Application Type | Buyawanong Placer</title>
    
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
        body {
            background-color: var(--bg-light);
            color: var(--brand-dark);
        }

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
        .step-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
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
            margin-bottom: 8px;
            display: block;
        }
        .field-label .req { color: #ef4444; }

        /* Pill option group (radio buttons) */
        .pill-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }
        .pill-option {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 2rem;
            border: 1px solid var(--border-color);
            background: #ffffff;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--brand-dark);
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
        }
        .pill-option:hover {
            border-color: #cbd5e1;
            background: #f9fafb;
        }
        .pill-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .pill-option .dot {
            display: inline-block;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            flex-shrink: 0;
            position: relative;
            transition: all 0.15s ease;
        }
        .pill-option input[type="radio"]:checked ~ .dot {
            border-color: var(--brand-dark);
        }
        .pill-option input[type="radio"]:checked ~ .dot::after {
            content: '';
            position: absolute;
            inset: 3px;
            border-radius: 50%;
            background: var(--brand-dark);
        }
        .pill-option.selected {
            background: var(--brand-dark);
            border-color: var(--brand-dark);
            color: #ffffff;
        }
        .pill-option.selected .dot {
            border-color: #ffffff;
            background: transparent;
        }
        .pill-option.selected .dot::after {
            background: #ffffff;
        }
        .pill-option.hidden { display: none; }

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
        .form-control::placeholder {
            color: #94a3b8;
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

        /* Alerts */
        .alert {
            border-radius: 8px;
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 991px) {
            .step-list {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin-bottom: 28px;
            }
            .step-list li {
                padding: 4px 10px 4px 4px;
                background: #ffffff;
                border: 1px solid var(--border-color);
                border-radius: 2rem;
            }
            .step-list li.completed {
                background: #ecfdf5;
                border-color: var(--green);
            }
            .step-list li.active {
                background: #fffbeb;
                border-color: var(--brand-yellow);
            }
            .step-list li span:not(.num) {
                font-size: 0.78rem;
            }
        }
        @media (max-width: 576px) {
            .btn-back, .btn-continue { width: 100%; }
            .pill-group { gap: 8px; }
            .pill-option { padding: 7px 14px; font-size: 0.8rem; }
        }
    </style>
</head>
<body>

<?php include 'header/nav.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 1080px;">

    <!-- Back link -->
    <div class="mb-3">
        <a href="dashboard.php" class="back-link">
            Back to dashboard
        </a>
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
                    if ($num < 2) $cls = 'completed';
                    elseif ($num === 2) $cls = 'active';
                ?>
                    <li class="<?php echo $cls; ?>">
                        <span class="num">
                            <?php if ($num < 2): ?>
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

            <!-- Renewal alert -->
            <?php if ($renewalSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    Your renewal application has been created with pre-filled data from your previous permit.
                    Please review and update any information as needed.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Error alert -->
            <?php if (isset($errorMsg)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <?php echo $errorMsg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="" id="applicationTypeForm">

                <!-- Application type -->
                <h2 class="section-heading">Application type</h2>
                <p class="section-subheading">Is this new, a renewal, or an amendment?</p>

                <label class="field-label">Type</label>
                <div class="pill-group" id="typeGroup">
                    <label class="pill-option <?php echo ($activeType == 'New') ? 'selected' : ''; ?><?php echo $showNew ? '' : ' hidden'; ?>" data-value="New">
                        <input type="radio" name="application_type_visual" value="New" <?php echo ($activeType == 'New') ? 'checked' : ''; ?>>
                        <span class="dot"></span>
                        <span>New</span>
                    </label>
                    <label class="pill-option <?php echo ($activeType == 'Renewal') ? 'selected' : ''; ?><?php echo $showRenewal ? '' : ' hidden'; ?>" data-value="Renewal">
                        <input type="radio" name="application_type_visual" value="Renewal" <?php echo ($activeType == 'Renewal') ? 'checked' : ''; ?>>
                        <span class="dot"></span>
                        <span>Renewal</span>
                    </label>
                    <label class="pill-option hidden" data-value="Amendment">
                        <input type="radio" name="application_type_visual" value="Amendment">
                        <span class="dot"></span>
                        <span>Amendment</span>
                    </label>
                </div>
                <input type="hidden" name="application_type" id="applicationType" value="<?php echo htmlspecialchars($activeType); ?>">

                <!-- Mode of payment -->
                <label class="field-label">Mode of payment</label>
                <?php $pm = $existing['payment_mode'] ?? ''; ?>
                <div class="pill-group">
                    <label class="pill-option <?php echo ($pm == 'Annually') ? 'selected' : ''; ?>">
                        <input type="radio" name="payment_mode" value="Annually" <?php echo ($pm == 'Annually') ? 'checked' : ''; ?> required>
                        <span class="dot"></span>
                        <span>Annually</span>
                    </label>
                    <label class="pill-option <?php echo ($pm == 'Semi-annually') ? 'selected' : ''; ?>">
                        <input type="radio" name="payment_mode" value="Semi-annually" <?php echo ($pm == 'Semi-annually') ? 'checked' : ''; ?>>
                        <span class="dot"></span>
                        <span>Semi-annually</span>
                    </label>
                    <label class="pill-option <?php echo ($pm == 'Quarterly') ? 'selected' : ''; ?>">
                        <input type="radio" name="payment_mode" value="Quarterly" <?php echo ($pm == 'Quarterly') ? 'checked' : ''; ?>>
                        <span class="dot"></span>
                        <span>Quarterly</span>
                    </label>
                </div>

                <!-- Registration no. + TIN no. side by side -->
                <div class="row g-4 mb-4">
                    <div class="col-md-7">
                        <label for="registrationNo" class="field-label">DTI/SEC/CDA registration no. <span class="req">*</span></label>
                        <input type="text" class="form-control" id="registrationNo" name="registration_no"
                               placeholder="e.g. DTI-2026-00001"
                               value="<?php echo htmlspecialchars($existing['registration_no'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-5">
                        <label for="tinNo" class="field-label">TIN no. <span class="req">*</span></label>
                        <input type="text" class="form-control" id="tinNo" name="tin_no"
                               placeholder="000-000-000"
                               value="<?php echo htmlspecialchars($existing['tin_no'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- Type of business -->
                <label class="field-label">Type of business</label>
                <?php $bt = $existing['business_type'] ?? ''; ?>
                <div class="pill-group">
                    <label class="pill-option <?php echo ($bt == 'Single') ? 'selected' : ''; ?>">
                        <input type="radio" name="business_type" value="Single" <?php echo ($bt == 'Single') ? 'checked' : ''; ?> required>
                        <span class="dot"></span>
                        <span>Single</span>
                    </label>
                    <label class="pill-option <?php echo ($bt == 'Partnership') ? 'selected' : ''; ?>">
                        <input type="radio" name="business_type" value="Partnership" <?php echo ($bt == 'Partnership') ? 'checked' : ''; ?>>
                        <span class="dot"></span>
                        <span>Partnership</span>
                    </label>
                    <label class="pill-option <?php echo ($bt == 'Corporation') ? 'selected' : ''; ?>">
                        <input type="radio" name="business_type" value="Corporation" <?php echo ($bt == 'Corporation') ? 'checked' : ''; ?>>
                        <span class="dot"></span>
                        <span>Corporation</span>
                    </label>
                    <label class="pill-option <?php echo ($bt == 'Cooperative') ? 'selected' : ''; ?>">
                        <input type="radio" name="business_type" value="Cooperative" <?php echo ($bt == 'Cooperative') ? 'checked' : ''; ?>>
                        <span class="dot"></span>
                        <span>Cooperative</span>
                    </label>
                </div>

                <!-- Tax incentive -->
                <label class="field-label">Do you enjoy a tax incentive from any government entity?</label>
                <?php $ti = $existing['tax_incentive'] ?? ''; ?>
                <div class="pill-group">
                    <label class="pill-option <?php echo ($ti == 'Yes') ? 'selected' : ''; ?>">
                        <input type="radio" name="tax_incentive" value="Yes" <?php echo ($ti == 'Yes') ? 'checked' : ''; ?> required>
                        <span class="dot"></span>
                        <span>Yes</span>
                    </label>
                    <label class="pill-option <?php echo ($ti == 'No') ? 'selected' : ''; ?>">
                        <input type="radio" name="tax_incentive" value="No" <?php echo ($ti == 'No') ? 'checked' : ''; ?>>
                        <span class="dot"></span>
                        <span>No</span>
                    </label>
                </div>

                <!-- Buttons -->
                <div class="d-flex justify-content-between mt-5 flex-wrap gap-2">
                    <button type="button" class="btn-back" id="backBtn">Back</button>
                    <button type="submit" class="btn-continue" id="continueBtn">Continue</button>
                </div>

            </form>
        </div>
    </div>

</div>

<!-- Footer outside the container, at the bottom of the page -->
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
    // ---------- Pill group selection handling ----------
    // When a radio is selected inside a pill, mark the parent label as .selected
    // and unmark siblings.
    document.querySelectorAll('.pill-option input[type="radio"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const name = this.name;
            document.querySelectorAll('input[name="' + name + '"]').forEach(function (r) {
                r.closest('.pill-option').classList.toggle('selected', r.checked);
            });
        });
    });

    // ---------- Application type toggle (visual only, forced by PHP) ----------
    const typeOptions = document.querySelectorAll('.pill-option[data-value]:not(.hidden)');
    const applicationTypeInput = document.getElementById('applicationType');
    typeOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            // Only allow if it's a real visible option
            if (this.classList.contains('hidden')) return;
            typeOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            applicationTypeInput.value = this.getAttribute('data-value');
        });
    });

    // ---------- Back button ----------
    document.getElementById('backBtn').addEventListener('click', function () {
        window.location.href = 'new_permit.php';
    });

    // ---------- Client-side validation ----------
    document.getElementById('applicationTypeForm').addEventListener('submit', async function (e) {
        const regNo = document.getElementById('registrationNo').value.trim();
        const tin = document.getElementById('tinNo').value.trim();
        const tinPattern = /^\d{3}-\d{3}-\d{3}$/;

        if (!regNo) {
            e.preventDefault();
            await Swal.fire({
                icon: 'warning',
                title: 'Registration number required',
                text: 'Please enter your DTI, SEC, or CDA registration number.',
                confirmButtonColor: '#1e293b'
            });
            document.getElementById('registrationNo').focus();
            return;
        }

        if (tin && !tinPattern.test(tin)) {
            e.preventDefault();
            await Swal.fire({
                icon: 'warning',
                title: 'Invalid TIN format',
                text: 'Please use format: 000-000-000',
                confirmButtonColor: '#1e293b'
            });
        }
    });
</script>

</body>
</html>