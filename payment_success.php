<?php
session_start();
require_once __DIR__ . '/config/connect.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/notifications_helper.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- PayPal credentials (MUST match paypal_frame.php) ---------- */
define('PAYPAL_CLIENT_ID', 'BAAAqXYT7FK2FGKXpxpfobYKxW7-VzAcdybosFIiId5lDBG71g3QMfCTZLhGTmLcs-19Crxb3w_6LCK8X8');
define('PAYPAL_SECRET',    'EJeugey18ReKzwje-jFbO3GZSTprNCx1ukqm2cj1lGh33HoCsh73YfbMMQpt6BB0BY-q-41H1eJTDwsW');
define('PAYPAL_BASE',      'https://api-m.sandbox.paypal.com');

/* ---------- Embedded mode? ---------- */
$isEmbedded = isset($_GET['embed']) && $_GET['embed'] === '1';

/* ---------- Auth check (embed-friendly) ---------- */
if (!isset($_SESSION['user_id'])) {
    if ($isEmbedded) {
        echo '<div style="font-family:Inter,sans-serif;padding:3rem;text-align:center;color:#64748b;">'
           . 'Your session expired. Please close this dialog and log in again.'
           . '</div>';
        exit;
    }
    header('Location: register.php');
    exit;
}

$ref     = $_GET['ref']          ?? '';
$orderId = $_GET['paypal_order'] ?? '';

if (!$ref) {
    if ($isEmbedded) {
        echo '<div style="font-family:Inter,sans-serif;padding:3rem;text-align:center;color:#dc3545;">'
           . 'Invalid reference number.'
           . '</div>';
        exit;
    }
    die('Invalid reference number.');
}

$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT a.*, p.id AS payment_id, p.status AS payment_status, p.amount_due
      FROM business_applications a
      LEFT JOIN payments p ON a.id = p.application_id
     WHERE a.reference_no = ? AND a.user_id = ?
     LIMIT 1
