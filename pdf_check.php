<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);          // errors go to log, not to the PDF stream
ini_set('log_errors', 1);

/* =====================================================================
   Find FPDF anywhere
   ===================================================================== */
$fpdfCandidates = [
    __DIR__ . '/fpdf/fpdf.php',
    __DIR__ . '/fpdf19/fpdf.php',
    __DIR__ . '/FPDF/fpdf.php',
    __DIR__ . '/vendor/fpdf/fpdf.php',
    __DIR__ . '/libs/fpdf/fpdf.php',
    __DIR__ . '/../fpdf/fpdf.php',
];
$fpdfPath = null;
foreach ($fpdfCandidates as $p) {
    if (file_exists($p)) { $fpdfPath = $p; break; }
}
if (!$fpdfPath) {
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8', true, 500);
    echo '<h2 style="font-family:sans-serif">Cannot generate PDF</h2>';
    echo '<p>The FPDF library was not found in any expected location:</p><ul>';
    foreach ($fpdfCandidates as $p) echo '<li><code>' . htmlspecialchars($p) . '</code></li>';
    echo '</ul><p>Download FPDF from <a href="https://www.fpdf.org/">fpdf.org</a>, '
       . 'extract the zip, and upload the <code>fpdf</code> folder to your project root.</p>';
    exit;
}
require_once $fpdfPath;
if (!class_exists('FPDF')) {
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8', true, 500);
    echo '<h2 style="font-family:sans-serif">FPDF class not defined</h2>';
    echo '<p>The file <code>' . htmlspecialchars($fpdfPath) . '</code> does not define class FPDF.</p>';
    exit;
}

/* =====================================================================
   Custom PDF class — no reliance on optional methods
   ===================================================================== */
class UserManualPDF extends FPDF
{
    /* Palette (as arrays, no spread operators) */
    public $navy   = [23, 34, 51];
    public $navy2  = [42, 59, 82];
    public $yellow = [251, 191, 36];
    public $green  = [0, 195, 137];
    public $blue   = [59, 130, 246];
    public $purple = [139, 92, 246];
    public $gray   = [107, 114, 128];
    public $dark   = [17, 24, 39];
    public $white  = [255, 255, 255];
    public $border = [229, 231, 235];

    public $inFrontMatter = true;

    /* ----- Color helpers (avoids ...array syntax entirely) ----- */
    function fillColor($c)  { $this->SetFillColor($c[0], $c[1], $c[2]); }
    function textColor($c)  { $this->SetTextColor($c[0], $c[1], $c[2]); }
    function drawColor($c)  { $this->SetDrawColor($c[0], $c[1], $c[2]); }

