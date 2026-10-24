<?php
session_start();
require_once __DIR__ . '/config/connect.php';

$ref    = $_GET['ref']    ?? '';
$amount = (float)($_GET['amount'] ?? 0);

/* 🛡️ Refuse to render the PayPal buttons if the app is already paid */
if (isset($_SESSION['user_id']) && $ref) {
    $uid = (int)$_SESSION['user_id'];
    $q = $conn->prepare("
        SELECT ba.status AS app_status, p.status AS payment_status
          FROM business_applications ba
          LEFT JOIN payments p ON ba.id = p.application_id
         WHERE ba.reference_no = ? AND ba.user_id = ?
         LIMIT 1
    ");
    if ($q) {
        $q->bind_param('si', $ref, $uid);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();

        $paid = $r && (
            ($r['payment_status'] ?? '') === 'paid'
            || in_array($r['app_status'] ?? '', ['payment_confirmed', 'approved', 'released'], true)
        );

        if ($paid) {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
               . '<style>body{font-family:Inter,sans-serif;padding:2rem;text-align:center;color:#198754;}'
               . '.check{font-size:3rem;}</style></head><body>'
               . '<div class="check">✓</div>'
               . '<h3>Already paid</h3>'
               . '<p style="color:#64748b;">This application has already been paid. '
               . 'You cannot pay again.</p>'
               . '<script>if(window.parent!==window){'
               . 'window.parent.postMessage({type:"already_paid"}, "*");}</script>'
               . '</body></html>';
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PayPal</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    *{font-family:'Inter',sans-serif;box-sizing:border-box;}
    html, body{
        margin:0; padding:0; height:100%;
        background:#fff; color:#1e293b;
    }
    body{
        min-height:100vh;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        text-align:center;
        padding:24px 20px;
        gap:16px;
    }
    .wrap{
        width:100%;
        max-width:440px;
        display:flex;
        flex-direction:column;
        align-items:center;
        gap:16px;
    }
    .summary{
        background:#f8fafc;
        border:1px solid #e2e8f0;
        border-radius:12px;
        padding:16px 20px;
        width:100%;
        text-align:center;
    }
    .summary h4{margin:0 0 6px;font-size:1rem;font-weight:600;color:#1e293b;}
    .summary .ref{color:#64748b;font-size:.78rem;margin:0 0 8px;}
    .summary .amt{font-size:1.6rem;font-weight:700;color:#198754;line-height:1;}
    #paypal-button-container{
        min-height:120px;
        width:100%;
        display:flex;
        align-items:center;
        justify-content:center;
    }
    .muted{color:#64748b;font-size:.78rem;margin:0;max-width:380px;}
    .divider{width:100%;height:1px;background:#e2e8f0;}
</style>
</head>
<body>
    <div class="wrap">
        <div class="summary">
            <h4>Business Permit Payment</h4>
            <p class="ref">Ref: <?php echo htmlspecialchars($ref); ?></p>
            <div class="amt">₱<?php echo number_format($amount, 2); ?></div>
        </div>

        <div id="paypal-button-container"></div>

        <div class="divider"></div>

        <p class="muted">
            You will be redirected to PayPal to complete your payment securely.
        </p>
    </div>

    <script>
        const REF    = <?php echo json_encode($ref); ?>;
        const AMOUNT = <?php echo json_encode(number_format($amount, 2, '.', '')); ?>;
    </script>

    <!-- Client ID MUST match payment_success.php. Force PHP + capture intent. -->
    <script src="https://www.paypal.com/sdk/js?client-id=BAAAqXYT7FK2FGKXpxpfobYKxW7-VzAcdybosFIiId5lDBG71g3QMfCTZLhGTmLcs-19Crxb3w_6LCK8X8&currency=PHP&intent=capture&components=buttons&disable-funding=venmo,paylater"></script>

    <script>
    paypal.Buttons({
        style: {
            layout : 'vertical',
            color  : 'gold',
            shape  : 'rect',
            label  : 'paypal',
            height : 45
        },

        createOrder: function (data, actions) {
            return actions.order.create({
                intent: 'CAPTURE',
                purchase_units: [{
                    reference_id: REF,
                    description : 'Business Permit Payment — Ref: ' + REF,
                    amount: {
                        value        : AMOUNT,
                        currency_code: 'PHP'
                    }
                }],
                application_context: {
                    brand_name          : 'Business Permit Portal',
                    shipping_preference : 'NO_SHIPPING',
                    user_action         : 'PAY_NOW'
                }
            });
        },

        onApprove: function (data, actions) {
            return actions.order.capture().then(function (details) {
                var url = 'payment_success.php?embed=1'
                        + '&ref='          + encodeURIComponent(REF)
                        + '&paypal_order=' + encodeURIComponent(data.orderID);
                window.location.href = url;
            });
        },

        onCancel: function () {
            window.parent.postMessage({ type: 'paypal_cancel' }, '*');
        },

        onError: function (err) {
            console.error('PayPal error:', err);
            window.parent.postMessage({ type: 'paypal_error', error: String(err) }, '*');
        }
    }).render('#paypal-button-container');
    </script>
</body>
</html>