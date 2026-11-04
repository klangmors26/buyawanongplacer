<?php
session_start();
require_once __DIR__ . '/config/connect.php';
require_once __DIR__ . '/includes/paymongo.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$ref     = $_GET['ref'] ?? '';
if (!$ref) die('Missing reference number.');

/* Pull payment status + application status too */
$stmt = $conn->prepare("
    SELECT ba.id, ba.reference_no, ba.status AS app_status,
           bi.business_name,
           p.amount_due,
           p.status AS payment_status,
           p.paid_at,
           p.transaction_id,
           p.payment_method
      FROM business_applications ba
      LEFT JOIN business_info_data bi ON ba.id = bi.application_id
      LEFT JOIN payments          p  ON ba.id = p.application_id
     WHERE ba.reference_no = ? AND ba.user_id = ?
     LIMIT 1
");
$stmt->bind_param('si', $ref, $user_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
if (!$app) die('Application not found.');

/* 🛡️ GUARD: already paid → do NOT show payment UI again.
   Redirect to the success/status page which shows "Under Review". */
$alreadyPaid = (
    ($app['payment_status'] ?? '') === 'paid'
    || in_array($app['app_status'] ?? '', ['payment_review', 'payment_confirmed', 'approved', 'released'], true)
);

if ($alreadyPaid) {
    header('Location: payment_success.php?ref=' . urlencode($ref));
    exit;
}

$amount       = (float)($app['amount_due'] ?? 0);
$businessName = $app['business_name'] ?? 'Business Permit';

/* CSRF token for the GCash / Maya forms */
if (empty($_SESSION['pay_csrf'])) $_SESSION['pay_csrf'] = bin2hex(random_bytes(16));
$csrf          = $_SESSION['pay_csrf'];
$walletsReady  = paymongo_configured() && $amount >= PAYMONGO_MIN_PESOS;

$pmMessages = [
    'csrf'        => 'Your session expired. Please try again.',
    'method'      => 'Please choose a payment method.',
    'unavailable' => 'GCash and Maya are not available right now. Please use another method or try again later.',
    'amount'      => 'This amount is below the minimum for e-wallet payments. Please use another method.',
    'gateway'     => 'We could not start or confirm the payment with the payment provider. If you were charged, do not pay again — it will be confirmed shortly. Otherwise, please try again.',
    'pending'     => 'We have not received confirmation of your payment yet. If you completed it in GCash/Maya, please wait a minute and refresh this page; do not pay twice.',
];
$pmNotice = $pmMessages[$_GET['pm'] ?? ''] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pay Online | Business Permit</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="shortcut icon" type="image/png" href="/img/logo.png">
<link rel="apple-touch-icon" href="/img/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body{font-family:'Inter',sans-serif;background:#f0f4f8;}
    .pay-card{max-width:640px;margin:3rem auto;border-radius:1.25rem;box-shadow:0 4px 24px rgba(15,23,42,.08);}
    .pay-header{background:#1e4b2e;color:#fff;padding:1.5rem;border-radius:1.25rem 1.25rem 0 0;}
    .modal-content{border:none;border-radius:1rem;overflow:hidden;}
    .method-btn{width:100%;display:flex;align-items:center;gap:1rem;text-align:left;padding:1rem 1.25rem;border:2px solid #e2e8f0;border-radius:1rem;background:#fff;transition:.15s;}
    .method-btn:hover:not(:disabled){border-color:#198754;box-shadow:0 2px 12px rgba(25,135,84,.15);}
    .method-btn:disabled{opacity:.5;cursor:not-allowed;}
    .method-logo{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;flex-shrink:0;}
    .logo-gcash{background:#007dfe;} .logo-maya{background:#00b46e;} .logo-paypal{background:#0070ba;}
</style>
<?php include __DIR__ . '/includes/offline_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/header/nav.php'; ?>

<div class="container">
    <div class="card pay-card border-0">
        <div class="pay-header text-center">
            <i class="bi bi-wallet2 fs-1"></i>
            <h3 class="fw-bold mt-2 mb-0">Pay Your Permit Fee</h3>
            <small class="opacity-75">Secure online payment — choose a method</small>
        </div>
        <div class="card-body p-4">
            <div class="text-center">
                <p class="mb-1 text-muted small">Business</p>
                <h5 class="fw-bold"><?php echo htmlspecialchars($businessName); ?></h5>

                <p class="mb-1 text-muted small mt-3">Reference Number</p>
                <p class="fw-bold"><?php echo htmlspecialchars($ref); ?></p>

                <p class="mb-1 text-muted small mt-3">Amount Due</p>
                <h3 class="fw-bold text-success mb-4">₱<?php echo number_format($amount, 2); ?></h3>
            </div>

            <?php if ($pmNotice): ?>
                <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($pmNotice); ?></div>
            <?php endif; ?>

            <div class="d-grid gap-3">
                <!-- GCash -->
                <form method="post" action="paymongo_checkout.php" data-offline="block">
                    <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref, ENT_QUOTES); ?>">
                    <input type="hidden" name="method" value="gcash">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <button type="submit" class="method-btn" <?php echo $walletsReady ? '' : 'disabled'; ?>>
                        <span class="method-logo logo-gcash">G</span>
                        <span><strong>GCash</strong><br><small class="text-muted">Pay with your GCash wallet</small></span>
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    </button>
                </form>

                <!-- Maya -->
                <form method="post" action="paymongo_checkout.php" data-offline="block">
                    <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref, ENT_QUOTES); ?>">
                    <input type="hidden" name="method" value="maya">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <button type="submit" class="method-btn" <?php echo $walletsReady ? '' : 'disabled'; ?>>
                        <span class="method-logo logo-maya">M</span>
                        <span><strong>Maya</strong><br><small class="text-muted">Pay with your Maya wallet</small></span>
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    </button>
                </form>

                <!-- PayPal -->
                <button type="button" class="method-btn" id="openPayModal">
                    <span class="method-logo logo-paypal"><i class="bi bi-paypal"></i></span>
                    <span><strong>PayPal</strong><br><small class="text-muted">PayPal balance or linked card</small></span>
                    <i class="bi bi-chevron-right ms-auto text-muted"></i>
                </button>
            </div>

            <?php if (!$walletsReady): ?>
                <p class="text-muted small text-center mt-3 mb-0">GCash and Maya are temporarily unavailable.</p>
            <?php endif; ?>
            <p class="text-muted small text-center mt-3 mb-0"><i class="bi bi-lock"></i> GCash and Maya payments are processed securely by PayMongo.</p>
        </div>
    </div>
</div>

<div class="modal fade" id="payModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="bi bi-paypal"></i> Complete your payment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="paypalFrame"
                src="paypal_frame.php?ref=<?php echo urlencode($ref); ?>&amount=<?php echo urlencode($amount); ?>"
                style="width:100%; height:640px; border:0; display:block;"
                allow="payment"
                referrerpolicy="origin"></iframe>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const REF = <?php echo json_encode($ref); ?>;

document.getElementById('openPayModal').addEventListener('click', () => {
    // 🛡️ Extra safety: verify with the server the app isn't already paid
    // (in case the user opened this page in two tabs and paid in one of them)
    fetch('check_payment_status.php?ref=' + encodeURIComponent(REF))
        .then(r => r.json())
        .then(data => {
            if (data && data.paid) {
                Swal.fire({
                    icon: 'info',
                    title: 'Already paid',
                    text: 'This application has already been paid. Redirecting to your status page…',
                    confirmButtonColor: '#198754'
                }).then(() => {
                    window.location.href = 'payment_success.php?ref=' + encodeURIComponent(REF);
                });
                return;
            }
            new bootstrap.Modal(document.getElementById('payModal')).show();
        })
        .catch(() => {
            new bootstrap.Modal(document.getElementById('payModal')).show();
        });
});

window.addEventListener('message', (ev) => {
    if (!ev.data || typeof ev.data !== 'object') return;

    if (ev.data.type === 'paypal_cancel') {
        window.location.href = 'payment_cancel.php?ref=' + encodeURIComponent(REF);
    } else if (ev.data.type === 'paypal_error') {
        Swal.fire({
            icon: 'error',
            title: 'Payment error',
            text: 'Something went wrong with PayPal. Please try again.'
        });
    }
});
</script>
</body>
</html>