<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$date = $data['date'] ?? '';
$time = $data['time'] ?? '';

if (!$date || !$time) {
    echo json_encode(['error' => 'Date and time required']);
    exit;
}

require_once 'config/connect.php';

// ------------------------------------------------------------------
// 1. SERVER-SIDE VALIDATION (keep in sync with frontend)
// ------------------------------------------------------------------

// Date must be tomorrow or later
$today = new DateTime('today');
$apptDate = DateTime::createFromFormat('Y-m-d', $date);
if (!$apptDate || $apptDate <= $today) {
    echo json_encode(['error' => 'Appointment date must be at least one day in advance.']);
    exit;
}

// No weekends
$dayOfWeek = (int)$apptDate->format('N'); // 1=Mon … 7=Sun
if ($dayOfWeek >= 6) {
    echo json_encode(['error' => 'Appointments are not available on weekends.']);
    exit;
}

// Philippine Holidays 2026 (copy from frontend)
$holidays2026 = [
    '2026-01-01', '2026-04-09', '2026-05-01', '2026-06-12', '2026-08-21',
    '2026-08-31', '2026-11-30', '2026-12-25', '2026-12-30',
    '2026-04-01', '2026-04-02', '2026-04-03',
    '2026-02-25', '2026-04-10', '2026-11-02', '2026-12-24', '2026-12-31'
];
if (in_array($date, $holidays2026)) {
    echo json_encode(['error' => 'Selected date is a public holiday.']);
    exit;
}

// Validate time slot (8:00 AM – 5:00 PM, half‑hour, excluding 12:00–1:00 PM)
$allowedTimes = [];
for ($minutes = 8*60; $minutes <= 17*60; $minutes += 30) {
    if ($minutes >= 12*60 && $minutes < 13*60) continue;
    $hour = floor($minutes / 60);
    $min = $minutes % 60;
    $ampm = $hour >= 12 ? 'PM' : 'AM';
    $h12 = $hour % 12 ?: 12;
    $allowedTimes[] = sprintf('%d:%02d %s', $h12, $min, $ampm);
}
if (!in_array($time, $allowedTimes)) {
    echo json_encode(['error' => 'Invalid time slot.']);
    exit;
}

// ------------------------------------------------------------------
// 2. Get current draft application
// ------------------------------------------------------------------
$appQuery = "SELECT id FROM business_applications WHERE user_id = ? AND status = 'draft' ORDER BY created_at DESC LIMIT 1";
$stmt = $conn->prepare($appQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$application = $result->fetch_assoc();

if (!$application) {
    echo json_encode(['error' => 'No draft application found. Please start a new application.']);
    exit;
}
$application_id = $application['id'];

// ------------------------------------------------------------------
// 3. Race‑condition safe booking using a transaction
// ------------------------------------------------------------------
$conn->begin_transaction();

try {
    // Lock the row(s) for the selected date/time to prevent concurrent bookings
    // This also locks the gap so no other transaction can insert the same slot.
    $lockStmt = $conn->prepare("
        SELECT id, application_id 
        FROM appointments 
        WHERE appointment_date = ? AND appointment_time = ? 
        FOR UPDATE
    ");
    $lockStmt->bind_param('ss', $date, $time);
    $lockStmt->execute();
    $lockResult = $lockStmt->get_result();

    $existing = $lockResult->fetch_assoc();

    if ($existing) {
        // A row with this exact date/time exists
        if ($existing['application_id'] != $application_id) {
            // Booked by another application – reject
            throw new Exception('This time slot is already booked by another applicant.');
        }
        // It's the same application – we will update the existing row below.
    }

    // Now insert or update – we rely on a unique key on `application_id`
    // (so each application has only one appointment).
    $upsertStmt = $conn->prepare("
        INSERT INTO appointments (user_id, application_id, appointment_date, appointment_time, status, created_at) 
        VALUES (?, ?, ?, ?, 'confirmed', NOW())
        ON DUPLICATE KEY UPDATE 
            appointment_date = VALUES(appointment_date),
            appointment_time = VALUES(appointment_time),
            status = 'confirmed',
            updated_at = NOW()
    ");
    $upsertStmt->bind_param('iiss', $user_id, $application_id, $date, $time);
    if (!$upsertStmt->execute()) {
        throw new Exception('Database error while saving appointment.');
    }

    // Commit
    $conn->commit();

    // Update session
    $_SESSION['appointment_confirmed'] = true;
    $_SESSION['appointment_date'] = $date;
    $_SESSION['appointment_time'] = $time;

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    // Log the real error for debugging (do not expose to user)
    error_log('save_appointment error: ' . $e->getMessage());
    echo json_encode(['error' => 'Unable to book appointment. Please try again later.']);
}
?>