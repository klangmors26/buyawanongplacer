<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
require_once '../config/connect.php';

$app_id = isset($_GET['application_id']) ? (int)$_GET['application_id'] : 0;
if (!$app_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing application ID']);
    exit;
}

$stmt = $conn->prepare("SELECT missing_documents, notes FROM application_rejections WHERE application_id = ?");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $missing = json_decode($row['missing_documents'], true);
    if (!is_array($missing)) $missing = [];
    echo json_encode(['success' => true, 'missing_documents' => $missing, 'notes' => $row['notes']]);
} else {
    echo json_encode(['success' => true, 'missing_documents' => [], 'notes' => '']);
}
$stmt->close();
?>