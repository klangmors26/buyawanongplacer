<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/config/connect.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['paid' => false, 'error' => 'auth']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$ref     = $_GET['ref'] ?? '';

if (!$ref) {
    echo json_encode(['paid' => false, 'error' => 'missing_ref']);
    exit;
}

$stmt = $conn->prepare("
    SELECT ba.status AS app_status, p.status AS payment_status
      FROM business_applications ba
      LEFT JOIN payments p ON ba.id = p.application_id
     WHERE ba.reference_no = ? AND ba.user_id = ?
     LIMIT 1
");
$stmt->bind_param('si', $ref, $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['paid' => false, 'error' => 'not_found']);
    exit;
}

$paid = (
    ($row['payment_status'] ?? '') === 'paid'
    || in_array($row['app_status'] ?? '', ['payment_confirmed', 'approved', 'released'], true)
);

echo json_encode(['paid' => $paid]);