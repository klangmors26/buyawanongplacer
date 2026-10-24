<?php
session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
    $_SESSION['error'] = 'Access denied. Staff only.';
    header('Location: ../login.php');
    exit;
}

$staff_id   = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'LGU Staff';
$user_type  = (int)$_SESSION['user_type'];

require_once '../config/connect.php';

// ============================================================
// LOAD ACCOUNT DATA
// ============================================================
$acctStmt = $conn->prepare("SELECT firstname, lastname, email, contact_number FROM accounts WHERE id = ?");
$acctStmt->bind_param("i", $staff_id);
$acctStmt->execute();
$acctRow = $acctStmt->get_result()->fetch_assoc();
$acctStmt->close();
$acctData = [
    'firstname' => $acctRow['firstname']      ?? '',
    'lastname'  => $acctRow['lastname']       ?? '',
    'email'     => $acctRow['email']          ?? '',
    'contact'   => $acctRow['contact_number'] ?? '',
];

// ============================================================
// NOTIFICATIONS
// ============================================================
require_once __DIR__ . '/../notifications_helper.php';
$staff_unreadCount   = countUnreadNotifications($conn, $staff_id);
$staff_notifications = getUnreadNotifications($conn, $staff_id, 5);

$role_label = ($user_type == 4) ? 'Licensing and approval staff' : 'Treasury Staff';
$headerLogo    = ($user_type == 4) ? 'bplo-logo2.png' : 'treasury-logo.png';
$headerLogoAlt = ($user_type == 4) ? 'BPLO Logo' : 'Treasury Office Logo';

$showPayments            = ($user_type == 3);
$showPaymentVerification = ($user_type == 3);
$showApprovalDocs        = ($user_type == 4);
$showAnnouncements       = true;
$showTreasuryRecords     = ($user_type == 3);
$showAppointments        = ($user_type == 4);
$showActivityLog         = ($user_type == 4);

// ============================================================
// STATUS FILTER
// ============================================================
if ($user_type == 4) {
    $statusFilter = "WHERE LOWER(ba.status) IN ('submitted', 'payment_confirmed')";
} elseif ($user_type == 3) {
    $statusFilter = "WHERE LOWER(ba.status) = 'pending_payment'";
} else {
    $statusFilter = "";
}

// ============================================================
// STATS
// ============================================================
$stats = ['total'=>0, 'pending_review'=>0, 'pending_payment'=>0, 'approved_issued'=>0];
$statQueries = [
    'total'           => "SELECT COUNT(*) AS count FROM business_applications",
    'pending_review'  => "SELECT COUNT(*) AS count FROM business_applications WHERE LOWER(status) = 'payment_confirmed'",
    'pending_payment' => "SELECT COUNT(*) AS count FROM business_applications WHERE LOWER(status) = 'pending_payment'",
    'approved_issued' => "SELECT COUNT(*) AS count FROM business_applications WHERE LOWER(status) IN ('approved','issued','verified','claimed')",
];
foreach ($statQueries as $key => $sql) {
    $r = $conn->query($sql);
    if ($r && $row = $r->fetch_assoc()) $stats[$key] = (int)$row['count'];
}

// ============================================================
// DASHBOARD CARD DATA
// ============================================================
$dashboardCardData = ['total'=>[], 'pending_review'=>[], 'pending_payment'=>[], 'approved_issued'=>[]];
$dcQ = "
    SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
           bi.business_name, at.application_type, fa.total_amount AS amount
    FROM business_applications ba
    LEFT JOIN business_info_data bi ON ba.id = bi.application_id
    LEFT JOIN application_type_data at ON ba.id = at.application_id
    LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
    WHERE ba.status != 'draft'
    ORDER BY ba.application_date DESC
";
$dcRes = $conn->query($dcQ);
if ($dcRes) {
    while ($row = $dcRes->fetch_assoc()) {
        $s = strtolower($row['status']);
        $dashboardCardData['total'][] = $row;
        if ($s === 'payment_confirmed')                           $dashboardCardData['pending_review'][]  = $row;
        if ($s === 'pending_payment')                             $dashboardCardData['pending_payment'][] = $row;
        if (in_array($s, ['approved','issued','verified','claimed'], true)) $dashboardCardData['approved_issued'][]  = $row;
    }
}

// ============================================================
// RECENT APPLICATIONS
// ============================================================
$recentApps = [];
$recentSql = "
    SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
           bi.business_name, at.application_type, fa.total_amount AS amount
    FROM business_applications ba
    LEFT JOIN business_info_data bi ON ba.id = bi.application_id
    LEFT JOIN application_type_data at ON ba.id = at.application_id
    LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
    $statusFilter
    ORDER BY ba.application_date DESC, ba.id DESC
    LIMIT 10
";
$recentResult = $conn->query($recentSql);
if ($recentResult) while ($row = $recentResult->fetch_assoc()) $recentApps[] = $row;

// ============================================================
// DASHBOARD TABLES (user_type 4)
// ============================================================
$needsReviewApps = [];
$waitingOnApplicantsApps = [];

if ($user_type == 4) {
    $nrSql = "SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
                     bi.business_name, at.application_type, fa.total_amount AS amount
              FROM business_applications ba
              LEFT JOIN business_info_data bi ON ba.id = bi.application_id
              LEFT JOIN application_type_data at ON ba.id = at.application_id
              LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
              WHERE LOWER(ba.status) = 'payment_confirmed'
              ORDER BY ba.application_date DESC LIMIT 10";
    $nrRes = $conn->query($nrSql);
    if ($nrRes) while ($row = $nrRes->fetch_assoc()) $needsReviewApps[] = $row;

    $waSql = "SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
                     bi.business_name, at.application_type
              FROM business_applications ba
              LEFT JOIN business_info_data bi ON ba.id = bi.application_id
              LEFT JOIN application_type_data at ON ba.id = at.application_id
              WHERE LOWER(ba.status) = 'submitted'
              ORDER BY ba.application_date DESC LIMIT 10";
    $waRes = $conn->query($waSql);
    if ($waRes) while ($row = $waRes->fetch_assoc()) $waitingOnApplicantsApps[] = $row;
}

// ============================================================
// PAYPAL PAYMENTS
// ============================================================
$payments = [];
$paymentsError = null;
if ($showPayments) {
    $ppSql = "
        SELECT pt.id, pt.application_id, pt.reference_no, pt.paypal_order_id, pt.paypal_capture_id,
               pt.payer_name, pt.payer_email, pt.amount, pt.currency, pt.payment_status,
               pt.paid_at, pt.created_at,
               ba.reference_no AS app_ref, bi.business_name
        FROM paypal_transactions pt
        LEFT JOIN business_applications ba ON pt.application_id = ba.id
        LEFT JOIN business_info_data bi ON ba.id = bi.application_id
        ORDER BY COALESCE(pt.paid_at, pt.created_at) DESC, pt.id DESC
        LIMIT 200
    ";
    $r = $conn->query($ppSql);
    if ($r) while ($row = $r->fetch_assoc()) $payments[] = $row;
    else $paymentsError = 'DB error: ' . $conn->error;
}

// ============================================================
// ALL APPS (user_type 4)
// ============================================================
$allApps = [];
if ($user_type == 4) {
    $allAppsSql = "
        SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
               ba.user_id, ba.application_no, ba.current_step, ba.tax_year,
               ba.submitted_at, ba.issued_at, ba.expiry_date, ba.resubmitted_at,
               bi.business_name,
               at.application_type,
               fa.total_amount AS amount,
               fa.gross_sales_tax, fa.mayors_permit_fee,
               fa.permit_on_calling, fa.health_certificate,
               fa.sanitary_inspection_fee, fa.garbage_charges,
               fa.weight_measure, fa.building_inspection_fee, fa.electrical_inspection_fee,
               fa.misc_fee, fa.others,
               fa.surcharge_fee, fa.interest_fee, fa.late_payment,
               fa.penalty_lost_permit,
               fa.fire_safety_fee_percent,
               (SELECT COUNT(*) FROM document_uploads WHERE application_id = ba.id) AS document_count
        FROM business_applications ba
        LEFT JOIN business_info_data bi ON ba.id = bi.application_id
        LEFT JOIN application_type_data at ON ba.id = at.application_id
        LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
        WHERE LOWER(ba.status) IN ('submitted', 'payment_confirmed')
        ORDER BY ba.application_date DESC
    ";
    $r = $conn->query($allAppsSql);
    if ($r) while ($row = $r->fetch_assoc()) $allApps[] = $row;
}

// ============================================================
// VERIFIED / REJECTED APPS
// ============================================================
$statusApps = [];
if ($user_type == 4) {
    $statusSql = "
        SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
               bi.business_name,
               at.application_type,
               fa.total_amount AS amount,
               fa.gross_sales_tax, fa.mayors_permit_fee,
               fa.permit_on_calling, fa.health_certificate,
               fa.sanitary_inspection_fee, fa.garbage_charges,
               fa.weight_measure, fa.building_inspection_fee, fa.electrical_inspection_fee,
               fa.misc_fee, fa.others,
               fa.surcharge_fee, fa.interest_fee, fa.late_payment,
               fa.penalty_lost_permit,
               fa.fire_safety_fee_percent
        FROM business_applications ba
        LEFT JOIN business_info_data bi ON ba.id = bi.application_id
        LEFT JOIN application_type_data at ON ba.id = at.application_id
        LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
        WHERE LOWER(ba.status) IN ('verified', 'claimed', 'rejected')
        ORDER BY ba.application_date DESC
    ";
    $r = $conn->query($statusSql);
    if ($r) while ($row = $r->fetch_assoc()) $statusApps[] = $row;
}

// ============================================================
// PENDING PAYMENT APPS
// ============================================================
$pendingPaymentApps = [];
if ($user_type == 3) {
    $pendingSql = "
        SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
               bi.business_name, at.application_type, fa.total_amount AS amount,
               ba.user_id, ba.application_no, ba.current_step, ba.tax_year, ba.submitted_at
        FROM business_applications ba
        LEFT JOIN business_info_data bi ON ba.id = bi.application_id
        LEFT JOIN application_type_data at ON ba.id = at.application_id
        LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
        WHERE LOWER(ba.status) = 'pending_payment'
        ORDER BY ba.application_date DESC
    ";
    $r = $conn->query($pendingSql);
    if ($r) while ($row = $r->fetch_assoc()) $pendingPaymentApps[] = $row;
}

// ============================================================
// TREASURY RECORDS
// ============================================================
$treasuryRecords = [];
if ($user_type == 3) {
    $treasurySql = "
        SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
               ba.tax_year, ba.submitted_at, ba.issued_at,
               bi.business_name, bi.business_activities,
               at.application_type, at.business_type, at.tin_no, at.registration_no, at.payment_mode,
               fa.gross_sales_tax, fa.mayors_permit_fee, fa.garbage_charges,
               fa.sanitary_inspection_fee, fa.building_inspection_fee,
               fa.electrical_inspection_fee, fa.others, fa.fire_safety_fee_percent,
               fa.total_amount AS amount,
               oi.first_name, oi.last_name, oi.middle_name, oi.owner_address, oi.mobile, oi.email,
               ad.business_address, ad.lessor_name, ad.lessor_address, ad.lessor_phone,
               ad.total_employees, ad.business_area,
               CONCAT(acc.firstname, ' ', acc.lastname) AS assessed_by_name
        FROM business_applications ba
        LEFT JOIN business_info_data bi ON ba.id = bi.application_id
        LEFT JOIN application_type_data at ON ba.id = at.application_id
        LEFT JOIN owner_info_data oi ON ba.id = oi.application_id
        LEFT JOIN business_address_data ad ON ba.id = ad.application_id
        LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
        LEFT JOIN accounts acc ON fa.assessed_by = acc.id
        WHERE LOWER(ba.status) IN ('payment_confirmed', 'verified')
        ORDER BY ba.application_date DESC
    ";
    $r = $conn->query($treasurySql);
    if ($r) while ($row = $r->fetch_assoc()) $treasuryRecords[] = $row;
}

// ============================================================
// APPOINTMENTS
// ============================================================
$appointments = [];
$calendarEvents = [];
if ($user_type == 4) {
    $apptSql = "
        SELECT a.id AS appointment_id, a.application_id, a.user_id,
               a.appointment_date, a.appointment_time,
               a.status AS appointment_status, a.created_at AS appointment_created,
               a.updated_at AS appointment_updated,
               ba.reference_no, ba.status AS application_status,
               ba.application_date, ba.tax_year,
               bi.business_name, at.application_type,
               acc.firstname, acc.lastname, acc.email, acc.contact_number
        FROM appointments a
        LEFT JOIN business_applications ba ON a.application_id = ba.id
        LEFT JOIN business_info_data bi ON ba.id = bi.application_id
        LEFT JOIN application_type_data at ON ba.id = at.application_id
        LEFT JOIN accounts acc ON a.user_id = acc.id
        WHERE LOWER(a.status) IN ('pending','confirmed','completed','cancelled')
          AND acc.user_type = 1
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
    ";
    $r = $conn->query($apptSql);
    if ($r) while ($row = $r->fetch_assoc()) $appointments[] = $row;

    foreach ($appointments as $appt) {
        $fullName = trim(($appt['firstname'] ?? '') . ' ' . ($appt['lastname'] ?? ''));
        if ($fullName === '') $fullName = 'Applicant #' . ($appt['user_id'] ?? '?');

        $s = strtolower($appt['appointment_status'] ?? '');
        switch ($s) {
            case 'pending':   $color = '#fbbf24'; $textColor = '#0f2038'; break;
            case 'confirmed': $color = '#3b82f6'; $textColor = '#ffffff'; break;
            case 'completed': $color = '#10b981'; $textColor = '#ffffff'; break;
            case 'cancelled': $color = '#ef4444'; $textColor = '#ffffff'; break;
            default:          $color = '#6b7280'; $textColor = '#ffffff';
        }

        $dateDisplay = date('M j', strtotime($appt['appointment_date']));
        $rawTime = trim($appt['appointment_time'] ?? '');
        $ts = strtotime(trim($appt['appointment_date']) . ' ' . $rawTime);
        $isoStart = ($ts === false) ? $appt['appointment_date'] . 'T00:00:00' : date('Y-m-d\TH:i:s', $ts);

        $calendarEvents[] = [
            'id' => $appt['appointment_id'],
            'title' => $fullName . ' — ' . ($appt['business_name'] ?? 'N/A'),
            'start' => $isoStart,
            'backgroundColor' => $color,
            'borderColor' => $color,
            'textColor' => $textColor,
            'extendedProps' => [
                'reference_no'       => $appt['reference_no'] ?? 'N/A',
                'business_name'      => $appt['business_name'] ?? 'N/A',
                'application_type'   => $appt['application_type'] ?? 'N/A',
                'application_status' => $appt['application_status'] ?? 'unknown',
                'appointment_status' => $appt['appointment_status'] ?? 'pending',
                'appointment_time'   => $appt['appointment_time'] ?? '',
                'date_display'       => $dateDisplay,
                'time_display'       => $rawTime,
                'owner_name'         => $fullName,
                'owner_email'        => $appt['email'] ?? '',
                'owner_contact'      => $appt['contact_number'] ?? '',
                'application_id'     => $appt['application_id'] ?? 0,
            ]
        ];
    }
}

// ============================================================
// MONTHLY COLLECTIONS (PayPal)
// ============================================================
$monthlyCollections = [];
$monthlyLabels      = [];
$monthlyCounts      = [];

if ($showPayments || $showTreasuryRecords) {
    $tz = new DateTimeZone(date_default_timezone_get());
    $windowEnd   = new DateTime('first day of next month', $tz);
    $windowStart = (clone $windowEnd)->modify('-12 months');

    $buckets = [];
    $cursor = clone $windowStart;
    while ($cursor < $windowEnd) {
        $buckets[$cursor->format('Y-m')] = ['label' => $cursor->format('M Y'), 'total' => 0, 'count' => 0];
        $cursor->modify('+1 month');
    }

    if (!$paymentsError) {
        $ppQ = "
            SELECT pt.amount, COALESCE(pt.paid_at, pt.created_at) AS event_time
            FROM paypal_transactions pt
            WHERE pt.payment_status IN ('completed','paid')
            ORDER BY event_time DESC
        ";
        $ppRes = $conn->query($ppQ);
        if ($ppRes) {
            while ($row = $ppRes->fetch_assoc()) {
                $ts = strtotime($row['event_time']);
                if ($ts === false) continue;
                $bucketKey = date('Y-m', $ts);
                if (!isset($buckets[$bucketKey])) continue;
                $buckets[$bucketKey]['total'] += (float)$row['amount'];
                $buckets[$bucketKey]['count'] += 1;
            }
        }
    }

    foreach ($buckets as $b) {
        $monthlyLabels[]      = $b['label'];
        $monthlyCollections[] = round($b['total'], 2);
        $monthlyCounts[]      = $b['count'];
    }
}

$hasMonthlyData    = array_sum($monthlyCollections) > 0;
$monthlyTotal      = array_sum($monthlyCollections);
$currentYear       = date('Y');

