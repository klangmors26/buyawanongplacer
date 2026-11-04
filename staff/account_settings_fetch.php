<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once '../config/connect.php';

$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT firstname, lastname, email, contact_number FROM accounts WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

echo json_encode([
    'success'        => true,
    'firstname'      => $user['firstname']      ?? '',
    'lastname'       => $user['lastname']       ?? '',
    'email'          => $user['email']          ?? '',
    'contact_number' => $user['contact_number'] ?? ''
]);