<?php
session_start();

// Allow only user_type 3 or 4
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
    http_response_code(403);
    die('Unauthorized');
}

if (!isset($_GET['file']) || empty($_GET['file'])) {
    http_response_code(400);
    die('File parameter missing');
}

$filePath = $_GET['file'];

// Security: Prevent directory traversal
$filePath = realpath(__DIR__ . '/../' . $filePath);
if ($filePath === false) {
    http_response_code(404);
    die('File not found');
}

// Ensure the file is inside the uploads directory
$uploadsDir = realpath(__DIR__ . '/../uploads/');
if (strpos($filePath, $uploadsDir) !== 0) {
    http_response_code(403);
    die('Access denied');
}

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found');
}

// Get file info
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);
finfo_close($finfo);

// Set headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
header('Cache-Control: public, max-age=86400');

// Output the file
readfile($filePath);
exit;
?>