<?php
/**
 * paymongo_return.php
 * Where PayMongo sends the applicant after GCash/Maya. The URL itself proves nothing:
 * we ask PayMongo's API whether the session was really paid before touching the database.
 */
session_start();
require_once __DIR__ . '/config/connect.php';
require_once __DIR__ . '/includes/paymongo.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

$ref   = trim($_GET['ref'] ?? '');
$tries = max(0, (int)($_GET['n'] ?? 0));
if (!$ref) { header('Location: dashboard.php'); exit; }

/* The application must belong to the logged-in user */
$q = $conn->prepare("SELECT id FROM business_applications WHERE reference_no = ? AND user_id = ? LIMIT 1");
$q->bind_param('si', $ref, $user_id);
$q->execute();
$own = $q->get_result()->fetch_assoc();
$q->close();
if (!$own) die('Application not found.');

$sessionId = $_SESSION['pm_session'][$ref] ?? '';
if (!$sessionId) { header('Location: payment_success.php?ref=' . urlencode($ref)); exit; } // webhook may have handled it

$result = paymongo_finalize($conn, $sessionId);

if ($result['state'] === 'paid') {
    unset($_SESSION['pm_session'][$ref]);
    header('Location: payment_success.php?ref=' . urlencode($ref));
    exit;
}

if ($result['state'] === 'error') {
    header('Location: payment.php?ref=' . urlencode($ref) . '&pm=gateway');
    exit;
}

/* pending: e-wallet confirmation can lag a few seconds. Re-check up to ~30s. */
if ($tries >= 6) {
    header('Location: payment.php?ref=' . urlencode($ref) . '&pm=pending');
    exit;
}
$next = 'paymongo_return.php?ref=' . urlencode($ref) . '&n=' . ($tries + 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="5;url=<?php echo htmlspecialchars($next, ENT_QUOTES); ?>">
<title>Confirming payment…</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh;font-family:Inter,sans-serif;">
  <div class="text-center p-4">
    <div class="spinner-border text-success mb-3" role="status"></div>
    <h5 class="fw-bold">Confirming your payment…</h5>
    <p class="text-muted small mb-0">Please don’t close this page. This usually takes a few seconds.</p>
  </div>
</body>
</html>