// ============================================================
// ANNOUNCEMENTS
// ============================================================
$message = '';
$staffAnnouncements = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_announcement'])) {
        $title = trim($_POST['title']); $content = trim($_POST['content']);
        $status = 'pending'; $created_by = $staff_id;
        $stmt = $conn->prepare("INSERT INTO announcements (title, content, created_by, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $title, $content, $created_by, $status);
        if ($stmt->execute()) $message = 'Announcement submitted for approval.';
        else $message = 'Error: ' . $stmt->error;
        $stmt->close();
    }
    if (isset($_POST['edit_announcement'])) {
        $id = (int)$_POST['id']; $title = trim($_POST['title']); $content = trim($_POST['content']);
        $check = $conn->prepare("SELECT status FROM announcements WHERE id=? AND created_by=?");
        $check->bind_param("ii", $id, $staff_id); $check->execute();
        $cr = $check->get_result();
        if ($cr->num_rows === 1) {
            $row = $cr->fetch_assoc();
            if ($row['status'] === 'pending') {
                $stmt = $conn->prepare("UPDATE announcements SET title=?, content=? WHERE id=? AND created_by=?");
                $stmt->bind_param("ssii", $title, $content, $id, $staff_id);
                if ($stmt->execute()) $message = 'Announcement updated successfully.';
                else $message = 'Error: ' . $stmt->error;
                $stmt->close();
            } else $message = 'Cannot edit a non-pending announcement.';
        } else $message = 'Announcement not found or you do not own it.';
        $check->close();
    }
    if (isset($_POST['delete_announcement'])) {
        $id = (int)$_POST['id'];
        $check = $conn->prepare("SELECT status FROM announcements WHERE id=? AND created_by=?");
        $check->bind_param("ii", $id, $staff_id); $check->execute();
        $cr = $check->get_result();
        if ($cr->num_rows === 1) {
            $row = $cr->fetch_assoc();
            if ($row['status'] === 'pending') {
                $stmt = $conn->prepare("DELETE FROM announcements WHERE id=? AND created_by=?");
                $stmt->bind_param("ii", $id, $staff_id);
                if ($stmt->execute()) $message = 'Announcement deleted.';
                else $message = 'Error: ' . $stmt->error;
                $stmt->close();
            } else $message = 'Cannot delete a non-pending announcement.';
        } else $message = 'Announcement not found or you do not own it.';
        $check->close();
    }
}
$annStmt = $conn->prepare("SELECT a.*, CONCAT(acc.firstname, ' ', acc.lastname) AS creator_name
                           FROM announcements a
                           LEFT JOIN accounts acc ON a.created_by = acc.id
                           WHERE a.created_by = ?
                           ORDER BY a.created_at DESC");
$annStmt->bind_param("i", $staff_id); $annStmt->execute();
$annResult = $annStmt->get_result();
if ($annResult) while ($row = $annResult->fetch_assoc()) $staffAnnouncements[] = $row;
$annStmt->close();

// ============================================================
// ACTIVITY LOG (user_type 4)
// ============================================================
$activityLog     = [];
$activityStats   = [
    'total' => 0, 'today' => 0, 'week' => 0,
    'reject' => 0, 'bplo' => 0, 'treasury' => 0, 'admin' => 0,
    'document_actions' => 0, 'verified' => 0,
    'payments' => 0, 'appointments' => 0, 'documents' => 0, 'submissions' => 0,
];

if ($showActivityLog) {

    $appendRows = function ($sql) use ($conn, &$activityLog) {
        $res = @$conn->query($sql);
        if (!$res) {
            error_log('[ActivityLog BPLO] query failed: ' . $conn->error . ' | SQL: ' . substr($sql, 0, 200));
            return 0;
        }
        $n = 0;
        while ($row = $res->fetch_assoc()) { $activityLog[] = $row; $n++; }
        $res->free();
        return $n;
    };

    $tableExists = function ($t) use ($conn) {
        $t = $conn->real_escape_string($t);
        $r = @$conn->query("SHOW TABLES LIKE '$t'");
        return $r && $r->num_rows > 0;
    };

    if ($tableExists('status_history')) {
        $appendRows("
            SELECT 'status_change' AS event_type, sh.id AS event_id, sh.application_id,
                sh.changed_at AS event_time, sh.changed_by AS actor_id,
                sh.old_status, sh.new_status, sh.notes,
                NULL AS missing_documents_json,
                ba.reference_no, ba.application_no, bid.business_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
                CONCAT(chg.firstname, ' ', chg.lastname) AS actor_name,
                chg.user_type AS actor_type
            FROM status_history sh
            LEFT JOIN business_applications ba ON sh.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            LEFT JOIN accounts chg ON sh.changed_by = chg.id
            ORDER BY sh.changed_at DESC
            LIMIT 500
        ");
    }

    if ($tableExists('fee_assessments')) {
        $appendRows("
            SELECT 'fee_assessment' AS event_type, fa.id AS event_id, fa.application_id,
                fa.assessed_at AS event_time, fa.assessed_by AS actor_id,
                NULL AS old_status, 'assessed' AS new_status,
                CONCAT('Assessed fees: ₱', FORMAT(fa.total_amount, 2)) AS notes,
                NULL AS missing_documents_json,
                ba.reference_no, ba.application_no, bid.business_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
                CONCAT(chg.firstname, ' ', chg.lastname) AS actor_name,
                chg.user_type AS actor_type
            FROM fee_assessments fa
            LEFT JOIN business_applications ba ON fa.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            LEFT JOIN accounts chg ON fa.assessed_by = chg.id
            WHERE fa.assessed_at IS NOT NULL
            ORDER BY fa.assessed_at DESC
            LIMIT 500
        ");
    }

    if ($tableExists('application_rejections')) {
        $appendRows("
            SELECT 'document_rejection' AS event_type, ar.id AS event_id, ar.application_id,
                ar.rejected_at AS event_time, ar.rejected_by AS actor_id,
                NULL AS old_status, 'rejected' AS new_status, ar.notes,
                ar.missing_documents AS missing_documents_json,
                ba.reference_no, ba.application_no, bid.business_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
                CONCAT(chg.firstname, ' ', chg.lastname) AS actor_name,
                chg.user_type AS actor_type
            FROM application_rejections ar
            LEFT JOIN business_applications ba ON ar.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            LEFT JOIN accounts chg ON ar.rejected_by = chg.id
            WHERE ar.rejected_at IS NOT NULL
            ORDER BY ar.rejected_at DESC
            LIMIT 500
        ");
    }

    if ($tableExists('paypal_transactions')) {
        $appendRows("
            SELECT 'payment' AS event_type, pt.id AS event_id, pt.application_id,
                COALESCE(pt.paid_at, pt.created_at) AS event_time, pt.user_id AS actor_id,
                NULL AS old_status, pt.payment_status AS new_status,
                CONCAT('PayPal ', pt.currency, ' ', FORMAT(pt.amount, 2),
                       ' · Payer: ', COALESCE(pt.payer_name, pt.payer_email, '—'),
                       ' · Capture: ', COALESCE(pt.paypal_capture_id, '—')) AS notes,
                NULL AS missing_documents_json,
                pt.reference_no, NULL AS application_no, bid.business_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
                'PayPal' AS actor_name,
                0 AS actor_type
            FROM paypal_transactions pt
            LEFT JOIN business_applications ba ON pt.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON pt.user_id = acc.id
            WHERE pt.payment_status IN ('completed','paid','voided','failed','cancelled')
            ORDER BY COALESCE(pt.paid_at, pt.created_at) DESC
            LIMIT 500
        ");
    }

    if ($tableExists('appointments')) {
        $appendRows("
            SELECT 'appointment_created' AS event_type, a.id AS event_id, a.application_id,
                a.created_at AS event_time, a.user_id AS actor_id,
                NULL AS old_status, 'booked' AS new_status,
                CONCAT('Appointment booked for ',
                       DATE_FORMAT(a.appointment_date, '%b %d, %Y'),
                       ' at ', a.appointment_time) AS notes,
                NULL AS missing_documents_json,
                ba.reference_no, ba.application_no, bid.business_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name,
                1 AS actor_type
            FROM appointments a
            LEFT JOIN business_applications ba ON a.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON a.user_id = acc.id
            WHERE a.created_at IS NOT NULL
            ORDER BY a.created_at DESC
            LIMIT 500
        ");

        $appendRows("
            SELECT CONCAT('appointment_', LOWER(a.status)) AS event_type,
                a.id AS event_id, a.application_id,
                a.updated_at AS event_time, NULL AS actor_id,
                'pending' AS old_status, a.status AS new_status,
                CONCAT('Appointment ', a.status, ' — scheduled ',
                       DATE_FORMAT(a.appointment_date, '%b %d, %Y'),
                       ' at ', a.appointment_time) AS notes,
                NULL AS missing_documents_json,
                ba.reference_no, ba.application_no, bid.business_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
                'BPLO Staff' AS actor_name,
                4 AS actor_type
            FROM appointments a
            LEFT JOIN business_applications ba ON a.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON a.user_id = acc.id
            WHERE a.updated_at IS NOT NULL
              AND a.updated_at != a.created_at
              AND LOWER(a.status) IN ('confirmed','completed','cancelled')
            ORDER BY a.updated_at DESC
            LIMIT 500
        ");
    }

    if ($tableExists('document_uploads')) {
        $appendRows("
            SELECT 'document_upload' AS event_type, du.id AS event_id, du.application_id,
                du.uploaded_at AS event_time, ba.user_id AS actor_id,
                NULL AS old_status, 'uploaded' AS new_status,
                CONCAT('Uploaded: ', COALESCE(du.original_name, du.document_type, 'Document'),
                       ' (', COALESCE(du.document_type, 'General'), ')') AS notes,
                NULL AS missing_documents_json,
                ba.reference_no, ba.application_no, bid.business_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name,
                1 AS actor_type
            FROM document_uploads du
            LEFT JOIN business_applications ba ON du.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            WHERE du.uploaded_at IS NOT NULL
            ORDER BY du.uploaded_at DESC
            LIMIT 500
        ");
    }

    if ($tableExists('business_applications')) {
        $appendRows("
            SELECT 'application_submitted' AS event_type, ba.id AS event_id, ba.id AS application_id,
                ba.submitted_at AS event_time, ba.user_id AS actor_id,
                'draft' AS old_status, ba.status AS new_status,
                CONCAT('Application submitted — ',
                       COALESCE(at.application_type, 'New'),
                       ' application for ',
                       COALESCE(bid.business_name, 'business')) AS notes,
                NULL AS missing_documents_json,
                ba.reference_no, ba.application_no, bid.business_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name,
                1 AS actor_type
            FROM business_applications ba
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN application_type_data at ON ba.id = at.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            WHERE ba.submitted_at IS NOT NULL
            ORDER BY ba.submitted_at DESC
            LIMIT 500
        ");
    }

    if ($tableExists('announcements')) {
        $appendRows("
            SELECT CONCAT('announcement_', LOWER(a.status)) AS event_type,
                a.id AS event_id, NULL AS application_id,
                a.created_at AS event_time, a.created_by AS actor_id,
                NULL AS old_status, a.status AS new_status,
                CONCAT('Announcement: ', a.title) AS notes,
                NULL AS missing_documents_json,
                NULL AS reference_no, NULL AS application_no, NULL AS business_name,
                NULL AS owner_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name,
                acc.user_type AS actor_type
            FROM announcements a
            LEFT JOIN accounts acc ON a.created_by = acc.id
            ORDER BY a.created_at DESC
            LIMIT 500
        ");
    }

    usort($activityLog, function ($a, $b) {
        return strtotime($b['event_time'] ?? 0) - strtotime($a['event_time'] ?? 0);
    });
    $activityLog = array_slice($activityLog, 0, 500);

    if (empty($activityLog) && $tableExists('business_applications')) {
        $fbResult = @$conn->query("
            SELECT ba.id AS application_id, ba.status, ba.updated_at AS event_time,
                   ba.created_at, ba.reference_no, ba.application_no,
                   bid.business_name,
                   CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name
            FROM business_applications ba
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            WHERE ba.status != 'draft'
            ORDER BY ba.updated_at DESC LIMIT 500
        ");
        if ($fbResult) {
            while ($row = $fbResult->fetch_assoc()) {
                $activityLog[] = [
                    'event_type'             => 'status_change',
                    'event_id'               => $row['application_id'],
                    'application_id'         => $row['application_id'],
                    'old_status'             => '—',
                    'new_status'             => $row['status'],
                    'actor_id'               => null,
                    'notes'                  => 'Current status',
                    'missing_documents_json' => null,
                    'event_time'             => $row['event_time'] ?? $row['created_at'],
                    'reference_no'           => $row['reference_no'],
                    'application_no'         => $row['application_no'],
                    'business_name'          => $row['business_name'],
                    'owner_name'             => $row['owner_name'] ?? 'Unknown',
                    'actor_name'             => 'System',
                    'actor_type'             => null,
                ];
            }
        }
    }

    $docMap = [
        'barangay'    => 'Barangay Clearance',
        'dti_sec'     => 'DTI/SEC – Xerox Copy',
        'rpt'         => 'RPT – Current Year Payment',
        'engineering' => 'Engineering',
        'zoning'      => 'Zoning Certification (MPDC)',
        'treasury'    => 'Treasury',
        'sanitary'    => 'Sanitary Health Permit',
        'fire_safety' => 'Fire Safety Certificate',
    ];

    foreach ($activityLog as &$log) {
        if (($log['event_type'] ?? '') === 'document_rejection' && !empty($log['missing_documents_json'])) {
            $missing = json_decode($log['missing_documents_json'], true);
            if (is_array($missing) && count($missing) > 0) {
                $labels = [];
                foreach ($missing as $key) $labels[] = $docMap[$key] ?? $key;
                $existing = trim($log['notes'] ?? '');
                $log['notes'] = ($existing !== '' ? $existing . ' — ' : '')
                              . 'Missing: ' . implode(', ', $labels);
            }
        }
    }
    unset($log);

    $todayDate = date('Y-m-d');
    $weekAgo   = date('Y-m-d', strtotime('-7 days'));

    foreach ($activityLog as $log) {
        $logDate = date('Y-m-d', strtotime($log['event_time'] ?? 'now'));
        if ($logDate === $todayDate) $activityStats['today']++;
        if ($logDate >= $weekAgo)    $activityStats['week']++;

        $newSt = strtolower($log['new_status'] ?? '');
        if ($newSt === 'rejected') $activityStats['reject']++;
        if ($newSt === 'verified') $activityStats['verified']++;

        $et = $log['event_type'] ?? '';
        if (in_array($et, ['document_rejection','fee_assessment','document_upload'], true))
            $activityStats['document_actions']++;
        if ($et === 'payment')                $activityStats['payments']++;
        if (strpos($et, 'appointment') === 0) $activityStats['appointments']++;
        if ($et === 'document_upload')        $activityStats['documents']++;
        if ($et === 'application_submitted')  $activityStats['submissions']++;

        $t = (int)($log['actor_type'] ?? 0);
        if ($t === 4)      $activityStats['bplo']++;
        elseif ($t === 3)  $activityStats['treasury']++;
        elseif ($t === 5)  $activityStats['admin']++;
    }
    $activityStats['total'] = count($activityLog);
}

$qrLibIncluded = true;

// ============================================================
// HELPER: receipt data attributes
// ============================================================
function renderReceiptDataAttrs($app) {
    $isRenewal = (strtolower(trim($app['application_type'] ?? '')) === 'renewal');
    $amount = number_format((float)($app['amount'] ?? 0), 2);

    $f = function ($v) {
        if ($v === null || $v === '') return 0.0;
        return (float)$v;
    };

    $attrs = [
        'data-appid'                    => (int)$app['application_id'],
        'data-ref'                      => $app['reference_no'],
        'data-amount'                   => $amount,
        'data-business'                 => $app['business_name'] ?? 'N/A',
        'data-is-renewal'               => $isRenewal ? '1' : '0',
        'data-mayors-permit-fee'        => $f($app['mayors_permit_fee'] ?? 0),
        'data-gross-sales-tax'          => $f($app['gross_sales_tax'] ?? 0),
        'data-permit-on-calling'        => $f($app['permit_on_calling'] ?? 0),
        'data-health-certificate'       => $f($app['health_certificate'] ?? 0),
        'data-sanitary-inspection-fee'  => $f($app['sanitary_inspection_fee'] ?? 0),
        'data-garbage-charges'          => $f($app['garbage_charges'] ?? 0),
        'data-weight-measure'           => $f($app['weight_measure'] ?? 0),
        'data-building-inspection-fee'  => $f($app['building_inspection_fee'] ?? 0),
        'data-electrical-inspection-fee'=> $f($app['electrical_inspection_fee'] ?? 0),
        'data-misc-fee'                 => $f($app['misc_fee'] ?? 0),
        'data-others'                   => $f($app['others'] ?? 0),
        'data-surcharge-fee'            => $f($app['surcharge_fee'] ?? 0),
        'data-interest-fee'             => $f($app['interest_fee'] ?? 0),
        'data-late-payment'             => $f($app['late_payment'] ?? 0),
        'data-penalty-lost-permit'      => $f($app['penalty_lost_permit'] ?? 0),
        'data-fire-safety-fee'          => $f($app['fire_safety_fee_percent'] ?? 0),
    ];

    $out = '';
    foreach ($attrs as $k => $val) {
        if (is_string($val)) {
            $out .= ' ' . $k . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
        } else {
            $out .= ' ' . $k . '="' . $val . '"';
        }
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title><?php echo $role_label; ?> Dashboard | Municipality of Placer</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root{
            --navy-dark:    #0f2038;
            --navy-sidebar: #12263f;
            --navy-hover:   #1e3a5f;
            --yellow-accent:#fbbf24;
            --yellow-hover: #f59e0b;
            --content-bg:   #f1f5f9;
            --border:       #e2e8f0;
            --text-dark:    #1e293b;
            --text-muted:   #64748b;
            --green:        #10b981;
            --red:          #ef4444;
            --blue:         #3b82f6;
        }
        *{font-family:'Inter',sans-serif;box-sizing:border-box}
        html,body{width:100%;max-width:100vw}
        body{background:var(--content-bg);color:var(--text-dark);overflow-x:hidden;margin:0}

        .top-bar{
            background:var(--navy-dark);color:#fff;padding:.85rem 1.5rem;
            display:flex;align-items:center;justify-content:space-between;
            position:sticky;top:0;z-index:1030;
        }
        .top-bar .brand{display:flex;align-items:center;gap:12px}
        .top-bar .brand-logo{height:42px;display:inline-flex;align-items:center;justify-content:center}
        .top-bar .brand-logo img{height:42px;width:auto;object-fit:contain}
        .top-bar .brand-text h6{font-size:.95rem;font-weight:700;margin:0;line-height:1.2}
        .top-bar .brand-text small{font-size:.7rem;color:#94a3b8;display:block}
        .top-bar .top-actions{display:flex;align-items:center;gap:8px}

        .top-bar .brand-logo,
        .top-bar .brand-logo img{
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            outline: none !important;
        }
        .top-bar .brand-logo img{
            mix-blend-mode: screen;
            filter: drop-shadow(0 0 1px rgba(255,255,255,.15));
        }

        .notif-bell{position:relative;width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:50%;color:#fff;text-decoration:none}
        .notif-bell:hover{background:rgba(255,255,255,.08);color:#fff}
        .notif-bell i{font-size:1.15rem}
        .notif-bell .badge-dot{position:absolute;top:0;right:0;min-width:16px;height:16px;padding:0 4px;background:#dc3545;color:#fff;font-size:.6rem;font-weight:700;border-radius:9px;display:flex;align-items:center;justify-content:center;border:2px solid var(--navy-dark);line-height:1}
        .user-chip{display:inline-flex;align-items:center;gap:8px;color:#fff;text-decoration:none;padding:.4rem .85rem;border-radius:40px;background:rgba(255,255,255,.08);font-size:.85rem;font-weight:500}
        .user-chip:hover{background:rgba(255,255,255,.15);color:#fff}

        .sidebar{background:var(--navy-sidebar);min-height:calc(100vh - 62px);padding:1.5rem 1rem;color:#cbd5e1;position:sticky;top:62px;display:flex;flex-direction:column}
        .sidebar .nav{flex:1}
        .sidebar .nav-link{color:#cbd5e1;padding:.75rem 1rem;font-weight:500;font-size:.88rem;display:flex;align-items:center;justify-content:space-between;gap:12px;border-radius:8px;margin-bottom:3px;transition:all .15s;text-decoration:none}
        .sidebar .nav-link .nav-left{display:flex;align-items:center;gap:12px}
        .sidebar .nav-link:hover{background:rgba(255,255,255,.05);color:#fff}
        .sidebar .nav-link.active{background:var(--yellow-accent);color:var(--navy-dark);font-weight:700}
        .sidebar-bottom{margin-top:auto;background:rgba(255,255,255,.04);border-radius:10px;padding:.9rem 1rem;border:1px solid rgba(255,255,255,.06)}
        .sidebar-bottom h6{margin:0;font-size:.85rem;font-weight:700;color:#fff}
        .sidebar-bottom small{color:#94a3b8;font-size:.72rem}

        .main-content{padding:1.75rem 2rem 3rem;min-width:0}
        .page-greeting{font-size:1.6rem;font-weight:700;margin:0 0 4px;color:var(--navy-dark)}
        .page-meta{color:var(--text-muted);font-size:.9rem;margin:0 0 1.5rem}

        .stat-tile{background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.1rem 1.25rem;position:relative;overflow:hidden;height:100%;transition:all .15s}
        .stat-tile:hover{box-shadow:0 4px 12px rgba(15,31,61,.05)}
        .stat-tile::before{content:'';position:absolute;top:0;left:0;right:0;height:4px}
        .stat-tile.green::before{background:var(--green)}
        .stat-tile.yellow::before{background:var(--yellow-accent)}
        .stat-tile.red::before{background:var(--red)}
        .stat-tile.blue::before{background:var(--blue)}
        .stat-tile.purple::before{background:#8b5cf6}
        .stat-tile .stat-val{font-size:1.9rem;font-weight:800;line-height:1;color:var(--navy-dark);margin-bottom:4px}
        .stat-tile .stat-lbl{font-size:.82rem;color:var(--text-muted);font-weight:500}

        .stat-tile-clickable{cursor:pointer;transition:transform .15s, box-shadow .15s;}
        .stat-tile-clickable:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(15,31,61,.10);}
        .stat-tile-clickable:active{transform:translateY(0);}
        .stat-tile-clickable:focus-visible{outline:2px solid var(--yellow-accent);outline-offset:2px;}
        .stat-tile-clickable .stat-lbl i.bi-box-arrow-up-right{font-size:.65rem;opacity:.5;}

        .panel{background:#fff;border:1px solid var(--border);border-radius:14px;padding:1.5rem 1.75rem;margin-bottom:1.25rem}
        .panel h5{font-size:1.1rem;font-weight:700;color:var(--navy-dark);margin:0 0 4px}
        .panel p.sub{color:var(--text-muted);font-size:.85rem;margin:0 0 1rem}

        .table-plain{width:100%;border-collapse:collapse}
        .table-plain thead th{background:transparent;color:var(--text-muted);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:.75rem .5rem;text-align:left;border-bottom:2px solid var(--navy-dark)}
        .table-plain tbody td{padding:.9rem .5rem;font-size:.88rem;vertical-align:middle;border-bottom:1px solid #f1f5f9}
        .table-plain tbody tr:last-child td{border-bottom:none}
        .table-plain tbody tr:hover{background:#fafafa}

        .pill{display:inline-block;padding:.3rem .75rem;border-radius:20px;font-size:.72rem;font-weight:600;line-height:1;white-space:nowrap}
        .pill-submitted{background:#fef3c7;color:#92400e}
        .pill-verified{background:#d1fae5;color:#065f46}
        .pill-payment{background:#dbeafe;color:#1e40af}
        .pill-cancelled{background:#f1f5f9;color:#475569}
        .pill-rejected{background:#fee2e2;color:#991b1b}
        .pill-approved{background:#d1fae5;color:#065f46}
        .pill-pending{background:#fef3c7;color:#92400e}
        .pill-info{background:#e0e7ff;color:#3730a3}
        .pill-draft{background:#e5e7eb;color:#374151}
        .pill-renewal{background:#fef3c7;color:#92400e}

        .btn-yellow{background:var(--yellow-accent);color:var(--navy-dark);border:none;border-radius:8px;padding:.55rem 1.2rem;font-weight:600;font-size:.85rem;display:inline-flex;align-items:center;gap:6px;transition:all .15s;text-decoration:none;white-space:nowrap}
        .btn-yellow:hover{background:var(--yellow-hover);color:var(--navy-dark)}
        .btn-outline-dark-custom{background:#fff;color:var(--navy-dark);border:1px solid var(--border);border-radius:8px;padding:.55rem 1.2rem;font-weight:600;font-size:.85rem;display:inline-flex;align-items:center;gap:6px;transition:all .15s;text-decoration:none;white-space:nowrap}
        .btn-outline-dark-custom:hover{background:#f8fafc;border-color:#cbd5e1;color:var(--navy-dark)}
        .btn-sm-action{background:#fff;color:var(--navy-dark);border:1px solid var(--border);border-radius:8px;padding:.35rem .9rem;font-weight:600;font-size:.78rem;text-decoration:none;display:inline-flex;align-items:center;white-space:nowrap}
        .btn-sm-action:hover{background:#f8fafc;border-color:#cbd5e1;color:var(--navy-dark)}
        .btn-sm-action.dark{background:var(--navy-dark);color:#fff;border-color:var(--navy-dark)}
        .btn-sm-action.dark:hover{background:#0a172a;color:#fff}

        .mobile-menu-btn{background:transparent;border:none;color:#fff;font-size:1.3rem;padding:0;margin-right:8px}
        .offcanvas.offcanvas-start{width:280px}
        .offcanvas .offcanvas-body{padding:0;background:var(--navy-sidebar)}
        .offcanvas .nav-link{color:#cbd5e1;padding:.75rem 1rem;font-size:.9rem;display:flex;align-items:center;justify-content:space-between;gap:12px;border-radius:8px;margin-bottom:3px}
        .offcanvas .nav-link.active{background:var(--yellow-accent);color:var(--navy-dark);font-weight:700}
        .offcanvas .offcanvas-header{border-bottom:1px solid rgba(255,255,255,.08)}
        .offcanvas .offcanvas-title{color:#fff;font-weight:700}

        #appointmentsCalendar{background:#fff;border-radius:12px}
        .fc .fc-toolbar-title{font-size:1.15rem!important;font-weight:700;color:var(--navy-dark)}
        .fc .fc-button-primary{background:#fff!important;color:var(--navy-dark)!important;border:1px solid var(--border)!important;font-size:.8rem;text-transform:capitalize}
        .fc .fc-button-primary:hover{background:#f8fafc!important}
        .fc .fc-button-primary:not(:disabled).fc-button-active,
        .fc .fc-button-primary:not(:disabled):active{background:var(--navy-dark)!important;color:#fff!important;border-color:var(--navy-dark)!important}
        .fc-event{border-radius:6px!important;padding:3px 6px!important;font-size:.75rem;font-weight:600;cursor:pointer}
        .fc .fc-day-today{background:#fffbeb!important}
        .fc-daygrid-day-frame{min-height:120px!important}

        .app-ref-link{color:var(--navy-dark);font-weight:700;text-decoration:none}
        .app-ref-link:hover{color:#065f46;text-decoration:underline}
        .activity-filter-btn{background:#fff;border:1px solid var(--border);color:var(--text-dark);font-size:.78rem;font-weight:600;border-radius:8px;padding:.4rem .9rem;display:inline-flex;align-items:center;gap:.3rem}
        .activity-filter-btn:hover{background:#f8fafc}
        .activity-filter-btn.active{background:var(--navy-dark);color:#fff;border-color:var(--navy-dark)}
        .event-tag{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .6rem;border-radius:2rem;font-size:.72rem;font-weight:600;white-space:nowrap}
        .role-tag{display:inline-block;padding:.15rem .5rem;border-radius:1rem;font-size:.65rem;font-weight:700}

        html, body { overflow-x: hidden; }
        canvas, img, video { max-width: 100%; height: auto; }
        .dropdown-menu { max-width: calc(100vw - 1rem); }

        @media (max-width: 992px) {
            .sidebar { display: none; }
            .main-content { padding: 1rem 1rem 2.5rem; }
            .page-greeting { font-size: 1.35rem; }
            .stat-tile .stat-val { font-size: 1.5rem; }
        }
        @media (max-width: 768px) {
            .top-bar { padding: .55rem .85rem; gap: .5rem; }
            .top-bar .brand { gap: 8px; min-width: 0; flex: 1 1 auto; }
            .top-bar .brand-logo, .top-bar .brand-logo img { height: 34px; }
            .top-bar .brand-text { min-width: 0; }
            .top-bar .brand-text h6,
            .top-bar .brand-text small { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .top-bar .brand-text h6 { font-size: .82rem; }
            .top-bar .brand-text small { font-size: .62rem; }
            .top-actions { gap: 4px !important; }
            .panel { padding: 1rem; border-radius: 12px; }
            .panel h5 { font-size: 1rem; }
            .panel p.sub { font-size: .8rem; }
            .table-plain thead th { font-size: .66rem; padding: .55rem .35rem; letter-spacing: .03em; }
            .table-plain tbody td { padding: .65rem .4rem; font-size: .78rem; }
            .table-responsive { border-radius: 8px; }
            .btn-yellow, .btn-outline-dark-custom { padding: .5rem 1rem; font-size: .8rem; }
            .btn-sm-action { padding: .3rem .6rem; font-size: .72rem; }
            .btn-sm-action + .btn-sm-action { margin-left: 3px; }
            .table-plain td .btn-sm-action { margin-bottom: 2px; }
            .dataTables_wrapper .row { --bs-gutter-x: .25rem; row-gap: .5rem; }
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter { text-align: left !important; width: 100%; }
            .dataTables_wrapper .dataTables_filter input { width: 100% !important; margin-left: 0 !important; }
            .dataTables_wrapper .dataTables_length select { width: auto; }
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate { width: 100%; text-align: center !important; margin-top: .5rem; }
            .dataTables_wrapper .dataTables_paginate .pagination { justify-content: center; flex-wrap: wrap; gap: 2px; }
            .dataTables_wrapper .dataTables_paginate .paginate_button { padding: .25rem .5rem !important; font-size: .78rem; }
            .fc .fc-toolbar { flex-direction: column; gap: .5rem; }
            .fc .fc-toolbar-title { font-size: 1rem !important; }
            .fc .fc-toolbar-chunk { display: flex; flex-wrap: wrap; justify-content: center; gap: .25rem; }
            .fc .fc-button { padding: .25rem .5rem !important; font-size: .72rem !important; }
            .fc-daygrid-day-frame { min-height: 80px !important; }
            .fc-event { font-size: .65rem !important; padding: 2px 4px !important; }
            .fc .fc-event-title { white-space: normal; line-height: 1.1; }
            .modal-dialog { margin: .5rem; max-width: calc(100% - 1rem); }
            .modal-dialog.modal-lg, .modal-dialog.modal-xl { max-width: calc(100% - 1rem); }
            .modal-content { border-radius: 12px; }
            .modal-header, .modal-footer { padding: .7rem .9rem; }
            .modal-title { font-size: 1rem; }
            .modal-body { padding: .9rem; }
            .receipt-modal .modal-dialog { max-width: calc(100% - 1rem); }
            .receipt-modal .modal-body { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .official-receipt-template { min-width: 560px; margin: 8px; padding: 14px; }
            .official-receipt-template .header-text { padding-right: 0; }
            .official-receipt-template .header-text h2 { font-size: 18px; letter-spacing: 1px; }
            .official-receipt-template .header-text p { font-size: 11px; }
            .official-receipt-template .header-logo { width: 50px; margin-right: 10px; }
            .official-receipt-template .header-logo img { max-height: 40px; }
            .official-receipt-template .col,
            .official-receipt-template table { font-size: 12px; }
            .swal2-popup.swal2-modal { width: calc(100vw - 1.5rem) !important; padding: 1rem !important; }
            .swal2-title { font-size: 1.1rem !important; }
            .swal2-html-container { font-size: .85rem !important; }
            .status-option-btn { min-width: 100% !important; margin: 4px 0 !important; }
            .swal2-actions { gap: .5rem; }
            .swal2-actions .swal2-styled { padding: .55rem 1rem; font-size: .85rem; }
            .activity-filter-btn { padding: .35rem .7rem; font-size: .72rem; }
            .staff-notif-menu { width: calc(100vw - 1.5rem); }
            .staff-notif-menu .notif-list { max-height: 60vh; }
            .offcanvas.offcanvas-start { width: min(280px, 85vw); }
        }
        @media (max-width: 576px) {
            .user-chip { padding: .35rem .55rem; font-size: .8rem; }
            .user-chip .user-name { display: none; }
            .notif-bell { width: 34px; height: 34px; }
            .notif-bell i { font-size: 1rem; }
        }
        @media (max-width: 480px) {
            .main-content { padding: .75rem .6rem 2rem; }
            .stat-tile { padding: .85rem .9rem; }
            .stat-tile .stat-val { font-size: 1.35rem; }
            .stat-tile .stat-lbl { font-size: .72rem; }
            .panel { padding: .85rem; }
            .page-greeting { font-size: 1.2rem; }
            .page-meta { font-size: .8rem; }
            .table-plain thead th, .table-plain tbody td { padding: .5rem .35rem; font-size: .72rem; }
        }

        .table-responsive{-webkit-overflow-scrolling:touch}
        .table-responsive::-webkit-scrollbar{height:6px}
        .table-responsive::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}

        .amount-clickable{cursor:pointer;color:var(--blue);text-decoration:underline;text-decoration-style:dotted;font-weight:600}
        .amount-clickable:hover{color:#1d4ed8}
        .receipt-modal .modal-dialog{max-width:750px}
        .receipt-modal .modal-body{padding:0;background:#fff}
        .official-receipt-template{font-family:'Courier New',monospace;padding:20px;border:2px solid #000;margin:10px;background:#fff}
        .official-receipt-template .header{display:flex;align-items:center;border-bottom:2px solid #000;padding-bottom:15px;margin-bottom:15px}
        .official-receipt-template .header-logo{width:80px;margin-right:20px;text-align:center}
        .official-receipt-template .header-text{flex:1;text-align:center;padding-right:80px}
        .official-receipt-template .header-text h2{font-size:24px;font-weight:bold;margin:0;letter-spacing:2px;font-family:'Times New Roman',serif}
        .official-receipt-template .header-text p{font-size:14px;margin:5px 0 0 0;font-family:'Times New Roman',serif}
        .official-receipt-template .form-no{text-align:center;font-size:12px;margin-bottom:10px;border-bottom:1px solid #000;padding-bottom:5px}
        .official-receipt-template .row{display:flex;border-bottom:1px solid #000;margin:0}
        .official-receipt-template .col{flex:1;padding:5px 10px;border-right:1px solid #000;font-size:14px;display:flex;align-items:center}
        .official-receipt-template .col:last-child{border-right:none}
        .official-receipt-template .col.label{font-weight:bold;min-width:80px;border-right:none;padding-right:0}
        .official-receipt-template .col.value{flex:2;font-family:'Courier New',monospace;font-weight:bold}
        .official-receipt-template .table-container{border:1px solid #000;margin-top:15px}
        .official-receipt-template table{width:100%;border-collapse:collapse;font-size:14px}
        .official-receipt-template th{border:1px solid #000;padding:8px;text-align:left;font-weight:normal;font-size:13px}
        .official-receipt-template td{border:1px solid #000;padding:5px 8px;height:25px}
        .official-receipt-template .total-row td{border-top:2px solid #000;font-weight:bold}
        .official-receipt-template .words-row td{font-weight:bold;text-align:center;font-size:13px;border-top:1px solid #000;padding:10px}
        .official-receipt-template .bottom-section{display:flex;margin-top:15px;border:1px solid #000}
        .official-receipt-template .payment-options{flex:1;padding:10px;border-right:1px solid #000}
        .official-receipt-template .payment-options div{margin-bottom:15px;display:flex;align-items:center;font-size:14px}
        .official-receipt-template .bank-details{flex:2}
        .official-receipt-template .signature-block{display:flex;justify-content:flex-end;margin-top:30px}
        .official-receipt-template .signature-line{width:250px;border-top:1px solid #000;text-align:center;padding-top:5px;font-weight:bold;font-size:14px}
        .official-receipt-template .note-text{font-size:9px;text-align:center;margin-top:20px;color:#333}

        .staff-notif-menu{width:340px;max-width:calc(100vw - 2rem);padding:0;border:none;box-shadow:0 8px 24px rgba(0,0,0,.15);border-radius:10px;overflow:hidden}
        .staff-notif-menu .notif-header{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1rem;background:var(--navy-dark);color:#fff}
        .staff-notif-menu .notif-header h6{margin:0;font-size:.9rem;font-weight:600;display:flex;align-items:center;gap:6px}
        .staff-notif-menu .notif-header .badge{background:var(--yellow-accent)!important;color:var(--navy-dark);font-size:.7rem}
        .staff-notif-menu .notif-list{max-height:360px;overflow-y:auto}
        .staff-notif-menu .notif-item{display:flex;gap:10px;padding:.75rem 1rem;border-bottom:1px solid #f1f3f5;text-decoration:none;color:inherit;transition:background .15s}
        .staff-notif-menu .notif-item:last-child{border-bottom:none}
        .staff-notif-menu .notif-item:hover{background:#f0f8ff}
        .staff-notif-menu .notif-icon{flex-shrink:0;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;color:#fff}
        .staff-notif-menu .notif-content{flex:1;min-width:0}
        .staff-notif-menu .notif-title{font-size:.85rem;font-weight:600;color:var(--navy-dark);margin:0 0 2px}
        .staff-notif-menu .notif-msg{font-size:.78rem;color:#6c757d;margin:0 0 4px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
        .staff-notif-menu .notif-time{font-size:.7rem;color:#adb5bd}
        .staff-notif-menu .notif-empty{padding:2rem 1rem;text-align:center;color:#adb5bd}
        .staff-notif-menu .notif-empty i{font-size:2rem;display:block;margin-bottom:.5rem;color:#d4edda}
        .staff-notif-menu .notif-footer{padding:.6rem 1rem;text-align:center;background:#f8f9fa;border-top:1px solid #f1f3f5}
        .staff-notif-menu .notif-footer a{font-size:.8rem;font-weight:600;color:var(--navy-dark);text-decoration:none}
        .staff-notif-menu .notif-footer a:hover{text-decoration:underline}

        .dataTables_wrapper .dataTables_filter input{border:1px solid var(--border);border-radius:8px;padding:.4rem .75rem;font-size:.85rem}
        .dataTables_wrapper .dataTables_length select{border:1px solid var(--border);border-radius:8px;padding:.35rem 1.5rem .35rem .6rem;font-size:.85rem}

        #accountSettingsModal .modal-content{border:none;border-radius:14px;box-shadow:0 20px 60px rgba(15,31,61,.25)}
        #accountSettingsModal .modal-header{background:var(--navy-dark);color:#fff;border:none;border-radius:14px 14px 0 0;padding:1rem 1.25rem}
        #accountSettingsModal .modal-header .modal-title{font-weight:700;font-size:1rem}
        #accountSettingsModal .modal-header .btn-close{filter:invert(1) brightness(2)}
        #accountSettingsModal .modal-body{padding:1.25rem 1.5rem}
        #accountSettingsModal .modal-footer{border-top:1px solid var(--border);padding:.85rem 1.25rem;gap:8px}
        #accountSettingsModal .form-label{font-weight:600;font-size:.78rem;color:var(--navy-dark);margin-bottom:.3rem}
        #accountSettingsModal .form-control:focus{border-color:var(--yellow-accent);box-shadow:0 0 0 3px rgba(251,191,36,.25)}
        #accountSettingsModal .input-group-text{background:#f8fafc;border-color:var(--border);font-weight:600;color:var(--navy-dark)}
        #accountSettingsModal hr{border-color:var(--border);opacity:1}

        #apptFilterBtns .activity-filter-btn {
            padding: .35rem .75rem;
            font-size: .78rem;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
        }
        #apptFilterBtns .activity-filter-btn.active {
            background: var(--navy-dark);
            color: #fff;
            border-color: var(--navy-dark);
        }
        #apptFilterBtns .activity-filter-btn .badge {
            font-size: .65rem;
            padding: .2em .55em;
            font-weight: 700;
        }
        #apptFilterBtns .activity-filter-btn.active .badge {
            background: var(--yellow-accent) !important;
            color: var(--navy-dark) !important;
        }

        @media (max-width: 768px) {
            #apptFilterBtns {
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 4px;
                width: 100%;
            }
            #apptFilterBtns::-webkit-scrollbar { height: 4px; }
            #apptFilterBtns::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 2px; }
            #apptFilterBtns .activity-filter-btn { flex-shrink: 0; }
        }

        #statCardModal .modal-body .table-plain thead th { position: sticky; top: 0; background: #fff; z-index: 1; }
        #statCardModal .modal-body { max-height: 70vh; overflow-y: auto; }

        /* ============================================================
           VIEW APPLICATION MODAL (embeds view_application.php)
           ============================================================ */
        #viewApplicationModal .modal-content {
            box-shadow: 0 20px 60px rgba(15,31,61,.3);
            border: none;
            border-radius: 14px;
            overflow: hidden;
        }
        #viewApplicationModal .modal-body {
            flex: 1 1 auto;
            min-height: 0;              /* critical for iframe height:100% */
            padding: 0 !important;
            position: relative;
            overflow: hidden;
        }
        #viewApplicationModal #viewAppFrame {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            background: #f0f4f8;
        }
        #viewApplicationModal .modal-header { border-bottom: none; }
        #viewApplicationModal #viewAppBusinessLabel {
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media (max-width: 768px) {
            #viewApplicationModal .modal-dialog {
                max-width: 100%;
                height: 100%;
                margin: 0;
            }
            #viewApplicationModal .modal-content { height: 100vh; border-radius: 0; }
            #viewApplicationModal .modal-footer { flex-wrap: wrap; }
            #viewApplicationModal #viewAppBusinessLabel { display: none !important; }
        }
        
        /* Arrange action buttons horizontally */
        .action-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
            min-width: 260px;
        }
        
        /* Consistent button layout */
        .action-buttons .btn-sm-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 7px 11px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #ffffff;
            color: #374151;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.2s, color 0.2s;
        }
        
        .action-buttons .btn-sm-action i {
            font-size: 14px;
        }
        
        /* View button */
        .action-buttons .view-btn:hover {
            background: #eff6ff;
            color: #2563eb;
            border-color: #93c5fd;
        }
        
        /* Confirm / Claim buttons */
        .action-buttons .dark {
            background: #1f2937;
            color: #ffffff;
            border-color: #1f2937;
        }
        
        .action-buttons .dark:hover {
            background: #374151;
        }
        
        /* Cancel button */
        .action-buttons .cancel-btn {
            color: #dc2626;
            border-color: #fecaca;
        }
        
        .action-buttons .cancel-btn:hover {
            background: #fef2f2;
            border-color: #f87171;
        }
        
        /* Keep buttons usable on smaller screens */
        @media (max-width: 768px) {
            .action-buttons {
                justify-content: flex-start;
                flex-wrap: wrap;
                min-width: 200px;
            }
        }

    </style>
</head>
<body>

<header class="top-bar">
    <div class="brand">
        <button class="mobile-menu-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
            <i class="bi bi-list"></i>
        </button>
        <div class="brand-logo">
            <img src="../img/<?php echo htmlspecialchars($headerLogo); ?>" alt="<?php echo htmlspecialchars($headerLogoAlt); ?>" class="brand-logo">
        </div>
        <div class="brand-text">
            <h6>Municipality of Placer</h6>
            <small><?php echo $role_label; ?></small>
        </div>
    </div>
    <div class="top-actions">
        <div class="dropdown">
            <a class="notif-bell" href="#" id="staffNotifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-bell<?php echo $staff_unreadCount > 0 ? '-fill' : ''; ?>"></i>
                <?php if ($staff_unreadCount > 0): ?>
                    <span class="badge-dot" id="staffNotifBadge"><?php echo $staff_unreadCount > 99 ? '99+' : $staff_unreadCount; ?></span>
                <?php endif; ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end staff-notif-menu">
                <li class="notif-header">
                    <h6><i class="bi bi-bell-fill"></i> Notifications</h6>
                    <?php if ($staff_unreadCount > 0): ?>
                        <span class="badge rounded-pill"><?php echo $staff_unreadCount; ?> new</span>
                    <?php endif; ?>
                </li>
                <li>
                    <div class="notif-list">
                        <?php if (!empty($staff_notifications)): ?>
                            <?php foreach ($staff_notifications as $n):
                                $meta = notificationMeta($n['type']);
                                $isClickable = notifIsClickable($n['link']);
                                $tag = $isClickable ? 'a' : 'div';
                            ?>
                                <<?php echo $tag; ?> class="notif-item<?php echo $isClickable ? '' : ' notif-no-link'; ?>"
                                   <?php echo notifHref($n['link']); ?> data-notif-id="<?php echo (int)$n['id']; ?>">
                                    <div class="notif-icon" style="background: <?php echo $meta['color']; ?>;">
                                        <i class="bi <?php echo $meta['icon']; ?>"></i>
                                    </div>
                                    <div class="notif-content">
                                        <p class="notif-title"><?php echo htmlspecialchars($n['title']); ?></p>
                                        <?php if (!empty($n['message'])): ?>
                                            <p class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></p>
                                        <?php endif; ?>
                                        <span class="notif-time"><i class="bi bi-clock"></i> <?php echo timeAgo($n['created_at']); ?></span>
                                    </div>
                                </<?php echo $tag; ?>>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notif-empty"><i class="bi bi-bell-slash"></i> No new notifications</div>
                        <?php endif; ?>
                    </div>
                </li>
                <li class="notif-footer"><a href="notifications.php">View all notifications</a></li>
            </ul>
        </div>

        <div class="dropdown">
            <a class="user-chip dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle"></i> <span class="user-name"><?php echo htmlspecialchars($staff_name); ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#accountSettingsModal">
                        <i class="bi bi-gear"></i> Account Settings
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</header>

<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar">
    <div class="offcanvas-header">
        <h6 class="offcanvas-title">Menu</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <nav class="nav flex-column p-3">
            <a class="nav-link active" href="#" data-section="dashboard"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <?php if ($showApprovalDocs): ?>
                <a class="nav-link" href="#" data-section="approval-docs"><i class="bi bi-file-earmark-check"></i> Manage Approval Documents</a>
            <?php endif; ?>
            <?php if ($showPaymentVerification): ?>
                <a class="nav-link" href="#" data-section="payment-verification"><i class="bi bi-credit-card-2-front"></i> Payment Verification</a>
            <?php endif; ?>
            <?php if ($showPayments): ?>
                <a class="nav-link" href="#" data-section="payments"><i class="bi bi-credit-card"></i> Monitor Payments</a>
            <?php endif; ?>
            <?php if ($showTreasuryRecords): ?>
                <a class="nav-link" href="#" data-section="treasury-records"><i class="bi bi-archive"></i> Treasury Records</a>
            <?php endif; ?>
            <?php if ($showAppointments): ?>
                <a class="nav-link" href="#" data-section="appointments"><i class="bi bi-calendar-check"></i> Appointment History</a>
            <?php endif; ?>
            <?php if ($showActivityLog): ?>
                <a class="nav-link" href="#" data-section="activity-log"><i class="bi bi-clock-history"></i> Activity Log</a>
            <?php endif; ?>
            <?php if ($showAnnouncements): ?>
                <a class="nav-link" href="#" data-section="announcements"><i class="bi bi-megaphone"></i> Announcements</a>
            <?php endif; ?>
        </nav>
    </div>
</div>

<div class="container-fluid p-0">
    <div class="row g-0">

        <aside class="col-lg-2 sidebar d-none d-lg-flex">
            <nav class="nav flex-column">
                <a class="nav-link active" href="#" data-section="dashboard">
                    <div class="nav-left"><i class="bi bi-speedometer2"></i><span>Dashboard</span></div>
                </a>
                <?php if ($showApprovalDocs): ?>
                    <a class="nav-link" href="#" data-section="approval-docs">
                        <div class="nav-left"><i class="bi bi-file-earmark-check"></i><span>Approval documents</span></div>
                    </a>
                <?php endif; ?>
                <?php if ($showPaymentVerification): ?>
                    <a class="nav-link" href="#" data-section="payment-verification">
                        <div class="nav-left"><i class="bi bi-credit-card-2-front"></i><span>Payment Verification</span></div>
                    </a>
                <?php endif; ?>
                <?php if ($showPayments): ?>
                    <a class="nav-link" href="#" data-section="payments">
                        <div class="nav-left"><i class="bi bi-credit-card"></i><span>Monitor Payments</span></div>
                    </a>
                <?php endif; ?>
                <?php if ($showTreasuryRecords): ?>
                    <a class="nav-link" href="#" data-section="treasury-records">
                        <div class="nav-left"><i class="bi bi-archive"></i><span>Treasury Records</span></div>
                    </a>
                <?php endif; ?>
                <?php if ($showAppointments): ?>
                    <a class="nav-link" href="#" data-section="appointments">
                        <div class="nav-left"><i class="bi bi-calendar-check"></i><span>Appointments</span></div>
                    </a>
                <?php endif; ?>
                <?php if ($showActivityLog): ?>
                    <a class="nav-link" href="#" data-section="activity-log">
                        <div class="nav-left"><i class="bi bi-clock-history"></i><span>Activity Log</span></div>
                    </a>
                <?php endif; ?>
                <?php if ($showAnnouncements): ?>
                    <a class="nav-link" href="#" data-section="announcements">
                        <div class="nav-left"><i class="bi bi-megaphone"></i><span>Announcements</span></div>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-bottom">
                <h6>LGU <?php echo $user_type == 4 ? 'BPLO' : 'Treasury'; ?></h6>
                <small><?php echo $staff_unreadCount; ?> unread notifications</small>
            </div>
        </aside>

        <main class="col-lg-10 main-content">

            <?php if ($message): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- DASHBOARD -->
            <div id="dashboard-section">
                <h1 class="page-greeting">Good day, <?php echo htmlspecialchars($staff_name); ?></h1>
                <p class="page-meta"><?php echo date('l, F j, Y'); ?>. <?php echo $user_type == 4 ? 'Review applications, verify documents, and issue permits.' : 'Manage payment confirmations and monitor collections.'; ?></p>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile green stat-tile-clickable" data-dash-card="total" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $stats['total']; ?></div>
                            <div class="stat-lbl">Total applications</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile yellow stat-tile-clickable" data-dash-card="pending_review" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $stats['pending_review']; ?></div>
                            <div class="stat-lbl">Ready to review</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile red stat-tile-clickable" data-dash-card="pending_payment" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $stats['pending_payment']; ?></div>
                            <div class="stat-lbl">Waiting for payment</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile blue stat-tile-clickable" data-dash-card="approved_issued" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $stats['approved_issued']; ?></div>
                            <div class="stat-lbl">Approved or issued</div>
                        </div>
                    </div>
                </div>

                <?php if ($user_type == 4): ?>
                    <?php if (count($needsReviewApps) > 0): ?>
                    <div class="panel">
                        <h5>Needs your review</h5>
                        <p class="sub">Payment is confirmed. Check documents and verify.</p>
                        <div class="table-responsive">
                            <table class="table-plain" id="needsReviewTable">
                                <thead>
                                    <tr><th>Business</th><th>Reference</th><th>Filed</th><th>Amount</th><th>Action</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($needsReviewApps as $app):
                                        $amount = isset($app['amount']) ? number_format((float)$app['amount'], 2) : '0.00';
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?></td>
                                        <td><code class="small text-primary"><?php echo htmlspecialchars($app['reference_no']); ?></code></td>
                                        <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                        <td class="fw-semibold">₱<?php echo $amount; ?></td>
                                        <td>
                                            <a href="view_application.php?id=<?php echo $app['application_id']; ?>"
                                               class="btn-yellow"
                                               style="padding: .35rem .9rem; font-size: .78rem;"
                                               data-business="<?php echo htmlspecialchars($app['business_name'] ?? 'N/A', ENT_QUOTES); ?>"
                                               data-status="<?php echo htmlspecialchars($app['status'] ?? '', ENT_QUOTES); ?>">
                                                Review documents
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($waitingOnApplicantsApps) > 0): ?>
                    <div class="panel">
                        <h5>Waiting on applicants</h5>
                        <p class="sub">Submitted, but payment is not confirmed yet.</p>
                        <div class="table-responsive">
                            <table class="table-plain" id="waitingTable">
                                <thead>
                                    <tr><th>Business</th><th>Reference</th><th>Filed</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($waitingOnApplicantsApps as $app): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?></td>
                                        <td><code class="small text-primary"><?php echo htmlspecialchars($app['reference_no']); ?></code></td>
                                        <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                        <td><span class="pill pill-submitted">Submitted</span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($statusApps) > 0): ?>
                    <div class="panel">
                        <h5>Verified / Claimed / Rejected applications</h5>
                        <p class="sub">Click an amount to view the official receipt. Print certificates for verified ones; claimed permits can be reprinted.</p>
                        <div class="table-responsive">
                            <table class="table-plain" id="statusAppsTable">
                                <thead>
                                    <tr>
                                        <th>ID</th><th>Reference</th><th>Business</th><th>Type</th>
                                        <th>Date filed</th><th>Amount</th><th>Status</th><th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($statusApps as $app):
                                        $s = strtolower($app['status']);
                                        $pillClass = in_array($s, ['verified','claimed'], true) ? 'pill-verified' : 'pill-rejected';
                                        $amount = isset($app['amount']) ? number_format((float)$app['amount'], 2) : '0.00';
                                        $isClickable = in_array($s, ['verified','claimed','payment_confirmed'], true);
                                        $isRenewal = (strtolower(trim($app['application_type'] ?? '')) === 'renewal');
                                    ?>
                                        <tr>
                                            <td><?php echo $app['application_id']; ?></td>
                                            <td><code class="small text-primary"><?php echo htmlspecialchars($app['reference_no']); ?></code></td>
                                            <td class="fw-semibold">
                                                <?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?>
                                                <?php if ($isRenewal): ?><span class="pill pill-renewal ms-1">Renewal</span><?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($app['application_type'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                            <td>
                                                <?php if ($isClickable): ?>
                                                    <span class="amount-clickable view-receipt"<?php echo renderReceiptDataAttrs($app); ?> title="Click to view official receipt">
                                                        ₱<?php echo $amount; ?> <i class="bi bi-receipt"></i>
                                                    </span>
                                                <?php else: ?>
                                                    ₱<?php echo $amount; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="pill <?php echo $pillClass; ?>"><?php echo ucfirst($s); ?></span></td>
                                            <td>
                                                <a href="view_application.php?id=<?php echo $app['application_id']; ?>"
                                                   class="btn-sm-action"
                                                   data-business="<?php echo htmlspecialchars($app['business_name'] ?? 'N/A', ENT_QUOTES); ?>"
                                                   data-status="<?php echo htmlspecialchars($app['status'] ?? '', ENT_QUOTES); ?>">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                                <?php if ($s === 'verified'): ?>
                                                    <a href="../generate_certificate.php?id=<?php echo (int)$app['application_id']; ?>" class="btn-sm-action dark" target="_blank"><i class="bi bi-printer"></i> Print</a>
                                                <?php elseif ($s === 'claimed'): ?>
                                                    <a href="../generate_certificate.php?id=<?php echo (int)$app['application_id']; ?>" class="btn-sm-action dark" target="_blank"><i class="bi bi-printer"></i> Reprint</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="panel">
                        <h5>Recent applications</h5>
                        <p class="sub">Applications awaiting payment confirmation.</p>
                        <div class="table-responsive">
                            <table class="table-plain" id="recentAppsTable">
                                <thead>
                                    <tr>
                                        <th>Business</th><th>Type</th><th>Filed</th><th>Amount</th><th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recentApps) > 0): foreach ($recentApps as $app):
                                        $s = strtolower($app['status']);
                                        $pillClass = 'pill-submitted'; $label = ucfirst(str_replace('_',' ',$s));
                                        if ($s === 'payment_confirmed') { $pillClass = 'pill-payment'; $label = 'Payment confirmed'; }
                                        elseif ($s === 'pending_payment') { $pillClass = 'pill-pending'; $label = 'Pending payment'; }
                                        elseif ($s === 'submitted') { $pillClass = 'pill-submitted'; $label = 'Submitted'; }
                                        elseif ($s === 'verified') { $pillClass = 'pill-verified'; $label = 'Verified'; }
                                        $amount = isset($app['amount']) ? number_format((float)$app['amount'], 2) : '0.00';
                                    ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($app['application_type'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                            <td class="fw-semibold">₱<?php echo $amount; ?></td>
                                            <td><span class="pill <?php echo $pillClass; ?>"><?php echo $label; ?></span></td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="5" class="text-center text-muted py-4">No applications found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- APPROVAL DOCUMENTS -->
            <?php if ($showApprovalDocs): ?>
            <div id="approval-docs-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-4 gap-3">
                    <div>
                        <h1 class="page-greeting" style="font-size:1.4rem;">Approval documents</h1>
                        <p class="page-meta mb-0">Check each document, then verify. The applicant gets an email when you verify.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn-outline-dark-custom" onclick="refreshTable()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                        <button class="btn-outline-dark-custom" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile green stat-tile-clickable" data-approval-card="total" role="button" tabindex="0">
                            <div class="stat-val"><?php echo count($allApps); ?></div>
                            <div class="stat-lbl">Total documents</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile yellow stat-tile-clickable" data-approval-card="pending" role="button" tabindex="0">
                            <div class="stat-val"><?php $pc=0; foreach($allApps as $a){ if(in_array(strtolower($a['status']),['submitted','under_review','pending_payment'])) $pc++; } echo $pc; ?></div>
                            <div class="stat-lbl">Pending</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile blue stat-tile-clickable" data-approval-card="approved" role="button" tabindex="0">
                            <div class="stat-val"><?php $ac=0; foreach($allApps as $a){ if(in_array(strtolower($a['status']),['approved','issued','verified','claimed'])) $ac++; } echo $ac; ?></div>
                            <div class="stat-lbl">Approved</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile red stat-tile-clickable" data-approval-card="cancelled" role="button" tabindex="0">
                            <div class="stat-val"><?php $cc=0; foreach($allApps as $a){ if(strtolower($a['status'])==='cancelled') $cc++; } echo $cc; ?></div>
                            <div class="stat-lbl">Cancelled</div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <h5 class="mb-3">Applications for verification</h5>
                    <div class="table-responsive">
                        <table class="table-plain" id="approvalTable">
                            <thead>
                                <tr>
                                    <th>ID</th><th>Reference</th><th>Business</th><th>Type</th>
                                    <th>Filed</th><th>Amount</th><th>Docs</th><th>Status</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($allApps) > 0): foreach ($allApps as $app):
                                    $s = strtolower($app['status']);
                                    $pillClass='pill-submitted'; $label=ucfirst(str_replace('_',' ',$s));
                                    if ($s==='payment_confirmed'){ $pillClass='pill-payment'; $label='Payment confirmed'; }
                                    elseif ($s==='submitted'){ $pillClass='pill-submitted'; $label='Submitted'; }
                                    elseif ($s==='verified'){ $pillClass='pill-verified'; $label='Verified'; }
                                    $amount = isset($app['amount']) ? number_format((float)$app['amount'],2) : '0.00';
                                    $docCount = $app['document_count'] ?? 0;
                                    $isClickable = in_array($s, ['payment_confirmed','verified']);
                                    $isRenewal = (strtolower(trim($app['application_type'] ?? '')) === 'renewal');
                                ?>
                                    <tr>
                                        <td><?php echo $app['application_id']; ?></td>
                                        <td><code class="small text-primary"><?php echo htmlspecialchars($app['reference_no']); ?></code></td>
                                        <td class="fw-semibold">
                                            <?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?>
                                            <?php if ($isRenewal): ?><span class="pill pill-renewal ms-1">Renewal</span><?php endif; ?>
                                            <?php if (!empty($app['resubmitted_at'])): ?>
                                                <span class="pill pill-pending ms-1"><i class="bi bi-arrow-up-circle"></i> Resubmitted</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($app['application_type'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                        <td>
                                            <?php if ($isClickable): ?>
                                                <span class="amount-clickable view-receipt"<?php echo renderReceiptDataAttrs($app); ?> title="Click to view official receipt">
                                                    ₱<?php echo $amount; ?> <i class="bi bi-receipt"></i>
                                                </span>
                                            <?php else: ?>
                                                ₱<?php echo $amount; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="pill pill-info"><i class="bi bi-file-earmark"></i> <?php echo $docCount; ?></span></td>
                                        <td><span class="pill <?php echo $pillClass; ?>"><?php echo $label; ?></span></td>
                                        <td>
                                            <a href="view_application.php?id=<?php echo $app['application_id']; ?>"
                                               class="btn-sm-action"
                                               title="View Application"
                                               data-business="<?php echo htmlspecialchars($app['business_name'] ?? 'N/A', ENT_QUOTES); ?>"
                                               data-status="<?php echo htmlspecialchars($app['status'] ?? '', ENT_QUOTES); ?>">
                                                <i class="bi bi-file-earmark-text"></i>
                                            </a>
                                            <?php if ($s !== 'submitted'): ?>
                                                <button class="btn-sm-action view-documents"
                                                        data-id="<?php echo $app['application_id']; ?>"
                                                        data-business="<?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?>"
                                                        data-ref="<?php echo htmlspecialchars($app['reference_no']); ?>"
                                                        data-resubmitted="<?php echo !empty($app['resubmitted_at'])?'1':'0'; ?>"
                                                        data-resubmitted-date="<?php echo !empty($app['resubmitted_at'])?date('M j, Y g:i A', strtotime($app['resubmitted_at'])):''; ?>"
                                                        title="View Documents"><i class="bi bi-eye"></i></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No payment-confirmed applications found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- PAYMENT VERIFICATION -->
            <?php if ($showPaymentVerification): ?>
            <div id="payment-verification-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-4 gap-3">
                    <div>
                        <h1 class="page-greeting" style="font-size:1.4rem;">Payment verification</h1>
                        <p class="page-meta mb-0">Confirm payments received from applicants.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn-outline-dark-custom" onclick="refreshTable()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                        <button class="btn-outline-dark-custom" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                    </div>
                </div>
                <div class="panel">
                    <h5>Applications awaiting payment confirmation</h5>
                    <div class="table-responsive">
                        <table class="table-plain" id="paymentVerificationTable">
                            <thead>
                                <tr><th>ID</th><th>Reference</th><th>Business</th><th>Type</th><th>Filed</th><th>Amount</th><th>Status</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php if (count($pendingPaymentApps) > 0): foreach ($pendingPaymentApps as $app):
                                    $amount = isset($app['amount']) ? number_format((float)$app['amount'], 2) : '0.00';
                                ?>
                                    <tr>
                                        <td><?php echo $app['application_id']; ?></td>
                                        <td><code class="small text-primary"><?php echo htmlspecialchars($app['reference_no']); ?></code></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($app['application_type'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                        <td class="fw-semibold">₱<?php echo $amount; ?></td>
                                        <td><span class="pill pill-pending">Pending payment</span></td>
                                        <td>
                                            <a href="view_application.php?id=<?php echo $app['application_id']; ?>"
                                               class="btn-sm-action"
                                               data-business="<?php echo htmlspecialchars($app['business_name'] ?? 'N/A', ENT_QUOTES); ?>"
                                               data-status="<?php echo htmlspecialchars($app['status'] ?? '', ENT_QUOTES); ?>">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="payment.php?ref=<?php echo urlencode($app['reference_no']); ?>" class="btn-sm-action dark"><i class="bi bi-calculator"></i></a>
                                            <button class="btn-sm-action status-update-btn"
                                                    data-id="<?php echo $app['application_id']; ?>"
                                                    data-ref="<?php echo htmlspecialchars($app['reference_no']); ?>"
                                                    data-business="<?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?>"
                                                    data-amount="<?php echo $amount; ?>"
                                                    data-status="pending_payment"
                                                    data-user-type="3"
                                                    title="Update Status"><i class="bi bi-arrow-repeat"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">No pending payments to verify.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- PAYMENTS / MONITOR -->
            <?php if ($showPayments): ?>
            <div id="payments-section" style="display:none;">
                <h1 class="page-greeting" style="font-size:1.4rem;">Monitor payments</h1>
                <p class="page-meta">PayPal transactions and monthly collections.</p>

                <div class="row g-3 mb-4">
                    <div class="col-lg-8">
                        <div class="panel">
                            <h5>Monthly collections (₱)</h5>
                            <p class="sub">Only <strong>completed</strong> PayPal transactions — last 12 months.</p>
                            <canvas id="paymentChart" height="200"></canvas>
                            <?php if (!$hasMonthlyData): ?>
                                <p class="text-muted small mt-2 mb-0"><i class="bi bi-info-circle"></i> No matched payments in <?php echo $currentYear; ?> yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="panel">
                            <h5>Total matched</h5>
                            <p class="sub">Sum of last 12 months</p>
                            <div class="stat-tile green" style="box-shadow:none;">
                                <div class="stat-val">₱<?php echo number_format($monthlyTotal, 2); ?></div>
                                <div class="stat-lbl">Matched collections</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <h5>All payments</h5>
                    <p class="sub">From PayPal</p>
                    <?php if ($paymentsError): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($paymentsError); ?></div>
                    <?php elseif (empty($payments)): ?>
                        <div class="alert alert-info">No payments found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="paymentsTable" class="table-plain">
                                <thead>
                                    <tr><th>Transaction ID</th><th>Reference</th><th>Amount</th><th>Status</th><th>Paid at</th><th>Method</th><th>Action</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment):
                                        $txnId = $payment['paypal_capture_id'] ?: ($payment['paypal_order_id'] ?: 'PT-' . $payment['id']);
                                        $ref = $payment['reference_no'] ?: $payment['app_ref'] ?: '—';
                                        $amount = number_format((float)$payment['amount'], 2);
                                        $currency = strtoupper($payment['currency'] ?? 'PHP');
                                        $status = strtolower($payment['payment_status'] ?? 'unknown');
                                        $pillClass = 'pill-pending';
                                        if (in_array($status, ['completed','paid'])) $pillClass = 'pill-verified';
                                        elseif (in_array($status, ['failed','voided','cancelled','expired'])) $pillClass = 'pill-rejected';
                                        $paidAt = $payment['paid_at'] ? date('M d, Y H:i', strtotime($payment['paid_at'])) : '—';
                                        $appId = (int)($payment['application_id'] ?? 0);
                                        $showView = ($status === 'completed' || $status === 'paid') && $appId > 0;
                                    ?>
                                        <tr>
                                            <td><code class="small"><?php echo htmlspecialchars(substr($txnId, 0, 18)); ?></code></td>
                                            <td><?php echo htmlspecialchars($ref); ?></td>
                                            <td class="fw-semibold"><?php echo ($currency === 'PHP' ? '₱' : $currency . ' ') . $amount; ?></td>
                                            <td><span class="pill <?php echo $pillClass; ?>"><?php echo ucfirst($status); ?></span></td>
                                            <td><?php echo $paidAt; ?></td>
                                            <td>PayPal</td>
                                            <td>
                                                <?php if ($showView): ?>
                                                    <a href="treasury_record.php?id=<?php echo $appId; ?>" class="btn-sm-action" target="_blank"><i class="bi bi-eye"></i> View</a>
                                                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- TREASURY RECORDS -->
            <?php if ($showTreasuryRecords): ?>
            <div id="treasury-records-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-4 gap-3">
                    <div>
                        <h1 class="page-greeting" style="font-size:1.4rem;">Treasury records</h1>
                        <p class="page-meta mb-0">Official record of assessed and confirmed payments.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn-outline-dark-custom" onclick="exportTableToCSV('treasuryRecordsTable')"><i class="bi bi-download"></i> Export</button>
                        <button class="btn-outline-dark-custom" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                    </div>
                </div>
                <div class="panel">
                    <div class="table-responsive">
                        <table class="table-plain" id="treasuryRecordsTable">
                            <thead>
                                <tr><th>ID</th><th>Reference</th><th>Business</th><th>Type</th><th>Filed</th><th>Amount</th><th>Tax year</th><th>Assessed by</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php if (count($treasuryRecords) > 0): foreach ($treasuryRecords as $rec):
                                    $amount = number_format((float)$rec['amount'], 2);
                                    $assessedBy = !empty($rec['assessed_by_name']) ? $rec['assessed_by_name'] : 'N/A';
                                ?>
                                    <tr>
                                        <td><?php echo $rec['application_id']; ?></td>
                                        <td><code class="small text-primary"><?php echo htmlspecialchars($rec['reference_no']); ?></code></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($rec['business_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($rec['application_type'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($rec['application_date'])); ?></td>
                                        <td class="fw-semibold">₱<?php echo $amount; ?></td>
                                        <td><?php echo $rec['tax_year'] ?? date('Y'); ?></td>
                                        <td><?php echo htmlspecialchars($assessedBy); ?></td>
                                        <td><a href="treasury_record.php?id=<?php echo $rec['application_id']; ?>" class="btn-sm-action" target="_blank"><i class="bi bi-eye"></i> View</a></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No treasury records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- APPOINTMENTS -->
            <?php if ($showAppointments): ?>
            <div id="appointments-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-4 gap-3">
                    <div>
                        <h1 class="page-greeting" style="font-size:1.4rem;">Appointments</h1>
                        <p class="page-meta mb-0">Confirm, complete, or cancel applicant bookings. Confirmed applicants receive a notification.</p>
                    </div>
                    <button class="btn-outline-dark-custom" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                </div>

                <?php
                $todayStr  = date('Y-m-d');
                $cntToday  = 0; $cntPending = 0; $cntCompleted = 0;
                foreach ($appointments as $a) {
                    if ($a['appointment_date'] === $todayStr) $cntToday++;
                    $st = strtolower($a['appointment_status'] ?? '');
                    if ($st === 'pending')   $cntPending++;
                    if ($st === 'completed') $cntCompleted++;
                }
                ?>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile green stat-tile-clickable" data-appt-stat="total" role="button" tabindex="0">
                            <div class="stat-val"><?php echo count($appointments); ?></div>
                            <div class="stat-lbl">Total <i class="bi bi-box-arrow-up-right"></i></div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile yellow stat-tile-clickable" data-appt-stat="today" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $cntToday; ?></div>
                            <div class="stat-lbl">Today <i class="bi bi-box-arrow-up-right"></i></div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile blue stat-tile-clickable" data-appt-stat="pending" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $cntPending; ?></div>
                            <div class="stat-lbl">Awaiting confirmation <i class="bi bi-box-arrow-up-right"></i></div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile purple stat-tile-clickable" data-appt-stat="completed" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $cntCompleted; ?></div>
                            <div class="stat-lbl">Completed <i class="bi bi-box-arrow-up-right"></i></div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h5 class="mb-0">Calendar</h5>
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <span class="pill pill-pending">Pending</span>
                            <span class="pill pill-payment">Confirmed</span>
                            <span class="pill pill-verified">Completed</span>
                            <span class="pill pill-rejected">Cancelled</span>
                            <span id="calendarFilterHint" class="pill pill-info d-none" style="font-size:.7rem;">
                                <i class="bi bi-funnel-fill"></i>
                                Filtered: <span id="calendarFilterLabel">All</span>
                            </span>
                        </div>
                    </div>
                    <div id="appointmentsCalendar"></div>
                </div>

                <div class="panel">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <h5 class="mb-1">Manage appointments</h5>
                            <p class="sub mb-0">Confirm pending bookings, mark confirmed ones as completed, or cancel.</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap" id="apptFilterBtns">
                            <button type="button" class="activity-filter-btn active" data-appt-filter="all">
                                <i class="bi bi-list-ul"></i> All
                                <span class="badge bg-secondary ms-1" id="cntAll">0</span>
                            </button>
                            <button type="button" class="activity-filter-btn" data-appt-filter="pending">
                                <i class="bi bi-hourglass-split" style="color:#f59e0b;"></i> Pending
                                <span class="badge bg-warning text-dark ms-1" id="cntPending">0</span>
                            </button>
                            <button type="button" class="activity-filter-btn" data-appt-filter="confirmed">
                                <i class="bi bi-check2-circle" style="color:#3b82f6;"></i> Confirmed
                                <span class="badge bg-primary ms-1" id="cntConfirmed">0</span>
                            </button>
                            <button type="button" class="activity-filter-btn" data-appt-filter="completed">
                                <i class="bi bi-check-all" style="color:#10b981;"></i> Completed
                                <span class="badge bg-success ms-1" id="cntCompleted">0</span>
                            </button>
                            <button type="button" class="activity-filter-btn" data-appt-filter="cancelled">
                                <i class="bi bi-x-circle" style="color:#ef4444;"></i> Cancelled
                                <span class="badge bg-danger ms-1" id="cntCancelled">0</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table-plain" id="appointmentsTable">
                            <thead>
                                <tr>
                                    <th>Date &amp; time</th>
                                    <th>Applicant</th>
                                    <th>Business</th>
                                    <th>Reference</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (count($appointments) > 0): foreach ($appointments as $appt):
                                $fullName = trim(($appt['firstname'] ?? '') . ' ' . ($appt['lastname'] ?? ''));
                                if ($fullName === '') $fullName = 'Applicant #' . ($appt['user_id'] ?? '?');
                                $st = strtolower($appt['appointment_status'] ?? 'pending');
                                $pillClass = 'pill-pending'; $stLabel = 'Pending';
                                if ($st === 'confirmed')      { $pillClass = 'pill-payment';  $stLabel = 'Confirmed'; }
                                elseif ($st === 'completed')  { $pillClass = 'pill-verified'; $stLabel = 'Completed'; }
                                elseif ($st === 'cancelled')  { $pillClass = 'pill-rejected'; $stLabel = 'Cancelled'; }

                                $apptId     = (int)$appt['appointment_id'];
                                $dateStr    = date('M j, Y', strtotime($appt['appointment_date']));
                                $dayName    = date('D',       strtotime($appt['appointment_date']));
                                $timeStr    = htmlspecialchars($appt['appointment_time'] ?? '');
                                $nameJs     = htmlspecialchars($fullName, ENT_QUOTES);
                            ?>
                                <tr data-appt-id="<?php echo $apptId; ?>" data-status="<?php echo $st; ?>">
                                    <td data-order="<?php echo (int)(strtotime($appt['appointment_date'] . ' ' . preg_replace('/\s*[-–—].*$/u', '', (string)($appt['appointment_time'] ?? ''))) ?: strtotime($appt['appointment_date'])); ?>">
                                        <div class="fw-semibold"><?php echo $dayName . ', ' . $dateStr; ?></div>
                                        <small class="text-muted"><i class="bi bi-clock"></i> <?php echo $timeStr; ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($fullName); ?></div>
                                        <small class="text-muted">
                                            <?php if (!empty($appt['email'])): ?><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($appt['email']); ?><?php endif; ?>
                                            <?php if (!empty($appt['contact_number'])): ?><br><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($appt['contact_number']); ?><?php endif; ?>
                                        </small>
                                    </td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($appt['business_name'] ?? 'N/A'); ?></td>
                                    <td><code class="small text-primary"><?php echo htmlspecialchars($appt['reference_no'] ?? 'N/A'); ?></code></td>
                                    <td><?php echo htmlspecialchars($appt['application_type'] ?? 'N/A'); ?></td>
                                    <td><span class="pill <?php echo $pillClass; ?> js-status-pill"><?php echo $stLabel; ?></span></td>
                  
                                    <td class="action-buttons">
                                        <!-- View Application -->
                                        <a href="view_application.php?id=<?php echo (int)($appt['application_id'] ?? 0); ?>"
                                           class="btn-sm-action view-btn"
                                           title="View application"
                                           data-business="<?php echo htmlspecialchars($appt['business_name'] ?? 'N/A', ENT_QUOTES); ?>"
                                           data-status="<?php echo htmlspecialchars($appt['application_status'] ?? '', ENT_QUOTES); ?>">
                                            <i class="bi bi-eye"></i>
                                            <span>View</span>
                                        </a>
                                    
                                        <?php if ($st === 'pending'): ?>
                                    
                                            <!-- Confirm -->
                                            <button type="button"
                                                    class="btn-sm-action dark appt-action confirm-btn"
                                                    data-id="<?php echo (int)$apptId; ?>"
                                                    data-status="confirmed"
                                                    data-name="<?php echo htmlspecialchars($nameJs, ENT_QUOTES); ?>"
                                                    title="Confirm application">
                                                <i class="bi bi-check2-circle"></i>
                                                <span>Confirm</span>
                                            </button>
                                    
                                            <!-- Cancel -->
                                            <button type="button"
                                                    class="btn-sm-action appt-action cancel-btn"
                                                    data-id="<?php echo (int)$apptId; ?>"
                                                    data-status="cancelled"
                                                    data-name="<?php echo htmlspecialchars($nameJs, ENT_QUOTES); ?>"
                                                    title="Cancel application">
                                                <i class="bi bi-x-circle"></i>
                                                <span>Cancel</span>
                                            </button>
                                    
                                        <?php elseif ($st === 'confirmed'): ?>
                                    
                                            <!-- Mark as Completed -->
                                            <button type="button"
                                                    class="btn-sm-action dark appt-action claim-btn"
                                                    data-id="<?php echo (int)$apptId; ?>"
                                                    data-status="completed"
                                                    data-name="<?php echo htmlspecialchars($nameJs, ENT_QUOTES); ?>"
                                                    title="Mark application as completed">
                                                <i class="bi bi-check-all"></i>
                                                <span>Claim</span>
                                            </button>
                                    
                                            <!-- Cancel -->
                                            <button type="button"
                                                    class="btn-sm-action appt-action cancel-btn"
                                                    data-id="<?php echo (int)$apptId; ?>"
                                                    data-status="cancelled"
                                                    data-name="<?php echo htmlspecialchars($nameJs, ENT_QUOTES); ?>"
                                                    title="Cancel application">
                                                <i class="bi bi-x-circle"></i>
                                                <span>Cancel</span>
                                            </button>
                                    
                                        <?php else: ?>
                                    
                                            <span class="text-muted small">No actions available</span>
                                    
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No appointments booked yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ACTIVITY LOG -->
            <?php if ($showActivityLog): ?>
            <div id="activity-log-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-4 gap-3">
                    <div>
                        <h1 class="page-greeting" style="font-size:1.4rem;">Activity log</h1>
                        <p class="page-meta mb-0">Unified transaction log — status changes, fee assessments, document rejections, payments, appointments, uploads, submissions, and announcements.</p>
                    </div>
                    <span class="pill pill-info"><i class="bi bi-clock-history"></i> <?php echo $activityStats['total']; ?> records</span>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile blue stat-tile-clickable" data-act-card="total" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['total']; ?></div>
                            <div class="stat-lbl">Total activities</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile green stat-tile-clickable" data-act-card="today" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['today']; ?></div>
                            <div class="stat-lbl">Today</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile purple stat-tile-clickable" data-act-card="week" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['week']; ?></div>
                            <div class="stat-lbl">This week</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile red stat-tile-clickable" data-act-card="reject" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['reject']; ?></div>
                            <div class="stat-lbl">Rejections</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile green stat-tile-clickable" data-act-card="bplo" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['bplo']; ?></div>
                            <div class="stat-lbl">BPLO actions</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile yellow stat-tile-clickable" data-act-card="treasury" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['treasury']; ?></div>
                            <div class="stat-lbl">Treasury actions</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile purple stat-tile-clickable" data-act-card="document_actions" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['document_actions']; ?></div>
                            <div class="stat-lbl">Document actions</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile blue stat-tile-clickable" data-act-card="verified" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['verified']; ?></div>
                            <div class="stat-lbl">Verified</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile green stat-tile-clickable" data-act-card="payments" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['payments']; ?></div>
                            <div class="stat-lbl">PayPal payments</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile purple stat-tile-clickable" data-act-card="appointments" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['appointments']; ?></div>
                            <div class="stat-lbl">Appointment events</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile blue stat-tile-clickable" data-act-card="documents" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['documents']; ?></div>
                            <div class="stat-lbl">Document uploads</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-tile yellow stat-tile-clickable" data-act-card="submissions" role="button" tabindex="0">
                            <div class="stat-val"><?php echo $activityStats['submissions']; ?></div>
                            <div class="stat-lbl">Applications submitted</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div class="d-flex gap-2 flex-wrap" id="activityFilterBtns">
                        <button type="button" class="activity-filter-btn active" data-role="all"><i class="bi bi-list-ul"></i> All</button>
                        <button type="button" class="activity-filter-btn" data-role="documents"><i class="bi bi-file-earmark-check"></i> Documents</button>
                        <button type="button" class="activity-filter-btn" data-role="payment"><i class="bi bi-paypal"></i> Payments</button>
                        <button type="button" class="activity-filter-btn" data-role="appointment"><i class="bi bi-calendar-check"></i> Appointments</button>
                        <button type="button" class="activity-filter-btn" data-role="submission"><i class="bi bi-file-earmark-plus"></i> Submissions</button>
                        <button type="button" class="activity-filter-btn" data-role="announcement"><i class="bi bi-megaphone"></i> Announcements</button>
                        <button type="button" class="activity-filter-btn" data-role="4"><i class="bi bi-file-earmark-check"></i> BPLO</button>
                        <button type="button" class="activity-filter-btn" data-role="3"><i class="bi bi-cash-coin"></i> Treasury</button>
                        <button type="button" class="activity-filter-btn" data-role="5"><i class="bi bi-shield-lock"></i> Admin</button>
                        <button type="button" class="activity-filter-btn" data-role="0"><i class="bi bi-gear"></i> System</button>
                    </div>
                    <span class="pill pill-info"><i class="bi bi-info-circle"></i> Click reference to view application</span>
                </div>

                <div class="panel">
                    <h5>All transaction records</h5>
                    <p class="sub">Newest first. Showing up to 500 entries.</p>
                    <div class="table-responsive">
                        <table class="table-plain" id="activityLogTable">
                            <thead>
                                <tr>
                                    <th>#</th><th>Date &amp; time</th><th>Event</th><th>Reference</th>
                                    <th>Business / owner</th><th>Previous</th><th>New status</th>
                                    <th>Performed by</th><th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($activityLog) > 0): ?>
                                    <?php $counter = 1; foreach ($activityLog as $log):
                                        $newSt = strtolower($log['new_status'] ?? '');
                                        $oldSt = strtolower($log['old_status'] ?? '');
                                        $eventType = $log['event_type'] ?? 'status_change';

                                        $newBadge = 'pill-pending';
                                        if ($newSt === 'submitted') $newBadge = 'pill-submitted';
                                        elseif ($newSt === 'under_review') $newBadge = 'pill-info';
                                        elseif ($newSt === 'pending_payment') $newBadge = 'pill-pending';
                                        elseif ($newSt === 'payment_confirmed') $newBadge = 'pill-payment';
                                        elseif ($newSt === 'completed') $newBadge = 'pill-verified';
                                        elseif ($newSt === 'paid') $newBadge = 'pill-verified';
                                        elseif (in_array($newSt, ['approved','claimed','verified','assessed','uploaded','booked'])) $newBadge = 'pill-verified';
                                        elseif ($newSt === 'rejected') $newBadge = 'pill-rejected';
                                        elseif ($newSt === 'cancelled') $newBadge = 'pill-cancelled';

                                        $oldBadge = 'pill-draft';
                                        if (in_array($oldSt, ['approved','claimed','verified'])) $oldBadge = 'pill-verified';
                                        elseif ($oldSt === 'rejected') $oldBadge = 'pill-rejected';
                                        elseif ($oldSt === 'under_review') $oldBadge = 'pill-info';
                                        elseif ($oldSt === 'pending_payment') $oldBadge = 'pill-pending';
                                        elseif ($oldSt === 'submitted') $oldBadge = 'pill-submitted';

                                        $eventIcon  = 'bi-arrow-repeat';
                                        $eventLabel = 'Status change';
                                        $eventColor = '#6366f1';

                                        switch ($eventType) {
                                            case 'fee_assessment':
                                                $eventIcon = 'bi-cash-coin';      $eventLabel = 'Fee assessment';       $eventColor = '#f59e0b'; break;
                                            case 'document_rejection':
                                            case 'rejection':
                                                $eventIcon = 'bi-file-earmark-x'; $eventLabel = 'Document rejection';   $eventColor = '#ef4444'; break;
                                            case 'payment':
                                                $eventIcon = 'bi-paypal';         $eventLabel = 'PayPal payment';       $eventColor = '#0070ba'; break;
                                            case 'appointment_created':
                                                $eventIcon = 'bi-calendar-plus';  $eventLabel = 'Appointment booked';   $eventColor = '#8b5cf6'; break;
                                            case 'appointment_confirmed':
                                                $eventIcon = 'bi-calendar-check'; $eventLabel = 'Appointment confirmed';$eventColor = '#3b82f6'; break;
                                            case 'appointment_completed':
                                                $eventIcon = 'bi-calendar-check-fill'; $eventLabel = 'Appointment completed'; $eventColor = '#10b981'; break;
                                            case 'appointment_cancelled':
                                                $eventIcon = 'bi-calendar-x';     $eventLabel = 'Appointment cancelled';$eventColor = '#ef4444'; break;
                                            case 'document_upload':
                                                $eventIcon = 'bi-cloud-upload';   $eventLabel = 'Document uploaded';    $eventColor = '#06b6d4'; break;
                                            case 'application_submitted':
                                                $eventIcon = 'bi-file-earmark-plus'; $eventLabel = 'Application submitted'; $eventColor = '#10b981'; break;
                                            case 'announcement_pending':
                                                $eventIcon = 'bi-megaphone';      $eventLabel = 'Announcement pending'; $eventColor = '#f59e0b'; break;
                                            case 'announcement_approved':
                                                $eventIcon = 'bi-megaphone-fill'; $eventLabel = 'Announcement approved';$eventColor = '#10b981'; break;
                                            case 'announcement_rejected':
                                                $eventIcon = 'bi-megaphone';      $eventLabel = 'Announcement rejected';$eventColor = '#ef4444'; break;
                                            default:
                                                $eventIcon = 'bi-arrow-repeat';   $eventLabel = 'Status change';        $eventColor = '#6366f1';
                                        }

                                        $actorT = (int)($log['actor_type'] ?? 0);
                                        $roleLabel = 'System';
                                        $roleBadge = 'background:#e5e7eb; color:#374151;';
                                        if ($actorT === 3) { $roleLabel = 'Treasury'; $roleBadge = 'background:#fef3c7; color:#92400e;'; }
                                        elseif ($actorT === 4) { $roleLabel = 'BPLO'; $roleBadge = 'background:#d1fae5; color:#065f46;'; }
                                        elseif ($actorT === 5) { $roleLabel = 'Admin'; $roleBadge = 'background:#e9d5ff; color:#6b21a8;'; }
                                        elseif ($actorT === 2) { $roleLabel = 'Staff'; $roleBadge = 'background:#dbeafe; color:#1e40af;'; }
                                    ?>
                                        <tr data-actor-role="<?php echo $actorT; ?>" data-event-type="<?php echo htmlspecialchars($eventType, ENT_QUOTES); ?>">
                                            <td><?php echo $counter++; ?></td>
                                            <td data-order="<?php echo (int)strtotime($log['event_time']); ?>">
                                                <div class="fw-semibold"><?php echo date('M d, Y', strtotime($log['event_time'])); ?></div>
                                                <small class="text-muted"><i class="bi bi-clock"></i> <?php echo date('g:i A', strtotime($log['event_time'])); ?></small>
                                            </td>
                                            <td>
                                                <span class="event-tag" style="background:<?php echo $eventColor; ?>22; color:<?php echo $eventColor; ?>;">
                                                    <i class="bi <?php echo $eventIcon; ?>"></i> <?php echo $eventLabel; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($log['reference_no'])): ?>
                                                    <a href="../permit.php?ref=<?php echo urlencode($log['reference_no']); ?>" target="_blank" class="app-ref-link">
                                                        <?php echo htmlspecialchars($log['reference_no']); ?>
                                                        <i class= style="font-size:.7rem;"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                                <?php if (!empty($log['application_no'])): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($log['application_no']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($log['business_name'] ?? '—'); ?></div>
                                                <small class="text-muted"><i class="bi bi-person"></i> <?php echo htmlspecialchars($log['owner_name'] ?? 'Unknown'); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($oldSt && $oldSt !== '—'): ?>
                                                    <span class="pill <?php echo $oldBadge; ?>"><?php echo ucfirst(str_replace('_',' ', $oldSt)); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="pill <?php echo $newBadge; ?>"><?php echo ucfirst(str_replace('_',' ', $newSt)); ?></span></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($log['actor_name'] ?? 'System'); ?></div>
                                                <small class="role-tag" style="<?php echo $roleBadge; ?>"><?php echo $roleLabel; ?></small>
                                            </td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($log['notes'] ?? '—'); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No activity records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ANNOUNCEMENTS -->
            <?php if ($showAnnouncements): ?>
            <div id="announcements-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-4 gap-3">
                    <div>
                        <h1 class="page-greeting" style="font-size:1.4rem;">Announcements</h1>
                        <p class="page-meta mb-0">New announcements stay Pending until an admin approves them.</p>
                    </div>
                    <button class="btn-yellow" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">Add announcement</button>
                </div>
                <div class="panel">
                    <div class="table-responsive">
                        <table class="table-plain" id="staffAnnouncementsTable">
                            <thead>
                                <tr><th>Title</th><th>Content</th><th>Status</th><th>Created</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php if (count($staffAnnouncements) > 0): foreach ($staffAnnouncements as $ann):
                                    $sc = 'pill-pending';
                                    if ($ann['status'] === 'approved') $sc = 'pill-verified';
                                    elseif ($ann['status'] === 'rejected') $sc = 'pill-rejected';
                                ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($ann['title']); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars(substr($ann['content'], 0, 60)) . (strlen($ann['content'])>60?'…':''); ?></td>
                                        <td><span class="pill <?php echo $sc; ?>"><?php echo ucfirst($ann['status']); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></td>
                                        <td>
                                            <?php if ($ann['status'] === 'pending'): ?>
                                                <button class="btn-sm-action edit-staff-announcement"
                                                        data-id="<?php echo $ann['id']; ?>"
                                                        data-title="<?php echo htmlspecialchars($ann['title']); ?>"
                                                        data-content="<?php echo htmlspecialchars($ann['content']); ?>"
                                                        data-bs-toggle="modal" data-bs-target="#editAnnouncementModal">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this pending announcement?');">
                                                    <input type="hidden" name="id" value="<?php echo $ann['id']; ?>">
                                                    <button type="submit" name="delete_announcement" class="btn-sm-action"><i class="bi bi-trash text-danger"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small"><i class="bi bi-lock"></i> Locked</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">You have not created any announcements.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- Account Settings Modal -->
<div class="modal fade" id="accountSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>Account Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="accountSettingsForm" autocomplete="off">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" name="firstname" class="form-control" required
                                   value="<?php echo htmlspecialchars($acctData['firstname']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="lastname" class="form-control" required
                                   value="<?php echo htmlspecialchars($acctData['lastname']); ?>">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Email (cannot be changed)</label>
                        <input type="email" class="form-control" disabled
                               value="<?php echo htmlspecialchars($acctData['email']); ?>">
                        <small class="text-muted">Contact support to change your email.</small>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Contact Number</label>
                        <div class="input-group">
                            <span class="input-group-text">+63</span>
                            <input type="tel" name="contact" class="form-control"
                                   value="<?php echo htmlspecialchars($acctData['contact']); ?>"
                                   placeholder="9123456789" pattern="[0-9 ]*">
                        </div>
                        <small class="text-muted">Digits only (e.g., 9123456789).</small>
                    </div>
                    <hr class="my-4">
                    <h6 class="fw-bold mb-0">Change Password <span class="text-muted fw-normal small">(optional)</span></h6>
                    <div class="mt-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control">
                    </div>
                    <div class="mt-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control">
                        <small class="text-muted">At least 8 characters</small>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-yellow"><i class="bi bi-check-lg"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Announcement -->
<div class="modal fade" id="addAnnouncementModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Add announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Content</label><textarea name="content" class="form-control" rows="4" required></textarea></div>
                    <div class="alert alert-warning mb-0" style="border-radius:8px;"><i class="bi bi-clock me-1"></i> This announcement will be submitted for <strong>admin approval</strong>.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_announcement" class="btn-yellow">Submit for approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Announcement -->
<div class="modal fade" id="editAnnouncementModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="id" id="editStaffAnnId">
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" id="editStaffAnnTitle" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Content</label><textarea name="content" id="editStaffAnnContent" class="form-control" rows="4" required></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_announcement" class="btn-yellow">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Document Viewer -->
<div class="modal fade" id="documentViewerModal" tabindex="-1">
    <div class="modal-dialog modal-lg" style="max-width:900px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i><span id="docViewerTitle">Documents</span>
                    <small class="text-muted ms-2" id="docViewerRef"></small></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-5" id="docLoadingIndicator">
                    <div class="spinner-border" style="color:var(--navy-dark);" role="status"></div>
                    <p class="mt-2 small text-muted">Loading documents…</p>
                </div>
                <div id="documentsList" style="display:none;"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- File Viewer -->
<div class="modal fade" id="fileViewerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark me-2"></i><span id="fileViewerTitle">File viewer</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body"><div id="fileViewerContent"><div class="text-center py-5"><div class="spinner-border" style="color:var(--navy-dark);"></div></div></div></div>
            <div class="modal-footer">
                <a href="#" id="fileViewerDownload" class="btn-yellow" target="_blank"><i class="bi bi-download"></i> Download file</a>
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Official Receipt Modal -->
<div class="modal fade receipt-modal" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-receipt me-2"></i> Official receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div id="receiptLoading" class="text-center py-5">
                    <div class="spinner-border" style="color:var(--navy-dark);"></div>
                    <p class="mt-2 small text-muted">Loading receipt…</p>
                </div>
                <div id="receiptContent" style="display:none;">
                    <div class="official-receipt-template">
                        <div class="header">
                            <div class="header-logo"><img src="../img/official-logo.png" alt="Municipality Logo" style="max-height:60px;"></div>
                            <div class="header-text"><h2>OFFICIAL RECEIPT</h2><p>Republic of the Philippines</p></div>
                        </div>
                        <div class="form-no"><span>Accountable Form No. 51</span><br><span>Revised January 1992</span></div>
                        <div class="row" style="border-top:1px solid #000;">
                            <div class="col"><span class="label">DATE</span><span class="value" id="rcpt-date" style="margin-left:10px;">__________</span></div>
                            <div class="col"><span class="label">NO.</span><span class="value" id="rcpt-no" style="margin-left:10px;">__________</span></div>
                        </div>
                        <div class="row">
                            <div class="col"><span class="label">AGENCY</span><span class="value" id="rcpt-agency" style="margin-left:10px;">BPLO</span></div>
                            <div class="col"><span class="label">FUND</span><span class="value" id="rcpt-fund" style="margin-left:10px;">GENERAL FUND</span></div>
                        </div>
                        <div class="row"><div class="col"><span class="label">PAYOR</span><span class="value" id="rcpt-payor" style="margin-left:10px;">__________</span></div></div>
                        <div class="row"><div class="col"><span class="label">TYPE</span><span class="value" id="rcpt-type" style="margin-left:10px;">—</span></div></div>
                        <div class="table-container">
                            <table>
                                <thead><tr><th style="width:60%;">NATURE OF COLLECTION</th><th style="width:20%; text-align:center;">ACCOUNT CODE</th><th style="width:20%; text-align:right;">AMOUNT</th></tr></thead>
                                <tbody id="rcpt-fee-rows"></tbody>
                                <tbody>
                                    <tr class="total-row"><td colspan="2" style="text-align:right; font-weight:bold;">TOTAL</td><td style="text-align:right; font-weight:bold;">P <span id="rcpt-total">0.00</span></td></tr>
                                    <tr class="words-row"><td colspan="3"><span id="rcpt-words">ZERO PESOS ONLY</span></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="bottom-section">
                            <div class="payment-options">
                                <div><input type="checkbox" checked> CASH</div>
                                <div><input type="checkbox"> CHECK</div>
                                <div><input type="checkbox"> MONEY ORDER</div>
                                <div><input type="checkbox"> E-WALLET/ONLINE</div>
                            </div>
                            <div class="bank-details">
                                <table>
                                    <thead><tr><th>DRAWEE BANK</th><th>NUMBER</th><th>DATE</th></tr></thead>
                                    <tbody><tr><td></td><td></td><td></td></tr></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="footer-text" style="margin-top:15px;font-size:12px;">Received the amount stated above</div>
                       
                        <div class="note-text">NOTE: Write the number and date of this receipt on the back of check or money order received.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Close</button>
                <button class="btn-yellow" id="printReceiptBtn"><i class="bi bi-printer"></i> Print receipt</button>
            </div>
        </div>
    </div>
</div>

<!-- Status Application Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Verified / rejected applications</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <table id="statusTable" class="table-plain">
                    <thead><tr><th>ID</th><th>Reference</th><th>Business</th><th>Type</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- UNIVERSAL STAT-CARD MODAL — shows a table for any clicked card -->
<!-- ============================================================ -->
<div class="modal fade" id="statCardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width:1200px;">
        <div class="modal-content" style="border-radius:14px; overflow:hidden;">
            <div class="modal-header" style="background:var(--navy-dark); color:#fff; border-bottom:none;">
                <h5 class="modal-title fw-bold d-flex align-items-center flex-wrap gap-2">
                    <i class="bi bi-bar-chart-line-fill" id="statCardModalIcon"></i>
                    <span id="statCardModalTitle">Details</span>
                    <span class="badge" id="statCardModalCount"
                          style="background:var(--yellow-accent); color:var(--navy-dark); font-size:.72rem;"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="background:#fff;">
                <div id="statCardModalEmpty" class="text-center py-5 d-none">
                    <i class="bi bi-inbox" style="font-size:2.5rem; color:#cbd5e1;"></i>
                    <p class="mt-3 text-muted mb-0">No records match this filter.</p>
                </div>
                <div class="table-responsive" id="statCardModalTableWrap" style="display:none;">
                    <table class="table-plain" id="statCardModalTable" style="margin:0;">
                        <thead id="statCardModalThead"></thead>
                        <tbody id="statCardModalTbody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);">
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Close</button>
                <a href="#" id="statCardModalOpenSection" class="btn-yellow" style="display:none;">
                    <i class="bi bi-arrow-right-circle"></i> <span>Open in section</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- VIEW APPLICATION MODAL (embeds view_application.php)         -->
<!-- ============================================================ -->
<div class="modal fade" id="viewApplicationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered"
         style="max-width: 1200px; height: 92vh;">
        <div class="modal-content" style="height: 100%;">

            <div class="modal-header"
                 style="background: var(--navy-dark); color: #fff; padding: .9rem 1.2rem;">
                <h5 class="modal-title d-flex align-items-center gap-2 flex-wrap"
                    style="font-size: 1rem; font-weight: 700;">
                    <i class="bi bi-file-earmark-pdf"></i>
                    <span>Application #<span id="viewAppIdTitle">—</span></span>
                    <span class="badge" id="viewAppStatusBadge" style="display:none;"></span>
                    <small class="text-light opacity-75 ms-1" id="viewAppBusinessLabel"></small>
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" style="position: relative;">
                <div id="viewAppLoader"
                    style="height: 100%; position: absolute; top:0; left:0; right:0;
                            background: #fff; z-index: 2;
                            display: flex; align-items: center; justify-content: center;">
                    <div class="text-center">
                        <div class="spinner-border" style="color: var(--navy-dark);"></div>
                        <p class="mt-2 text-muted small mb-0">Loading application…</p>
                    </div>
                </div>
                <iframe id="viewAppFrame" src="about:blank"
                        style="width: 100%; height: 100%; border: 0;"></iframe>
            </div>

            <div class="modal-footer"
                 style="border-top: 1px solid var(--border); padding: .75rem 1rem; gap: 8px;">
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Close
                </button>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
// Sort date columns by the real date/time (not alphabetically) so the latest is always on top
(function ($) {
    if (!$ || !$.fn || !$.fn.dataTable) return;
    var WD = '(?:(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\\s+)?';
    var re = new RegExp('^' + WD + '((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\\.?\\s+\\d{1,2},\\s+\\d{4})(?:\\s+(\\d{1,2}:\\d{2}(?:\\s*[AaPp][Mm])?))?', 'i');
    var blank = /^(?:|—|-|–|n\/a|not submitted|not assessed)$/i;
    function clean(d) { return String(d == null ? '' : d).replace(/<[^>]*>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim(); }
    $.fn.dataTable.ext.type.detect.unshift(function (d) {
        if (typeof d !== 'string') return null;
        var t = clean(d);
        return (re.test(t) || blank.test(t)) ? 'ph-date' : null;
    });
    $.fn.dataTable.ext.type.order['ph-date-pre'] = function (d) {
        var m = clean(d).match(re);
        if (!m) return 0;
        var ms = Date.parse(m[1] + (m[2] ? ' ' + m[2] : ''));
        return isNaN(ms) ? 0 : ms;
    };
})(window.jQuery);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ============================================================
// SECTION SWITCHING
// ============================================================
const sections = ['dashboard'];
<?php if ($showApprovalDocs): ?> sections.push('approval-docs'); <?php endif; ?>
<?php if ($showPaymentVerification): ?> sections.push('payment-verification'); <?php endif; ?>
<?php if ($showPayments): ?> sections.push('payments'); <?php endif; ?>
<?php if ($showTreasuryRecords): ?> sections.push('treasury-records'); <?php endif; ?>
<?php if ($showAppointments): ?> sections.push('appointments'); <?php endif; ?>
<?php if ($showActivityLog): ?> sections.push('activity-log'); <?php endif; ?>
<?php if ($showAnnouncements): ?> sections.push('announcements'); <?php endif; ?>

function showSection(sectionId) {
    sections.forEach(sec => {
        const el = document.getElementById(sec + '-section');
        if (el) el.style.display = 'none';
    });
    const active = document.getElementById(sectionId + '-section');
    if (active) active.style.display = 'block';

    document.querySelectorAll('.sidebar .nav-link, .offcanvas .nav-link').forEach(link => {
        link.classList.toggle('active', link.dataset.section === sectionId);
    });

    const off = document.getElementById('mobileSidebar');
    if (off && off.classList.contains('show')) {
        const inst = bootstrap.Offcanvas.getInstance(off);
        if (inst) inst.hide();
    }

    if (sectionId === 'appointments') setTimeout(() => initAppointmentsCalendar(), 100);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.querySelectorAll('.sidebar .nav-link, .offcanvas .nav-link').forEach(link => {
    link.addEventListener('click', (e) => {
        e.preventDefault();
        const section = link.dataset.section;
        if (section && sections.includes(section)) showSection(section);
    });
});
showSection('dashboard');

// ============================================================
// ACCOUNT SETTINGS
// ============================================================
document.getElementById('accountSettingsForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const fd = new FormData(form);

    Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch('account_settings.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.user-name').forEach(el => {
                el.textContent = data.new_name || el.textContent;
            });
            form.querySelector('[name="current_password"]').value = '';
            form.querySelector('[name="new_password"]').value     = '';
            form.querySelector('[name="confirm_password"]').value = '';

            Swal.fire({
                icon: 'success', title: 'Saved!',
                text: data.message || 'Profile updated successfully.',
                timer: 2000, showConfirmButton: false
            }).then(() => {
                const inst = bootstrap.Modal.getInstance(document.getElementById('accountSettingsModal'));
                if (inst) inst.hide();
            });
        } else {
            Swal.fire({
                icon: 'error', title: 'Update Failed',
                html: (data.message || 'Something went wrong.').replace(/\n/g, '<br>')
            });
        }
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' }));
});

// ============================================================
// EDIT ANNOUNCEMENT
// ============================================================
document.querySelectorAll('.edit-staff-announcement').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('editStaffAnnId').value = this.dataset.id;
        document.getElementById('editStaffAnnTitle').value = this.dataset.title;
        document.getElementById('editStaffAnnContent').value = this.dataset.content;
    });
});

// ============================================================
// DATATABLES
// ============================================================
<?php if ($showPayments && !empty($payments) && !$paymentsError): ?>
$(document).ready(function () {
    $('#paymentsTable').DataTable({ "pageLength": 10, "order": [[4, "desc"]], "columnDefs": [{ "orderable": false, "targets": [6] }] });
});
<?php endif; ?>
<?php if ($showApprovalDocs && count($allApps) > 0): ?>
$(document).ready(function () {
    $('#approvalTable').DataTable({ "pageLength": 25, "order": [[4, "desc"], [0, "desc"]], "columnDefs": [{ "orderable": false, "targets": [8] }] });
});
<?php endif; ?>
<?php if ($showPaymentVerification && count($pendingPaymentApps) > 0): ?>
$(document).ready(function () {
    $('#paymentVerificationTable').DataTable({ "pageLength": 25, "order": [[4, "desc"], [0, "desc"]], "columnDefs": [{ "orderable": false, "targets": [7] }] });
});
<?php endif; ?>
<?php if ($showTreasuryRecords && count($treasuryRecords) > 0): ?>
$(document).ready(function () {
    $('#treasuryRecordsTable').DataTable({ "pageLength": 25, "order": [[4, "desc"], [0, "desc"]], "columnDefs": [{ "orderable": false, "targets": [8] }] });
});
<?php endif; ?>
<?php if ($user_type == 4 && count($statusApps) > 0): ?>
$(document).ready(function () {
    $('#statusAppsTable').DataTable({ "pageLength": 10, "order": [[4, "desc"], [0, "desc"]], "columnDefs": [{ "orderable": false, "targets": [7] }] });
});
<?php endif; ?>
<?php if ($showAppointments && count($appointments) > 0): ?>
let appointmentsTable = null;

$(document).ready(function () {
    appointmentsTable = $('#appointmentsTable').DataTable({
        "pageLength": 25,
        "order": [[0, "desc"]],
        "columnDefs": [{ "orderable": false, "targets": [6] }],
        "language": { "search": "Search appointments:", "emptyTable": "No appointments booked yet" }
    });

    const counts = { all: 0, pending: 0, confirmed: 0, completed: 0, cancelled: 0 };
    document.querySelectorAll('#appointmentsTable tbody tr[data-status]').forEach(tr => {
        const st = (tr.getAttribute('data-status') || '').toLowerCase();
        counts.all++;
        if (counts[st] !== undefined) counts[st]++;
    });

    const setCount = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    };
    setCount('cntAll',       counts.all);
    setCount('cntPending',   counts.pending);
    setCount('cntConfirmed', counts.confirmed);
    setCount('cntCompleted', counts.completed);
    setCount('cntCancelled', counts.cancelled);

    document.querySelectorAll('#apptFilterBtns button').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#apptFilterBtns button').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            const filter = this.dataset.apptFilter;

            $.fn.dataTable.ext.search.pop();
            if (filter && filter !== 'all') {
                $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
                    if (settings.nTable.id !== 'appointmentsTable') return true;
                    const row = appointmentsTable.row(dataIndex).node();
                    const st = (row.getAttribute('data-status') || '').toLowerCase();
                    return st === filter;
                });
            }
            appointmentsTable.draw();

            const hint = document.getElementById('calendarFilterHint');
            const lbl  = document.getElementById('calendarFilterLabel');
            if (hint && lbl) {
                if (filter && filter !== 'all') {
                    lbl.textContent = filter.charAt(0).toUpperCase() + filter.slice(1);
                    hint.classList.remove('d-none');
                } else {
                    hint.classList.add('d-none');
                }
            }

            if (appointmentsCalendar) {
                appointmentsCalendar.removeAllEvents();
                const filtered = (filter && filter !== 'all')
                    ? allCalendarEvents.filter(ev =>
                        (ev.extendedProps?.appointment_status || '').toLowerCase() === filter
                      )
                    : allCalendarEvents;
                appointmentsCalendar.addEventSource(filtered);
            }
        });
    });
});
<?php endif; ?>
<?php if ($user_type == 4 && count($needsReviewApps) > 0): ?>
$(document).ready(function () {
    $('#needsReviewTable').DataTable({ "pageLength": 5, "order": [[2, "desc"]], "columnDefs": [{ "orderable": false, "targets": [4] }] });
});
<?php endif; ?>
<?php if ($user_type == 4 && count($waitingOnApplicantsApps) > 0): ?>
$(document).ready(function () {
    $('#waitingTable').DataTable({ "pageLength": 5, "order": [[2, "desc"]], "columnDefs": [] });
});
<?php endif; ?>

// ============================================================
// ACTIVITY LOG TABLE + FILTER
// ============================================================
let activityLogTable = null;
<?php if ($showActivityLog): ?>
$(document).ready(function () {
    if (document.querySelectorAll('#activityLogTable tbody tr[data-actor-role]').length > 0) {
        activityLogTable = $('#activityLogTable').DataTable({
            "pageLength": 25,
            "order": [[1, "desc"]],
            drawCallback: function () {
                // Renumber # so it always runs 1,2,3... in the order currently shown
                var api = this.api();
                var start = api.page.info().start;
                api.column(0, { page: 'current' }).nodes().each(function (cell, i) {
                    cell.innerHTML = start + i + 1;
                });
            },
            "language": {
                "search": "Search log:",
                "lengthMenu": "Show _MENU_ entries",
                "info": "Showing _START_ to _END_ of _TOTAL_ records",
                "infoEmpty": "No records available",
                "emptyTable": "No activity logged yet"
            },
            "columnDefs": [{ "orderable": false, "targets": [0, 2, 8] }]
        });
    }
});

document.querySelectorAll('#activityFilterBtns button').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#activityFilterBtns button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const role = this.dataset.role;
        if (!activityLogTable) return;

        $.fn.dataTable.ext.search.pop();

        const rowMeta = function (dataIndex) {
            const row = activityLogTable.row(dataIndex).node();
            return {
                actor: row.getAttribute('data-actor-role'),
                event: row.getAttribute('data-event-type') || ''
            };
        };

        if (role === 'all') {
            activityLogTable.draw();
            return;
        }

        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== 'activityLogTable') return true;
            const meta  = rowMeta(dataIndex);
            const et    = meta.event;
            const a     = meta.actor;
            const newSt = (data[6] || '').toLowerCase();

            switch (role) {
                case 'documents':
                    return et === 'document_rejection'
                        || et === 'fee_assessment'
                        || et === 'document_upload'
                        || newSt.includes('verified')
                        || newSt.includes('rejected');
                case 'payment':
                    return et === 'payment';
                case 'appointment':
                    return et.indexOf('appointment_') === 0;
                case 'submission':
                    return et === 'application_submitted';
                case 'announcement':
                    return et.indexOf('announcement_') === 0;
                case '0':
                    return (!a || a === '0' || a === '') && et !== 'payment';
                case '4':
                case '3':
                case '5':
                    return a === role;
                default:
                    return true;
            }
        });

        activityLogTable.draw();
    });
});
<?php endif; ?>

// ============================================================
// HELPERS
// ============================================================
function getFileIcon(type) {
    const icons = {
        'pdf': '<i class="bi bi-filetype-pdf text-danger"></i>',
        'image': '<i class="bi bi-file-image text-primary"></i>',
        'word': '<i class="bi bi-filetype-docx text-primary"></i>',
        'excel': '<i class="bi bi-filetype-xlsx text-success"></i>',
        'text': '<i class="bi bi-file-text"></i>',
        'zip': '<i class="bi bi-file-zip text-warning"></i>',
        'video': '<i class="bi bi-file-play text-danger"></i>',
        'audio': '<i class="bi bi-file-music text-info"></i>'
    };
    const t = (type || '').toLowerCase();
    if (t.includes('pdf')) return icons.pdf;
    if (t.includes('image') || t.includes('jpg') || t.includes('png') || t.includes('jpeg') || t.includes('gif')) return icons.image;
    if (t.includes('word') || t.includes('doc')) return icons.word;
    if (t.includes('excel') || t.includes('xls')) return icons.excel;
    if (t.includes('text') || t.includes('txt')) return icons.text;
    if (t.includes('zip') || t.includes('rar')) return icons.zip;
    if (t.includes('video') || t.includes('mp4') || t.includes('mov')) return icons.video;
    if (t.includes('audio') || t.includes('mp3') || t.includes('wav')) return icons.audio;
    return '<i class="bi bi-file-earmark"></i>';
}
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024, sizes = ['Bytes','KB','MB','GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
function numberToWords(num) {
    const a = ['','ONE ','TWO ','THREE ','FOUR ','FIVE ','SIX ','SEVEN ','EIGHT ','NINE ','TEN ','ELEVEN ','TWELVE ','THIRTEEN ','FOURTEEN ','FIFTEEN ','SIXTEEN ','SEVENTEEN ','EIGHTEEN ','NINETEEN '];
    const b = ['','','TWENTY ','THIRTY ','FORTY ','FIFTY ','SIXTY ','SEVENTY ','EIGHTY ','NINETY '];
    let n = Math.floor(num);
    let cents = Math.round((num - n) * 100);
    let str = '';
    if (n === 0) str = 'ZERO ';
    else {
        if (n >= 1000000) { str += numberToWords(Math.floor(n / 1000000)).replace(' PESOS ONLY','') + ' MILLION '; n %= 1000000; }
        if (n >= 1000) { str += numberToWords(Math.floor(n / 1000)).replace(' PESOS ONLY','') + ' THOUSAND '; n %= 1000; }
        if (n >= 100) { str += a[Math.floor(n / 100)] + 'HUNDRED '; n %= 100; }
        if (n > 0) {
            if (str !== '') str += 'AND ';
            if (n < 20) str += a[n];
            else str += b[Math.floor(n / 10)] + a[n % 10];
        }
    }
    str += 'PESOS ';
    if (cents > 0) str += 'AND ' + cents + ' CENTAVOS ';
    str += 'ONLY';
    return str;
}
function formatStatusText(status) {
    if (!status) return 'Unknown';
    const map = { 'submitted':'Submitted','under_review':'Under Review','pending_payment':'Pending Payment','payment_confirmed':'Payment Confirmed','approved':'Approved','issued':'Issued','rejected':'Rejected','cancelled':'Cancelled','verified':'Verified','claimed':'Claimed' };
    return map[status.toLowerCase()] || status.replace(/_/g,' ').toUpperCase();
}
function getStatusBadgeClass(status) {
    if (!status) return 'pill-pending';
    const s = status.toLowerCase();
    if (s === 'submitted') return 'pill-submitted';
    if (s === 'payment_confirmed') return 'pill-payment';
    if (s === 'pending_payment') return 'pill-pending';
    if (s === 'verified' || s === 'approved' || s === 'issued' || s === 'claimed') return 'pill-verified';
    if (s === 'rejected') return 'pill-rejected';
    if (s === 'cancelled') return 'pill-cancelled';
    return 'pill-info';
}

// ============================================================
// UNIVERSAL STAT-CARD MODAL
// ============================================================
function openStatCardModal(opts) {
    const {
        title,
        icon = 'bi-bar-chart-line-fill',
        headers = [],
        rows = [],
        section = null,
        sectionFilter = null,
        emptyText = 'No records match this filter.'
    } = opts;

    document.getElementById('statCardModalIcon').className = 'bi ' + icon;
    document.getElementById('statCardModalTitle').textContent = title;
    document.getElementById('statCardModalCount').textContent =
        rows.length + (rows.length === 1 ? ' record' : ' records');

    const theadEl = document.getElementById('statCardModalThead');
    const tbodyEl = document.getElementById('statCardModalTbody');
    const wrapEl  = document.getElementById('statCardModalTableWrap');
    const emptyEl = document.getElementById('statCardModalEmpty');
    const linkEl  = document.getElementById('statCardModalOpenSection');

    theadEl.innerHTML = '<tr>' + headers.map((h, i) => {
        const pad = i === 0 ? 'padding-left:1.5rem;' : (i === headers.length - 1 ? 'padding-right:1.5rem;' : '');
        return `<th style="${pad}">${h}</th>`;
    }).join('') + '</tr>';

    tbodyEl.innerHTML = '';
    if (rows.length === 0) {
        emptyEl.querySelector('p').textContent = emptyText;
        emptyEl.classList.remove('d-none');
        wrapEl.style.display = 'none';
    } else {
        emptyEl.classList.add('d-none');
        wrapEl.style.display = 'block';

        rows.forEach(cells => {
            const tr = document.createElement('tr');
            tr.innerHTML = cells.map((c, i) => {
                const pad = i === 0 ? 'padding-left:1.5rem;' : (i === cells.length - 1 ? 'padding-right:1.5rem;' : '');
                return `<td style="${pad}">${c}</td>`;
            }).join('');
            tbodyEl.appendChild(tr);
        });
    }

    if (section) {
        linkEl.style.display = '';
        linkEl.onclick = function (e) {
            e.preventDefault();
            const inst = bootstrap.Modal.getInstance(document.getElementById('statCardModal'));
            if (inst) inst.hide();
            showSection(section);
            if (sectionFilter) {
                setTimeout(() => {
                    const btn = document.querySelector(
                        '#activityFilterBtns button[data-role="' + sectionFilter + '"], ' +
                        '#apptFilterBtns button[data-appt-filter="' + sectionFilter + '"]'
                    );
                    if (btn) btn.click();
                }, 150);
            }
        };
    } else {
        linkEl.style.display = 'none';
        linkEl.onclick = null;
    }

    new bootstrap.Modal(document.getElementById('statCardModal')).show();
}

function bindStatCard(selector, handler) {
    document.querySelectorAll(selector).forEach(card => {
        card.addEventListener('click', handler);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                handler.call(card);
            }
        });
    });
}

// ── DASHBOARD cards ──
const dashboardCardsData = <?php echo json_encode($dashboardCardData, JSON_UNESCAPED_UNICODE); ?>;

function dashStatusPill(st) {
    st = (st || '').toLowerCase();
    const map = {
        'submitted': 'pill-submitted', 'under_review': 'pill-info',
        'pending_payment': 'pill-pending', 'payment_confirmed': 'pill-payment',
        'approved': 'pill-verified', 'issued': 'pill-verified', 'verified': 'pill-verified', 'claimed': 'pill-verified',
        'rejected': 'pill-rejected', 'cancelled': 'pill-cancelled'
    };
    const cls = map[st] || 'pill-info';
    return '<span class="pill ' + cls + '">' + st.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase()) + '</span>';
}

function dashCardRows(arr) {
    return arr.map(a => {
        const amt = '₱' + Number(a.amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const dateStr = a.application_date ? new Date(a.application_date).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : '—';
        return [
            '<div class="fw-semibold">' + (a.business_name || 'N/A') + '</div>',
            '<code class="small text-primary">' + (a.reference_no || '—') + '</code>',
            (a.application_type || 'N/A'),
            dateStr,
            '<strong>' + amt + '</strong>',
            dashStatusPill(a.status),
            '<a href="view_application.php?id=' + a.application_id + '" class="btn-sm-action" data-business="' + (a.business_name || '').replace(/"/g,'&quot;') + '" data-status="' + (a.status || '') + '"><i class="bi bi-eye"></i></a>'
        ];
    });
}

bindStatCard('.stat-tile-clickable[data-dash-card]', function () {
    const kind = this.dataset.dashCard;
    const data = dashboardCardsData[kind] || [];
    const titles = {
        'total': { title: 'All applications', icon: 'bi-people-fill' },
        'pending_review': { title: 'Ready to review', icon: 'bi-file-earmark-text' },
        'pending_payment': { title: 'Waiting for payment', icon: 'bi-wallet2' },
        'approved_issued': { title: 'Approved or issued', icon: 'bi-patch-check' }
    };
    const t = titles[kind] || { title: 'Applications', icon: 'bi-list-ul' };
    openStatCardModal({
        title: t.title,
        icon: t.icon,
        headers: ['Business', 'Reference', 'Type', 'Date filed', 'Amount', 'Status', 'Action'],
        rows: dashCardRows(data),
        section: 'dashboard'
    });
});

// ── APPROVAL DOCUMENTS cards ──
<?php if ($showApprovalDocs): ?>
const approvalCardsData = <?php echo json_encode($allApps, JSON_UNESCAPED_UNICODE); ?>;

function filterApprovalCards(kind) {
    if (kind === 'total')     return approvalCardsData.slice();
    if (kind === 'pending')   return approvalCardsData.filter(a => ['submitted','under_review','pending_payment'].includes((a.status||'').toLowerCase()));
    if (kind === 'approved')  return approvalCardsData.filter(a => ['approved','issued','verified','claimed'].includes((a.status||'').toLowerCase()));
    if (kind === 'cancelled') return approvalCardsData.filter(a => (a.status||'').toLowerCase() === 'cancelled');
    return [];
}

bindStatCard('.stat-tile-clickable[data-approval-card]', function () {
    const kind = this.dataset.approvalCard;
    const data = filterApprovalCards(kind);
    const titles = {
        'total':     { title: 'All approval documents', icon: 'bi-file-earmark-check' },
        'pending':   { title: 'Pending documents',      icon: 'bi-hourglass-split' },
        'approved':  { title: 'Approved documents',     icon: 'bi-check-circle' },
        'cancelled': { title: 'Cancelled documents',    icon: 'bi-x-circle' }
    };
    const t = titles[kind] || { title: 'Documents', icon: 'bi-files' };

    const rows = data.map(a => {
        const amt = '₱' + Number(a.amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const dateStr = a.application_date ? new Date(a.application_date).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : '—';
        const docCount = a.document_count ?? 0;
        const isRenewal = (a.application_type || '').toLowerCase() === 'renewal';
        const renewalTag = isRenewal ? ' <span class="pill pill-renewal ms-1">Renewal</span>' : '';

        return [
            '<div class="fw-semibold">' + (a.business_name || 'N/A') + renewalTag + '</div>',
            '<code class="small text-primary">' + (a.reference_no || '—') + '</code>',
            (a.application_type || 'N/A'),
            dateStr,
            '<strong>' + amt + '</strong>',
            '<span class="pill pill-info"><i class="bi bi-file-earmark"></i> ' + docCount + '</span>',
            dashStatusPill(a.status),
            '<a href="view_application.php?id=' + a.application_id + '" class="btn-sm-action" data-business="' + (a.business_name || '').replace(/"/g,'&quot;') + '" data-status="' + (a.status || '') + '"><i class="bi bi-file-earmark-text"></i></a>'
        ];
    });

    openStatCardModal({
        title: t.title,
        icon: t.icon,
        headers: ['Business', 'Reference', 'Type', 'Filed', 'Amount', 'Docs', 'Status', 'Action'],
        rows: rows,
        section: 'approval-docs'
    });
});
<?php endif; ?>

// ── APPOINTMENTS cards ──
<?php if ($showAppointments): ?>
const apptCardsData = <?php echo json_encode(array_map(function ($a) {
    $fullName = trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
    if ($fullName === '') $fullName = 'Applicant #' . ($a['user_id'] ?? '?');
    return [
        'application_id'   => (int)($a['application_id'] ?? 0),
        'appointment_date' => $a['appointment_date'] ?? '',
        'appointment_time' => $a['appointment_time'] ?? '',
        'status'           => strtolower($a['appointment_status'] ?? 'pending'),
        'applicant_name'   => $fullName,
        'applicant_email'  => $a['email'] ?? '',
        'applicant_contact'=> $a['contact_number'] ?? '',
        'business_name'    => $a['business_name'] ?? 'N/A',
        'reference_no'     => $a['reference_no'] ?? 'N/A',
        'application_type' => $a['application_type'] ?? 'N/A',
    ];
}, $appointments ?? []), JSON_UNESCAPED_UNICODE); ?>;

const APPT_TODAY = '<?php echo date('Y-m-d'); ?>';

function apptCardRows(arr) {
    return arr.map(a => {
        const dateStr = a.appointment_date
            ? new Date(a.appointment_date + 'T00:00:00').toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric', year:'numeric' })
            : '—';
        return [
            '<div class="fw-semibold">' + dateStr + '</div>' +
            '<small class="text-muted"><i class="bi bi-clock"></i> ' + (a.appointment_time || '—') + '</small>',
            '<div class="fw-semibold">' + a.applicant_name + '</div>' +
            '<small class="text-muted">' +
                (a.applicant_email   ? '<i class="bi bi-envelope"></i> ' + a.applicant_email : '') +
                (a.applicant_contact ? '<br><i class="bi bi-telephone"></i> ' + a.applicant_contact : '') +
            '</small>',
            (a.business_name),
            '<code class="small text-primary">' + a.reference_no + '</code>',
            (a.application_type),
            '<span class="pill ' + (
                a.status === 'pending'   ? 'pill-pending' :
                a.status === 'confirmed' ? 'pill-payment' :
                a.status === 'completed' ? 'pill-verified' : 'pill-rejected'
            ) + '">' + a.status.charAt(0).toUpperCase() + a.status.slice(1) + '</span>',
            '<a href="view_application.php?id=' + a.application_id + '" class="btn-sm-action" data-business="' + (a.business_name || '').replace(/"/g,'&quot;') + '"><i class="bi bi-eye"></i></a>'
        ];
    });
}

bindStatCard('.stat-tile-clickable[data-appt-stat]', function () {
    const kind = this.dataset.apptStat;
    let data = apptCardsData.slice();
    if (kind === 'today')     data = data.filter(a => a.appointment_date === APPT_TODAY);
    if (kind === 'pending')   data = data.filter(a => a.status === 'pending');
    if (kind === 'completed') data = data.filter(a => a.status === 'completed');

    const titles = {
        'total':     { title: 'All appointments',            icon: 'bi-calendar-check' },
        'today':     { title: "Today's appointments",        icon: 'bi-calendar-day' },
        'pending':   { title: 'Awaiting confirmation',       icon: 'bi-hourglass-split' },
        'completed': { title: 'Completed appointments',      icon: 'bi-check-all' }
    };
    const t = titles[kind] || { title: 'Appointments', icon: 'bi-calendar-check' };

    openStatCardModal({
        title: t.title,
        icon: t.icon,
        headers: ['Date & time', 'Applicant', 'Business', 'Reference', 'Type', 'Status', 'Action'],
        rows: apptCardRows(data),
        section: 'appointments',
        sectionFilter: (kind === 'pending' || kind === 'completed') ? kind : null
    });
});
<?php endif; ?>

// ── ACTIVITY LOG cards ──
<?php if ($showActivityLog): ?>
const activityCardsData = <?php echo json_encode($activityLog, JSON_UNESCAPED_UNICODE); ?>;
const ACT_TODAY = '<?php echo date('Y-m-d'); ?>';
const ACT_WEEK_AGO = '<?php echo date('Y-m-d', strtotime('-7 days')); ?>';

function filterActivityCards(kind) {
    const oneDayAgo = new Date(ACT_WEEK_AGO + 'T00:00:00');
    return activityCardsData.filter(log => {
        const when = log.event_time ? new Date(log.event_time.replace(' ', 'T')) : new Date();
        const dayStr = when.toISOString().slice(0, 10);
        const et = log.event_type || '';
        const ns = (log.new_status || '').toLowerCase();
        const at = parseInt(log.actor_type || 0, 10);

        switch (kind) {
            case 'total':            return true;
            case 'today':            return dayStr === ACT_TODAY;
            case 'week':             return when >= oneDayAgo;
            case 'reject':           return ns === 'rejected';
            case 'bplo':             return at === 4;
            case 'treasury':         return at === 3;
            case 'document_actions': return ['document_rejection','fee_assessment','document_upload'].includes(et);
            case 'verified':         return ns === 'verified';
            case 'payments':         return et === 'payment';
            case 'appointments':     return et.indexOf('appointment_') === 0;
            case 'documents':        return et === 'document_upload';
            case 'submissions':      return et === 'application_submitted';
            default:                 return true;
        }
    });
}

function actEventBadge(et) {
    const map = {
        'status_change':         ['bi-arrow-repeat',     'Status change',       '#6366f1'],
        'fee_assessment':        ['bi-cash-coin',        'Fee assessment',      '#f59e0b'],
        'document_rejection':    ['bi-file-earmark-x',   'Document rejection',  '#ef4444'],
        'payment':               ['bi-paypal',           'PayPal payment',      '#0070ba'],
        'appointment_created':   ['bi-calendar-plus',    'Appointment booked',  '#8b5cf6'],
        'appointment_confirmed': ['bi-calendar-check',   'Appointment confirmed','#3b82f6'],
        'appointment_completed': ['bi-calendar-check-fill','Appointment completed','#10b981'],
        'appointment_cancelled': ['bi-calendar-x',       'Appointment cancelled','#ef4444'],
        'document_upload':       ['bi-cloud-upload',     'Document uploaded',   '#06b6d4'],
        'application_submitted': ['bi-file-earmark-plus','Application submitted','#10b981'],
        'announcement_pending':  ['bi-megaphone',        'Announcement pending','#f59e0b'],
        'announcement_approved': ['bi-megaphone-fill',   'Announcement approved','#10b981'],
        'announcement_rejected': ['bi-megaphone',        'Announcement rejected','#ef4444']
    };
    const [icon, label, color] = map[et] || ['bi-arrow-repeat', 'Status change', '#6366f1'];
    return '<span class="event-tag" style="background:' + color + '22; color:' + color + ';">' +
           '<i class="bi ' + icon + '"></i> ' + label + '</span>';
}

function actCardRows(arr) {
    return arr.map(log => {
        const when = log.event_time ? new Date(log.event_time.replace(' ', 'T')) : new Date();
        const dateStr = when.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
        const timeStr = when.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });

        return [
            '<div class="fw-semibold">' + dateStr + '</div>' +
            '<small class="text-muted"><i class="bi bi-clock"></i> ' + timeStr + '</small>',
            actEventBadge(log.event_type || 'status_change'),
            log.reference_no
                ? '<code class="small">' + log.reference_no + '</code>'
                : '<span class="text-muted">—</span>',
            '<div class="fw-semibold">' + (log.business_name || '—') + '</div>' +
            '<small class="text-muted"><i class="bi bi-person"></i> ' + (log.owner_name || 'Unknown') + '</small>',
            log.old_status ? '<span class="pill pill-draft">' + log.old_status.replace(/_/g,' ') + '</span>' : '<span class="text-muted">—</span>',
            '<span class="pill pill-info">' + (log.new_status || '—').replace(/_/g,' ') + '</span>',
            '<div class="fw-semibold">' + (log.actor_name || 'System') + '</div>',
            '<small class="text-muted">' + (log.notes || '—') + '</small>'
        ];
    });
}

bindStatCard('.stat-tile-clickable[data-act-card]', function () {
    const kind = this.dataset.actCard;
    const data = filterActivityCards(kind);
    const titles = {
        'total':            { title: 'All activities',           icon: 'bi-list-ul' },
        'today':            { title: "Today's activities",       icon: 'bi-calendar-day' },
        'week':             { title: 'This week',                icon: 'bi-calendar-week' },
        'reject':           { title: 'Rejections',               icon: 'bi-x-circle' },
        'bplo':             { title: 'BPLO actions',             icon: 'bi-file-earmark-check' },
        'treasury':         { title: 'Treasury actions',         icon: 'bi-cash-coin' },
        'document_actions': { title: 'Document actions',         icon: 'bi-file-earmark-check' },
        'verified':         { title: 'Verified',                 icon: 'bi-patch-check' },
        'payments':         { title: 'PayPal payments',          icon: 'bi-paypal' },
        'appointments':     { title: 'Appointment events',       icon: 'bi-calendar-check' },
        'documents':        { title: 'Document uploads',         icon: 'bi-cloud-upload' },
        'submissions':      { title: 'Applications submitted',   icon: 'bi-file-earmark-plus' }
    };
    const t = titles[kind] || { title: 'Activity Log', icon: 'bi-clock-history' };

    const sectionFilterMap = {
        'bplo':             '4',
        'treasury':         '3',
        'document_actions': 'documents',
        'payments':         'payment',
        'appointments':     'appointment',
        'documents':        'documents',
        'submissions':      'submission'
    };

    openStatCardModal({
        title: t.title,
        icon: t.icon,
        headers: ['Date & time', 'Event', 'Reference', 'Business / owner', 'Previous', 'New status', 'By', 'Notes'],
        rows: actCardRows(data),
        section: 'activity-log',
        sectionFilter: sectionFilterMap[kind] || null
    });
});
<?php endif; ?>

// ============================================================
// VIEW DOCUMENTS
// ============================================================
$(document).on('click', '.view-documents', function () {
    const appId = $(this).data('id');
    const businessName = $(this).data('business');
    const refNo = $(this).data('ref');
    const isResubmitted = $(this).data('resubmitted') == '1';
    const resubmittedDate = $(this).data('resubmitted-date') || '';

    $('#docViewerTitle').text('Documents for ' + businessName);
    $('#docViewerRef').text('(' + refNo + ')');
    $('#docLoadingIndicator').show();
    $('#documentsList').hide().html('');

    new bootstrap.Modal(document.getElementById('documentViewerModal')).show();

    $.ajax({
        url: 'get_documents.php', type: 'POST', data: { application_id: appId }, dataType: 'json',
        success: function (response) {
            $('#docLoadingIndicator').hide();
            let html = '';
            if (isResubmitted && resubmittedDate) {
                html += `<div class="alert alert-warning py-2 mb-3"><i class="bi bi-arrow-up-circle me-2"></i><strong>Resubmitted</strong> — re-uploaded on <strong>${resubmittedDate}</strong>.</div>`;
            }
            if (response.success && response.documents.length > 0) {
                html += '<div>';
                response.documents.forEach(function (doc) {
                    const fileIcon = getFileIcon(doc.document_type);
                    const fileSize = doc.file_size ? formatFileSize(doc.file_size) : 'Unknown size';
                    const uploadedDate = new Date(doc.uploaded_at).toLocaleString();
                    const fileExists = doc.file_exists !== undefined ? doc.file_exists : true;
                    const fileStatus = fileExists ? '' : ' <span class="pill pill-rejected">Missing</span>';
                    html += `<div class="d-flex align-items-center" style="padding:.85rem 0; border-bottom:1px solid #f1f5f9;">
                        <div style="font-size:1.5rem;margin-right:.75rem;color:var(--navy-dark);">${fileIcon}</div>
                        <div style="flex:1;min-width:0;">
                            <div class="fw-bold">${escapeHtml(doc.original_name)}${fileStatus}</div>
                            <div class="small text-muted"><span class="pill pill-info me-2">${doc.document_type || 'General'}</span>${fileSize} · ${uploadedDate}</div>
                        </div>
                        <div>${fileExists ? `<button class="btn-sm-action view-file" data-file="${doc.file_path}" data-name="${doc.original_name}"><i class="bi bi-eye"></i> View</button>` : `<span class="small text-danger">File not found</span>`}</div>
                    </div>`;
                });
                html += '</div>';
                $('#documentsList').html(html).show();
            } else {
                $('#documentsList').html('<div class="text-center py-5"><i class="bi bi-file-earmark-x" style="font-size:2.5rem;color:#cbd5e1;"></i><p class="mt-3 text-muted">No documents found.</p></div>').show();
            }
        },
        error: function () {
            $('#docLoadingIndicator').hide();
            $('#documentsList').html('<div class="alert alert-danger">Error loading documents.</div>').show();
        }
    });
});

// ============================================================
// VIEW FILE
// ============================================================
$(document).on('click', '.view-file', function () {
    const filePath = $(this).data('file');
    const fileName = $(this).data('name');
    const downloadUrl = 'download_document.php?file=' + encodeURIComponent(filePath);

    $('#fileViewerTitle').text(fileName);
    $('#fileViewerDownload').attr('href', downloadUrl).attr('download', fileName);

    const ext = fileName.split('.').pop().toLowerCase();
    const imageTypes = ['jpg','jpeg','png','gif','bmp','webp','svg'];
    const pdfTypes = ['pdf'];
    const videoTypes = ['mp4','webm','ogg','mov'];
    const audioTypes = ['mp3','wav','ogg','m4a'];

    $('#fileViewerContent').html('<div class="text-center py-5"><div class="spinner-border" style="color:var(--navy-dark);"></div></div>');
    new bootstrap.Modal(document.getElementById('fileViewerModal')).show();

    let content = '';
    if (imageTypes.includes(ext)) content = `<div style="text-align:center;padding:1rem;"><img src="${downloadUrl}" style="max-width:100%;max-height:500px;border-radius:.5rem;"></div>`;
    else if (pdfTypes.includes(ext)) content = `<iframe src="${downloadUrl}#toolbar=1" style="width:100%;height:600px;border:0;border-radius:.5rem;"></iframe>`;
    else if (videoTypes.includes(ext)) content = `<video controls style="max-width:100%;max-height:500px;"><source src="${downloadUrl}" type="video/${ext}"></video>`;
    else if (audioTypes.includes(ext)) content = `<audio controls style="width:100%;"><source src="${downloadUrl}" type="audio/${ext}"></audio>`;
    else if (['txt','log','md','csv','json'].includes(ext)) {
        $.ajax({ url: downloadUrl, type: 'GET', dataType: 'text',
            success: t => $('#fileViewerContent').html(`<pre style="max-height:500px;overflow:auto;white-space:pre-wrap;padding:1rem;background:#f8f9fa;border-radius:.5rem;">${escapeHtml(t)}</pre>`) });
        return;
    } else content = `<div class="text-center py-5"><i class="bi bi-file-earmark" style="font-size:2.5rem;color:#cbd5e1;"></i><p class="mt-3 text-muted">Preview not available. Use Download.</p></div>`;

    $('#fileViewerContent').html(content);
});

// ============================================================
// VIEW OFFICIAL RECEIPT
// ============================================================
$(document).on('click', '.view-receipt', function (e) {
    e.stopPropagation();
    const $el             = $(this);
    const appId           = $el.data('appid');
    const business        = $el.data('business');
    const isRenewal       = String($el.data('is-renewal')) === '1';
    const storedTotal     = parseFloat($el.data('amount')) || 0;

    const prevYear  = new Date().getFullYear() - 1;
    const lateLabel = 'Late Payment ' + prevYear;

    const baseFees = [
        { label: "Mayor's Permit",           value: parseFloat($el.data('mayors-permit-fee')) || 0 },
        { label: 'Gross Sales Tax Computed', value: parseFloat($el.data('gross-sales-tax')) || 0 },
        { label: 'Permit on Calling',        value: parseFloat($el.data('permit-on-calling')) || 0 },
        { label: 'Health Certificate',       value: parseFloat($el.data('health-certificate')) || 0 },
        { label: 'Sanitary Inspection Fee',  value: parseFloat($el.data('sanitary-inspection-fee')) || 0 },
        { label: 'Sanitary Garbage Fee',     value: parseFloat($el.data('garbage-charges')) || 0 },
        { label: 'Weight & Measure',         value: parseFloat($el.data('weight-measure')) || 0 },
        { label: 'Building Inspection Fee',  value: parseFloat($el.data('building-inspection-fee')) || 0 },
        { label: 'Electrical Inspection Fee',value: parseFloat($el.data('electrical-inspection-fee')) || 0 },
        { label: 'Misc. Fee',                value: parseFloat($el.data('misc-fee')) || 0 },
        { label: 'Other Fees',               value: parseFloat($el.data('others')) || 0 },
        { label: 'Surcharge Fee',            value: parseFloat($el.data('surcharge-fee')) || 0 },
        { label: 'Interest Fee',             value: parseFloat($el.data('interest-fee')) || 0 },
        { label: lateLabel,                  value: parseFloat($el.data('late-payment')) || 0 }
    ];

    if (isRenewal) {
        baseFees.push({
            label: 'Penalty (Lost Business Permit)',
            value: parseFloat($el.data('penalty-lost-permit')) || 0
        });
    }

    const fireSafety = parseFloat($el.data('fire-safety-fee')) || 0;
    const lineSum = baseFees.reduce((a, f) => a + (f.value || 0), 0) + fireSafety;
    const displayTotal = lineSum > 0 ? lineSum : storedTotal;

    function buildFeeRows(items, fireFee, grandTotal) {
        let html  = '';
        let sum   = 0;
        let shown = 0;

        items.forEach(f => {
            if (f.value > 0) {
                sum += f.value;
                shown++;
                html += `<tr>
                    <td style="font-weight:bold;">${escapeHtml(f.label)}</td>
                    <td style="text-align:center;"></td>
                    <td style="text-align:right;font-weight:bold;">P ${f.value.toFixed(2)}</td>
                </tr>`;
            }
        });

        if (fireFee > 0) {
            sum += fireFee;
            shown++;
            html += `<tr>
                <td style="font-weight:bold;">Fire Safety Inspection Fee (FSIF)</td>
                <td style="text-align:center;"></td>
                <td style="text-align:right;font-weight:bold;">P ${fireFee.toFixed(2)}</td>
            </tr>`;
        }

        if (shown === 0 && grandTotal > 0) {
            html = `<tr>
                <td style="font-weight:bold;">BUSINESS PERMIT FEE</td>
                <td></td>
                <td style="text-align:right;font-weight:bold;">P ${grandTotal.toFixed(2)}</td>
            </tr>`;
        }
        else if (shown > 0 && Math.abs(grandTotal - sum) >= 0.01) {
            const diff = +(grandTotal - sum).toFixed(2);
            if (diff > 0) {
                html += `<tr>
                    <td style="font-weight:bold;">OTHER FEES / ADJUSTMENT</td>
                    <td></td>
                    <td style="text-align:right;font-weight:bold;">P ${diff.toFixed(2)}</td>
                </tr>`;
            }
        }
        return html;
    }

    $('#receiptLoading').show();
    $('#receiptContent').hide();
    $('#rcpt-fee-rows').html(buildFeeRows(baseFees, fireSafety, displayTotal));
    $('#rcpt-type').text(isRenewal ? 'Renewal' : 'New');

    const sm = bootstrap.Modal.getInstance(document.getElementById('statusModal'));
    if (sm) sm.hide();

    setTimeout(function () {
        new bootstrap.Modal(document.getElementById('receiptModal'),
            { backdrop: 'static', keyboard: false }).show();

        const defaultDate = new Date().toLocaleDateString('en-US',
            { month:'2-digit', day:'2-digit', year:'numeric' }).replace(/\//g,'');
        const defaultOR = 'OR-' + new Date().getFullYear() + '-' + String(appId).padStart(6,'0');

        $('#rcpt-date').text(defaultDate);
        $('#rcpt-no').text(defaultOR);
        $('#rcpt-payor').text(business);
        $('#rcpt-total').text(displayTotal.toFixed(2));
        $('#rcpt-words').text(numberToWords(displayTotal));

        $.ajax({
            url: 'get_receipt.php', type: 'POST', data: { application_id: appId }, dataType: 'json',
            success: function (response) {
                $('#receiptLoading').hide();
                if (response && response.success && response.data) {
                    const d = response.data;
                    if (d.or_number) $('#rcpt-no').text(d.or_number);
                    if (d.paid_at) {
                        const dt = new Date(d.paid_at);
                        $('#rcpt-date').text(dt.toLocaleDateString('en-US',
                            { month:'2-digit', day:'2-digit', year:'numeric' }).replace(/\//g,''));
                    }
                    if (d.payor) $('#rcpt-payor').text(d.payor);
                }
                $('#receiptContent').show();
            },
            error: function () {
                $('#receiptLoading').hide();
                $('#receiptContent').show();
            }
        });
    }, 300);
});

document.getElementById('printReceiptBtn')?.addEventListener('click', function () {
    const receiptContent = document.getElementById('receiptContent');
    if (!receiptContent) return;
    const w = window.open('', '_blank', 'width=800,height=900');
    if (!w) { alert('Please allow pop-ups to print the receipt.'); return; }
    const html = receiptContent.innerHTML;
    w.document.write(`<!DOCTYPE html><html><head><title>Official Receipt</title>
    <style>body{font-family:'Courier New',monospace;padding:20px;background:#fff}
    .official-receipt-template{width:100%;max-width:750px;margin:0 auto;border:2px solid #000;padding:20px}
    .header{display:flex;align-items:center;border-bottom:2px solid #000;padding-bottom:15px;margin-bottom:15px}
    .header-logo{width:80px;margin-right:20px;text-align:center}
    .header-text{flex:1;text-align:center;padding-right:80px}
    .header-text h2{font-size:24px;font-weight:bold;margin:0;font-family:'Times New Roman',serif}
    .header-text p{font-size:14px;margin:5px 0 0 0;font-family:'Times New Roman',serif}
    .form-no{text-align:center;font-size:12px;margin-bottom:10px;border-bottom:1px solid #000;padding-bottom:5px}
    .row{display:flex;border-bottom:1px solid #000}.col{flex:1;padding:5px 10px;border-right:1px solid #000;font-size:14px;display:flex;align-items:center}.col:last-child{border-right:none}.col.label{font-weight:bold;min-width:80px;border-right:none}
    .table-container{border:1px solid #000;margin-top:15px}table{width:100%;border-collapse:collapse;font-size:14px}th{border:1px solid #000;padding:8px;text-align:left;font-weight:normal;font-size:13px}td{border:1px solid #000;padding:5px 8px;height:25px}
    .total-row td{border-top:2px solid #000;font-weight:bold}.words-row td{font-weight:bold;text-align:center;font-size:13px;border-top:1px solid #000;padding:10px}
    .bottom-section{display:flex;margin-top:15px;border:1px solid #000}.payment-options{flex:1;padding:10px;border-right:1px solid #000}.payment-options div{margin-bottom:15px;display:flex;align-items:center;font-size:14px}.bank-details{flex:2}
    .signature-block{display:flex;justify-content:flex-end;margin-top:30px}.signature-line{width:250px;border-top:1px solid #000;text-align:center;padding-top:5px;font-weight:bold;font-size:14px}
    .note-text{font-size:9px;text-align:center;margin-top:20px;color:#333}@media print{body{padding:0}.official-receipt-template{border:none;margin:0}}</style>
    </head><body>${html}<script>window.onload=function(){window.print()}<\/script></body></html>`);
    w.document.close();
});

// ============================================================
// STATUS UPDATE
// ============================================================
$(document).on('click', '.status-update-btn', function () {
    const appId = $(this).data('id');
    const businessName = $(this).data('business');
    const currentStatus = $(this).data('status');
    const userType = $(this).data('user-type') || '4';

    let statusOptions = [];
    if (userType == '3') {
        statusOptions = [
            { value: 'payment_confirmed', label: 'Payment Confirmed', icon: '✅', color: '#10b981' },
            { value: 'cancelled', label: 'Cancelled', icon: '❌', color: '#ef4444' }
        ];
    } else {
        statusOptions = [
            { value: 'verified', label: 'Verified (📧 Email)', icon: '✅', color: '#10b981' },
            { value: 'rejected', label: 'Rejected', icon: '❌', color: '#ef4444' }
        ];
    }

    let buttonsHtml = '';
    statusOptions.forEach(o => {
        const active = currentStatus === o.value;
        buttonsHtml += `<button class="btn status-option-btn m-1" data-value="${o.value}" style="min-width:140px; border-radius:8px; padding:.5rem 1rem; font-weight:600; font-size:.85rem; border:2px solid ${active ? o.color : '#e2e8f0'}; background:${active ? o.color : '#fff'}; color:${active ? '#fff' : '#1e293b'};">${o.icon} ${o.label}${active ? ' ✓' : ''}</button>`;
    });

    const badgeClass = getStatusBadgeClass(currentStatus);
    const formattedStatus = formatStatusText(currentStatus);

    const checklistHtml = `<div id="docChecklist" style="display:none; margin-top:1rem; text-align:left;">
        <p class="fw-bold small mb-2">Select missing documents:</p>
        <div class="row g-2" style="font-size:.85rem;">
            <div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="barangay"> Barangay Clearance</div>
            <div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="dti_sec"> DTI/SEC – Xerox Copy</div>
            <div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="rpt"> RPT – Current Year Payment</div>
            <div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="engineering"> Engineering</div>
            <div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="zoning"> Zoning Certification (MPDC)</div>
            <div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="treasury"> Treasury</div>
            <div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="sanitary"> Sanitary Health Permit</div>
            <div class="col-md-6"><input type="checkbox" class="doc-checkbox" value="fire_safety"> Fire Safety Certificate</div>
        </div>
    </div>`;

    const hintHtml = `<div id="statusHint" class="alert alert-info mt-2" style="display:none; border-radius:8px; font-size:.82rem;"><i class="bi bi-info-circle"></i> <span id="hintText"></span></div>`;

    async function fetchMissingDocs(id) {
        try {
            const r = await fetch(`get_rejection.php?application_id=${id}`);
            const d = await r.json();
            return d.success ? (d.missing_documents || []) : [];
        } catch (e) { return []; }
    }

    Swal.fire({
        title: 'Update application status',
        html: `<div class="text-start" style="font-size:.9rem;">
            <p><strong>Application:</strong> ${businessName}</p>
            <p><strong>Current:</strong> <span class="pill ${badgeClass}">${formattedStatus}</span></p>
            <hr><p class="fw-bold mt-3">Select new status:</p>
            <div class="d-flex flex-wrap gap-2 mt-2">${buttonsHtml}</div>
            ${checklistHtml}${hintHtml}
            <div class="mt-3"><label class="form-label fw-bold small">Remarks (optional):</label>
                <textarea id="statusRemarks" class="form-control" rows="2" placeholder="Add remarks…"></textarea></div>
        </div>`,
        showCancelButton: true,
        confirmButtonText: 'Update status',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#fbbf24',
        cancelButtonColor: '#6b7280',
        width: 700,
        didOpen: async function () {
            document.querySelectorAll('.status-option-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.status-option-btn').forEach(b => {
                        b.style.background = '#fff'; b.style.color = '#1e293b'; b.style.borderColor = '#e2e8f0';
                    });
                    const color = statusOptions.find(o => o.value === this.dataset.value)?.color || '#fbbf24';
                    this.style.background = color; this.style.color = '#fff'; this.style.borderColor = color;

                    const hint = document.getElementById('statusHint');
                    const hintTxt = document.getElementById('hintText');
                    const cl = document.getElementById('docChecklist');
                    if (this.dataset.value === 'verified') {
                        hint.style.display = 'block';
                        hintTxt.innerHTML = 'Selecting <strong>Verified</strong> will send an email and mark the appointment as <strong>completed</strong>.';
                        cl.style.display = 'none';
                    } else if (this.dataset.value === 'rejected' && <?php echo $user_type == 4 ? 'true' : 'false'; ?>) {
                        hint.style.display = 'block';
                        hintTxt.innerHTML = 'Selecting <strong>Rejected</strong> will send an email listing the missing documents.';
                        cl.style.display = 'block';
                    } else {
                        hint.style.display = 'none';
                        cl.style.display = 'none';
                    }
                });
            });

            const missing = await fetchMissingDocs(appId);
            if (missing.length > 0) {
                document.getElementById('docChecklist').style.display = 'block';
                document.querySelectorAll('.doc-checkbox').forEach(cb => { if (missing.includes(cb.value)) cb.checked = true; });
            }
        },
        preConfirm: function () {
            const selected = Array.from(document.querySelectorAll('.status-option-btn')).find(b => {
                const bg = b.style.background || '';
                return bg && bg !== 'rgb(255, 255, 255)' && bg !== 'white' && bg !== '';
            });
            if (!selected) { Swal.showValidationMessage('Please select a status'); return false; }
            const newStatus = selected.dataset.value;
            const remarks = document.getElementById('statusRemarks').value.trim();
            let missing = [];
            if (newStatus === 'rejected' && <?php echo $user_type == 4 ? 'true' : 'false'; ?>) {
                document.querySelectorAll('.doc-checkbox:checked').forEach(cb => missing.push(cb.value));
                if (missing.length === 0 && currentStatus !== 'rejected') { Swal.showValidationMessage('Please select at least one missing document.'); return false; }
            }
            return { status: newStatus, remarks, missing_docs: missing };
        }
    }).then(function (result) {
        if (result.isConfirmed && result.value) updateStatus(appId, result.value.status, result.value.remarks, result.value.missing_docs);
    });
});

function updateStatus(appId, status, remarks, missingDocs) {
    Swal.fire({ title: 'Processing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.ajax({
        url: 'update_application_status.php', type: 'POST',
        data: { application_id: appId, status, notes: remarks, missing_docs: missingDocs },
        dataType: 'json',
        success: function (r) {
            if (r.success) {
                let m = r.message || 'Status updated.';
                if (r.email_sent) m += '<br><i class="bi bi-envelope-check text-success"></i> ' + r.email_msg;
                else if (r.email_msg) m += '<br><i class="bi bi-info-circle text-muted"></i> ' + r.email_msg;
                Swal.fire({ icon: 'success', title: 'Updated!', html: m, timer: 3000, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Update failed', text: r.message || 'Unknown error' });
            }
        },
        error: function (xhr) {
            let msg = 'Please try again.';
            try { const r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch (e) {}
            Swal.fire({ icon: 'error', title: 'Request failed', html: 'Error: ' + msg });
        }
    });
}

// ============================================================
// APPOINTMENT ACTIONS
// ============================================================
$(document).on('click', '.appt-action', function () {
    runApptAction($(this).data('id'), $(this).data('status'), $(this).data('name'));
});

// Shared by the table buttons AND the calendar popup.
// skipConfirm = true -> already confirmed by the user (e.g. clicked from the calendar), update right away.
function runApptAction(apptId, newStatus, name, skipConfirm) {

    const config = {
        confirmed: {
            icon: 'question', title: 'Confirm this appointment?',
            text: `${name} will be notified that their booking is confirmed.`,
            confirmText: 'Yes, confirm', confirmColor: '#10b981'
        },
        completed: {
            icon: 'question', title: 'Mark as completed?',
            text: `${name}'s appointment will be marked as done.`,
            confirmText: 'Mark complete', confirmColor: '#3b82f6'
        },
        cancelled: {
            icon: 'warning', title: 'Cancel this appointment?',
            text: `${name} will be notified that their booking was cancelled.`,
            confirmText: 'Yes, cancel', confirmColor: '#ef4444',
            input: 'textarea', inputPlaceholder: 'Reason for cancellation (optional)'
        }
    }[newStatus];

    if (!config) return;

    const askFirst = skipConfirm
        ? Promise.resolve({ isConfirmed: true, value: '' })
        : Swal.fire({
            icon: config.icon,
            title: config.title,
            text: config.text,
            input: config.input || undefined,
            inputPlaceholder: config.inputPlaceholder || undefined,
            showCancelButton: true,
            confirmButtonText: config.confirmText,
            confirmButtonColor: config.confirmColor,
            cancelButtonColor: '#6b7280'
        });

    askFirst.then(result => {
        if (!result.isConfirmed) return;
        const remarks = (typeof result.value === 'string') ? result.value : '';

        Swal.fire({ title: 'Updating…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch('update_appointment_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ appointment_id: apptId, status: newStatus, remarks: remarks })
        })
        .then(r => r.text())
        .then(txt => {
            try { return JSON.parse(txt); }
            catch (e) { throw new Error((txt || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) || 'Empty response from server'); }
        })
        .then(data => {
            if (data.success) {
                let html = data.message || 'Appointment updated.';
                if (data.email_sent) {
                    html += '<br><small class="text-success"><i class="bi bi-envelope-check"></i> '
                          + (data.email_msg || 'Email sent.') + '</small>';
                } else if (data.email_msg) {
                    html += '<br><small class="text-muted"><i class="bi bi-info-circle"></i> '
                          + data.email_msg + '</small>';
                }
                Swal.fire({
                    icon: 'success',
                    title: 'Updated!',
                    html: html,
                    timer: 2200,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', data.message || 'Update failed.', 'error');
            }
        })
        .catch(err => Swal.fire('Error', 'Request failed: ' + ((err && err.message) ? err.message : 'Network error') + ' — the status may already be saved, please refresh the page.', 'error'));
    });
}

// ============================================================
// EXPORT + REFRESH
// ============================================================
function exportTableToCSV(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    let csv = [];
    const rows = table.querySelectorAll('tr');
    for (let i = 0; i < rows.length; i++) {
        const row = [];
        const cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            if (j === cols.length - 1 && i > 0) continue;
            row.push('"' + cols[j].innerText.replace(/,/g, '') + '"');
        }
        csv.push(row.join(','));
    }
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = tableId + '_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
}
function refreshTable() { location.reload(); }

// ============================================================
// PAYMENT CHART
// ============================================================
<?php if ($showPayments): ?>
(function () {
    const ctx = document.getElementById('paymentChart')?.getContext('2d');
    if (!ctx) return;
    const labels = <?php echo json_encode($monthlyLabels); ?>;
    const data   = <?php echo json_encode($monthlyCollections); ?>;
    const counts = <?php echo json_encode($monthlyCounts); ?>;
    new Chart(ctx, {
        type: 'line',
        data: { labels: labels.length ? labels : ['No data'], datasets: [{
            label: 'Matched collections (₱)',
            data: labels.length ? data : [0],
            borderColor: '#0f2038', backgroundColor: 'rgba(15,32,56,0.08)',
            fill: true, tension: 0.3, pointBackgroundColor: '#fbbf24',
            pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 4
        }] },
        options: {
            responsive: true,
            plugins: {
                legend: { labels: { font: { weight: '600' }, color: '#0f2038' } },
                tooltip: { callbacks: { label: c => ['₱ ' + Number(c.parsed.y).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}), (counts[c.dataIndex]||0) + ' matched payment(s)'] } }
            },
            scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + Number(v).toLocaleString('en-PH') } }, x: { grid: { display: false } } }
        }
    });
})();
<?php endif; ?>

// ============================================================
// APPOINTMENT CALENDAR
// ============================================================
<?php if ($showAppointments): ?>
let appointmentsCalendar = null;
let allCalendarEvents    = [];
function openApptApplication(p) {
    if (typeof window.openViewApplicationModal === 'function') {
        window.openViewApplicationModal(p.application_id, { business: p.business_name, status: p.application_status });
    } else {
        window.open('view_application.php?id=' + p.application_id, '_blank');
    }
}
function initAppointmentsCalendar() {
    if (appointmentsCalendar) { setTimeout(() => appointmentsCalendar.updateSize(), 100); return; }
    const el = document.getElementById('appointmentsCalendar');
    if (!el || typeof FullCalendar === 'undefined') return;

    allCalendarEvents = <?php echo json_encode($calendarEvents); ?>;
    const events = allCalendarEvents.slice();

    appointmentsCalendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
        height: 'auto', navLinks: true, editable: false, selectable: false,
        dayMaxEvents: 3, events,
        eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
        dayHeaderFormat: { weekday: 'short' },
        eventContent: function (arg) {
            const p = arg.event.extendedProps;
            const nodes = [];
            const d = document.createElement('div');
            d.innerHTML = '<i class="bi bi-calendar-event"></i> ' + (p.date_display || '');
            d.style.fontWeight = '700'; d.style.fontSize = '0.72rem'; d.style.marginBottom = '1px';
            nodes.push(d);
            const n = document.createElement('div');
            n.textContent = p.owner_name || arg.event.title;
            n.style.fontWeight = 'bold'; n.style.fontSize = '0.8rem';
            n.style.whiteSpace = 'normal'; n.style.wordBreak = 'break-word'; n.style.lineHeight = '1.15';
            nodes.push(n);
            if (p.time_display) {
                const t = document.createElement('div');
                t.innerHTML = '<i class="bi bi-clock"></i> ' + p.time_display;
                t.style.fontSize = '0.7rem'; t.style.opacity = '0.9'; t.style.marginTop = '2px';
                nodes.push(t);
            }

            // Status label (so it's clear even without the legend colors)
            const sLow = (p.appointment_status || '').toLowerCase();
            const lbl = document.createElement('div');
            lbl.textContent = sLow ? sLow.charAt(0).toUpperCase() + sLow.slice(1) : '';
            lbl.style.fontSize = '0.62rem'; lbl.style.fontWeight = '700';
            lbl.style.textTransform = 'uppercase'; lbl.style.letterSpacing = '.4px'; lbl.style.marginTop = '2px';
            nodes.push(lbl);

            // Quick action right on the calendar: Pending -> Confirm, Confirmed -> Claim
            if (sLow === 'pending' || sLow === 'confirmed') {
                const b = document.createElement('button');
                b.type = 'button';
                b.innerHTML = sLow === 'pending'
                    ? '<i class="bi bi-check2-circle"></i> Confirm'
                    : '<i class="bi bi-check-all"></i> Claim';
                b.style.cssText = 'margin-top:4px;background:rgba(255,255,255,.95);color:#0f2038;border:0;'
                                + 'border-radius:5px;font-size:.7rem;font-weight:700;padding:2px 8px;cursor:pointer;';
                b.addEventListener('click', function (ev) {
                    ev.stopPropagation(); ev.preventDefault();   // don't open the details popup
                    runApptAction(arg.event.id, sLow === 'pending' ? 'confirmed' : 'completed', p.owner_name, true);
                });
                nodes.push(b);
            }
            return { domNodes: nodes };
        },
        eventDidMount: function (info) {
            const s = (info.event.extendedProps.appointment_status || '').toLowerCase();
            let bg = '#6b7280', fg = '#fff';
            if (s === 'pending')   { bg = '#fbbf24'; fg = '#0f2038'; }
            else if (s === 'confirmed') { bg = '#3b82f6'; fg = '#fff'; }
            else if (s === 'completed') { bg = '#10b981'; fg = '#fff'; }
            else if (s === 'cancelled') { bg = '#ef4444'; fg = '#fff'; }
            info.el.style.backgroundColor = bg;
            info.el.style.borderColor = bg;
            info.el.style.color = fg;
        },
        eventClick: function (info) {
            const p = info.event.extendedProps;
            const apptDate = info.event.start.toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
            const s = (p.appointment_status || '').toLowerCase();
            let badge = '<span class="pill pill-pending">' + p.appointment_status + '</span>';
            if (s === 'confirmed') badge = '<span class="pill pill-payment">Confirmed</span>';
            else if (s === 'completed') badge = '<span class="pill pill-verified">Completed</span>';
            else if (s === 'cancelled') badge = '<span class="pill pill-rejected">Cancelled</span>';
            // Pending -> Confirm | Confirmed -> Claim | both can be cancelled, right from the calendar
            const actionable = (s === 'pending' || s === 'confirmed');
            const nextStatus = (s === 'pending') ? 'confirmed' : 'completed';
            const mainLabel  = (s === 'pending') ? '<i class="bi bi-check2-circle"></i> Confirm' : '<i class="bi bi-check-all"></i> Claim';
            const mainColor  = (s === 'pending') ? '#10b981' : '#3b82f6';
            Swal.fire({
                title: '<i class="bi bi-calendar-check"></i> Appointment details',
                html: `<div class="text-start" style="font-size:.9rem;">
                    <p><strong>Applicant:</strong> ${p.owner_name}</p>
                    <p><strong>Contact:</strong> ${p.owner_contact || '—'}</p>
                    <p><strong>Email:</strong> ${p.owner_email || '—'}</p>
                    <hr>
                    <p><strong>Business:</strong> ${p.business_name}</p>
                    <p><strong>Reference:</strong> <code>${p.reference_no}</code></p>
                    <p><strong>Type:</strong> ${p.application_type}</p>
                    <hr>
                    <p><strong>Scheduled:</strong> ${apptDate}</p>
                    <p><strong>Time:</strong> ${p.appointment_time}</p>
                    <p><strong>Status:</strong> ${badge}</p>
                    ${actionable ? '<p class="mb-0"><a href="#" id="calViewAppLink"><i class="bi bi-eye"></i> View application</a></p>' : ''}
                </div>`,
                showConfirmButton: true,
                showDenyButton: actionable,
                showCancelButton: true,
                confirmButtonText: actionable ? mainLabel : 'View application',
                denyButtonText: '<i class="bi bi-x-circle"></i> Cancel appointment',
                cancelButtonText: 'Close',
                confirmButtonColor: actionable ? mainColor : '#fbbf24',
                denyButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                width: 600,
                didOpen: () => {
                    const a = document.getElementById('calViewAppLink');
                    if (a) a.addEventListener('click', e => { e.preventDefault(); Swal.close(); openApptApplication(p); });
                }
            }).then(r => {
                if (r.isDenied) { runApptAction(info.event.id, 'cancelled', p.owner_name); return; }
                if (!r.isConfirmed) return;
                if (actionable) { runApptAction(info.event.id, nextStatus, p.owner_name, true); return; }
                openApptApplication(p);
            });
        },
        eventDisplay: 'block'
    });
    appointmentsCalendar.render();

    const activeBtn = document.querySelector('#apptFilterBtns button.active');
    if (activeBtn) {
        const filter = activeBtn.dataset.apptFilter;
        if (filter && filter !== 'all') {
            const filtered = allCalendarEvents.filter(ev =>
                (ev.extendedProps?.appointment_status || '').toLowerCase() === filter
            );
            appointmentsCalendar.removeAllEvents();
            appointmentsCalendar.addEventSource(filtered);
        }
    }
}
<?php endif; ?>

// ============================================================
// STAFF NOTIFICATION: mark-as-read
// ============================================================
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.staff-notif-menu .notif-item[data-notif-id]').forEach(function (item) {
        item.addEventListener('click', function () {
            const id = this.getAttribute('data-notif-id');
            if (!id) return;
            fetch('../mark_notification_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id }),
                keepalive: true
            }).catch(() => {});
            const badge = document.getElementById('staffNotifBadge');
            if (badge) {
                let c = parseInt(badge.textContent, 10) || 0;
                c = Math.max(0, c - 1);
                if (c === 0) badge.remove(); else badge.textContent = c > 99 ? '99+' : c;
            }
        });
    });
});

// ============================================================
// VIEW APPLICATION IN MODAL
// ============================================================
(function initViewApplicationModal() {
    'use strict';

    const modalEl = document.getElementById('viewApplicationModal');
    if (!modalEl) { console.warn('[VAM] modal not found'); return; }

    const frame       = document.getElementById('viewAppFrame');
    const loader      = document.getElementById('viewAppLoader');
    const titleEl     = document.getElementById('viewAppIdTitle');
    const badgeEl     = document.getElementById('viewAppStatusBadge');
    const businessEl  = document.getElementById('viewAppBusinessLabel');
    const openTabLink = document.getElementById('viewAppOpenTab');

    const MY_USER_TYPE = <?php echo (int)$user_type; ?>;

    // ---- Metadata lookup (business name + status per app id) ----
    const appMetaMap = {};
    try {
        (dashboardCardsData.total || []).forEach(function (a) {
            appMetaMap[String(a.application_id)] = {
                business: a.business_name || 'N/A',
                status:   a.status        || ''
            };
        });
    } catch (err) { /* ignore */ }

    function statusPillClass(status) {
        const map = {
            'submitted':         'pill-submitted',
            'under_review':      'pill-info',
            'pending_payment':   'pill-pending',
            'payment_confirmed': 'pill-payment',
            'approved':          'pill-verified',
            'issued':            'pill-verified',
            'verified':          'pill-verified',
            'rejected':          'pill-rejected',
            'cancelled':         'pill-cancelled',
            'draft':             'pill-draft'
        };
        return map[(status || '').toLowerCase()] || 'pill-info';
    }

    // ---- Bootstrap modal instance (created once) ----
    let bsModal = null;
    try { bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); }
    catch (e) { console.error('[VAM] bootstrap failed:', e); return; }

    let fallbackTimer = null;
    let currentLoadHandler = null;

    function showLoader() {
        if (!loader) return;
        loader.innerHTML =
            '<div class="text-center">' +
                '<div class="spinner-border" style="color: var(--navy-dark);"></div>' +
                '<p class="mt-2 text-muted small mb-0">Loading application…</p>' +
            '</div>';
        loader.style.setProperty('display', 'flex', 'important');
    }

    function hideLoader() {
        if (loader) loader.style.setProperty('display', 'none', 'important');
        if (fallbackTimer) { clearTimeout(fallbackTimer); fallbackTimer = null; }
    }

    function showLoadError(appUrl) {
        if (!loader) return;
        loader.innerHTML =
            '<div class="text-center p-4">' +
                '<i class="bi bi-exclamation-triangle text-warning" style="font-size:2rem;"></i>' +
                '<p class="mt-2 mb-1 fw-semibold">Taking too long to load…</p>' +
                '<p class="small text-muted mb-3">The application could not be embedded in the modal.</p>' +
            '</div>';
        loader.style.display = 'flex';
    }

    function openViewApplication(appId, overrideMeta) {
        if (!appId) return;

        const meta     = overrideMeta || appMetaMap[String(appId)] || {};
        const business = meta.business || 'N/A';
        const status   = meta.status   || '';

        // ---- Header ----
        if (titleEl)    titleEl.textContent = appId;
        if (businessEl) businessEl.textContent = business ? '· ' + business : '';

        if (badgeEl) {
            if (status) {
                badgeEl.textContent = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                badgeEl.className   = 'badge pill ' + statusPillClass(status);
                badgeEl.style.cssText = 'font-size:.72rem; display:inline-block;';
                badgeEl.style.display = 'inline-block';
            } else {
                badgeEl.style.display = 'none';
            }
        }

        const appUrl = 'view_application.php?id=' + encodeURIComponent(appId);
        if (openTabLink) openTabLink.href = appUrl;

        // ---- Show loader ----
        showLoader();

        // ---- Attach a fresh load handler for this open ----
        if (currentLoadHandler) {
            frame.removeEventListener('load', currentLoadHandler);
            currentLoadHandler = null;
        }
        currentLoadHandler = function () {
            hideLoader();

            // If the iframe navigated away from view_application.php,
            // the user performed an action inside → refresh parent.
            try {
                const path = frame.contentWindow.location.pathname || '';
                if (path && path.indexOf('view_application.php') === -1) {
                    setTimeout(function () { window.location.reload(); }, 350);
                }
            } catch (e) { /* cross-origin — ignore */ }
        };
        frame.addEventListener('load', currentLoadHandler);

        // ---- Show modal FIRST so iframe has a laid-out parent ----
        bsModal.show();

        // ---- Load the iframe URL with a cache-buster ----
        // The _t param guarantees a fresh navigation every time,
        // even when the same application is reopened. This removes
        // the about:blank race that kept the loader stuck.
        setTimeout(function () {
            frame.src = appUrl + (appUrl.indexOf('?') >= 0 ? '&' : '?') + '_t=' + Date.now();
        }, 250);

        // ---- Safety net: never keep the spinner forever ----
        if (fallbackTimer) clearTimeout(fallbackTimer);
        fallbackTimer = setTimeout(function () {
            if (loader && loader.style.display !== 'none') {
                showLoadError(appUrl);
            }
        }, 8000);
    }

    window.openViewApplicationModal = openViewApplication;

    // ---- Cleanup on close ----
    modalEl.addEventListener('hidden.bs.modal', function () {
        if (fallbackTimer) { clearTimeout(fallbackTimer); fallbackTimer = null; }
        if (currentLoadHandler) {
            frame.removeEventListener('load', currentLoadHandler);
            currentLoadHandler = null;
        }
    });

    // ---- Global click interception ----
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href*="view_application.php"]');
        if (!link) return;
        if (link.classList.contains('allow-new-tab')) return;
        if (link.hasAttribute('data-force-new-tab')) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;

        const href = link.getAttribute('href') || '';
        let appId = '';
        try {
            const url = new URL(href, window.location.href);
            appId = url.searchParams.get('id') || '';
        } catch (_) {
            const m = href.match(/[?&]id=(\d+)/);
            appId = m ? m[1] : '';
        }
        if (!appId) return;

        e.preventDefault();

        const cached = appMetaMap[String(appId)] || {};
        openViewApplication(appId, {
            business: link.dataset.business || cached.business || '',
            status:   link.dataset.status   || cached.status   || ''
        });
    });
})();
</script>
</body>
</html>