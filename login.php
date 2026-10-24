<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once 'config/connect.php';

$error = '';
$success = false;
$redirectUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $conn->prepare("
    SELECT id, user_type, firstname, lastname, email,
           contact_number, password, verified
    FROM accounts
    WHERE email = ?
    LIMIT 1
");

$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $error = 'Invalid email or password. Please try again.';
} else {
    $storedHash = $user['password'];

    // Temporary support for existing MD5 passwords.
    $isLegacyMD5 = preg_match(
        '/^[a-f0-9]{32}$/i',
        $storedHash
    ) === 1;

    if ($isLegacyMD5) {
        $passwordIsValid = hash_equals(
            strtolower($storedHash),
            md5($password)
        );
    } else {
        $passwordIsValid = password_verify(
            $password,
            $storedHash
        );
    }

    if (!$passwordIsValid) {
        $error = 'Invalid email or password. Please try again.';
    } elseif ((int)$user['verified'] !== 1) {
        $error = 'Your account is not verified. Please verify your email before logging in.';
    } else {
        // Upgrade MD5 hash to a modern password hash.
        if (
            $isLegacyMD5 ||
            password_needs_rehash($storedHash, PASSWORD_DEFAULT)
        ) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);

            if ($newHash === false) {
                $error = 'Unable to process login. Please try again.';
            } else {
                $update = $conn->prepare("
                    UPDATE accounts
                    SET password = ?
                    WHERE id = ? AND password = ?
                ");

                if (!$update) {
                    $error = 'Unable to process login. Please try again.';
                } else {
                    $update->bind_param(
                        "sis",
                        $newHash,
                        $user['id'],
                        $storedHash
                    );

                    if (!$update->execute() ||
                        $update->affected_rows !== 1) {
                        $error = 'Unable to update password securely. Please try again.';
                    }

                    $update->close();
                }
            }
        }

        if ($error === '') {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] =
                $user['firstname'] . ' ' . $user['lastname'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_type'] = (int)$user['user_type'];

            $success = true;

            if ($_SESSION['user_type'] == 5) {
                $redirectUrl = 'admin/index.php';
            } elseif ($_SESSION['user_type'] == 3) {
                $redirectUrl = 'staff/dashboard_treasury.php';
            } elseif ($_SESSION['user_type'] == 4) {
                $redirectUrl = 'staff/dashboard_bplo.php';
            } else {
                $redirectUrl = 'dashboard.php';
            }
        }
    }
}
    }

    // Return JSON if it's an AJAX request from our modal
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');

        if ($success) {
            echo json_encode([
                'success'  => true,
                'redirect' => $redirectUrl,
                'name'     => $_SESSION['user_name']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error'   => $error
            ]);
        }
        exit;
    }
}
?>
<!-- The rest of your login.php HTML remains here for fallback -->