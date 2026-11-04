<?php
/**
 * includes/paymongo.php
 * GCash + Maya payments through PayMongo Checkout Sessions.
 *
 * Flow:
 *   payment.php -> paymongo_checkout.php (creates session, redirects to PayMongo)
 *   -> applicant pays in GCash/Maya -> paymongo_return.php (verifies with PayMongo API)
 *   (paymongo_webhook.php is a backup path that calls the same paymongo_finalize())
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../notifications_helper.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

const PAYMONGO_API_BASE = 'https://api.paymongo.com/v1/';
const PAYMONGO_MIN_PESOS = 20.00;   // PayMongo's minimum for e-wallet checkout

/** UI method key => [PayMongo type, payments.payment_method value, label] */
function paymongo_methods(): array
{
    return [
        'gcash' => ['type' => 'gcash',   'db' => 'gcash', 'label' => 'GCash'],
        'maya'  => ['type' => 'paymaya', 'db' => 'maya',  'label' => 'Maya'],
    ];
}

function paymongo_config(): array
{
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/../config/paymongo.php';
    return $cfg;
}

function paymongo_demo(): bool
{
    return !empty(paymongo_config()['demo_mode']);
}

/** GCash/Maya buttons are usable with real keys OR in demo mode. */
function paymongo_configured(): bool
{
    return paymongo_config()['secret_key'] !== '' || paymongo_demo();
}

