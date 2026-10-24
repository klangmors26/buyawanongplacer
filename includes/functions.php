<?php
require_once __DIR__ . '/../config/connect.php';

// ============================================================
// Get or create draft application
// ============================================================
function getOrCreateDraftApplication($user_id) {
    global $conn;
    
    // Check for existing draft
    $stmt = $conn->prepare("SELECT * FROM business_applications WHERE user_id = ? AND status = 'draft' LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return $row;
    }
    $stmt->close();
    
    // Create new draft
    $stmt = $conn->prepare("INSERT INTO business_applications (user_id, status, current_step, created_at) VALUES (?, 'draft', 1, NOW())");
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        die('Failed to create draft: ' . $stmt->error);
    }
    $id = $conn->insert_id;
    $stmt->close();
    
    return ['id' => $id, 'status' => 'draft', 'current_step' => 1];
}

/**
 * Generate a sequential reference number for the current month.
 * Format: YYYY-MM-NNN (e.g., 2026-08-614)
 * The number resets every month.
 *
 * @param mysqli $conn Database connection
 * @return string Reference number
 */
function generateReferenceNumber($conn) {
    $year = date('Y');
    $month = date('m');
    $prefix = $year . '-' . $month . '-';
    
    // Query the highest numeric suffix from business_applications.reference_no
    $sql = "SELECT MAX(CAST(SUBSTRING(reference_no, LENGTH(?) + 1) AS UNSIGNED)) as last_num
            FROM business_applications
            WHERE reference_no LIKE ?
              AND status != 'draft'";
    $like = $prefix . '%';
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ss", $prefix, $like);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $last = $row['last_num'] ?? 0;
            $next = $last + 1;
            $suffix = str_pad($next, 3, '0', STR_PAD_LEFT);
            return $prefix . $suffix;
        }
    }
    // Fallback: use a unique BP number
    return 'BP-' . date('Y') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
}

// ============================================================
// Update application step
// ============================================================
function updateApplicationStep($app_id, $step) {
    global $conn;
    $sql = "UPDATE business_applications SET current_step = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $step, $app_id);
    return $stmt->execute();
}

// ============================================================
// Save application type
// ============================================================
function saveApplicationType($app_id, $data) {
    global $conn;
    $del = "DELETE FROM application_type_data WHERE application_id = ?";
    $stmt = $conn->prepare($del);
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $sql = "INSERT INTO application_type_data (application_id, application_type, payment_mode, registration_no, tin_no, business_type, tax_incentive) VALUES (?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssss", $app_id, $data['application_type'], $data['payment_mode'], $data['registration_no'], $data['tin_no'], $data['business_type'], $data['tax_incentive']);
    return $stmt->execute();
}

// ============================================================
// Save business info
// ============================================================
function saveBusinessInfo($app_id, $data, $activities) {
    global $conn;
    $del = "DELETE FROM business_info_data WHERE application_id = ?";
    $stmt = $conn->prepare($del);
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $activities_json = json_encode($activities);
    $sql = "INSERT INTO business_info_data (application_id, last_name, first_name, middle_name, business_name, trade_name, business_activities) VALUES (?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssss", $app_id, $data['last_name'], $data['first_name'], $data['middle_name'], $data['business_name'], $data['trade_name'], $activities_json);
    return $stmt->execute();
}

// ============================================================
// Save business address
// ============================================================
function saveBusinessAddress($app_id, $data) {
    global $conn;
    $del = "DELETE FROM business_address_data WHERE application_id = ?";
    $stmt = $conn->prepare($del);
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $sql = "INSERT INTO business_address_data (application_id, business_address, postal_code, email, telephone, mobile, business_area, total_employees, employees_residing, lessor_name, monthly_rental, lessor_address, lessor_phone, lessor_email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssssiisssss", $app_id, $data['business_address'], $data['postal_code'], $data['email'], $data['telephone'], $data['mobile'], $data['business_area'], $data['total_employees'], $data['employees_residing'], $data['lessor_name'], $data['monthly_rental'], $data['lessor_address'], $data['lessor_phone'], $data['lessor_email']);
    return $stmt->execute();
}

// ============================================================
// Save owner info
// ============================================================
function saveOwnerInfo($app_id, $data) {
    global $conn;
    $del = "DELETE FROM owner_info_data WHERE application_id = ?";
    $stmt = $conn->prepare($del);
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $sql = "INSERT INTO owner_info_data (application_id, last_name, first_name, middle_name, owner_address, postal_code, email, telephone, mobile, emergency_name, emergency_phone, emergency_email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssssssssss", $app_id, $data['last_name'], $data['first_name'], $data['middle_name'], $data['owner_address'], $data['postal_code'], $data['email'], $data['telephone'], $data['mobile'], $data['emergency_name'], $data['emergency_phone'], $data['emergency_email']);
    return $stmt->execute();
}

