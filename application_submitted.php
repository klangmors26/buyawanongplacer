<?php
session_start();
require_once 'config/connect.php';
require_once 'includes/functions.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

$ref = $_GET['ref'] ?? '';
if (!$ref) {
    die('Invalid reference number.');
}

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT id, submitted_at, created_at FROM business_applications WHERE reference_no = ? AND user_id = ?");
$stmt->bind_param('si', $ref, $user_id);
$stmt->execute();
$appResult = $stmt->get_result();
$app = $appResult->fetch_assoc();
if (!$app) {
    die('Application not found.');
}
$app_id = $app['id'];

// ============================================================
// NOTIFY STAFF + APPLICANT (one-time per session per application)
// ============================================================
$notifKey = 'notified_app_' . $app_id;

if (empty($_SESSION[$notifKey])) {
    $_SESSION[$notifKey] = true;

    $metaStmt = $conn->prepare("
        SELECT bi.business_name,
               at.application_type,
               CONCAT(acc.firstname, ' ', acc.lastname) AS applicant_name
        FROM business_applications ba
        LEFT JOIN business_info_data    bi  ON ba.id = bi.application_id
        LEFT JOIN application_type_data at  ON ba.id = at.application_id
        LEFT JOIN accounts              acc ON ba.user_id = acc.id
        WHERE ba.id = ?
        LIMIT 1
    ");
    if ($metaStmt) {
        $metaStmt->bind_param('i', $app_id);
        $metaStmt->execute();
        $meta = $metaStmt->get_result()->fetch_assoc();
        $metaStmt->close();

        $bizName       = $meta['business_name']    ?? 'a business';
        $applicantName = $meta['applicant_name']   ?? 'An applicant';
        $appType       = $meta['application_type'] ?? 'Business Permit';

        $title   = 'New Permit Application Submitted';
        $message = "$applicantName submitted a $appType application for \"$bizName\" (Ref: $ref).";
        $link    = 'index.php';
        $type    = 'permit';

        // Notify staff (Treasury=3, BPLO=4, Admin=5)
        $staffStmt = $conn->prepare("SELECT id FROM accounts WHERE user_type IN (3, 4, 5)");
        if ($staffStmt) {
            $staffStmt->execute();
            $staffResult = $staffStmt->get_result();

            $insertStmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, link)
                VALUES (?, ?, ?, ?, ?)
            ");
            if ($insertStmt) {
                while ($staffRow = $staffResult->fetch_assoc()) {
                    $staffId = (int)$staffRow['id'];
                    $insertStmt->bind_param('issss', $staffId, $type, $title, $message, $link);
                    $insertStmt->execute();
                }
                $insertStmt->close();
            }
            $staffStmt->close();
        }

        // Notify the applicant (confirmation)
        $appNotifStmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, 'success', 'Application Submitted', ?, 'dashboard.php')
        ");
        if ($appNotifStmt) {
            $appMsg = "Your $appType application for \"$bizName\" has been received. Ref: $ref.";
            $appNotifStmt->bind_param('is', $user_id, $appMsg);
            $appNotifStmt->execute();
            $appNotifStmt->close();
        }
    }
}

// ============================================================
// REST OF PAGE
// ============================================================
$submittedDate = $app['submitted_at'] ?? $app['created_at'] ?? date('Y-m-d H:i:s');

// Absolute URL for QR code
$scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
$base_url = $scheme . '://' . $host . $base_dir . '/';

$track_url = $base_url . "permit.php?ref=" . urlencode($ref);
$qr_url    = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($track_url);

