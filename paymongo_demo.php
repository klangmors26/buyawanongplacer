<?php
/**
 * paymongo_demo.php — SIMULATED GCash/Maya checkout for demos. No real money moves.
 * Only works when PAYMONGO_DEMO is on AND no real PayMongo key is set.
 */
session_start();
require_once __DIR__ . '/config/connect.php';
require_once __DIR__ . '/includes/paymongo.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!paymongo_demo() || paymongo_config()['secret_key'] !== '') { http_response_code(404); exit('Not available.'); }

$user_id = (int)$_SESSION['user_id'];
$ref     = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
$demo    = $_SESSION['pm_demo'][$ref] ?? null;
if (!$ref || !$demo) { header('Location: payment.php?ref=' . urlencode($ref)); exit; }

$methods = paymongo_methods();
$label   = $methods[$demo['method']]['label'];
$isGcash = $demo['method'] === 'gcash';

$stmt = $conn->prepare("
    SELECT ba.id, ba.user_id, bi.business_name, p.amount_due, p.status AS payment_status
      FROM business_applications ba
      LEFT JOIN business_info_data bi ON ba.id = bi.application_id
      LEFT JOIN payments p            ON ba.id = p.application_id
     WHERE ba.reference_no = ? AND ba.user_id = ? LIMIT 1");
$stmt->bind_param('si', $ref, $user_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$app) die('Application not found.');
if (($app['payment_status'] ?? '') === 'paid') { header('Location: payment_success.php?ref=' . urlencode($ref)); exit; }

$amount = (float)$app['amount_due'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($demo['token'], $_POST['token'] ?? '')) die('Invalid request.');

    if (($_POST['action'] ?? '') === 'pay') {
        $sid = 'demo_cs_'  . bin2hex(random_bytes(8));
        $pid = 'demo_pay_' . bin2hex(random_bytes(8));
        $raw = json_encode(['demo' => true, 'method' => $demo['method'], 'note' => 'Simulated payment, no real money.']);
        paymongo_record_paid($conn, (int)$app['id'], $user_id, $ref, $demo['method'],
            $sid, $pid, (int) round($amount * 100), null, null, $raw);
        unset($_SESSION['pm_demo'][$ref]);
        header('Location: payment_success.php?ref=' . urlencode($ref));
        exit;
    }
    unset($_SESSION['pm_demo'][$ref]);
    header('Location: payment_cancel.php?ref=' . urlencode($ref));
    exit;
}
$brand  = $isGcash ? '#007dfe' : '#00b46e';
$brand2 = $isGcash ? '#0057b8' : '#008a54';
$bizName = $app['business_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $label; ?> Checkout (Demo)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{background:#e9eef5;font-family:Inter,system-ui,sans-serif}
  .phone{max-width:400px;margin:1.5rem auto;border-radius:1.5rem;overflow:hidden;background:#fff;box-shadow:0 8px 32px rgba(15,23,42,.18);min-height:620px;display:flex;flex-direction:column}
  .demo-bar{background:#fff3cd;color:#664d03;font-size:.72rem;text-align:center;padding:.4rem}
  .top{background:linear-gradient(160deg,<?php echo $brand; ?>,<?php echo $brand2; ?>);color:#fff;padding:1.4rem 1.5rem 1.6rem}
  .brand{font-weight:800;font-size:1.5rem;letter-spacing:-.5px}
  .body{padding:1.5rem;flex:1}
  .step{display:none}.step.active{display:block;animation:f .25s}
  @keyframes f{from{opacity:0;transform:translateY(8px)}to{opacity:1}}
  .btn-brand{background:<?php echo $brand; ?>;color:#fff;border:0;border-radius:2rem;padding:.75rem;font-weight:600}
  .btn-brand:hover{background:<?php echo $brand2; ?>;color:#fff}
  .btn-brand:disabled{opacity:.5}
  .pin-dots{display:flex;gap:.75rem;justify-content:center;margin:1.2rem 0}
  .pin-dots span{width:14px;height:14px;border-radius:50%;border:2px solid <?php echo $brand; ?>}
  .pin-dots span.on{background:<?php echo $brand; ?>}
  .keypad{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem}
  .keypad button{border:0;background:#f1f5f9;border-radius:.9rem;padding:.9rem;font-size:1.2rem;font-weight:600}
  .keypad button:active{background:#e2e8f0}
  .otp{letter-spacing:.6rem;text-align:center;font-size:1.5rem}
  .row-line{display:flex;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid #eef2f7;font-size:.9rem}
  .check{width:84px;height:84px;border-radius:50%;background:<?php echo $brand; ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:2.6rem;margin:1rem auto;animation:pop .4s}
  @keyframes pop{0%{transform:scale(.3)}70%{transform:scale(1.15)}100%{transform:scale(1)}}
  .note{font-size:.72rem;color:#64748b;text-align:center;margin-top:1rem}
</style>
</head>
<body>
<div class="phone">
  <div class="demo-bar"><i class="bi bi-info-circle"></i> DEMO MODE — simulated payment. No real money is charged; what you type is not saved.</div>
  <div class="top">
    <div class="brand"><?php echo $label; ?></div>
    <div class="small opacity-75">Secure checkout (sandbox)</div>
    <div class="mt-3 small opacity-75">Paying</div>
    <div class="fs-3 fw-bold">₱<?php echo number_format($amount, 2); ?></div>
  </div>

  <div class="body">
    <!-- Step 1: mobile number -->
    <div class="step active" id="s1">
      <h6 class="fw-bold">Log in to your <?php echo $label; ?></h6>
      <p class="text-muted small">Enter your mobile number (any number works in this demo).</p>
      <div class="input-group mb-3">
        <span class="input-group-text">+63</span>
        <input type="tel" id="mobile" class="form-control" placeholder="9XX XXX XXXX" maxlength="10" inputmode="numeric" autocomplete="off">
      </div>
      <button class="btn btn-brand w-100" id="to2" disabled>Next</button>
    </div>

    <!-- Step 2: OTP -->
    <div class="step" id="s2">
      <h6 class="fw-bold">Enter the 6-digit code</h6>
      <p class="text-muted small">We “sent” a code to <strong id="shownum"></strong>. Type any 6 digits (demo).</p>
      <input type="text" id="otp" class="form-control otp mb-3" maxlength="6" inputmode="numeric" placeholder="······" autocomplete="off">
      <button class="btn btn-brand w-100" id="to3" disabled>Verify</button>
    </div>

    <!-- Step 3: PIN -->
    <div class="step" id="s3">
      <h6 class="fw-bold text-center">Enter your <?php echo $isGcash ? 'MPIN' : 'MPIN'; ?></h6>
      <p class="text-muted small text-center mb-0">Type any 4 digits (demo).</p>
      <div class="pin-dots" id="dots"><span></span><span></span><span></span><span></span></div>
      <div class="keypad" id="pad">
        <?php foreach ([1,2,3,4,5,6,7,8,9] as $k): ?><button type="button" data-k="<?php echo $k; ?>"><?php echo $k; ?></button><?php endforeach; ?>
        <button type="button" data-k="x"><i class="bi bi-backspace"></i></button>
        <button type="button" data-k="0">0</button>
        <span></span>
      </div>
    </div>

    <!-- Step 4: review -->
    <div class="step" id="s4">
      <h6 class="fw-bold">Review payment</h6>
      <div class="mt-3">
        <div class="row-line"><span class="text-muted">Merchant</span><strong>Business Permit Portal</strong></div>
        <div class="row-line"><span class="text-muted">Business</span><strong><?php echo htmlspecialchars($bizName); ?></strong></div>
        <div class="row-line"><span class="text-muted">Reference</span><strong><?php echo htmlspecialchars($ref); ?></strong></div>
        <div class="row-line"><span class="text-muted">Amount</span><strong>₱<?php echo number_format($amount, 2); ?></strong></div>
        <div class="row-line"><span class="text-muted">Fee</span><strong>₱0.00</strong></div>
        <div class="row-line"><span>Total</span><strong style="color:<?php echo $brand; ?>">₱<?php echo number_format($amount, 2); ?></strong></div>
      </div>
      <button class="btn btn-brand w-100 mt-4" id="payNow">Pay ₱<?php echo number_format($amount, 2); ?></button>
    </div>

    <!-- Step 5: success -->
    <div class="step text-center" id="s5">
      <div class="check"><i class="bi bi-check-lg"></i></div>
      <h5 class="fw-bold">Payment successful</h5>
      <p class="text-muted small mb-1">₱<?php echo number_format($amount, 2); ?> paid via <?php echo $label; ?></p>
      <p class="text-muted small">Returning to the Business Permit Portal…</p>
      <div class="spinner-border spinner-border-sm text-secondary"></div>
    </div>

    <form method="post" id="payForm" class="d-none">
      <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref, ENT_QUOTES); ?>">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($demo['token'], ENT_QUOTES); ?>">
      <input type="hidden" name="action" value="pay">
    </form>
    <form method="post" id="cancelForm" class="text-center" style="margin-top:1rem">
      <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref, ENT_QUOTES); ?>">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($demo['token'], ENT_QUOTES); ?>">
      <input type="hidden" name="action" value="cancel">
      <button class="btn btn-link btn-sm text-muted" id="cancelBtn">Cancel payment</button>
    </form>
    <div class="note">Demo only — not connected to GCash, Maya or any bank.</div>
  </div>
</div>

<script>
const $ = id => document.getElementById(id);
function show(n){document.querySelectorAll('.step').forEach(e=>e.classList.remove('active'));$('s'+n).classList.add('active');
  $('cancelForm').style.display = n===5 ? 'none' : '';}
const mobile=$('mobile'), otp=$('otp');
mobile.addEventListener('input',()=>{mobile.value=mobile.value.replace(/\D/g,'');$('to2').disabled=mobile.value.length<10;});
$('to2').onclick=()=>{$('shownum').textContent='+63 '+mobile.value.slice(0,3)+' *** '+mobile.value.slice(-2);show(2);otp.focus();};
otp.addEventListener('input',()=>{otp.value=otp.value.replace(/\D/g,'');$('to3').disabled=otp.value.length<6;});
$('to3').onclick=()=>show(3);
let pin='';
function dots(){document.querySelectorAll('#dots span').forEach((d,i)=>d.classList.toggle('on',i<pin.length));}
document.querySelectorAll('#pad button').forEach(b=>b.onclick=()=>{
  const k=b.dataset.k; if(k==='x') pin=pin.slice(0,-1); else if(pin.length<4) pin+=k;
  dots(); if(pin.length===4) setTimeout(()=>show(4),250);
});
$('payNow').onclick=()=>{ $('payNow').disabled=true; show(5); setTimeout(()=>$('payForm').submit(),2200); };
</script>
</body>
</html>
