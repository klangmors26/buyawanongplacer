<?php
// TEMP DEBUG — remove after fixing
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('Asia/Manila');

/**
 * Format a datetime string from the DB (already stored in Philippine time).
 * If the string already contains tz info (Z / +08:00) it is respected.
 */
function toManila($value, string $format = 'M d, Y H:i'): string {
    if (empty($value)) return '—';
    try {
        $hasTz = (bool) preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', (string)$value);
        $dt = new DateTime((string)$value, $hasTz ? null : new DateTimeZone('Asia/Manila'));
        $dt->setTimezone(new DateTimeZone('Asia/Manila'));
        return $dt->format($format);
    } catch (Exception $e) {
        return (string)$value;
    }
}

/** Return a real Unix timestamp (UTC seconds) for the stored value. */
function toManilaTs($value): ?int {
    if (empty($value)) return null;
    try {
        $hasTz = (bool) preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', (string)$value);
        $dt = new DateTime((string)$value, $hasTz ? null : new DateTimeZone('Asia/Manila'));
        return $dt->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], [3, 4])) {
    $_SESSION['error'] = 'Access denied. Staff only.';
    header('Location: ../login.php');
    exit;
}

$staff_id   = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'LGU Staff';
$user_type  = (int)$_SESSION['user_type'];

require_once '../config/connect.php';

$notifHelperPath = __DIR__ . '/../notifications_helper.php';
$hasNotifHelper = file_exists($notifHelperPath);
if ($hasNotifHelper) {
    require_once $notifHelperPath;
}

$staff_unreadCount   = 0;
$staff_notifications = [];
if ($hasNotifHelper && function_exists('countUnreadNotifications')) {
    $staff_unreadCount   = countUnreadNotifications($conn, $staff_id);
    $staff_notifications = getUnreadNotifications($conn, $staff_id, 5);
}

$role_label = ($user_type == 3) ? 'Treasury Staff' : 'Licensing/Approval Staff';
$headerLogo = ($user_type == 3) ? 'treasury-logo.png' : 'bplo-logo.png';
$headerLogoAlt = ($user_type == 3) ? 'Treasury Office Logo' : 'BPLO Logo';

$showPayments            = ($user_type == 3);
$showPaymentVerification = ($user_type == 3);
$showTreasuryRecords     = ($user_type == 3);
$showFeeAssessment       = ($user_type == 3);
$showApprovalDocs        = ($user_type == 4);
$showAnnouncements       = true;
$showAppointments        = ($user_type == 4);
$showActivityLog         = true;

// ---------- STATS ----------
$stats = ['total' => 0, 'pending_review' => 0, 'pending_payment' => 0, 'approved_issued' => 0];
$statQueries = [
    'total'           => "SELECT COUNT(*) AS count FROM business_applications",
    'pending_review'  => "SELECT COUNT(*) AS count FROM business_applications WHERE LOWER(status) = 'payment_confirmed'",
    'pending_payment' => "SELECT COUNT(*) AS count FROM business_applications WHERE LOWER(status) = 'pending_payment'",
    'approved_issued' => "SELECT COUNT(*) AS count FROM business_applications WHERE LOWER(status) IN ('approved', 'issued', 'verified')",
];
foreach ($statQueries as $key => $sql) {
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $stats[$key] = (int)$row['count'];
    }
}

// ---------- DASHBOARD CARD DATA ----------
$dashboardCardData = ['total'=>[], 'for_assessment'=>[], 'pending_payment'=>[], 'approved_issued'=>[]];
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
        // Payment is confirmed, so the application is ready for fee assessment.
        if ($s === 'payment_confirmed')                            $dashboardCardData['for_assessment'][] = $row;
        if ($s === 'pending_payment')                              $dashboardCardData['pending_payment'][] = $row;
        if (in_array($s, ['approved','issued','verified'], true))  $dashboardCardData['approved_issued'][]  = $row;
    }
}

// ---------- RECENT APPS ----------
$recentApps = [];
$recentSql = "
    SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
           bi.business_name, at.application_type, fa.total_amount AS amount
    FROM business_applications ba
    LEFT JOIN business_info_data bi ON ba.id = bi.application_id
    LEFT JOIN application_type_data at ON ba.id = at.application_id
    LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
    WHERE LOWER(ba.status) = 'pending_payment'
    ORDER BY ba.application_date DESC, ba.id DESC LIMIT 10
";
$recentResult = $conn->query($recentSql);
if ($recentResult) {
    while ($row = $recentResult->fetch_assoc()) $recentApps[] = $row;
}

// ---------- FEE ASSESSMENT LIST ----------
$feeAssessmentApps = [];
if ($showFeeAssessment) {
    $sql = "
        SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
               ba.submitted_at,
               COALESCE(bi.business_name, 'N/A') AS business_name,
               COALESCE(CONCAT(oi.first_name, ' ', oi.last_name), 'N/A') AS owner_name,
               COALESCE(at.application_type, 'New') AS application_type,
               fa.total_amount AS amount,
               CASE WHEN fa.application_id IS NULL THEN 0 ELSE 1 END AS assessed_count
        FROM business_applications ba
        LEFT JOIN business_info_data bi ON ba.id = bi.application_id
        LEFT JOIN owner_info_data oi ON ba.id = oi.application_id
        LEFT JOIN application_type_data at ON ba.id = at.application_id
        LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
        WHERE LOWER(ba.status) = 'pending_payment'
        ORDER BY ba.application_date DESC
    ";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $feeAssessmentApps[] = $row;
    }
}

// ---------- PENDING PAYMENT APPS ----------
$pendingPaymentApps = [];
if ($user_type == 3) {
    $sql = "
        SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
               COALESCE(bi.business_name, 'N/A') AS business_name,
               COALESCE(CONCAT(oi.first_name, ' ', oi.last_name), 'N/A') AS owner_name,
               COALESCE(at.application_type, 'New') AS application_type,
               fa.total_amount AS amount,
               ba.user_id, ba.application_no, ba.current_step, ba.tax_year, ba.submitted_at
        FROM business_applications ba
        LEFT JOIN business_info_data bi ON ba.id = bi.application_id
        LEFT JOIN owner_info_data oi ON ba.id = oi.application_id
        LEFT JOIN application_type_data at ON ba.id = at.application_id
        LEFT JOIN fee_assessments fa ON ba.id = fa.application_id
        WHERE LOWER(ba.status) = 'pending_payment'
        ORDER BY ba.application_date DESC
    ";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $pendingPaymentApps[] = $row;
    }
}

// ---------- TREASURY RECORDS ----------
$treasuryRecords = [];
if ($user_type == 3) {
    $sql = "
        SELECT ba.id AS application_id, ba.reference_no, ba.status, ba.application_date,
               ba.tax_year, ba.submitted_at, ba.issued_at,
               bi.business_name, bi.business_activities,
               at.application_type, at.business_type, at.tin_no, at.registration_no,
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
        WHERE (
                LOWER(ba.status) IN ('payment_confirmed', 'verified', 'claimed', 'renewed')
                OR EXISTS (SELECT 1 FROM payments pp WHERE pp.application_id = ba.id AND pp.status = 'paid')
              )
          AND LOWER(ba.status) NOT IN ('draft', 'cancelled', 'rejected')
        ORDER BY ba.application_date DESC, ba.id DESC
    ";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $treasuryRecords[] = $row;
    }
}

// ---------- PAYPAL PAYMENTS ----------
$payments = [];
$paymentsError = null;
if ($showPayments) {
    $sql = "
        SELECT
            pt.id, pt.application_id, pt.user_id, pt.reference_no,
            pt.paypal_order_id, pt.paypal_capture_id, pt.payer_id, pt.payer_email, pt.payer_name,
            pt.amount, pt.currency, pt.payment_status, pt.paid_at, pt.created_at,
            ba.reference_no AS app_reference_no, ba.status AS app_status,
            bi.business_name, at.application_type, oi.first_name, oi.last_name,
            fa.total_amount,
            fa.gross_sales_tax, fa.mayors_permit_fee, fa.garbage_charges,
            fa.sanitary_inspection_fee, fa.building_inspection_fee,
            fa.electrical_inspection_fee, fa.fire_safety_fee_percent, fa.others,
            fa.permit_on_calling, fa.health_certificate, fa.weight_measure,
            fa.misc_fee, fa.surcharge_fee, fa.interest_fee,
            fa.late_payment, fa.penalty_lost_permit
        FROM paypal_transactions pt
        LEFT JOIN business_applications ba ON pt.application_id = ba.id
        LEFT JOIN business_info_data bi     ON ba.id = bi.application_id
        LEFT JOIN application_type_data at  ON ba.id = at.application_id
        LEFT JOIN owner_info_data oi        ON ba.id = oi.application_id
        LEFT JOIN fee_assessments fa        ON ba.id = fa.application_id
        ORDER BY COALESCE(pt.paid_at, pt.created_at) DESC, pt.id DESC
    ";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $payments[] = $row;
    } else {
        $paymentsError = 'DB error: ' . $conn->error;
    }
}

// ---------- MONTHLY COLLECTIONS ----------
$monthlyCollections = [];
$monthlyLabels      = [];
$monthlyCounts      = [];

if ($showPayments || $showTreasuryRecords) {
    $tz = new DateTimeZone(date_default_timezone_get());
    $windowEnd = new DateTime('first day of next month', $tz);
    $windowStart = (clone $windowEnd)->modify('-12 months');
    $buckets = [];
    $cursor = clone $windowStart;
    while ($cursor < $windowEnd) {
        $key = $cursor->format('Y-m');
        $buckets[$key] = ['label' => $cursor->format('M Y'), 'total' => 0, 'count' => 0];
        $cursor->modify('+1 month');
    }
    if (!$paymentsError && !empty($payments)) {
        $manilaTz = new DateTimeZone('Asia/Manila');
        foreach ($payments as $p) {
            $status = strtolower(trim($p['payment_status'] ?? ''));
            if (!in_array($status, ['completed', 'paid'], true)) continue;

            $c = $p['paid_at'] ?: ($p['created_at'] ?? null);
            if (!$c) continue;

            try {
                $hasTz = (bool) preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', (string)$c);
                $dt = new DateTime((string)$c, $hasTz ? null : new DateTimeZone('Asia/Manila'));
                $dt->setTimezone($manilaTz);
            } catch (Exception $e) { continue; }

            $bucketKey = $dt->format('Y-m');
            if (!isset($buckets[$bucketKey])) continue;
            $buckets[$bucketKey]['total'] += (float)($p['amount'] ?? 0);
            $buckets[$bucketKey]['count'] += 1;
        }
    }
    foreach ($buckets as $b) {
        $monthlyLabels[] = $b['label'];
        $monthlyCollections[] = round($b['total'], 2);
        $monthlyCounts[] = $b['count'];
    }
}
$hasMonthlyData = array_sum($monthlyCollections) > 0;
$monthlyTotal = array_sum($monthlyCollections);
$currentYear = date('Y');

// ============================================================
// ACTIVITY LOG (Treasury view) — SAFE (no UNION → no collation issue)
// ============================================================
$activityLog   = [];
$activityStats = [
    'total' => 0, 'today' => 0, 'week' => 0, 'reject' => 0,
    'bplo' => 0, 'treasury' => 0, 'admin' => 0, 'payment' => 0,
    'document_actions' => 0, 'verified' => 0,
    'appointments' => 0, 'documents' => 0, 'submissions' => 0,
];

