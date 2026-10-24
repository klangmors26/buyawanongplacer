<?php
session_start();
// Allow user_type 3 and 4 only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
    header('Location: ../login.php');
    exit;
}

require_once '../config/connect.php';
require_once '../fpdf19/fpdf.php';

$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($application_id <= 0) die('Invalid application ID.');

$user_type = (int)$_SESSION['user_type'];

// Fetch all data
$app = [];
$stmt = $conn->prepare("SELECT * FROM business_applications WHERE id = ?");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) die('Application not found.');
$app = $result->fetch_assoc();
$stmt->close();

if ($user_type == 3 && !in_array(strtolower($app['status']), ['pending_payment', 'payment_confirmed'])) {
    die('Access denied. You can only view pending payment or payment confirmed applications.');
}

$business_info = [];
$stmt = $conn->prepare("SELECT * FROM business_info_data WHERE application_id = ?");
$stmt->bind_param("i", $application_id); $stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) $business_info = $row;
$stmt->close();

$owner_info = [];
$stmt = $conn->prepare("SELECT * FROM owner_info_data WHERE application_id = ?");
$stmt->bind_param("i", $application_id); $stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) $owner_info = $row;
$stmt->close();

$app_type = [];
$stmt = $conn->prepare("SELECT * FROM application_type_data WHERE application_id = ?");
$stmt->bind_param("i", $application_id); $stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) $app_type = $row;
$stmt->close();

$address_data = [];
$stmt = $conn->prepare("SELECT * FROM business_address_data WHERE application_id = ?");
$stmt->bind_param("i", $application_id); $stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) $address_data = $row;
$stmt->close();

$additional_info = [];
$stmt = $conn->prepare("SELECT * FROM additional_info_data WHERE application_id = ?");
$stmt->bind_param("i", $application_id); $stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) $additional_info = $row;
$stmt->close();

$assessment = null;
$stmt = $conn->prepare("SELECT * FROM fee_assessments WHERE application_id = ?");
$stmt->bind_param("i", $application_id); $stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) $assessment = $row;
$stmt->close();

$rejection = null;
$stmt = $conn->prepare("SELECT missing_documents, notes FROM application_rejections WHERE application_id = ?");
$stmt->bind_param("i", $application_id); $stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) $rejection = $row;
$stmt->close();

// ============================================================
// FETCH DOCUMENTS
// ============================================================
$documents = [];
if ($user_type == 3) {
    $docTypeFilter = "AND LOWER(document_type) IN ('brgy clearance', 'dti/sec', 'dti_sec', 'barangay clearance', 'barangay', 'engineering')";
    $docTypeLabel = 'Barangay Clearance, DTI/SEC & Engineering';
} else {
    $docTypeFilter = "";
    $docTypeLabel = 'All Documents';
}
$stmt = $conn->prepare("SELECT id, document_type, original_name, file_path, uploaded_at
                        FROM document_uploads
                        WHERE application_id = ? $docTypeFilter
                        ORDER BY uploaded_at DESC");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$docResult = $stmt->get_result();
while ($row = $docResult->fetch_assoc()) {
    $fullPath = __DIR__ . '/../' . $row['file_path'];
    $row['file_exists'] = file_exists($fullPath);
    $row['file_size'] = $row['file_exists'] ? filesize($fullPath) : 0;
    $documents[] = $row;
}
$stmt->close();

function val($data, $key, $default = '') { return isset($data[$key]) ? $data[$key] : $default; }
function fmtDate($date) { return $date ? date('M d, Y', strtotime($date)) : ''; }
function getFeeAmount($assessment, $fieldNames) {
    if (!$assessment) return 0;
    if (is_string($fieldNames)) $fieldNames = [$fieldNames];
    foreach ($fieldNames as $f) {
        if (isset($assessment[$f]) && $assessment[$f] !== null && $assessment[$f] !== '') return (float)$assessment[$f];
    }
    return 0;
}

$activities = [];
if (!empty($business_info['business_activities'])) {
    $decoded = json_decode($business_info['business_activities'], true);
    if (is_array($decoded)) {
        if (isset($decoded['line_of_business'])) $activities[] = $decoded;
        else $activities = $decoded;
    }
}

// ----- Build PDF -----
$pdf = new FPDF('P', 'mm', array(215.9, 330.2));
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();

// PAGE 1
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(0, 5, 'ANNEX 1 (Page 1 of 2)', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 13);
$pdf->Cell(0, 7, 'APPLICATION FORM FOR BUSINESS PERMIT', 0, 1, 'C');
$pdf->Ln(1);

$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(22, 5, 'TAX YEAR', 0, 0);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(35, 5, val($app, 'tax_year'), 'B', 0);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(35, 5, 'CITY/MUNICIPALITY', 0, 0);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 5, val($address_data, 'city', 'Placer'), 'B', 1);
$pdf->Ln(1);

$pdf->SetFont('Helvetica', 'B', 8);
$pdf->MultiCell(0, 4, "INSTRUCTIONS:\n1. Provide accurate information and print legibly to avoid delays. Incomplete application form will be returned to the applicant.\n2. Ensure that all documents attached to this form (if any) are complete and properly filled out.", 1, 'L');
$pdf->Ln(1);

$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 7, 'I. APPLICANT SECTION', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, '1. BASIC INFORMATION', 0, 1, 'L');

$pdf->SetFont('Helvetica', '', 8);
$chk_new = (val($app_type, 'application_type') == 'New') ? 'x' : '';
$chk_renew = (val($app_type, 'application_type') == 'Renewal') ? 'x' : '';
$mode = val($app_type, 'payment_mode');
$pdf->Cell(28, 5, 'Application Type:', 0, 0);
$pdf->Cell(14, 5, '[ '.$chk_new.' ] New', 0, 0);
$pdf->Cell(16, 5, '[ '.$chk_renew.' ] Renewal', 0, 0);
$pdf->Cell(28, 5, 'Mode of Payment:', 0, 0);
$pdf->Cell(18, 5, '[ '.($mode=='Annually'?'x':'').' ] Annually', 0, 0);
$pdf->Cell(22, 5, '[ '.($mode=='Semi-Annually'?'x':'').' ] Semi-Annually', 0, 0);
$pdf->Cell(0, 5, '[ '.($mode=='Quarterly'?'x':'').' ] Quarterly', 0, 1);