// ============================================================
// Save additional info
// ============================================================
function saveAdditionalInfo($app_id, $data) {
    global $conn;
    $del = "DELETE FROM additional_info_data WHERE application_id = ?";
    $stmt = $conn->prepare($del);
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $sql = "INSERT INTO additional_info_data (application_id, application_no_applicant, application_date, owner_fullname, business_name, floor_area, contact_no, establishment_address, position_title, declaration_agreed) VALUES (?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssdsssi", $app_id, $data['application_no'], $data['application_date'], $data['owner_fullname'], $data['business_name'], $data['floor_area'], $data['contact_no'], $data['establishment_address'], $data['position_title'], $data['declaration_agreed']);
    return $stmt->execute();
}

// ============================================================
// UPLOAD DIRECTORY HELPERS — HARDENED
// ============================================================

/**
 * Returns the current PHP process user (for diagnostics).
 */
function _currentPhpUser() {
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $u = @posix_getpwuid(posix_geteuid());
        if (is_array($u) && !empty($u['name'])) return $u['name'];
    }
    if (function_exists('get_current_user')) {
        $u = @get_current_user();
        if ($u) return $u;
    }
    return 'unknown';
}

/**
 * Tries to create a directory, falling back through progressively
 * looser permissions. Returns true on success.
 */
function _ensureDir($path) {
    if (is_dir($path)) {
        if (is_writable($path)) return true;
        @chmod($path, 0755);
        if (is_writable($path)) return true;
        @chmod($path, 0775);
        if (is_writable($path)) return true;
        @chmod($path, 0777);
        return is_writable($path);
    }

    foreach ([0755, 0775, 0777] as $mode) {
        error_clear_last();
        if (@mkdir($path, $mode, true)) {
            @chmod($path, $mode);
            if (is_writable($path)) return true;
        }
    }
    return false;
}

/**
 * Finds (and creates if needed) the uploads/ base folder.
 * Tries several candidate locations so it works whether this
 * file is in /includes/ or at the project root.
 *
 * @return string|false  Absolute path WITHOUT trailing slash, or false.
 */
function getUploadBaseDir() {
    // functions.php is expected to be in /includes/.
    // dirname(__DIR__)  => project root
    $projectRoot = realpath(dirname(__DIR__));
    if ($projectRoot === false) {
        // Fallback if realpath fails
        $projectRoot = dirname(__DIR__);
    }

    $candidates = [
        $projectRoot . DIRECTORY_SEPARATOR . 'uploads',
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads',   // if this file is at root
    ];

    $tried = [];
    foreach ($candidates as $path) {
        $tried[] = $path;
        if (is_dir($path) && is_writable($path)) {
            return rtrim($path, '/\\');
        }
    }
    // Try creating each candidate
    foreach ($candidates as $path) {
        $tried[] = $path . ' (create)';
        if (_ensureDir($path)) {
            return rtrim($path, '/\\');
        }
    }

    // Log full diagnostic so admin can see exactly what happened
    $err = error_get_last();
    error_log(
        "getUploadBaseDir FAILED. PHP user: " . _currentPhpUser() .
        " | Project root: {$projectRoot}" .
        " | Project root writable: " . (is_writable($projectRoot) ? 'yes' : 'no') .
        " | open_basedir: " . (ini_get('open_basedir') ?: '(none)') .
        " | Tried: " . implode(' | ', $tried) .
        " | Last error: " . ($err['message'] ?? 'none')
    );

    return false;
}

/**
 * Detailed reason the upload dir could not be prepared.
 * Used to build a helpful error message for the user.
 */
function getUploadDirDiagnostics() {
    $projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $baseCandidate = $projectRoot . DIRECTORY_SEPARATOR . 'uploads';

    $lines = [];
    $lines[] = "PHP user: " . _currentPhpUser();
    $lines[] = "Project root: {$projectRoot}";
    $lines[] = "Project root writable: " . (is_writable($projectRoot) ? 'yes' : 'no');
    $lines[] = "uploads path tried: {$baseCandidate}";
    $lines[] = "uploads exists: " . (is_dir($baseCandidate) ? 'yes' : 'no');
    if (is_dir($baseCandidate)) {
        $lines[] = "uploads writable: " . (is_writable($baseCandidate) ? 'yes' : 'no');
    }
    $ob = ini_get('open_basedir');
    if ($ob) $lines[] = "open_basedir: {$ob}";

    return implode(' | ', $lines);
}

