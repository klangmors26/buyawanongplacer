<?php
session_start();

// Allow applicants (1), Treasury (3), and Licensing (4)
if (!isset($_SESSION['user_id']) || !in_array((int)$_SESSION['user_type'], [1, 3, 4])) {
    http_response_code(403);
    exit('Access denied. Please log in.');
}

require_once 'config/connect.php';
require_once 'fpdf19/fpdf.php';

$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($application_id <= 0) {
    http_response_code(400);
    exit('Invalid application ID.');
}

$user_id   = (int)$_SESSION['user_id'];
$user_type = (int)$_SESSION['user_type'];

// ============================================================
// FETCH APPLICATION (with ownership check for user_type = 1)
// ============================================================
$sql = "SELECT * FROM business_applications WHERE id = ?";
if ($user_type === 1) {
    $sql .= " AND user_id = ?";
}
$stmt = $conn->prepare($sql);
if ($user_type === 1) {
    $stmt->bind_param("ii", $application_id, $user_id);
} else {
    $stmt->bind_param("i", $application_id);
}
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    http_response_code(404);
    exit('Application not found or access denied.');
}
$app = $result->fetch_assoc();
$stmt->close();

// ============================================================
// Only allow download when status = 'verified'
// ============================================================
if (strtolower($app['status']) !== 'verified') {
    http_response_code(403);
    exit('The application form is only downloadable once the application is verified.');
}

// ============================================================
// FETCH RELATED DATA
// ============================================================
function fetchRow($conn, $sql, $id) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r ?: [];
}

$business_info  = fetchRow($conn, "SELECT * FROM business_info_data    WHERE application_id = ?", $application_id);
$owner_info     = fetchRow($conn, "SELECT * FROM owner_info_data       WHERE application_id = ?", $application_id);
$app_type       = fetchRow($conn, "SELECT * FROM application_type_data WHERE application_id = ?", $application_id);
$address_data   = fetchRow($conn, "SELECT * FROM business_address_data WHERE application_id = ?", $application_id);
$additional_info= fetchRow($conn, "SELECT * FROM additional_info_data  WHERE application_id = ?", $application_id);
$assessment     = fetchRow($conn, "SELECT * FROM fee_assessments       WHERE application_id = ?", $application_id);
$rejection      = fetchRow($conn, "SELECT missing_documents, notes FROM application_rejections WHERE application_id = ?", $application_id);

function val($data, $key, $default = '') {
    return isset($data[$key]) ? $data[$key] : $default;
}
function fmtDate($date) {
    return $date ? date('M d, Y', strtotime($date)) : '';
}

// Parse business activities
$activities = [];
if (!empty($business_info['business_activities'])) {
    $decoded = json_decode($business_info['business_activities'], true);
    if (is_array($decoded)) {
        if (isset($decoded['line_of_business'])) {
            $activities[] = $decoded;
        } else {
            $activities = $decoded;
        }
    }
}

// ============================================================
// GENERATE PDF (same template as staff/view_application.php)
// ============================================================
$pdf = new FPDF('P', 'mm', array(215.9, 330.2));
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();