$submitted_at = date('M j, Y, g:i A', strtotime($submittedDate));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Application Submitted | Buyawanong Placer</title>
    
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand-dark: #1e293b;
            --brand-dark-hover: #0f172a;
            --bg-light: #f8fafc;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --green: #10b981;
        }

        * { font-family: 'Inter', sans-serif; }
        body {
            background-color: var(--bg-light);
            color: var(--brand-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .confirm-card {
            max-width: 900px;
            margin: 60px auto 80px;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 24px -4px rgba(15, 23, 42, 0.08);
            background: #ffffff;
            border: 1px solid #e2e8f0;
            flex-grow: 0;
        }

        .confirm-header {
            background-color: var(--brand-dark);
            color: #ffffff;
            padding: 32px 40px 28px;
        }
        .confirm-header h1 {
            font-weight: 800;
            font-size: clamp(1.5rem, 3vw, 2rem);
            letter-spacing: -0.02em;
            margin-bottom: 6px;
            color: #ffffff;
        }
        .confirm-header p {
            color: #94a3b8;
            font-size: 0.95rem;
            margin: 0;
            line-height: 1.5;
        }

        .confirm-body { padding: 40px; }

        .confirm-row {
            display: flex;
            gap: 40px;
            align-items: flex-start;
        }
        .qr-column { flex-shrink: 0; }
        .qr-column img {
            width: 180px;
            height: 180px;
            display: block;
        }
        .info-column {
            flex: 1;
            min-width: 0;
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
            margin-bottom: 4px;
            display: block;
        }
        .info-value {
            font-size: clamp(1.4rem, 3vw, 1.75rem);
            font-weight: 800;
            color: var(--brand-dark);
            letter-spacing: -0.01em;
            margin-bottom: 6px;
            word-break: break-word;
        }
        .info-meta {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .info-description {
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.55;
            margin-bottom: 24px;
        }

        /* Next-step callout */
        .next-step {
            background: #ecfdf5;
            border-left: 4px solid var(--green);
            border-radius: 8px;
            padding: 16px 18px;
            font-size: 0.875rem;
            color: var(--brand-dark);
            line-height: 1.55;
            margin-bottom: 24px;
        }
        .next-step strong { color: var(--brand-dark); }

        .action-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-confirm-primary {
            background-color: var(--brand-dark);
            color: #ffffff !important;
            border: none;
            border-radius: 8px;
            padding: 12px 28px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-confirm-primary:hover {
            background-color: var(--brand-dark-hover);
            color: #ffffff !important;
            transform: translateY(-1px);
        }
        .btn-confirm-outline {
            background-color: #ffffff;
            color: var(--brand-dark) !important;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 28px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .btn-confirm-outline:hover {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
            color: var(--brand-dark) !important;
        }

        .page-footer {
            margin-top: auto;
            border-top: 1px solid var(--border-color);
            padding: 24px 0;
            background: #ffffff;
        }
        .page-footer .footer-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-footer small {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .page-footer a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.15s ease;
        }
        .page-footer a:hover { color: var(--brand-dark); }

        @media (max-width: 768px) {
            .confirm-card {
                margin: 30px 16px 60px;
                border-radius: 14px;
            }
            .confirm-header { padding: 24px 24px 20px; }
            .confirm-body { padding: 28px 24px; }
            .confirm-row {
                flex-direction: column;
                gap: 24px;
            }
            .qr-column img {
                width: 140px;
                height: 140px;
                margin: 0 auto;
            }
            .action-group { width: 100%; }
            .btn-confirm-primary,
            .btn-confirm-outline {
                width: 100%;
                justify-content: center;
            }
        }
        @media (max-width: 480px) {
            .confirm-header h1 { font-size: 1.4rem; }
            .info-value { font-size: 1.25rem; }
        }

        @media print {
            body { background: #ffffff; }
            .page-footer, .no-print { display: none !important; }
            .confirm-card {
                box-shadow: none;
                border: 1px solid #000;
                margin: 0;
                max-width: 100%;
            }
            .confirm-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<?php include 'header/nav.php'; ?>

<div class="confirm-card">

    <div class="confirm-header">
        <h1>Application submitted</h1>
        <p>Thank you. Keep this page for your records.</p>
    </div>

    <div class="confirm-body">
        <div class="confirm-row">

            <div class="qr-column">
                <img src="<?php echo $qr_url; ?>" alt="QR Code">
            </div>

            <div class="info-column">
                <span class="info-label">Reference number</span>
                <div class="info-value"><?php echo htmlspecialchars($ref); ?></div>
                <div class="info-meta">Submitted <?php echo htmlspecialchars($submitted_at); ?></div>

                <p class="info-description">
                    Track your application with this number or by scanning the code.
                </p>

                <!-- Next-step notice -->
                <div class="next-step">
                    <strong>What happens next:</strong> The BPLO will review your documents
                    and assess your fees. We'll notify you when your permit is ready for pickup,
                    and you'll be able to schedule a pickup appointment at that time.
                </div>

                <div class="action-group">
                    <a href="dashboard.php" class="btn-confirm-primary">
                        Go to dashboard
                    </a>
                    <button type="button" class="btn-confirm-outline" id="printBtn">
                        Print this page
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<footer class="page-footer">
    <div class="footer-inner">
        <small>© <?php echo date('Y'); ?> Municipality of Placer, Surigao del Norte.</small>
        <div>
            <a href="#" class="me-3">Privacy Policy</a>
            <a href="#">Contact Us</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('printBtn').addEventListener('click', function () {
        window.print();
    });
</script>

</body>
</html>