<?php
session_start();
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Mark current draft as abandoned (do not delete – keep for auditing)
$conn->query("UPDATE business_applications SET status = 'abandoned' WHERE user_id = $user_id AND status = 'draft'");

// Clear any appointment session data
unset($_SESSION['appointment_confirmed'], $_SESSION['appointment_date'], $_SESSION['appointment_time']);

// Redirect to new_permit.php – a fresh draft will be created automatically
header('Location: new_permit.php');
exit;