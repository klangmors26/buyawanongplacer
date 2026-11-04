<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

$user_id = $_SESSION['user_id'];

require_once 'config/connect.php';

// Cancel the user's appointment (set status to cancelled)
$stmt = $conn->prepare("UPDATE appointments SET status = 'cancelled' WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();

// Clear session variables
unset($_SESSION['appointment_confirmed']);
unset($_SESSION['appointment_date']);
unset($_SESSION['appointment_time']);

header('Location: dashboard.php');
exit;
?>