<?php
session_start();
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
        throw new Exception('Unauthorized access');
    }

    if (!isset($_POST['application_id']) || empty($_POST['application_id'])) {
        throw new Exception('Application ID is required');
    }

    $application_id = (int)$_POST['application_id'];
    require_once __DIR__ . '/../config/connect.php';
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $sql = "SELECT id, application_id, document_type, file_path, original_name, uploaded_at 
            FROM document_uploads 
            WHERE application_id = ? 
            ORDER BY uploaded_at DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);
    $stmt->bind_param("i", $application_id);
    if (!$stmt->execute()) throw new Exception('Execute error: ' . $stmt->error);
    $result = $stmt->get_result();

    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $fullPath = __DIR__ . '/../' . $row['file_path'];
        $fileExists = file_exists($fullPath);
        $documents[] = [
            'id' => $row['id'],
            'application_id' => $row['application_id'],
            'document_type' => $row['document_type'],
            'file_path' => $row['file_path'],
            'original_name' => $row['original_name'],
            'uploaded_at' => $row['uploaded_at'],
            'file_size' => $fileExists ? filesize($fullPath) : 0,
            'file_exists' => $fileExists
        ];
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'documents' => $documents,
        'count' => count($documents)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>