function paymongo_base_url(): string
{
    $cfg = paymongo_config();
    if ($cfg['app_base_url'] !== '') return $cfg['app_base_url'];

    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/** Low-level call. Throws RuntimeException with a safe message on failure. */
function paymongo_request(string $method, string $path, ?array $json = null): array
{
    $cfg = paymongo_config();
    if ($cfg['secret_key'] === '') throw new RuntimeException('PayMongo is not configured.');

    $http = new Client(['base_uri' => PAYMONGO_API_BASE, 'timeout' => 20, 'http_errors' => false]);
    $opts = [
        'auth'    => [$cfg['secret_key'], ''],
        'headers' => ['Accept' => 'application/json'],
    ];
    if ($json !== null) $opts['json'] = $json;

    try {
        $res = $http->request($method, ltrim($path, '/'), $opts);
    } catch (GuzzleException $e) {
        error_log('[PayMongo] network error: ' . $e->getMessage());
        throw new RuntimeException('Could not reach PayMongo. Please try again.');
    }

    $code = $res->getStatusCode();
    $data = json_decode((string)$res->getBody(), true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        error_log('[PayMongo] HTTP ' . $code . ' ' . $path . ' -> ' . substr((string)$res->getBody(), 0, 800));
        throw new RuntimeException('PayMongo rejected the request (HTTP ' . $code . ').');
    }
    return $data;
}

/**
 * Create a hosted checkout session limited to one e-wallet.
 * @return array ['id' => 'cs_...', 'checkout_url' => 'https://...']
 */
function paymongo_create_checkout(string $methodKey, array $app, float $amount): array
{
    $methods = paymongo_methods();
    if (!isset($methods[$methodKey])) throw new RuntimeException('Unsupported payment method.');
    $m = $methods[$methodKey];

    $ref   = $app['reference_no'];
    $base  = paymongo_base_url();
    $biz   = trim((string)($app['business_name'] ?? '')) ?: 'Business Permit';

    $body = ['data' => ['attributes' => [
        'line_items' => [[
            'currency'    => 'PHP',
            'amount'      => (int) round($amount * 100),   // centavos
            'name'        => 'Business Permit Fee',
            'description' => $biz . ' (Ref: ' . $ref . ')',
            'quantity'    => 1,
        ]],
        'payment_method_types' => [$m['type']],
        'description'          => 'Business Permit Payment - Ref ' . $ref,
        'reference_number'     => $ref,
        'success_url'          => $base . '/paymongo_return.php?ref=' . rawurlencode($ref),
        'cancel_url'           => $base . '/payment_cancel.php?ref=' . rawurlencode($ref),
        'send_email_receipt'   => false,
        'show_description'     => true,
        'show_line_items'      => true,
        'metadata'             => [
            'application_id' => (string)$app['id'],
            'user_id'        => (string)$app['user_id'],
            'reference_no'   => $ref,
        ],
    ]]];

    $res   = paymongo_request('POST', 'checkout_sessions', $body);
    $id    = $res['data']['id'] ?? null;
    $url   = $res['data']['attributes']['checkout_url'] ?? null;
    if (!$id || !$url) throw new RuntimeException('PayMongo did not return a checkout link.');
    return ['id' => $id, 'checkout_url' => $url];
}

/**
 * Verify a checkout session with PayMongo and, if really paid, record it.
 * Safe to call many times / from both the return page and the webhook.
 *
 * @return array ['state' => 'paid'|'pending'|'error', 'ref' => string|null,
 *                'already' => bool, 'message' => string]
 */
function paymongo_finalize(mysqli $conn, string $sessionId): array
{
    try {
        $res = paymongo_request('GET', 'checkout_sessions/' . rawurlencode($sessionId));
    } catch (RuntimeException $e) {
        return ['state' => 'error', 'ref' => null, 'already' => false, 'message' => $e->getMessage()];
    }

    $attr = $res['data']['attributes'] ?? [];
    $ref  = $attr['reference_number'] ?? ($attr['metadata']['reference_no'] ?? null);
    if (!$ref) return ['state' => 'error', 'ref' => null, 'already' => false, 'message' => 'Session has no reference.'];

    /* Find a successful payment on the session */
    $paid = null;
    foreach (($attr['payments'] ?? []) as $p) {
        if (($p['attributes']['status'] ?? '') === 'paid') { $paid = $p; break; }
    }
    if (!$paid) {
        // PayMongo may also expose the intent status
        $intentStatus = $attr['payment_intent']['attributes']['status'] ?? '';
        $state = ($intentStatus === 'succeeded') ? 'error' : 'pending'; // succeeded but no payment object = unexpected
        return ['state' => $state, 'ref' => $ref, 'already' => false,
                'message' => $state === 'pending' ? 'Payment not confirmed yet.' : 'Unexpected PayMongo response.'];
    }

    /* Load our application + expected amount (never trust client/webhook values) */
    $q = $conn->prepare("
        SELECT ba.id, ba.user_id, ba.status AS app_status,
               p.status AS payment_status, p.amount_due
          FROM business_applications ba
          LEFT JOIN payments p ON p.application_id = ba.id
         WHERE ba.reference_no = ?
         LIMIT 1");
    $q->bind_param('s', $ref);
    $q->execute();
    $app = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$app) return ['state' => 'error', 'ref' => $ref, 'already' => false, 'message' => 'Application not found.'];

    $appId  = (int)$app['id'];
    $userId = (int)$app['user_id'];

    /* Amount check: what PayMongo says was paid must cover what we assessed */
    $paidCentavos = (int)($paid['attributes']['amount'] ?? 0);
    $dueCentavos  = (int) round(((float)$app['amount_due']) * 100);
    if ($paidCentavos < $dueCentavos) {
        error_log("[PayMongo] amount mismatch ref=$ref paid=$paidCentavos due=$dueCentavos session=$sessionId");
        return ['state' => 'error', 'ref' => $ref, 'already' => false, 'message' => 'Paid amount does not match the amount due.'];
    }

    $srcType   = $paid['attributes']['source']['type'] ?? '';
    $provider  = ($srcType === 'paymaya') ? 'maya' : 'gcash';
    $billing   = $paid['attributes']['billing'] ?? [];
    $firstTime = paymongo_record_paid(
        $conn, $appId, $userId, $ref, $provider, $sessionId,
        $paid['id'] ?? $sessionId, $paidCentavos,
        $billing['name'] ?? null, $billing['email'] ?? null,
        json_encode($res['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    return ['state' => 'paid', 'ref' => $ref, 'already' => !$firstTime, 'message' => 'Payment confirmed.'];
}

/**
 * Write a confirmed payment to the database (ledger + payments + application status + notifications).
 * Idempotent. Returns true only for the first caller.
 */
function paymongo_record_paid(mysqli $conn, int $appId, int $userId, string $ref, string $provider,
    string $sessionId, string $paymentId, int $paidCentavos, ?string $payerName, ?string $payerEmail, string $rawJson): bool
{
    $amountStr = number_format($paidCentavos / 100, 2, '.', '');

    /* Ledger row (idempotent on the session id) */
    $ins = $conn->prepare("
        INSERT INTO paypal_transactions
            (application_id, user_id, reference_no, payment_provider,
             paypal_order_id, paypal_capture_id, payer_name, payer_email,
             amount, currency, payment_status, raw_response, paid_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PHP', 'completed', ?, NOW())
        ON DUPLICATE KEY UPDATE
            paypal_capture_id = VALUES(paypal_capture_id),
            payment_status    = 'completed',
            raw_response      = VALUES(raw_response),
            updated_at        = NOW()");
    $ins->bind_param('iissssssss', $appId, $userId, $ref, $provider,
        $sessionId, $paymentId, $payerName, $payerEmail, $amountStr, $rawJson);
    if (!$ins->execute()) error_log('[PayMongo] ledger insert failed: ' . $ins->error);
    $ins->close();

    /* Mark paid. affected_rows > 0 only for the FIRST caller -> notify once. */
    $dbMethod = paymongo_methods()[$provider]['db'];
    $u1 = $conn->prepare("
        UPDATE payments
           SET status = 'paid', payment_method = ?, transaction_id = ?, paid_at = NOW()
         WHERE application_id = ? AND status <> 'paid'");
    $u1->bind_param('ssi', $dbMethod, $paymentId, $appId);
    $u1->execute();
    $firstTime = $u1->affected_rows > 0;
    $u1->close();

    if ($firstTime) {
        $u2 = $conn->prepare("
            UPDATE business_applications
               SET status = 'payment_review', updated_at = NOW()
             WHERE id = ?
               AND status NOT IN ('payment_confirmed','under_review','approved','claimed','verified','renewed')");
        $u2->bind_param('i', $appId);
        $u2->execute();
        $u2->close();

        paymongo_notify($conn, $appId, $userId, $ref, paymongo_methods()[$provider]['label']);
    }
    return $firstTime;
}

/** Same notifications the PayPal flow sends (Treasury reviews first). */
function paymongo_notify(mysqli $conn, int $appId, int $userId, string $ref, string $methodLabel): void
{
    $m = $conn->prepare("
        SELECT bi.business_name, CONCAT(acc.firstname, ' ', acc.lastname) AS applicant_name
          FROM business_applications ba
          LEFT JOIN business_info_data bi ON ba.id = bi.application_id
          LEFT JOIN accounts acc          ON ba.user_id = acc.id
         WHERE ba.id = ? LIMIT 1");
    if (!$m) return;
    $m->bind_param('i', $appId);
    $m->execute();
    $meta = $m->get_result()->fetch_assoc() ?: [];
    $m->close();

    $biz  = $meta['business_name']  ?? 'a business';
    $name = trim($meta['applicant_name'] ?? '') ?: 'An applicant';

    notifyByUserType($conn, [3],
        'Payment Under Review — Please Verify',
        "$name paid via $methodLabel for \"$biz\" (Ref: $ref). Please review the payment, then proceed the application to BPLO.",
        'dashboard_treasury.php', 'warning');

    notifyByUserType($conn, [5],
        'Payment Received',
        "\"$biz\" (Ref: $ref) — $methodLabel payment received and awaiting verification.",
        null, 'info');

    if ($userId > 0) {
        createNotification($conn, $userId,
            'Payment Received — Under Review',
            "We've received your $methodLabel payment for \"$biz\" (Ref: $ref). The Treasury Office is now reviewing your payment. You will be notified once it is confirmed.",
            null, 'success');
    }
}
