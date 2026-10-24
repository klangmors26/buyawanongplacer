<?php
/**
 * Business Permit PDF Generator
 * Uses FPDF 1.9 - Landscape A4
 *
 * Permit can only be generated when:
 * 1. User is logged in
 * 2. Applicant can only access their own application
 * 3. Application status is "verified"
 */

// =========================================================
// AUTHENTICATION / DATABASE
// =========================================================
session_start();

require_once 'config/connect.php';
require('fpdf19/fpdf.php');


// =========================================================
// CHECK LOGIN
// =========================================================
if (
    !isset($_SESSION['user_id']) ||
    !in_array((int)$_SESSION['user_type'], [1, 3, 4], true)
) {
    header('Location: login.php');
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_type = (int)$_SESSION['user_type'];

$app_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

if ($app_id <= 0) {
    http_response_code(400);
    die('Invalid application ID.');
}


// =========================================================
// GET APPLICATION
// =========================================================
$sql = "
    SELECT
        ba.id,
        ba.user_id,
        ba.reference_no,
        ba.status,
        ba.application_date,
        ba.expiry_date,
        ba.tax_year,
        ba.renewed_from_id,

        atd.application_type,
        atd.tin_no,
        atd.business_type,

        bid.business_name,
        bid.trade_name,
        bid.first_name AS owner_first_name,
        bid.last_name AS owner_last_name,
        bid.middle_name AS owner_middle_name,
        bid.business_activities,

        bad.business_address,
        bad.total_employees

    FROM business_applications ba

    LEFT JOIN application_type_data atd
        ON ba.id = atd.application_id

    LEFT JOIN business_info_data bid
        ON ba.id = bid.application_id

    LEFT JOIN business_address_data bad
        ON ba.id = bad.application_id

    WHERE ba.id = ?
";


// Applicants can only generate their own permit.
// Staff/admin users can generate any application.
if ($user_type === 1) {
    $sql .= " AND ba.user_id = ?";
}


$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Database query preparation failed.');
}


if ($user_type === 1) {
    $stmt->bind_param('ii', $app_id, $user_id);
} else {
    $stmt->bind_param('i', $app_id);
}


$stmt->execute();

$result = $stmt->get_result();

$app = $result->fetch_assoc();

$stmt->close();


// =========================================================
// APPLICATION NOT FOUND
// =========================================================
if (!$app) {
    http_response_code(404);
    die('Application not found or access denied.');
}


// =========================================================
// VERIFIED CHECK
// =========================================================
if (!in_array($app['status'], ['verified', 'claimed'], true)) {
    http_response_code(403);
    die('Business permit can only be generated after BPLO verification.');
}


// =========================================================
// APPLICATION DATA
// =========================================================

// ---------------------------------------------------------
// Business name
// ---------------------------------------------------------
$businessName = trim($app['business_name'] ?? '');
if ($businessName === '') {
    $businessName = 'N/A';
}

// ---------------------------------------------------------
// Owner name
// ---------------------------------------------------------
$ownerName = trim(
    ($app['owner_first_name'] ?? '') . ' ' .
    ($app['owner_middle_name'] ?? '') . ' ' .
    ($app['owner_last_name'] ?? '')
);
$ownerName = preg_replace('/\s+/', ' ', $ownerName);
if ($ownerName === '') {
    $ownerName = 'N/A';
}

// ---------------------------------------------------------
// Business address
// ---------------------------------------------------------
$businessAddress = trim($app['business_address'] ?? '');
if ($businessAddress === '') {
    $businessAddress = 'N/A';
}

// ---------------------------------------------------------
// Tax year
// ---------------------------------------------------------
$taxYear = !empty($app['tax_year']) ? $app['tax_year'] : date('Y');

// ---------------------------------------------------------
// Reference number
// ---------------------------------------------------------
$referenceNo = trim($app['reference_no'] ?? '');
if ($referenceNo === '') {
    $referenceNo = 'N/A';
}


// =========================================================
// LINE OF BUSINESS
// =========================================================
$lineOfBusiness = 'Not specified';

if (!empty($app['business_activities'])) {
    $activities = json_decode($app['business_activities'], true);

    if (is_array($activities)) {
        if (
            isset($activities[0]) &&
            is_array($activities[0]) &&
            !empty($activities[0]['line_of_business'])
        ) {
            $lineOfBusiness = trim($activities[0]['line_of_business']);
        } elseif (
            isset($activities['line_of_business']) &&
            !empty($activities['line_of_business'])
        ) {
            $lineOfBusiness = trim($activities['line_of_business']);
        }
    }
}

if ($lineOfBusiness === '') {
    $lineOfBusiness = 'Not specified';
}


// =========================================================
// APPLICATION TYPE LABEL (NEW / RENEWAL / AMENDMENT)
// =========================================================
$appTypeRaw = strtolower(trim($app['application_type'] ?? ''));

