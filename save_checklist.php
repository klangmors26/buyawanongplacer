<?php
session_start();
require_once 'includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$application = getOrCreateDraftApplication($user_id);
$app_id = $application['id'];

$input = json_decode(file_get_contents('php://input'), true);
$checklist = $input['checklist'] ?? [];

global $conn;
$checklistJson = json_encode($checklist);
$sql = "UPDATE business_applications SET checklist_data = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $checklistJson, $app_id);
$success = $stmt->execute();

echo json_encode(['success' => $success]);
?>