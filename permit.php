<?php
require_once 'config/connect.php';

$ref = isset($_GET['ref']) ? trim($_GET['ref']) : '';

// ============================================================
// ✅ FIX 3: Normalize $ref if it arrived as a doubly-encoded value
// ============================================================
if ($ref !== '' && (strpos($ref, 'ref=') !== false || preg_match('#^https?://#i', $ref))) {
    if (preg_match('/[?&]ref=([^&]+)/', $ref, $m)) {
        $ref = urldecode($m[1]);
    }
}

$application = null;
$feeAssessment = null;
$payment = null;
$timeline = [];
$business_name = '';
$owner_name = '';
$status = '';
$paymentConfirmed = false;
$isExpiredRenewed = false;
$newReference = '';

if ($ref) {
    if (strpos($ref, '-OLD') !== false) {
        $isExpiredRenewed = true;
        $newReference = str_replace('-OLD', '', $ref);
    }

    $stmt = $conn->prepare("
        SELECT 
            ba.id,
            ba.reference_no,
            ba.status,
            ba.submitted_at,
            ba.application_date,
            ba.created_at,
            ba.expiry_date,
            bid.business_name,
            CONCAT(bid.first_name, ' ', bid.last_name) AS owner_name
        FROM business_applications ba
        LEFT JOIN business_info_data bid ON ba.id = bid.application_id
        WHERE ba.reference_no = ?
    ");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $application = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($application) {
        $status = strtolower($application['status']);
        $business_name = $application['business_name'] ?? 'N/A';
        $owner_name = $application['owner_name'] ?? 'N/A';
        $application_id = $application['id'];

        $stmt = $conn->prepare("SELECT * FROM fee_assessments WHERE application_id = ?");
        $stmt->bind_param('i', $application_id);
        $stmt->execute();
        $feeAssessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("SELECT * FROM payments WHERE application_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $application_id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $paymentConfirmed = false;
        if ($payment && $payment['status'] === 'paid') {
            $paymentConfirmed = true;
        } elseif (in_array($status, ['payment_confirmed', 'paid', 'under_review', 'approved', 'claimed', 'verified'])) {
            $paymentConfirmed = true;
        } elseif ($status === 'cancelled') {
            if ($payment && $payment['status'] === 'paid') {
                $paymentConfirmed = true;
            }
            if (!in_array($status, ['submitted', 'pending_payment'])) {
                $paymentConfirmed = true;
            }
        }

        $timeline = [];

        if (!empty($application['submitted_at'])) {
            $timeline[] = [
                'step' => 'Application submitted',
                'date' => date('F j, Y — g:i A', strtotime($application['submitted_at'])),
                'completed' => true
            ];
        } else {
            $timeline[] = [
                'step' => 'Application submitted',
                'date' => 'Pending',
                'completed' => false
            ];
        }

        $docsVerified = !in_array($status, ['submitted']);
        $timeline[] = [
            'step' => 'Documents verified',
            'date' => $docsVerified ? 'Completed' : 'Pending',
            'completed' => $docsVerified
        ];

        $paymentDate = '';
        if ($paymentConfirmed) {
            if ($payment && isset($payment['paid_at'])) {
                $paymentDate = date('F j, Y — g:i A', strtotime($payment['paid_at']));
            } else {
                $paymentDate = 'Completed';
            }
        } else {
            $paymentDate = 'Pending';
        }
        $timeline[] = [
            'step' => 'Payment confirmed',
            'date' => $paymentDate,
            'completed' => $paymentConfirmed
        ];

        $underReview = in_array($status, ['under_review', 'approved', 'claimed', 'verified']);
        $timeline[] = [
            'step' => 'Under review by licensing office',
            'date' => $underReview ? 'In progress' : 'Pending',
            'completed' => $underReview
        ];

        if ($status === 'verified') {
            $timeline[] = [
                'step' => '✅ VERIFIED - Permit ready for issuance',
                'date' => 'Completed',
                'completed' => true,
                'is_verified' => true
            ];
        } elseif ($status === 'rejected' || $status === 'cancelled') {
            $timeline[] = [
                'step' => ($status === 'cancelled') ? '❌ CANCELLED - Application cancelled' : '❌ REJECTED - Application not approved',
                'date' => 'Completed',
                'completed' => true,
                'is_rejected' => true
            ];
        } elseif (in_array($status, ['approved', 'claimed'])) {
            $timeline[] = [
                'step' => '🏆 Permit issued & QR code generated',
                'date' => 'Completed',
                'completed' => true,
                'is_issued' => true
            ];
        } else {
            $timeline[] = [
                'step' => 'Permit issuance pending',
                'date' => 'Pending',
                'completed' => false
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Buyawanong Placer | Municipality of Placer</title>
    
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Live camera scanner -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <!-- Robust still-image QR decoder -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>

    <style>
        :root {
            --brand-dark: #1e293b;
            --brand-yellow: #fbbf24;
            --brand-yellow-hover: #f59e0b;
            --bg-light: #f8fafc;
            --text-muted: #64748b;
            --card-radius: 16px;
            --primary-green: #00c389;
            --primary-green-dark: #00996e;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--brand-dark);
        }

        /* Hero Section */
        .hero-section {
            background-color: var(--brand-dark);
            color: white;
            position: relative;
            padding: 100px 0 80px;
        }

        .hero-section h1 {
            font-weight: 800;
            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1.1;
            letter-spacing: -0.02em;
        }

        .hero-section p {
            color: #94a3b8;
            font-size: clamp(1rem, 1.5vw, 1.125rem);
            max-width: 600px;
            line-height: 1.6;
        }

        /* BUTTON FIX: Using !important and direct hex codes to override Bootstrap */
        .btn-warning-custom {
            background-color: #fbbf24 !important;
            color: #1e293b !important;
            border: 1px solid #fbbf24 !important;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .btn-warning-custom:hover {
            background-color: #f59e0b !important;
            border-color: #f59e0b !important;
            color: #1e293b !important;
            transform: translateY(-2px);
        }

        .btn-outline-light-custom {
            background-color: transparent !important;
            border: 1px solid rgba(255, 255, 255, 0.5) !important;
            color: #ffffff !important;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .btn-outline-light-custom:hover {
            background-color: rgba(255, 255, 255, 0.1) !important;
            border-color: #ffffff !important;
            color: #ffffff !important;
        }

        /* Scallop Bottom Effect */
        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 30px;
            background-image: radial-gradient(circle at 50% 0%, transparent 25px, var(--bg-light) 26px);
            background-size: 60px 30px;
            background-repeat: repeat-x;
        }

        /* How it works */
        .step-item {
            position: relative;
        }

        .step-line {
            height: 2px;
            background-color: #cbd5e1;
            margin-bottom: 20px;
        }

        .step-number {
            width: 36px;
            height: 36px;
            background-color: var(--brand-yellow);
            color: var(--brand-dark);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
        }

        /* Cards */
        .custom-card {
            border: none;
            border-radius: var(--card-radius);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            background: white;
        }

        .dark-card {
            background-color: var(--brand-dark);
            color: white;
            border-radius: var(--card-radius);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .dark-card hr {
            border-color: rgba(255, 255, 255, 0.1);
            opacity: 1;
        }

        .dark-card .feature-title {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .dark-card .feature-desc {
            color: #94a3b8;
            font-size: 0.875rem;
            margin-bottom: 0;
        }

        /* Tracking Timeline */
        .status-timeline {
            border-left: 2px solid #e2e8f0;
            padding-left: 1.5rem;
            margin-top: 1rem;
        }

        .status-item {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .status-item:last-child {
            margin-bottom: 0;
        }

        .status-item .status-icon {
            position: absolute;
            left: -2.1rem;
            top: 0;
            background: white;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
        }

        .status-item.completed .status-icon { color: var(--primary-green); }
        .status-item.verified .status-icon { color: var(--primary-green); }
        .status-item.rejected .status-icon { color: #dc3545; }
        .status-item.issued .status-icon { color: var(--primary-green); }
        .status-item.active .status-icon { color: var(--brand-yellow); }
        .status-item.expired .status-icon { color: #fd7e14; }

        .status-item .status-text {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--brand-dark);
        }

        .status-item .status-text.verified-text { color: var(--primary-green); }
        .status-item .status-text.rejected-text { color: #dc3545; }
        .status-item .status-text.issued-text { color: var(--primary-green); }
        .status-item .status-date {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Badges */
        .badge-verified { background: #e6f9f2; color: var(--primary-green-dark); padding: 0.5rem 1rem; border-radius: 2rem; font-weight: 600; font-size: 0.8rem; }
        .badge-rejected { background: #f8d7da; color: #721c24; padding: 0.5rem 1rem; border-radius: 2rem; font-weight: 600; font-size: 0.8rem; }
        .badge-expired { background: #fff3e0; color: #e65100; padding: 0.5rem 1rem; border-radius: 2rem; font-weight: 600; font-size: 0.8rem; }

        /* Custom Form Controls */
        .form-control-custom {
            background-color: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            font-weight: 500;
        }
        .form-control-custom:focus {
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.2);
            border-color: var(--brand-yellow);
        }

        .btn-dark-custom {
            background-color: var(--brand-dark);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn-dark-custom:hover {
            background-color: #0f172a;
            color: white;
        }

        .btn-outline-custom {
            border: 1px solid #cbd5e1;
            color: var(--brand-dark);
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn-outline-custom:hover {
            background-color: #f1f5f9;
            border-color: #94a3b8;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-section { padding: 60px 0 50px; }
            .hero-section::after { height: 20px; background-size: 40px 20px; background-image: radial-gradient(circle at 50% 0%, transparent 15px, var(--bg-light) 16px); }
            .status-timeline { padding-left: 1rem; }
            .status-item .status-icon { left: -1.6rem; }
        }
    </style>
</head>
<body>

<?php include 'header/nav.php'; ?>

<!-- HERO SECTION -->
<section class="hero-section">
    <div class="container position-relative" style="z-index: 2;">
        <div class="row">
            <div class="col-lg-8">
                <h1 class="mb-3">Business permits, from application to QR code</h1>
                <p class="mb-4">Create an account, upload your documents, pay online or at the counter, then download your permit. The service is free.</p>
                <div class="d-flex flex-wrap gap-3">
                    <!-- UPDATED BUTTONS TO TRIGGER THE COMBINED AUTH MODAL -->
                    <button type="button" class="btn btn-warning-custom" data-bs-toggle="modal" data-bs-target="#authModal" data-tab="login">
                        Log in to apply
                    </button>
                    <button type="button" class="btn btn-outline-light-custom" data-bs-toggle="modal" data-bs-target="#authModal" data-tab="register">
                        Create account
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- HOW IT WORKS SECTION -->
<section class="py-5">
    <div class="container py-4">
        <h2 class="fw-bold mb-2">How it works</h2>
        <p class="text-muted mb-5">Four steps. You can check progress at any time.</p>
        
        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="step-item">
                    <div class="step-line"></div>
                    <div class="step-number">1</div>
                    <h5 class="fw-bold mt-3 mb-1">Register</h5>
                    <p class="text-muted small mb-0">Create your account with your email and mobile number.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="step-item">
                    <div class="step-line"></div>
                    <div class="step-number">2</div>
                    <h5 class="fw-bold mt-3 mb-1">Fill in the form</h5>
                    <p class="text-muted small mb-0">Complete the application and upload your documents.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="step-item">
                    <div class="step-line"></div>
                    <div class="step-number">3</div>
                    <h5 class="fw-bold mt-3 mb-1">Pay</h5>
                    <p class="text-muted small mb-0">Use GCash, bank transfer, or pay over the counter.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="step-item">
                    <div class="step-line"></div>
                    <div class="step-number">4</div>
                    <h5 class="fw-bold mt-3 mb-1">Get your permit</h5>
                    <p class="text-muted small mb-0">Download the digital permit with its QR code.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- TRACKING SECTION -->
<section class="pb-5" id="track-section">
    <div class="container pb-4">
        <div class="row g-4">
            <!-- Left Card: Track Application -->
            <div class="col-lg-7">
                <div class="custom-card h-100 p-4 p-md-5">
                    <h3 class="fw-bold mb-2">Track an application</h3>
                    <p class="text-muted mb-4">Anyone can track one. Enter the reference number from your receipt.</p>
                    
                    <div class="d-flex flex-column flex-sm-row gap-3 mb-4">
                        <input type="text" class="form-control form-control-custom flex-grow-1" placeholder="BP-2026-00087" id="trackRef" value="<?php echo htmlspecialchars($ref); ?>">
                        <button class="btn btn-dark-custom" type="button" id="trackBtn">Track</button>
                    </div>

                    <button class="btn btn-outline-custom w-100 mb-4" type="button" id="scanQrBtn">
                        <i class="bi bi-qr-code-scan me-1"></i> Scan QR Code
                    </button>

                    <!-- PHP DYNAMIC TIMELINE OUTPUT -->
                    <?php if ($isExpiredRenewed): ?>
                        <div class="alert alert-warning border-0 rounded-3 mb-4">
                            <div class="d-flex align-items-center gap-3">
                                <i class="bi bi-clock-history fs-2 text-warning"></i>
                                <div>
                                    <h6 class="fw-bold mb-1 text-warning-emphasis">Permit Expired & Already Renewed</h6>
                                    <p class="mb-0 small text-muted">
                                        This permit has expired and was renewed as <strong><?php echo htmlspecialchars($newReference); ?></strong>.
                                        Please track the new reference for the current permit.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between flex-wrap align-items-start border-bottom pb-3 mb-3">
                            <div>
                                <span class="badge-expired">
                                    <i class="bi bi-clock"></i> Expired / Renewed
                                </span>
                                <h5 class="mt-2 mb-0 fw-bold"><?php echo htmlspecialchars($ref); ?></h5>
                                <small class="text-muted">This is the old/expired permit record</small>
                            </div>
                            <div class="text-end mt-2 mt-sm-0">
                                <a href="permit.php?ref=<?php echo urlencode($newReference); ?>" class="btn btn-sm btn-dark-custom mt-2">
                                    <i class="bi bi-arrow-right"></i> View Renewed Permit
                                </a>
                            </div>
                        </div>

                        <?php if ($application): ?>
                            <div class="status-timeline">
                                <?php foreach ($timeline as $idx => $item): ?>
                                    <?php 
                                        $completed = $item['completed'] ?? false;
                                        $active = ($idx == count($timeline) - 1 && !$completed && !isset($item['is_verified']) && !isset($item['is_rejected']) && !isset($item['is_issued']));
                                        $is_verified = isset($item['is_verified']) && $item['is_verified'];
                                        $is_rejected = isset($item['is_rejected']) && $item['is_rejected'];
                                        $is_issued = isset($item['is_issued']) && $item['is_issued'];
                                    ?>
                                    <div class="status-item <?php echo $is_verified ? 'verified' : ($is_rejected ? 'rejected' : ($is_issued ? 'issued' : ($completed ? 'completed' : ($active ? 'active' : '')))); ?>">
                                        <div class="status-icon">
                                            <?php if ($is_verified): ?>
                                                <i class="bi bi-trophy-fill"></i>
                                            <?php elseif ($is_rejected): ?>
                                                <i class="bi bi-x-circle-fill"></i>
                                            <?php elseif ($is_issued): ?>
                                                <i class="bi bi-check-circle-fill"></i>
                                            <?php elseif ($completed): ?>
                                                <i class="bi bi-check-circle-fill"></i>
                                            <?php elseif ($active): ?>
                                                <i class="bi bi-hourglass-split"></i>
                                            <?php else: ?>
                                                <i class="bi bi-clock"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="status-text <?php echo $is_verified ? 'verified-text' : ($is_rejected ? 'rejected-text' : ($is_issued ? 'issued-text' : '')); ?>">
                                            <?php echo htmlspecialchars($item['step']); ?>
                                        </div>
                                        <div class="status-date"><?php echo htmlspecialchars($item['date']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-exclamation-triangle fs-1 text-warning"></i>
                                <h5 class="mt-3">Expired record not found</h5>
                                <p class="text-muted">The expired permit record could not be found in the database.</p>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($application): ?>
                        <div class="d-flex justify-content-between flex-wrap align-items-start border-bottom pb-3 mb-3">
                            <div>
                                <?php if ($status === 'verified'): ?>
                                    <span class="badge-verified"><i class="bi bi-trophy"></i> Verified</span>
                                <?php elseif ($status === 'rejected' || $status === 'cancelled'): ?>
                                    <span class="badge-rejected"><i class="bi bi-x-circle"></i> <?php echo ucfirst($status); ?></span>
                                <?php elseif (in_array($status, ['approved', 'claimed'])): ?>
                                    <span class="badge bg-success text-white px-3 py-2 rounded-pill" style="font-size: 0.8rem;"><i class="bi bi-check-circle-fill"></i> Active permit</span>
                                <?php elseif ($status === 'under_review'): ?>
                                    <span class="badge bg-warning text-dark px-3 py-2 rounded-pill" style="font-size: 0.8rem;"><i class="bi bi-hourglass-split"></i> Under review</span>
                                <?php elseif ($status === 'payment_confirmed'): ?>
                                    <span class="badge bg-info text-dark px-3 py-2 rounded-pill" style="font-size: 0.8rem;"><i class="bi bi-credit-card"></i> Payment confirmed</span>
                                <?php elseif ($status === 'pending_payment'): ?>
                                    <span class="badge bg-warning text-dark px-3 py-2 rounded-pill" style="font-size: 0.8rem;"><i class="bi bi-cash"></i> Payment pending</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary text-white px-3 py-2 rounded-pill" style="font-size: 0.8rem;"><i class="bi bi-clock"></i> <?php echo ucfirst(str_replace('_', ' ', $status)); ?></span>
                                <?php endif; ?>
                                <h5 class="mt-2 mb-0 fw-bold"><?php echo htmlspecialchars($application['reference_no']); ?></h5>
                            </div>
                            <div class="text-end mt-2 mt-sm-0">
                                <small class="text-muted d-block">Business name</small>
                                <strong><?php echo htmlspecialchars($business_name); ?></strong>
                            </div>
                        </div>

                        <div class="status-timeline">
                            <?php foreach ($timeline as $idx => $item): ?>
                                <?php 
                                    $completed = $item['completed'] ?? false;
                                    $active = ($idx == count($timeline) - 1 && !$completed && !isset($item['is_verified']) && !isset($item['is_rejected']) && !isset($item['is_issued']));
                                    $is_verified = isset($item['is_verified']) && $item['is_verified'];
                                    $is_rejected = isset($item['is_rejected']) && $item['is_rejected'];
                                    $is_issued = isset($item['is_issued']) && $item['is_issued'];
                                ?>
                                <div class="status-item <?php echo $is_verified ? 'verified' : ($is_rejected ? 'rejected' : ($is_issued ? 'issued' : ($completed ? 'completed' : ($active ? 'active' : '')))); ?>">
                                    <div class="status-icon">
                                        <?php if ($is_verified): ?>
                                            <i class="bi bi-trophy-fill"></i>
                                        <?php elseif ($is_rejected): ?>
                                            <i class="bi bi-x-circle-fill"></i>
                                        <?php elseif ($is_issued): ?>
                                            <i class="bi bi-check-circle-fill"></i>
                                        <?php elseif ($completed): ?>
                                            <i class="bi bi-check-circle-fill"></i>
                                        <?php elseif ($active): ?>
                                            <i class="bi bi-hourglass-split"></i>
                                        <?php else: ?>
                                            <i class="bi bi-clock"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="status-text <?php echo $is_verified ? 'verified-text' : ($is_rejected ? 'rejected-text' : ($is_issued ? 'issued-text' : '')); ?>">
                                        <?php echo htmlspecialchars($item['step']); ?>
                                    </div>
                                    <div class="status-date"><?php echo htmlspecialchars($item['date']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($status === 'verified'): ?>
                            <div class="mt-4 p-3 rounded-3 d-flex align-items-center justify-content-between flex-wrap" style="background: #e6f9f2; border: 1px solid var(--primary-green);">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="bi bi-trophy fs-3" style="color: var(--primary-green);"></i>
                                    <div>
                                        <div class="fw-bold" style="color: var(--primary-green-dark);">Application Verified</div>
                                        <small class="text-muted">This application has been verified and approved by the BPLO</small>
                                    </div>
                                </div>
                                <span class="badge-verified mt-2 mt-sm-0">Verified</span>
                            </div>
                        <?php elseif ($status === 'rejected' || $status === 'cancelled'): ?>
                            <div class="mt-4 p-3 rounded-3 d-flex align-items-center justify-content-between flex-wrap bg-danger bg-opacity-10 border border-danger">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="bi bi-x-circle-fill fs-3 text-danger"></i>
                                    <div>
                                        <div class="fw-bold text-danger"><?php echo ($status === 'cancelled') ? 'Application Cancelled' : 'Application Rejected'; ?></div>
                                        <small class="text-muted">This application has been <?php echo ($status === 'cancelled') ? 'cancelled' : 'rejected'; ?> by the BPLO</small>
                                    </div>
                                </div>
                                <span class="badge-rejected mt-2 mt-sm-0"><?php echo ucfirst($status); ?></span>
                            </div>
                        <?php elseif (in_array($status, ['approved', 'claimed'])): ?>
                            <div class="mt-4 p-3 rounded-3 d-flex align-items-center justify-content-between flex-wrap border">
                                <div class="d-flex align-items-center gap-3">
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($application['reference_no']); ?>" 
                                         alt="QR Code" style="width: 80px; height: 80px;">
                                    <div>
                                        <div class="fw-bold text-dark">Permit issued</div>
                                        <small class="text-muted">Scan to verify authenticity</small>
                                    </div>
                                </div>
                                <span class="badge bg-success text-white mt-2 mt-sm-0">Active</span>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 p-3 rounded-3 d-flex align-items-center justify-content-between flex-wrap bg-light border">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="bi bi-upc-scan fs-3 text-muted"></i>
                                    <div>
                                        <div class="fw-bold text-dark">QR code will appear here once issued</div>
                                        <small class="text-muted">Scannable permit for validation</small>
                                    </div>
                                </div>
                                <span class="badge bg-secondary text-white mt-2 mt-sm-0">Preview</span>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($ref): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-exclamation-triangle fs-1 text-warning"></i>
                            <h5 class="mt-3">Application not found</h5>
                            <p class="text-muted">No permit or application matches reference <strong><?php echo htmlspecialchars($ref); ?></strong>.</p>
                            <p class="small text-muted">Please check the number or try again.</p>
                        </div>
                    <?php else: ?>
                        <!-- Default empty state / Demo data to match the screenshot -->
                        <div class="d-flex justify-content-between flex-wrap align-items-start border-bottom pb-3 mb-3">
                            <div>
                                <span class="badge bg-dark text-white px-3 py-2 rounded-pill" style="font-size: 0.8rem;">
                                    <i class="bi bi-check-circle-fill"></i> Active application
                                </span>
                                <h5 class="mt-2 mb-0 fw-bold">BP-2026-00087</h5>
                            </div>
                            <div class="text-end mt-2 mt-sm-0">
                                <small class="text-muted d-block">Business name</small>
                                <strong>Della Cruz General Merchandise</strong>
                            </div>
                        </div>

                        <div class="status-timeline">
                            <div class="status-item completed">
                                <div class="status-icon"><i class="bi bi-check-circle-fill"></i></div>
                                <div class="status-text">Application submitted</div>
                                <div class="status-date">April 30, 2026 — 9:14 AM</div>
                            </div>
                            <div class="status-item completed">
                                <div class="status-icon"><i class="bi bi-check-circle-fill"></i></div>
                                <div class="status-text">Documents verified</div>
                                <div class="status-date">May 2, 2026 — 2:30 PM</div>
                            </div>
                            <div class="status-item completed">
                                <div class="status-icon"><i class="bi bi-check-circle-fill"></i></div>
                                <div class="status-text">Payment confirmed</div>
                                <div class="status-date">May 3, 2026 — 10:05 AM</div>
                            </div>
                            <div class="status-item active">
                                <div class="status-icon"><i class="bi bi-hourglass-split"></i></div>
                                <div class="status-text">Under review by licensing office</div>
                                <div class="status-date">In progress</div>
                            </div>
                            <div class="status-item">
                                <div class="status-icon"><i class="bi bi-clock"></i></div>
                                <div class="status-text">Permit issuance pending</div>
                                <div class="status-date">Pending</div>
                            </div>
                        </div>

                        <div class="mt-4 p-3 rounded-3 d-flex align-items-center justify-content-between flex-wrap bg-light border">
                            <div class="d-flex align-items-center gap-3">
                                <i class="bi bi-upc-scan fs-3 text-muted"></i>
                                <div>
                                    <div class="fw-bold text-dark">QR code will appear here once issued</div>
                                    <small class="text-muted">Scannable permit for validation</small>
                                </div>
                            </div>
                            <span class="badge bg-secondary text-white mt-2 mt-sm-0">Preview</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Card: Every permit comes with -->
            <div class="col-lg-5">
                <div class="dark-card h-100 p-4 p-md-5">
                    <h4 class="fw-bold mb-4">Every permit comes with</h4>
                    
                    <div class="mb-4">
                        <h6 class="feature-title">Online application</h6>
                        <p class="feature-desc">Send new or renewal forms from anywhere.</p>
                    </div>
                    <hr class="my-4">
                    
                    <div class="mb-4">
                        <h6 class="feature-title">Approved payment channels</h6>
                        <p class="feature-desc">Pay through LGU-approved options only.</p>
                    </div>
                    <hr class="my-4">
                    
                    <div class="mb-4">
                        <h6 class="feature-title">Status updates</h6>
                        <p class="feature-desc">See which office has your application.</p>
                    </div>
                    <hr class="my-4">
                    
                    <div>
                        <h6 class="feature-title">A scannable QR code</h6>
                        <p class="feature-desc">Anyone can scan it to check the permit is real.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'footer/footer.php'; ?>

<!-- QR Scanner Modal -->
<div class="modal fade" id="qrScannerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Scan QR Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center bg-light">
                <div id="qr-reader" class="mb-3"></div>
                <div id="qr-reader-results"></div>

                <!-- MOBILE-ONLY CONTROLS -->
                <div class="d-md-none mt-3">
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-custom" id="switchCamBtn">
                            <i class="bi bi-arrow-repeat"></i> Switch Camera
                        </button>
                        <button type="button" class="btn btn-outline-custom" id="takePhotoBtn">
                            <i class="bi bi-camera"></i> Take Photo
                        </button>
                        <button type="button" class="btn btn-outline-custom" id="uploadQrBtn">
                            <i class="bi bi-image"></i> Upload Image (Gallery / Screenshot)
                        </button>
                    </div>

                    <input type="file" id="qrCameraInput" accept="image/*" capture="environment" class="d-none">
                    <input type="file" id="qrFileInput" accept="image/*" class="d-none">

                    <small class="text-muted d-block mt-3">
                        Tip: If the QR is on another screen or blurred, use <strong>Take Photo</strong> or <strong>Upload Image</strong>.
                    </small>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-dark-custom w-100" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // ============================================================
    // AUTO-SWITCH TABS ON AUTH MODAL OPEN
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        const authModal = document.getElementById('authModal');
        if (authModal) {
            authModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget; // Button that triggered the modal
                if (button) {
                    const tab = button.getAttribute('data-tab');
                    if (tab === 'register') {
                        const registerTab = document.getElementById('register-tab');
                        if (registerTab) registerTab.click();
                    } else if (tab === 'login') {
                        const loginTab = document.getElementById('login-tab');
                        if (loginTab) loginTab.click();
                    }
                }
            });
        }
    });

    // Track button
    document.getElementById('trackBtn').addEventListener('click', function() {
        const ref = document.getElementById('trackRef').value.trim();
        if (!ref) {
            alert('Please enter a reference number (e.g., BP-2026-00123)');
            return;
        }
        window.location.href = 'permit.php?ref=' + encodeURIComponent(ref);
    });

    document.getElementById('trackRef').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('trackBtn').click();
        }
    });

    // ============================================================
    // Live camera scanner state
    // ============================================================
    let html5QrCode = null;
    let isScanning = false;
    let currentFacingMode = "environment";

    const qrScannerModalEl = document.getElementById('qrScannerModal');
    const qrScannerModal = new bootstrap.Modal(qrScannerModalEl);
    const qrResultsEl = document.getElementById('qr-reader-results');

    function setQrMessage(html) { qrResultsEl.innerHTML = html || ''; }

    function startQrScanner() {
        if (isScanning) return;
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("qr-reader");
        }
        const config = { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 };
        html5QrCode.start(
            { facingMode: currentFacingMode },
            config,
            onScanSuccess,
            onScanError
        ).then(() => { isScanning = true; })
        .catch(err => {
            isScanning = false;
            console.error('Camera start error:', err);
            setQrMessage('<div class="alert alert-warning small mb-0">Unable to start the camera. Please allow camera access, or use “Take Photo” / “Upload Image” instead.</div>');
        });
    }

    function stopQrScanner() {
        if (html5QrCode && isScanning) {
            return html5QrCode.stop()
                .then(() => { isScanning = false; })
                .catch(err => { isScanning = false; console.error('Stop error:', err); });
        }
        return Promise.resolve();
    }

    document.getElementById('scanQrBtn').addEventListener('click', function() {
        currentFacingMode = "environment";
        setQrMessage('');
        qrScannerModal.show();
        setTimeout(() => { startQrScanner(); }, 500);
    });

    document.getElementById('switchCamBtn').addEventListener('click', function() {
        currentFacingMode = (currentFacingMode === 'environment') ? 'user' : 'environment';
        setQrMessage('');
        stopQrScanner().then(() => startQrScanner());
    });

    // ============================================================
    // Robust image-based QR decoder
    // ============================================================
    function loadImageFromFile(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = (e) => { URL.revokeObjectURL(url); reject(e); };
            img.src = url;
        });
    }

    function cloneImageData(id) {
        try {
            return new ImageData(new Uint8ClampedArray(id.data), id.width, id.height);
        } catch (e) {
            const c = document.createElement('canvas');
            c.width = id.width; c.height = id.height;
            const cx = c.getContext('2d');
            cx.putImageData(id, 0, 0);
            return cx.getImageData(0, 0, id.width, id.height);
        }
    }

    function applyGrayscaleContrast(id, contrast) {
        const d = id.data;
        const intercept = 128 * (1 - contrast);
        for (let i = 0; i < d.length; i += 4) {
            let g = 0.299 * d[i] + 0.587 * d[i+1] + 0.114 * d[i+2];
            g = g * contrast + intercept;
            g = g < 0 ? 0 : g > 255 ? 255 : g;
            d[i] = d[i+1] = d[i+2] = g;
        }
        return id;
    }

    function applyBinarize(id, threshold) {
        const d = id.data;
        for (let i = 0; i < d.length; i += 4) {
            const g = 0.299 * d[i] + 0.587 * d[i+1] + 0.114 * d[i+2];
            const v = g > threshold ? 255 : 0;
            d[i] = d[i+1] = d[i+2] = v;
        }
        return id;
    }

    async function decodeQrFromImage(file) {
        const img = await loadImageFromFile(file);

        let detector = null;
        try {
            if ('BarcodeDetector' in window) {
                const fmts = await BarcodeDetector.getSupportedFormats();
                if (fmts && fmts.includes('qr_code')) {
                    detector = new BarcodeDetector({ formats: ['qr_code'] });
                }
            }
        } catch (e) { /* ignore */ }

        const longest = Math.max(img.width, img.height);
        const baseScale = longest > 1600 ? 1600 / longest : 1;

        const srcCanvas = document.createElement('canvas');
        srcCanvas.width  = Math.max(1, Math.round(img.width  * baseScale));
        srcCanvas.height = Math.max(1, Math.round(img.height * baseScale));
        srcCanvas.getContext('2d').drawImage(img, 0, 0, srcCanvas.width, srcCanvas.height);

        const workCanvas = document.createElement('canvas');
        const workCtx = workCanvas.getContext('2d', { willReadFrequently: true });

        const rotations = [0, 90, 180, 270];
        const scaleFactors = [1, 1.5, 0.75, 2];

        for (const rot of rotations) {
            const rotCanvas = document.createElement('canvas');
            const rotCtx = rotCanvas.getContext('2d', { willReadFrequently: true });

            if (rot === 0 || rot === 180) {
                rotCanvas.width  = srcCanvas.width;
                rotCanvas.height = srcCanvas.height;
            } else {
                rotCanvas.width  = srcCanvas.height;
                rotCanvas.height = srcCanvas.width;
            }
            rotCtx.translate(rotCanvas.width / 2, rotCanvas.height / 2);
            rotCtx.rotate(rot * Math.PI / 180);
            rotCtx.drawImage(srcCanvas, -srcCanvas.width / 2, -srcCanvas.height / 2);

            for (const s of scaleFactors) {
                const w = Math.round(rotCanvas.width  * s);
                const h = Math.round(rotCanvas.height * s);
                if (w < 100 || h < 100 || w > 2500 || h > 2500) continue;

                workCanvas.width = w;
                workCanvas.height = h;
                workCtx.drawImage(rotCanvas, 0, 0, w, h);

                if (detector) {
                    try {
                        const codes = await detector.detect(workCanvas);
                        if (codes && codes.length) return codes[0].rawValue;
                    } catch (e) { /* ignore */ }
                }

                const orig = workCtx.getImageData(0, 0, w, h);

                try {
                    const c = jsQR(orig.data, w, h, { inversionAttempts: 'attemptBoth' });
                    if (c && c.data) return c.data;
                } catch (e) { /* ignore */ }

                const gcId = cloneImageData(orig);
                applyGrayscaleContrast(gcId, 1.6);
                try {
                    const c = jsQR(gcId.data, w, h, { inversionAttempts: 'attemptBoth' });
                    if (c && c.data) return c.data;
                } catch (e) { /* ignore */ }

                for (const t of [100, 128, 160, 190]) {
                    const bId = cloneImageData(orig);
                    applyBinarize(bId, t);
                    try {
                        const c = jsQR(bId.data, w, h, { inversionAttempts: 'attemptBoth' });
                        if (c && c.data) return c.data;
                    } catch (e) { /* ignore */ }
                }

                if (detector) {
                    try {
                        workCtx.putImageData(gcId, 0, 0);
                        const codes = await detector.detect(workCanvas);
                        if (codes && codes.length) return codes[0].rawValue;
                    } catch (e) { /* ignore */ }
                }
            }
        }

        return null;
    }

    // ============================================================
    // Mobile-only: Take Photo / Upload Image
    // ============================================================
    function handleQrFileInput(inputId) {
        const inputEl = document.getElementById(inputId);
        if (!inputEl) return;

        inputEl.addEventListener('change', async function(e) {
            const file = e.target.files && e.target.files[0];
            e.target.value = ''; 
            if (!file) return;

            setQrMessage('<div class="text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>Reading image…</div>');

            await stopQrScanner();

            try {
                const decoded = await decodeQrFromImage(file);
                if (decoded) {
                    setQrMessage('');
                    onScanSuccess(decoded);
                } else {
                    setQrMessage('<div class="alert alert-warning small mb-0">No QR code detected in that image. Try a clearer / closer photo, or crop the image so the QR fills more of it.</div>');
                    startQrScanner();
                }
            } catch (err) {
                console.error('Decode error:', err);
                setQrMessage('<div class="alert alert-warning small mb-0">Error reading image. Please try again.</div>');
                startQrScanner();
            }
        });
    }

    handleQrFileInput('qrCameraInput'); 
    handleQrFileInput('qrFileInput');   

    document.getElementById('takePhotoBtn').addEventListener('click', function() {
        document.getElementById('qrCameraInput').click();
    });

    document.getElementById('uploadQrBtn').addEventListener('click', function() {
        document.getElementById('qrFileInput').click();
    });

    qrScannerModalEl.addEventListener('hidden.bs.modal', function () {
        stopQrScanner();
        setQrMessage('');
    });

    function onScanSuccess(decodedText, decodedResult) {
        let ref = (decodedText || '').trim();

        if (ref.includes('?') || ref.includes('ref=') || /^https?:\/\//i.test(ref)) {
            try {
                const url = new URL(ref, window.location.origin + window.location.pathname);
                const refParam = url.searchParams.get('ref');
                if (refParam) ref = refParam;
            } catch (e) {
                const match = ref.match(/[?&]ref=([^&]+)/);
                if (match) ref = decodeURIComponent(match[1]);
            }
        }

        document.getElementById('trackRef').value = ref;
        document.getElementById('trackBtn').click();
        qrScannerModal.hide();
    }

    function onScanError(errorMessage) { /* ignore */ }
</script>

</body>
</html>