if ($showActivityLog) {
    $appendRows = function ($sql) use ($conn, &$activityLog) {
        $res = @$conn->query($sql);
        if (!$res) {
            error_log('[ActivityLog Treasury] query failed: ' . $conn->error . ' | SQL: ' . substr($sql, 0, 200));
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
            ORDER BY sh.changed_at DESC LIMIT 500
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
            ORDER BY fa.assessed_at DESC LIMIT 500
        ");
    }
    if ($tableExists('application_rejections')) {
        $appendRows("
            SELECT 'rejection' AS event_type, ar.id AS event_id, ar.application_id,
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
            ORDER BY ar.rejected_at DESC LIMIT 500
        ");
    }
    if ($tableExists('paypal_transactions')) {
        $appendRows("
            SELECT 'payment' AS event_type, pt.id AS event_id, pt.application_id,
                COALESCE(pt.paid_at, pt.created_at) AS event_time,
                pt.user_id AS actor_id,
                NULL AS old_status, pt.payment_status AS new_status,
                CONCAT('PayPal ', pt.currency, ' ', FORMAT(pt.amount, 2),
                       ' · Capture: ', COALESCE(pt.paypal_capture_id, '—')) AS notes,
                NULL AS missing_documents_json,
                pt.reference_no, NULL AS application_no, bid.business_name,
                CONCAT(acc.firstname, ' ', acc.lastname) AS owner_name,
                'PayPal' AS actor_name, 0 AS actor_type
            FROM paypal_transactions pt
            LEFT JOIN business_applications ba ON pt.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON pt.user_id = acc.id
            WHERE pt.payment_status IN ('completed','paid','voided','failed')
            ORDER BY COALESCE(pt.paid_at, pt.created_at) DESC LIMIT 500
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
                CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name, 1 AS actor_type
            FROM appointments a
            LEFT JOIN business_applications ba ON a.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON a.user_id = acc.id
            WHERE a.created_at IS NOT NULL
            ORDER BY a.created_at DESC LIMIT 500
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
                'BPLO Staff' AS actor_name, 4 AS actor_type
            FROM appointments a
            LEFT JOIN business_applications ba ON a.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON a.user_id = acc.id
            WHERE a.updated_at IS NOT NULL
              AND a.updated_at != a.created_at
              AND LOWER(a.status) IN ('confirmed','completed','cancelled')
            ORDER BY a.updated_at DESC LIMIT 500
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
                CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name, 1 AS actor_type
            FROM document_uploads du
            LEFT JOIN business_applications ba ON du.application_id = ba.id
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            WHERE du.uploaded_at IS NOT NULL
            ORDER BY du.uploaded_at DESC LIMIT 500
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
                CONCAT(acc.firstname, ' ', acc.lastname) AS actor_name, 1 AS actor_type
            FROM business_applications ba
            LEFT JOIN business_info_data bid ON ba.id = bid.application_id
            LEFT JOIN application_type_data at ON ba.id = at.application_id
            LEFT JOIN accounts acc ON ba.user_id = acc.id
            WHERE ba.submitted_at IS NOT NULL
            ORDER BY ba.submitted_at DESC LIMIT 500
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
            ORDER BY a.created_at DESC LIMIT 500
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
                    'event_type' => 'status_change', 'event_id' => $row['application_id'],
                    'application_id' => $row['application_id'], 'old_status' => '—',
                    'new_status' => $row['status'], 'actor_id' => null,
                    'notes' => 'Current status', 'missing_documents_json' => null,
                    'event_time' => $row['event_time'] ?? $row['created_at'],
                    'reference_no' => $row['reference_no'], 'application_no' => $row['application_no'],
                    'business_name' => $row['business_name'],
                    'owner_name' => $row['owner_name'] ?? 'Unknown',
                    'actor_name' => 'System', 'actor_type' => null,
                ];
            }
        }
    }

    $docMap = [
        'barangay' => 'Barangay Clearance', 'dti_sec' => 'DTI/SEC', 'rpt' => 'RPT',
        'engineering' => 'Engineering', 'zoning' => 'Zoning Cert (MPDC)',
        'treasury' => 'Treasury', 'sanitary' => 'Sanitary Health Permit',
        'fire_safety' => 'Fire Safety Cert',
    ];
    foreach ($activityLog as &$log) {
        if (($log['event_type'] ?? '') === 'rejection' && !empty($log['missing_documents_json'])) {
            $missing = json_decode($log['missing_documents_json'], true);
            if (is_array($missing) && count($missing) > 0) {
                $labels = [];
                foreach ($missing as $key) $labels[] = $docMap[$key] ?? $key;
                $log['notes'] = trim(($log['notes'] ?? '') . ' — Missing: ' . implode(', ', $labels));
            }
        }
    }
    unset($log);

    $todayDate = date('Y-m-d');
    $weekAgo   = date('Y-m-d', strtotime('-7 days'));
    foreach ($activityLog as $log) {
        $logDate = date('Y-m-d', strtotime($log['event_time'] ?? 'now'));
        if ($logDate === $todayDate)  $activityStats['today']++;
        if ($logDate >= $weekAgo)     $activityStats['week']++;
        if (strtolower($log['new_status'] ?? '') === 'rejected') $activityStats['reject']++;
        if (strtolower($log['new_status'] ?? '') === 'verified') $activityStats['verified']++;

        $et = $log['event_type'] ?? '';
        if (in_array($et, ['document_rejection','fee_assessment','document_upload','rejection'], true))
            $activityStats['document_actions']++;
        if ($et === 'payment')                $activityStats['payment']++;
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

// ---------- ANNOUNCEMENTS ----------
$message = '';
$staffAnnouncements = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_announcement'])) {
        $title = trim($_POST['title']); $content = trim($_POST['content']);
        $status = 'pending'; $created_by = $staff_id;
        $stmt = $conn->prepare("INSERT INTO announcements (title, content, created_by, status) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssis", $title, $content, $created_by, $status);
            if ($stmt->execute()) $message = 'Announcement submitted for approval.';
            else $message = 'Error: ' . $stmt->error;
            $stmt->close();
        }
    }
    if (isset($_POST['edit_announcement'])) {
        $id = (int)$_POST['id']; $title = trim($_POST['title']); $content = trim($_POST['content']);
        $check = $conn->prepare("SELECT status FROM announcements WHERE id=? AND created_by=?");
        if ($check) {
            $check->bind_param("ii", $id, $staff_id); $check->execute();
            $cr = $check->get_result();
            if ($cr->num_rows === 1) {
                $row = $cr->fetch_assoc();
                if ($row['status'] === 'pending') {
                    $stmt = $conn->prepare("UPDATE announcements SET title=?, content=? WHERE id=? AND created_by=?");
                    if ($stmt) {
                        $stmt->bind_param("ssii", $title, $content, $id, $staff_id);
                        if ($stmt->execute()) $message = 'Announcement updated successfully.';
                        else $message = 'Error: ' . $stmt->error;
                        $stmt->close();
                    }
                } else $message = 'Cannot edit a non-pending announcement.';
            } else $message = 'Announcement not found.';
            $check->close();
        }
    }
    if (isset($_POST['delete_announcement'])) {
        $id = (int)$_POST['id'];
        $check = $conn->prepare("SELECT status FROM announcements WHERE id=? AND created_by=?");
        if ($check) {
            $check->bind_param("ii", $id, $staff_id); $check->execute();
            $cr = $check->get_result();
            if ($cr->num_rows === 1) {
                $row = $cr->fetch_assoc();
                if ($row['status'] === 'pending') {
                    $stmt = $conn->prepare("DELETE FROM announcements WHERE id=? AND created_by=?");
                    if ($stmt) {
                        $stmt->bind_param("ii", $id, $staff_id);
                        if ($stmt->execute()) $message = 'Announcement deleted.';
                        else $message = 'Error: ' . $stmt->error;
                        $stmt->close();
                    }
                } else $message = 'Cannot delete a non-pending announcement.';
            } else $message = 'Announcement not found.';
            $check->close();
        }
    }
}
$annStmt = $conn->prepare("SELECT a.*, CONCAT(acc.firstname, ' ', acc.lastname) AS creator_name
                           FROM announcements a
                           LEFT JOIN accounts acc ON a.created_by = acc.id
                           WHERE a.created_by = ?
                           ORDER BY a.created_at DESC");
if ($annStmt) {
    $annStmt->bind_param("i", $staff_id); $annStmt->execute();
    $annResult = $annStmt->get_result();
    while ($row = $annResult->fetch_assoc()) $staffAnnouncements[] = $row;
    $annStmt->close();
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --navy-dark:   #0f1f3d;
            --navy-hover:  #253d68;
            --blue-accent: #2563eb;
            --blue-hover:  #1d4ed8;
            --content-bg:  #f1f5f9;
            --border:      #e2e8f0;
            --text-dark:   #1e293b;
            --text-muted:  #64748b;
            --green:       #10b981;
            --yellow:      #f59e0b;
            --red:         #ef4444;
            --purple:      #8b5cf6;
        }
        * { font-family: 'Inter', sans-serif; box-sizing: border-box; }
        html, body { width: 100%; max-width: 100vw; }
        body { background: var(--content-bg); color: var(--text-dark); overflow-x: hidden; margin: 0; }

        .gov-header { background: var(--navy-dark); color: white; padding: 0.75rem 1.25rem;
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1030; gap: 8px; }
        .brand-wrap { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1; }
        .brand-logo { height: 36px; width: 36px; border-radius: 50%; background: white; padding: 2px; object-fit: contain; flex-shrink: 0; }
        .brand-text { min-width: 0; overflow: hidden; }
        .brand-text h5 { font-size: 0.9rem; font-weight: 700; margin: 0; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .brand-text small { font-size: 0.65rem; color: #94a3b8; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .header-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

        .user-greeting { background: rgba(255,255,255,0.08); border-radius: 40px;
            padding: 0.35rem 0.85rem; font-weight: 500; font-size: 0.8rem;
            cursor: pointer; color: #fff !important;
            display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .user-greeting:hover { background: rgba(255,255,255,0.15); color: #fff; }

        .staff-notif-bell { position: relative; width: 36px; height: 36px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; color: #fff; text-decoration: none; flex-shrink: 0; }
        .staff-notif-bell:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .staff-notif-bell i { font-size: 1.1rem; }
        .staff-notif-badge { position: absolute; top: 0; right: 0;
            min-width: 16px; height: 16px; padding: 0 4px;
            background: #dc3545; color: #fff; font-size: 0.6rem; font-weight: 700;
            border-radius: 9px; display: flex; align-items: center; justify-content: center;
            border: 2px solid var(--navy-dark); line-height: 1; }
        
        .notif-bell{position:relative;width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:50%;color:#fff;text-decoration:none}
        .notif-bell:hover{background:rgba(255,255,255,.08);color:#fff}
        .notif-bell i{font-size:1.15rem}
        .notif-bell .badge-dot{position:absolute;top:0;right:0;min-width:16px;height:16px;padding:0 4px;background:#dc3545;color:#fff;font-size:.6rem;font-weight:700;border-radius:9px;display:flex;align-items:center;justify-content:center;border:2px solid var(--navy-dark);line-height:1}
        .user-chip{display:inline-flex;align-items:center;gap:8px;color:#fff;text-decoration:none;padding:.4rem .85rem;border-radius:40px;background:rgba(255,255,255,.08);font-size:.85rem;font-weight:500}
        .user-chip:hover{background:rgba(255,255,255,.15);color:#fff}

        .staff-notif-menu {
            width: 360px;
            max-width: 90vw;
            padding: 0;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15,31,61,0.15);
            overflow: hidden;
        }
        .staff-notif-menu .notif-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            background: var(--navy-dark);
            color: #fff;
        }
        .staff-notif-menu .notif-header h6 {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .staff-notif-menu .notif-header .badge {
            background: var(--blue-accent);
            font-size: 0.68rem;
        }
        .staff-notif-menu .notif-list {
            max-height: 380px;
            overflow-y: auto;
        }
        .staff-notif-menu .notif-item {
            display: flex;
            gap: 10px;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            color: inherit;
            transition: background 0.15s;
        }
        .staff-notif-menu .notif-item:hover {
            background: #f8fafc;
            color: inherit;
        }
        .staff-notif-menu .notif-item.notif-no-link {
            cursor: default;
        }
        .staff-notif-menu .notif-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
        }
        .staff-notif-menu .notif-icon i {
            font-size: 1rem;
        }
        .staff-notif-menu .notif-content {
            min-width: 0;
            flex: 1;
        }
        .staff-notif-menu .notif-title {
            font-size: 0.82rem;
            font-weight: 600;
            margin: 0 0 2px;
            color: var(--text-dark);
        }
        .staff-notif-menu .notif-msg {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin: 0 0 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .staff-notif-menu .notif-time {
            font-size: 0.68rem;
            color: var(--text-muted);
        }
        .staff-notif-menu .notif-empty {
            padding: 2rem 1rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .staff-notif-menu .notif-empty i {
            display: block;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }
        .staff-notif-menu .notif-footer {
            text-align: center;
            background: #f8fafc;
            border-top: 1px solid var(--border);
        }
        .staff-notif-menu .notif-footer a {
            display: block;
            padding: 0.65rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--blue-accent);
            text-decoration: none;
        }
        .staff-notif-menu .notif-footer a:hover {
            background: #f1f5f9;
            color: var(--blue-hover);
        }

        .sidebar { background: var(--navy-dark); min-height: calc(100vh - 58px); padding: 1rem 0; }
        .sidebar .nav-link { color: #cbd5e1; padding: 0.7rem 1.25rem;
            font-weight: 500; font-size: 0.88rem;
            display: flex; align-items: center; gap: 12px;
            transition: all 0.15s; border: none; border-radius: 0; }
        .sidebar .nav-link i { font-size: 1.1rem; width: 20px; text-align: center; flex-shrink: 0; }
        .sidebar .nav-link:hover { background: var(--navy-hover); color: #fff; }
        .sidebar .nav-link.active { background: var(--blue-accent); color: #fff; font-weight: 600; }

        .main-content { padding: 1.25rem 1.5rem 2rem; min-width: 0; max-width: 100%; }
        .page-title { font-size: 1.35rem; font-weight: 700; margin: 0; }
        .page-subtitle { font-size: 0.85rem; color: var(--text-muted); margin: 3px 0 0; }

        .stat-card {
            background: white; border-radius: 12px; padding: 1rem;
            border: 1px solid var(--border); transition: all 0.15s;
            height: 100%; display: flex; align-items: center; gap: 12px; min-width: 0;
        }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(15,31,61,0.06); }
        .stat-icon { width: 44px; height: 44px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.25rem; flex-shrink: 0; }
        .stat-icon.blue   { background: #dbeafe; color: var(--blue-accent); }
        .stat-icon.green  { background: #d1fae5; color: #059669; }
        .stat-icon.yellow { background: #fef3c7; color: #d97706; }
        .stat-icon.red    { background: #fee2e2; color: #dc2626; }
        .stat-icon.purple { background: #ede9fe; color: var(--purple); }
        .stat-info { min-width: 0; flex: 1; }
        .stat-info .label { font-size: 0.72rem; color: var(--text-muted); font-weight: 500; margin-bottom: 3px; line-height: 1.2; }
        .stat-info .value { font-size: 1.4rem; font-weight: 700; color: var(--text-dark); line-height: 1; }

        /* Clickable stat cards */
        .stat-card-clickable { cursor: pointer; transition: transform .15s, box-shadow .15s; }
        .stat-card-clickable:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(15,31,61,.10); }
        .stat-card-clickable:active { transform: translateY(0); }
        .stat-card-clickable:focus-visible { outline: 2px solid var(--blue-accent); outline-offset: 2px; }
        .stat-card-clickable .stat-info .label i.bi-box-arrow-up-right { font-size: .65rem; opacity: .5; }

        .data-card { background: white; border-radius: 12px; border: 1px solid var(--border);
            overflow: hidden; margin-bottom: 1.25rem; }
        .data-card-header { padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 8px; }
        .data-card-header h5 { margin: 0; font-size: 0.95rem; font-weight: 700;
            display: flex; align-items: center; gap: 8px; min-width: 0; flex-wrap: wrap; }

        .custom-table { width: 100%; margin: 0; font-size: 0.85rem; }
        .custom-table thead th { background: #f8fafc; color: var(--text-muted);
            font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
            padding: 0.7rem 0.85rem; border-bottom: 1px solid var(--border); white-space: nowrap; }
        .custom-table tbody td { padding: 0.85rem 0.85rem; vertical-align: middle;
            border-bottom: 1px solid #f1f5f9; }
        .custom-table tbody tr:hover { background: #f8fafc; }
        .custom-table tbody tr:last-child td { border-bottom: none; }

        .badge-status { display: inline-block; padding: 0.3rem 0.65rem;
            border-radius: 6px; font-size: 0.7rem; font-weight: 600; line-height: 1; white-space: nowrap; }
        .badge-pending      { background: #fef3c7; color: #92400e; }
        .badge-paid         { background: #d1fae5; color: #065f46; }
        .badge-approved     { background: #d1fae5; color: #065f46; }
        .badge-rejected     { background: #fee2e2; color: #991b1b; }
        .badge-warning      { background: #fef3c7; color: #92400e; }
        .badge-secondary    { background: #f1f5f9; color: #475569; }
        .badge-info         { background: #dbeafe; color: #1e40af; }
        .badge-verified     { background: #d1fae5; color: #065f46; }
        .badge-assessed     { background: #d1fae5; color: #065f46; }
        .badge-failed       { background: #fee2e2; color: #991b1b; }
        
        
        /* Application type badges */
        .badge-new {
            background: #dbeafe;
            color: #1d4ed8;
            border: 1px solid #93c5fd;
        }
        
        .badge-renewal {
            background: #f3e8ff;
            color: #7e22ce;
            border: 1px solid #d8b4fe;
        }


        .btn-blue { background: var(--blue-accent); color: #fff; border: none;
            border-radius: 8px; padding: 0.5rem 1rem;
            font-weight: 600; font-size: 0.82rem; transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .btn-blue:hover { background: var(--blue-hover); color: #fff; }

        .btn-outline-custom { background: white; color: var(--text-dark);
            border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem 1rem;
            font-weight: 600; font-size: 0.82rem; transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .btn-outline-custom:hover { background: #f8fafc; border-color: #cbd5e1; color: var(--text-dark); }
        .btn-outline-custom.btn-sm { padding: 0.35rem 0.75rem; font-size: 0.75rem; }

        .btn-assess { background: var(--blue-accent); color: #fff; border: none;
            border-radius: 8px; padding: 0.42rem 1rem;
            font-weight: 600; font-size: 0.8rem;
            display: inline-flex; align-items: center; justify-content: center; gap: 5px;
            min-width: 80px; white-space: nowrap; }
        .btn-assess:hover { background: var(--blue-hover); color: #fff; }
        .btn-assess.re-assess { background: #fff; color: var(--blue-accent); border: 1px solid var(--blue-accent); }
        .btn-assess.re-assess:hover { background: var(--blue-accent); color: #fff; }

        .search-wrap { position: relative; max-width: 320px; width: 100%; }
        .search-wrap input { width: 100%; padding: 0.5rem 0.9rem 0.5rem 2.2rem;
            border: 1px solid var(--border); border-radius: 8px; font-size: 0.82rem; outline: none; }
        .search-wrap input:focus { border-color: var(--blue-accent); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }

        .filter-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
        .filter-row .form-control,
        .filter-row .form-select { border: 1px solid var(--border); border-radius: 8px;
            padding: 0.5rem 0.75rem; font-size: 0.82rem; min-width: 130px; }

        .chart-container { position: relative; width: 100%; height: 280px; }
        .chart-container canvas { max-width: 100%; }

        .offcanvas-start { width: 280px; }
        .offcanvas .offcanvas-body { padding: 0; background: var(--navy-dark); }
        .offcanvas .nav-link { color: #cbd5e1; padding: 0.7rem 1.25rem; font-size: 0.88rem;
            display: flex; align-items: center; gap: 12px; }
        .offcanvas .nav-link:hover { background: var(--navy-hover); color: #fff; }
        .offcanvas .nav-link.active { background: var(--blue-accent); color: #fff; font-weight: 600; }

        .dataTables_wrapper .dataTables_filter input { border: 1px solid var(--border); border-radius: 8px; padding: 0.4rem 0.75rem; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--blue-accent) !important; color: white !important;
            border-color: var(--blue-accent) !important; border-radius: 6px; }
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_paginate { font-size: 0.8rem; }

        #accountSettingsModal .form-label { font-size: 0.75rem; color: var(--text-dark); margin-bottom: 0.35rem; }
        #accountSettingsModal .form-control { border: 1px solid var(--border); border-radius: 8px;
            padding: 0.55rem 0.85rem; font-size: 0.85rem; }
        #accountSettingsModal .form-control:focus { border-color: var(--blue-accent); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        #accountSettingsModal .form-control:disabled { background: #f8fafc; cursor: not-allowed; }
        #accountSettingsModal .input-group-text { background: #f8fafc; border: 1px solid var(--border); font-size: 0.85rem; }

        .activity-filter-btn { background:#fff; border:1px solid var(--border); color:var(--text-dark);
            font-size:.78rem; font-weight:600; border-radius:8px; padding:.4rem .9rem;
            display:inline-flex; align-items:center; gap:.3rem; }
        .activity-filter-btn:hover { background:#f8fafc; }
        .activity-filter-btn.active { background:var(--navy-dark); color:#fff; border-color:var(--navy-dark); }

        .official-receipt-template { font-family: 'Courier New', monospace; color: #000;
            padding: 20px; border: 2px solid #000; margin: 0 auto; background: #fff; max-width: 720px; }
        .official-receipt-template .header { display: flex; align-items: center;
            border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 15px; }
        .official-receipt-template .header-logo { width: 80px; margin-right: 20px; text-align: center; }
        .official-receipt-template .header-logo img { max-width: 100%; height: auto; }
        .official-receipt-template .header-text { flex: 1; text-align: center; padding-right: 80px; }
        .official-receipt-template .header-text h2 { font-size: 22px; font-weight: bold; margin: 0; letter-spacing: 2px; font-family: 'Times New Roman', Times, serif; }
        .official-receipt-template .header-text p { font-size: 13px; margin: 5px 0 0 0; font-family: 'Times New Roman', serif; }
        .official-receipt-template .form-no { text-align: center; font-size: 12px; margin-bottom: 10px;
            border-bottom: 1px solid #000; padding-bottom: 5px; }
        .official-receipt-template .row { display: flex; border-bottom: 1px solid #000; margin: 0; }
        .official-receipt-template .row:last-child { border-bottom: none; }
        .official-receipt-template .col { flex: 1; padding: 5px 10px; border-right: 1px solid #000;
            font-size: 13px; display: flex; align-items: center; }
        .official-receipt-template .col:last-child { border-right: none; }
        .official-receipt-template .col.label { font-weight: bold; min-width: 90px; border-right: none; padding-right: 0; }
        .official-receipt-template .col.value { flex: 2; font-family: 'Courier New', monospace; font-weight: bold; }
        .official-receipt-template .table-container { border: 1px solid #000; margin-top: 15px; }
        .official-receipt-template table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .official-receipt-template th { border: 1px solid #000; padding: 8px;
            text-align: left; font-weight: normal; font-size: 12px; }
        .official-receipt-template td { border: 1px solid #000; padding: 5px 8px; height: 25px; }
        .official-receipt-template .total-row td { border-top: 2px solid #000; font-weight: bold; }
        .official-receipt-template .words-row td { font-weight: bold; text-align: center; font-size: 12px;
            border-top: 1px solid #000; padding: 10px; }
        .official-receipt-template .bottom-section { display: flex; margin-top: 15px; border: 1px solid #000; }
        .official-receipt-template .payment-options { flex: 1; padding: 10px; border-right: 1px solid #000; }
        .official-receipt-template .payment-options div { margin-bottom: 10px; display: flex; align-items: center; font-size: 13px; }
        .official-receipt-template .payment-options input[type="checkbox"] { margin-right: 10px; width: 14px; height: 14px; }
        .official-receipt-template .bank-details { flex: 2; }
        .official-receipt-template .bank-details table { border: none; height: 100%; }
        .official-receipt-template .bank-details th { border: 1px solid #000; text-align: center; font-size: 12px; padding: 5px; border-top: none; }
        .official-receipt-template .signature-block { display: flex; justify-content: flex-end; margin-top: 30px; }
        .official-receipt-template .signature-line { width: 250px; border-top: 1px solid #000;
            text-align: center; padding-top: 5px; font-weight: bold; font-size: 13px; }
        .official-receipt-template .note-text { font-size: 9px; text-align: center; margin-top: 20px; color: #333; }
        .official-receipt-template .footer-text { margin-top: 15px; font-size: 12px; }

        @media (max-width: 992px) {
            .sidebar { display: none; }
            .main-content { padding: 1rem; }
            .page-title { font-size: 1.2rem; }
            .page-subtitle { font-size: 0.8rem; }
        }
        @media (max-width: 768px) {
            .gov-header { padding: 0.6rem 0.9rem; }
            .brand-logo { height: 32px; width: 32px; }
            .brand-text h5 { font-size: 0.82rem; }
            .brand-text small { font-size: 0.6rem; }
            .user-greeting { padding: 0.3rem 0.7rem; font-size: 0.75rem; }
            .user-greeting span { display: none; }
            .staff-notif-bell { width: 32px; height: 32px; }
            .staff-notif-bell i { font-size: 1rem; }
            .stat-card { padding: 0.85rem; gap: 10px; }
            .stat-icon { width: 38px; height: 38px; font-size: 1.05rem; }
            .stat-info .value { font-size: 1.15rem; }
            .stat-info .label { font-size: 0.66rem; }
            .data-card-header { padding: 0.75rem 0.9rem; }
            .data-card-header h5 { font-size: 0.88rem; }
            .custom-table thead th,
            .custom-table tbody td { padding: 0.65rem 0.6rem; font-size: 0.78rem; }
            .btn-assess { padding: 0.35rem 0.75rem; font-size: 0.72rem; min-width: 70px; }
            .btn-blue, .btn-outline-custom { padding: 0.42rem 0.85rem; font-size: 0.76rem; }
            .search-wrap { max-width: 100%; }
            .search-wrap input { font-size: 0.78rem; }
            .filter-row .form-control,
            .filter-row .form-select { font-size: 0.78rem; min-width: 100%; flex: 1; }
            .chart-container { height: 220px; }
        }
        @media (max-width: 480px) {
            .main-content { padding: 0.75rem; }
            .page-title { font-size: 1.1rem; }
            .stat-card { padding: 0.7rem; gap: 8px; }
            .stat-icon { width: 34px; height: 34px; font-size: 0.95rem; }
            .stat-info .value { font-size: 1.05rem; }
            .stat-info .label { font-size: 0.62rem; }
            .custom-table thead th,
            .custom-table tbody td { padding: 0.55rem 0.5rem; font-size: 0.72rem; }
            .chart-container { height: 180px; }
        }

        .table-responsive { -webkit-overflow-scrolling: touch; }
        .table-responsive::-webkit-scrollbar { height: 6px; }
        .table-responsive::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

        /* Universal stat-card modal */
        #statCardModal .modal-body .custom-table thead th { position: sticky; top: 0; background: #fff; z-index: 1; }
        #statCardModal .modal-body { max-height: 70vh; overflow-y: auto; }

        /* ============================================================
        FEE ASSESSMENT MODAL (embeds payment.php)
        ============================================================ */
        #feeAssessmentModal .modal-content {
            box-shadow: 0 20px 60px rgba(15,31,61,.3);
            border: none;
            border-radius: 14px;
            overflow: hidden;
        }
        #feeAssessmentModal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            padding: 0 !important;
            position: relative;
            overflow: hidden;
        }
        #feeAssessmentModal #feeAssessFrame {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }
        #feeAssessmentModal .modal-header { border-bottom: none; }
        #feeAssessmentModal #feeAssessRefLabel {
            font-family: ui-monospace, monospace;
            font-size: .8rem;
            opacity: .85;
        }

        @media (max-width: 768px) {
            #feeAssessmentModal .modal-dialog {
                max-width: 100%;
                height: 100%;
                margin: 0;
            }
            #feeAssessmentModal .modal-content { height: 100vh; border-radius: 0; }
            #feeAssessmentModal .modal-footer { flex-wrap: wrap; }
        }

        /* ============================================================
        TREASURY RECORD MODAL (embeds treasury_record.php)
        ============================================================ */
        #viewTreasuryRecordModal .modal-content {
            box-shadow: 0 20px 60px rgba(15,31,61,.3);
            border: none;
            border-radius: 14px;
            overflow: hidden;
        }
        #viewTreasuryRecordModal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            padding: 0 !important;
            position: relative;
            overflow: hidden;
        }
        #viewTreasuryRecordModal #treasRecFrame {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            background: #f0f4f8;
        }
        #viewTreasuryRecordModal .modal-header { border-bottom: none; }
        #viewTreasuryRecordModal #treasRecRefLabel {
            font-family: ui-monospace, monospace;
            font-size: .8rem;
            opacity: .85;
        }

        @media (max-width: 768px) {
            #viewTreasuryRecordModal .modal-dialog {
                max-width: 100%;
                height: 100%;
                margin: 0;
            }
            #viewTreasuryRecordModal .modal-content { height: 100vh; border-radius: 0; }
            #viewTreasuryRecordModal .modal-footer { flex-wrap: wrap; }
            #viewTreasuryRecordModal #treasRecRefLabel { display: none; }
        }

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
            min-height: 0;
            padding: 0 !important;
            position: relative;
            overflow: hidden;
        }
        #viewApplicationModal #viewAppFrame {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }
        #viewApplicationModal .modal-header { border-bottom: none; }
        #viewApplicationModal #viewAppRefLabel {
            font-family: ui-monospace, monospace;
            font-size: .8rem;
            opacity: .85;
        }

        @media (max-width: 768px) {
            #viewApplicationModal .modal-dialog {
                max-width: 100%;
                height: 100%;
                margin: 0;
            }
            #viewApplicationModal .modal-content { height: 100vh; border-radius: 0; }
            #viewApplicationModal .modal-footer { flex-wrap: wrap; }
            #viewApplicationModal #viewAppRefLabel { display: none; }
        }
    </style>
</head>
<body>

<header class="gov-header">
    <div class="brand-wrap">
        <button class="btn text-white d-lg-none p-0 me-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" style="font-size:1.2rem;">
            <i class="bi bi-list"></i>
        </button>
        <img src="../img/<?php echo htmlspecialchars($headerLogo); ?>" alt="<?php echo htmlspecialchars($headerLogoAlt); ?>" class="brand-logo">
        <div class="brand-text">
            <h5>BUYAWANONG PLACER</h5>
            <small><?php echo $role_label; ?> · Municipal Business Permit Portal</small>
        </div>
    </div>
    <div class="header-actions">
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
            <a class="dropdown-toggle user-greeting" href="#" role="button" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle"></i> <span><?php echo htmlspecialchars($staff_name); ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#" id="openAccountSettings"><i class="bi bi-gear"></i> Account Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</header>

<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
    <div class="offcanvas-header bg-white border-bottom">
        <h6 class="offcanvas-title fw-bold">Menu</h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <nav class="nav flex-column">
            <a class="nav-link active" href="#" data-section="dashboard"><i class="bi bi-house"></i> Dashboard</a>
            <?php if ($showFeeAssessment): ?><a class="nav-link" href="#" data-section="fee-assessment"><i class="bi bi-clipboard-check"></i> Fee Assessment</a><?php endif; ?>
            <?php if ($showPaymentVerification): ?><a class="nav-link" href="#" data-section="payment-verification"><i class="bi bi-check2-square"></i> Payment Verification</a><?php endif; ?>
            <?php if ($showPayments): ?><a class="nav-link" href="#" data-section="payments"><i class="bi bi-credit-card"></i> Monitor Payments</a><?php endif; ?>
            <?php if ($showTreasuryRecords): ?><a class="nav-link" href="#" data-section="treasury-records"><i class="bi bi-folder"></i> Treasury Records</a><?php endif; ?>
            <?php if ($showActivityLog): ?><a class="nav-link" href="#" data-section="activity-log"><i class="bi bi-clock-history"></i> Activity Log</a><?php endif; ?>
            <?php if ($showAnnouncements): ?><a class="nav-link" href="#" data-section="announcements"><i class="bi bi-megaphone"></i> Announcements</a><?php endif; ?>
        </nav>
    </div>
</div>

<div class="container-fluid p-0">
    <div class="row g-0">
        <aside class="col-lg-2 sidebar d-none d-lg-block">
            <nav class="nav flex-column">
                <a class="nav-link active" href="#" data-section="dashboard"><i class="bi bi-house"></i> Dashboard</a>
                <?php if ($showFeeAssessment): ?><a class="nav-link" href="#" data-section="fee-assessment"><i class="bi bi-clipboard-check"></i> Fee Assessment</a><?php endif; ?>
                <?php if ($showPaymentVerification): ?><a class="nav-link" href="#" data-section="payment-verification"><i class="bi bi-check2-square"></i> Payment Verification</a><?php endif; ?>
                <?php if ($showPayments): ?><a class="nav-link" href="#" data-section="payments"><i class="bi bi-credit-card"></i> Monitor Payments</a><?php endif; ?>
                <?php if ($showTreasuryRecords): ?><a class="nav-link" href="#" data-section="treasury-records"><i class="bi bi-folder"></i> Treasury Records</a><?php endif; ?>
                <?php if ($showActivityLog): ?><a class="nav-link" href="#" data-section="activity-log"><i class="bi bi-clock-history"></i> Activity Log</a><?php endif; ?>
                <?php if ($showAnnouncements): ?><a class="nav-link" href="#" data-section="announcements"><i class="bi bi-megaphone"></i> Announcements</a><?php endif; ?>
            </nav>
        </aside>

        <main class="col-lg-10 main-content">
            <?php if ($message): ?>
                <div class="alert alert-info alert-dismissible fade show">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- DASHBOARD -->
            <div id="dashboard-section">
                <div class="d-flex justify-content-between align-items-end flex-wrap mb-3">
                    <div>
                        <h1 class="page-title"><?php echo $role_label; ?> Dashboard</h1>
                        <p class="page-subtitle"><?php echo date('l, F j, Y'); ?></p>
                    </div>
                </div>

                <div class="row g-2 g-md-3 mb-3 mb-md-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-clickable" data-tre-card="total" role="button" tabindex="0">
                            <div class="stat-icon blue"><i class="bi bi-people"></i></div>
                            <div class="stat-info">
                                <div class="label">Total Applications</div>
                                <div class="value"><?php echo $stats['total']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-clickable" data-tre-card="for_assessment" role="button" tabindex="0">
                            <div class="stat-icon yellow"><i class="bi bi-file-earmark-text"></i></div>
                            <div class="stat-info">
                                <div class="label">For Assessment</div>
                                <div class="value"><?php echo count($feeAssessmentApps); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-clickable" data-tre-card="pending_payment" role="button" tabindex="0">
                            <div class="stat-icon green"><i class="bi bi-wallet2"></i></div>
                            <div class="stat-info">
                                <div class="label">Pending Payment</div>
                                <div class="value"><?php echo $stats['pending_payment']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-clickable" data-tre-card="approved_issued" role="button" tabindex="0">
                            <div class="stat-icon purple"><i class="bi bi-patch-check"></i></div>
                            <div class="stat-info">
                                <div class="label">Approved / Issued</div>
                                <div class="value"><?php echo $stats['approved_issued']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="data-card">
                    <div class="data-card-header">
                        <h5><i class="bi bi-clock-history"></i> Recent Applications</h5>
                        <span class="badge-status badge-warning">Pending Payment</span>
                    </div>
                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Business Name</th><th>Type</th><th>Date Filed</th><th>Amount</th><th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recentApps) > 0): ?>
                                    <?php foreach ($recentApps as $app):
                                        $status = strtolower($app['status']);
                                        $badgeClass = 'badge-secondary';
                                        if ($status === 'pending_payment')  $badgeClass = 'badge-warning';
                                        elseif ($status === 'payment_confirmed') $badgeClass = 'badge-info';
                                        elseif (in_array($status, ['approved','verified','issued'])) $badgeClass = 'badge-approved';
                                        $amount = isset($app['amount']) ? number_format($app['amount'], 2) : '0.00';
                                    ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($app['business_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($app['application_type'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                            <td>₱<?php echo $amount; ?></td>
                                            <td><span class="badge-status <?php echo $badgeClass; ?>"><?php echo ucfirst(str_replace('_', ' ', $status)); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No applications found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- FEE ASSESSMENT -->
            <?php if ($showFeeAssessment): ?>
            <div id="fee-assessment-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-end flex-wrap mb-3 gap-2">
                    <div>
                        <h1 class="page-title">Applications for Fee Assessment</h1>
                        <p class="page-subtitle">Applications pending payment that need fee computation</p>
                    </div>
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" id="feeSearchInput" placeholder="Search business name...">
                    </div>
                </div>

                <div class="data-card">
                    <div class="table-responsive">
                        <table class="custom-table" id="feeAssessmentTable">
                            <thead>
                                <tr>
                                    <th style="width:50px;">No.</th><th>Business Name</th><th>Owner</th><th>Type</th>
                                    <th>Date Approved</th><th style="width:110px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($feeAssessmentApps) > 0): ?>
                                    <?php $i = 1; foreach ($feeAssessmentApps as $app):
                                        $isAssessed = ((int)($app['assessed_count'] ?? 0) > 0);
                                    ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($app['business_name']); ?></td>
                                            <td><?php echo htmlspecialchars($app['owner_name']); ?></td>
                                            <td><?php echo htmlspecialchars($app['application_type']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($app['application_date'])); ?></td>
                                            <td>
                                                <a href="payment.php?ref=<?php echo urlencode($app['reference_no']); ?>"
                                                class="btn-assess<?php echo $isAssessed ? ' re-assess' : ''; ?>"
                                                data-business="<?php echo htmlspecialchars($app['business_name'] ?? '', ENT_QUOTES); ?>">
                                                    <i class="bi bi-<?php echo $isAssessed ? 'arrow-repeat' : 'pencil-square'; ?>"></i>
                                                    <?php echo $isAssessed ? 'Re-assess' : 'Assess'; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-5">No applications waiting for assessment.</td></tr>
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
                <div class="d-flex justify-content-between align-items-end flex-wrap mb-3">
                    <div>
                        <h1 class="page-title">Payment Verification</h1>
                        <p class="page-subtitle">Verify payments received from applicants</p>
                    </div>
                </div>

                <div class="filter-row">
                    <div class="search-wrap" style="flex:1; max-width:360px;">
                        <i class="bi bi-search"></i>
                        <input type="text" id="pvSearchInput" placeholder="Search by Business Name or Reference No.">
                    </div>
                    <select class="form-select" id="pvStatusFilter" style="max-width:180px;">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="paid">Paid</option>
                    </select>
                    <input type="date" class="form-control" id="pvDateFrom" style="max-width:160px;">
                    <input type="date" class="form-control" id="pvDateTo" style="max-width:160px;">
                    <button class="btn-blue" onclick="filterPaymentVerification()">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>

                <div class="data-card">
                    <div class="table-responsive">
                        <table class="custom-table" id="paymentVerificationTable">
                            <thead>
                                <tr>
                                    <th style="width:50px;">No.</th>
                                    <th>Business Name</th>
                                    <th>Owner</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($pendingPaymentApps) > 0): ?>
                                    <?php
                                    $i = 1;
                                    foreach ($pendingPaymentApps as $app):
                                        $amount = isset($app['amount'])
                                            ? number_format($app['amount'], 2)
                                            : '0.00';
                                    ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                        
                                            <td class="fw-semibold">
                                                <?php echo htmlspecialchars($app['business_name']); ?>
                                            </td>
                        
                                            <td>
                                                <?php echo htmlspecialchars($app['owner_name']); ?>
                                            </td>
                        
                                            <td>
                                                <strong>₱<?php echo $amount; ?></strong>
                                            </td>
                        
                                            <td>PayPal</td>
                        
                                            <td>
                                                <span class="badge-status badge-warning">
                                                    Pending
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-5">
                                            No pending payments to verify.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>


            <!-- MONITOR PAYMENTS -->
            <?php if ($showPayments): ?>
            <div id="payments-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-end flex-wrap mb-3">
                    <div>
                        <h1 class="page-title">Monitor Payments</h1>
                        <p class="page-subtitle">PayPal transactions from applicants</p>
                    </div>
                </div>
            
                <div class="data-card mb-3">
                    <div class="data-card-header">
                        <h5>
                            <i class="bi bi-graph-up"></i>
                            Monthly Collections — Last 12 Months
                        </h5>
                        <span class="badge-status badge-approved">
                            Total: ₱<?php echo number_format($monthlyTotal, 2); ?>
                        </span>
                    </div>
            
                    <div class="p-3">
                        <div class="chart-container">
                            <canvas id="paymentChart"></canvas>
                        </div>
                    </div>
                </div>
            
                <div class="data-card">
                    <div class="data-card-header">
                        <h5>
                            <i class="bi bi-list-ul"></i>
                            All Payments (from PayPal)
                        </h5>
                    </div>
            
                    <div class="table-responsive">
                        <?php if ($paymentsError): ?>
            
                            <div class="alert alert-danger m-3">
                                <?php echo htmlspecialchars($paymentsError); ?>
                            </div>
            
                        <?php elseif (empty($payments)): ?>
            
                            <div class="alert alert-info m-3">
                                No payments found.
                            </div>
            
                        <?php else: ?>
            
                            <table id="paymentsTable" class="custom-table">
                                <thead>
                                    <tr>
                                        <th>Transaction ID</th>
                                        <th>Reference</th>
                                        <th>Business Name</th>
                                        <th>Application Type</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Paid At</th>
                                        <th>Method</th>
                                        <th style="width:110px;">Receipt</th>
                                    </tr>
                                </thead>
            
                                <tbody>
                                <?php foreach ($payments as $pay): ?>
            
                                    <?php
                                    $txnId = $pay['paypal_capture_id']
                                        ?: ($pay['paypal_order_id'] ?: ('PT-' . $pay['id']));
            
                                    $ref = $pay['reference_no']
                                        ?: ($pay['app_reference_no'] ?? '—');
            
                                    $businessName = trim($pay['business_name'] ?? '');
                                    if ($businessName === '') {
                                        $businessName = 'N/A';
                                    }
            
                                    // Identify whether this is a new application or renewal.
                                    $appTypeRaw = trim($pay['application_type'] ?? '');
                                    $appTypeNormalized = strtolower($appTypeRaw);
            
                                    if (
                                        strpos($appTypeNormalized, 'renew') !== false
                                    ) {
                                        $appTypeLabel = 'Renewal';
                                        $appTypeClass = 'badge-renewal';
            
                                    } elseif (
                                        strpos($appTypeNormalized, 'new') !== false
                                    ) {
                                        $appTypeLabel = 'New Application';
                                        $appTypeClass = 'badge-new';
            
                                    } else {
                                        $appTypeLabel = $appTypeRaw !== ''
                                            ? $appTypeRaw
                                            : 'Not specified';
            
                                        $appTypeClass = 'badge-secondary';
                                    }
            
                                    $amountRaw = (float)($pay['amount'] ?? 0);
                                    $amount = number_format($amountRaw, 2);
                                    $currency = strtoupper($pay['currency'] ?? 'PHP');
            
                                    $status = strtolower(
                                        $pay['payment_status'] ?? 'unknown'
                                    );
            
                                    $statusClass = 'badge-secondary';
            
                                    if (in_array($status, ['completed', 'paid'], true)) {
                                        $statusClass = 'badge-paid';
            
                                    } elseif (
                                        in_array(
                                            $status,
                                            ['failed', 'voided', 'cancelled', 'expired'],
                                            true
                                        )
                                    ) {
                                        $statusClass = 'badge-failed';
            
                                    } elseif (
                                        in_array(
                                            $status,
                                            ['pending', 'created', 'approved', 'processing'],
                                            true
                                        )
                                    ) {
                                        $statusClass = 'badge-pending';
                                    }
            
                                    $paidAtTs = toManilaTs($pay['paid_at'] ?? null);
                                    $paidAtStr = toManila(
                                        $pay['paid_at'] ?? null,
                                        'M d, Y H:i'
                                    );
            
                                    if (!empty($pay['paid_at'])) {
                                        $ts = strtotime($pay['paid_at']);
            
                                        if ($ts !== false) {
                                            $paidAtTs = $ts;
                                            $paidAtStr = date('M d, Y H:i', $ts);
                                        }
                                    }
            
                                    $hasReceipt = in_array(
                                        $status,
                                        ['completed', 'paid'],
                                        true
                                    );
                                    ?>
            
                                    <tr>
                                        <!-- Transaction ID -->
                                        <td>
                                            <code class="small">
                                                <?php
                                                echo htmlspecialchars(
                                                    substr((string)$txnId, 0, 16)
                                                );
                                                ?>…
                                            </code>
                                        </td>
            
                                        <!-- Reference -->
                                        <td>
                                            <?php echo htmlspecialchars((string)$ref); ?>
                                        </td>
            
                                        <!-- Business Name -->
                                        <td>
                                            <?php echo htmlspecialchars($businessName); ?>
                                        </td>
            
                                        <!-- Application Type -->
                                        <td>
                                            <span class="badge-status <?php echo $appTypeClass; ?>">
                                                <?php echo htmlspecialchars($appTypeLabel); ?>
                                            </span>
                                        </td>
            
                                        <!-- Amount -->
                                        <td>
                                            <strong>
                                                <?php
                                                echo ($currency === 'PHP' ? '₱' : $currency . ' ')
                                                    . $amount;
                                                ?>
                                            </strong>
                                        </td>
            
                                        <!-- Payment Status -->
                                        <td>
                                            <span class="badge-status <?php echo $statusClass; ?>">
                                                <?php echo htmlspecialchars(ucfirst($status)); ?>
                                            </span>
                                        </td>
            
                                        <!-- Paid At -->
                                        <td>
                                            <?php echo htmlspecialchars($paidAtStr); ?>
                                        </td>
            
                                        <!-- Payment Method -->
                                        <td>PayPal</td>
            
                                        <!-- Receipt -->
                                        <td>
                                            <?php if ($hasReceipt): ?>
            
                                                <button
                                                    type="button"
                                                    class="btn-outline-custom btn-sm pp-receipt-btn"
                                                    data-txn="<?php echo htmlspecialchars((string)$txnId, ENT_QUOTES); ?>"
                                                    data-ref="<?php echo htmlspecialchars((string)$ref, ENT_QUOTES); ?>"
                                                    data-amount="<?php echo number_format($amountRaw, 2, '.', ''); ?>"
                                                    data-paid-at="<?php echo $paidAtTs ? (int)$paidAtTs : ''; ?>"
                                                    data-source="PayPal"
                                                    data-app-id="<?php echo (int)($pay['application_id'] ?? 0); ?>"
                                                    data-business="<?php echo htmlspecialchars($businessName, ENT_QUOTES); ?>"
                                                    data-owner="<?php echo htmlspecialchars(trim(($pay['first_name'] ?? '') . ' ' . ($pay['last_name'] ?? '')), ENT_QUOTES); ?>"
                                                    data-app-type="<?php echo htmlspecialchars($appTypeLabel, ENT_QUOTES); ?>"
                                                    data-gst="<?php echo (float)($pay['gross_sales_tax'] ?? 0); ?>"
                                                    data-mayor="<?php echo (float)($pay['mayors_permit_fee'] ?? 0); ?>"
                                                    data-garbage="<?php echo (float)($pay['garbage_charges'] ?? 0); ?>"
                                                    data-sanitary="<?php echo (float)($pay['sanitary_inspection_fee'] ?? 0); ?>"
                                                    data-building="<?php echo (float)($pay['building_inspection_fee'] ?? 0); ?>"
                                                    data-electrical="<?php echo (float)($pay['electrical_inspection_fee'] ?? 0); ?>"
                                                    data-fire="<?php echo (float)($pay['fire_safety_fee_percent'] ?? 0); ?>"
                                                    data-others="<?php echo (float)($pay['others'] ?? 0); ?>"
                                                    data-permit-calling="<?php echo (float)($pay['permit_on_calling'] ?? 0); ?>"
                                                    data-health-cert="<?php echo (float)($pay['health_certificate'] ?? 0); ?>"
                                                    data-weight-measure="<?php echo (float)($pay['weight_measure'] ?? 0); ?>"
                                                    data-misc="<?php echo (float)($pay['misc_fee'] ?? 0); ?>"
                                                    data-surcharge="<?php echo (float)($pay['surcharge_fee'] ?? 0); ?>"
                                                    data-interest="<?php echo (float)($pay['interest_fee'] ?? 0); ?>"
                                                    data-late-payment="<?php echo (float)($pay['late_payment'] ?? 0); ?>"
                                                    data-penalty-lost="<?php echo (float)($pay['penalty_lost_permit'] ?? 0); ?>"
                                                    title="View Official Receipt"
                                                >
                                                    <i class="bi bi-receipt"></i>
                                                    Receipt
                                                </button>
            
                                            <?php else: ?>
            
                                                <span class="text-muted small">—</span>
            
                                            <?php endif; ?>
                                        </td>
                                    </tr>
            
                                <?php endforeach; ?>
                                </tbody>
                            </table>
            
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- TREASURY RECORDS -->
            <?php if ($showTreasuryRecords): ?>
            <div id="treasury-records-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-end flex-wrap mb-3">
                    <div>
                        <h1 class="page-title">Treasury Records</h1>
                        <p class="page-subtitle">Official record of assessed and confirmed payments</p>
                    </div>
                </div>

                <div class="row g-2 g-md-3 mb-3 mb-md-4">
                    <div class="col-6 col-md-4">
                        <div class="stat-card stat-card-clickable" data-tre-rec-card="total" role="button" tabindex="0">
                            <div class="stat-icon blue"><i class="bi bi-folder"></i></div>
                            <div class="stat-info">
                                <div class="label">Total Records</div>
                                <div class="value"><?php echo count($treasuryRecords); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="stat-card stat-card-clickable" data-tre-rec-card="amount" role="button" tabindex="0">
                            <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
                            <div class="stat-info">
                                <div class="label">Total Amount</div>
                                <div class="value" style="font-size:1.05rem;">₱<?php
                                    $totalRec = 0;
                                    foreach ($treasuryRecords as $r) $totalRec += (float)($r['amount'] ?? 0);
                                    echo number_format($totalRec, 2);
                                ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="stat-card stat-card-clickable" data-tre-rec-card="year" role="button" tabindex="0">
                            <div class="stat-icon purple"><i class="bi bi-calendar3"></i></div>
                            <div class="stat-info">
                                <div class="label">This Year</div>
                                <div class="value"><?php
                                    $yrCount = 0;
                                    foreach ($treasuryRecords as $r) if (date('Y', strtotime($r['application_date'])) == date('Y')) $yrCount++;
                                    echo $yrCount;
                                ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="data-card">
                    <div class="data-card-header">
                        <h5><i class="bi bi-list-ul"></i> Payment Confirmed Applications</h5>
                        <span class="badge-status badge-approved">Payment Confirmed</span>
                    </div>
                    <div class="table-responsive">
                        <?php if (count($treasuryRecords) > 0): ?>
                            <table id="treasuryRecordsTable" class="custom-table">
                                <thead>
                                    <tr>
                                        <th>ID</th><th>Reference</th><th>Business Name</th><th>Type</th>
                                        <th>Date Filed</th><th>Amount</th><th>Tax Year</th><th>Assessed By</th>
                                        <th style="width:80px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($treasuryRecords as $record):
                                        $amount = number_format((float)($record['amount'] ?? 0), 2);
                                        $assessedBy = !empty($record['assessed_by_name']) ? $record['assessed_by_name'] : 'N/A';
                                    ?>
                                        <tr>
                                            <td><?php echo $record['application_id']; ?></td>
                                            <td><code class="small"><?php echo htmlspecialchars($record['reference_no']); ?></code></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($record['business_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($record['application_type'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($record['application_date'])); ?></td>
                                            <td><strong>₱<?php echo $amount; ?></strong></td>
                                            <td><?php echo $record['tax_year'] ?? date('Y'); ?></td>
                                            <td><?php echo htmlspecialchars($assessedBy); ?></td>
                                            <td>
                                                <a href="treasury_record.php?id=<?php echo $record['application_id']; ?>"
                                                class="btn-outline-custom btn-sm"
                                                data-business="<?php echo htmlspecialchars($record['business_name'] ?? 'N/A', ENT_QUOTES); ?>"
                                                data-ref="<?php echo htmlspecialchars($record['reference_no'] ?? '', ENT_QUOTES); ?>">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="alert alert-info text-center m-3">No payment-confirmed records found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ACTIVITY LOG -->
            <?php if ($showActivityLog): ?>
            <div id="activity-log-section" style="display:none;">
                <div class="d-flex justify-content-between align-items-end flex-wrap mb-3">
                    <div>
                        <h1 class="page-title">Activity Log</h1>
                        <p class="page-subtitle">Unified transaction log — status changes, fee assessments, rejections, and PayPal payments.</p>
                    </div>
                    <span class="badge-status badge-info"><i class="bi bi-clock-history"></i> <?php echo $activityStats['total']; ?> records</span>
                </div>

                <div class="row g-2 g-md-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="stat-card stat-card-clickable" data-tre-act-card="total" role="button" tabindex="0">
                            <div class="stat-icon blue"><i class="bi bi-list-ul"></i></div>
                            <div class="stat-info">
                                <div class="label">Total activities</div>
                                <div class="value"><?php echo $activityStats['total']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card stat-card-clickable" data-tre-act-card="today" role="button" tabindex="0">
                            <div class="stat-icon green"><i class="bi bi-calendar-check"></i></div>
                            <div class="stat-info">
                                <div class="label">Today</div>
                                <div class="value"><?php echo $activityStats['today']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card stat-card-clickable" data-tre-act-card="payments" role="button" tabindex="0">
                            <div class="stat-icon purple"><i class="bi bi-paypal"></i></div>
                            <div class="stat-info">
                                <div class="label">PayPal payments</div>
                                <div class="value"><?php echo $activityStats['payment']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card stat-card-clickable" data-tre-act-card="reject" role="button" tabindex="0">
                            <div class="stat-icon red"><i class="bi bi-x-circle"></i></div>
                            <div class="stat-info">
                                <div class="label">Rejections</div>
                                <div class="value"><?php echo $activityStats['reject']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 g-md-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="stat-card stat-card-clickable" data-tre-act-card="bplo" role="button" tabindex="0">
                            <div class="stat-icon green"><i class="bi bi-file-earmark-check"></i></div>
                            <div class="stat-info">
                                <div class="label">BPLO actions</div>
                                <div class="value"><?php echo $activityStats['bplo']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card stat-card-clickable" data-tre-act-card="treasury" role="button" tabindex="0">
                            <div class="stat-icon yellow"><i class="bi bi-cash-stack"></i></div>
                            <div class="stat-info">
                                <div class="label">Treasury actions</div>
                                <div class="value"><?php echo $activityStats['treasury']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card stat-card-clickable" data-tre-act-card="admin" role="button" tabindex="0">
                            <div class="stat-icon purple"><i class="bi bi-shield-lock"></i></div>
                            <div class="stat-info">
                                <div class="label">Admin actions</div>
                                <div class="value"><?php echo $activityStats['admin']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card stat-card-clickable" data-tre-act-card="system" role="button" tabindex="0">
                            <div class="stat-icon blue"><i class="bi bi-gear"></i></div>
                            <div class="stat-info">
                                <div class="label">System / other</div>
                                <div class="value"><?php echo max(0, $activityStats['total'] - $activityStats['bplo'] - $activityStats['treasury'] - $activityStats['admin'] - $activityStats['payment']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div class="d-flex gap-2 flex-wrap" id="treasuryActivityFilterBtns">
                        <button type="button" class="activity-filter-btn active" data-role="all"><i class="bi bi-list-ul"></i> All</button>
                        <button type="button" class="activity-filter-btn" data-role="payment"><i class="bi bi-paypal"></i> Payments</button>
                        <button type="button" class="activity-filter-btn" data-role="4"><i class="bi bi-file-earmark-check"></i> BPLO</button>
                        <button type="button" class="activity-filter-btn" data-role="3"><i class="bi bi-cash-coin"></i> Treasury</button>
                        <button type="button" class="activity-filter-btn" data-role="5"><i class="bi bi-shield-lock"></i> Admin</button>
                        <button type="button" class="activity-filter-btn" data-role="0"><i class="bi bi-gear"></i> System</button>
                    </div>
                    <span class="badge-status badge-info"><i class="bi bi-info-circle"></i> Click reference to view application</span>
                </div>

                <div class="data-card">
                    <div class="data-card-header">
                        <h5><i class="bi bi-list-ul"></i> All transaction records</h5>
                        <span class="badge-status badge-secondary">Newest first · max 500</span>
                    </div>
                    <div class="table-responsive">
                        <table class="custom-table" id="treasuryActivityLogTable">
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

                                        $newBadge = 'badge-pending';
                                        if ($newSt === 'submitted')          $newBadge = 'badge-warning';
                                        elseif ($newSt === 'under_review')   $newBadge = 'badge-info';
                                        elseif ($newSt === 'pending_payment')$newBadge = 'badge-warning';
                                        elseif ($newSt === 'payment_confirmed') $newBadge = 'badge-info';
                                        elseif ($newSt === 'completed')      $newBadge = 'badge-paid';
                                        elseif ($newSt === 'paid')           $newBadge = 'badge-paid';
                                        elseif (in_array($newSt, ['approved','claimed','verified','assessed','uploaded','booked'])) $newBadge = 'badge-approved';
                                        elseif (in_array($newSt, ['rejected','voided','failed'])) $newBadge = 'badge-rejected';
                                        elseif ($newSt === 'cancelled')      $newBadge = 'badge-secondary';

                                        $oldBadge = 'badge-secondary';
                                        if (in_array($oldSt, ['approved','claimed','verified'])) $oldBadge = 'badge-approved';
                                        elseif (in_array($oldSt, ['rejected','voided','failed'])) $oldBadge = 'badge-rejected';
                                        elseif ($oldSt === 'under_review')   $oldBadge = 'badge-info';
                                        elseif ($oldSt === 'pending_payment')$oldBadge = 'badge-warning';
                                        elseif ($oldSt === 'submitted')      $oldBadge = 'badge-warning';

                                        $eventIcon = 'bi-arrow-repeat'; $eventLabel = 'Status change'; $eventColor = '#6366f1';
                                        if ($eventType === 'fee_assessment') { $eventIcon = 'bi-cash-coin'; $eventLabel = 'Fee assessment'; $eventColor = '#f59e0b'; }
                                        elseif ($eventType === 'rejection' || $eventType === 'document_rejection') { $eventIcon = 'bi-x-circle'; $eventLabel = 'Rejection'; $eventColor = '#ef4444'; }
                                        elseif ($eventType === 'payment') { $eventIcon = 'bi-paypal'; $eventLabel = 'PayPal payment'; $eventColor = '#0070ba'; }
                                        elseif ($eventType === 'appointment_created') { $eventIcon = 'bi-calendar-plus'; $eventLabel = 'Appointment booked'; $eventColor = '#8b5cf6'; }
                                        elseif ($eventType === 'appointment_confirmed') { $eventIcon = 'bi-calendar-check'; $eventLabel = 'Appointment confirmed'; $eventColor = '#3b82f6'; }
                                        elseif ($eventType === 'appointment_completed') { $eventIcon = 'bi-calendar-check-fill'; $eventLabel = 'Appointment completed'; $eventColor = '#10b981'; }
                                        elseif ($eventType === 'appointment_cancelled') { $eventIcon = 'bi-calendar-x'; $eventLabel = 'Appointment cancelled'; $eventColor = '#ef4444'; }
                                        elseif ($eventType === 'document_upload') { $eventIcon = 'bi-cloud-upload'; $eventLabel = 'Document uploaded'; $eventColor = '#06b6d4'; }
                                        elseif ($eventType === 'application_submitted') { $eventIcon = 'bi-file-earmark-plus'; $eventLabel = 'Application submitted'; $eventColor = '#10b981'; }
                                        elseif (strpos($eventType, 'announcement_') === 0) { $eventIcon = 'bi-megaphone'; $eventLabel = 'Announcement'; $eventColor = '#f59e0b'; }

                                        $actorT = (int)($log['actor_type'] ?? 0);
                                        $roleLabel = 'System';
                                        $roleStyle = 'background:#e5e7eb; color:#374151;';
                                        if ($actorT === 3)      { $roleLabel = 'Treasury'; $roleStyle = 'background:#fef3c7; color:#92400e;'; }
                                        elseif ($actorT === 4)  { $roleLabel = 'BPLO';     $roleStyle = 'background:#d1fae5; color:#065f46;'; }
                                        elseif ($actorT === 5)  { $roleLabel = 'Admin';    $roleStyle = 'background:#e9d5ff; color:#6b21a8;'; }
                                        elseif ($actorT === 2)  { $roleLabel = 'Staff';    $roleStyle = 'background:#dbeafe; color:#1e40af;'; }
                                    ?>
                                        <tr data-actor-role="<?php echo $actorT; ?>" data-event-type="<?php echo htmlspecialchars($eventType, ENT_QUOTES); ?>">
                                            <td><?php echo $counter++; ?></td>
                                            <td data-order="<?php echo (int)strtotime($log['event_time']); ?>">
                                                <div class="fw-semibold"><?php echo date('M d, Y', strtotime($log['event_time'])); ?></div>
                                                <div class="small text-muted"><i class="bi bi-clock"></i> <?php echo date('g:i A', strtotime($log['event_time'])); ?></div>
                                            </td>
                                            <td>
                                                <span style="display:inline-flex; align-items:center; gap:.3rem; padding:.25rem .6rem; border-radius:2rem; font-size:.72rem; font-weight:600; background:<?php echo $eventColor; ?>22; color:<?php echo $eventColor; ?>;">
                                                    <i class="bi <?php echo $eventIcon; ?>"></i> <?php echo $eventLabel; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <code class="small"><?php echo htmlspecialchars($log['reference_no'] ?? '—'); ?></code>
                                                <?php if (!empty($log['application_no'])): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($log['application_no']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($log['business_name'] ?? '—'); ?></div>
                                                <div class="small text-muted"><i class="bi bi-person"></i> <?php echo htmlspecialchars($log['owner_name'] ?? 'Unknown'); ?></div>
                                            </td>
                                            <td>
                                                <?php if ($oldSt && $oldSt !== '—'): ?>
                                                    <span class="badge-status <?php echo $oldBadge; ?>"><?php echo ucfirst(str_replace('_',' ', $oldSt)); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge-status <?php echo $newBadge; ?>"><?php echo ucfirst(str_replace('_',' ', $newSt)); ?></span></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($log['actor_name'] ?? 'System'); ?></div>
                                                <span style="display:inline-block; padding:.15rem .5rem; border-radius:1rem; font-size:.65rem; font-weight:700; <?php echo $roleStyle; ?>"><?php echo $roleLabel; ?></span>
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
                <div class="d-flex justify-content-between align-items-end flex-wrap mb-3 gap-2">
                    <div>
                        <h1 class="page-title">Announcements</h1>
                        <p class="page-subtitle">Post announcements for admin approval</p>
                    </div>
                    <button class="btn-blue" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">
                        <i class="bi bi-plus-circle"></i> Add
                    </button>
                </div>

                <div class="data-card">
                    <div class="data-card-header">
                        <h5><i class="bi bi-megaphone"></i> Your Announcements</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="custom-table" id="staffAnnouncementsTable">
                            <thead>
                                <tr>
                                    <th>Title</th><th>Content</th><th>Status</th><th>Created</th><th style="width:100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($staffAnnouncements) > 0): ?>
                                    <?php foreach ($staffAnnouncements as $ann): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($ann['title']); ?></td>
                                            <td><?php echo htmlspecialchars(substr($ann['content'], 0, 40)) . (strlen($ann['content']) > 40 ? '…' : ''); ?></td>
                                            <td>
                                                <?php
                                                    $sc = 'badge-pending';
                                                    if ($ann['status'] == 'approved') $sc = 'badge-approved';
                                                    elseif ($ann['status'] == 'rejected') $sc = 'badge-rejected';
                                                ?>
                                                <span class="badge-status <?php echo $sc; ?>"><?php echo ucfirst($ann['status']); ?></span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></td>
                                            <td>
                                                <?php if ($ann['status'] == 'pending'): ?>
                                                    <button class="btn-outline-custom btn-sm edit-staff-announcement"
                                                            data-id="<?php echo $ann['id']; ?>"
                                                            data-title="<?php echo htmlspecialchars($ann['title']); ?>"
                                                            data-content="<?php echo htmlspecialchars($ann['content']); ?>"
                                                            data-bs-toggle="modal" data-bs-target="#editAnnouncementModal"
                                                            title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete?');">
                                                        <input type="hidden" name="id" value="<?php echo $ann['id']; ?>">
                                                        <button type="submit" name="delete_announcement" class="btn-outline-custom btn-sm text-danger" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted small"><i class="bi bi-lock"></i></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No announcements yet.</td></tr>
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

<!-- PAYPAL OFFICIAL RECEIPT MODAL -->
<div class="modal fade" id="pmReceiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:800px;">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--navy-dark); color: #fff; border-bottom: none;">
                <h5 class="modal-title fw-bold"><i class="bi bi-receipt me-2"></i> Official Receipt</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="background:#fff;">
                <div id="pmReceiptContent" style="padding:10px;">
                    <div class="official-receipt-template" id="pmReceiptTemplate">
                        <div class="header">
                            <div class="header-logo"><img src="../img/official-logo.png" alt="Municipality Logo" style="max-height:60px;"></div>
                            <div class="header-text"><h2>OFFICIAL RECEIPT</h2><p>Republic of the Philippines</p></div>
                        </div>
                        <div class="form-no"><span>Accountable Form No. 51</span><br><span>Revised January 1992</span></div>
                        <div class="row" style="border-top:1px solid #000;">
                            <div class="col"><span class="label">DATE</span><span class="value" id="pm-rcpt-date" style="margin-left:10px;">__________</span></div>
                            <div class="col"><span class="label">NO.</span><span class="value" id="pm-rcpt-no" style="margin-left:10px;">__________</span></div>
                        </div>
                        <div class="row">
                            <div class="col"><span class="label">AGENCY</span><span class="value" id="pm-rcpt-agency" style="margin-left:10px;">BPLO / TREASURY</span></div>
                            <div class="col"><span class="label">FUND</span><span class="value" id="pm-rcpt-fund" style="margin-left:10px;">GENERAL FUND</span></div>
                        </div>
                        <div class="row"><div class="col"><span class="label">PAYOR</span><span class="value" id="pm-rcpt-payor" style="margin-left:10px;">__________</span></div></div>
                        <div class="row"><div class="col"><span class="label">BUSINESS</span><span class="value" id="pm-rcpt-business" style="margin-left:10px;">__________</span></div></div>
                        <div class="row"><div class="col"><span class="label">TXN ID</span><span class="value" id="pm-rcpt-txn" style="margin-left:10px;">__________</span></div></div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:60%;">NATURE OF COLLECTION</th>
                                        <th style="width:20%; text-align:center;">ACCOUNT CODE</th>
                                        <th style="width:20%; text-align:right;">AMOUNT</th>
                                    </tr>
                                </thead>
                                <tbody id="pm-rcpt-fee-rows"></tbody>
                                <tbody>
                                    <tr class="total-row">
                                        <td colspan="2" style="text-align:right; font-weight:bold;">TOTAL</td>
                                        <td style="text-align:right; font-weight:bold;">P <span id="pm-rcpt-total">0.00</span></td>
                                    </tr>
                                    <tr class="words-row"><td colspan="3"><span id="pm-rcpt-words" style="text-transform:uppercase;">ZERO PESOS ONLY</span></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="bottom-section">
                            <div class="payment-options">
                                <div><input type="checkbox"> CASH</div>
                                <div><input type="checkbox"> CHECK</div>
                                <div><input type="checkbox"> MONEY ORDER</div>
                                <div><input type="checkbox" checked> E-WALLET / ONLINE</div>
                            </div>
                            <div class="bank-details">
                                <table><thead><tr><th>DRAWEE BANK</th><th>NUMBER</th><th>DATE</th></tr></thead>
                                <tbody><tr><td></td><td></td><td></td></tr></tbody></table>
                            </div>
                        </div>
                        <div class="footer-text">Received the amount stated above.<br>Payment Method: <span id="pm-rcpt-source">—</span></div>
                        
                        <div class="note-text">NOTE: Write the number and date of this receipt on the back of check or money order received.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid var(--border);">
                <a href="#" id="pmReceiptViewApp" class="btn-outline-custom d-none" target="_blank"><i class="bi bi-box-arrow-up-right"></i> View Application</a>
                <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn-blue" id="pmPrintReceiptBtn"><i class="bi bi-printer"></i> Print Receipt</button>
            </div>
        </div>
    </div>
</div>

<!-- ACCOUNT SETTINGS MODAL -->
<div class="modal fade" id="accountSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--navy-dark); color: #fff; border-bottom: none;">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-gear me-2"></i>Account Settings</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="accountSettingsForm">
                <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                    <div id="accountSettingsAlert" class="alert d-none" role="alert"></div>
                    <h6 class="fw-bold mb-3" style="color: var(--navy-dark);"><i class="bi bi-person-circle me-1"></i> Personal Information</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-uppercase">First Name</label>
                            <input type="text" name="firstname" id="asFirstname" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-uppercase">Last Name</label>
                            <input type="text" name="lastname" id="asLastname" class="form-control" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold small text-uppercase">Email Address</label>
                        <input type="email" id="asEmail" class="form-control" disabled>
                        <small class="text-muted">Contact admin to change your email.</small>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold small text-uppercase">Contact Number</label>
                        <div class="input-group">
                            <span class="input-group-text">+63</span>
                            <input type="tel" name="contact" id="asContact" class="form-control" placeholder="912 345 6789" pattern="[0-9 ]*">
                        </div>
                    </div>
                    <hr class="my-4">
                    <h6 class="fw-bold mb-3" style="color: var(--navy-dark);"><i class="bi bi-shield-lock me-1"></i> Change Password <span class="text-muted fw-normal small">(optional)</span></h6>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small text-uppercase">Current Password</label>
                            <input type="password" name="current_password" id="asCurrentPassword" class="form-control" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-uppercase">New Password</label>
                            <input type="password" name="new_password" id="asNewPassword" class="form-control" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-uppercase">Confirm New Password</label>
                            <input type="password" name="confirm_password" id="asConfirmPassword" class="form-control" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border);">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-blue" id="accountSettingsSubmitBtn"><i class="bi bi-check-lg"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ANNOUNCEMENT MODALS -->
<div class="modal fade" id="addAnnouncementModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Add Announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Content</label><textarea name="content" class="form-control" rows="4" required></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_announcement" class="btn-blue">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editAnnouncementModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit Announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="id" id="editStaffAnnId">
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" id="editStaffAnnTitle" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Content</label><textarea name="content" id="editStaffAnnContent" class="form-control" rows="4" required></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_announcement" class="btn-blue">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- UNIVERSAL STAT-CARD MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="statCardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width:1200px;">
        <div class="modal-content" style="border-radius:14px; overflow:hidden;">
            <div class="modal-header" style="background:var(--navy-dark); color:#fff; border-bottom:none;">
                <h5 class="modal-title fw-bold d-flex align-items-center flex-wrap gap-2">
                    <i class="bi bi-bar-chart-line-fill" id="statCardModalIcon"></i>
                    <span id="statCardModalTitle">Details</span>
                    <span class="badge" id="statCardModalCount" style="background:var(--blue-accent); color:#fff; font-size:.72rem;"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="background:#fff;">
                <div id="statCardModalEmpty" class="text-center py-5 d-none">
                    <i class="bi bi-inbox" style="font-size:2.5rem; color:#cbd5e1;"></i>
                    <p class="mt-3 text-muted mb-0">No records match this filter.</p>
                </div>
                <div class="table-responsive" id="statCardModalTableWrap" style="display:none;">
                    <table class="custom-table" id="statCardModalTable" style="margin:0;">
                        <thead id="statCardModalThead"></thead>
                        <tbody id="statCardModalTbody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);">
                <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- FEE ASSESSMENT MODAL (embeds payment.php)                    -->
<!-- ============================================================ -->
<div class="modal fade" id="feeAssessmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered"
         style="max-width: 1100px; height: 92vh;">
        <div class="modal-content" style="height: 100%;">

            <div class="modal-header"
                 style="background: var(--navy-dark); color: #fff; padding: .9rem 1.2rem;">
                <h5 class="modal-title d-flex align-items-center gap-2 flex-wrap"
                    style="font-size: 1rem; font-weight: 700;">
                    <i class="bi bi-calculator-fill"></i>
                    <span>Fee Assessment</span>
                    <small id="feeAssessRefLabel">—</small>
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0" style="position: relative;">
                <div id="feeAssessLoader"
                     style="height: 100%; position: absolute; top:0; left:0; right:0;
                            background: #fff; z-index: 2;
                            display: flex; align-items: center; justify-content: center;">
                    <div class="text-center">
                        <div class="spinner-border" style="color: var(--navy-dark);"></div>
                        <p class="mt-2 text-muted small mb-0">Loading assessment form…</p>
                    </div>
                </div>
                <iframe id="feeAssessFrame" src="about:blank"
                        style="width: 100%; height: 100%; border: 0;"></iframe>
            </div>

            <div class="modal-footer"
                 style="border-top: 1px solid var(--border); padding: .75rem 1rem; gap: 8px;">
                <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Close
                </button>
            </div>

        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- TREASURY RECORD MODAL (embeds treasury_record.php)           -->
<!-- ============================================================ -->
<div class="modal fade" id="viewTreasuryRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered"
         style="max-width: 1100px; height: 92vh;">
        <div class="modal-content" style="height: 100%;">

            <div class="modal-header"
                 style="background: var(--navy-dark); color: #fff; padding: .9rem 1.2rem;">
                <h5 class="modal-title d-flex align-items-center gap-2 flex-wrap"
                    style="font-size: 1rem; font-weight: 700;">
                    <i class="bi bi-file-earmark-text-fill"></i>
                    <span>Treasury Record</span>
                    <small id="treasRecRefLabel">—</small>
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0" style="position: relative;">
                <div id="treasRecLoader"
                     style="height: 100%; position: absolute; top:0; left:0; right:0;
                            background: #fff; z-index: 2;
                            display: flex; align-items: center; justify-content: center;">
                    <div class="text-center">
                        <div class="spinner-border" style="color: var(--navy-dark);"></div>
                        <p class="mt-2 text-muted small mb-0">Loading treasury record…</p>
                    </div>
                </div>
                <iframe id="treasRecFrame" src="about:blank"
                        style="width: 100%; height: 100%; border: 0;"></iframe>
            </div>

            <div class="modal-footer"
                 style="border-top: 1px solid var(--border); padding: .75rem 1rem; gap: 8px;">
                <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Close
                </button>
            </div>

        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- VIEW APPLICATION MODAL (embeds view_application.php)         -->
<!-- ============================================================ -->
<div class="modal fade" id="viewApplicationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered"
         style="max-width: 1100px; height: 92vh;">
        <div class="modal-content" style="height: 100%;">

            <div class="modal-header"
                 style="background: var(--navy-dark); color: #fff; padding: .9rem 1.2rem;">
                <h5 class="modal-title d-flex align-items-center gap-2 flex-wrap"
                    style="font-size: 1rem; font-weight: 700;">
                    <i class="bi bi-file-earmark-text-fill"></i>
                    <span>Application Details</span>
                    <small id="viewAppRefLabel">—</small>
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
                <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">
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
// Section switching
const sections = ['dashboard'];
<?php if ($showFeeAssessment): ?>sections.push('fee-assessment');<?php endif; ?>
<?php if ($showPaymentVerification): ?>sections.push('payment-verification');<?php endif; ?>
<?php if ($showPayments): ?>sections.push('payments');<?php endif; ?>
<?php if ($showTreasuryRecords): ?>sections.push('treasury-records');<?php endif; ?>
<?php if ($showActivityLog): ?>sections.push('activity-log');<?php endif; ?>
<?php if ($showAnnouncements): ?>sections.push('announcements');<?php endif; ?>

function showSection(id) {
    sections.forEach(s => { const el = document.getElementById(s + '-section'); if (el) el.style.display = 'none'; });
    const active = document.getElementById(id + '-section');
    if (active) active.style.display = 'block';
    document.querySelectorAll('.sidebar .nav-link, .offcanvas .nav-link').forEach(l => {
        l.classList.toggle('active', l.dataset.section === id);
    });
    const off = document.getElementById('sidebarOffcanvas');
    if (off && off.classList.contains('show')) {
        const inst = bootstrap.Offcanvas.getInstance(off);
        if (inst) inst.hide();
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
document.querySelectorAll('.sidebar .nav-link, .offcanvas .nav-link').forEach(l => {
    l.addEventListener('click', e => {
        e.preventDefault();
        if (l.dataset.section && sections.includes(l.dataset.section)) showSection(l.dataset.section);
    });
});
showSection('dashboard');

// Fee search
document.getElementById('feeSearchInput')?.addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#feeAssessmentTable tbody tr').forEach(tr => {
        if (tr.querySelector('td[colspan]')) return;
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Payment verification filter
function filterPaymentVerification() {
    const q = document.getElementById('pvSearchInput').value.toLowerCase().trim();

    document.querySelectorAll('#paymentVerificationTable tbody tr').forEach(tr => {
        if (tr.querySelector('td[colspan]')) return;

        tr.style.display = (!q || tr.innerText.toLowerCase().includes(q))
            ? ''
            : 'none';
    });
}

document.getElementById('pvSearchInput')
    ?.addEventListener('input', filterPaymentVerification);

// Edit announcement
document.querySelectorAll('.edit-staff-announcement').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('editStaffAnnId').value = this.dataset.id;
        document.getElementById('editStaffAnnTitle').value = this.dataset.title;
        document.getElementById('editStaffAnnContent').value = this.dataset.content;
    });
});


// DataTables
$(document).ready(function () {

    if ($('#feeAssessmentTable tbody tr').length > 0 &&
        !$('#feeAssessmentTable tbody td[colspan]').length) {

        $('#feeAssessmentTable').DataTable({
            order: [[4, 'desc'], [0, 'asc']],
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: [5] }],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_",
                info: "_START_-_END_ of _TOTAL_"
            }
        });

        $('.dataTables_filter').hide();
    }

    // Payment Verification Table (Action column removed)
    if ($('#paymentVerificationTable tbody tr').length > 0 &&
        !$('#paymentVerificationTable tbody td[colspan]').length) {

        $('#paymentVerificationTable').DataTable({
            order: [],
            pageLength: 10,
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_",
                info: "_START_-_END_ of _TOTAL_"
            }
        });

        $('.dataTables_filter').hide();
    }

    if ($('#paymentsTable tbody tr').length > 0) {
        $('#paymentsTable').DataTable({
            order: [[6, 'desc']],
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: [6] }],
            language: {
                lengthMenu: "Show _MENU_",
                info: "_START_-_END_ of _TOTAL_"
            }
        });
    }

    if ($('#treasuryRecordsTable tbody tr').length > 0) {
        $('#treasuryRecordsTable').DataTable({
            order: [[4, 'desc'], [0, 'desc']],
            pageLength: 25,
            columnDefs: [{ orderable: false, targets: [8] }]
        });
    }

    if ($('#staffAnnouncementsTable tbody tr').length > 0) {
        $('#staffAnnouncementsTable').DataTable({
            order: [[3, 'desc']],
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: [4] }]
        });
    }

});


// ============================================================
// TREASURY ACTIVITY LOG — table + filter
// ============================================================
let treasuryActivityTable = null;
$(document).ready(function () {
    if (document.querySelectorAll('#treasuryActivityLogTable tbody tr[data-actor-role]').length > 0) {
        treasuryActivityTable = $('#treasuryActivityLogTable').DataTable({
            pageLength: 25,
            order: [[1, 'desc']],
            drawCallback: function () {
                // Renumber # so it always runs 1,2,3... in the order currently shown
                var api = this.api();
                var start = api.page.info().start;
                api.column(0, { page: 'current' }).nodes().each(function (cell, i) {
                    cell.innerHTML = start + i + 1;
                });
            },
            language: {
                search: 'Search log:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ records',
                infoEmpty: 'No records available',
                emptyTable: 'No activity logged yet'
            },
            columnDefs: [{ orderable: false, targets: [0, 2, 8] }]
        });
    }
});

document.querySelectorAll('#treasuryActivityFilterBtns button').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#treasuryActivityFilterBtns button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const role = this.dataset.role;
        if (!treasuryActivityTable) return;
        $.fn.dataTable.ext.search.pop();
        if (role === 'all') {
            treasuryActivityTable.draw();
        } else if (role === 'payment') {
            $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
                if (settings.nTable.id !== 'treasuryActivityLogTable') return true;
                const row = treasuryActivityTable.row(dataIndex).node();
                return row.getAttribute('data-event-type') === 'payment';
            });
            treasuryActivityTable.draw();
        } else if (role === '0') {
            $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
                if (settings.nTable.id !== 'treasuryActivityLogTable') return true;
                const row = treasuryActivityTable.row(dataIndex).node();
                const t = row.getAttribute('data-actor-role');
                const et = row.getAttribute('data-event-type');
                return et === 'payment' || !t || t === '0' || t === '';
            });
            treasuryActivityTable.draw();
        } else {
            $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
                if (settings.nTable.id !== 'treasuryActivityLogTable') return true;
                const row = treasuryActivityTable.row(dataIndex).node();
                return row.getAttribute('data-actor-role') === role;
            });
            treasuryActivityTable.draw();
        }
    });
});

// ============================================================
// PAYMENT CHART
// ============================================================
<?php if ($showPayments): ?>
(function () {
    const ctx = document.getElementById('paymentChart')?.getContext('2d');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($monthlyLabels); ?>,
            datasets: [{
                label: 'Collections (₱)',
                data: <?php echo json_encode($monthlyCollections); ?>,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,0.08)',
                fill: true, tension: 0.35,
                pointBackgroundColor: '#2563eb',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { font: { size: 11 }, color: '#64748b' } } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { color: '#64748b', font: { size: 10 }, callback: v => '₱' + Number(v).toLocaleString('en-PH') }
                },
                x: { ticks: { color: '#64748b', font: { size: 10 }, maxRotation: 45, minRotation: 0 }, grid: { display: false } }
            }
        }
    });
})();
<?php endif; ?>

// ============================================================
// PAYPAL RECEIPT
// ============================================================
function pmNumberToWords(num) {
    const a = ['','ONE ','TWO ','THREE ','FOUR ','FIVE ','SIX ','SEVEN ','EIGHT ','NINE ',
               'TEN ','ELEVEN ','TWELVE ','THIRTEEN ','FOURTEEN ','FIFTEEN ','SIXTEEN ',
               'SEVENTEEN ','EIGHTEEN ','NINETEEN '];
    const b = ['','','TWENTY ','THIRTY ','FORTY ','FIFTY ','SIXTY ','SEVENTY ','EIGHTY ','NINETY '];
    let n = Math.floor(num);
    const cents = Math.round((num - n) * 100);
    let str = '';
    if (n === 0) str = 'ZERO ';
    else {
        if (n >= 1000000) { str += pmNumberToWords(Math.floor(n / 1000000)).replace(' PESOS ONLY','') + ' MILLION '; n %= 1000000; }
        if (n >= 1000)    { str += pmNumberToWords(Math.floor(n / 1000)).replace(' PESOS ONLY','') + ' THOUSAND '; n %= 1000; }
        if (n >= 100)     { str += a[Math.floor(n / 100)] + 'HUNDRED '; n %= 100; }
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

function pmEscapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

$(document).on('click', '.pp-receipt-btn', function () {
    const d = $(this).data();
    const fees = [
        { label: 'GROSS SALES TAX',           value: parseFloat(d.gst) || 0 },
        { label: "MAYOR'S PERMIT FEE",        value: parseFloat(d.mayor) || 0 },
        { label: 'PERMIT ON CALLING',         value: parseFloat(d.permitCalling) || 0 },
        { label: 'HEALTH CERTIFICATE',        value: parseFloat(d.healthCert) || 0 },
        { label: 'SANITARY INSPECTION FEE',   value: parseFloat(d.sanitary) || 0 },
        { label: 'SANITARY GARBAGE FEE',      value: parseFloat(d.garbage) || 0 },
        { label: 'WEIGHT & MEASURE',          value: parseFloat(d.weightMeasure) || 0 },
        { label: 'BUILDING INSPECTION FEE',   value: parseFloat(d.building) || 0 },
        { label: 'ELECTRICAL INSPECTION FEE', value: parseFloat(d.electrical) || 0 },
        { label: 'FIRE SAFETY FEE',           value: parseFloat(d.fire) || 0 },
        { label: 'MISC. FEE',                 value: parseFloat(d.misc) || 0 },
        { label: 'SURCHARGE FEE',             value: parseFloat(d.surcharge) || 0 },
        { label: 'INTEREST FEE',              value: parseFloat(d.interest) || 0 },
        { label: 'LATE PAYMENT',              value: parseFloat(d.latePayment) || 0 },
        { label: 'PENALTY (LOST BUSINESS PERMIT)', value: parseFloat(d.penaltyLost) || 0 },
        { label: 'OTHER FEES',                value: parseFloat(d.others) || 0 }
    ];
    const total = parseFloat(d.amount) || 0;
    let feeRowsHtml = '';
    let anyFee = false;
    fees.forEach(f => {
        if (f.value > 0) {
            anyFee = true;
            feeRowsHtml += `<tr><td style="font-weight:bold;">${pmEscapeHtml(f.label)}</td><td style="text-align:center;"></td><td style="text-align:right; font-weight:bold;">P ${f.value.toFixed(2)}</td></tr>`;
        }
    });
    if (!anyFee) {
        feeRowsHtml = `<tr><td style="font-weight:bold;">BUSINESS PERMIT FEE</td><td style="text-align:center;"></td><td style="text-align:right; font-weight:bold;">P ${total.toFixed(2)}</td></tr>`;
    }
    let orDate = new Date();
    if (d.paidAt) {
        const ts = parseInt(d.paidAt, 10);
        if (!isNaN(ts)) { orDate = new Date(ts > 9999999999 ? ts : ts * 1000); }
    }
    const dateStr = orDate.toLocaleDateString('en-US', { month:'2-digit', day:'2-digit', year:'numeric' }).replace(/\//g, '');
    const orNo = 'OR-PP-' + orDate.getFullYear() + '-' + String(d.appId || 0).padStart(6, '0');
    $('#pm-rcpt-fee-rows').html(feeRowsHtml);
    $('#pm-rcpt-date').text(dateStr);
    $('#pm-rcpt-no').text(orNo);
    $('#pm-rcpt-payor').text(d.owner || d.business || '—');
    $('#pm-rcpt-business').text(d.business || '—');
    $('#pm-rcpt-txn').text(d.txn || '—');
    $('#pm-rcpt-source').text(d.source || 'PayPal');
    $('#pm-rcpt-total').text(total.toFixed(2));
    $('#pm-rcpt-words').text(pmNumberToWords(total));
    if (d.appId) { $('#pmReceiptViewApp').removeClass('d-none').attr('href', 'view_application.php?id=' + d.appId); }
    else { $('#pmReceiptViewApp').addClass('d-none').attr('href', '#'); }
    new bootstrap.Modal(document.getElementById('pmReceiptModal')).show();
});

document.getElementById('pmPrintReceiptBtn')?.addEventListener('click', function () {
    const content = document.getElementById('pmReceiptContent');
    if (!content) return;
    const w = window.open('', '_blank', 'width=850,height=950');
    if (!w) { alert('Please allow pop-ups to print the receipt.'); return; }
    const html = content.innerHTML;
    w.document.write(`<!DOCTYPE html><html><head><title>Official Receipt</title><style>
        body{font-family:'Courier New',monospace;padding:20px;background:#fff;}
        .official-receipt-template{width:100%;max-width:750px;margin:0 auto;border:2px solid #000;padding:20px;}
        .header{display:flex;align-items:center;border-bottom:2px solid #000;padding-bottom:15px;margin-bottom:15px;}
        .header-logo{width:80px;margin-right:20px;text-align:center;}
        .header-text{flex:1;text-align:center;padding-right:80px;}
        .header-text h2{font-size:24px;font-weight:bold;margin:0;font-family:'Times New Roman',serif;}
        .header-text p{font-size:14px;margin:5px 0 0 0;font-family:'Times New Roman',serif;}
        .form-no{text-align:center;font-size:12px;margin-bottom:10px;border-bottom:1px solid #000;padding-bottom:5px;}
        .row{display:flex;border-bottom:1px solid #000;}
        .col{flex:1;padding:5px 10px;border-right:1px solid #000;font-size:14px;display:flex;align-items:center;}
        .col:last-child{border-right:none;}.col.label{font-weight:bold;min-width:90px;border-right:none;}
        .table-container{border:1px solid #000;margin-top:15px;}
        table{width:100%;border-collapse:collapse;font-size:14px;}
        th{border:1px solid #000;padding:8px;text-align:left;font-weight:normal;font-size:13px;}
        td{border:1px solid #000;padding:5px 8px;height:25px;}
        .total-row td{border-top:2px solid #000;font-weight:bold;}
        .words-row td{font-weight:bold;text-align:center;font-size:13px;border-top:1px solid #000;padding:10px;}
        .bottom-section{display:flex;margin-top:15px;border:1px solid #000;}
        .payment-options{flex:1;padding:10px;border-right:1px solid #000;}
        .payment-options div{margin-bottom:15px;display:flex;align-items:center;font-size:14px;}
        .bank-details{flex:2;}
        .signature-block{display:flex;justify-content:flex-end;margin-top:30px;}
        .signature-line{width:250px;border-top:1px solid #000;text-align:center;padding-top:5px;font-weight:bold;font-size:14px;}
        .note-text{font-size:9px;text-align:center;margin-top:20px;color:#333;}
        @media print{body{padding:0;}.official-receipt-template{border:none;margin:0;}}
    </style></head><body>${html}<script>window.onload=function(){window.print()}<\/script></body></html>`);
    w.document.close();
});

// ============================================================
// ACCOUNT SETTINGS MODAL
// ============================================================
document.getElementById('openAccountSettings')?.addEventListener('click', function (e) {
    e.preventDefault();
    const form = document.getElementById('accountSettingsForm');
    form.reset();
    const alertEl = document.getElementById('accountSettingsAlert');
    alertEl.classList.add('d-none');
    alertEl.className = 'alert d-none';
    fetch('account_settings_fetch.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('asFirstname').value = data.firstname || '';
                document.getElementById('asLastname').value  = data.lastname  || '';
                document.getElementById('asEmail').value     = data.email     || '';
                document.getElementById('asContact').value   = data.contact_number || '';
                new bootstrap.Modal(document.getElementById('accountSettingsModal')).show();
            } else { Swal.fire('Error', data.error || 'Unable to load account data.', 'error'); }
        })
        .catch(() => Swal.fire('Error', 'Network error while loading account data.', 'error'));
});

document.getElementById('accountSettingsForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = document.getElementById('accountSettingsSubmitBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
    const formData = new FormData(this);
    fetch('account_settings_ajax.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        const alertEl = document.getElementById('accountSettingsAlert');
        alertEl.classList.remove('d-none');
        if (data.success) {
            alertEl.className = 'alert alert-success';
            alertEl.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>' + data.message;
            const greeting = document.querySelector('.user-greeting');
            if (greeting && data.name) { greeting.innerHTML = '<i class="bi bi-person-circle"></i> <span>' + data.name + '</span>'; }
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('accountSettingsModal'));
                if (modal) modal.hide();
                document.getElementById('asCurrentPassword').value = '';
                document.getElementById('asNewPassword').value = '';
                document.getElementById('asConfirmPassword').value = '';
            }, 1400);
        } else {
            alertEl.className = 'alert alert-danger';
            alertEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>' + (data.error || 'Update failed.');
        }
    })
    .catch(() => {
        const alertEl = document.getElementById('accountSettingsAlert');
        alertEl.classList.remove('d-none');
        alertEl.className = 'alert alert-danger';
        alertEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>Network error. Please try again.';
    })
    .finally(() => { btn.disabled = false; btn.innerHTML = originalText; });
});

if (window.location.hash === '#account-settings') {
    setTimeout(() => { document.getElementById('openAccountSettings')?.click(); }, 400);
}

// ============================================================
// UNIVERSAL STAT-CARD MODAL
// ============================================================
function openStatCardModal(opts) {
    const {
        title, icon = 'bi-bar-chart-line-fill', headers = [], rows = [],
        section = null, sectionFilter = null,
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

    // This footer link is optional. Guard it because the current modal markup omits it.
    if (linkEl) {
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
                            '#treasuryActivityFilterBtns button[data-role="' + sectionFilter + '"]'
                        );
                        if (btn) btn.click();
                    }, 150);
                }
            };
        } else {
            linkEl.style.display = 'none';
            linkEl.onclick = null;
        }
    }

    const modalEl = document.getElementById('statCardModal');
    if (modalEl && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function bindStatCard(selector, handler) {
    document.querySelectorAll(selector).forEach(card => {
        card.addEventListener('click', handler);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handler.call(card); }
        });
    });
}

function dashStatusPill(st) {
    st = (st || '').toLowerCase();
    const map = {
        'submitted': 'badge-warning', 'under_review': 'badge-info',
        'pending_payment': 'badge-warning', 'payment_confirmed': 'badge-info',
        'approved': 'badge-approved', 'issued': 'badge-approved', 'verified': 'badge-approved',
        'rejected': 'badge-rejected', 'cancelled': 'badge-secondary',
        'completed': 'badge-paid', 'paid': 'badge-paid'
    };
    const cls = map[st] || 'badge-secondary';
    return '<span class="badge-status ' + cls + '">' + st.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase()) + '</span>';
}