");
$stmt->bind_param('si', $ref, $user_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();

if (!$app) {
    if ($isEmbedded) {
        echo '<div style="font-family:Inter,sans-serif;padding:3rem;text-align:center;color:#dc3545;">'
           . 'Application not found.'
           . '</div>';
        exit;
    }
    die('Application not found.');
}

$isAlreadyPaid       = ($app['payment_status'] === 'paid');
$verificationFailed  = false;
$errorMsg            = '';
$pendingVerification = false;

/* ---------- Server-side verification against PayPal ---------- */
if (!$isAlreadyPaid && $orderId) {
    try {
        $http   = new Client(['base_uri' => PAYPAL_BASE, 'timeout' => 20]);
        $tokRes = $http->post('/v1/oauth2/token', [
            'auth'        => [PAYPAL_CLIENT_ID, PAYPAL_SECRET],
            'form_params' => ['grant_type' => 'client_credentials'],
        ]);
        $token = json_decode($tokRes->getBody(), true)['access_token'] ?? null;
        if (!$token) throw new Exception('No PayPal access token');

        $ordRes = $http->get('/v2/checkout/orders/' . urlencode($orderId), [
            'headers' => ['Authorization' => "Bearer $token"],
        ]);
        $order       = json_decode($ordRes->getBody(), true);
        $orderStatus = $order['status'] ?? 'UNKNOWN';

        $capture       = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
        $captureStatus = $capture['status'] ?? null;

        /* ───────────────── SUCCESS ONLY ───────────────── */
        if ($orderStatus === 'COMPLETED' && $captureStatus === 'COMPLETED') {

            $txnId        = $capture['id']                      ?? $orderId;
            $paidAmount   = $capture['amount']['value']         ?? 0;
            $paidCurrency = $capture['amount']['currency_code'] ?? 'PHP';
            $payerId      = $order['payer']['payer_id']         ?? null;
            $payerEmail   = $order['payer']['email_address']    ?? null;
            $payerName    = trim(
                ($order['payer']['name']['given_name'] ?? '') . ' ' .
                ($order['payer']['name']['surname']    ?? '')
            );
            $rawJson = json_encode($order, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            /* Insert into paypal_transactions */
            $ins = $conn->prepare("
                INSERT INTO paypal_transactions
                    (application_id, user_id, reference_no,
                     paypal_order_id, paypal_capture_id,
                     payer_id, payer_email, payer_name,
                     amount, currency, payment_status, raw_response, paid_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, NOW())
                ON DUPLICATE KEY UPDATE
                    paypal_capture_id = VALUES(paypal_capture_id),
                    payment_status    = 'completed',
                    raw_response      = VALUES(raw_response),
                    updated_at        = NOW()
            ");

            if ($ins) {
                /* amount as string to be safe */
                $ins->bind_param(
                    'iisssssssss',
                    $app['id'],
                    $user_id,
                    $ref,
                    $orderId,
                    $txnId,
                    $payerId,
                    $payerEmail,
                    $payerName,
                    $paidAmount,
                    $paidCurrency,
                    $rawJson
                );
                if (!$ins->execute()) {
                    error_log('[PayPal txn insert failed] ' . $ins->error);
                }
                $ins->close();
            } else {
                error_log('[PayPal txn prepare failed] ' . $conn->error);
            }

            /* Update payments */
            $u1 = $conn->prepare("UPDATE payments
                                     SET status='paid', payment_method='paypal', paid_at=NOW()
                                   WHERE application_id = ? AND user_id = ?");
            $u1->bind_param('ii', $app['id'], $user_id);
            $u1->execute();
            $u1->close();

            /* Update business_applications */
            $u2 = $conn->prepare("UPDATE business_applications
                                     SET status='payment_confirmed', updated_at=NOW()
                                   WHERE id = ?");
            $u2->bind_param('i', $app['id']);
            $u2->execute();
            $u2->close();

            $isAlreadyPaid = true;

            /* Notifications */
            $notifKey = 'notified_paid_app_' . $app['id'];
            if (empty($_SESSION[$notifKey])) {
                $_SESSION[$notifKey] = true;

                $metaStmt = $conn->prepare("
                    SELECT bi.business_name,
                           CONCAT(acc.firstname, ' ', acc.lastname) AS applicant_name,
                           ba.user_id
                      FROM business_applications ba
                      LEFT JOIN business_info_data bi  ON ba.id = bi.application_id
                      LEFT JOIN accounts           acc ON ba.user_id = acc.id
                     WHERE ba.id = ?
                     LIMIT 1
                ");
                if ($metaStmt) {
                    $metaStmt->bind_param('i', $app['id']);
                    $metaStmt->execute();
                    $meta = $metaStmt->get_result()->fetch_assoc();
                    $metaStmt->close();

                    if ($meta) {
                        $bizName       = $meta['business_name']  ?? 'a business';
                        $applicantName = $meta['applicant_name'] ?? 'An applicant';
                        $applicantId   = (int)$meta['user_id'];

                        notifyByUserType($conn, [3],
                            'Payment Received — Verify Payment',
                            "$applicantName paid for \"$bizName\" (Ref: $ref). Please verify the payment.",
                            null, 'success');

                        notifyByUserType($conn, [4],
                            'Payment Received — Ready for Review',
                            "Payment for \"$bizName\" (Ref: $ref) has been received. You may review the application documents.",
                            null, 'permit');

                        notifyByUserType($conn, [5],
                            'Payment Received',
                            "\"$bizName\" (Ref: $ref) — payment received and awaiting verification.",
                            null, 'info');

                        if ($applicantId > 0) {
                            createNotification($conn, $applicantId,
                                'Payment Received',
                                "We've received your payment for \"$bizName\" (Ref: $ref). Your application is now being processed.",
                                null, 'success');
                        }
                    }
                }
            }
        }
        /* ───────────── NON-SUCCESS (no inserts) ───────────── */
        elseif ($orderStatus === 'APPROVED' || $orderStatus === 'CREATED') {
            $pendingVerification = true;
        } elseif ($orderStatus === 'VOIDED') {
            $errorMsg = 'The PayPal payment was voided. Please try again.';
        } else {
            $verificationFailed = true;
        }

    } catch (ClientException $e) {
        error_log('PayPal verify error: ' . $e->getMessage());
        $verificationFailed = true;
    } catch (Exception $e) {
        error_log('PayPal verify error: ' . $e->getMessage());
        $verificationFailed = true;
    }
} elseif (!$isAlreadyPaid && !$orderId) {
    $errorMsg = 'No PayPal order was returned. Please return to the payment page.';
}

/* ---------- Business name for the UI ---------- */
$businessName = 'N/A';
$stmt = $conn->prepare("SELECT business_name FROM business_info_data WHERE application_id = ?");
$stmt->bind_param('i', $app['id']);
$stmt->execute();
$business = $stmt->get_result()->fetch_assoc();
if ($business) $businessName = htmlspecialchars($business['business_name']);
$stmt->close();

$amountPaid = $isAlreadyPaid ? number_format($app['amount_due'], 2) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status | Business Permit | Placer</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <?php if (!$isEmbedded): ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php endif; ?>
    <style>
        * { font-family: 'Inter', sans-serif; box-sizing: border-box; }

        <?php if (!$isEmbedded): ?>
        body { background: #f0f4f8; margin: 0; }
        .success-card { max-width: 700px; margin: 2rem auto; border-radius: 1.5rem; overflow: hidden; }
        .success-header { background: #1e4b2e; color: white; padding: 2rem; text-align: center; }
        .success-icon { font-size: 4rem; margin-bottom: 1rem; }
        .status-badge { background: #ffc107; color: #2c3e2f; padding: 0.5rem 1rem; border-radius: 2rem; font-weight: 600; display: inline-block; }
        <?php endif; ?>

        <?php if ($isEmbedded): ?>
        html, body { margin: 0; padding: 0; background: #f8fafc; min-height: 100vh; }
        .embed-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .success-card { width: 100%; max-width: 520px; border-radius: 1rem; overflow: hidden; box-shadow: 0 6px 24px -8px rgba(15,23,42,.15); background: #fff; }
        .success-header { background: #1e4b2e; color: #fff; padding: 1.25rem 1rem; text-align: center; }
        .success-header h2 { font-size: 1.15rem; margin: 0; }
        .success-header p  { font-size: 0.78rem; margin: 4px 0 0; opacity: .85; }
        .success-icon { font-size: 2.25rem; display: block; margin-bottom: 6px; }
        .status-badge { background: #ffc107; color: #2c3e2f; padding: 0.35rem 0.85rem; border-radius: 2rem; font-weight: 600; font-size: 0.78rem; display: inline-block; }
        .success-card .card-body { padding: 1.25rem !important; }
        .success-card .card-body h4 { font-size: 1.05rem; margin-top: 0.5rem; }
        .success-card .card-body h5 { font-size: 0.95rem; }
        .success-card .card-body p,
        .success-card .card-body li,
        .success-card .card-body ul { font-size: 0.82rem; }
        .success-card .alert { padding: 0.6rem 0.85rem; font-size: 0.78rem; margin-bottom: 0.75rem; }
        .success-card .alert ul { margin: 4px 0 0; padding-left: 1.1rem; }
        .success-card .btn { font-size: 0.8rem; padding: 0.4rem 1rem; }
        .success-card .d-flex.justify-content-center.gap-3 { gap: 0.5rem !important; }
        <?php endif; ?>
    </style>
</head>
<body>

<?php if (!$isEmbedded): ?>
<?php include __DIR__ . '/header/nav.php'; ?>
<?php endif; ?>

<?php if ($isEmbedded): ?>
<div class="embed-wrap">
<?php else: ?>
<div class="container">
<?php endif; ?>

    <div class="card shadow border-0 success-card">
        <div class="success-header">
            <i class="bi bi-paypal success-icon"></i>
            <h2 class="fw-bold">Payment Status</h2>
            <p class="mb-0 opacity-75">Reference: <?php echo htmlspecialchars($ref); ?></p>
        </div>
        <div class="card-body p-4">
            <?php if ($isAlreadyPaid): ?>
                <div class="text-center mb-3">
                    <span class="status-badge"><i class="bi bi-hourglass-split"></i> Under Review</span>
                    <h4 class="mt-3">Thank you for your payment</h4>
                    <p class="text-muted mb-2">
                        Your payment of <strong>₱<?php echo $amountPaid; ?></strong> has been successfully recorded.<br>
                        The Business Permit and Licensing Office (BPLO) is now reviewing your documents and payment.
                    </p>
                </div>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    <strong>What happens next?</strong>
                    <ul class="mb-0 mt-1">
                        <li>Verification of submitted documents (1–2 working days).</li>
                        <li>Issuance of official receipt and assessment notice.</li>
                        <li>Final approval and release of the digital permit.</li>
                    </ul>
                </div>

                <div class="alert alert-warning mb-3">
                    <i class="bi bi-envelope-paper"></i>
                    You will receive an email notification once your permit is ready for download.
                </div>

                <div class="d-flex justify-content-center gap-3 mt-3 flex-wrap">
                    <a href="dashboard.php" target="_top" class="btn btn-success rounded-pill px-4">
                        <i class="bi bi-speedometer2"></i> Go to Dashboard
                    </a>
                    <a href="track.php?ref=<?php echo urlencode($ref); ?>" target="_top" class="btn btn-outline-success rounded-pill px-4">
                        <i class="bi bi-graph-up"></i> Track Status
                    </a>
                </div>

                <?php if (!$isEmbedded): ?>
                <script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Payment Successful',
                        text: 'Your payment is now under review by the LGU. You can track the status from your dashboard.',
                        confirmButtonColor: '#198754'
                    });
                </script>
                <?php endif; ?>

            <?php elseif ($pendingVerification): ?>
                <div class="text-center py-3">
                    <i class="bi bi-clock-history fs-1 text-warning"></i>
                    <h4 class="mt-3">Payment pending confirmation</h4>
                    <p class="text-muted mb-3">
                        Your payment is still being processed by PayPal.<br>
                        It may take a few minutes to be confirmed.<br>
                        Please check back soon or return to your dashboard.
                    </p>
                    <a href="dashboard.php" target="_top" class="btn btn-warning rounded-pill">
                        Return to Dashboard
                    </a>
                </div>

            <?php elseif (!empty($errorMsg)): ?>
                <div class="text-center py-3">
                    <i class="bi bi-exclamation-triangle-fill fs-1 text-danger"></i>
                    <h4 class="mt-3">Payment not confirmed</h4>
                    <p class="text-muted mb-3"><?php echo htmlspecialchars($errorMsg); ?></p>
                    <a href="payment.php?ref=<?php echo urlencode($ref); ?>" target="_top" class="btn btn-danger rounded-pill">
                        Retry Payment
                    </a>
                </div>

            <?php elseif ($verificationFailed): ?>
                <div class="text-center py-3">
                    <i class="bi bi-question-circle fs-1 text-secondary"></i>
                    <h4 class="mt-3">Unable to verify payment status</h4>
                    <p class="text-muted mb-3">
                        We couldn't immediately verify your payment with PayPal.<br>
                        The LGU will manually check and update your application within 24 hours.
                    </p>
                    <a href="dashboard.php" target="_top" class="btn btn-secondary rounded-pill">
                        Go to Dashboard
                    </a>
                </div>

            <?php else: ?>
                <div class="text-center py-3">
                    <i class="bi bi-question-circle fs-1 text-secondary"></i>
                    <h4 class="mt-3">Unexpected state</h4>
                    <p class="text-muted mb-3">Please contact support or return to the payment page.</p>
                    <a href="payment.php?ref=<?php echo urlencode($ref); ?>" target="_top" class="btn btn-secondary rounded-pill">
                        Back to Payment
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</body>
</html>