$pdf->Cell(32, 5, 'Date of Application:', 0, 0);
$pdf->Cell(50, 5, fmtDate(val($app, 'application_date')), 'B', 1);
$pdf->Ln(1);

$pdf->Cell(42, 5, 'DTI/SEC/CDA Registration No.:', 0, 0);
$pdf->Cell(50, 5, val($app_type, 'registration_no'), 'B', 0);
$pdf->Cell(18, 5, 'TIN No.:', 0, 0);
$pdf->Cell(50, 5, val($app_type, 'tin_no'), 'B', 1);

$pdf->Cell(32, 5, 'Type of Business:', 0, 0);
$biz_type = val($app_type, 'business_type');
$pdf->Cell(14, 5, '[ '.($biz_type=='Single'?'x':'').' ] Single', 0, 0);
$pdf->Cell(20, 5, '[ '.($biz_type=='Partnership'?'x':'').' ] Partnership', 0, 0);
$pdf->Cell(20, 5, '[ '.($biz_type=='Corporation'?'x':'').' ] Corporation', 0, 0);
$pdf->Cell(0, 5, '[ '.($biz_type=='Cooperative'?'x':'').' ] Cooperative', 0, 1);

$incentive = val($app_type, 'tax_incentive');
$pdf->Cell(65, 5, 'Enjoying tax incentive from any Gov. Entity?', 0, 0);
$pdf->Cell(14, 5, '[ '.($incentive=='Yes'?'x':'').' ] Yes', 0, 0);
$pdf->Cell(14, 5, '[ '.($incentive=='No'?'x':'').' ] No', 0, 0);
$pdf->Cell(18, 5, 'Specify:', 0, 0);
$pdf->Cell(0, 5, ($incentive=='Yes' ? val($app_type, 'tax_incentive_specify') : ''), 'B', 1);
$pdf->Ln(1);

$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, 'Name of Taxpayer / Registrant:', 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(22, 5, 'Last Name:', 0, 0);
$pdf->Cell(38, 5, val($owner_info, 'last_name'), 'B', 0);
$pdf->Cell(22, 5, 'First Name:', 0, 0);
$pdf->Cell(38, 5, val($owner_info, 'first_name'), 'B', 0);
$pdf->Cell(25, 5, 'Middle Name:', 0, 0);
$pdf->Cell(0, 5, val($owner_info, 'middle_name'), 'B', 1);
$pdf->Cell(28, 5, 'Business Name:', 0, 0);
$pdf->Cell(70, 5, val($business_info, 'business_name'), 'B', 0);
$pdf->Cell(35, 5, 'Trade name / Franchise:', 0, 0);
$pdf->Cell(0, 5, val($business_info, 'trade_name'), 'B', 1);
$pdf->Ln(1);

$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, '2. OTHER INFORMATION', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->Cell(0, 4, 'For renewal applications, do not fill up this section unless certain information have changed.', 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 8);

$pdf->Cell(28, 5, 'Business Address:', 0, 0);
$pdf->Cell(68, 5, val($address_data, 'business_address'), 'B', 0);
$pdf->Cell(20, 5, 'Postal Code:', 0, 0);
$pdf->Cell(18, 5, val($address_data, 'postal_code'), 'B', 0);
$pdf->Cell(10, 5, 'Email:', 0, 0);
$pdf->Cell(0, 5, val($address_data, 'email'), 'B', 1);

$pdf->Cell(18, 5, 'Tel. No.:', 0, 0);
$pdf->Cell(28, 5, val($address_data, 'telephone'), 'B', 0);
$pdf->Cell(18, 5, 'Mobile:', 0, 0);
$pdf->Cell(28, 5, val($address_data, 'mobile'), 'B', 0);
$pdf->Cell(30, 5, "Owner's Address:", 0, 0);
$pdf->Cell(0, 5, val($owner_info, 'owner_address'), 'B', 1);

$pdf->Cell(22, 5, 'Postal Code:', 0, 0);
$pdf->Cell(18, 5, val($owner_info, 'postal_code'), 'B', 0);
$pdf->Cell(10, 5, 'Email:', 0, 0);
$pdf->Cell(38, 5, val($owner_info, 'email'), 'B', 0);
$pdf->Cell(18, 5, 'Tel. No.:', 0, 0);
$pdf->Cell(28, 5, val($owner_info, 'telephone'), 'B', 0);
$pdf->Cell(18, 5, 'Mobile:', 0, 0);
$pdf->Cell(0, 5, val($owner_info, 'mobile'), 'B', 1);

$pdf->Cell(48, 5, 'In case of emergency, provide name:', 0, 0);
$pdf->Cell(48, 5, val($owner_info, 'emergency_name'), 'B', 0);
$pdf->Cell(20, 5, 'Tel/Mobile No.:', 0, 0);
$pdf->Cell(32, 5, val($owner_info, 'emergency_phone'), 'B', 0);
$pdf->Cell(10, 5, 'Email:', 0, 0);
$pdf->Cell(0, 5, val($owner_info, 'emergency_email'), 'B', 1);

$pdf->Cell(38, 5, 'Business Area (sq m):', 0, 0);
$pdf->Cell(18, 5, val($address_data, 'business_area'), 'B', 0);
$pdf->Cell(48, 5, 'Total No. of Employees:', 0, 0);
$pdf->Cell(18, 5, val($address_data, 'total_employees'), 'B', 0);
$pdf->Cell(48, 5, 'Employees residing within LGU:', 0, 0);
$pdf->Cell(0, 5, val($address_data, 'employees_residing'), 'B', 1);
$pdf->Ln(1);

