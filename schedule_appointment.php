<?php
// ============================================================
// schedule_appointment.php
// ============================================================
// Only allows scheduling when the user has at least one
// application with status = 'verified' (permit ready for pickup).
// ============================================================

session_start();
header('Content-Type: application/json');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json', true, 500);
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Server error: ' . $err['message']
                       . ' in ' . basename($err['file']) . ':' . $err['line']
        ]);
    }
});

try {

    require_once __DIR__ . '/config/connect.php';

    // ── Auth ─────────────────────────────────────────────────
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];

    // ── Input ────────────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $date    = trim($input['date'] ?? '');
    $time    = trim($input['time'] ?? '');
    $purpose = trim($input['purpose'] ?? 'Claim Business Permit');
    if (strlen($purpose) > 100) $purpose = substr($purpose, 0, 100);

    if (!$date || !$time) {
        echo json_encode(['success' => false, 'error' => 'Missing date or time.']);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format (expected YYYY-MM-DD).']);
        exit;
    }
    if (strlen($time) > 20) {
        echo json_encode(['success' => false, 'error' => 'Invalid time value.']);
        exit;
    }

    // Reject weekends
    $dow = (int)date('N', strtotime($date));
    if ($dow >= 6) {
        echo json_encode(['success' => false, 'error' => 'Weekends are not available.']);
        exit;
    }

    // ============================================================
    // ✅ GATE: User must have at least one VERIFIED application
    // ============================================================
    $verifiedStmt = $conn->prepare("
        SELECT id FROM business_applications
        WHERE user_id = ? AND status = 'verified'
        LIMIT 1
    ");
    if (!$verifiedStmt) throw new Exception('Prepare (verified check) failed: ' . $conn->error);
    $verifiedStmt->bind_param('i', $user_id);
    if (!$verifiedStmt->execute()) throw new Exception('Execute (verified check) failed: ' . $verifiedStmt->error);
    if ($verifiedStmt->get_result()->num_rows === 0) {
        $verifiedStmt->close();
        echo json_encode([
            'success' => false,
            'error'   => 'You can only schedule an appointment once your permit is verified and ready for pickup.'
        ]);
        exit;
    }
    $verifiedStmt->close();

    // ============================================================
    // 1) Prevent more than one active appointment per user
    // ============================================================
    $check = $conn->prepare(
        "SELECT id FROM appointments
         WHERE user_id = ? AND status IN ('pending','confirmed')
         LIMIT 1"
    );
    if (!$check) throw new Exception('Prepare (active check) failed: ' . $conn->error);

    $check->bind_param('i', $user_id);
    if (!$check->execute()) throw new Exception('Execute (active check) failed: ' . $check->error);

    if ($check->get_result()->num_rows > 0) {
        $check->close();
        echo json_encode([
            'success' => false,
            'error'   => 'You already have an active appointment. Cancel or wait for the BPLO decision.'
        ]);
        exit;
    }
    $check->close();

    // ============================================================
    // 2) Slot availability
    // ============================================================
    $slot = $conn->prepare(
        "SELECT id FROM appointments
         WHERE appointment_date = ? AND appointment_time = ?
           AND status IN ('pending','confirmed')
         LIMIT 1"
    );
    if (!$slot) throw new Exception('Prepare (slot check) failed: ' . $conn->error);

    $slot->bind_param('ss', $date, $time);
    if (!$slot->execute()) throw new Exception('Execute (slot check) failed: ' . $slot->error);

    if ($slot->get_result()->num_rows > 0) {
        $slot->close();
        echo json_encode([
            'success' => false,
            'error'   => 'That time slot is already taken. Please choose another.'
        ]);
        exit;
    }
    $slot->close();

    // ============================================================
    // 3) Find the most recent verified application to back-link
    // ============================================================
    $app_id = null;
    $appStmt = $conn->prepare("
        SELECT id FROM business_applications
        WHERE user_id = ? AND status = 'verified'
        ORDER BY created_at DESC LIMIT 1
    ");
    if ($appStmt) {
        $appStmt->bind_param('i', $user_id);
        if ($appStmt->execute()) {
            $app = $appStmt->get_result()->fetch_assoc();
            if ($app) $app_id = (int)$app['id'];
        }
        $appStmt->close();
    }

    // ============================================================
    // 4) Insert appointment as PENDING
    // ============================================================
    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "INSERT INTO appointments
            (user_id, application_id, purpose, appointment_date, appointment_time, status, created_at)
         VALUES (?, ?, ?, ?, ?, 'pending', NOW())"
    );
    if (!$stmt) throw new Exception('Prepare (appointment insert) failed: ' . $conn->error);

    if ($app_id === null) {
        $nullVal = null;
        $stmt->bind_param('issss', $user_id, $nullVal, $purpose, $date, $time);
    } else {
        $stmt->bind_param('iisss', $user_id, $app_id, $purpose, $date, $time);
    }

    if (!$stmt->execute()) {
        $errMsg  = $stmt->error;
        $errCode = $stmt->errno;
        $stmt->close();
        $conn->rollback();

        if ($errCode === 1062 || stripos($errMsg, 'Duplicate') !== false) {
            echo json_encode([
                'success' => false,
                'error'   => 'That time slot was just booked by someone else. Please pick another.'
            ]);
            exit;
        }
        throw new Exception('Appointment insert failed: ' . $errMsg);
    }

    $apptId = (int)$conn->insert_id;
    $stmt->close();

    // ============================================================
    // 5) Notify the applicant
    // ============================================================
    $notifyStmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, link)
        VALUES (?, 'info', 'Pickup Appointment Submitted',
                'Your appointment to claim your verified permit is pending BPLO approval.',
                'dashboard.php')
    ");
    if ($notifyStmt) {
        $notifyStmt->bind_param('i', $user_id);
        $notifyStmt->execute();
        $notifyStmt->close();
    }

    $conn->commit();

    echo json_encode([
        'success'        => true,
        'appointment_id' => $apptId,
        'application_id' => $app_id,
        'message'        => 'Appointment submitted for BPLO approval.'
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        try { $conn->rollback(); } catch (Throwable $t) {}
    }
    if (!headers_sent()) {
        header('Content-Type: application/json', true, 500);
    }
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}