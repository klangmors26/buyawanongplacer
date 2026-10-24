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

// --- RESET DRAFT ---
if (isset($_GET['reset']) && $_GET['reset'] == 1) {
    if (!$conn) die('Database connection failed.');

    $getId = $conn->prepare("SELECT id FROM business_applications WHERE user_id = ? AND status = 'draft' LIMIT 1");
    $getId->bind_param("i", $user_id);
    $getId->execute();
    $res = $getId->get_result();
    $draftId = null;
    if ($row = $res->fetch_assoc()) $draftId = $row['id'];
    $getId->close();

    if ($draftId) {
        $conn->query("DELETE FROM application_type_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM business_info_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM business_address_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM owner_info_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM additional_info_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM document_uploads WHERE application_id = $draftId");
        $conn->query("DELETE FROM appointments WHERE application_id = $draftId");
        $conn->query("DELETE FROM business_applications WHERE id = $draftId");
    }

    unset($_SESSION['appointment_confirmed'], $_SESSION['appointment_date'], $_SESSION['appointment_time']);
    header('Location: new_permit.php');
    exit;
}

// --- Draft application handling ---
$application = getOrCreateDraftApplication($user_id);
$current_step = $application['current_step'];
$isRenewal = !empty($application['renewed_from_id']);
$_SESSION['application_type'] = $isRenewal ? 'renewal' : 'new';

if ($current_step > 1) {
    $stepPages = [
        2 => 'application_type.php',
        3 => 'business_info.php',
        4 => 'business_address.php',
        5 => 'owner_info.php',
        6 => 'additional_info.php',
        7 => 'documents.php',
        8 => 'review_submit.php'
    ];
    if (isset($stepPages[$current_step])) {
        header('Location: ' . $stepPages[$current_step]);
        exit;
    }
}

// Sidebar step list
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

// Requirements list
$requirements = [
    'Barangay Clearance',
    'DTI/SEC Photocopy',
    'RPT, Current year 2026 Payment',
    'Engineering Receipt',
    'Zoning Certification (MPDC)',
    'Treasury Office for Payment',
    'Sanitary Health Permit (Health Center)',
    'Fire Safety Certificate 2026',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>New Business Permit Application | Buyawanong Placer</title>
    
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
        .step-list li.active {
            color: var(--brand-dark);
            font-weight: 700;
        }
        .step-list li.active .num {
            background: var(--brand-yellow);
            border-color: var(--brand-yellow);
            color: var(--brand-dark);
        }

        .section-heading {
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--brand-dark);
            margin-bottom: 6px;
        }
        .section-subheading {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .requirements-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .requirements-list li {
            display: flex;
            align-items: baseline;
            gap: 22px;
            padding: 14px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: var(--brand-dark);
        }
        .requirements-list li:last-child { border-bottom: none; }
        .requirements-list .num {
            color: var(--text-muted);
            font-size: 0.8rem;
            min-width: 16px;
            flex-shrink: 0;
        }
        .requirements-list .text {
            font-weight: 500;
        }

        .info-notice {
            background: #ecfdf5;
            border-left: 4px solid var(--green);
            border-radius: 6px;
            padding: 14px 18px;
            font-size: 0.85rem;
            color: var(--brand-dark);
            margin-top: 22px;
            line-height: 1.55;
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
            .step-list li.active {
                background: #fffbeb;
                border-color: var(--brand-yellow);
            }
            .step-list li span:not(.num) {
                font-size: 0.78rem;
            }
        }
        @media (max-width: 576px) {
            .requirements-list li { padding: 12px 0; font-size: 0.85rem; }
            .btn-continue { width: 100%; }
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
                <?php foreach ($steps as $num => $label): ?>
                    <li class="<?php echo $num === 1 ? 'active' : ''; ?>">
                        <span class="num"><?php echo $num; ?></span>
                        <span><?php echo htmlspecialchars($label); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- RIGHT: Content -->
        <div class="col-lg-8 col-xl-9">

            <!-- Before you begin -->
            <h2 class="section-heading">Before you begin</h2>
            <p class="section-subheading">
                Have these ready. Incomplete submissions are returned to the applicant.
            </p>

            <!-- Requirements list -->
            <ol class="requirements-list">
                <?php foreach ($requirements as $i => $req): ?>
                    <li>
                        <span class="num"><?php echo $i + 1; ?>.</span>
                        <span class="text"><?php echo htmlspecialchars($req); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>

            <!-- Info notice -->
            <div class="info-notice">
                Only business owners operating within the Municipality of Placer may apply.
                Press <strong>Continue</strong> to confirm your documents are ready.
            </div>

            <!-- Continue button -->
            <div class="d-flex justify-content-end mt-4">
                <button class="btn-continue" id="continueBtn">
                    Continue
                </button>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    const continueBtn = document.getElementById('continueBtn');
    if (continueBtn) {
        continueBtn.addEventListener('click', async function() {
            const result = await Swal.fire({
                icon: 'question',
                title: 'Confirm Requirements',
                html: 'Have you prepared <strong>all</strong> the required documents listed above?',
                showCancelButton: true,
                confirmButtonColor: '#1e293b',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, I have them ready',
                cancelButtonText: 'Not yet'
            });

            if (!result.isConfirmed) return;

            try {
                await fetch('update_step.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ step: 2 })
                });
            } catch(e) { console.warn('Step update failed'); }

            const type = "<?php echo $isRenewal ? 'renewal' : 'new'; ?>";
            await Swal.fire({
                icon: 'success',
                title: 'Proceeding to application type',
                timer: 1200,
                showConfirmButton: false
            });
            window.location.href = 'application_type.php?type=' + type;
        });
    }
</script>

</body>
</html>