$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell(0, 5, 'Note: Fill Up Only If Business Place Is Rented', 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(38, 5, "Lessor's Full Name:", 0, 0);
$pdf->Cell(58, 5, val($address_data, 'lessor_name'), 'B', 0);
$pdf->Cell(25, 5, "Lessor's Address:", 0, 0);
$pdf->Cell(0, 5, val($address_data, 'lessor_address'), 'B', 1);
$pdf->Cell(42, 5, "Lessor's Tel/Mobile No.:", 0, 0);
$pdf->Cell(38, 5, val($address_data, 'lessor_phone'), 'B', 0);
$pdf->Cell(28, 5, "Lessor's Email:", 0, 0);
$pdf->Cell(48, 5, val($address_data, 'lessor_email'), 'B', 0);
$pdf->Cell(25, 5, 'Monthly Rental:', 0, 0);
$pdf->Cell(0, 5, val($address_data, 'monthly_rental'), 'B', 1);
$pdf->Ln(2);

$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, '3. BUSINESS ACTIVITY', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 7);
$w = array(48, 18, 28, 28, 18, 18);
$pdf->Cell($w[0], 5, 'Line of Business', 1, 0, 'C');
$pdf->Cell($w[1], 5, 'No. of Units', 1, 0, 'C');
$pdf->Cell($w[2], 5, 'Capitalization', 1, 0, 'C');
$pdf->Cell($w[3], 5, 'Gross/Sales', 1, 0, 'C');
$pdf->Cell($w[4], 5, 'Essential', 1, 0, 'C');
$pdf->Cell($w[5], 5, 'Non-Essential', 1, 1, 'C');
$pdf->SetFont('Helvetica', '', 7);
if (empty($activities)) {
    $pdf->Cell($w[0], 5, val($business_info, 'business_activities'), 1, 0, 'L');
    $pdf->Cell($w[1], 5, val($additional_info, 'floor_area', ''), 1, 0, 'C');
    $pdf->Cell($w[2], 5, val($app_type, 'capitalization', ''), 1, 0, 'C');
    $pdf->Cell($w[3], 5, val($app, 'gross_sales', ''), 1, 0, 'C');
    $pdf->Cell($w[4], 5, '', 1, 0, 'C');
    $pdf->Cell($w[5], 5, '', 1, 1, 'C');
} else {
    foreach ($activities as $act) {
        $line = val($act, 'line_of_business', '');
        $units = val($act, 'num_units', '');
        $cap = val($act, 'capitalization', '');
        $gross = val($act, 'gross_essential', '') ?: val($act, 'gross_non_essential', '');
        $essential = (isset($act['essential']) && $act['essential'] == 'Yes') ? 'x' : '';
        $nonessential = (isset($act['non_essential']) && $act['non_essential'] == 'Yes') ? 'x' : '';
        $pdf->Cell($w[0], 5, $line, 1, 0, 'L');
        $pdf->Cell($w[1], 5, $units, 1, 0, 'C');
        $pdf->Cell($w[2], 5, $cap, 1, 0, 'C');
        $pdf->Cell($w[3], 5, $gross, 1, 0, 'C');
        $pdf->Cell($w[4], 5, $essential, 1, 0, 'C');
        $pdf->Cell($w[5], 5, $nonessential, 1, 1, 'C');
    }
}
$pdf->Ln(2);

$pdf->SetFont('Helvetica', 'B', 8);
$pdf->MultiCell(0, 4, "I DECLARE UNDER PENALTY OF PERJURY that the foregoing information are true based on my personal knowledge and authentic records. Further, I agree to comply with the regulatory requirement and other deficiencies within 30 days from release of the business permit.", 0, 'L');
$pdf->Ln(1);
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(50, 5, 'Signature of Applicant/Taxpayer:', 0, 0);
$pdf->Cell(60, 5, val($additional_info, 'owner_fullname'), 'B', 0);
$pdf->Cell(30, 5, 'Position/Title:', 0, 0);
$pdf->Cell(0, 5, val($additional_info, 'position_title'), 'B', 1);

// PAGE 2
$pdf->AddPage();
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(0, 5, 'ANNEX 1 (Page 2 of 2)  Application Form for Business Permit', 0, 1, 'L');
$pdf->Ln(1);

$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 7, 'II. LGU SECTION (Do Not Fill Up This Section)', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, '1. VERIFICATION OF DOCUMENTS', 0, 1, 'L');

$headers = array('Description', 'Office/Agency', 'Yes', 'No', 'Not Needed');
$w2 = array(68, 48, 14, 14, 14);
$pdf->SetFont('Helvetica', 'B', 7);
foreach($headers as $i=>$h) { $pdf->Cell($w2[$i], 5, $h, 1, 0, 'C'); }
$pdf->Ln();

$pdf->SetFont('Helvetica', '', 7);
$rows = [
    ['BARANGAY CLEARANCE', 'Barangay'],
    ['DTI/SEC – XEROX COPY', 'DTI / SEC'],
    ['RPT – CURRENT YEAR 2026 PAYMENT', 'Treasury Office'],
    ['ENGINEERING', 'Municipal Engineering Office'],
    ['ZONING CERTIFICATION (MPDC)', 'MPDC'],
    ['TREASURY', 'Treasury Office'],
    ['SANITARY HEALTH PERMIT (HEALTH CENTER)', 'Health Center'],
    ['FIRE SAFETY CERTIFICATE 2026', 'BFP']
];
$docKeys = [
    'BARANGAY CLEARANCE' => 'barangay',
    'DTI/SEC – XEROX COPY' => 'dti_sec',
    'RPT – CURRENT YEAR 2026 PAYMENT' => 'rpt',
    'ENGINEERING' => 'engineering',
    'ZONING CERTIFICATION (MPDC)' => 'zoning',
    'TREASURY' => 'treasury',
    'SANITARY HEALTH PERMIT (HEALTH CENTER)' => 'sanitary',
    'FIRE SAFETY CERTIFICATE 2026' => 'fire_safety'
];

$status = strtolower($app['status']);
$isVerified = ($status === 'verified');
$isRejected = ($status === 'rejected');

