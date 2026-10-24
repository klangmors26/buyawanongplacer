<?php
session_start();
// Allow only user_type 4 (removed 2)
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 4) {
    header('Location: ../login.php');
    exit;
}
require_once '../config/connect.php';

$app_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($app_id <= 0) {
    die('Invalid application ID.');
}

// Get reference number and current status
$sql = "SELECT reference_no, status FROM business_applications WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $app_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $ref = $row['reference_no'];
    $status = strtolower($row['status']);
    // Allow both 'under_review' and 'submitted' to proceed
    if ($status == 'under_review' || $status == 'submitted') {
        // Update status to 'pending_payment'
        $update = "UPDATE business_applications SET status = 'pending_payment' WHERE id = ?";
        $stmt2 = $conn->prepare($update);
        $stmt2->bind_param("i", $app_id);
        $stmt2->execute();
        $stmt2->close();
    }
    // Redirect to payment page (whether updated or not)
    header('Location: payment.php?ref=' . urlencode($ref));
    exit;
} else {
    die('Application not found.');
}
?>