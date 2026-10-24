<?php
session_start();
require_once 'config/connect.php';
require_once 'includes/functions.php';

// Enable error reporting for debugging (remove after testing)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$ref = isset($_GET['ref']) ? trim($_GET['ref']) : '';
$error = null;
$application = null;
$ownerData = null;

if (!empty($ref)) {
    $sql = "SELECT 
                ba.*,
                bid.business_name,
                bid.trade_name,
                bid.last_name as owner_last_name,
                bid.first_name as owner_first_name,
                bid.middle_name as owner_middle_name,
                atd.application_type,
                atd.payment_mode,
                atd.tin_no,
                bad.business_address,
                bad.email,
                bad.mobile
            FROM business_applications ba
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN application_type_data atd ON ba.id = atd.application_id
            LEFT JOIN business_address_data bad ON ba.id = bad.application_id
            WHERE ba.reference_no = ? AND ba.status IN ('approved', 'claimed', 'verified', 'under_review', 'pending_payment')
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $application = $row;
        $ownerStmt = $conn->prepare("SELECT * FROM owner_info_data WHERE application_id = ?");
        $ownerStmt->bind_param("i", $row['id']);
        $ownerStmt->execute();
        $ownerData = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
    } else {
        $error = "Permit not found or not yet approved.";
    }
    $stmt->close();
} else {
    $error = "No reference number provided.";
}

