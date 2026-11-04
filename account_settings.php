<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config/connect.php';

$embed = (isset($_GET['embed']) && $_GET['embed'] === '1');

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Fetch current user data
$stmt = $conn->prepare("SELECT firstname, lastname, email, contact_number FROM accounts WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = trim($_POST['firstname']);
    $lastname  = trim($_POST['lastname']);
    $contact   = trim($_POST['contact']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];

    if (empty($firstname)) $errors[] = 'First name is required.';
    if (empty($lastname)) $errors[] = 'Last name is required.';

    // If password change is requested
    if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
        // Verify current password (MD5)
        $stmt = $conn->prepare("SELECT password FROM accounts WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($hashed_password);
        $stmt->fetch();
        $stmt->close();

        if (md5($current_password) !== $hashed_password) {
            $errors[] = 'Current password is incorrect.';
        }
        if (strlen($new_password) < 8 && !empty($new_password)) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($new_password !== $confirm_password) {
            $errors[] = 'New passwords do not match.';
        }
    }

    if (empty($errors)) {
        // Update basic info
        $update_sql = "UPDATE accounts SET firstname = ?, lastname = ?, contact_number = ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sssi", $firstname, $lastname, $contact, $user_id);
        $stmt->execute();
        $stmt->close();

        // Update password if provided
        if (!empty($new_password)) {
            $new_hashed = md5($new_password);
            $stmt = $conn->prepare("UPDATE accounts SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $new_hashed, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        // Update session name
        $_SESSION['user_name'] = $firstname . ' ' . $lastname;
        $message = 'Profile updated successfully.';
        // Refresh user data
        $user['firstname'] = $firstname;
        $user['lastname'] = $lastname;
        $user['contact_number'] = $contact;
    } else {
        $error = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings | Buyawanong Placer</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; }
        body.embed-mode { background: #f5f7fa; }
        .settings-card {
            border-radius: 1.25rem;
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05);
        }
        body.embed-mode .settings-card {
            box-shadow: none;
            border-radius: 0;
            padding: 1.25rem !important;
        }
        .btn-save {
            background: #198754;
            border-radius: 2rem;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            color: #2c5a3a;
        }
    </style>
</head>
<body class="<?php echo $embed ? 'embed-mode' : ''; ?>">

<?php if (!$embed) { include 'header/nav.php'; } ?>

<div class="container <?php echo $embed ? 'py-3' : 'py-5'; ?>">
    <div class="row justify-content-center">
        <div class="col-lg-<?php echo $embed ? '12' : '6'; ?>">
            <div class="settings-card p-4 p-md-5 bg-white">

                <?php if (!$embed): ?>
                <div class="text-center mb-4">
                    <i class="bi bi-person-gear fs-1 text-success"></i>
                    <h3 class="fw-bold mt-2">Account Settings</h3>
                    <p class="text-muted">Update your personal information</p>
                </div>
                <?php endif; ?>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" name="firstname" class="form-control" value="<?php echo htmlspecialchars($user['firstname']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="lastname" class="form-control" value="<?php echo htmlspecialchars($user['lastname']); ?>" required>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Email Address (cannot be changed)</label>
                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                        <small class="text-muted">Contact support to change your email.</small>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Contact Number</label>
                        <div class="input-group">
                            <span class="input-group-text">+63</span>
                            <input type="tel" name="contact" class="form-control" value="<?php echo htmlspecialchars($user['contact_number']); ?>" placeholder="912 345 6789">
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-bold">Change Password (optional)</h6>
                    <div class="mt-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" autocomplete="off">
                    </div>
                    <div class="mt-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" autocomplete="off">
                        <small class="text-muted">At least 8 characters</small>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" autocomplete="off">
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-save btn-success w-100">
                            <i class="bi bi-check-lg"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($embed && $message): ?>
<script>
  if (window.parent !== window) {
    window.parent.postMessage({
      type: 'account-settings-updated',
      name: <?php echo json_encode(trim($firstname . ' ' . $lastname)); ?>
    }, '*');
  }
</script>
<?php endif; ?>
</body>
</html>