$missingList = [];
if ($isRejected && $rejection && !empty($rejection['missing_documents'])) {
    $missingList = json_decode($rejection['missing_documents'], true);
}
if ($isRejected && $rejection) {
    $note = "Application was rejected due to missing documents. Remarks: " . htmlspecialchars($rejection['notes']);
    $pdf->Cell(0, 5, $note, 0, 1, 'L');
    $pdf->Ln(2);
}
foreach($rows as $r) {
    $key = $docKeys[$r[0]] ?? '';
    $yesMark = ''; $noMark = '';
    if ($isVerified) $yesMark = 'X';
    elseif ($isRejected && in_array($key, $missingList)) $noMark = 'X';
    $pdf->Cell($w2[0], 5, $r[0], 1, 0, 'L');
    $pdf->Cell($w2[1], 5, $r[1], 1, 0, 'L');
    $pdf->Cell($w2[2], 5, $yesMark, 1, 0, 'C');
    $pdf->Cell($w2[3], 5, $noMark, 1, 0, 'C');
    $pdf->Cell($w2[4], 5, '', 1, 1, 'C');
}
$pdf->Ln(1);
$pdf->Cell(50, 5, 'Verified by:', 0, 0);
$pdf->Cell(0, 5, 'BPLO', 'B', 1);

// 2. ASSESSMENT OF APPLICABLE FEES — ANNEX 2
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, '2. ASSESSMENT OF APPLICABLE FEES', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 7);
$headers2 = array('Local Taxes / Fees', 'Amount Due', 'Penalty / Surcharge', 'Total');
$w3 = array(78, 28, 28, 28);
foreach($headers2 as $i=>$h) { $pdf->Cell($w3[$i], 5, $h, 1, 0, 'C'); }
$pdf->Ln();

$prevYear = (int)date('Y') - 1;
$latePaymentLabel = 'Late Payment ' . $prevYear;
$feeItems = [
    ["Mayor's Permits",                ['mayors_permit_fee', 'mayors_permit']],
    ['Gross Sales Tax Computed',       ['gross_sales_tax', 'gross_sales_tax_computed']],
    ['Permit on Calling',              ['permit_on_calling']],
    ['Health Certificate',             ['health_certificate']],
    ['Sanitary Inspection Fee',        ['sanitary_inspection_fee']],
    ['Sanitary Garbage Fee',           ['garbage_charges', 'sanitary_garbage_fee']],
    ['Weight & Measure',               ['weight_measure']],
    ['Misc. Fee',                      ['misc_fee', 'others']],
    ['Surcharge Fee',                  ['surcharge_fee']],
    ['Interest Fee',                   ['interest_fee']],
    [$latePaymentLabel,                ['late_payment']],
    ['Penalty (Lost Business Permit)', ['penalty_lost_permit', 'penalty']],
];
$lguTotal = 0;
foreach ($feeItems as $fi) { $lguTotal += getFeeAmount($assessment, $fi[1]); }
$fireSafety = $assessment ? (float)($assessment['fire_safety_fee_percent'] ?? 0) : 0;
$grandTotal = $lguTotal + $fireSafety;

$pdf->SetFont('Helvetica', '', 6.8);
foreach ($feeItems as $fi) {
    $amount = getFeeAmount($assessment, $fi[1]);
    $amountStr = $amount > 0 ? number_format($amount, 2) : '';
    $pdf->Cell($w3[0], 5, $fi[0], 1, 0, 'L');
    $pdf->Cell($w3[1], 5, $amountStr, 1, 0, 'C');
    $pdf->Cell($w3[2], 5, '', 1, 0, 'C');
    $pdf->Cell($w3[3], 5, $amountStr, 1, 1, 'C');
}
$pdf->SetFont('Helvetica', 'B', 7);
$pdf->Cell($w3[0], 5, 'TOTAL FEES for LGU', 1, 0, 'L');
$pdf->Cell($w3[1], 5, '', 1, 0, 'C');
$pdf->Cell($w3[2], 5, '', 1, 0, 'C');
$pdf->Cell($w3[3], 5, $lguTotal > 0 ? number_format($lguTotal, 2) : '', 1, 1, 'C');

$fireStr = $fireSafety > 0 ? number_format($fireSafety, 2) : '';
$pdf->Cell($w3[0], 5, 'FIRE SAFETY INSPECTION FEE (10%)', 1, 0, 'L');
$pdf->Cell($w3[1], 5, $fireStr, 1, 0, 'C');
$pdf->Cell($w3[2], 5, '', 1, 0, 'C');
$pdf->Cell($w3[3], 5, $fireStr, 1, 1, 'C');

$pdf->Cell($w3[0], 5, 'GRAND TOTAL', 1, 0, 'L');
$pdf->Cell($w3[1], 5, '', 1, 0, 'C');
$pdf->Cell($w3[2], 5, '', 1, 0, 'C');
$pdf->Cell($w3[3], 5, $grandTotal > 0 ? number_format($grandTotal, 2) : '', 1, 1, 'C');

$pdf->Ln(1);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell(50, 5, 'Assessed by:', 0, 0);
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(0, 5, 'CTO', 'B', 1);
$pdf->Cell(50, 5, 'FSIF Assessment Approved by:', 0, 0);
$pdf->Cell(0, 5, 'BFP', 'B', 1);
$pdf->Ln(1);

// III. FIRE STATION SECTION
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 7, 'III. CITY/MUNICIPALITY FIRE STATION SECTION', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell(20, 5, 'DATE:', 0, 0);
$pdf->Cell(40, 5, '', 'B', 1);
$pdf->Cell(30, 5, 'APPLICATION NO.:', 0, 0);
$pdf->Cell(50, 5, val($app, 'reference_no'), 'B', 0);
$pdf->SetFont('Helvetica', 'I', 6);
$pdf->Cell(0, 5, '(TO BE FILLED UP BY APPLICANT/OWNER)', 0, 1);
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(40, 5, 'Name of Applicant/Owner:', 0, 0);
$pdf->Cell(60, 5, val($additional_info, 'owner_fullname'), 'B', 0);
$pdf->Cell(35, 5, 'Name of Business:', 0, 0);
$pdf->Cell(0, 5, val($business_info, 'business_name'), 'B', 1);
$pdf->Cell(30, 5, 'Total Floor Area:', 0, 0);
$pdf->Cell(25, 5, val($address_data, 'business_area'), 'B', 0);
$pdf->Cell(20, 5, 'Contact No.:', 0, 0);
$pdf->Cell(30, 5, val($address_data, 'telephone'), 'B', 0);
$pdf->Cell(35, 5, 'Address of Establishment:', 0, 0);
$pdf->Cell(0, 5, val($address_data, 'business_address'), 'B', 1);
$pdf->Cell(50, 5, 'Signature of Applicant/Owner:', 0, 0);
$pdf->Cell(50, 5, '', 'B', 0);
$pdf->Cell(30, 5, 'Certified by:', 0, 0);
$pdf->Cell(0, 5, 'Customer Relations Officer', 'B', 1);
$pdf->Cell(40, 5, 'Time and Date Received:', 0, 0);
$pdf->Cell(0, 5, '', 'B', 1);
$pdf->Ln(1);

