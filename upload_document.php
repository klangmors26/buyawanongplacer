<?php
session_start();
require_once 'includes/functions.php';
require_once 'config/connect.php';

// Enable error logging but not display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/upload_errors.log');

header('Content-Type: application/json');

// ============================================================
// FILE VALIDATION RULES
// ============================================================
const MAX_FILE_SIZE_BYTES = 25 * 1024 * 1024; // 25 MB

$ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
$ALLOWED_MIME_TYPES = [
    'application/pdf',
    'image/jpeg',
    'image/jpg',
    'image/png'
];

try {
    // Check authentication
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    $user_id = $_SESSION['user_id'];

    // Get or create draft application
    $application = getOrCreateDraftApplication($user_id);
    if (!$application || !isset($application['id'])) {
        throw new Exception('Failed to retrieve application');
    }
    $app_id = $application['id'];

    // Validate document type
    $doc_type = $_POST['document_type'] ?? '';
    $allowed = ['barangay', 'dti_sec', 'rpt', 'engineering', 'zoning', 'treasury', 'sanitary', 'fire_safety'];
    if (!in_array($doc_type, $allowed)) {
        throw new Exception('Invalid document type: ' . $doc_type);
    }

    // ============================================================
    // FILE PRESENCE + UPLOAD ERROR CHECK
    // ============================================================
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $_FILES['file']['error'] ?? 'unknown';
        $errorMsg = 'File upload failed. Error code: ' . $errorCode;
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload'
        ];
        if (isset($uploadErrors[$errorCode])) {
            $errorMsg .= ' – ' . $uploadErrors[$errorCode];
        }
        throw new Exception($errorMsg);
    }

    $file = $_FILES['file'];

    // ============================================================
    // 1) FILE SIZE — MAX 25 MB
    // ============================================================
    if ($file['size'] > MAX_FILE_SIZE_BYTES) {
        $sizeMB = number_format($file['size'] / 1048576, 2);
        throw new Exception("File size is {$sizeMB} MB. Maximum allowed is 25 MB per document.");
    }

    if ($file['size'] === 0) {
        throw new Exception('The uploaded file is empty.');
    }

    // ============================================================
    // 2) EXTENSION CHECK
    // ============================================================
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXTENSIONS, true)) {
        throw new Exception(
            "File type .{$ext} is not allowed. " .
            "Only PDF, JPG, JPEG, and PNG files are accepted. " .
            "Videos and audio files are not permitted."
        );
    }

    // ============================================================
    // 3) REAL MIME TYPE CHECK (via finfo — catches renamed videos)
    // ============================================================
    if (!function_exists('finfo_open')) {
        throw new Exception('Server misconfiguration: finfo extension is required.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);

    if ($realMime === false) {
        throw new Exception('Could not determine the file type.');
    }

    $realMime = strtolower($realMime);

    // 3a) Explicitly block video/audio
    if (strpos($realMime, 'video/') === 0) {
        throw new Exception('Video files are not allowed. Please upload a PDF or image.');
    }
    if (strpos($realMime, 'audio/') === 0) {
        throw new Exception('Audio files are not allowed. Please upload a PDF or image.');
    }

    // 3b) Must be in whitelist
    if (!in_array($realMime, $ALLOWED_MIME_TYPES, true)) {
        throw new Exception(
            "The file content ({$realMime}) does not match an allowed format. " .
            "Please upload a genuine PDF or image (JPG, JPEG, PNG)."
        );
    }

    // ============================================================
    // 4) IMAGE INTEGRITY CHECK (extra safety for JPG/PNG)
    // ============================================================
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $imgInfo = @getimagesize($file['tmp_name']);
        if ($imgInfo === false) {
            throw new Exception('The image file appears to be corrupted or invalid.');
        }

        // Verify the reported image type is also in our allowed list
        $allowedImageTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
        if (!in_array($imgInfo[2], $allowedImageTypes, true)) {
            throw new Exception('Only JPG, JPEG, and PNG images are accepted.');
        }
    }

    // ============================================================
    // 5) PDF HEADER CHECK (extra safety for PDF)
    // ============================================================
    if ($ext === 'pdf') {
        $handle = @fopen($file['tmp_name'], 'rb');
        if ($handle) {
            $header = fread($handle, 5);
            fclose($handle);
            if (strpos($header, '%PDF-') !== 0) {
                throw new Exception('The PDF file appears to be corrupted or invalid.');
            }
        }
    }

    // ============================================================
    // FIX: Ensure document_type column can accept new values
    // ============================================================
    global $conn;
    if (!$conn) {
        throw new Exception('Database connection not available');
    }

    $colInfo = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                              WHERE TABLE_NAME='document_uploads' AND COLUMN_NAME='document_type'");
    if ($colInfo && $row = $colInfo->fetch_assoc()) {
        $colType = $row['COLUMN_TYPE'];
        $isEnum = strpos($colType, 'enum') !== false;
        $isVarchar = strpos($colType, 'varchar') !== false || strpos($colType, 'char') !== false;

        if ($isEnum) {
            preg_match("/^enum\((.*)\)$/", $colType, $matches);
            if (isset($matches[1])) {
                $values = array_map(function($v) { return trim($v, "'"); }, explode(',', $matches[1]));
                $newValues = $values;
                foreach ($allowed as $val) {
                    if (!in_array($val, $newValues)) {
                        $newValues[] = $val;
                    }
                }
                if (count($newValues) > count($values)) {
                    $newEnum = "enum('" . implode("','", $newValues) . "')";
                    $alterSQL = "ALTER TABLE document_uploads MODIFY COLUMN document_type $newEnum";
                    $conn->query($alterSQL);
                    if ($conn->error) {
                        throw new Exception('Failed to alter document_type column: ' . $conn->error);
                    }
                }
            }
        } elseif (!$isVarchar) {
            $alterSQL = "ALTER TABLE document_uploads MODIFY COLUMN document_type VARCHAR(50)";
            $conn->query($alterSQL);
            if ($conn->error) {
                throw new Exception('Failed to alter document_type column: ' . $conn->error);
            }
        } elseif ($isVarchar) {
            preg_match("/varchar\((\d+)\)/i", $colType, $matches);
            if (isset($matches[1]) && intval($matches[1]) < 50) {
                $alterSQL = "ALTER TABLE document_uploads MODIFY COLUMN document_type VARCHAR(50)";
                $conn->query($alterSQL);
                if ($conn->error) {
                    throw new Exception('Failed to alter document_type column length: ' . $conn->error);
                }
            }
        }
    }

    // ============================================================
    // SAVE FILE
    // ============================================================
    $result = saveUploadedFile($_FILES['file'], $user_id, $app_id, $doc_type);
    if (isset($result['error'])) {
        throw new Exception($result['error']);
    }

    // ============================================================
    // INSERT RECORD
    // ============================================================
    $sql = "INSERT INTO document_uploads (application_id, document_type, file_path, original_name) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . $conn->error);
    }
    $stmt->bind_param("isss", $app_id, $doc_type, $result['path'], $result['original_name']);
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'File uploaded successfully',
            'file' => [
                'name' => $result['original_name'],
                'size' => $file['size'],
                'size_mb' => round($file['size'] / 1048576, 2),
                'type' => $realMime
            ]
        ]);
    } else {
        throw new Exception('Database execute error: ' . $stmt->error);
    }

} catch (Exception $e) {
    error_log('Upload error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>