/**
 * Get the sanitized folder name from user's first and last name.
 * Falls back to `user_{id}` if name is missing.
 *
 * @param int $user_id
 * @return string
 */
function getUserFolderName($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT firstname, lastname FROM accounts WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && !empty($user['firstname']) && !empty($user['lastname'])) {
        $first = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $user['firstname']));
        $last  = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $user['lastname']));
        $folder = $first . '_' . $last;
        if (strlen($folder) > 1) {
            return $folder;
        }
    }
    return 'user_' . $user_id;
}

/**
 * Get the full upload directory for a specific user + application.
 * Creates: uploads/{first_last}/app_{app_id}/
 *
 * @return string|false  Absolute path WITH trailing slash, or false.
 */
function getUserUploadDir($user_id, $app_id) {
    $baseDir = getUploadBaseDir();
    if ($baseDir === false) {
        return false;
    }

    $folderName = getUserFolderName($user_id);
    $userDir    = $baseDir . DIRECTORY_SEPARATOR . $folderName;

    if (!_ensureDir($userDir)) {
        $err = error_get_last();
        error_log("getUserUploadDir: cannot create user dir {$userDir}: " . ($err['message'] ?? 'unknown'));
        return false;
    }

    $appDir = $userDir . DIRECTORY_SEPARATOR . 'app_' . (int)$app_id;

    if (!_ensureDir($appDir)) {
        $err = error_get_last();
        error_log("getUserUploadDir: cannot create app dir {$appDir}: " . ($err['message'] ?? 'unknown'));
        return false;
    }

    return rtrim($appDir, '/\\') . DIRECTORY_SEPARATOR;
}

/**
 * Save an uploaded file with strict validation.
 *  - Allowed: PDF, JPG, JPEG, PNG
 *  - Max size: 25 MB
 *  - Verifies real MIME type (blocks renamed videos/audio)
 *
 * @param array  $file         $_FILES['file']
 * @param int    $user_id
 * @param int    $app_id
 * @param string $document_type
 * @return array  ['success' => true, 'path' => ..., 'original_name' => ...]
 *                or ['error' => 'message']
 */
function saveUploadedFile($file, $user_id, $app_id, $document_type) {

    // ============================================================
    // 1) UPLOAD ERROR CHECK
    // ============================================================
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $code = $file['error'] ?? 'unknown';
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload'
        ];
        return ['error' => 'Upload failed: ' . ($map[$code] ?? "code $code")];
    }

    // ============================================================
    // 2) SIZE — MAX 25 MB
    // ============================================================
    $maxBytes = 25 * 1024 * 1024; // 25 MB
    if ($file['size'] <= 0) {
        return ['error' => 'The uploaded file is empty.'];
    }
    if ($file['size'] > $maxBytes) {
        $sizeMB = number_format($file['size'] / 1048576, 2);
        return ['error' => "File size is {$sizeMB} MB. Maximum allowed is 25 MB."];
    }

    // ============================================================
    // 3) EXTENSION CHECK
    // ============================================================
    $originalName = basename($file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowedExt, true)) {
        return [
            'error' => "File type .{$extension} is not allowed. " .
                       "Only PDF, JPG, JPEG, and PNG are accepted. " .
                       "Videos and audio are not permitted."
        ];
    }

    // ============================================================
    // 4) REAL MIME CHECK (catches renamed video/audio)
    // ============================================================
    $allowedMime = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    $realMime = '';

    if (function_exists('finfo_open')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = @$finfo->file($file['tmp_name']) ?: '';
    } elseif (function_exists('mime_content_type')) {
        $realMime = @mime_content_type($file['tmp_name']) ?: '';
    }

    if ($realMime) {
        $realMime = strtolower($realMime);
        if (strpos($realMime, 'video/') === 0) {
            return ['error' => 'Video files are not allowed.'];
        }
        if (strpos($realMime, 'audio/') === 0) {
            return ['error' => 'Audio files are not allowed.'];
        }
        if (!in_array($realMime, $allowedMime, true)) {
            return ['error' => "The file content ({$realMime}) is not an accepted format."];
        }
    }

    // ============================================================
    // 5) EXTRA INTEGRITY CHECKS
    // ============================================================
    if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        if (@getimagesize($file['tmp_name']) === false) {
            return ['error' => 'The image file appears to be corrupted.'];
        }
    }
    if ($extension === 'pdf') {
        $h = @fopen($file['tmp_name'], 'rb');
        if ($h) {
            $header = fread($h, 5);
            fclose($h);
            if (strpos($header, '%PDF-') !== 0) {
                return ['error' => 'The PDF file is invalid or corrupted.'];
            }
        }
    }

    // ============================================================
    // 6) GET TARGET DIRECTORY
    // ============================================================
    $targetDir = getUserUploadDir($user_id, $app_id);
    if ($targetDir === false) {
        return [
            'error' =>
                'Could not create upload directory. ' .
                'Please create a folder named "uploads" in your project root and ' .
                'grant write access to the web server user. ' .
                'Details → ' . getUploadDirDiagnostics()
        ];
    }

    // ============================================================
    // 7) SAFE FILENAME + MOVE
    // ============================================================
    $safeDocType  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $document_type);
    $safeOriginal = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $originalName);

    // random_bytes may fail on exotic setups — fall back to uniqid
    try {
        $uniqueSuffix = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $uniqueSuffix = substr(md5(uniqid('', true)), 0, 8);
    }

    $safeName = $safeDocType . '_' . time() . '_' . $uniqueSuffix . '_' . $safeOriginal;
    $targetPath = $targetDir . $safeName;

    // is_uploaded_file() fails in CLI / test contexts — fall back to file check
    if (!is_uploaded_file($file['tmp_name'])) {
        if (!is_file($file['tmp_name'])) {
            return ['error' => 'Invalid upload source.'];
        }
    }

    $moved = @move_uploaded_file($file['tmp_name'], $targetPath);
    if (!$moved) {
        // Fallback for environments where move_uploaded_file is disabled
        $moved = @rename($file['tmp_name'], $targetPath);
    }
    if (!$moved) {
        $err = error_get_last();
        return ['error' => 'Failed to save file. ' . ($err['message'] ?? 'unknown error')];
    }

    @chmod($targetPath, 0644);

    // ============================================================
    // 8) BUILD RELATIVE PATH FOR DATABASE (always forward slashes)
    // ============================================================
    $folderName = getUserFolderName($user_id);
    $relativePath = 'uploads/' . $folderName . '/app_' . (int)$app_id . '/' . $safeName;

    return [
        'success' => true,
        'path' => $relativePath,
        'original_name' => $originalName
    ];
}

