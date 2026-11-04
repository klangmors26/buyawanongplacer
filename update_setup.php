<?php
session_start();
require_once 'includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$step = $data['step'] ?? 0;
if ($step < 1 || $step > 8) {
    echo json_encode(['success' => false, 'error' => 'Invalid step']);
    exit;
}

$application = getOrCreateDraftApplication($_SESSION['user_id']);
$app_id = $application['id'];

$sql = "UPDATE business_applications SET current_step = ? WHERE id = ?";
global $conn;
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $step, $app_id);
$success = $stmt->execute();

echo json_encode(['success' => $success]);
?>