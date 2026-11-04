<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$date = $_GET['date'] ?? '';
if (!$date) {
    echo json_encode(['error' => 'Date required']);
    exit;
}

// Validate date format and that it's not in the past (optional)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => 'Invalid date format']);
    exit;
}

require_once 'config/connect.php';
$user_id = $_SESSION['user_id'];

// Get current draft application ID
$appQuery = "SELECT id FROM business_applications WHERE user_id = ? AND status = 'draft' ORDER BY created_at DESC LIMIT 1";
$stmt = $conn->prepare($appQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$appResult = $stmt->get_result();
$currentApp = $appResult->fetch_assoc();
$currentAppId = $currentApp ? $currentApp['id'] : 0;

// Fetch booked slots: only confirmed appointments, exclude the current application's own appointment
$query = "SELECT appointment_time FROM appointments WHERE appointment_date = ? AND application_id != ? AND status = 'confirmed'";
$stmt = $conn->prepare($query);
$stmt->bind_param('si', $date, $currentAppId);
$stmt->execute();
$result = $stmt->get_result();

$booked = [];
while ($row = $result->fetch_assoc()) {
    $booked[] = $row['appointment_time'];
}

echo json_encode(['booked' => $booked]);
?>