$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell(0, 5, 'FIRE SAFETY INSPECTION FEE ASSESSMENT:', 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 7);
$notice = "Important Notice: As per Section 12 of the Implementing Rules and Regulations of the Fire Code of 2008, certain establishments (e.g. building lessors, fire, earthquake, and explosion hazard insurance companies, and vendors of fire fighting equipment, appliances and devices) may be required to pay additional charges and fees other than the Fire Safety Inspection Fees. These shall be collected during Inspections or in another process to be communicated by representatives of the Bureau of Fire Protection (BFP).";
$pdf->MultiCell(0, 4, $notice, 1, 'L');

$pdf_content = $pdf->Output('S');

$canViewDocs = ($user_type == 3 && in_array(strtolower($app['status']), ['pending_payment', 'payment_confirmed'])) || ($user_type == 4);

function getStatusBadgeClass($status) {
    $s = strtolower($status);
    if ($s === 'submitted') return 'badge-pending';
    if ($s === 'under_review') return 'badge-review';
    if ($s === 'pending_payment') return 'badge-warning';
    if ($s === 'payment_confirmed') return 'badge-info';
    if ($s === 'approved' || $s === 'issued' || $s === 'verified') return 'badge-success';
    if ($s === 'rejected' || $s === 'cancelled') return 'badge-danger';
    return 'badge-secondary';
}
function getStatusLabel($status) {
    $map = [
        'submitted' => 'Submitted','under_review' => 'Under Review','pending_payment' => 'Pending Payment',
        'payment_confirmed' => 'Payment Confirmed','approved' => 'Approved','issued' => 'Issued',
        'rejected' => 'Rejected','cancelled' => 'Cancelled','verified' => 'Verified'
    ];
    $s = strtolower($status);
    return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
}