// ============================================================
// Get uploaded docs status
// ============================================================
function getUploadedDocsStatus($app_id) {
    global $conn;
    $sql = "SELECT document_type, original_name FROM document_uploads WHERE application_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $docs = [];
    while ($row = $result->fetch_assoc()) {
        $docs[$row['document_type']] = $row['original_name'];
    }
    $stmt->close();
    return $docs;
}

// ============================================================
// Generate QR code
// ============================================================
function generateQRCode($reference_no, $output_path) {
    // Requires phpqrcode library – download from https://sourceforge.net/projects/phpqrcode/
    require_once __DIR__ . '/phpqrcode.php';
    QRcode::png($reference_no, $output_path, QR_ECLEVEL_L, 10);
    return $output_path;
}

// ============================================================
// Fees
// ============================================================
function calculateTotalFees() {
    return 1485.00; // as per sample assessment
}

// ============================================================
// Get user name
// ============================================================
function getUserName($user_id) {
    global $conn;
    $sql = "SELECT firstname, lastname FROM accounts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? ($row['firstname'] . ' ' . $row['lastname']) : '';
}

// ============================================================
// Get checklist data
// ============================================================
function getChecklistData($app_id) {
    global $conn;
    $sql = "SELECT checklist_data FROM business_applications WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if ($row && $row['checklist_data']) {
        return json_decode($row['checklist_data'], true);
    }
    return [];
}

// ============================================================
// Check which step is first incomplete (2–7)
// ============================================================
function getMissingStep($app_id, $conn) {
    // Define mandatory fields for each step
    $stepChecks = [
        2 => ['application_type', 'payment_mode'],
        3 => ['business_name'],
        4 => ['business_address'],
        5 => ['first_name', 'last_name'],
        6 => ['contact_no', 'owner_fullname'],
        7 => 'documents'
    ];

    // Step 2
    $typeData = $conn->query("SELECT * FROM application_type_data WHERE application_id = $app_id")->fetch_assoc();
    if (!$typeData) return 2;
    foreach ($stepChecks[2] as $field) {
        if (empty($typeData[$field])) return 2;
    }

    // Step 3
    $businessInfo = $conn->query("SELECT * FROM business_info_data WHERE application_id = $app_id")->fetch_assoc();
    if (!$businessInfo) return 3;
    foreach ($stepChecks[3] as $field) {
        if (empty($businessInfo[$field])) return 3;
    }

    // Step 4
    $addressData = $conn->query("SELECT * FROM business_address_data WHERE application_id = $app_id")->fetch_assoc();
    if (!$addressData) return 4;
    foreach ($stepChecks[4] as $field) {
        if (empty($addressData[$field])) return 4;
    }

    // Step 5
    $ownerData = $conn->query("SELECT * FROM owner_info_data WHERE application_id = $app_id")->fetch_assoc();
    if (!$ownerData) return 5;
    foreach ($stepChecks[5] as $field) {
        if (empty($ownerData[$field])) return 5;
    }

    // Step 6
    $additionalData = $conn->query("SELECT * FROM additional_info_data WHERE application_id = $app_id")->fetch_assoc();
    if (!$additionalData) return 6;
    foreach ($stepChecks[6] as $field) {
        if (empty($additionalData[$field])) return 6;
    }

    // Step 7 – at least one document must be uploaded
    $docCount = $conn->query("SELECT COUNT(*) as cnt FROM document_uploads WHERE application_id = $app_id")->fetch_assoc()['cnt'];
    if ($docCount < 1) return 7;

    return 0; // all steps complete
}