if (strpos($appTypeRaw, 'renew') !== false) {
    $applicationTypeLabel = 'RENEWAL';
} elseif (strpos($appTypeRaw, 'amend') !== false) {
    $applicationTypeLabel = 'AMENDMENT';
} elseif (strpos($appTypeRaw, 'new') !== false) {
    $applicationTypeLabel = 'NEW';
} else {
    $applicationTypeLabel = 'NEW';
}

// Smart fallback: if renewed_from_id is set, force RENEWAL
if (!empty($app['renewed_from_id']) && $applicationTypeLabel === 'NEW') {
    $applicationTypeLabel = 'RENEWAL';
}


// =========================================================
// EXPIRY DATE
// =========================================================
if (!empty($app['expiry_date'])) {
    $expiryTimestamp = strtotime($app['expiry_date']);
    if ($expiryTimestamp !== false) {
        $expiryDate = date('F j, Y', $expiryTimestamp);
    } else {
        $expiryDate = 'December 31, ' . $taxYear;
    }
} else {
    $expiryDate = 'December 31, ' . $taxYear;
}


// =========================================================
// OR NUMBER
// =========================================================
$orNumber = 'OR-' . date('Y') . '-' . str_pad($app_id, 6, '0', STR_PAD_LEFT);


// =========================================================
// PERMIT NUMBER
// =========================================================
if ($referenceNo !== 'N/A') {
    if (strpos($referenceNo, 'REF-') === 0) {
        $permitNo = substr($referenceNo, 4);
    } else {
        $permitNo = $referenceNo;
    }
} else {
    $permitNo = 'MP-' . str_pad($app_id, 6, '0', STR_PAD_LEFT);
}


// =========================================================
// PAGE SETUP
// =========================================================
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0, 0);
$pdf->AddPage();

$pageW = $pdf->GetPageWidth();   // 297 mm
$pageH = $pdf->GetPageHeight();  // 210 mm


// =========================================================
// COLORS
// =========================================================
$ink     = [20, 16, 12];
$blue    = [35, 64, 168];
$sealBlu = [41, 72, 157];
$boxInk  = [107, 31, 31];


// =========================================================
// BACKGROUND
// =========================================================
$pdf->SetFillColor(253, 251, 245);
$pdf->Rect(0, 0, $pageW, $pageH, 'F');


// =========================================================
// SQUARE-DOT BORDER
// =========================================================
$pdf->SetFillColor($sealBlu[0], $sealBlu[1], $sealBlu[2]);

$inset = 8;
$size  = 3;
$gap   = 6;
$step  = $size + $gap;

for ($x = $inset; $x <= $pageW - $inset - $size; $x += $step) {
    $pdf->Rect($x, $inset, $size, $size, 'F');
    $pdf->Rect($x, $pageH - $inset - $size, $size, $size, 'F');
}

for ($y = $inset + $step; $y <= $pageH - $inset - $size - $step; $y += $step) {
    $pdf->Rect($inset, $y, $size, $size, 'F');
    $pdf->Rect($pageW - $inset - $size, $y, $size, $size, 'F');
}


// =========================================================
// LOGO
// =========================================================
$logo = 'img/official-logo.png';

if (file_exists($logo)) {
    $pdf->Image($logo, 25, 15, 50, 0);   // width 60, height auto
} else {
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->SetLineWidth(0.5);
    $pdf->Circle(47, 42, 32);
}


// =========================================================
// MASTHEAD
// =========================================================
$pdf->SetTextColor($ink[0], $ink[1], $ink[2]);
$pdf->SetFont('Times', 'B', 10);

$pdf->SetXY(85, 15);
$pdf->Cell(150, 5, 'Republic of the Philippines', 0, 1, 'C');

$pdf->SetX(85);
$pdf->Cell(150, 5, 'Province of Surigal del Norte', 0, 1, 'C');

$pdf->SetFont('Times', 'B', 12);
$pdf->SetX(85);
$pdf->Cell(150, 6, 'MUNICIPALITY OF PLACER', 0, 1, 'C');

$pdf->SetFont('Times', 'B', 10);
$pdf->SetX(85);
$pdf->Cell(150, 5, 'OFFICE OF THE MUNICIPAL MAYOR', 0, 1, 'C');


// =========================================================
// YEAR
// =========================================================
$pdf->SetFont('Times', 'BI', 45);
$pdf->SetXY($pageW - 55, 15);
$pdf->Cell(25, 25, (string)$taxYear, 0, 0, 'R');


// =========================================================
// SALUTATION
// =========================================================
$pdf->SetFont('Times', 'I', 18);
$pdf->SetXY(85, 45);
$pdf->Cell(0, 8, 'To Whom It May Concern:', 0, 1, 'L');

// =========================================================
// RECITAL
// =========================================================
$pdf->SetFont('Times', 'I', 11);
$pdf->SetXY(85, 65);

$recital =
    'This is to certify that ' .  
    strtoupper($businessName) .
    '/' .
    strtoupper($ownerName) .
    ' located at Barangay ' .
    strtoupper($businessAddress) .
    ' after having complied ' .
    'with existing regulations and paid the corresponding fees ' .
    'as required by law and ordinances is hereby granted this.';