    /* ----- Ellipse polyfill (works on vanilla FPDF) ----- */
    function Ellipse($x, $y, $w, $h = 0, $style = '')
    {
        if ($h === 0) $h = $w;
        $k  = $this->k;
        $hp = $this->h;

        $op = 'S';
        if ($style === 'F')  $op = 'f';
        elseif ($style === 'FD' || $style === 'DF') $op = 'B';

        $myArc = 4 / 3 * (sqrt(2) - 1);
        $cx    = $x + $w / 2;
        $cy    = $y + $h / 2;
        $rx    = $w / 2;
        $ry    = $h / 2;

        // start at right-middle
        $this->_out(sprintf('%.2F %.2F m', ($cx + $rx) * $k, ($hp - $cy) * $k));

        // right → bottom
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($cx + $rx) * $k, ($hp - ($cy + $ry * $myArc)) * $k,
            ($cx + $rx * $myArc) * $k, ($hp - ($cy + $ry)) * $k,
            $cx * $k, ($hp - ($cy + $ry)) * $k
        ));
        // bottom → left
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($cx - $rx * $myArc) * $k, ($hp - ($cy + $ry)) * $k,
            ($cx - $rx) * $k, ($hp - ($cy + $ry * $myArc)) * $k,
            ($cx - $rx) * $k, ($hp - $cy) * $k
        ));
        // left → top
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($cx - $rx) * $k, ($hp - ($cy - $ry * $myArc)) * $k,
            ($cx - $rx * $myArc) * $k, ($hp - ($cy - $ry)) * $k,
            $cx * $k, ($hp - ($cy - $ry)) * $k
        ));
        // top → right
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($cx + $rx * $myArc) * $k, ($hp - ($cy - $ry)) * $k,
            ($cx + $rx) * $k, ($hp - ($cy - $ry * $myArc)) * $k,
            ($cx + $rx) * $k, ($hp - $cy) * $k
        ));
        $this->_out($op);
    }

    /* ----- Header / Footer ----- */
    function Header()
    {
        if ($this->inFrontMatter) return;
        $this->fillColor($this->navy);
        $this->Rect(0, 0, 210, 10, 'F');

        $this->SetFont('Helvetica', 'B', 7.5);
        $this->textColor($this->white);
        $this->SetXY(15, 3);
        $this->Cell(90, 4, 'BUYAWANONG PLACER  |  SYSTEM USER MANUAL', 0, 0, 'L');
        $this->SetXY(105, 3);
        $this->Cell(90, 4, 'Municipality of Placer, Surigao del Norte', 0, 0, 'R');
        $this->SetY(18);
    }

    function Footer()
    {
        if ($this->inFrontMatter) return;
        $this->SetY(-15);
        $this->drawColor($this->border);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetFont('Helvetica', '', 8);
        $this->textColor($this->gray);
        $this->SetXY(15, $this->GetY() + 3);
        $this->Cell(90, 5, 'Buyawanong Placer  |  User Manual v1.0', 0, 0, 'L');
        $this->SetXY(105, $this->GetY());
        $this->Cell(90, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }

    /* ----- Cover ----- */
    function renderCover()
    {
        $this->inFrontMatter = true;

        $this->fillColor($this->navy);
        $this->Rect(0, 0, 210, 297, 'F');

        $this->fillColor($this->yellow);
        $this->Rect(0, 244, 210, 3, 'F');
        $this->Rect(0, 292, 210, 5, 'F');

        $this->fillColor([255, 255, 255]);
        $this->Ellipse(105, 60, 26, 26, 'F');
        $this->SetFont('Helvetica', 'B', 24);
        $this->textColor($this->navy);
        $this->SetXY(85, 52);
        $this->Cell(40, 16, 'BP', 0, 0, 'C');

        $this->SetFont('Helvetica', 'B', 10);
        $this->textColor($this->yellow);
        $this->SetXY(0, 108);
        $this->Cell(210, 6, 'MUNICIPALITY OF PLACER - SURIGAO DEL NORTE', 0, 1, 'C');

        $this->SetFont('Helvetica', 'B', 34);
        $this->textColor($this->white);
        $this->SetXY(0, 120);
        $this->Cell(210, 16, 'BUYAWANONG', 0, 1, 'C');
        $this->SetXY(0, 136);
        $this->Cell(210, 16, 'PLACER', 0, 1, 'C');

        $this->SetFont('Helvetica', 'B', 14);
        $this->textColor($this->yellow);
        $this->SetXY(0, 160);
        $this->Cell(210, 8, 'SYSTEM USER MANUAL', 0, 1, 'C');

        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(200, 210, 225);
        $this->SetXY(30, 172);
        $this->MultiCell(150, 5,
            'A Web-Based One-Stop Digital Information Portal for the Municipality of Placer',
            0, 'C');

        $this->SetFont('Helvetica', 'B', 10);
        $this->textColor($this->yellow);
        $this->SetXY(0, 255);
        $this->Cell(210, 6, 'VERSION 1.0', 0, 1, 'C');

        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(160, 175, 195);
        $this->SetXY(0, 262);
        $this->Cell(210, 5, 'Developed for the Municipality of Placer, Surigao del Norte', 0, 1, 'C');
        $this->SetXY(0, 268);
        $this->Cell(210, 5, 'North Eastern Mindanao State University - Cantilan Campus', 0, 1, 'C');
    }

    /* ----- TOC ----- */
    function renderTOC()
    {
        $this->inFrontMatter = true;

        $this->fillColor($this->navy);
        $this->Rect(0, 0, 210, 42, 'F');

        $this->SetFont('Helvetica', 'B', 22);
        $this->textColor($this->white);
        $this->SetXY(15, 16);
        $this->Cell(180, 10, 'TABLE OF CONTENTS', 0, 1, 'L');

        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(200, 210, 225);
        $this->SetXY(15, 28);
        $this->Cell(180, 6, 'Buyawanong Placer - System User Manual', 0, 1, 'L');

        $y = 55;
        $blocks = [
            [1, 'Business Owner Guide',
             'Account registration, login, dashboard, permit application, tracking and claiming.',
             $this->green],
            [2, 'BPLO Staff Guide',
             'Reviewing applications, managing appointments and creating announcements.',
             $this->blue],
            [3, 'Treasury Staff Guide',
             'Assessing permit fees, verifying and monitoring payments, printing receipts.',
             $this->yellow],
            [4, 'System Administrator Guide',
             'Managing employees, verifying user accounts, moderating announcements, editing portal content.',
             $this->purple],
        ];
        foreach ($blocks as $b) {
            list($num, $title, $desc, $color) = $b;

            $this->fillColor($color);
            $this->Rect(15, $y, 12, 12, 'F');
            $this->SetFont('Helvetica', 'B', 12);
            $this->textColor($this->white);
            $this->SetXY(15, $y + 2);
            $this->Cell(12, 8, (string)$num, 0, 0, 'C');

            $this->SetFont('Helvetica', 'B', 13);
            $this->textColor($this->navy);
            $this->SetXY(32, $y);
            $this->Cell(160, 6, $title, 0, 1, 'L');

            $this->SetFont('Helvetica', '', 9);
            $this->textColor($this->gray);
            $this->SetXY(32, $y + 6);
            $this->MultiCell(160, 4, $desc, 0, 'L');

            $this->drawColor($this->border);
            $this->Line(15, $y + 17, 195, $y + 17);
            $y += 22;
        }

        $this->SetFont('Helvetica', 'I', 8.5);
        $this->textColor($this->gray);
        $this->SetXY(15, 270);
        $this->MultiCell(180, 4,
            'Tip: Use the PDF bookmarks (left panel of most readers) to jump to any section.',
            0, 'C');
    }

    /* ----- Section divider ----- */
    function renderSectionDivider($num, $title, $subtitle, $color)
    {
        $this->inFrontMatter = true;

        $this->fillColor($color);
        $this->Rect(0, 0, 210, 90, 'F');
        $this->fillColor($this->navy);
        $this->Rect(0, 90, 210, 6, 'F');

        $this->SetFont('Helvetica', 'B', 72);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(20, 28);
        $this->Cell(60, 40, (string)$num, 0, 0, 'L');

        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(20, 68);
        $this->Cell(180, 5, 'SECTION ' . $num, 0, 1, 'L');

        $this->SetFont('Helvetica', 'B', 24);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(20, 74);
        $this->Cell(180, 12, $title, 0, 1, 'L');

        $this->SetFont('Helvetica', '', 11);
        $this->textColor($this->gray);
        $this->SetXY(20, 105);
        $this->MultiCell(170, 6, $subtitle, 0, 'L');

        $this->SetY(120);
        $this->inFrontMatter = false;
    }

    /* ----- Sub-heading ----- */
    function subHeading($num, $title, $color)
    {
        $y = $this->GetY();
        if ($y > 250) { $this->AddPage(); $y = $this->GetY(); }

        $this->fillColor($color);
        $this->Rect(15, $y + 1, 3, 7, 'F');

        $this->SetFont('Helvetica', 'B', 12.5);
        $this->textColor($this->navy);
        $this->SetXY(22, $y);
        $this->Cell(170, 8, $num . '  ' . $title, 0, 1, 'L');

        $this->drawColor($this->border);
        $this->Line(15, $y + 10, 195, $y + 10);
        $this->SetY($y + 14);
    }

    function paragraph($text, $size = 10)
    {
        $this->SetFont('Helvetica', '', $size);
        $this->textColor($this->dark);
        $this->SetX(15);
        $this->MultiCell(180, 5.5, $text, 0, 'L');
        $this->Ln(2);
    }

    function infoBox($title, $text, $type = 'info')
    {
        $map = [
            'info' => $this->blue,
            'tip'  => $this->green,
            'warn' => $this->yellow,
            'note' => $this->gray,
        ];
        $color = isset($map[$type]) ? $map[$type] : $this->blue;

        $y = $this->GetY();
        if ($y > 250) { $this->AddPage(); $y = $this->GetY(); }

        $this->fillColor([248, 250, 252]);
        $this->Rect(15, $y, 180, 16, 'F');

        $this->fillColor($color);
        $this->Rect(15, $y, 2.5, 16, 'F');

        $this->SetFont('Helvetica', 'B', 9.5);
        $this->textColor($color);
        $this->SetXY(21, $y + 2);
        $this->Cell(170, 4, strtoupper($title), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 9.5);
        $this->textColor($this->dark);
        $this->SetXY(21, $y + 7);
        $this->MultiCell(170, 4.5, $text, 0, 'L');

        $this->SetY($y + 20);
    }

    function step($num, $text, $color)
    {
        $y = $this->GetY();
        if ($y > 260) { $this->AddPage(); $y = $this->GetY(); }

        $this->fillColor($color);
        $this->Ellipse(19.5, $y + 3.5, 3.5, 3.5, 'F');

        $this->SetFont('Helvetica', 'B', 9);
        $this->textColor($this->white);
        $this->SetXY(16, $y + 1.4);
        $this->Cell(7, 5, (string)$num, 0, 0, 'C');

        $this->SetFont('Helvetica', '', 10);
        $this->textColor($this->dark);
        $this->SetXY(28, $y + 0.5);
        $this->MultiCell(162, 5.5, $text, 0, 'L');

        $this->SetY(max($y + 8, $this->GetY() + 2));
    }

    function featureCards($items, $color)
    {
        foreach ($items as $it) {
            $y = $this->GetY();
            if ($y > 245) { $this->AddPage(); $y = $this->GetY(); }

            $this->fillColor([248, 250, 252]);
            $this->Rect(15, $y, 180, 14, 'F');
            $this->drawColor($this->border);
            $this->Rect(15, $y, 180, 14, 'D');

            $this->fillColor($color);
            $this->Rect(18, $y + 3, 8, 8, 'F');

            $this->SetFont('Helvetica', 'B', 10.5);
            $this->textColor($this->navy);
            $this->SetXY(30, $y + 2);
            $this->Cell(160, 5, $it['title'], 0, 1, 'L');

            $this->SetFont('Helvetica', '', 9);
            $this->textColor($this->gray);
            $this->SetXY(30, $y + 7);
            $this->MultiCell(160, 4.5, $it['desc'], 0, 'L');

            $this->SetY($y + 17);
        }
        $this->Ln(2);
    }
}