// ── DASHBOARD CARDS ──
const treasuryDashData = <?php echo json_encode($dashboardCardData, JSON_UNESCAPED_UNICODE); ?>;

function treasuryDashRows(arr) {
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
            '<a href="view_application.php?id=' + a.application_id + '" class="btn-outline-custom btn-sm" target="_blank" title="View"><i class="bi bi-eye"></i></a>'
        ];
    });
}

bindStatCard('.stat-card-clickable[data-tre-card]', function () {
    const kind = this.dataset.treCard;
    const data = treasuryDashData[kind] || [];
    const titles = {
        'total':            { title: 'All applications',     icon: 'bi-people-fill' },
        'for_assessment':   { title: 'For assessment',        icon: 'bi-file-earmark-text' },
        'pending_payment':  { title: 'Pending payment',       icon: 'bi-wallet2' },
        'approved_issued':  { title: 'Approved or issued',    icon: 'bi-patch-check' }
    };
    const t = titles[kind] || { title: 'Applications', icon: 'bi-list-ul' };
    openStatCardModal({
        title: t.title, icon: t.icon,
        headers: ['Business', 'Reference', 'Type', 'Date filed', 'Amount', 'Status', 'Action'],
        rows: treasuryDashRows(data),
        section: 'dashboard'
    });
});

