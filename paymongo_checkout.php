<?php
/**
 * paymongo_checkout.php
 * POST: ref, method (gcash|maya), csrf  -> creates a PayMongo checkout session and redirects to it.
 */
session_start();
require_once __DIR__ . '/config/connect.php';
require_once __DIR__ . '/includes/paymongo.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }

$ref       = trim($_POST['ref'] ?? '');
$methodKey = $_POST['method'] ?? '';
$csrf      = $_POST['csrf'] ?? '';

function pm_back(string $ref, string $code): void {
    header('Location: payment.php?ref=' . urlencode($ref) . '&pm=' . urlencode($code));
    exit;
}

if (!$ref) die('Missing reference number.');
if (empty($_SESSION['pay_csrf']) || !hash_equals($_SESSION['pay_csrf'], $csrf)) pm_back($ref, 'csrf');
if (!isset(paymongo_methods()[$methodKey])) pm_back($ref, 'method');
if (!paymongo_configured()) pm_back($ref, 'unavailable');

$stmt = $conn->prepare("
    SELECT ba.id, ba.user_id, ba.reference_no, ba.status AS app_status,
           bi.business_name, p.amount_due, p.status AS payment_status
      FROM business_applications ba
      LEFT JOIN business_info_data bi ON ba.id = bi.application_id
      LEFT JOIN payments p            ON ba.id = p.application_id
     WHERE ba.reference_no = ? AND ba.user_id = ?
     LIMIT 1");
$stmt->bind_param('si', $ref, $user_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$app) die('Application not found.');

$alreadyPaid = ($app['payment_status'] ?? '') === 'paid'
    || in_array($app['app_status'] ?? '', ['payment_review', 'payment_confirmed', 'approved', 'released'], true);
if ($alreadyPaid) { header('Location: payment_success.php?ref=' . urlencode($ref)); exit; }

$amount = (float)($app['amount_due'] ?? 0);   // always from the database
if ($amount < PAYMONGO_MIN_PESOS) pm_back($ref, 'amount');

if (paymongo_demo() && paymongo_config()['secret_key'] === '') {
    $_SESSION['pm_demo'][$ref] = ['method' => $methodKey, 'token' => bin2hex(random_bytes(8))];
    header('Location: paymongo_demo.php?ref=' . urlencode($ref));
    exit;
}

try {
    $session = paymongo_create_checkout($methodKey, $app, $amount);
} catch (RuntimeException $e) {
    error_log('[PayMongo checkout] ' . $e->getMessage());
    pm_back($ref, 'gateway');
}

$_SESSION['pm_session'][$ref] = $session['id'];   // used by paymongo_return.php
header('Location: ' . $session['checkout_url']);
exit;
