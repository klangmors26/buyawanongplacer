<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

require_once 'config/connect.php';
require_once 'includes/functions.php';

$user_id = $_SESSION['user_id'];

// Helper: get expiry status
function getExpiryStatus($expiry_date) {
    if (empty($expiry_date)) return ['label' => 'N/A', 'key' => 'unknown'];
    $now = new DateTime();
    $expiry = new DateTime($expiry_date);
    $diff = $now->diff($expiry);
    $days = (int)$diff->format('%r%a');
    if ($days < 0) return ['label' => 'Expired', 'key' => 'expired'];
    if ($days <= 30) return ['label' => 'Expiring soon', 'key' => 'soon'];
    return ['label' => 'Valid', 'key' => 'valid'];
}

// --- Handle selection of a permit to renew ---
if (isset($_GET['renew']) && is_numeric($_GET['renew'])) {
    $original_id = (int)$_GET['renew'];

    // Verify the permit belongs to the user and is eligible
    $check = $conn->prepare("SELECT id, expiry_date FROM business_applications WHERE id = ? AND user_id = ? AND status IN ('approved','claimed','verified')");
    $check->bind_param("ii", $original_id, $user_id);
    $check->execute();
    $result = $check->get_result();
    if ($result->num_rows === 0) {
        die('Invalid application selected.');
    }
    $permit = $result->fetch_assoc();
    $check->close();

    // Optional renewal window warnings
    $expStatus = getExpiryStatus($permit['expiry_date']);
    if ($expStatus['key'] === 'expired') {
        $_SESSION['renewal_warning'] = 'This permit has already expired. Renewal may be subject to penalties.';
    } elseif ($expStatus['key'] === 'valid' && !empty($permit['expiry_date'])) {
        $now = new DateTime();
        $expiry = new DateTime($permit['expiry_date']);
        $diff = $now->diff($expiry);
        $days = (int)$diff->format('%r%a');
        if ($days > 60) {
            $_SESSION['renewal_warning'] = 'This permit is not yet within the 60-day renewal window. You can still renew, but we recommend waiting until closer to expiry.';
        }
    }

    // Delete any existing draft to avoid conflicts
    $draft = $conn->query("SELECT id FROM business_applications WHERE user_id = $user_id AND status = 'draft' LIMIT 1")->fetch_assoc();
    if ($draft) {
        $draftId = $draft['id'];
        $conn->query("DELETE FROM application_type_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM business_info_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM business_address_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM owner_info_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM additional_info_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM document_uploads WHERE application_id = $draftId");
        $conn->query("DELETE FROM appointments WHERE application_id = $draftId");
        $conn->query("DELETE FROM business_applications WHERE id = $draftId");
    }

    // Create a fresh draft
    $newApp = getOrCreateDraftApplication($user_id);
    $new_id = $newApp['id'];
    if (!$new_id) {
        die('Failed to create a new draft application.');
    }

    // Copy all data from the original permit
    $copyResult = copyApplicationData($original_id, $new_id, $conn);
    if (!$copyResult) {
        die('Failed to copy application data. Please try again.');
    }

    // Set application type to 'Renewal' and store the original ID
    $conn->query("UPDATE business_applications SET renewed_from_id = $original_id, current_step = 1 WHERE id = $new_id");

    $checkType = $conn->query("SELECT id FROM application_type_data WHERE application_id = $new_id");
    if ($checkType->num_rows === 0) {
        $conn->query("INSERT INTO application_type_data (application_id, application_type) VALUES ($new_id, 'Renewal')");
    } else {
        $conn->query("UPDATE application_type_data SET application_type = 'Renewal' WHERE application_id = $new_id");
    }

    header('Location: new_permit.php');
    exit;
}