function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function getExpiryStatus($expiry_date) {
    if (empty($expiry_date)) return ['label' => 'N/A', 'class' => 'text-muted'];
    $now = new DateTime();
    $expiry = new DateTime($expiry_date);
    $diff = $now->diff($expiry);
    $days = (int)$diff->format('%r%a');
    if ($days < 0) return ['label' => 'Expired', 'class' => 'text-danger'];
    if ($days <= 30) return ['label' => 'Expires soon', 'class' => 'text-warning'];
    return ['label' => 'Valid', 'class' => 'text-success'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit Verification | Placer</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f5f7fa; min-height: 100vh; display: flex; flex-direction: column; }
        .container { max-width: 900px; margin: 2rem auto; flex: 1; }
        .card { border-radius: 1.5rem; box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.05); border: 1px solid rgba(0,0,0,0.04); overflow: hidden; }
        .card-header { background: #1e4b2e; color: white; padding: 1.5rem; border-bottom: none; }
        .card-header h2 { font-weight: 700; margin: 0; }
        .card-body { padding: 2rem; background: white; }
        .status-badge {
            padding: 0.35rem 1rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .status-approved { background: #e0f5e8; color: #198754; }
        .status-claimed { background: #e3f0fc; color: #0d6efd; }
        .status-verified { background: #d1ecf1; color: #0c5460; }
        .status-under_review { background: #fff3e0; color: #cc7b00; }
        .status-pending_payment { background: #ffe8e8; color: #dc3545; }
        .detail-row { display: flex; padding: 0.6rem 0; border-bottom: 1px solid #eef2f5; }
        .detail-label { width: 35%; font-weight: 600; color: #2c5a3a; }
        .detail-value { width: 65%; color: #1a202c; }
        .qr-container { background: #f8f9fa; border-radius: 1rem; padding: 1rem; text-align: center; }
        .qr-container img { max-width: 120px; }
        .footer { text-align: center; padding: 1.5rem 0; color: #6c757d; font-size: 0.85rem; }
        .error-icon { font-size: 3rem; color: #dc3545; }
        .expiry-badge { font-size: 0.8rem; font-weight: 600; padding: 0.2rem 0.6rem; border-radius: 1rem; margin-left: 0.5rem; }
        .expiry-valid { background: #d4edda; color: #155724; }
        .expiry-soon { background: #fff3cd; color: #856404; }
        .expiry-expired { background: #f8d7da; color: #721c24; }
        @media (max-width: 768px) {
            .detail-label { width: 100%; }
            .detail-value { width: 100%; }
            .card-body { padding: 1.2rem; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="card-header d-flex align-items-center gap-3">
            <i class="bi bi-check-circle-fill fs-3"></i>
            <div>
                <h2>Permit Verification</h2>
                <small class="opacity-75">Municipality of Placer, Surigao del Norte</small>
            </div>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="text-center py-4">
                    <div class="error-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    <h4 class="mt-3 text-danger"><?php echo htmlspecialchars($error); ?></h4>
                    <p class="text-muted">Please check the reference number and try again.</p>
                    <a href="dashboard.php" class="btn btn-outline-success rounded-pill mt-3"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
                </div>
            <?php elseif ($application): 
                $expStatus = getExpiryStatus($application['expiry_date']);
            ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="fw-bold mb-0" style="color: #1e4b2e;"><?php echo htmlspecialchars($application['business_name']); ?></h5>
                        <span class="text-muted small"><?php echo htmlspecialchars($application['reference_no']); ?></span>
                    </div>
                    <span class="status-badge status-<?php echo $application['status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $application['status'])); ?>
                    </span>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <h6 class="fw-bold text-uppercase small text-muted mb-3">Business Details</h6>
                        <div class="detail-row"><span class="detail-label">Business Name</span><span class="detail-value"><?php echo htmlspecialchars($application['business_name']); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Trade Name</span><span class="detail-value"><?php echo htmlspecialchars($application['trade_name'] ?: '—'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Address</span><span class="detail-value"><?php echo htmlspecialchars($application['business_address'] ?: '—'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Application Type</span><span class="detail-value"><?php echo htmlspecialchars($application['application_type']); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Payment Mode</span><span class="detail-value"><?php echo htmlspecialchars($application['payment_mode']); ?></span></div>
                        <div class="detail-row"><span class="detail-label">TIN</span><span class="detail-value"><?php echo htmlspecialchars($application['tin_no']); ?></span></div>
                        <?php if (!empty($application['expiry_date'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Expiry Date</span>
                                <span class="detail-value">
                                    <?php echo formatDate($application['expiry_date']); ?>
                                    <span class="expiry-badge expiry-<?php echo strtolower(str_replace(' ', '-', $expStatus['label'])); ?>"><?php echo $expStatus['label']; ?></span>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold text-uppercase small text-muted mb-3">Owner / Contact</h6>
                        <?php
                        $ownerName = trim(($ownerData['first_name'] ?? '') . ' ' . ($ownerData['middle_name'] ?? '') . ' ' . ($ownerData['last_name'] ?? ''));
                        ?>
                        <div class="detail-row"><span class="detail-label">Owner</span><span class="detail-value"><?php echo htmlspecialchars($ownerName ?: '—'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Email</span><span class="detail-value"><?php echo htmlspecialchars($application['email'] ?: '—'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Mobile</span><span class="detail-value"><?php echo htmlspecialchars($application['mobile'] ?: '—'); ?></span></div>
                        <?php if ($ownerData && !empty($ownerData['owner_address'])): ?>
                            <div class="detail-row"><span class="detail-label">Owner Address</span><span class="detail-value"><?php echo htmlspecialchars($ownerData['owner_address']); ?></span></div>
                        <?php endif; ?>
                        <div class="detail-row"><span class="detail-label">Filed on</span><span class="detail-value"><?php echo formatDate($application['created_at']); ?></span></div>
                        <?php if ($application['submitted_at']): ?>
                            <div class="detail-row"><span class="detail-label">Submitted on</span><span class="detail-value"><?php echo formatDate($application['submitted_at']); ?></span></div>
                        <?php endif; ?>
                        <?php if (in_array($application['status'], ['approved', 'claimed', 'verified'])): ?>
                            <div class="detail-row"><span class="detail-label">Validity</span><span class="detail-value">Valid until <?php echo !empty($application['expiry_date']) ? formatDate($application['expiry_date']) : 'Dec 31, ' . date('Y'); ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-light rounded-3">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div>
                            <i class="bi bi-qr-code fs-1 text-success"></i>
                        </div>
                        <div>
                            <p class="mb-1 fw-semibold">Scan again to verify</p>
                            <p class="text-muted small mb-0">This QR code is unique to this permit. Anyone can scan it to confirm its validity.</p>
                        </div>
                        <div class="ms-auto">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode('track.php?ref=' . urlencode($ref)); ?>" alt="QR Code" class="img-fluid rounded" style="max-width:100px;">
                        </div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2 flex-wrap">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left"></i> Dashboard</a>
                        <?php if (in_array($application['status'], ['approved', 'claimed', 'verified'])): ?>
                            <a href="renew_permit.php?renew=<?php echo $application['id']; ?>" class="btn btn-success rounded-pill"><i class="bi bi-arrow-repeat"></i> Renew This Permit</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="register.php" class="btn btn-outline-success rounded-pill">Apply for a permit</a>
                        <a href="index.php" class="btn btn-outline-secondary rounded-pill">Back to Home</a>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        <p>© <?php echo date('Y'); ?> Municipality of Placer, Surigao del Norte. All rights reserved.</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>