// ── TREASURY RECORDS CARDS ──
<?php if ($showTreasuryRecords): ?>
const treasuryRecordsData = <?php echo json_encode($treasuryRecords, JSON_UNESCAPED_UNICODE); ?>;

function treRecordsRows(arr) {
    return arr.map(r => {
        const amt = '₱' + Number(r.amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const dateStr = r.application_date ? new Date(r.application_date).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : '—';
        return [
            '<div class="fw-semibold">' + (r.business_name || 'N/A') + '</div>',
            '<code class="small text-primary">' + (r.reference_no || '—') + '</code>',
            (r.application_type || 'N/A'),
            dateStr,
            '<strong>' + amt + '</strong>',
            (r.tax_year || '—'),
            dashStatusPill(r.status),
            '<a href="treasury_record.php?id=' + r.application_id + '" class="btn-outline-custom btn-sm" target="_blank"><i class="bi bi-eye"></i></a>'
        ];
    });
}

bindStatCard('.stat-card-clickable[data-tre-rec-card]', function () {
    const kind = this.dataset.treRecCard;
    let data = treasuryRecordsData.slice();
    if (kind === 'year') {
        const yr = new Date().getFullYear();
        data = data.filter(r => r.application_date && new Date(r.application_date).getFullYear() === yr);
    }
    const titles = {
        'total':  { title: 'All treasury records',        icon: 'bi-folder' },
        'amount': { title: 'Treasury records (all)',       icon: 'bi-cash-stack' },
        'year':   { title: 'Records filed this year',      icon: 'bi-calendar3' }
    };
    const t = titles[kind] || { title: 'Treasury Records', icon: 'bi-folder' };
    openStatCardModal({
        title: t.title, icon: t.icon,
        headers: ['Business', 'Reference', 'Type', 'Date filed', 'Amount', 'Tax year', 'Status', 'Action'],
        rows: treRecordsRows(data),
        section: 'treasury-records'
    });
});
<?php endif; ?>

// ── ACTIVITY LOG CARDS ──
<?php if ($showActivityLog): ?>
const treasuryActivityData = <?php echo json_encode($activityLog, JSON_UNESCAPED_UNICODE); ?>;
const TRE_ACT_TODAY = '<?php echo date('Y-m-d'); ?>';
const TRE_ACT_WEEK_AGO = '<?php echo date('Y-m-d', strtotime('-7 days')); ?>';

function filterTreasuryActivityCards(kind) {
    const oneWeekAgo = new Date(TRE_ACT_WEEK_AGO + 'T00:00:00');
    return treasuryActivityData.filter(log => {
        const when = log.event_time ? new Date(log.event_time.replace(' ', 'T')) : new Date();
        const dayStr = when.toISOString().slice(0, 10);
        const et = log.event_type || '';
        const ns = (log.new_status || '').toLowerCase();
        const at = parseInt(log.actor_type || 0, 10);

        switch (kind) {
            case 'total':      return true;
            case 'today':      return dayStr === TRE_ACT_TODAY;
            case 'payments':   return et === 'payment';
            case 'reject':     return ns === 'rejected';
            case 'bplo':       return at === 4;
            case 'treasury':   return at === 3;
            case 'admin':      return at === 5;
            case 'system':     return (!at || at === 0) && et !== 'payment';
            default:           return true;
        }
    });
}

function treActEventBadge(et) {
    const map = {
        'status_change':         ['bi-arrow-repeat', 'Status change', '#6366f1'],
        'fee_assessment':        ['bi-cash-coin', 'Fee assessment', '#f59e0b'],
        'rejection':             ['bi-file-earmark-x', 'Rejection', '#ef4444'],
        'document_rejection':    ['bi-file-earmark-x', 'Document rejection', '#ef4444'],
        'payment':               ['bi-paypal', 'PayPal payment', '#0070ba'],
        'appointment_created':   ['bi-calendar-plus', 'Appointment booked', '#8b5cf6'],
        'appointment_confirmed': ['bi-calendar-check', 'Appointment confirmed', '#3b82f6'],
        'appointment_completed': ['bi-calendar-check-fill', 'Appointment completed', '#10b981'],
        'appointment_cancelled': ['bi-calendar-x', 'Appointment cancelled', '#ef4444'],
        'document_upload':       ['bi-cloud-upload', 'Document uploaded', '#06b6d4'],
        'application_submitted': ['bi-file-earmark-plus', 'Application submitted', '#10b981'],
        'announcement_pending':  ['bi-megaphone', 'Announcement pending', '#f59e0b'],
        'announcement_approved': ['bi-megaphone-fill', 'Announcement approved', '#10b981'],
        'announcement_rejected': ['bi-megaphone', 'Announcement rejected', '#ef4444']
    };
    const [icon, label, color] = map[et] || ['bi-arrow-repeat', 'Status change', '#6366f1'];
    return '<span style="display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .6rem;border-radius:2rem;font-size:.72rem;font-weight:600;background:' + color + '22;color:' + color + ';">' +
           '<i class="bi ' + icon + '"></i> ' + label + '</span>';
}

function treActRows(arr) {
    return arr.map(log => {
        const when = log.event_time ? new Date(log.event_time.replace(' ', 'T')) : new Date();
        const dateStr = when.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
        const timeStr = when.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
        return [
            '<div class="fw-semibold">' + dateStr + '</div>' +
            '<small class="text-muted"><i class="bi bi-clock"></i> ' + timeStr + '</small>',
            treActEventBadge(log.event_type || 'status_change'),
            log.reference_no ? '<code class="small">' + log.reference_no + '</code>' : '<span class="text-muted">—</span>',
            '<div class="fw-semibold">' + (log.business_name || '—') + '</div>' +
            '<small class="text-muted"><i class="bi bi-person"></i> ' + (log.owner_name || 'Unknown') + '</small>',
            log.old_status ? dashStatusPill(log.old_status) : '<span class="text-muted">—</span>',
            dashStatusPill(log.new_status),
            '<div class="fw-semibold">' + (log.actor_name || 'System') + '</div>',
            '<small class="text-muted">' + (log.notes || '—') + '</small>'
        ];
    });
}

bindStatCard('.stat-card-clickable[data-tre-act-card]', function () {
    const kind = this.dataset.treActCard;
    const data = filterTreasuryActivityCards(kind);
    const titles = {
        'total':      { title: 'All activities',           icon: 'bi-list-ul' },
        'today':      { title: "Today's activities",       icon: 'bi-calendar-check' },
        'payments':   { title: 'PayPal payments',          icon: 'bi-paypal' },
        'reject':     { title: 'Rejections',               icon: 'bi-x-circle' },
        'bplo':       { title: 'BPLO actions',             icon: 'bi-file-earmark-check' },
        'treasury':   { title: 'Treasury actions',         icon: 'bi-cash-stack' },
        'admin':      { title: 'Admin actions',            icon: 'bi-shield-lock' },
        'system':     { title: 'System / other',           icon: 'bi-gear' }
    };
    const t = titles[kind] || { title: 'Activity Log', icon: 'bi-clock-history' };

    const sectionFilterMap = {
        'payments': 'payment',
        'bplo':     '4',
        'treasury': '3',
        'admin':    '5'
    };

    openStatCardModal({
        title: t.title, icon: t.icon,
        headers: ['Date & time', 'Event', 'Reference', 'Business / owner', 'Previous', 'New status', 'By', 'Notes'],
        rows: treActRows(data),
        section: 'activity-log',
        sectionFilter: sectionFilterMap[kind] || null
    });
});
<?php endif; ?>

// ============================================================
// FEE ASSESSMENT IN MODAL (embeds payment.php)
// ============================================================
(function initFeeAssessmentModal() {
    'use strict';

    const modalEl = document.getElementById('feeAssessmentModal');
    if (!modalEl) return;

    const frame       = document.getElementById('feeAssessFrame');
    const loader      = document.getElementById('feeAssessLoader');
    const refLabel    = document.getElementById('feeAssessRefLabel');
    const openTabLink = document.getElementById('feeAssessOpenTab');

    let bsModal = null;
    try { bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); }
    catch (e) { console.error('[FAM] bootstrap failed:', e); return; }

    let pollTimer     = null;
    let hardStopTimer = null;

    function stopTimers() {
        if (pollTimer)     { clearInterval(pollTimer);     pollTimer     = null; }
        if (hardStopTimer) { clearTimeout(hardStopTimer);  hardStopTimer = null; }
    }

    function showLoader() {
        if (!loader) return;
        loader.innerHTML =
            '<div class="text-center">' +
                '<div class="spinner-border" style="color: var(--navy-dark);"></div>' +
                '<p class="mt-2 text-muted small mb-0">Loading assessment form…</p>' +
            '</div>';
        loader.style.setProperty('display', 'flex', 'important');
    }

    function hideLoader() {
        if (loader) loader.style.setProperty('display', 'none', 'important');
        stopTimers();
    }

    function isIframeReady() {
        try {
            const win = frame.contentWindow;
            if (!win) return false;
            const href = String(win.location.href || '');
            if (!href || href === 'about:blank' || href === 'about:srcdoc') return false;
            const doc = win.document;
            if (!doc || !doc.body) return false;
            const rs = doc.readyState;
            if (rs !== 'interactive' && rs !== 'complete') return false;
            if (doc.body.innerHTML.length < 200) return false;
            return true;
        } catch (e) { return true; }
    }

    function startPolling() {
        stopTimers();
        pollTimer = setInterval(function () {
            if (!isIframeReady()) return;
            hideLoader();

            // Detect a successful submission: payment.php renders
            // a `.alert-success` block after POST. When present:
            //  • wait briefly so the user can read it
            //  • close the modal
            //  • reload the parent so the assessment table refreshes
            try {
                const doc = frame.contentWindow.document;
                const success = doc.querySelector('.alert-success');
                if (success && !success.dataset.vamHandled) {
                    success.dataset.vamHandled = '1';
                    setTimeout(function () {
                        try { bsModal.hide(); } catch (e) {}
                        setTimeout(function () { window.location.reload(); }, 200);
                    }, 1500);
                }
            } catch (e) { /* cross-origin */ }
        }, 400);
        hardStopTimer = setTimeout(hideLoader, 12000);
    }

    frame.addEventListener('load', function () {
        setTimeout(function () {
            if (isIframeReady()) hideLoader();
        }, 150);
    });

    function openFeeAssessment(ref, overrideMeta) {
        if (!ref) return;
        const meta     = overrideMeta || {};
        const business = meta.business || '';

        if (refLabel) refLabel.textContent = business ? (ref + ' · ' + business) : ref;

        const appUrl = 'payment.php?ref=' + encodeURIComponent(ref);
        if (openTabLink) openTabLink.href = appUrl;

        stopTimers();
        showLoader();

        try { frame.src = 'about:blank'; } catch (e) {}

        bsModal.show();

        setTimeout(function () {
            frame.src = appUrl + '&_t=' + Date.now();
            startPolling();
        }, 300);
    }

    window.openFeeAssessmentModal = openFeeAssessment;

    modalEl.addEventListener('hidden.bs.modal', function () {
        stopTimers();
        if (modalEl.contains(document.activeElement)) {
            try { document.activeElement.blur(); } catch (e) {}
        }
    });

    // ---- Global click interception for payment.php links ----
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href*="payment.php?ref="]');
        if (!link) return;
        if (link.classList.contains('allow-new-tab')) return;
        if (link.hasAttribute('data-force-new-tab')) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;

        const href = link.getAttribute('href') || '';
        let ref = '';
        try {
            const url = new URL(href, window.location.href);
            ref = url.searchParams.get('ref') || '';
        } catch (_) {
            const m = href.match(/[?&]ref=([^&]+)/);
            ref = m ? decodeURIComponent(m[1]) : '';
        }
        if (!ref) return;

        e.preventDefault();

        openFeeAssessment(ref, {
            business: link.dataset.business || ''
        });
    });
})();