/* =====================================================================
   BUILD PDF
   ===================================================================== */
try {
    $pdf = new UserManualPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetMargins(15, 18, 15);
    $pdf->AliasNbPages();

    /* Cover + TOC */
    $pdf->AddPage(); $pdf->renderCover();
    $pdf->AddPage(); $pdf->renderTOC();

    /* ---------------- SECTION 1 ---------------- */
    $pdf->AddPage();
    $pdf->renderSectionDivider(1, 'Business Owner Guide',
        'This section guides business owners through creating an account, applying for permits, and managing their transactions.',
        $pdf->green);

    $pdf->subHeading('1.1', 'Account Registration', $pdf->green);
    $pdf->paragraph('Create your Buyawanong Placer account to start applying for business permits online.');
    $pdf->step(1, 'On the portal homepage, click the "Log in" button on the top-right corner.', $pdf->green);
    $pdf->step(2, 'In the login modal, select the "Create account" tab.', $pdf->green);
    $pdf->step(3, 'Enter your First Name, Last Name, Mobile Number, Email address and Password (at least 8 characters). Re-enter the password in the Confirm password field.', $pdf->green);
    $pdf->step(4, 'Click the "Create account" button.', $pdf->green);
    $pdf->step(5, 'Check your inbox for the One-Time Password (OTP). Enter the 6-digit code in the verification modal.', $pdf->green);
    $pdf->step(6, 'Click "Verify & Register" to complete registration.', $pdf->green);
    $pdf->infoBox('Note', 'Only business owners operating within the Municipality of Placer may register on this portal.', 'warn');

    $pdf->subHeading('1.2', 'Logging In & Password Reset', $pdf->green);
    $pdf->paragraph('To log in, open the login modal, select "Log in", enter your registered email and password, then click "Log in".');
    $pdf->paragraph('If you have forgotten your password, click "Forgot password?" below the password field. Enter your registered email and click "Send verification code". Check your inbox for the 6-digit reset code, enter it, and set a new password.');

    $pdf->subHeading('1.3', 'Navigating Your Dashboard', $pdf->green);
    $pdf->paragraph('Upon login you will land on your User Dashboard, which shows a summary of your applications and payments.');
    $pdf->featureCards([
        ['title' => 'Summary Widgets',     'desc' => 'Total Applications, In Review, Pending Payments and Approved Permits are shown at the top.'],
        ['title' => 'Recent Applications', 'desc' => 'Displays your latest applications, their status, date filed and expiry.'],
        ['title' => 'Payment History',     'desc' => 'Shows every PayPal transaction with amount, reference number and receipt.'],
        ['title' => 'Notifications Bell',  'desc' => 'Click the bell icon for real-time notifications (Permit Verified, Payment Received, Fee Assessment).'],
        ['title' => 'Account Settings',    'desc' => 'Click your profile name and select Account Settings to update your name, contact number or password.'],
    ], $pdf->green);

    $pdf->subHeading('1.4', 'Applying for a Business Permit', $pdf->green);
    $pdf->paragraph('From your dashboard, click "New Application" (or "Renew" if renewing an existing permit). The application has 8 steps:');
    $pdf->step(1, 'Requirements - Review the 8 mandatory document checklist and click Continue.', $pdf->green);
    $pdf->step(2, 'Application Type - Select New, Renewal or Amendment. Choose your Mode of Payment and enter DTI/SEC Registration No. and TIN No.', $pdf->green);
    $pdf->step(3, 'Business Info - Enter Owner Name, Business Name, Trade Name and Business Activity details.', $pdf->green);
    $pdf->step(4, 'Business Address - Pin your business location on the map. Fill in address and employee details.', $pdf->green);
    $pdf->step(5, 'Owner Info - Enter the owner\'s home address and emergency contact details.', $pdf->green);
    $pdf->step(6, 'Additional Info - Verify the Application No., enter Total Floor Area, Establishment Address, and check the declaration box.', $pdf->green);
    $pdf->step(7, 'Documents - Upload each required document (Barangay Clearance, DTI/SEC, RPT, Engineering, Zoning, Treasury, Sanitary, Fire Safety).', $pdf->green);
    $pdf->step(8, 'Review and Submit - Review all information, check the two declaration boxes and click "Submit application".', $pdf->green);
    $pdf->infoBox('Tip', 'Incomplete submissions are returned to the applicant. Double-check all required documents before submitting.', 'tip');

    $pdf->subHeading('1.5', 'Tracking Your Application', $pdf->green);
    $pdf->paragraph('You can track your application at any time - no login needed.');
    $pdf->step(1, 'Go to the "Track an application" section on the Permit page.', $pdf->green);
    $pdf->step(2, 'Enter your Reference Number (e.g., BP-2026-00087) or click the QR Code Scanner icon to scan the QR code on your receipt.', $pdf->green);
    $pdf->step(3, 'Click "Track" to view the real-time Application Progress timeline.', $pdf->green);

    $pdf->subHeading('1.6', 'Viewing Your Permit and Scheduling a Claim', $pdf->green);
    $pdf->step(1, 'Once approved, scroll down on your application view to see your Mayor\'s Business Permit and unique QR Code.', $pdf->green);
    $pdf->step(2, 'Click "Print Permit" or "Download PDF" to save a copy.', $pdf->green);
    $pdf->step(3, 'To claim the physical permit, click "Schedule Appointment".', $pdf->green);
    $pdf->step(4, 'Select an available Date and Time from the calendar and click "Confirm".', $pdf->green);
    $pdf->infoBox('Reminder', 'Bring a valid government-issued ID and your reference number when claiming your permit at the Municipal Hall. Office hours: 8:00 AM - 5:00 PM (closed 12:00 - 1:00 PM).', 'warn');

    /* ---------------- SECTION 2 ---------------- */
    $pdf->AddPage();
    $pdf->renderSectionDivider(2, 'BPLO Staff Guide',
        'This section guides Business Permits and Licensing Office personnel through their daily tasks.',
        $pdf->blue);

    $pdf->subHeading('2.1', 'Reviewing & Approving Applications', $pdf->blue);
    $pdf->step(1, 'On your BPLO Dashboard, locate the "Needs your review" table.', $pdf->blue);
    $pdf->step(2, 'Click the "Review documents" button for a specific application.', $pdf->blue);
    $pdf->step(3, 'A split-screen modal opens. Use the left panel to read the submitted ANNEX 1 form.', $pdf->blue);
    $pdf->step(4, 'Use the right panel to click and download the uploaded documents.', $pdf->blue);
    $pdf->step(5, 'Click "Approve" to forward to Treasury, OR "Reject" and select the missing documents.', $pdf->blue);
    $pdf->infoBox('Tip', 'When you verify an application, the applicant is automatically notified by email and can then schedule a pickup.', 'tip');

    $pdf->subHeading('2.2', 'Managing Appointments', $pdf->blue);
    $pdf->step(1, 'Click "Appointments" on the left sidebar.', $pdf->blue);
    $pdf->step(2, 'Toggle between Calendar View and List View using the filter buttons.', $pdf->blue);
    $pdf->step(3, 'Locate a "Pending" appointment and click "Confirm" or "Cancel".', $pdf->blue);
    $pdf->step(4, 'Confirmed applicants receive a notification about their scheduled visit.', $pdf->blue);

    $pdf->subHeading('2.3', 'Creating Announcements', $pdf->blue);
    $pdf->step(1, 'Click "Announcements" on the left sidebar.', $pdf->blue);
    $pdf->step(2, 'Click the "Add announcement" button.', $pdf->blue);
    $pdf->step(3, 'Enter the Title and Content.', $pdf->blue);
    $pdf->step(4, 'Click "Submit for approval" - an admin must approve it before it appears on the public homepage.', $pdf->blue);

    /* ---------------- SECTION 3 ---------------- */
    $pdf->AddPage();
    $pdf->renderSectionDivider(3, 'Treasury Staff Guide',
        'This section guides Municipal Treasurer\'s Office personnel through financial processing.',
        $pdf->yellow);

    $pdf->subHeading('3.1', 'Assessing Permit Fees', $pdf->yellow);
    $pdf->step(1, 'On your Treasury Dashboard, check the "Pending Payment" summary card.', $pdf->yellow);
    $pdf->step(2, 'Click "Fee Assessment" on the left sidebar.', $pdf->yellow);
    $pdf->step(3, 'Locate the approved application and click "Re-assess".', $pdf->yellow);
    $pdf->step(4, 'Review the fixed fees and taxes displayed.', $pdf->yellow);
    $pdf->step(5, 'Click "Save Assessment". The system will notify the applicant to proceed with payment.', $pdf->yellow);
    $pdf->infoBox('Note', 'Fixed values are pre-filled based on the application type. New applications use a standard fixed fee structure.', 'warn');

    $pdf->subHeading('3.2', 'Verifying Payments', $pdf->yellow);
    $pdf->step(1, 'Click "Payment Verification" on the left sidebar.', $pdf->yellow);
    $pdf->step(2, 'Locate the applicant who has initiated a PayPal payment.', $pdf->yellow);
    $pdf->step(3, 'Cross-check the amount and click "Confirm".', $pdf->yellow);
    $pdf->step(4, 'Once confirmed, the application is forwarded back to BPLO for final verification and permit issuance.', $pdf->yellow);

    $pdf->subHeading('3.3', 'Monitoring Payments & Treasury Records', $pdf->yellow);
    $pdf->step(1, 'Click "Monitor Payments" to view the line graph of monthly collections and PayPal transactions.', $pdf->yellow);
    $pdf->step(2, 'Click "Treasury Records" to view and print official digital receipts.', $pdf->yellow);
    $pdf->step(3, 'Use the search bar to filter by business name, reference number or date.', $pdf->yellow);

    /* ---------------- SECTION 4 ---------------- */
    $pdf->AddPage();
    $pdf->renderSectionDivider(4, 'System Administrator Guide',
        'This section guides the System Administrator in managing the overall portal.',
        $pdf->purple);

    $pdf->subHeading('4.1', 'Managing Employee Accounts', $pdf->purple);
    $pdf->step(1, 'Click "Employees" on the left sidebar.', $pdf->purple);
    $pdf->step(2, 'Click the "Add Employee" button.', $pdf->purple);
    $pdf->step(3, 'Enter the staff\'s Name, Email and Contact. Select their Role (Treasury Staff, BPLO Staff, Admin).', $pdf->purple);
    $pdf->step(4, 'Click "Add & Send Verification". A verification email with a password is sent automatically.', $pdf->purple);
    $pdf->step(5, 'To edit a role, click the Edit (pencil) icon next to their name.', $pdf->purple);

    $pdf->subHeading('4.2', 'Verifying User Accounts', $pdf->purple);
    $pdf->step(1, 'Click "User Accounts" on the left sidebar.', $pdf->purple);
    $pdf->step(2, 'Locate the registered Business Owner.', $pdf->purple);
    $pdf->step(3, 'Toggle the "Verified" switch to the ON position to allow them to log in.', $pdf->purple);
    $pdf->infoBox('Note', 'Only verified accounts can log in and apply for permits.', 'warn');

    $pdf->subHeading('4.3', 'Managing Announcements', $pdf->purple);
    $pdf->step(1, 'Click "Announcements" on the left sidebar.', $pdf->purple);
    $pdf->step(2, 'Review the list of pending announcements submitted by BPLO staff.', $pdf->purple);
    $pdf->step(3, 'Click the Check (Approve) icon to publish, or the Trash (Reject) icon to decline.', $pdf->purple);

    $pdf->subHeading('4.4', 'Editing Portal Content (System Settings)', $pdf->purple);
    $pdf->step(1, 'Click "System Settings" on the left sidebar.', $pdf->purple);
    $pdf->step(2, 'Select the page tab you want to edit (Home Page, About Page, etc.).', $pdf->purple);
    $pdf->step(3, 'Click into the text fields to edit the Hero Title, Hero Subtitle, or Info Cards.', $pdf->purple);
    $pdf->step(4, 'Click "Save Changes" to update the live website.', $pdf->purple);

    /* ---------------- Back cover ---------------- */
    $pdf->AddPage();
    $pdf->inFrontMatter = true;
    $pdf->fillColor($pdf->navy);
    $pdf->Rect(0, 0, 210, 297, 'F');
    $pdf->fillColor($pdf->yellow);
    $pdf->Rect(0, 0, 210, 4, 'F');

    $pdf->SetFont('Helvetica', 'B', 22);
    $pdf->textColor($pdf->yellow);
    $pdf->SetXY(0, 100);
    $pdf->Cell(210, 12, 'THANK YOU', 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 12);
    $pdf->SetTextColor(200, 210, 225);
    $pdf->SetXY(30, 120);
    $pdf->MultiCell(150, 6,
        'Thank you for using Buyawanong Placer - the Municipality of Placer\'s one-stop digital information portal.',
        0, 'C');

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->textColor($pdf->white);
    $pdf->SetXY(0, 200);
    $pdf->Cell(210, 5, 'MUNICIPALITY OF PLACER', 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(160, 175, 195);
    $pdf->SetXY(0, 206); $pdf->Cell(210, 5, 'Surigao del Norte, Philippines', 0, 1, 'C');
    $pdf->SetXY(0, 218); $pdf->Cell(210, 5, 'bploplacer@gmail.com  |  treasuryofficeplacer@gmail.com', 0, 1, 'C');
    $pdf->SetXY(0, 224); $pdf->Cell(210, 5, 'Mon-Fri, 8:00 AM - 5:00 PM', 0, 1, 'C');

    /* ---------------- Ship it ---------------- */
    ob_end_clean();
    $preview = isset($_GET['preview']) && $_GET['preview'] === '1';
    $pdf->Output($preview ? 'I' : 'D', 'Buyawanong_Placer_User_Manual.pdf');
    exit;

} catch (Throwable $e) {
    // Show the real error as HTML so the browser doesn't show "unknown server error"
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8', true, 500);
    echo '<h2 style="font-family:sans-serif;color:#c00">PDF generation failed</h2>';
    echo '<p style="font-family:sans-serif"><b>Error:</b> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p style="font-family:sans-serif"><b>At:</b> ' . htmlspecialchars($e->getFile()) . ' : line ' . $e->getLine() . '</p>';
    echo '<pre style="background:#f5f5f5;padding:1rem;overflow:auto">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    exit;
}