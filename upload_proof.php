<?php
session_start();
require_once 'includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$ref = $_POST['reference'] ?? '';
if (!$ref) {
    echo json_encode(['error' => 'Missing reference']);
    exit;
}

$user_id = $_SESSION['user_id'];
$app = $conn->query("SELECT id FROM business_applications WHERE reference_no='$ref' AND user_id=$user_id")->fetch_assoc();
if (!$app) {
    echo json_encode(['error' => 'Application not found']);
    exit;
}

if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

// Save file in uploads/proofs/
$uploadDir = __DIR__ . '/uploads/proofs/';
if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
$ext = pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION);
$fileName = 'proof_' . $ref . '_' . time() . '.' . $ext;
$targetPath = $uploadDir . $fileName;

if (move_uploaded_file($_FILES['proof']['tmp_name'], $targetPath)) {
    // Optionally update a `payment_proofs` table or just record in payments table
    $relativePath = 'uploads/proofs/' . $fileName;
    $sql = "INSERT INTO payments (application_id, amount, payment_method, status, metadata) VALUES (?, ?, 'over_counter', 'pending_proof', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ids", $app['id'], $grandTotal, json_encode(['proof_path' => $relativePath]));
    $stmt->execute();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Failed to save file']);
}
?>