// --- List eligible permits for renewal ---
$eligible = $conn->query("
    SELECT ba.id, ba.reference_no, ba.created_at, ba.expiry_date,
           COALESCE(bid.business_name, 'Untitled') as business_name
    FROM business_applications ba
    LEFT JOIN business_info_data bid ON ba.id = bid.application_id
    WHERE ba.user_id = $user_id AND ba.status IN ('approved','claimed','verified')
    ORDER BY ba.created_at DESC
");
if (!$eligible) {
    die('Error fetching eligible permits: ' . $conn->error);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Renew Business Permit | Buyawanong Placer</title>
    
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand-dark: #1e293b;
            --brand-yellow: #fbbf24;
            --bg-light: #f8fafc;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --green: #10b981;
            --yellow: #f59e0b;
            --red: #ef4444;
        }

        * { font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-light); color: var(--brand-dark); }

        /* Back Link */
        .back-link {
            color: var(--brand-dark);
            text-decoration: underline;
            text-decoration-color: var(--border-color);
            text-underline-offset: 3px;
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
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            letter-spacing: -0.02em;
            color: var(--brand-dark);
            margin-bottom: 4px;
        }
        .page-subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        /* Permit Card */
        .permit-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 12px;
            text-decoration: none;
            display: block;
            color: var(--brand-dark);
            transition: all 0.2s ease;
        }
        .permit-card:hover {
            border-color: var(--brand-dark);
            box-shadow: 0 8px 20px -8px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
            color: var(--brand-dark);
        }
        .permit-card .business-name {
            font-weight: 700;
            font-size: 1.05rem;
            margin-bottom: 4px;
        }
        .permit-card .permit-ref {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .permit-card .permit-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .permit-card .permit-meta strong { color: var(--brand-dark); font-weight: 600; }

        /* Expiry Badge */
        .expiry-badge {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            margin-left: 6px;
        }
        .expiry-valid { background: #d1fae5; color: #065f46; }
        .expiry-soon { background: #fef3c7; color: #92400e; }
        .expiry-expired { background: #fee2e2; color: #991b1b; }
        .expiry-unknown { background: #f1f5f9; color: #475569; }

        /* Renew Indicator */
        .renew-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--brand-dark);
        }
        .renew-indicator i {
            font-size: 1rem;
            transition: transform 0.2s;
        }
        .permit-card:hover .renew-indicator i {
            transform: translateX(4px);
        }

        /* Empty state */
        .empty-state {
            background: #ffffff;
            border: 1px dashed var(--border-color);
            border-radius: 12px;
            padding: 3rem 2rem;
            text-align: center;
        }
        .empty-state i { font-size: 2.5rem; color: var(--text-muted); }
        .empty-state p { color: var(--text-muted); margin: 1rem 0; }

        @media (max-width: 576px) {
            .permit-card { padding: 1rem; }
            .permit-card .business-name { font-size: 0.95rem; }
        }
    </style>
</head>
<body>

<?php include 'header/nav.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 900px;">

    <!-- Back link -->
    <div class="mb-3">
        <a href="dashboard.php" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to dashboard
        </a>
    </div>

    <!-- Title -->
    <div class="mb-4">
        <h1 class="page-title">Renew business permit</h1>
        <p class="page-subtitle">Select an approved, claimed, or verified permit to renew.</p>
    </div>

    <?php if ($eligible->num_rows === 0): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p>You have no permits that are eligible for renewal.</p>
            <a href="new_permit.php" class="btn btn-dark" style="background-color:var(--brand-dark);border:none;border-radius:8px;padding:10px 24px;font-weight:600;">
                Start a new application
            </a>
        </div>
    <?php else: ?>
        <?php while ($row = $eligible->fetch_assoc()):
            $expStatus = getExpiryStatus($row['expiry_date']);
        ?>
            <a href="?renew=<?php echo $row['id']; ?>" class="permit-card">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div class="flex-grow-1">
                        <div class="business-name"><?php echo htmlspecialchars($row['business_name']); ?></div>
                        <div class="permit-ref">
                            <?php echo htmlspecialchars($row['reference_no']); ?>
                            &middot; Filed on <?php echo date('M j, Y', strtotime($row['created_at'])); ?>
                        </div>
                        <div class="permit-meta">
                            <?php if (!empty($row['expiry_date'])): ?>
                                Expires: <strong><?php echo date('M j, Y', strtotime($row['expiry_date'])); ?></strong>
                                <span class="expiry-badge expiry-<?php echo $expStatus['key']; ?>">
                                    <?php echo $expStatus['label']; ?>
                                </span>
                            <?php else: ?>
                                <span class="expiry-badge expiry-unknown">No expiry</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="renew-indicator">
                        Renew <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
        <?php endwhile; ?>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if (!empty($_SESSION['renewal_warning'])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    Swal.fire({
        icon: 'info',
        title: 'Renewal Notice',
        text: <?php echo json_encode($_SESSION['renewal_warning']); ?>,
        confirmButtonColor: '#1e293b'
    });
</script>
<?php unset($_SESSION['renewal_warning']); endif; ?>

</body>
</html>