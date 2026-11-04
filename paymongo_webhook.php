<?php
/**
 * paymongo_webhook.php  — backup confirmation path (e.g. applicant closes the browser after paying).
 *
 * Setup: PayMongo Dashboard > Developers > Webhooks > add
 *        URL:    https://YOUR-DOMAIN/paymongo_webhook.php
 *        Events: checkout_session.payment.paid
 * Then put the signing secret (whsec_...) in config/paymongo.local.php.
 * (Needs a public URL; on localhost use a tunnel such as ngrok. The return page works without it.)
 */
require_once __DIR__ . '/config/connect.php';
require_once __DIR__ . '/includes/paymongo.php';

$cfg = paymongo_config();
$raw = file_get_contents('php://input');

if ($cfg['webhook_secret'] === '') { http_response_code(503); exit('webhook not configured'); }

/* Header format: t=<ts>,te=<hmac for test mode>,li=<hmac for live mode> */
$header = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
$parts  = [];
foreach (explode(',', $header) as $kv) {
    $p = explode('=', trim($kv), 2);
    if (count($p) === 2) $parts[$p[0]] = $p[1];
}
$ts       = $parts['t'] ?? '';
$isLive   = strpos($cfg['secret_key'], 'sk_live_') === 0;
$expected = hash_hmac('sha256', $ts . '.' . $raw, $cfg['webhook_secret']);
$given    = $parts[$isLive ? 'li' : 'te'] ?? '';

if ($ts === '' || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(400);
    exit('bad signature');
}

$event = json_decode($raw, true);
$type  = $event['data']['attributes']['type'] ?? '';

if ($type === 'checkout_session.payment.paid') {
    $sessionId = $event['data']['attributes']['data']['id'] ?? '';
    if ($sessionId !== '') {
        // We never trust the payload's contents: paymongo_finalize() re-fetches the session from the API.
        $r = paymongo_finalize($conn, $sessionId);
        if ($r['state'] === 'error') error_log('[PayMongo webhook] ' . $r['message'] . ' session=' . $sessionId);
    }
}

http_response_code(200);   // always ack so PayMongo doesn't retry events we don't care about
echo 'ok';
