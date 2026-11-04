<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once '../config/connect.php';

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$firstname        = trim($_POST['firstname'] ?? '');
$lastname         = trim($_POST['lastname']  ?? '');
$contact          = trim($_POST['contact']   ?? '');
$current_password = $_POST['current_password'] ?? '';
$new_password     = $_POST['new_password']     ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

$errors = [];

if (empty($firstname)) $errors[] = 'First name is required.';
if (empty($lastname))  $errors[] = 'Last name is required.';

$contact_clean = preg_replace('/[^0-9]/', '', $contact);
if (strlen($contact_clean) > 0) {
    if (strpos($contact_clean, '63') === 0) {
        $contact_clean = substr($contact_clean, 2);
    }
    if (strlen($contact_clean) < 10) {
        $errors[] = 'Contact number must be at least 10 digits (after +63).';
    }
}

$change_password = !empty($current_password) || !empty($new_password) || !empty($confirm_password);
if ($change_password) {
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
    if (empty($new_password)) {
        $errors[] = 'New password cannot be empty if you want to change it.';
    }
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

$stmt = $conn->prepare("UPDATE accounts SET firstname = ?, lastname = ?, contact_number = ? WHERE id = ?");
$stmt->bind_param("sssi", $firstname, $lastname, $contact_clean, $user_id);
$stmt->execute();
$stmt->close();

if (!empty($new_password)) {
    $new_hashed = md5($new_password);
    $stmt = $conn->prepare("UPDATE accounts SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $new_hashed, $user_id);
    $stmt->execute();
    $stmt->close();
}

$_SESSION['user_name'] = trim($firstname . ' ' . $lastname);

echo json_encode([
    'success' => true,
    'message' => 'Profile updated successfully.',
    'name'    => $_SESSION['user_name']
]);