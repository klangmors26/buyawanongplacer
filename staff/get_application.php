<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/connect.php';

$ref = isset($_GET['ref']) ? trim($_GET['ref']) : '';
if (empty($ref)) {
    echo json_encode(['success' => false, 'message' => 'Reference number required']);
    exit;
}

// Fetch application with business info
$stmt = $conn->prepare("
    SELECT 
        ba.id,
        ba.reference_no,
        ba.status,
        ba.application_date,
        ba.submitted_at,
        ba.created_at,
        bi.business_name,
        at.application_type,
        fa.total_amount AS amount,
        CONCAT(u.firstname, ' ', u.lastname) AS owner_name
    FROM business_applications ba
    LEFT JOIN business_info_data bi ON ba.id = bi.application_id
    LEFT JOIN application_type_data at ON ba.id = at.application_id
    LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
    LEFT JOIN accounts u ON ba.user_id = u.id
    WHERE ba.reference_no = ?
");
$stmt->bind_param("s", $ref);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$app) {
    echo json_encode(['success' => false, 'message' => 'Application not found']);
    exit;
}

// Fetch status history if table exists (optional, but we'll build our own timeline)
$history = [];
// We'll build a clean timeline without "Approved/Issued" or "Rejected" steps

// Determine steps based on current status
$status = strtolower($app['status']);
$submitted_date = $app['submitted_at'] ? date('M d, Y — g:i A', strtotime($app['submitted_at'])) : 'Pending';

// Build timeline with only these steps: Submitted, Docs verified, Payment confirmed, Under review, Verified (or Cancelled)
$timeline = [];

// Step 1: Application submitted
$timeline[] = [
    'step' => 'Application submitted',
    'date' => $submitted_date,
    'completed' => true
];

// Step 2: Documents verified
$docs_verified = !in_array($status, ['submitted']);
$timeline[] = [
    'step' => 'Documents verified',
    'date' => $docs_verified ? 'Completed' : 'Pending',
    'completed' => $docs_verified
];

// Step 3: Payment confirmed
$payment_confirmed = in_array($status, ['payment_confirmed', 'under_review', 'approved', 'issued', 'verified']);
$timeline[] = [
    'step' => 'Payment confirmed',
    'date' => $payment_confirmed ? 'Completed' : 'Pending',
    'completed' => $payment_confirmed
];

// Step 4: Under review
$under_review = in_array($status, ['under_review', 'approved', 'issued', 'verified']);
$timeline[] = [
    'step' => 'Under review',
    'date' => $under_review ? 'In progress' : 'Pending',
    'completed' => $under_review
];

// Step 5: Final status - Verified, Cancelled, or Rejected (but we won't show Rejected as a step)
if ($status === 'verified' || $status === 'approved' || $status === 'issued') {
    $timeline[] = [
        'step' => '✅ Verified',
        'date' => 'Completed',
        'completed' => true,
        'is_verified' => true
    ];
} elseif ($status === 'cancelled') {
    $timeline[] = [
        'step' => '❌ Cancelled',
        'date' => 'Completed',
        'completed' => true,
        'is_cancelled' => true
    ];
} elseif ($status === 'rejected') {
    // We do NOT add a "Rejected" step – the timeline will end at "Under review"
    // The status badge will show "Rejected" at the top.
    // Optionally, we could add a final step "Rejected" but the user asked not to display it.
    // We'll keep it out.
    // If you want to show a "Rejected" step, uncomment the next lines:
    /*
    $timeline[] = [
        'step' => '❌ Rejected',
        'date' => 'Completed',
        'completed' => true,
        'is_rejected' => true
    ];
    */
} else {
    $timeline[] = [
        'step' => 'Permit issuance pending',
        'date' => 'Pending',
        'completed' => false
    ];
}

echo json_encode([
    'success' => true,
    'application' => $app,
    'history' => $history,
    'timeline' => $timeline
]);
?>