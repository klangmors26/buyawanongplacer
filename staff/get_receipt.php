<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 4) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

require_once '../config/connect.php';

$application_id = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;

if ($application_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
    exit;
}

// Fetch fee assessment
$feeAssessment = null;
$stmt = $conn->prepare("SELECT * FROM fee_assessments WHERE application_id = ?");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $feeAssessment = $row;
}
$stmt->close();

// Fetch payment record
$payment = null;
$stmt = $conn->prepare("SELECT * FROM payments WHERE application_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $payment = $row;
}
$stmt->close();

// Calculate totals
$totalLGU = 0;
if ($feeAssessment) {
    $feeColumns = ['gross_sales_tax', 'mayors_permit_fee', 'garbage_charges', 'sanitary_inspection_fee', 'building_inspection_fee', 'electrical_inspection_fee', 'others'];
    foreach ($feeColumns as $col) {
        $totalLGU += (float)($feeAssessment[$col] ?? 0);
    }
}
$fireSafety = $feeAssessment['fire_safety_fee_percent'] ?? ($totalLGU * 0.10);
$totalAmount = $feeAssessment['total_amount'] ?? ($totalLGU + $fireSafety);

$data = [
    'or_number' => 'OR-' . date('Y') . '-' . str_pad($application_id, 6, '0', STR_PAD_LEFT),
    'payment_method' => $payment['payment_method'] ?? 'Over-the-Counter',
    'paid_at' => $payment['paid_at'] ?? date('Y-m-d H:i:s'),
    'total_lgu' => number_format($totalLGU, 2),
    'fire_safety' => number_format($fireSafety, 2),
    'total_amount' => number_format($totalAmount, 2)
];

header('Content-Type: application/json');
echo json_encode(['success' => true, 'data' => $data]);
exit;
?>