// ============================================================
// TREASURY RECORD IN MODAL (embeds treasury_record.php)
// ============================================================
(function initTreasuryRecordModal() {
    'use strict';

    const modalEl = document.getElementById('viewTreasuryRecordModal');
    if (!modalEl) return;

    const frame       = document.getElementById('treasRecFrame');
    const loader      = document.getElementById('treasRecLoader');
    const refLabel    = document.getElementById('treasRecRefLabel');
    const openTabLink = document.getElementById('treasRecOpenTab');

    let bsModal = null;
    try { bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); }
    catch (e) { console.error('[TRM] bootstrap failed:', e); return; }

    let pollTimer     = null;
    let hardStopTimer = null;

    function stopTimers() {
        if (pollTimer)     { clearInterval(pollTimer);     pollTimer     = null; }
        if (hardStopTimer) { clearTimeout(hardStopTimer);  hardStopTimer = null; }
    }

    function showLoader() {
        if (!loader) return;
        loader.innerHTML =
            '<div class="text-center">' +
                '<div class="spinner-border" style="color: var(--navy-dark);"></div>' +
                '<p class="mt-2 text-muted small mb-0">Loading treasury record…</p>' +
            '</div>';
        loader.style.setProperty('display', 'flex', 'important');
    }

    function hideLoader() {
        if (loader) loader.style.setProperty('display', 'none', 'important');
        stopTimers();
    }

    function isIframeReady() {
        try {
            const win = frame.contentWindow;
            if (!win) return false;
            const href = String(win.location.href || '');
            if (!href || href === 'about:blank' || href === 'about:srcdoc') return false;
            const doc = win.document;
            if (!doc || !doc.body) return false;
            const rs = doc.readyState;
            if (rs !== 'interactive' && rs !== 'complete') return false;
            if (doc.body.innerHTML.length < 200) return false;
            return true;
        } catch (e) { return true; }
    }

    function startPolling() {
        stopTimers();
        pollTimer = setInterval(function () {
            if (isIframeReady()) hideLoader();
        }, 200);
        hardStopTimer = setTimeout(hideLoader, 10000);
    }

    frame.addEventListener('load', function () {
        setTimeout(function () {
            if (isIframeReady()) hideLoader();
        }, 150);
    });

    function openTreasuryRecord(appId, overrideMeta) {
        if (!appId) return;

        const meta     = overrideMeta || {};
        const business = meta.business || '';
        const ref      = meta.ref      || '';

        if (refLabel) {
            refLabel.textContent = ref
                ? (ref + (business ? ' · ' + business : ''))
                : (business || ('Record #' + appId));
        }

        const appUrl = 'treasury_record.php?id=' + encodeURIComponent(appId);
        if (openTabLink) openTabLink.href = appUrl;

        stopTimers();
        showLoader();

        try { frame.src = 'about:blank'; } catch (e) {}

        bsModal.show();

        setTimeout(function () {
            frame.src = appUrl + '&_t=' + Date.now();
            startPolling();
        }, 300);
    }

    window.openTreasuryRecordModal = openTreasuryRecord;

    modalEl.addEventListener('hidden.bs.modal', function () {
        stopTimers();
        if (modalEl.contains(document.activeElement)) {
            try { document.activeElement.blur(); } catch (e) {}
        }
    });

    // ---- Global click interception for treasury_record.php links ----
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href*="treasury_record.php?id="]');
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

        openTreasuryRecord(appId, {
            business: link.dataset.business || '',
            ref:      link.dataset.ref      || ''
        });
    });
})();