// ---------- PAGE 1 ----------
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
$chk_new   = (val($app_type, 'application_type') == 'New')     ? 'x' : '';
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
$pdf->Cell($w[1], 5, 'No. of Units',    1, 0, 'C');
$pdf->Cell($w[2], 5, 'Capitalization',  1, 0, 'C');
$pdf->Cell($w[3], 5, 'Gross/Sales',     1, 0, 'C');
$pdf->Cell($w[4], 5, 'Essential',       1, 0, 'C');
$pdf->Cell($w[5], 5, 'Non-Essential',   1, 1, 'C');
$pdf->SetFont('Helvetica', '', 7);
if (empty($activities)) {
    $pdf->Cell($w[0], 5, val($business_info, 'business_activities'), 1, 0, 'L');
    $pdf->Cell($w[1], 5, val($additional_info, 'floor_area', ''),   1, 0, 'C');
    $pdf->Cell($w[2], 5, val($app_type, 'capitalization', ''),      1, 0, 'C');
    $pdf->Cell($w[3], 5, val($app, 'gross_sales', ''),              1, 0, 'C');
    $pdf->Cell($w[4], 5, '', 1, 0, 'C');
    $pdf->Cell($w[5], 5, '', 1, 1, 'C');
} else {
    foreach ($activities as $act) {
        $line         = val($act, 'line_of_business', '');
        $units        = val($act, 'num_units', '');
        $cap          = val($act, 'capitalization', '');
        $gross        = val($act, 'gross_essential', '') ?: val($act, 'gross_non_essential', '');
        $essential    = (isset($act['essential'])     && $act['essential']     == 'Yes') ? 'x' : '';
        $nonessential = (isset($act['non_essential']) && $act['non_essential'] == 'Yes') ? 'x' : '';
        $pdf->Cell($w[0], 5, $line,         1, 0, 'L');
        $pdf->Cell($w[1], 5, $units,        1, 0, 'C');
        $pdf->Cell($w[2], 5, $cap,          1, 0, 'C');
        $pdf->Cell($w[3], 5, $gross,        1, 0, 'C');
        $pdf->Cell($w[4], 5, $essential,    1, 0, 'C');
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

// ---------- PAGE 2 ----------
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
foreach ($headers as $i => $h) {
    $pdf->Cell($w2[$i], 5, $h, 1, 0, 'C');
}
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

foreach ($rows as $r) {
    $key = $docKeys[$r[0]] ?? '';
    $yesMark = '';
    $noMark  = '';
    if ($isVerified) {
        $yesMark = 'X';
    } elseif ($isRejected && in_array($key, $missingList)) {
        $noMark = 'X';
    }
    $pdf->Cell($w2[0], 5, $r[0],   1, 0, 'L');
    $pdf->Cell($w2[1], 5, $r[1],   1, 0, 'L');
    $pdf->Cell($w2[2], 5, $yesMark,1, 0, 'C');
    $pdf->Cell($w2[3], 5, $noMark, 1, 0, 'C');
    $pdf->Cell($w2[4], 5, '',      1, 1, 'C');
}
$pdf->Ln(1);
$pdf->Cell(50, 5, 'Verified by:', 0, 0);
$pdf->Cell(0, 5, 'BPLO', 'B', 1);

// --- 2. ASSESSMENT OF APPLICABLE FEES ---
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, '2. ASSESSMENT OF APPLICABLE FEES', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 7);
$headers2 = array('Local Taxes', 'Amount Due', 'Penalty/Surcharge', 'Total');
$w3 = array(78, 28, 28, 28);
foreach ($headers2 as $i => $h) {
    $pdf->Cell($w3[$i], 5, $h, 1, 0, 'C');
}
$pdf->Ln();

$feeItems = [
    'Gross Sales Tax',
    'Tax on Delivery Vans / Trucks',
    'Tax on Storage for Combustible/Flammable of Explosive Substance',
    'Tax on Signboard / Billboards',
    'REGULATORY FEES AND CHARGES',
    'Mayor’s Permit Fee',
    'Garbage Charges',
    'Delivery Trucks/Vans Permit Fee',
    'Sanitary Inspection Fee',
    'Building Inspection Fee',
    'Electrical Inspection Fee',
    'Mechanical Inspection Fee',
    'Plumbing Inspection Fee',
    'Signboard/Billboard Renewal Fee',
    'Storage and Sale of Combustible / Flammable or Explosive Substance',
    'Others',
    'TOTAL FEES for LGU'
];

$feeMapping = [
    'Gross Sales Tax'            => 'gross_sales_tax',
    'Mayor’s Permit Fee'         => 'mayors_permit_fee',
    'Garbage Charges'            => 'garbage_charges',
    'Sanitary Inspection Fee'    => 'sanitary_inspection_fee',
    'Building Inspection Fee'    => 'building_inspection_fee',
    'Electrical Inspection Fee'  => 'electrical_inspection_fee',
    'Others'                     => 'others',
];

$lguTotal = 0;
if ($assessment) {
    foreach (['gross_sales_tax','mayors_permit_fee','garbage_charges','sanitary_inspection_fee','building_inspection_fee','electrical_inspection_fee','others'] as $f) {
        $lguTotal += floatval($assessment[$f] ?? 0);
    }
}
$fireSafety = $assessment ? floatval($assessment['fire_safety_fee_percent'] ?? 0) : 0;

$pdf->SetFont('Helvetica', '', 6.5);
$seen = [];
foreach ($feeItems as $item) {
    if ($item == 'Signboard/Billboard Renewal Fee' && in_array($item, $seen)) continue;
    $seen[] = $item;

    $dbField = $feeMapping[$item] ?? null;
    if ($dbField !== null && $assessment) {
        $amount = floatval($assessment[$dbField] ?? 0);
        $amountStr = $amount > 0 ? number_format($amount, 2) : '';
    } else {
        $amountStr = '';
    }
    if ($item === 'TOTAL FEES for LGU') {
        $amountStr = $lguTotal > 0 ? number_format($lguTotal, 2) : '';
    }

    if ($item === 'REGULATORY FEES AND CHARGES') {
        $pdf->Cell($w3[0], 5, $item, 1, 0, 'L');
        $pdf->Cell($w3[1], 5, '',    1, 0, 'C');
        $pdf->Cell($w3[2], 5, '',    1, 0, 'C');
        $pdf->Cell($w3[3], 5, '',    1, 1, 'C');
    } else {
        $pdf->Cell($w3[0], 5, $item,     1, 0, 'L');
        $pdf->Cell($w3[1], 5, $amountStr,1, 0, 'C');
        $pdf->Cell($w3[2], 5, '',        1, 0, 'C');
        $pdf->Cell($w3[3], 5, $amountStr,1, 1, 'C');
    }
}

$fireStr  = $fireSafety > 0 ? number_format($fireSafety, 2) : '';
$grandTotal = $lguTotal + $fireSafety;
$grandStr = $grandTotal > 0 ? number_format($grandTotal, 2) : '';

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->Cell($w3[0], 5, 'FIRE SAFETY INSPECTION FEE (10%)', 1, 0, 'L');
$pdf->Cell($w3[1], 5, $fireStr, 1, 0, 'C');
$pdf->Cell($w3[2], 5, '',       1, 0, 'C');
$pdf->Cell($w3[3], 5, $fireStr, 1, 1, 'C');

$pdf->Cell($w3[0], 5, 'GRAND TOTAL', 1, 0, 'L');
$pdf->Cell($w3[1], 5, '',           1, 0, 'C');
$pdf->Cell($w3[2], 5, '',           1, 0, 'C');
$pdf->Cell($w3[3], 5, $grandStr,    1, 1, 'C');

$pdf->Ln(1);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell(50, 5, 'Assessed by:', 0, 0);
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(0, 5, 'CTO', 'B', 1);
$pdf->Cell(50, 5, 'FSIF Assessment Approved by:', 0, 0);
$pdf->Cell(0, 5, 'BFP', 'B', 1);
$pdf->Ln(1);

// --- III. FIRE STATION SECTION ---
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 7, 'III. CITY/MUNICIPALITY FIRE STATION SECTION', 0, 1, 'L');
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell(20, 5, 'DATE:', 0, 0);
$pdf->Cell(40, 5, '', 'B', 1);
$pdf->Cell(30, 5, 'APPLICATION NO.:', 0, 0);
$pdf->Cell(50, 5, '', 'B', 0);
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

// ============================================================
// OUTPUT AS DOWNLOAD
// ============================================================
$ref = $app['reference_no'] ?? ('APP-' . $application_id);
$filename = 'Application_Form_' . preg_replace('/[^A-Za-z0-9\-_]/', '_', $ref) . '.pdf';

if (ob_get_length()) ob_end_clean();
$pdf->Output('D', $filename); // D = force download
exit;