$pdf->MultiCell(190, 5.5, utf8_decode($recital), 0, 'L');


// =========================================================
// BUSINESS PERMIT STAMP
// =========================================================
$pdf->SetFont('Times', 'B', 55);
$pdf->SetTextColor($blue[0], $blue[1], $blue[2]);
$pdf->SetXY(25, 95);
$pdf->Cell($pageW, 18, 'BUSINESS PERMIT', 0, 1, 'C');


// =========================================================
// RESET TEXT COLOR
// =========================================================
$pdf->SetTextColor($ink[0], $ink[1], $ink[2]);
$pdf->SetDrawColor($ink[0], $ink[1], $ink[2]);
$pdf->SetLineWidth(0.4);


// =========================================================
// LOWER SECTION - LEFT COLUMN
// =========================================================
$boxX = 22;
$boxY = 124;
$boxW = 52;
$boxH = 55;

$pdf->Rect($boxX, $boxY, $boxW, $boxH);


// =========================================================
// OR NUMBER
// =========================================================
$pdf->SetFont('Times', 'B', 8);
$pdf->SetXY($boxX, $boxY + 2);
$pdf->Cell($boxW, 5, 'OR#  ' . $orNumber, 0, 0, 'C');


// =========================================================
// QUARTERS
// =========================================================
$labels = [
    'First Quarter',
    'Second Quarter',
    'Third Quarter',
    'Fourth Quarter'
];

$qH = ($boxH - 9) / 4;

for ($i = 0; $i < 4; $i++) {

    $y = $boxY + 9 + ($i * $qH);

    $pdf->Line($boxX, $y, $boxX + $boxW, $y);

    // Label
    $pdf->SetFont('Times', 'B', 7);
    $pdf->SetTextColor($boxInk[0], $boxInk[1], $boxInk[2]);
    $pdf->SetXY($boxX, $y + 1.5);
    $pdf->Cell($boxW, 4, $labels[$i], 0, 0, 'C');

    // Value
    $pdf->SetFont('Times', 'B', 9);
    $pdf->SetTextColor($ink[0], $ink[1], $ink[2]);
    $pdf->SetXY($boxX, $y + 6);
    $pdf->Cell($boxW, 4, 'X X X X X', 0, 0, 'C');
}


// =========================================================
// APPLICATION TYPE LABEL (DYNAMIC)
// =========================================================
$pdf->SetFont('Times', 'B', 12);
$pdf->SetXY($boxX + 15, $boxY + $boxH + 3);
$pdf->Cell($boxW, 6, $applicationTypeLabel, 0, 0, 'L');


// =========================================================
// LOWER SECTION - RIGHT COLUMN
// =========================================================
$rightX = 88;
$rightW = $pageW - $rightX - 22;


// =========================================================
// GRANT PARAGRAPH
// =========================================================
$pdf->SetFont('Times', 'I', 10.5);
$pdf->SetXY($rightX, 124);

$grant =
    'Authorizing him/her to engage in ' .
    strtoupper($lineOfBusiness) .
    ' for the quarter indicated below as attested by ' .
    'Municipal Treasurer or his duly authorized ' .
    'representative unless sooner revoked or cancelled ' .
    'for cause, which would thereby subject the fees paid ' .
    'therefore to be forfeited.';

$pdf->MultiCell($rightW, 5, utf8_decode($grant), 0, 'L');


// =========================================================
// SIGNATORY
// =========================================================
$sigW = 65;
$sigX = $rightX + $rightW - $sigW;
$sigY = 155;

$pdf->SetFont('Times', 'B', 11);
$pdf->SetXY($sigX, $sigY + 3);
$pdf->Cell($sigW, 5, 'JOVYMARIE C. VILLASON', 0, 0, 'C');

$pdf->Line($sigX, $sigY + 9, $sigX + $sigW, $sigY + 9);

$pdf->SetFont('Times', '', 9.5);
$pdf->SetXY($sigX, $sigY + 10);
$pdf->Cell($sigW, 5, 'Municipal Mayor', 0, 0, 'C');


// =========================================================
// PERMIT NO.
// =========================================================
$pdf->SetFont('Times', 'B', 10);
$pdf->SetXY($rightX, 183);
$pdf->Cell(0, 5, 'PERMIT NO. :  ' . $permitNo, 0, 0, 'L');


// =========================================================
// NOTE
// =========================================================
$pdf->SetFont('Times', '', 9);
$pdf->SetXY($rightX, 190);
$pdf->Cell(15, 5, 'NOTE:', 0, 0, 'L');

$pdf->SetXY($rightX + 15, 190);
$pdf->MultiCell(
    $rightW - 15,
    5,
    utf8_decode('This permit shall be posted in a conspicuous place and brought to the Office of the Municipal Treasurer every renewal.'),
    0,
    'L'
);


// =========================================================
// OUTPUT
// =========================================================
$pdf->Output('I', 'business_permit_' . $app_id . '.pdf');
exit;