// ============================================================
// VIEW APPLICATION IN MODAL (embeds view_application.php)
// ============================================================
(function initViewApplicationModal() {
    'use strict';

    const modalEl = document.getElementById('viewApplicationModal');
    if (!modalEl) return;

    const frame    = document.getElementById('viewAppFrame');
    const loader   = document.getElementById('viewAppLoader');
    const refLabel = document.getElementById('viewAppRefLabel');

    let bsModal = null;
    try { bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); }
    catch (e) { console.error('[VAM] bootstrap failed:', e); return; }

    let pollTimer     = null;
    let hardStopTimer = null;

    function stopTimers() {
        if (pollTimer)     { clearInterval(pollTimer);     pollTimer     = null; }
        if (hardStopTimer) { clearTimeout(hardStopTimer);  hardStopTimer = null; }
    }

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
        stopTimers();
    }

    function isIframeReady() {
        try {
            const win = frame.contentWindow;
            if (!win) return false;
            const href = String(win.location.href || '');
            if (!href || href === 'about:blank' || href === 'about:srcdoc') return false;
            const doc = win.document;
            if (!doc || !doc.body) return false;
            const rs = doc.readyState;
            if (rs !== 'interactive' && rs !== 'complete') return false;
            if (doc.body.innerHTML.length < 200) return false;
            return true;
        } catch (e) { return true; }
    }

    function startPolling() {
        stopTimers();
        pollTimer = setInterval(function () {
            if (isIframeReady()) hideLoader();
        }, 200);
        hardStopTimer = setTimeout(hideLoader, 10000);
    }

    frame.addEventListener('load', function () {
        setTimeout(function () {
            if (isIframeReady()) hideLoader();
        }, 150);
    });

    function openApplication(appId, overrideMeta) {
        if (!appId) return;

        const meta     = overrideMeta || {};
        const business = meta.business || '';
        const ref      = meta.ref      || '';

        if (refLabel) {
            refLabel.textContent = ref
                ? (ref + (business ? ' · ' + business : ''))
                : (business || ('App #' + appId));
        }

        const appUrl = 'view_application.php?id=' + encodeURIComponent(appId);

        stopTimers();
        showLoader();

        try { frame.src = 'about:blank'; } catch (e) {}

        bsModal.show();

        setTimeout(function () {
            frame.src = appUrl + '&_t=' + Date.now();
            startPolling();
        }, 300);
    }

    window.openViewApplicationModal = openApplication;

    modalEl.addEventListener('hidden.bs.modal', function () {
        stopTimers();
        if (modalEl.contains(document.activeElement)) {
            try { document.activeElement.blur(); } catch (e) {}
        }
    });

    // ---- Global click interception for view_application.php?id= links ----
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href*="view_application.php?id="]');
        if (!link) return;

        if (link.classList.contains('allow-new-tab')) return;
        if (link.hasAttribute('data-force-new-tab')) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;

        // Skip links inside other open modals (e.g. PayPal receipt) so we
        // don't stack modals. Those keep their default new-tab behaviour.
        const parentModal = link.closest('.modal.show');
        if (parentModal && parentModal.id !== 'viewApplicationModal') return;

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

        openApplication(appId, {
            business: link.dataset.business || '',
            ref:      link.dataset.ref      || ''
        });
    });
})();
</script>
</body>
</html>