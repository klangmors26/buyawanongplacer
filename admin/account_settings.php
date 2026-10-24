<?php
session_start();

// Allow user_type 2, 3, and 4
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4, 5])) {
    $_SESSION['error'] = 'Access denied. Staff only.';
    header('Location: ../login.php');
    exit;
}

require_once '../config/connect.php';

$user_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'LGU Staff';
$user_type = (int)$_SESSION['user_type'];

// Fetch current user data
$stmt = $conn->prepare("SELECT firstname, lastname, email, contact_number FROM accounts WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = trim($_POST['firstname']);
    $lastname  = trim($_POST['lastname']);
    $contact   = trim($_POST['contact']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];

    // Basic validation
    if (empty($firstname)) $errors[] = 'First name is required.';
    if (empty($lastname)) $errors[] = 'Last name is required.';

    // Clean contact number: remove non-digits, then remove leading '63' if present
    $contact_clean = preg_replace('/[^0-9]/', '', $contact);
    if (strlen($contact_clean) > 0) {
        if (strpos($contact_clean, '63') === 0) {
            $contact_clean = substr($contact_clean, 2);
        }
        if (strlen($contact_clean) < 10) {
            $errors[] = 'Contact number must be at least 10 digits (after +63).';
        }
    }

    // Password change logic
    $change_password = !empty($current_password) || !empty($new_password) || !empty($confirm_password);
    if ($change_password) {
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM accounts WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($hashed_password);
        $stmt->fetch();
        $stmt->close();

        if (empty($current_password) || md5($current_password) !== $hashed_password) {
            $errors[] = 'Current password is incorrect.';
        }
        if (strlen($new_password) < 8 && !empty($new_password)) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($new_password !== $confirm_password) {
            $errors[] = 'New passwords do not match.';
        }
        if (empty($new_password) && ($change_password)) {
            $errors[] = 'New password cannot be empty if you want to change it.';
        }
    }

    if (empty($errors)) {
        // Update basic info
        $update_sql = "UPDATE accounts SET firstname = ?, lastname = ?, contact_number = ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sssi", $firstname, $lastname, $contact_clean, $user_id);
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
        // Refresh user data for display
        $user['firstname'] = $firstname;
        $user['lastname'] = $lastname;
        $user['contact_number'] = $contact_clean;
    } else {
        $error = implode('<br>', $errors);
    }
}

// Get user type label
$user_type_labels = [
    2 => 'BPLO Staff',
    3 => 'Treasury Staff',
    4 => 'Approval Staff'
];
$user_type_label = $user_type_labels[$user_type] ?? 'Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Account Settings | Admin</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body {
            background: #f0f4f8;
        }
        .gov-header {
            background: #1e4b2e;
            color: white;
            padding: 0.7rem 1.5rem;
            border-bottom: 3px solid #ffc107;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1030;
        }
        .logo-circle {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #1e4b2e;
            margin-right: 0.8rem;
        }
        .user-greeting {
            background: rgba(255,255,255,0.15);
            border-radius: 40px;
            padding: 0.3rem 1rem;
            font-weight: 500;
            cursor: pointer;
        }
        .settings-card {
            border-radius: 1.25rem;
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05);
            background: white;
        }
        .btn-save {
            background: #198754;
            border-radius: 2rem;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            border: none;
            transition: all 0.2s;
        }
        .btn-save:hover {
            background: #0f5937;
            transform: translateY(-2px);
        }
        .btn-back {
            border-radius: 2rem;
            padding: 0.4rem 1.2rem;
            font-weight: 500;
            border-color: #1e4b2e;
            color: #1e4b2e;
        }
        .btn-back:hover {
            background: #1e4b2e;
            color: white;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            color: #2c5a3a;
            margin-bottom: 0.4rem;
        }
        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 3px rgba(25, 135, 84, 0.2);
        }
        .input-group-text {
            background: #f8f9fa;
        }
        .user-type-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.2rem 0.8rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 500;
        }
    </style>
</head>
<body>

<header class="gov-header d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
        <div class="logo-circle">
            <i class="bi bi-building"></i>
        </div>
        <div>
            <h5 class="mb-0 fw-bold">Municipality of Placer, SDN</h5>
            <small>Business Permit & Licensing Office (BPLO)</small>
        </div>
    </div>
    <div class="dropdown">
        <a class="dropdown-toggle user-greeting text-decoration-none text-white" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($staff_name); ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="account_settings.php"><i class="bi bi-gear"></i> Account Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
        </ul>
    </div>
</header>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="settings-card p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="fw-bold mb-0" style="color: #1e4b2e;">
                            <i class="bi bi-person-gear me-2"></i>Account Settings
                        </h4>
                        <small class="text-muted">Role: <?php echo $user_type_label; ?></small>
                    </div>
                    <a href="index.php" class="btn btn-back">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>

                <form method="POST" id="settingsForm">
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
                            <input type="tel" name="contact" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['contact_number']); ?>" 
                                   placeholder="912 345 6789" pattern="[0-9 ]*">
                        </div>
                        <small class="text-muted">Enter only digits (e.g., 9123456789).</small>
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

<?php if ($message): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: '<?php echo addslashes($message); ?>',
        timer: 3000,
        showConfirmButton: false
    });
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
    Swal.fire({
        icon: 'error',
        title: 'Update Failed',
        html: '<?php echo addslashes($error); ?>',
        confirmButtonColor: '#198754'
    });
</script>
<?php endif; ?>

</body>
</html>