// ✅ dashboard target based on role
$dashboardUrl = ($user_type == 3) ? 'dashboard_treasury.php' : 'dashboard_bplo.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Application #<?php echo $application_id; ?></title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f0f4f8; padding: 20px; }
        .view-container { max-width: 1400px; margin: 0 auto; }
        .action-bar {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .pdf-wrapper {
            border: 1px solid #dee2e6;
            border-radius: 12px;
            overflow: hidden;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        embed { display: block; width: 100%; height: 800px; }
        .btn-secondary-custom {
            border-radius: 2rem;
            padding: 0.5rem 1.5rem;
            border: 1px solid #1e4b2e;
            color: #1e4b2e;
            background: transparent;
            text-decoration: none;
            display: inline-block;
        }
        .btn-secondary-custom:hover { background: #1e4b2e; color: white; }
        .badge-status {
            padding: 0.3rem 0.8rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-pending { background: #fff3e0; color: #cc7b00; }
        .badge-review { background: #e2e3ff; color: #4339ca; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #f8d7da; color: #b02a37; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }

        .doc-sidebar {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .doc-sidebar h6 {
            color: #1e4b2e;
            font-weight: 700;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .doc-item {
            padding: 10px 12px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .doc-item:last-child { border-bottom: none; }

        /* ✅ Truncate long file names without overflow */
        .doc-item-info {
            flex: 1;
            min-width: 0;   /* allows children to shrink inside flex */
            overflow: hidden;
        }
        .doc-title {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            margin-bottom: 2px;
        }
        .doc-title .doc-icon {
            flex-shrink: 0;
            font-size: 1.15rem;
            color: #1e4b2e;
            line-height: 1;
        }
        .doc-title .doc-name {
            flex: 1;
            min-width: 0;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }
        .doc-item-actions {
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }

        .doc-item .doc-meta { font-size: 0.7rem; color: #6c757d; }
        .doc-item .btn-sm { padding: 0.15rem 0.4rem; font-size: 0.7rem; }
        .doc-missing { color: #dc3545; font-size: 0.7rem; }
        .no-docs { text-align: center; padding: 20px 0; color: #6c757d; }
        .no-docs i { font-size: 2rem; }
        .doc-type-badge {
            background: #e9f5ef;
            color: #1e4b2e;
            font-size: 0.6rem;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        .doc-type-badge.treasury { background: #fff3cd; color: #856404; }
        .doc-type-badge.licensing { background: #cce5ff; color: #004085; }
        .doc-filter-info {
            font-size: 0.75rem;
            padding: 6px 10px;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        .doc-filter-info.treasury {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        .doc-filter-info.licensing {
            background: #cce5ff;
            color: #004085;
            border: 1px solid #b8d4f0;
        }

        /* ✅ Modal viewer tweaks */
        #docViewerModal .modal-header {
            background: #1e4b2e;
            color: #fff;
        }
        #docViewerModal .modal-header .btn-close {
            filter: invert(1) brightness(2);
        }
        #docViewerModal .modal-title {
            font-size: 0.95rem;
            max-width: 80%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        #docViewerModal .modal-body {
            background: #525659;
        }
        #docViewerFrame {
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
        }

        @media (max-width: 768px) {
            .action-bar { flex-direction: column; align-items: stretch; }
            .action-bar > div { text-align: center; }
            .action-bar .btn { width: 100%; margin-bottom: 5px; }
            embed { height: 500px; }
        }
    </style>
</head>
<body>
    <div class="container view-container">
        <!-- Action Bar -->
        <div class="action-bar">
            <div>
                <h4 class="fw-bold" style="color: #1e4b2e; display: inline;">
                    <i class="bi bi-file-earmark-pdf"></i> Application #<?php echo $application_id; ?>
                </h4>
                <span class="badge-status <?php echo getStatusBadgeClass($app['status']); ?> ms-2">
                    <?php echo getStatusLabel($app['status']); ?>
                </span>
                <?php if ($user_type == 3): ?>
                    <span class="badge bg-primary ms-2">Treasury View</span>
                <?php else: ?>
                    <span class="badge bg-info ms-2">Licensing View</span>
                <?php endif; ?>
                <div class="mt-1">
                    <small class="text-muted">Ref: <?php echo htmlspecialchars($app['reference_no']); ?></small>
                    <small class="text-muted ms-3">Filed: <?php echo fmtDate($app['application_date']); ?></small>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <?php if ($user_type == 3): ?>
                    <?php
                        $statusLower = strtolower($app['status']);
                        if ($statusLower == 'pending_payment' || $statusLower == 'payment_confirmed'):
                    ?>
                        <button class="btn btn-warning status-update-btn"
                                data-id="<?php echo $application_id; ?>"
                                data-business="<?php echo htmlspecialchars(val($business_info, 'business_name', 'N/A')); ?>"
                                data-status="<?php echo $app['status']; ?>"
                                data-user-type="3">
                            <i class="bi bi-arrow-repeat"></i> Update Status
                        </button>
                    <?php else: ?>
                        <span class="text-muted"><i class="bi bi-lock"></i> No actions available</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($user_type == 4): ?>
                    <?php if (strtolower($app['status']) === 'verified'): ?>
                        <span class="badge bg-success">
                            <i class="bi bi-check-circle-fill"></i> Application Verified — No further action
                        </span>
                    <?php else: ?>
                        <button class="btn btn-warning status-update-btn"
                                data-id="<?php echo $application_id; ?>"
                                data-business="<?php echo htmlspecialchars(val($business_info, 'business_name', 'N/A')); ?>"
                                data-status="<?php echo $app['status']; ?>"
                                data-user-type="4">
                            <i class="bi bi-arrow-repeat"></i> Update Status
                        </button>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- ✅ Back to Dashboard (correct target per role) -->
                <!--<a href="<?php //echo $dashboardUrl; ?>" class="btn btn-secondary-custom">-->
                <!--    <i class="bi bi-arrow-left"></i> Back to Dashboard-->
                <!--</a>-->
            </div>
        </div>

        <div class="row">
            <!-- Main PDF -->
            <div class="col-lg-9">
                <div class="pdf-wrapper">
                    <embed src="data:application/pdf;base64,<?php echo base64_encode($pdf_content); ?>" type="application/pdf" />
                </div>
            </div>

            <!-- Documents Sidebar -->
            <div class="col-lg-3">
                <div class="doc-sidebar">
                    <h6>
                        <i class="bi bi-files me-2"></i>
                        <?php if ($user_type == 3): ?>
                            Barangay Clearance, DTI/SEC & Engineering
                        <?php else: ?>
                            All Documents
                        <?php endif; ?>
                        <span class="badge bg-secondary ms-1"><?php echo count($documents); ?></span>
                        <?php if ($user_type == 3): ?>
                            <span class="doc-type-badge treasury ms-1">Treasury</span>
                        <?php else: ?>
                            <span class="doc-type-badge licensing ms-1">Licensing</span>
                        <?php endif; ?>
                    </h6>

                    <?php if ($user_type == 3): ?>
                        <div class="doc-filter-info treasury">
                            <i class="bi bi-info-circle"></i> Showing: Barangay Clearance, DTI/SEC & Engineering only
                        </div>
                    <?php else: ?>
                        <div class="doc-filter-info licensing">
                            <i class="bi bi-info-circle"></i> Showing all documents
                        </div>
                    <?php endif; ?>

                    <?php if (empty($documents)): ?>
                        <div class="no-docs">
                            <i class="bi bi-file-earmark-x"></i>
                            <p class="mt-1 mb-0">No documents found.</p>
                            <?php if ($user_type == 3): ?>
                                <small class="text-muted">No Barangay Clearance, DTI/SEC or Engineering documents uploaded.</small>
                            <?php else: ?>
                                <small class="text-muted">No documents uploaded yet.</small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <?php
                                $fileExists = isset($doc['file_exists']) ? $doc['file_exists'] : true;
                                $filePath = $doc['file_path'];
                                $docType = $doc['document_type'] ?: 'General';
                                $originalName = $doc['original_name'];
                            ?>
                            <div class="doc-item">
                                <div class="doc-item-info">
                                    <div class="doc-title">
                                        <span class="doc-icon"><i class="bi bi-file-earmark-pdf"></i></span>
                                        <span class="doc-name" title="<?php echo htmlspecialchars($originalName); ?>">
                                            <?php echo htmlspecialchars($originalName); ?>
                                        </span>
                                    </div>
                                    <div class="doc-meta">
                                        <span class="doc-type-badge"><?php echo htmlspecialchars($docType); ?></span>
                                        · <?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?>
                                        <?php if (!$fileExists): ?>
                                            <span class="doc-missing"><i class="bi bi-exclamation-triangle"></i> Missing</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="doc-item-actions">
                                    <?php if ($fileExists && $canViewDocs): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary doc-view-btn"
                                                data-file="<?php echo htmlspecialchars($filePath); ?>"
                                                data-name="<?php echo htmlspecialchars($originalName); ?>"
                                                title="View">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    <?php elseif (!$fileExists): ?>
                                        <span class="text-danger" title="File not found"><i class="bi bi-exclamation-triangle"></i></span>
                                    <?php else: ?>
                                        <span class="text-muted small">No access</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ Document Viewer Modal -->
    <div class="modal fade" id="docViewerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1100px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="docViewerTitle">
                        <i class="bi bi-file-earmark-pdf"></i> Document
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" style="height: 75vh;">
                    <iframe id="docViewerFrame" src="about:blank" title="Document Viewer"></iframe>
                </div>
                <div class="modal-footer">
                    <a id="docDownloadLink" href="#" class="btn btn-outline-primary" download>
                        <i class="bi bi-download"></i> Download
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    // ============================================================
    // Document Viewer Modal
    // ============================================================
    (function() {
        const modalEl   = document.getElementById('docViewerModal');
        const frameEl   = document.getElementById('docViewerFrame');
        const titleEl   = document.getElementById('docViewerTitle');
        const dlLinkEl  = document.getElementById('docDownloadLink');
        const bsModal   = new bootstrap.Modal(modalEl);

        document.querySelectorAll('.doc-view-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const file = this.getAttribute('data-file') || '';
                const name = this.getAttribute('data-name') || 'Document';
                const url  = 'download_document.php?file=' + encodeURIComponent(file);

                titleEl.innerHTML = '<i class="bi bi-file-earmark-pdf"></i> ' + name;
                titleEl.setAttribute('title', name);
                frameEl.src = url;
                dlLinkEl.href = url;
                bsModal.show();
            });
        });

        // Reset iframe when modal closes (stops PDF from loading in background)
        modalEl.addEventListener('hidden.bs.modal', function() {
            frameEl.src = 'about:blank';
        });
    })();

    // ============================================================
    // Status update flow (unchanged)
    // ============================================================
    function formatStatusText(status) {
        if (!status) return 'Unknown';
        const map = {
            'submitted': 'Submitted', 'under_review': 'Under Review',
            'pending_payment': 'Pending Payment', 'payment_confirmed': 'Payment Confirmed',
            'approved': 'Approved', 'issued': 'Issued', 'rejected': 'Rejected',
            'cancelled': 'Cancelled', 'verified': 'Verified'
        };
        return map[status.toLowerCase()] || status.replace(/_/g, ' ').toUpperCase();
    }
    function getStatusBadgeClass(status) {
        if (!status) return 'badge-secondary';
        const s = status.toLowerCase();
        if (s === 'submitted') return 'badge-pending';
        if (s === 'under_review') return 'badge-review';
        if (s === 'pending_payment') return 'badge-warning';
        if (s === 'payment_confirmed') return 'badge-info';
        if (s === 'approved' || s === 'issued' || s === 'verified') return 'badge-success';
        if (s === 'rejected' || s === 'cancelled') return 'badge-danger';
        return 'badge-secondary';
    }

    $(document).on('click', '.status-update-btn', function() {
        const appId = $(this).data('id');
        const businessName = $(this).data('business');
        const currentStatus = $(this).data('status');
        const userType = $(this).data('user-type') || '4';

        let statusOptions = [];
        if (userType == '3') {
            statusOptions = [
                { value: 'payment_confirmed', label: 'Payment Confirmed', icon: '✅', color: '#198754' },
                { value: 'cancelled', label: 'Cancelled', icon: '❌', color: '#dc3545' }
            ];
        } else if (userType == '4') {
            if (currentStatus === 'submitted') {
                statusOptions = [
                    { value: 'pending_payment', label: 'Proceed to Treasury', icon: '🏛️', color: '#856404' },
                    { value: 'rejected', label: 'Rejected', icon: '❌', color: '#dc3545' }
                ];
            } else if (currentStatus === 'under_review') {
                statusOptions = [
                    { value: 'under_review', label: 'Under Review', icon: '📋', color: '#4339ca' },
                    { value: 'rejected', label: 'Rejected', icon: '❌', color: '#dc3545' }
                ];
            } else if (currentStatus === 'pending_payment') {
                statusOptions = [
                    { value: 'pending_payment', label: 'Proceed to Treasury', icon: '🏛️', color: '#856404' },
                    { value: 'rejected', label: 'Rejected', icon: '❌', color: '#dc3545' }
                ];
            } else if (currentStatus === 'payment_confirmed') {
                statusOptions = [
                    { value: 'verified', label: 'Verified (📧 Email)', icon: '✅', color: '#155724' },
                    { value: 'rejected', label: 'Rejected', icon: '❌', color: '#dc3545' }
                ];
            } else if (currentStatus === 'rejected') {
                statusOptions = [
                    { value: 'verified', label: 'Verified (📧 Email)', icon: '✅', color: '#155724' },
                    { value: 'pending_payment', label: 'Proceed to Treasury', icon: '🏛️', color: '#856404' }
                ];
            } else {
                statusOptions = [
                    { value: 'pending_payment', label: 'Proceed to Treasury', icon: '🏛️', color: '#856404' },
                    { value: 'rejected', label: 'Rejected', icon: '❌', color: '#dc3545' }
                ];
            }
        }

        let statusButtonsHtml = '';
        statusOptions.forEach(function(option) {
            const isActive = currentStatus === option.value;
            statusButtonsHtml += `<button class="btn btn-outline-${isActive ? 'primary' : 'secondary'} status-option-btn m-1" data-value="${option.value}" style="min-width:160px; ${isActive ? 'border: 2px solid ' + option.color : ''}">${option.icon} ${option.label}${isActive ? ' ✓' : ''}</button>`;
        });

        const formattedStatus = formatStatusText(currentStatus);
        const badgeClass = getStatusBadgeClass(currentStatus);

        const checklistHtml = `<div id="docChecklist" style="display:none; margin-top:1rem;"><p class="fw-bold">Select missing documents:</p><div class="row g-2"><div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="barangay"> Barangay Clearance</div><div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="dti_sec"> DTI/SEC – Xerox Copy</div><div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="rpt"> RPT – Current Year 2026 Payment</div><div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="engineering"> Engineering</div><div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="zoning"> Zoning Certification (MPDC)</div><div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="treasury"> Treasury</div><div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="sanitary"> Sanitary Health Permit (Health Center)</div><div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="fire_safety"> Fire Safety Certificate 2026</div></div></div>`;
        const hintHtml = `<div id="statusHint" class="alert alert-info mt-2" style="display:none;"><i class="bi bi-info-circle"></i> <span id="hintText"></span></div>`;

        async function fetchMissingDocs(appId) {
            try {
                const response = await fetch(`get_rejection.php?application_id=${appId}`);
                const data = await response.json();
                return data.success ? (data.missing_documents || []) : [];
            } catch (e) { return []; }
        }

        Swal.fire({
            title: 'Update Application Status',
            html: `<div class="text-start"><p><strong>Application:</strong> ${businessName}</p><p><strong>Current Status:</strong> <span class="badge-status ${badgeClass}">${formattedStatus}</span></p><hr><p class="fw-bold mt-3">Select new status:</p><div class="d-flex flex-wrap justify-content-center gap-2 mt-2">${statusButtonsHtml}</div>${checklistHtml}${hintHtml}<div class="mt-3"><label class="form-label fw-bold">Remarks (optional):</label><textarea id="statusRemarks" class="form-control" rows="2" placeholder="Add remarks for this status change..."></textarea></div></div>`,
            showCancelButton: true,
            confirmButtonText: 'Update Status',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            width: 700,
            didOpen: async function() {
                document.querySelectorAll('.status-option-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        document.querySelectorAll('.status-option-btn').forEach(function(b) {
                            b.classList.remove('btn-primary');
                            b.classList.add('btn-outline-secondary');
                            b.style.border = 'none';
                        });
                        this.classList.remove('btn-outline-secondary');
                        this.classList.add('btn-primary');
                        this.style.border = '2px solid #198754';

                        const hintDiv = document.getElementById('statusHint');
                        const hintText = document.getElementById('hintText');
                        const checklistDiv = document.getElementById('docChecklist');

                        if (this.dataset.value === 'verified') {
                            hintDiv.style.display = 'block';
                            hintText.innerHTML = 'Selecting <strong>Verified</strong> will send an email notification to the applicant about certificate claiming.';
                            checklistDiv.style.display = 'none';
                        } else if (this.dataset.value === 'rejected' && userType == '4') {
                            hintDiv.style.display = 'block';
                            hintText.innerHTML = 'Selecting <strong>Rejected</strong> will send an email listing the missing documents you check below.';
                            checklistDiv.style.display = 'block';
                        } else if (this.dataset.value === 'pending_payment') {
                            hintDiv.style.display = 'block';
                            hintText.innerHTML = 'Application will be forwarded to the <strong>Treasury Office</strong> for payment processing.';
                            checklistDiv.style.display = 'none';
                        } else if (this.dataset.value === 'under_review') {
                            hintDiv.style.display = 'block';
                            hintText.innerHTML = 'Application will be marked as <strong>Under Review</strong>.';
                            checklistDiv.style.display = 'none';
                        } else {
                            hintDiv.style.display = 'none';
                            checklistDiv.style.display = 'none';
                        }
                    });
                });

                const missingDocs = await fetchMissingDocs(appId);
                if (missingDocs.length > 0) {
                    document.getElementById('docChecklist').style.display = 'block';
                    document.querySelectorAll('.doc-checkbox').forEach(function(cb) {
                        if (missingDocs.includes(cb.value)) cb.checked = true;
                    });
                    document.getElementById('statusHint').style.display = 'block';
                    document.getElementById('hintText').innerHTML = 'Selecting <strong>Rejected</strong> will send an email listing the missing documents you check below.';
                }

                if (currentStatus === 'rejected' && userType == '4') {
                    const b = document.querySelector('.status-option-btn[data-value="rejected"]'); if (b) b.click();
                }
                if (currentStatus === 'under_review' && userType == '4') {
                    const b = document.querySelector('.status-option-btn[data-value="under_review"]'); if (b) b.click();
                }
                if (currentStatus === 'pending_payment' && userType == '4') {
                    const b = document.querySelector('.status-option-btn[data-value="pending_payment"]'); if (b) b.click();
                }
            },
            preConfirm: function() {
                const selectedBtn = document.querySelector('.status-option-btn.btn-primary');
                if (!selectedBtn) { Swal.showValidationMessage('Please select a status'); return false; }
                const newStatus = selectedBtn.dataset.value;
                const remarks = document.getElementById('statusRemarks').value.trim();
                let missingDocs = [];
                if (newStatus === 'rejected' && userType == '4') {
                    document.querySelectorAll('.doc-checkbox:checked').forEach(function(cb) { missingDocs.push(cb.value); });
                    if (missingDocs.length === 0 && currentStatus !== 'rejected') {
                        Swal.showValidationMessage('Please select at least one missing document.');
                        return false;
                    }
                }
                return { status: newStatus, remarks: remarks, missing_docs: missingDocs };
            }
        }).then(function(result) {
            if (result.isConfirmed && result.value) {
                updateStatus(appId, result.value.status, result.value.remarks, result.value.missing_docs);
            }
        });
    });

    function updateStatus(appId, status, remarks, missingDocs) {
        Swal.fire({ title: 'Processing...', html: 'Please wait...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
        $.ajax({
            url: 'update_application_status.php',
            type: 'POST',
            data: { application_id: appId, status: status, notes: remarks, missing_docs: missingDocs },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    let message = response.message || 'Status updated successfully.';
                    if (response.email_sent) message += ' <br><i class="bi bi-envelope-check text-success"></i> ' + response.email_msg;
                    else if (response.email_msg) message += ' <br><i class="bi bi-info-circle text-muted"></i> ' + response.email_msg;
                    let redirectUrl = response.redirect || '<?php echo $dashboardUrl; ?>';
                    Swal.fire({ icon: 'success', title: 'Status Updated!', html: message + '<br><br><small>Redirecting...</small>', timer: 3000, showConfirmButton: false })
                        .then(function() { window.location.href = redirectUrl; });
                } else {
                    Swal.fire({ icon: 'error', title: 'Update Failed', text: response.message || 'Unknown error occurred.' });
                }
            },
            error: function(xhr) {
                let errorMsg = 'Please try again.';
                try { const response = JSON.parse(xhr.responseText); if (response.message) errorMsg = response.message; } catch(e) { errorMsg = xhr.responseText || 'Unknown error'; }
                Swal.fire({ icon: 'error', title: 'Request Failed', html: 'Error: ' + errorMsg + '<br><small class="text-muted">Status: ' + xhr.status + '</small>' });
            }
        });
    }
    </script>
</body>
</html>
<?php
exit;
?>