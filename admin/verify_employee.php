<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/connect.php';

// Must match VERIFY_SECRET in admin/index.php
define('VERIFY_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING_32CHARS_MIN');

function verifyEmployeeToken($token) {
    $decoded = base64_decode(strtr($token, '-_', '+/'), true);
    if ($decoded === false) return ['error' => 'malformed'];
    $parts = explode('|', $decoded);
    if (count($parts) !== 4) return ['error' => 'malformed'];
    list($employeeId, $email, $expires, $sig) = $parts;
    if ((int)$expires < time()) return ['error' => 'expired'];
    $expected = hash_hmac('sha256', $employeeId . '|' . $email . '|' . $expires, VERIFY_SECRET);
    if (!hash_equals($expected, $sig)) return ['error' => 'invalid'];
    return ['employee_id' => (int)$employeeId, 'email' => $email];
}

$token  = $_GET['token'] ?? '';
$result = verifyEmployeeToken($token);

// ---------- Error page helper ----------
function showErrorPage($title, $message, $icon = 'x-circle', $color = '#ef4444') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?> | Buyawanong Placer</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            * { font-family: 'Inter', sans-serif; }
            body {
                background: linear-gradient(135deg, #faf5ef 0%, #f0e6da 100%);
                min-height: 100vh; display: flex; align-items: center; justify-content: center;
                margin: 0; padding: 1rem;
            }
            .msg-card {
                background: #fff; border-radius: 16px;
                box-shadow: 0 20px 60px rgba(43,24,16,0.15);
                max-width: 520px; width: 100%;
                padding: 3rem 2.25rem; text-align: center;
                border-top: 5px solid <?php echo $color; ?>;
            }
            .msg-icon {
                width: 84px; height: 84px; border-radius: 50%;
                background: <?php echo $color; ?>15; color: <?php echo $color; ?>;
                display: inline-flex; align-items: center; justify-content: center;
                font-size: 2.5rem; margin-bottom: 1.25rem;
            }
            .msg-title { font-size: 1.35rem; font-weight: 800; color: #2b1810; margin-bottom: 0.5rem; }
            .msg-text { color: #6d5b4f; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.75rem; }
            .btn-brown {
                background: #6d4c41; color: #fff; border: none; border-radius: 10px;
                padding: 0.75rem 1.75rem; font-weight: 700; font-size: 0.92rem;
                text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
                transition: background 0.15s;
            }
            .btn-brown:hover { background: #2b1810; color: #fff; }
        </style>
    </head>
    <body>
        <div class="msg-card">
            <div class="msg-icon"><i class="bi bi-<?php echo $icon; ?>"></i></div>
            <div class="msg-title"><?php echo htmlspecialchars($title); ?></div>
            <div class="msg-text"><?php echo $message; ?></div>
            <a href="../login.php" class="btn-brown"><i class="bi bi-box-arrow-in-right"></i> Go to Login</a>
        </div>
    </body>
    </html>
    <?php
}

if (isset($result['error'])) {
    switch ($result['error']) {
        case 'expired':
            showErrorPage('Link Expired',
                'This verification link has expired (valid for 48 hours). Please ask the administrator to resend the verification email.',
                'clock-history', '#f59e0b');
            exit;
        case 'invalid':
            showErrorPage('Invalid Link',
                'This verification link is invalid or has been tampered with. Please use the link from the original email.',
                'shield-x', '#ef4444');
            exit;
        default:
            showErrorPage('Invalid Link',
                'We could not read this verification link. Please make sure you copied the full URL.',
                'question-circle', '#ef4444');
            exit;
    }
}

$employeeId = (int)$result['employee_id'];
$email      = $result['email'];

// ---------- Look up the account ----------
$stmt = $conn->prepare("SELECT id, user_type, firstname, lastname, email, verified FROM accounts WHERE id = ? AND email = ? LIMIT 1");
$stmt->bind_param("is", $employeeId, $email);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$account) {
    showErrorPage('Account Not Found',
        'No matching account was found for this verification link. It may have been deleted.',
        'person-x', '#ef4444');
    exit;
}

// ---------- Mark verified ----------
if ((int)$account['verified'] !== 1) {
    $up = $conn->prepare("UPDATE accounts SET verified = 1 WHERE id = ?");
    $up->bind_param("i", $employeeId);
    $up->execute();
    $up->close();
}

// ---------- Auto sign-in the employee ----------
$_SESSION['user_id']   = (int)$account['id'];
$_SESSION['user_type'] = (int)$account['user_type'];
$_SESSION['user_name'] = trim($account['firstname'] . ' ' . $account['lastname']);
$_SESSION['user_email'] = $account['email'];

// ---------- Redirect to their role-specific dashboard ----------
$ut = (int)$account['user_type'];
switch ($ut) {
    case 3: // Treasury Staff
        header('Location: ../staff/dashboard_treasury.php');
        break;
    case 4: // BPLO / Licensing Staff
        header('Location: ../staff/dashboard_bplo.php');
        break;
    case 5: // Admin
        header('Location: index.php');
        break;
    case 2: // Generic Staff → default to BPLO dashboard
    default:
        header('Location: ../staff/dashboard_bplo.php');
        break;
}
exit;