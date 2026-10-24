<?php
session_start();
header('Content-Type: application/json');
require_once 'config/connect.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE appointments 
                        SET status = 'cancelled', updated_at = NOW() 
                        WHERE user_id = ? AND status = 'pending'");
$stmt->bind_param('i', $user_id);

if ($stmt->execute()) {
    $affected = $stmt->affected_rows;
    $stmt->close();
    echo json_encode(['success' => true, 'cancelled' => $affected]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed: ' . $conn->error]);
    $stmt->close();
}