<?php
// api.php – PayPal endpoints (config + verification)
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/connect.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/* ---------- PayPal credentials ---------- */
// REST API credentials (server-side verification)
define('PAYPAL_CLIENT_ID', 'Af17js3P93kCoG6y46F4NaFQ9Xft6yz8oat8WULLFWK0sA8Jg0ptvwzSwB2yNEHdfX7zMblwTY27qQIk');
define('PAYPAL_SECRET',    'ECdIy0ApKudaoO8Djq3BJ1Jwkq9cYmLZBpkOhXqJwJ_NiBs1NgbFrFTU_WMYkWfRctSwplQoFgU35OMQ');
define('PAYPAL_BASE',      'https://api-m.sandbox.paypal.com'); // ⬅ switch to https://api-m.paypal.com in production

// SDK credentials (client-side hosted button)
define('PAYPAL_SDK_CLIENT_ID',    'BAAsDEN0yBHU2fqQIh6DHpzibtr6ahJD-_Qb91dK73Q0qbd2y7swbydPUWqtOkyl_jIVarHEoGwig4ohZM');
define('PAYPAL_HOSTED_BUTTON_ID', '7R6WN5HEBMMWU');

header('Content-Type: application/json');

$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$endpoint = $input['endpoint'] ?? ($_GET['endpoint'] ?? '');

function paypalHttp() {
    return new Client([
        'base_uri' => PAYPAL_BASE,
        'headers'  => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'timeout'  => 20,
    ]);
}

function getPayPalAccessToken() {
    $res  = paypalHttp()->post('/v1/oauth2/token', [
        'auth'        => [PAYPAL_CLIENT_ID, PAYPAL_SECRET],
        'form_params' => ['grant_type' => 'client_credentials'],
    ]);
    $data = json_decode($res->getBody(), true);
    if (empty($data['access_token'])) throw new Exception('No PayPal access token');
    return $data['access_token'];
}

function verifyPayPalOrder(string $orderId): array {
    $token = getPayPalAccessToken();
    $res   = paypalHttp()->get('/v2/checkout/orders/' . urlencode($orderId), [
        'headers' => ['Authorization' => "Bearer $token"],
    ]);
    return json_decode($res->getBody(), true);
}

try {
    global $conn;
    if (!$conn) throw new Exception('Database connection missing in api.php');

    /* ---------- 1. Config endpoint for front-end ---------- */
    if ($endpoint === 'paypal_config') {
        echo json_encode([
            'sdk_client_id'    => PAYPAL_SDK_CLIENT_ID,
            'hosted_button_id' => PAYPAL_HOSTED_BUTTON_ID,
        ]);
        exit;
    }

    /* ---------- 2. Verify a PayPal order after user approves ---------- */
    if ($endpoint === 'verify_paypal') {
        $orderId = trim($input['order_id'] ?? '');
        $ref     = trim($input['ref'] ?? '');
        if (!$orderId) throw new Exception('Missing PayPal order_id');
        if (!$ref)     throw new Exception('Missing reference number');

        $order  = verifyPayPalOrder($orderId);
        $status = $order['status'] ?? 'UNKNOWN';
        $capture       = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
        $captureStatus = $capture['status'] ?? null;

        if ($status === 'COMPLETED' && $captureStatus === 'COMPLETED') {
            $txnId      = $capture['id'] ?? $orderId;
            $paidAmount = $capture['amount']['value'] ?? null;

            $updPay = $conn->prepare("
                UPDATE payments
                   SET status = 'paid',
                       payment_method = 'paypal',
                       paid_at = NOW()
                 WHERE application_id = (SELECT id FROM business_applications WHERE reference_no = ? LIMIT 1)
            ");
            if ($updPay) { $updPay->bind_param('s', $ref); $updPay->execute(); $updPay->close(); }

            $updApp = $conn->prepare("
                UPDATE business_applications
                   SET status = 'payment_confirmed', updated_at = NOW()
                 WHERE reference_no = ?
            ");
            if ($updApp) { $updApp->bind_param('s', $ref); $updApp->execute(); $updApp->close(); }

            echo json_encode([
                'ok'          => true,
                'status'      => $status,
                'capture'     => $captureStatus,
                'transaction' => $txnId,
                'amount'      => $paidAmount,
            ]);
            exit;
        }

        echo json_encode(['ok' => false, 'status' => $status, 'capture' => $captureStatus, 'order' => $order]);
        exit;
    }

    throw new Exception('Unknown endpoint');

} catch (ClientException $e) {
    $body = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
    error_log("PayPal API error: " . $e->getMessage() . " – " . $body);
    http_response_code(400);
    echo json_encode(['error' => 'PayPal gateway error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}