// ============================================================
// Copy application data (for renewal)
// ============================================================
function copyApplicationData($from_id, $to_id, $conn) {
    $tables = [
        'application_type_data',
        'business_info_data',
        'business_address_data',
        'owner_info_data',
        'additional_info_data'
    ];

    foreach ($tables as $table) {
        // Delete any existing data for the target application
        $del = $conn->prepare("DELETE FROM $table WHERE application_id = ?");
        if ($del) {
            $del->bind_param("i", $to_id);
            $del->execute();
            $del->close();
        }

        // Fetch source data
        $result = $conn->query("SELECT * FROM $table WHERE application_id = $from_id");
        if (!$result) continue;
        $from = $result->fetch_assoc();
        if (!$from) continue;

        // Remove primary key and foreign key
        unset($from['id']);
        unset($from['application_id']);

        $cols = array_keys($from);
        $vals = array_values($from);
        if (empty($cols)) continue;

        $placeholders = implode(',', array_fill(0, count($vals), '?'));
        $sql = "INSERT INTO $table (application_id, " . implode(',', $cols) . ") VALUES (?, $placeholders)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("copyApplicationData: Prepare failed for $table: " . $conn->error);
            continue;
        }

        // Build bind_param arguments with references
        $types = 'i' . str_repeat('s', count($vals));
        $params = array_merge([$types], [$to_id], $vals);
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[] = &$value;
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        if (!$stmt->execute()) {
            error_log("copyApplicationData: Execute failed for $table: " . $stmt->error);
            $stmt->close();
            return false;
        }
        $stmt->close();
    }
    return true;
}

// ============================================================
// Assign reference number on submission
// ============================================================
function assignReferenceNumber($app_id, $conn) {
    // Fetch application details
    $stmt = $conn->prepare("SELECT renewed_from_id FROM business_applications WHERE id = ?");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $app = $result->fetch_assoc();
    $stmt->close();

    if (!$app) {
        throw new Exception("Application not found (ID: $app_id)");
    }

    $reference_no = null;

    if (!empty($app['renewed_from_id'])) {
        // ---- RENEWAL ----
        $orig_id = $app['renewed_from_id'];

        $origStmt = $conn->prepare("SELECT reference_no FROM business_applications WHERE id = ?");
        $origStmt->bind_param("i", $orig_id);
        $origStmt->execute();
        $origResult = $origStmt->get_result();
        $orig = $origResult->fetch_assoc();
        $origStmt->close();

        if (!$orig || empty($orig['reference_no'])) {
            $reference_no = generateReferenceNumber($conn);
        } else {
            $reference_no = $orig['reference_no'];

            // Archive the original permit
            $newOrigRef = $reference_no . '-OLD';
            $updateOrig = $conn->prepare("UPDATE business_applications SET reference_no = ?, status = 'renewed' WHERE id = ?");
            $updateOrig->bind_param("si", $newOrigRef, $orig_id);
            if (!$updateOrig->execute()) {
                throw new Exception("Failed to archive original permit: " . $updateOrig->error);
            }
            $updateOrig->close();
        }
    } else {
        // ---- NEW APPLICATION ----
        $reference_no = generateReferenceNumber($conn);
    }

    // Update the current application with the reference number
    if ($reference_no) {
        $updateApp = $conn->prepare("UPDATE business_applications SET reference_no = ? WHERE id = ?");
        $updateApp->bind_param("si", $reference_no, $app_id);
        if (!$updateApp->execute()) {
            throw new Exception("Failed to set reference number: " . $updateApp->error);
        }
        $updateApp->close();
    } else {
        throw new Exception("Reference number could not be generated.");
    }

    return $reference_no;
}

?>