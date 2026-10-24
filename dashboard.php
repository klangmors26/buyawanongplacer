<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

require_once 'config/connect.php';
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

// --- Discard draft ---
if (isset($_GET['discard']) && $_GET['discard'] == 1) {
    $getId = $conn->prepare("SELECT id FROM business_applications WHERE user_id = ? AND status = 'draft' LIMIT 1");
    $getId->bind_param("i", $user_id);
    $getId->execute();
    $res = $getId->get_result();
    $draftId = null;
    if ($row = $res->fetch_assoc()) $draftId = $row['id'];
    $getId->close();

    if ($draftId) {
        $conn->query("DELETE FROM application_type_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM business_info_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM business_address_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM owner_info_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM additional_info_data WHERE application_id = $draftId");
        $conn->query("DELETE FROM document_uploads WHERE application_id = $draftId");
        $conn->query("DELETE FROM appointments WHERE application_id = $draftId");
        $conn->query("DELETE FROM business_applications WHERE id = $draftId");
    }
    unset($_SESSION['appointment_confirmed'], $_SESSION['appointment_date'], $_SESSION['appointment_time']);
    header('Location: dashboard.php');
    exit;
}

// ---------- CHECK DRAFT ----------
$draftQuery = "SELECT id, current_step FROM business_applications WHERE user_id = ? AND status = 'draft' LIMIT 1";
$stmt = $conn->prepare($draftQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$draftResult = $stmt->get_result();
$hasDraft = $draftResult->num_rows > 0;
$draftData = $hasDraft ? $draftResult->fetch_assoc() : null;
$stmt->close();

// ---------- STATS ----------
$stmt = $conn->prepare("SELECT COUNT(*) AS t FROM business_applications WHERE user_id = ? AND status != 'cancelled'");
$stmt->bind_param("i", $user_id); $stmt->execute();
$totalApps = $stmt->get_result()->fetch_assoc()['t']; $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM business_applications WHERE user_id = ? AND status = 'under_review'");
$stmt->bind_param("i", $user_id); $stmt->execute();
$pendingReview = $stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM business_applications WHERE user_id = ? AND status = 'pending_payment'");
$stmt->bind_param("i", $user_id); $stmt->execute();
$pendingPayment = $stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM business_applications WHERE user_id = ? AND status IN ('approved','claimed','verified')");
$stmt->bind_param("i", $user_id); $stmt->execute();
$completed = $stmt->get_result()->fetch_assoc()['c']; $stmt->close();

// ============================================================
// ✅ FEATURE GATE — "Renew a permit" and "Verify a permit"
//    Both unlock only after the owner has at least one permit
//    that has actually gone through verification.
// ============================================================
$verifiedAppCount = 0;
$gateStmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM business_applications
    WHERE user_id = ?
      AND status IN ('verified', 'claimed')
");
if ($gateStmt) {
    $gateStmt->bind_param("i", $user_id);
    $gateStmt->execute();
    $verifiedAppCount = (int) ($gateStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $gateStmt->close();
}
$hasVerifiedApplication = ($verifiedAppCount > 0);

// ---------- RECENT APPS ----------
$recentQuery = "
    SELECT ba.id, ba.reference_no, ba.status, ba.created_at, ba.submitted_at, ba.expiry_date,
           ba.application_date, ba.issued_at, ba.renewed_from_id,
           COALESCE(bid.business_name, 'Not provided') AS business_name,
           COALESCE(atd.application_type, 'New') AS application_type,
           rej.missing_documents, rej.notes AS rejection_notes,
           EXISTS (SELECT 1 FROM appointments ap
                   WHERE ap.application_id = ba.id AND ap.status = 'completed') AS has_completed_appt
    FROM business_applications ba
    LEFT JOIN business_info_data bid ON ba.id = bid.application_id
    LEFT JOIN application_type_data atd ON ba.id = atd.application_id
    LEFT JOIN application_rejections rej ON ba.id = rej.application_id
    WHERE ba.user_id = ? AND ba.status NOT IN ('draft','cancelled')
    ORDER BY ba.created_at DESC
";
$stmt = $conn->prepare($recentQuery);
$stmt->bind_param("i", $user_id); $stmt->execute();
$recentResult = $stmt->get_result();
$recentApps = [];
while ($row = $recentResult->fetch_assoc()) {
    // Permit already picked up (appointment completed) -> show as Claimed, not "Ready for pickup"
    if ($row['status'] === 'verified' && !empty($row['has_completed_appt'])) {
        $row['status'] = 'claimed';
    }
    $row['created_at_formatted'] = date('M j, Y', strtotime($row['created_at']));
    $row['missing_documents'] = !empty($row['missing_documents']) ? json_decode($row['missing_documents'], true) : [];
    $recentApps[] = $row;
}
$stmt->close();

// ============================================================
// CURRENT USER'S ACTIVE APPOINTMENT
// ============================================================
$userAppointment = null;
$apptStmt = $conn->prepare("
    SELECT a.*, ba.reference_no, bi.business_name
    FROM appointments a
    LEFT JOIN business_applications ba ON a.application_id = ba.id
    LEFT JOIN business_info_data bi    ON ba.id = bi.application_id
    WHERE a.user_id = ? AND a.status IN ('pending','confirmed')
    ORDER BY a.created_at DESC LIMIT 1
");
if ($apptStmt) {
    $apptStmt->bind_param("i", $user_id); $apptStmt->execute();
    if ($row = $apptStmt->get_result()->fetch_assoc()) $userAppointment = $row;
    $apptStmt->close();
}

$hasActiveAppt   = ($userAppointment !== null);
$isApptPending   = $hasActiveAppt && strtolower($userAppointment['status']) === 'pending';
$isApptConfirmed = $hasActiveAppt && strtolower($userAppointment['status']) === 'confirmed';

// ============================================================
// ✅ APPOINTMENT PROMPT: Only show when user has VERIFIED applications
// ============================================================
$verifiedApps = [];
$canScheduleAppointment = false;

if (!$hasActiveAppt) {
    $verifiedStmt = $conn->prepare("
        SELECT ba.id, ba.reference_no, ba.status,
               COALESCE(bid.business_name, 'Not provided') AS business_name,
               ba.submitted_at, ba.expiry_date
        FROM business_applications ba
        LEFT JOIN business_info_data bid ON ba.id = bid.application_id
        WHERE ba.user_id = ?
          AND ba.status = 'verified'
          AND NOT EXISTS (
              SELECT 1 FROM appointments ap
              WHERE ap.application_id = ba.id AND ap.status = 'completed'
          )
        ORDER BY ba.created_at DESC
        LIMIT 5
    ");
    if ($verifiedStmt) {
        $verifiedStmt->bind_param("i", $user_id);
        $verifiedStmt->execute();
        $vRes = $verifiedStmt->get_result();
        while ($r = $vRes->fetch_assoc()) $verifiedApps[] = $r;
        $verifiedStmt->close();
    }
    $canScheduleAppointment = (count($verifiedApps) > 0);
}

// Latest COMPLETED appointment (permit already claimed) - shown instead of the schedule prompt
$completedAppt = null;
if (!$hasActiveAppt) {
    $compStmt = $conn->prepare("
        SELECT a.appointment_date, a.updated_at, ba.reference_no, bi.business_name
        FROM appointments a
        LEFT JOIN business_applications ba ON a.application_id = ba.id
        LEFT JOIN business_info_data bi    ON ba.id = bi.application_id
        WHERE a.user_id = ? AND a.status = 'completed'
        ORDER BY a.updated_at DESC, a.id DESC LIMIT 1
    ");
    if ($compStmt) {
        $compStmt->bind_param("i", $user_id); $compStmt->execute();
        if ($r = $compStmt->get_result()->fetch_assoc()) $completedAppt = $r;
        $compStmt->close();
    }
}

// No gate on starting an application
$canStartApplication = true;

// ============================================================
// PAYMENT HISTORY — now sourced from paypal_transactions
// ============================================================
$paymentHistory = [];
$paymentTotals  = ['paid' => 0.0, 'count' => 0, 'pending' => 0, 'failed' => 0];

$paymentQuery = "
    SELECT
        pt.id,
        pt.application_id,
        pt.user_id,
        pt.reference_no,
        pt.paypal_order_id,
        pt.paypal_capture_id,
        pt.payer_id,
        pt.payer_email,
        pt.payer_name,
        pt.amount,
        pt.currency,
        pt.payment_status,
        pt.paid_at,
        pt.created_at,
        ba.reference_no AS app_reference_no,
        ba.status       AS app_status,
        ba.user_id      AS app_user_id,
        COALESCE(bi.business_name, 'Not provided') AS business_name,
        atd.application_type
    FROM paypal_transactions pt
    INNER JOIN business_applications ba ON pt.application_id = ba.id
    LEFT JOIN business_info_data     bi  ON ba.id = bi.application_id
    LEFT JOIN application_type_data  atd ON ba.id = atd.application_id
    WHERE ba.user_id = ?
    ORDER BY COALESCE(pt.paid_at, pt.created_at) DESC, pt.id DESC
";
$stmt = $conn->prepare($paymentQuery);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $payResult = $stmt->get_result();
    while ($row = $payResult->fetch_assoc()) {
        $paymentHistory[] = $row;
        $status = strtolower($row['payment_status'] ?? '');
        if (in_array($status, ['completed', 'paid'], true)) {
            $paymentTotals['paid'] += (float)$row['amount'];
            $paymentTotals['count'] += 1;
        } elseif (in_array($status, ['pending','created','approved','processing'], true)) {
            $paymentTotals['pending']++;
        } elseif (in_array($status, ['failed','voided','cancelled','expired'], true)) {
            $paymentTotals['failed']++;
        }
    }
    $stmt->close();
}
// ============================================================
// HELPERS
// ============================================================
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'under_review': return 'status-review';
        case 'pending_payment': return 'status-pending';
        case 'approved': return 'status-approved';
        case 'claimed': return 'status-claimed';
        case 'verified': return 'status-verified';
        case 'rejected': return 'status-rejected';
        default: return 'status-default';
    }
}
function getStatusLabel($status) {
    switch ($status) {
        case 'under_review': return 'Under review';
        case 'pending_payment': return 'Pending payment';
        case 'approved': return 'Approved';
        case 'claimed': return 'Claimed';
        case 'verified': return 'Ready for pickup';
        case 'rejected': return 'Rejected';
        default: return ucfirst(str_replace('_', ' ', $status));
    }
}
function getPaymentBadgeClass($status) {
    $s = strtolower($status ?? '');
    if (in_array($s, ['completed', 'paid'], true))                                       return 'status-approved';
    if (in_array($s, ['pending','created','approved','processing'], true))                return 'status-pending';
    if (in_array($s, ['failed','voided','cancelled','expired'], true))                    return 'status-rejected';
    return 'status-default';
}
function getPaymentLabel($status) {
    $s = strtolower($status ?? '');
    $map = [
        'completed'  => 'Paid',
        'paid'       => 'Paid',
        'created'    => 'Created',
        'approved'   => 'Approved',
        'pending'    => 'Pending',
        'processing' => 'Processing',
        'voided'     => 'Voided',
        'failed'     => 'Failed',
        'cancelled'  => 'Cancelled',
        'expired'    => 'Expired',
        'unknown'    => 'Unknown',
    ];
    return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
}
/* formatPaymentMethod() removed — all payments now come from PayPal */


function getExpiryStatus($expiry_date, $application_type = 'New', $renewed_from_id = null) {
    if (empty($expiry_date)) return ['label'=>'N/A','class'=>'text-muted','display_date'=>'—','note'=>''];
    $now = new DateTime(); $expiry = new DateTime($expiry_date);
    $days = (int)$now->diff($expiry)->format('%r%a');
    $displayDate = date('M j, Y', strtotime($expiry_date));
    if ($days < 0) { $label='Expired'; $class='text-danger'; }
    elseif ($days <= 30) { $label='Expires soon'; $class='text-warning'; }
    else { $label='Valid'; $class='text-success'; }
    $note = '';
    if ($application_type === 'New' && empty($renewed_from_id)) $note = '(Expires Dec 31)';
    elseif ($application_type === 'Renewal' || !empty($renewed_from_id)) $note = '(Annual renewal)';
    return ['label'=>$label,'class'=>$class,'display_date'=>$displayDate,'note'=>$note];
}

// ============================================================
// ✅ DATA FOR STAT-CARD MODAL POPUPS
//    Matches the counts above exactly (all except cancelled).
//    Drafts are included so "Total applications" is consistent.
// ============================================================
$modalAppsData = [];
$modalQuery = "
    SELECT ba.id, ba.reference_no, ba.status, ba.created_at, ba.submitted_at, ba.expiry_date,
           ba.renewed_from_id,
           COALESCE(bid.business_name, 'Not provided') AS business_name,
           COALESCE(atd.application_type, 'New') AS application_type
    FROM business_applications ba
    LEFT JOIN business_info_data bid ON ba.id = bid.application_id
    LEFT JOIN application_type_data atd ON ba.id = atd.application_id
    WHERE ba.user_id = ? AND ba.status != 'cancelled'
    ORDER BY ba.created_at DESC
";
$mStmt = $conn->prepare($modalQuery);
if ($mStmt) {
    $mStmt->bind_param("i", $user_id);
    $mStmt->execute();
    $mRes = $mStmt->get_result();
    while ($row = $mRes->fetch_assoc()) {
        $filedTs   = !empty($row['submitted_at']) ? $row['submitted_at'] : $row['created_at'];
        $expInfo   = getExpiryStatus($row['expiry_date'] ?? null, $row['application_type'] ?? 'New', $row['renewed_from_id'] ?? null);
        $modalAppsData[] = [
            'id'               => (int)$row['id'],
            'reference_no'     => $row['reference_no'],
            'business_name'    => $row['business_name'],
            'application_type' => $row['application_type'],
            'status'           => $row['status'],
            'status_label'     => getStatusLabel($row['status']),
            'status_class'     => getStatusBadgeClass($row['status']),
            'date_filed'       => date('M j, Y', strtotime($filedTs)),
            'expiry_display'   => $expInfo['display_date'],
            'expiry_note'      => $expInfo['note'],
            'has_expiry'       => !empty($row['expiry_date']),
        ];
    }
    $mStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Dashboard | Buyawanong Placer</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <style>
        :root {
            --brand-dark: #1e293b;
            --brand-dark-hover: #0f172a;
            --brand-yellow: #fbbf24;
            --brand-yellow-hover: #f59e0b;
            --bg-light: #f8fafc;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --card-radius: 16px;
            --green: #10b981;
            --yellow: #f59e0b;
            --red: #ef4444;
            --blue: #3b82f6;
        }

        * { font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-light); color: var(--brand-dark); overflow-x: hidden; }

        .welcome-title {
            font-weight: 800;
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            color: var(--brand-dark);
            letter-spacing: -0.02em;
            margin-bottom: 6px;
        }
        .welcome-subtitle { color: var(--text-muted); font-size: 1rem; margin-bottom: 0; }

        .alert-bar {
            border-radius: 12px;
            padding: 1rem 1.25rem;
            border: none;
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .alert-bar.draft { background: #fffbeb; border-left: 4px solid var(--brand-yellow); }
        .alert-bar.appointment { background: #eff6ff; border-left: 4px solid var(--blue); }
        .alert-bar.action-needed { background: #ecfdf5; border-left: 4px solid var(--green); }
        .alert-bar.action-needed .btn-primary-dark {
            padding: 0.45rem 1rem;
            font-size: 0.8rem;
        }

        .action-card {
            display: block;
            padding: 1.5rem 1.75rem;
            border-radius: var(--card-radius);
            text-decoration: none;
            transition: all 0.2s ease;
            height: 100%;
            border: 1px solid var(--border-color);
            background: #ffffff;
        }
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
        }
        .action-card .action-title {
            font-weight: 700;
            font-size: 1.05rem;
            margin-bottom: 4px;
            color: var(--brand-dark);
        }
        .action-card .action-desc {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 0;
            line-height: 1.5;
        }
        .action-card.primary { background: var(--brand-dark); border-color: var(--brand-dark); }
        .action-card.primary .action-title,
        .action-card.primary .action-desc { color: #ffffff; }
        .action-card.primary .action-desc { color: #94a3b8; }
        .action-card.primary:hover { background: var(--brand-dark-hover); border-color: var(--brand-dark-hover); }

        /* Locked action cards (gated features) */
        .action-card.action-card-locked {
            background: #f8fafc;
            border-style: dashed;
            border-color: #cbd5e1;
            cursor: not-allowed;
            opacity: 0.8;
        }
        .action-card.action-card-locked:hover {
            transform: none;
            box-shadow: none;
            background: #f8fafc;
        }
        .action-card.action-card-locked .action-title { color: var(--text-muted); }
        .action-card.action-card-locked .action-desc { color: #94a3b8; }
        .action-card .lock-icon {
            color: var(--text-muted);
            font-size: 1.05rem;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .action-card .lock-note {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 0.85rem;
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            background: #e2e8f0;
            color: #475569;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .stat-card {
            background: #ffffff;
            border-radius: var(--card-radius);
            padding: 1.25rem 1.5rem;
            border: 1px solid var(--border-color);
            height: 100%;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; }
        .stat-card.green::before { background: var(--green); }
        .stat-card.yellow::before { background: var(--yellow); }
        .stat-card.red::before { background: var(--red); }
        .stat-card.blue::before { background: var(--blue); }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.06); }
        .stat-num { font-size: 2.25rem; font-weight: 800; line-height: 1; color: var(--brand-dark); margin-bottom: 6px; }
        .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }

        /* ---------- Clickable stat cards (modal popups) ---------- */
        .stat-card {
            cursor: pointer;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }
        .stat-card:focus-visible {
            outline: 2px solid var(--brand-dark);
            outline-offset: 2px;
        }
        .stat-card:hover { border-color: #cbd5e1; }

        /* Small "opens modal" affordance */
        .stat-card .stat-open-icon {
            position: absolute;
            bottom: 12px;
            right: 14px;
            color: #cbd5e1;
            font-size: 1rem;
            transition: color .15s, transform .15s;
            pointer-events: none;
        }
        .stat-card:hover .stat-open-icon {
            color: var(--brand-dark);
            transform: translate(1px, -1px);
        }

        /* Stat modal table tweaks */
        #statFilterModal .custom-table tbody td { padding: 0.85rem 0.5rem; }
        #statFilterModal .modal-header { background: #f8fafc; }
        #statFilterModal .stat-modal-count {
            font-size: 0.75rem;
            font-weight: 600;
            background: #e2e8f0;
            color: #334155;
            padding: 0.2rem 0.6rem;
            border-radius: 2rem;
        }

        .table-card {
            background: #ffffff;
            border-radius: var(--card-radius);
            border: 1px solid var(--border-color);
            padding: 1.5rem;
        }
        .table-card h5 { font-weight: 700; font-size: 1.15rem; color: var(--brand-dark); margin-bottom: 1rem; }

        .custom-table { width: 100%; border-collapse: collapse; }
        .custom-table thead th {
            font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.5px; color: var(--text-muted);
            padding: 0.75rem 0.5rem; border-bottom: 1px solid var(--border-color); text-align: left;
        }
        .custom-table tbody td {
            padding: 1rem 0.5rem; font-size: 0.9rem; vertical-align: middle;
            border-bottom: 1px solid #f1f5f9; color: var(--brand-dark);
        }
        .custom-table tbody tr:last-child td { border-bottom: none; }
        .custom-table tbody tr:hover { background: #fafafa; }
        .custom-table .business-name { font-weight: 600; }
        .custom-table .business-ref { font-size: 0.75rem; color: var(--text-muted); font-weight: 400; }

        .status-pill {
            display: inline-block; padding: 0.3rem 0.75rem; border-radius: 2rem;
            font-size: 0.75rem; font-weight: 600; white-space: nowrap;
        }
        .status-review { background: #fef3c7; color: #92400e; }
        .status-pending { background: #fee2e2; color: #991b1b; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-verified { background: #d1fae5; color: #065f46; }
        .status-claimed { background: #dbeafe; color: #1e40af; }
        .status-rejected { background: #fecaca; color: #991b1b; }
        .status-default { background: #f1f5f9; color: #475569; }

        .type-pill { display: inline-block; padding: 0.3rem 0.75rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 600; }
        .type-new { background: #fef3c7; color: #92400e; }
        .type-renewal { background: #e0e7ff; color: #3730a3; }

        .view-link {
            color: var(--brand-dark); font-weight: 600; font-size: 0.85rem;
            text-decoration: underline; text-decoration-color: var(--border-color);
            text-underline-offset: 3px; transition: all 0.2s;
        }
        .view-link:hover { color: var(--blue); text-decoration-color: var(--blue); }

        .info-card {
            background: #ffffff; border-radius: var(--card-radius);
            border: 1px solid var(--border-color); padding: 1.5rem; height: 100%;
        }
        .info-card h6 { font-weight: 700; font-size: 1rem; color: var(--brand-dark); margin-bottom: 6px; }
        .info-card p { font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0; line-height: 1.5; }
        .info-card a { color: var(--blue); text-decoration: none; font-weight: 500; }
        .info-card a:hover { text-decoration: underline; }

        .btn-primary-dark {
            background-color: var(--brand-dark); color: #ffffff;
            border: 1px solid var(--brand-dark); border-radius: 8px;
            padding: 0.6rem 1.25rem; font-weight: 600; font-size: 0.875rem;
            transition: all 0.2s ease; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary-dark:hover { background-color: var(--brand-dark-hover); color: #ffffff; }
        .btn-outline-dark-custom {
            background-color: transparent; color: var(--brand-dark);
            border: 1px solid var(--border-color); border-radius: 8px;
            padding: 0.6rem 1.25rem; font-weight: 600; font-size: 0.875rem;
            transition: all 0.2s ease;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-outline-dark-custom:hover { background-color: #f1f5f9; border-color: var(--brand-dark); }

        .payment-summary {
            background: #f8fafc; border: 1px solid var(--border-color);
            border-radius: 12px; padding: 1rem 1.25rem;
        }
        .payment-summary .amount { font-size: 1.5rem; font-weight: 800; color: var(--brand-dark); line-height: 1.2; }
        .payment-summary .label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .payment-summary .sub { font-size: 0.75rem; color: var(--text-muted); }

        .payment-method-pill {
            display: inline-flex; align-items: center; gap: 4px;
            background: #f0f7ff; color: #0d6efd; font-size: 0.7rem;
            font-weight: 600; padding: 0.15rem 0.55rem; border-radius: 2rem;
            border: 1px solid #d3e4f7;
        }
        .payment-method-pill.paypal {
            background: #eaf3fb;
            color: #0070ba;
            border-color: #c9dff2;
        }
        .view-payment-btn {
            background: #ffffff; border: 1px solid var(--border-color);
            color: var(--brand-dark); font-weight: 600; font-size: 0.75rem;
            padding: 0.3rem 0.75rem; border-radius: 6px; transition: all 0.15s;
        }
        .view-payment-btn:hover { background: var(--brand-dark); color: #ffffff; border-color: var(--brand-dark); }

        .btn-reupload {
            color: #991b1b; border: 1px solid #fecaca; background: #fee2e2;
            font-weight: 600; font-size: 0.75rem; padding: 0.35rem 0.75rem; border-radius: 6px;
        }
        .btn-reupload:hover { background: #fecaca; color: #991b1b; }

        #scanner-container { width: 100%; max-width: 400px; margin: 0 auto; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 0.5rem; background: #f8fafc; }
        #scanner-container video { width: 100%; height: auto; border-radius: 8px; }
        .manual-input-group { margin-top: 1rem; }

        .missing-doc-item { padding: 0.75rem; background: #f8fafc; border-radius: 8px; margin-bottom: 0.75rem; border: 1px solid var(--border-color); }
        .missing-doc-item .doc-label { font-weight: 600; font-size: 0.9rem; }
        .missing-doc-item .file-input-wrapper { margin-top: 0.5rem; }

        .modal-content { border-radius: 12px; border: none; }
        .modal-header { border-bottom: 1px solid var(--border-color); background: #f8fafc; }
        .modal-title { font-weight: 700; color: var(--brand-dark); }

        .payment-receipt-modal .modal-content { border-radius: 1rem; overflow: hidden; }
        .payment-receipt-modal .modal-header { background: var(--brand-dark); color: #fff; border-bottom: 3px solid var(--brand-yellow); }
        .payment-receipt-modal .modal-header .btn-close { filter: invert(1); }
        .prm-body { padding: 0; background: #fff; }
        .prm-receipt { font-family: 'Courier New', Courier, monospace; padding: 25px 30px; background: #fff; color: #000; }
        .prm-header { display: flex; align-items: center; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 12px; }
        .prm-logo { width: 70px; text-align: center; margin-right: 15px; }
        .prm-logo i { font-size: 2.5rem; color: var(--brand-dark); }
        .prm-title { flex: 1; text-align: center; padding-right: 70px; }
        .prm-title h3 { font-family: 'Times New Roman', serif; font-size: 1.4rem; font-weight: 700; margin: 0; letter-spacing: 2px; }
        .prm-title p { font-family: 'Times New Roman', serif; font-size: 0.8rem; margin: 3px 0 0 0; }
        .prm-meta { font-size: 0.75rem; border-bottom: 1px dashed #999; padding-bottom: 10px; margin-bottom: 12px; text-align: center; color: #555; letter-spacing: 1px; }
        .prm-row { display: flex; padding: 5px 0; font-size: 0.85rem; border-bottom: 1px solid #eee; }
        .prm-row .lbl { width: 130px; font-weight: bold; flex-shrink: 0; }
        .prm-row .val { flex: 1; word-break: break-word; }
        .prm-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin: 15px 0 10px; }
        .prm-table th, .prm-table td { border: 1px solid #000; padding: 8px 10px; }
        .prm-table th { background: #f5f5f5; text-align: left; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; }
        .prm-table td.right { text-align: right; }
        .prm-table .total-row td { background: var(--brand-dark); color: #fff; font-weight: 700; font-size: 0.95rem; }
        .prm-footer { margin-top: 15px; padding-top: 10px; border-top: 1px dashed #999; font-size: 0.7rem; color: #666; text-align: center; }
        .prm-signature { display: flex; justify-content: flex-end; margin-top: 25px; }
        .prm-signature .line { width: 200px; border-top: 1px solid #000; text-align: center; padding-top: 4px; font-size: 0.75rem; font-weight: bold; }

        .time-slot {
            display: inline-block; padding: 0.4rem 0.9rem; border-radius: 2rem;
            font-size: 0.78rem; border: 1px solid var(--border-color);
            background: #fff; cursor: pointer; transition: 0.15s;
        }
        .time-slot:hover { border-color: var(--brand-dark); }
        .time-slot.selected { background: var(--brand-dark); color: #fff; border-color: var(--brand-dark); }
        .time-slot.booked { background: #e9ecef; color: #adb5bd; cursor: not-allowed; pointer-events: none; }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--brand-dark) !important; color: #fff !important;
            border-color: var(--brand-dark) !important; border-radius: 6px;
        }
        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border-radius: 6px; border: 1px solid var(--border-color); padding: 0.35rem 0.75rem;
        }

        @media (max-width: 768px) {
            .stat-num { font-size: 1.75rem; }
            .welcome-title { font-size: 1.6rem; }
            .custom-table thead th, .custom-table tbody td { padding: 0.75rem 0.35rem; font-size: 0.8rem; }
            .table-card { padding: 1rem; }
            .stat-card .stat-open-icon { font-size: 0.85rem; bottom: 8px; right: 10px; }
        }
    </style>
</head>
<body>

<?php include 'header/sidebar.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 1400px;">

    <div class="mb-4">
        <h1 class="welcome-title">Welcome back, <span id="welcomeName"><?php echo htmlspecialchars($first_name); ?></span></h1>
        <p class="welcome-subtitle">Your business permit applications at a glance.</p>
    </div>

    <?php if (isset($_SESSION['gate_error'])): ?>
        <div class="alert-bar" style="background:#fffbeb;border-left:4px solid var(--brand-yellow);">
            <i class="bi bi-exclamation-triangle-fill" style="color:var(--brand-yellow);"></i>
            <span><?php echo htmlspecialchars($_SESSION['gate_error']); ?></span>
        </div>
        <?php unset($_SESSION['gate_error']); ?>
    <?php endif; ?>

    <!-- ✅ VERIFIED → Schedule Appointment prompt -->
    <?php if ($canScheduleAppointment): ?>
        <div class="alert-bar action-needed mb-4">
            <i class="bi bi-patch-check-fill" style="color:var(--green);font-size:1.75rem;"></i>
            <div class="flex-grow-1">
                <strong>Your permit is verified and ready for pickup</strong>
                <div class="text-muted small mt-1">
                    You have <?php echo count($verifiedApps); ?> verified permit<?php echo count($verifiedApps) > 1 ? 's' : ''; ?> ready to be claimed.
                    Schedule a visit to the Municipal Hall to pick up your printed permit.
                </div>
                <div class="small mt-2">
                    <?php foreach ($verifiedApps as $vapp): ?>
                        <span class="badge bg-light text-dark me-1 mb-1" style="font-size: 0.7rem;">
                            <i class="bi bi-file-earmark-check"></i>
                            <?php echo htmlspecialchars($vapp['business_name']); ?>
                            · <?php echo htmlspecialchars($vapp['reference_no']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <button class="btn-primary-dark" id="scheduleApptPromptBtn">
                <i class="bi bi-calendar-plus"></i> Schedule appointment
            </button>
        </div>
    <?php endif; ?>

    <!-- Completed appointment (permit claimed) -->
    <?php if ($completedAppt && !$canScheduleAppointment): ?>
        <div class="alert-bar appointment mb-4">
            <i class="bi bi-check-circle-fill" style="color:var(--green);font-size:1.5rem;"></i>
            <div class="flex-grow-1">
                <strong>Appointment Completed &mdash; Permit Claimed</strong>
                <span class="text-muted ms-2 small">
                    <?php echo date('F j, Y', strtotime($completedAppt['appointment_date'])); ?>
                    <?php if (!empty($completedAppt['business_name'])): ?>
                        &middot; <?php echo htmlspecialchars($completedAppt['business_name']); ?>
                    <?php endif; ?>
                    <?php if (!empty($completedAppt['reference_no'])): ?>
                        &middot; <?php echo htmlspecialchars($completedAppt['reference_no']); ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Active appointment bar -->
    <?php if ($hasActiveAppt): ?>
        <div class="alert-bar appointment mb-4">
            <i class="bi bi-calendar-check" style="color:var(--blue);font-size:1.25rem;"></i>
            <div class="flex-grow-1">
                <strong>Appointment <?php echo $isApptPending ? 'Pending Approval' : 'Confirmed'; ?></strong>
                <span class="text-muted ms-2 small">
                    <?php echo date('F j, Y', strtotime($userAppointment['appointment_date'])); ?> at <?php echo htmlspecialchars($userAppointment['appointment_time']); ?>
                    <?php if (!empty($userAppointment['purpose'])): ?>
                        &middot; <?php echo htmlspecialchars($userAppointment['purpose']); ?>
                    <?php endif; ?>
                </span>
                <div class="text-muted small mt-1">
                    <?php if ($isApptPending): ?>
                        Your appointment is waiting for BPLO confirmation. We'll notify you once it's approved.
                    <?php else: ?>
                        Please bring a valid ID and the printed reference number on your visit.
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isApptPending): ?>
                <button class="btn btn-outline-dark-custom btn-sm" id="cancelApptBtn">
                    <i class="bi bi-x-circle"></i> Cancel Request
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Draft alert -->
    <?php if ($hasDraft): ?>
        <div class="alert-bar draft mb-4">
            <i class="bi bi-exclamation-triangle-fill" style="color:var(--brand-yellow);font-size:1.25rem;"></i>
            <div class="flex-grow-1">
                <strong>You have an incomplete application.</strong>
                <span class="text-muted ms-2 small">Last saved at step <?php echo $draftData['current_step']; ?> of 8.</span>
            </div>
            <div class="d-flex gap-2">
                <a href="new_permit.php" class="btn-primary-dark btn-sm">Continue</a>
                <button id="discardDraftBtn" class="btn-outline-dark-custom btn-sm">
                    <i class="bi bi-trash3"></i> Discard
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Three Action Cards -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <a href="new_permit.php?reset=1" id="newAppBtn" class="action-card primary">
                <div class="action-title">Start a new application</div>
                <p class="action-desc">Create a brand-new business permit application.</p>
            </a>
        </div>

        <!-- Renew a permit — unlocks after at least one verified permit -->
        <div class="col-lg-4">
            <a href="<?php echo $hasVerifiedApplication ? 'renew_permit.php' : '#'; ?>"
               id="renewPermitBtn"
               class="action-card<?php echo $hasVerifiedApplication ? '' : ' action-card-locked'; ?>"
               data-unlocked="<?php echo $hasVerifiedApplication ? '1' : '0'; ?>"
               aria-disabled="<?php echo $hasVerifiedApplication ? 'false' : 'true'; ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="action-title">Renew a permit</div>
                        <p class="action-desc">Renew an existing permit.</p>
                    </div>
                    <?php if (!$hasVerifiedApplication): ?>
                        <i class="bi bi-lock-fill lock-icon" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>
                <?php if (!$hasVerifiedApplication): ?>
                    <div class="lock-note">
                        <i class="bi bi-info-circle"></i>
                        Unlocks once your permit is verified
                    </div>
                <?php endif; ?>
            </a>
        </div>

        <!-- Verify a permit — unlocks after at least one verified permit -->
        <div class="col-lg-4">
            <a href="#"
               id="verifyPermitBtn"
               class="action-card<?php echo $hasVerifiedApplication ? '' : ' action-card-locked'; ?>"
               data-unlocked="<?php echo $hasVerifiedApplication ? '1' : '0'; ?>"
               aria-disabled="<?php echo $hasVerifiedApplication ? 'false' : 'true'; ?>"
               <?php if ($hasVerifiedApplication): ?>data-bs-toggle="modal" data-bs-target="#verifyModal"<?php endif; ?>>
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="action-title">Verify a permit</div>
                        <p class="action-desc">Scan or enter a QR code.</p>
                    </div>
                    <?php if (!$hasVerifiedApplication): ?>
                        <i class="bi bi-lock-fill lock-icon" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>
                <?php if (!$hasVerifiedApplication): ?>
                    <div class="lock-note">
                        <i class="bi bi-info-circle"></i>
                        Unlocks once your permit is verified
                    </div>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <!-- Stat Cards — click a card to pop up a modal with matching applications -->
    <div class="row g-3 mb-4" id="statCardsRow">
        <div class="col-6 col-lg-3">
            <div class="stat-card green" role="button" tabindex="0"
                 data-filter="all" aria-label="Show all applications"
                 title="Click to view all applications">
                <i class=aria-hidden="true"></i>
                <div class="stat-num"><?php echo $totalApps; ?></div>
                <div class="stat-label">Total applications</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card yellow" role="button" tabindex="0"
                 data-filter="under_review" aria-label="Show applications in review"
                 title="Click to view applications in review">
                <i class=aria-hidden="true"></i>
                <div class="stat-num"><?php echo $pendingReview; ?></div>
                <div class="stat-label">In review</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card red" role="button" tabindex="0"
                 data-filter="pending_payment" aria-label="Show applications awaiting payment"
                 title="Click to view applications awaiting payment">
                <i class=aria-hidden="true"></i>
                <div class="stat-num"><?php echo $pendingPayment; ?></div>
                <div class="stat-label">Waiting for payment</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card blue" role="button" tabindex="0"
                 data-filter="completed" aria-label="Show approved or verified applications"
                 title="Click to view approved or verified applications">
                <i class= aria-hidden="true"></i>
                <div class="stat-num"><?php echo $completed; ?></div>
                <div class="stat-label">Approved or verified</div>
            </div>
        </div>
    </div>

    <!-- Recent Applications -->
    <div class="table-card mb-4">
        <h5>Recent applications</h5>
        <div class="table-responsive">
            <table id="recentAppsTable" class="custom-table" style="width:100%">
                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Type</th>
                        <th>Date filed</th>
                        <th>Expires</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recentApps) > 0): ?>
                        <?php foreach ($recentApps as $app):
                            $expStatus = getExpiryStatus($app['expiry_date'] ?? null, $app['application_type'] ?? 'New', $app['renewed_from_id'] ?? null);
                            $isRejected = ($app['status'] === 'rejected');
                            $isVerified = ($app['status'] === 'verified');
                        ?>
                            <tr>
                                <td>
                                    <div class="business-name"><?php echo htmlspecialchars($app['business_name']); ?></div>
                                    <div class="business-ref"><?php echo htmlspecialchars($app['reference_no']); ?></div>
                                </td>
                                <td>
                                    <span class="type-pill <?php echo $app['application_type'] == 'New' ? 'type-new' : 'type-renewal'; ?>">
                                        <?php echo htmlspecialchars($app['application_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $dateFiled = !empty($app['submitted_at']) ? $app['submitted_at'] : $app['created_at'];
                                    echo date('M j, Y', strtotime($dateFiled));
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($app['expiry_date'])): ?>
                                        <div><?php echo $expStatus['display_date']; ?></div>
                                        <?php if (!empty($expStatus['note'])): ?>
                                            <div class="small text-muted"><?php echo $expStatus['note']; ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-pill <?php echo getStatusBadgeClass($app['status']); ?>">
                                        <?php echo getStatusLabel($app['status']); ?>
                                    </span>
                                    <?php if ($isVerified && !$hasActiveAppt): ?>
                                        <div class="small mt-1">
                                            <a href="#" class="view-link schedule-from-row" data-app-id="<?php echo $app['id']; ?>">
                                                Schedule pickup
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isRejected): ?>
                                        <button class="btn-reupload reupload-btn"
                                                data-appid="<?php echo $app['id']; ?>"
                                                data-business="<?php echo htmlspecialchars($app['business_name']); ?>"
                                                data-missing='<?php echo json_encode($app['missing_documents']); ?>'
                                                data-bs-toggle="modal" data-bs-target="#reuploadModal">
                                            <i class="bi bi-arrow-up-circle"></i> Re-upload
                                        </button>
                                    <?php else: ?>
                                        <a href="view_application.php?id=<?php echo (int)$app['id']; ?>"
                                           class="view-link view-app-link"
                                           data-app-id="<?php echo (int)$app['id']; ?>"
                                           data-business="<?php echo htmlspecialchars($app['business_name'], ENT_QUOTES); ?>">View</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td class="text-center py-4 text-muted">No applications found.</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Payment History -->
    <div class="table-card mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
            <h5 class="mb-0">Payment history</h5>
            <span class="status-pill status-approved">
                <i class="bi bi-paypal"></i> Secured by PayPal
            </span>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="payment-summary">
                    <div class="label">Total paid</div>
                    <div class="amount">₱<?php echo number_format($paymentTotals['paid'], 2); ?></div>
                    <div class="sub"><i class="bi bi-check-circle-fill" style="color:var(--green);"></i>
                        <?php echo $paymentTotals['count']; ?> successful
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="payment-summary">
                    <div class="label">Pending</div>
                    <div class="amount" style="color:var(--yellow);"><?php echo $paymentTotals['pending']; ?></div>
                    <div class="sub"><i class="bi bi-hourglass-split" style="color:var(--yellow);"></i> Awaiting</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="payment-summary">
                    <div class="label">Failed / Expired</div>
                    <div class="amount" style="color:var(--red);"><?php echo $paymentTotals['failed']; ?></div>
                    <div class="sub"><i class="bi bi-x-circle" style="color:var(--red);"></i> Unsuccessful</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="payment-summary">
                    <div class="label">Total records</div>
                    <div class="amount" style="color:var(--blue);"><?php echo count($paymentHistory); ?></div>
                    <div class="sub"><i class="bi bi-list-ul" style="color:var(--blue);"></i> All-time</div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table id="paymentsHistoryTable" class="custom-table" style="width:100%">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Business</th>
                        <th>Method</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($paymentHistory) > 0): ?>
                        <?php foreach ($paymentHistory as $pay):
                            $payDate          = !empty($pay['paid_at']) ? $pay['paid_at'] : $pay['created_at'];
                            $payDateFormatted = date('M j, Y g:i A', strtotime($payDate));
                            $statusLabel      = getPaymentLabel($pay['payment_status']);
                            $statusClass      = getPaymentBadgeClass($pay['payment_status']);
                            $currency         = strtoupper($pay['currency'] ?? 'PHP');
                            $currencySymbol   = $currency === 'PHP' ? '₱' : ($currency === 'USD' ? '$' : $currency . ' ');
                            $amount           = number_format((float)$pay['amount'], 2);
                            $refDisplay       = $pay['paypal_capture_id'] ?: ($pay['paypal_order_id'] ?: '—');
                            $refShort         = strlen($refDisplay) > 16 ? substr($refDisplay, 0, 16) . '…' : $refDisplay;
                            $description      = 'Business Permit Fee';
                        ?>
                            <tr>
                                <td data-order="<?php echo strtotime($payDate); ?>">
                                    <div><?php echo date('M j, Y', strtotime($payDate)); ?></div>
                                    <div class="small text-muted"><?php echo date('g:i A', strtotime($payDate)); ?></div>
                                </td>
                                <td>
                                    <code class="small" title="<?php echo htmlspecialchars($refDisplay, ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars($refShort); ?>
                                    </code>
                                </td>
                                <td>
                                    <div class="business-name"><?php echo htmlspecialchars($pay['business_name']); ?></div>
                                    <?php if (!empty($pay['app_reference_no'])): ?>
                                        <div class="business-ref">App: <?php echo htmlspecialchars($pay['app_reference_no']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="payment-method-pill paypal">
                                        <i class="bi bi-paypal"></i> PayPal
                                    </span>
                                </td>
                                <td><strong><?php echo $currencySymbol . $amount; ?></strong></td>
                                <td>
                                    <span class="status-pill <?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="view-payment-btn view-payment-history"
                                            data-payment-id="<?php echo htmlspecialchars($pay['paypal_capture_id'] ?: ($pay['paypal_order_id'] ?: $pay['id']), ENT_QUOTES); ?>"
                                            data-ref="<?php echo htmlspecialchars($refDisplay, ENT_QUOTES); ?>"
                                            data-description="<?php echo htmlspecialchars($description, ENT_QUOTES); ?>"
                                            data-amount="<?php echo htmlspecialchars($pay['amount'], ENT_QUOTES); ?>"
                                            data-currency="<?php echo htmlspecialchars($currency, ENT_QUOTES); ?>"
                                            data-status="<?php echo htmlspecialchars($pay['payment_status'], ENT_QUOTES); ?>"
                                            data-status-label="<?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?>"
                                            data-source="PayPal"
                                            data-paid-at="<?php echo htmlspecialchars($payDateFormatted, ENT_QUOTES); ?>"
                                            data-business="<?php echo htmlspecialchars($pay['business_name'], ENT_QUOTES); ?>"
                                            data-payer-name="<?php echo htmlspecialchars($pay['payer_name'] ?? '', ENT_QUOTES); ?>"
                                            data-payer-email="<?php echo htmlspecialchars($pay['payer_email'] ?? '', ENT_QUOTES); ?>"
                                            data-app-id="<?php echo (int)($pay['application_id'] ?? 0); ?>">
                                        View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td class="text-center py-4 text-muted">No payment records yet.</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Bottom Info Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="info-card">
                <h6>Need help?</h6>
                <p>The LGU help desk is open Monday to Friday, 8 AM to 5 PM. Write to <a href="mailto:lgu.placer@gov.ph">lgu.placer@gov.ph</a></p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="info-card">
                <h6>Every approved permit has a QR code</h6>
                <p>Anyone can scan it to check that your permit is genuine.</p>
            </div>
        </div>
    </div>

</div>

<!-- ============================================================
     STAT CARD → MODAL POPUP (application list)
     ============================================================ -->
<div class="modal fade" id="statFilterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width:1100px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-funnel-fill"></i>
                    <span id="statModalTitle">Applications</span>
                    <span class="stat-modal-count" id="statModalCount">0</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="statModalEmpty" class="text-center py-5 text-muted" style="display:none;">
                    <i class="bi bi-inbox" style="font-size:2.5rem; color:#cbd5e1;"></i>
                    <p class="mt-3 mb-0">No applications in this category.</p>
                </div>
                <div class="table-responsive" id="statModalTableWrap">
                    <table class="custom-table" style="width:100%">
                        <thead>
                            <tr>
                                <th>Business</th>
                                <th>Type</th>
                                <th>Date filed</th>
                                <th>Expires</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="statModalTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- SCHEDULE APPOINTMENT MODAL -->
<div class="modal fade" id="scheduleApptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-calendar-plus me-2"></i> Schedule Permit Pickup
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    This appointment is for <strong>claiming your verified permit</strong> at the Municipal Hall.
                    Bring a valid ID and your reference number.
                    Office hours: <strong>8:00 AM – 5:00 PM</strong> (closed 12:00 – 1:00 PM).
                </div>

                <?php if (count($verifiedApps) > 0): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold small text-uppercase">Permit<?php echo count($verifiedApps) > 1 ? 's' : ''; ?> to claim</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($verifiedApps as $vapp): ?>
                            <span class="badge bg-light text-dark border" style="font-size: 0.75rem; padding: 0.5rem 0.75rem;">
                                <i class="bi bi-file-earmark-check text-success"></i>
                                <?php echo htmlspecialchars($vapp['business_name']); ?>
                                · <?php echo htmlspecialchars($vapp['reference_no']); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold small text-uppercase">Purpose of visit</label>
                    <input type="text" id="dashApptPurpose" class="form-control"
                           value="Claim Business Permit" readonly>
                </div>

                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold small text-uppercase">Select Date</label>
                        <input type="date" id="dashApptDate" class="form-control">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label fw-semibold text-uppercase small">Select Time Slot</label>
                        <div class="d-flex flex-wrap gap-2" id="dashTimeSlotContainer"></div>
                        <input type="hidden" id="dashSelectedSlot" value="">
                    </div>
                </div>

                <div class="mt-3 pt-2 border-top">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted"><i class="bi bi-info-circle"></i> Selected:</span>
                        <span id="dashApptSummary" class="fw-semibold" style="color:var(--blue);">No appointment selected</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-primary-dark" id="confirmDashApptBtn">
                    <i class="bi bi-check-lg"></i> Submit for Approval
                </button>
            </div>
        </div>
    </div>
</div>

<!-- RE-UPLOAD MODAL -->
<div class="modal fade" id="reuploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-up-circle me-2" style="color:var(--red);"></i>Re-upload Missing Documents
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Your application was rejected because the following documents are missing or incomplete. Please upload the required files.</p>
                <div id="reuploadAppInfo" class="alert alert-secondary d-none">
                    <strong>Application:</strong> <span id="reuploadBusinessName"></span>
                </div>
                <form id="reuploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="application_id" id="reuploadAppId">
                    <div id="missingDocsContainer"></div>
                    <div class="mt-3 text-end">
                        <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-dark" id="submitReuploadBtn">
                            <i class="bi bi-cloud-upload"></i> Submit for Review
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- PAYMENT RECEIPT MODAL -->
<div class="modal fade payment-receipt-modal" id="paymentReceiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Payment Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body prm-body" id="prmBody">
                <div class="prm-receipt">
                    <div class="prm-header">
                        <div class="prm-logo"><i class="bi bi-bank2"></i></div>
                        <div class="prm-title">
                            <h3>PAYMENT RECEIPT</h3>
                            <p>Republic of the Philippines</p>
                            <p>Municipality of Placer · Surigao del Norte</p>
                        </div>
                    </div>
                    <div class="prm-meta">Electronic Payment Confirmation · PayPal</div>
                    <div class="prm-row"><span class="lbl">Receipt No.:</span><span class="val" id="prm-receipt-no">—</span></div>
                    <div class="prm-row"><span class="lbl">Transaction ID:</span><span class="val" id="prm-transaction-id">—</span></div>
                    <div class="prm-row"><span class="lbl">Date / Time:</span><span class="val" id="prm-date">—</span></div>
                    <div class="prm-row"><span class="lbl">Payment Method:</span><span class="val" id="prm-method">—</span></div>
                    <div class="prm-row"><span class="lbl">Status:</span><span class="val" id="prm-status">—</span></div>
                    <div class="prm-row"><span class="lbl">Payer:</span><span class="val" id="prm-payer">—</span></div>
                    <div class="prm-row"><span class="lbl">Business:</span><span class="val" id="prm-business">—</span></div>
                    <table class="prm-table">
                        <thead><tr><th>Description</th><th style="text-align:right;">Amount</th></tr></thead>
                        <tbody>
                            <tr><td id="prm-description">Business Permit Fee</td><td class="right" id="prm-amount-row">₱0.00</td></tr>
                            <tr class="total-row"><td>TOTAL PAID</td><td class="right" id="prm-total">₱0.00</td></tr>
                        </tbody>
                    </table>
                    <div class="prm-footer">This document serves as your official acknowledgment of the electronic payment above. Please keep it for your records.</div>
                    <div class="prm-signature"><div class="line">Municipal Treasurer</div></div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="prm-view-app-link" class="btn-outline-dark-custom" target="_blank" style="display:none;">
                    <i class="bi bi-box-arrow-up-right"></i> View Application
                </a>
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn-primary-dark" id="prmPrintBtn">
                    <i class="bi bi-printer"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<!-- VERIFY PERMIT MODAL -->
<div class="modal fade" id="verifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-upc-scan me-2"></i>Verify Business Permit
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Scan the QR code on the permit or enter the reference number manually.</p>
                <div id="scanner-container"></div>
                <div id="scanner-status" class="text-center text-muted small mt-2">Waiting for camera access...</div>

                <div class="d-md-none mt-3">
                    <div class="d-grid gap-2">
                        <button type="button" class="btn-outline-dark-custom" id="switchCamBtn"><i class="bi bi-arrow-repeat"></i> Switch Camera</button>
                        <button type="button" class="btn-outline-dark-custom" id="takePhotoBtn"><i class="bi bi-camera"></i> Take Photo</button>
                        <button type="button" class="btn-outline-dark-custom" id="uploadQrBtn"><i class="bi bi-image"></i> Upload Image</button>
                    </div>
                    <input type="file" id="qrCameraInput" accept="image/*" capture="environment" class="d-none">
                    <input type="file" id="qrFileInput" accept="image/*" class="d-none">
                    <small class="text-muted d-block mt-2">Tip: If the QR is on another screen or blurred, use <strong>Take Photo</strong> or <strong>Upload Image</strong>.</small>
                </div>

                <div class="manual-input-group">
                    <label for="manualRef" class="form-label fw-semibold">Or enter reference number</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="manualRef" placeholder="e.g. BP-2026-00001">
                        <button class="btn-primary-dark" id="manualVerifyBtn">Verify</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- VIEW APPLICATION MODAL -->
<div class="modal fade" id="viewApplicationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width:1200px;">
        <div class="modal-content" style="border-radius:12px;overflow:hidden;">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    <span id="viewAppTitle">Application Details</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-0 position-relative" style="height:80vh;background:#f8fafc;">
                <div id="viewAppLoader"
                     class="d-flex flex-column justify-content-center align-items-center"
                     style="position:absolute;inset:0;">
                    <div class="spinner-border text-secondary" role="status"></div>
                    <p class="text-muted small mt-2 mb-0">Loading application…</p>
                </div>
                <iframe id="viewAppFrame"
                        src="about:blank"
                        title="Application details"
                        style="position:absolute;inset:0;width:100%;height:100%;border:0;display:none;"></iframe>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">
                    Close
                </button>
            </div>

        </div>
    </div>
</div>

<!-- ============================================================
     ACCOUNT SETTINGS MODAL
     ============================================================ -->
<div class="modal fade" id="accountSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="overflow:hidden;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>Account Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 position-relative" style="height:72vh;background:#f5f7fa;">
                <div id="accountSettingsLoader"
                     class="d-flex flex-column justify-content-center align-items-center"
                     style="position:absolute;inset:0;">
                    <div class="spinner-border text-secondary" role="status"></div>
                    <p class="text-muted small mt-2 mb-0">Loading account settings…</p>
                </div>
                <iframe id="accountSettingsFrame" src="about:blank" title="Account Settings"
                        style="position:absolute;inset:0;width:100%;height:100%;border:0;display:none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     NOTIFICATIONS MODAL
     ============================================================ -->
<div class="modal fade" id="notificationsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="overflow:hidden;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bell-fill me-2"></i>Notifications</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 position-relative" style="height:72vh;background:#f8f9fa;">
                <div id="notificationsLoader"
                     class="d-flex flex-column justify-content-center align-items-center"
                     style="position:absolute;inset:0;">
                    <div class="spinner-border text-secondary" role="status"></div>
                    <p class="text-muted small mt-2 mb-0">Loading notifications…</p>
                </div>
                <iframe id="notificationsFrame" src="about:blank" title="Notifications"
                        style="position:absolute;inset:0;width:100%;height:100%;border:0;display:none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline-dark-custom" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {

    // ---------- FEATURE GATE (mirrors the PHP $hasVerifiedApplication flag) ----------
    const HAS_VERIFIED_APP = <?php echo $hasVerifiedApplication ? 'true' : 'false'; ?>;

    const LOCK_MESSAGES = {
        renew: {
            title: 'Renewal not available yet',
            html: 'You can renew a permit only after at least one of your applications has been ' +
                  '<strong>verified</strong> by the BPLO.<br><br>' +
                  'Start a new application and wait for it to be verified.'
        },
        verify: {
            title: 'Permit verification locked',
            html: 'The permit verification tool is available only to business owners with at least one ' +
                  '<strong>verified</strong> permit.<br><br>' +
                  'Once your application is verified you can scan permits here.'
        }
    };

    function showLockedNotice(kind) {
        const msg = LOCK_MESSAGES[kind] || { title: 'Locked', html: 'This feature is not available yet.' };
        Swal.fire({
            icon: 'info',
            title: msg.title,
            html: msg.html,
            confirmButtonColor: '#1e293b',
            confirmButtonText: 'Got it'
        });
    }

    // Renew a permit — blocked until a permit is verified
    $(document).on('click', '#renewPermitBtn', function (e) {
        if (String($(this).data('unlocked')) === '1') return; // allowed → follow the link
        e.preventDefault();
        showLockedNotice('renew');
    });

    // Verify a permit — blocked until a permit is verified
    $(document).on('click', '#verifyPermitBtn', function (e) {
        if (HAS_VERIFIED_APP) return; // Bootstrap opens the modal
        e.preventDefault();
        e.stopPropagation();
        showLockedNotice('verify');
    });

    // Defensive: never let the scanner modal open while locked
    $('#verifyModal').on('show.bs.modal', function (e) {
        if (!HAS_VERIFIED_APP) {
            e.preventDefault();
            e.stopPropagation();
            showLockedNotice('verify');
        }
    });

    // ---------- VIEW APPLICATION (modal, no new tab) ----------
    const viewAppModalEl = document.getElementById('viewApplicationModal');
    if (viewAppModalEl) {
        bootstrap.Modal.getOrCreateInstance(viewAppModalEl);
    }

    $(document).on('click', '.view-app-link', function (e) {
        // Let modified clicks (Ctrl/Cmd/middle-click) behave normally
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.which === 2) return;

        e.preventDefault();

        // If the stat-filter modal is open, close it so only one modal is visible
        const statModalEl = document.getElementById('statFilterModal');
        if (statModalEl) {
            const statInst = bootstrap.Modal.getInstance(statModalEl);
            if (statInst) statInst.hide();
        }

        const $link   = $(this);
        const appId   = $link.data('app-id');
        const bizName = $link.data('business') || '';
        if (!appId) return;

        const frame   = document.getElementById('viewAppFrame');
        const loader  = document.getElementById('viewAppLoader');
        const modalEl = document.getElementById('viewApplicationModal');
        if (!frame || !loader || !modalEl) return;

        // Reset loader to the spinner before every open
        loader.innerHTML =
            '<div class="spinner-border text-secondary" role="status"></div>' +
            '<p class="text-muted small mt-2 mb-0">Loading application…</p>';
        loader.style.display = 'flex';
        loader.classList.remove('d-none');

        // Title
        $('#viewAppTitle').text(bizName ? ('Application — ' + bizName) : 'Application Details');

        // Reset state
        frame.style.display = 'none';
        frame.src = 'about:blank';

        // Set the new-tab fallback link (no embed param)
        $('#viewAppOpenNew').attr('href', 'view_application.php?id=' + encodeURIComponent(appId));

        // Detect load timeout (5s) → show fallback with explanation
        let loaded = false;
        const timeoutId = setTimeout(() => {
            if (loaded) return;
            loader.innerHTML =
                '<div class="text-center p-4">' +
                    '<i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:2rem;"></i>' +
                    '<p class="text-muted small mt-3 mb-2">This application could not be displayed inline.</p>' +
                    '<p class="text-muted small mb-3">It may require you to log in again, or the page is blocked from embedding.</p>' +
                '</div>';
        }, 5000);

        frame.onload = function () {
            loaded = true;
            clearTimeout(timeoutId);
            loader.style.display = 'none';
            frame.style.display = 'block';
            frame.onload = null;
        };
        frame.src = 'view_application.php?id=' + encodeURIComponent(appId) + '&embed=1';

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    // Ensure the loader is restored every time the modal is opened fresh
    $('#viewApplicationModal').on('hidden.bs.modal', function () {
        const loader = document.getElementById('viewAppLoader');
        if (loader) {
            loader.innerHTML =
                '<div class="spinner-border text-secondary" role="status"></div>' +
                '<p class="text-muted small mt-2 mb-0">Loading application…</p>';
        }
    });

    // Stop any iframe activity when the modal is dismissed
    $('#viewApplicationModal').on('hidden.bs.modal', function () {
        const frame = document.getElementById('viewAppFrame');
        if (frame) frame.src = 'about:blank';
    });

    const scheduleApptModalEl = document.getElementById('scheduleApptModal');
    if (scheduleApptModalEl) {
        bootstrap.Modal.getOrCreateInstance(scheduleApptModalEl);
    }

    // ---------- DATATABLES ----------
    var table = $('#recentAppsTable');
    if (table.find('tbody tr').length > 1 || (table.find('tbody tr').length === 1 && table.find('tbody tr td').length > 1 && !table.find('tbody tr td[colspan]').length)) {
        table.DataTable({
            pageLength: 5, lengthMenu: [[5,10,25],[5,10,25]],
            language: { search:"Search:", lengthMenu:"Show _MENU_ entries", info:"Showing _START_ to _END_ of _TOTAL_ applications", paginate:{ previous:"<", next:">" } },
            order: [[2,'desc']], columnDefs:[{ orderable:false, targets:5 }]
        });
    } else { table.DataTable({ searching:false, paging:false, info:false, ordering:false }); }

    var payTable = $('#paymentsHistoryTable');
    var payRows = payTable.find('tbody tr').length;
    var isPayEmpty = (payRows === 1 && payTable.find('tbody tr td[colspan]').length > 0);
    if (payRows > 0 && !isPayEmpty) {
        payTable.DataTable({
            pageLength: 5, lengthMenu: [[5,10,25,50],[5,10,25,50]],
            language: { search:"Search payments:", lengthMenu:"Show _MENU_ entries", info:"Showing _START_ to _END_ of _TOTAL_ payments", emptyTable:"No payment records.", paginate:{ previous:"<", next:">" } },
            order: [[0,'desc']], columnDefs:[{ orderable:false, targets:[6] }]
        });
    } else { payTable.DataTable({ searching:false, paging:false, info:false, ordering:false }); }

    // ============================================================
    // STAT CARD → MODAL POPUP
    // ============================================================
    const MODAL_APPS = <?php echo json_encode($modalAppsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    const STAT_CONFIG = {
        all:             { title: 'All applications',                  icon: 'bi-collection-fill', statuses: null },
        under_review:    { title: 'Applications in review',            icon: 'bi-hourglass-split', statuses: ['under_review'] },
        pending_payment: { title: 'Applications awaiting payment',     icon: 'bi-credit-card',     statuses: ['pending_payment'] },
        completed:       { title: 'Approved or verified applications', icon: 'bi-patch-check-fill',statuses: ['approved', 'claimed', 'verified'] }
    };

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function renderStatModal(key) {
        const cfg = STAT_CONFIG[key];
        if (!cfg) return;

        const apps = MODAL_APPS.filter(a => !cfg.statuses || cfg.statuses.indexOf(a.status) !== -1);

        $('#statModalTitle').text(cfg.title);
        $('#statModalCount').text(apps.length + (apps.length === 1 ? ' item' : ' items'));

        const tbody = document.getElementById('statModalTableBody');
        const empty = document.getElementById('statModalEmpty');
        const wrap  = document.getElementById('statModalTableWrap');
        if (!tbody || !empty || !wrap) return;

        if (apps.length === 0) {
            tbody.innerHTML = '';
            empty.style.display = 'block';
            wrap.style.display  = 'none';
            return;
        }

        empty.style.display = 'none';
        wrap.style.display  = 'block';

        const rows = apps.map(a => {
            const typeClass = a.application_type === 'New' ? 'type-new' : 'type-renewal';
            const expiryCell = a.has_expiry
                ? escapeHtml(a.expiry_display) +
                  (a.expiry_note ? '<div class="small text-muted">' + escapeHtml(a.expiry_note) + '</div>' : '')
                : '<span class="text-muted">—</span>';

            return `
                <tr>
                    <td>
                        <div class="business-name">${escapeHtml(a.business_name)}</div>
                        <div class="business-ref">${escapeHtml(a.reference_no)}</div>
                    </td>
                    <td><span class="type-pill ${typeClass}">${escapeHtml(a.application_type)}</span></td>
                    <td>${escapeHtml(a.date_filed)}</td>
                    <td>${expiryCell}</td>
                    <td><span class="status-pill ${escapeHtml(a.status_class)}">${escapeHtml(a.status_label)}</span></td>
                    <td>
                        <a href="view_application.php?id=${a.id}"
                           class="view-link view-app-link"
                           data-app-id="${a.id}"
                           data-business="${escapeHtml(a.business_name)}">View</a>
                    </td>
                </tr>
            `;
        }).join('');

        tbody.innerHTML = rows;
    }

    function openStatModal(key) {
        if (!STAT_CONFIG[key]) return;
        renderStatModal(key);
        const el = document.getElementById('statFilterModal');
        if (el) bootstrap.Modal.getOrCreateInstance(el).show();
    }

    // Click + keyboard on stat cards
    $(document).on('click', '.stat-card', function () {
        openStatModal(String($(this).data('filter')));
    });
    $(document).on('keydown', '.stat-card', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openStatModal(String($(this).data('filter')));
        }
    });

    // Schedule button in the "verified" alert bar
    $(document).on('click', '#scheduleApptPromptBtn', function () {
        if (!scheduleApptModalEl) return;
        bootstrap.Modal.getOrCreateInstance(scheduleApptModalEl).show();
    });

    // "Schedule pickup" link inside the recent applications table row
    $(document).on('click', '.schedule-from-row', function (e) {
        e.preventDefault();
        if (!scheduleApptModalEl) return;
        bootstrap.Modal.getOrCreateInstance(scheduleApptModalEl).show();
    });

    // ============================================================
    // APPOINTMENT SCHEDULING
    // ============================================================
    const dashHolidays = [
        "2026-01-01","2026-04-09","2026-05-01","2026-06-12","2026-08-21",
        "2026-08-31","2026-11-30","2026-12-25","2026-12-30",
        "2026-04-01","2026-04-02","2026-04-03",
        "2026-02-25","2026-04-10","2026-11-02","2026-12-24","2026-12-31"
    ];

    function dashIsWeekendOrHoliday(dateString) {
        const d = new Date(dateString);
        const day = d.getDay();
        return day === 0 || day === 6 || dashHolidays.includes(dateString);
    }

    function dashGenSlots() {
        const slots = [];
        for (let m = 8*60; m <= 17*60; m += 30) {
            if (m >= 12*60 && m < 13*60) continue;
            let h = Math.floor(m/60), min = m%60;
            const ampm = h >= 12 ? 'PM' : 'AM';
            let h12 = h % 12 || 12;
            slots.push(`${h12}:${min === 0 ? '00' : min} ${ampm}`);
        }
        return slots;
    }

    let dashBookedSlots = [];

    function dashRenderSlots() {
        const container = document.getElementById('dashTimeSlotContainer');
        if (!container) return;
        container.innerHTML = '';
        dashGenSlots().forEach(slot => {
            const isBooked = dashBookedSlots.includes(slot);
            const btn = document.createElement('span');
            btn.textContent = slot;
            btn.className = 'time-slot' + (isBooked ? ' booked' : '');
            if (!isBooked) {
                btn.addEventListener('click', function() {
                    container.querySelectorAll('.time-slot').forEach(el => el.classList.remove('selected'));
                    this.classList.add('selected');
                    document.getElementById('dashSelectedSlot').value = slot;
                    dashUpdateSummary();
                });
            }
            container.appendChild(btn);
        });
    }

    function dashUpdateSummary() {
        const date = document.getElementById('dashApptDate').value;
        const time = document.getElementById('dashSelectedSlot').value;
        const el = document.getElementById('dashApptSummary');
        if (date && time) {
            el.textContent = new Date(date).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'}) + ' at ' + time;
        } else if (date) {
            el.textContent = 'Date selected, please pick a time slot';
        } else {
            el.textContent = 'No appointment selected';
        }
    }

    async function dashFetchBooked(date) {
        try {
            const res = await fetch('get_booked_slots.php?date=' + encodeURIComponent(date));
            const data = await res.json();
            dashBookedSlots = data.booked || [];
            dashRenderSlots();
        } catch(e) { console.warn(e); }
    }

    if (scheduleApptModalEl) {
        scheduleApptModalEl.addEventListener('show.bs.modal', function() {
            const t = new Date(); t.setDate(t.getDate() + 1);
            let d = t.toISOString().split('T')[0];
            while (dashIsWeekendOrHoliday(d)) {
                const x = new Date(d); x.setDate(x.getDate() + 1);
                d = x.toISOString().split('T')[0];
            }
            const inp = document.getElementById('dashApptDate');
            inp.value = d; inp.min = d;
            document.getElementById('dashSelectedSlot').value = '';
            dashUpdateSummary();
            dashFetchBooked(d);
        });
    }

    document.addEventListener('change', function(e) {
        if (e.target.id !== 'dashApptDate') return;
        const val = e.target.value;
        if (dashIsWeekendOrHoliday(val)) {
            Swal.fire({ icon:'warning', title:'Date unavailable', text:'Weekend or holiday — please pick another date.', confirmButtonColor:'#1e293b' });
            return;
        }
        document.getElementById('dashSelectedSlot').value = '';
        dashUpdateSummary();
        dashFetchBooked(val);
    });

    document.addEventListener('click', async function(e) {
        if (!e.target.closest('#confirmDashApptBtn')) return;

        const date = document.getElementById('dashApptDate').value;
        const time = document.getElementById('dashSelectedSlot').value;
        const purpose = document.getElementById('dashApptPurpose') ? document.getElementById('dashApptPurpose').value : 'Claim Business Permit';

        if (!date || !time) {
            Swal.fire({ icon:'warning', title:'Incomplete', text:'Please select date and time slot.', confirmButtonColor:'#1e293b' });
            return;
        }

        const btn = e.target.closest('#confirmDashApptBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Submitting...';

        try {
            const res = await fetch('schedule_appointment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ date, time, purpose })
            });

            const raw = await res.text();
            let data = null;
            try { data = JSON.parse(raw); }
            catch (parseErr) {
                console.error('Non-JSON response from server:', raw);
                await Swal.fire({
                    icon: 'error',
                    title: 'Server error (' + res.status + ')',
                    html: '<pre style="text-align:left;font-size:11px;max-height:300px;overflow:auto;background:#f8f9fa;padding:10px;border-radius:6px;">'
                        + raw.replace(/</g, '&lt;').substring(0, 3000)
                        + '</pre>',
                    width: 700,
                    confirmButtonColor: '#1e293b'
                });
                return;
            }

            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: 'Submitted for Approval',
                    html: `Your pickup appointment is now <strong>pending BPLO approval</strong>.<br>
                        You'll be notified once confirmed.<br><br>
                        <small class="text-muted">${date} at ${time}</small>`,
                    confirmButtonColor: '#1e293b'
                });
                location.reload();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Cannot book',
                    html: data.error || 'Please try again.',
                    confirmButtonColor: '#1e293b'
                });
            }

        } catch (err) {
            console.error('Network error:', err);
            Swal.fire({
                icon: 'error',
                title: 'Network error',
                text: err.message || 'Please try again.',
                confirmButtonColor: '#1e293b'
            });
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Submit for Approval';
        }
    });

    document.addEventListener('click', async function(e) {
        if (!e.target.closest('#cancelApptBtn')) return;
        const result = await Swal.fire({
            icon:'warning',
            title:'Cancel Appointment?',
            text:'Your pending appointment will be cancelled.',
            showCancelButton:true,
            confirmButtonColor:'#dc3545',
            cancelButtonColor:'#6c757d',
            confirmButtonText:'Yes, cancel it',
            cancelButtonText:'Keep it'
        });
        if (!result.isConfirmed) return;
        try {
            const res = await fetch('cancel_appointment.php', { method:'POST' });
            const data = await res.json();
            if (data.success) {
                await Swal.fire({ icon:'success', title:'Cancelled', timer:1200, showConfirmButton:false });
                location.reload();
            } else {
                Swal.fire({ icon:'error', title:'Error', text:data.error || 'Failed.', confirmButtonColor:'#1e293b' });
            }
        } catch(err) {
            Swal.fire({ icon:'error', title:'Network error', text:'Please try again.', confirmButtonColor:'#1e293b' });
        }
    });

    // ---------- Discard draft ----------
    $('#discardDraftBtn').on('click', function(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Discard draft?',
            text: 'This will permanently delete your incomplete draft application.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, discard draft',
            cancelButtonText: 'Cancel'
        }).then((r) => {
            if (r.isConfirmed) {
                window.location.href = 'dashboard.php?discard=1';
            }
        });
    });

    $('#newAppBtn').on('click', function(e) {
        <?php if ($hasDraft): ?>
            e.preventDefault();
            Swal.fire({
                title:'Start new application?', text:'You have an existing draft. Starting a new application will discard the current draft.',
                icon:'question', showCancelButton:true, confirmButtonColor:'#1e293b', cancelButtonColor:'#6c757d',
                confirmButtonText:'Yes, start fresh', cancelButtonText:'Cancel'
            }).then((r)=>{ if(r.isConfirmed) window.location.href=$(this).attr('href'); });
        <?php endif; ?>
    });

    // ---------- RE-UPLOAD ----------
    $(document).on('click', '.reupload-btn', function() {
        const appId = $(this).data('appid');
        const businessName = $(this).data('business');
        let missingDocs = $(this).data('missing');
        if (typeof missingDocs === 'string') { try { missingDocs = JSON.parse(missingDocs); } catch(e) { missingDocs = []; } }
        if (!Array.isArray(missingDocs)) missingDocs = [];

        $('#reuploadAppId').val(appId);
        $('#reuploadBusinessName').text(businessName);
        $('#reuploadAppInfo').removeClass('d-none');

        const container = $('#missingDocsContainer');
        container.empty();
        if (missingDocs.length === 0) {
            container.html('<div class="alert alert-info">No specific documents were requested.</div>');
        } else {
            const docMap = {
                'barangay':'Barangay Clearance','dti_sec':'DTI/SEC – Xerox Copy',
                'rpt':'RPT – Current Year 2026 Payment','engineering':'Engineering',
                'zoning':'Zoning Certification (MPDC)','sanitary':'Sanitary Health Permit (Health Center)',
                'fire_safety':'Fire Safety Certificate 2026'
            };
            missingDocs.forEach(function(key) {
                const label = docMap[key] || key.replace(/_/g,' ').toUpperCase();
                container.append(`
                    <div class="missing-doc-item">
                        <div class="doc-label"><i class="bi bi-file-earmark me-2"></i>${label}</div>
                        <div class="file-input-wrapper">
                            <input type="file" class="form-control" name="doc_${key}" accept=".pdf,.jpg,.jpeg,.png" required>
                            <small class="text-muted">PDF, JPG, PNG (max 5MB)</small>
                        </div>
                    </div>
                `);
            });
        }
    });

    $('#reuploadForm').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const appId = $('#reuploadAppId').val();
        if (!appId) { Swal.fire('Error','Application ID missing.','error'); return; }

        let hasFile = false;
        $(this).find('input[type="file"]').each(function() { if ($(this)[0].files.length > 0) hasFile = true; });
        if (!hasFile) { Swal.fire('Missing files','Please select at least one file.','warning'); return; }

        $('#submitReuploadBtn').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Uploading...');

        $.ajax({
            url:'resubmit_rejected.php', type:'POST', data:formData,
            processData:false, contentType:false, dataType:'json',
            success: function(response) {
                $('#submitReuploadBtn').prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> Submit for Review');
                if (response.success) {
                    Swal.fire({ icon:'success', title:'Submitted!', html: response.message || 'Documents re-uploaded.', confirmButtonColor:'#1e293b' }).then(()=>location.reload());
                } else {
                    Swal.fire('Error', response.message || 'Failed.', 'error');
                }
            },
            error: function(xhr) {
                $('#submitReuploadBtn').prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> Submit for Review');
                let msg = 'An unexpected error occurred.';
                try { const j = JSON.parse(xhr.responseText); if (j.message) msg = j.message; } catch(e){}
                Swal.fire('Error', msg, 'error');
            }
        });
    });

    // ---------- PAYMENT RECEIPT ----------
    $(document).on('click', '.view-payment-history', function() {
        const btn = $(this);
        const paymentId   = btn.data('payment-id') || '—';
        const ref         = btn.data('ref') || '—';
        const description = btn.data('description') || 'Business Permit Fee';
        const amount      = parseFloat(btn.data('amount')) || 0;
        const currency    = String(btn.data('currency') || 'PHP').toUpperCase();
        const status      = btn.data('status-label') || '—';
        const source      = btn.data('source') || 'PayPal';
        const paidAt      = btn.data('paid-at') || '—';
        const business    = btn.data('business') || 'N/A';
        const payerName   = btn.data('payer-name') || <?php echo json_encode($user_name); ?>;
        const payerEmail  = btn.data('payer-email') || '';
        const appId       = parseInt(btn.data('app-id')) || 0;

        const symbol = currency === 'PHP' ? '₱'
                    : currency === 'USD' ? '$'
                    : currency + ' ';

        const payerDisplay = payerName
            ? (payerEmail ? payerName + ' <' + payerEmail + '>' : payerName)
            : (payerEmail || '—');

        $('#prm-receipt-no').text(
            ref !== '—' ? ref : 'PP-' + String(paymentId).substring(0, 10).toUpperCase()
        );
        $('#prm-transaction-id').text(paymentId);
        $('#prm-date').text(paidAt);
        $('#prm-method').text('PayPal');
        $('#prm-status').text(status);
        $('#prm-payer').text(payerDisplay);
        $('#prm-business').text(business);
        $('#prm-description').text(description);
        $('#prm-amount-row').text(symbol + amount.toFixed(2) + ' ' + currency);
        $('#prm-total').text(symbol + amount.toFixed(2) + ' ' + currency);

        const viewLink = $('#prm-view-app-link');
        if (appId > 0) viewLink.attr('href', 'view_application.php?id=' + appId).show();
        else viewLink.hide();

        new bootstrap.Modal(document.getElementById('paymentReceiptModal')).show();
    });

    $('#prmPrintBtn').on('click', function() {
        const receiptEl = document.querySelector('#prmBody .prm-receipt');
        if (!receiptEl) return;
        const win = window.open('', '_blank', 'width=800,height=900');
        if (!win) { alert('Please allow pop-ups.'); return; }
        const styles = `
            body{font-family:'Courier New',Courier,monospace;padding:20px;color:#000;background:#fff;}
            .prm-receipt{max-width:700px;margin:0 auto;padding:25px 30px;}
            .prm-header{display:flex;align-items:center;border-bottom:2px solid #000;padding-bottom:12px;margin-bottom:12px;}
            .prm-logo{width:70px;text-align:center;margin-right:15px;}
            .prm-logo i{font-size:2.5rem;color:#1e293b;}
            .prm-title{flex:1;text-align:center;padding-right:70px;}
            .prm-title h3{font-family:'Times New Roman',serif;font-size:1.4rem;font-weight:700;margin:0;letter-spacing:2px;}
            .prm-title p{font-family:'Times New Roman',serif;font-size:.8rem;margin:3px 0 0 0;}
            .prm-meta{font-size:.75rem;border-bottom:1px dashed #999;padding-bottom:10px;margin-bottom:12px;text-align:center;color:#555;letter-spacing:1px;}
            .prm-row{display:flex;padding:5px 0;font-size:.85rem;border-bottom:1px solid #eee;}
            .prm-row .lbl{width:130px;font-weight:bold;flex-shrink:0;}
            .prm-row .val{flex:1;word-break:break-word;}
            .prm-table{width:100%;border-collapse:collapse;font-size:.85rem;margin:15px 0 10px;}
            .prm-table th,.prm-table td{border:1px solid #000;padding:8px 10px;}
            .prm-table th{background:#f5f5f5;text-align:left;font-weight:700;font-size:.75rem;text-transform:uppercase;}
            .prm-table td.right{text-align:right;}
            .prm-table .total-row td{background:#1e293b;color:#fff;font-weight:700;font-size:.95rem;}
            .prm-footer{margin-top:15px;padding-top:10px;border-top:1px dashed #999;font-size:.7rem;color:#666;text-align:center;}
            .prm-signature{display:flex;justify-content:flex-end;margin-top:25px;}
            .prm-signature .line{width:200px;border-top:1px solid #000;text-align:center;padding-top:4px;font-size:.75rem;font-weight:bold;}
        `;
        win.document.write('<!DOCTYPE html><html><head><title>Payment Receipt</title><style>' + styles + '</style></head><body>');
        win.document.write(receiptEl.outerHTML);
        win.document.write('<script>window.onload = function(){ window.print(); }<\/script></body></html>');
        win.document.close();
    });

    // ---------- QR SCANNER ----------
    let html5QrCode = null;
    let lastScanText = '';
    let lastScanTime = 0;
    let isRedirecting = false;
    let currentFacingMode = "environment";
    const SCAN_DEBOUNCE_MS = 1500;
    const statusDiv = document.getElementById('scanner-status');

    function startScanner() {
        if (html5QrCode) {
            return html5QrCode.stop()
                .then(() => { try { html5QrCode.clear(); } catch(e){} html5QrCode = null; })
                .catch(() => { html5QrCode = null; })
                .then(() => startScannerInner());
        }
        return startScannerInner();
    }

    function startScannerInner() {
        lastScanText = ''; lastScanTime = 0; isRedirecting = false;
        html5QrCode = new Html5Qrcode("scanner-container");
        const config = { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 };
        html5QrCode.start({ facingMode: currentFacingMode }, config, onScanSuccess, onScanError)
            .then(() => { statusDiv.textContent = "Camera is active. Point at the QR code."; })
            .catch((err) => {
                statusDiv.textContent = "Error: " + err;
                Swal.fire({ icon:'warning', title:'Camera not available', text:'Enter reference manually or use Take Photo / Upload Image.', confirmButtonColor:'#1e293b' });
            });
    }

    function stopScanner() {
        if (html5QrCode) {
            const inst = html5QrCode;
            html5QrCode = null;
            return inst.stop().then(() => { try { inst.clear(); } catch(e){} }).catch(() => {});
        }
        statusDiv.textContent = "Scanner stopped.";
        return Promise.resolve();
    }

    function extractPermitRef(decodedText) {
        if (!decodedText || typeof decodedText !== 'string') return null;
        let ref = decodedText.trim();
        if (ref.includes('ref=')) {
            try {
                const queryString = ref.split('?')[1];
                if (queryString) {
                    const urlParams = new URLSearchParams(queryString);
                    const extracted = urlParams.get('ref');
                    if (extracted) ref = extracted.trim();
                }
            } catch (e) {}
        }
        if (/^BP-\d{4}-\d{4,6}$/i.test(ref)) return ref.toUpperCase();
        if (/^\d{4}-\d{2}-\d{3,4}$/.test(ref)) return ref;
        return null;
    }

    function onScanSuccess(decodedText) {
        if (isRedirecting) return;
        const trimmed = (decodedText || '').trim();
        const now = Date.now();
        if (trimmed === lastScanText && (now - lastScanTime) < SCAN_DEBOUNCE_MS) return;
        lastScanText = trimmed; lastScanTime = now;
        const ref = extractPermitRef(decodedText);
        if (ref) {
            isRedirecting = true;
            stopScanner();
            if (statusDiv) statusDiv.innerHTML = '<span style="color:var(--green);"><i class="bi bi-check-circle"></i> Valid permit detected. Redirecting...</span>';
            window.location.href = 'view_application.php?ref=' + encodeURIComponent(ref);
        } else {
            if (statusDiv) statusDiv.innerHTML = '<span style="color:var(--red);"><i class="bi bi-x-circle"></i> Invalid QR</span>';
            setTimeout(() => { if (!isRedirecting && statusDiv) statusDiv.textContent = "Camera is active."; }, SCAN_DEBOUNCE_MS);
        }
    }

    function onScanError(errorMessage) {}

    $('#verifyModal').on('shown.bs.modal', function() { currentFacingMode = "environment"; startScanner(); });
    $('#verifyModal').on('hidden.bs.modal', function() { stopScanner(); });
    $('#verifyModal').on('show.bs.modal', function() {
        $('#manualRef').val('');
        lastScanText = ''; lastScanTime = 0; isRedirecting = false;
    });

    $(document).on('click', '#switchCamBtn', function() {
        currentFacingMode = (currentFacingMode === 'environment') ? 'user' : 'environment';
        if (statusDiv) statusDiv.textContent = 'Switching camera…';
        stopScanner().then(() => startScanner());
    });

    function loadImageFromFile(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = (e) => { URL.revokeObjectURL(url); reject(e); };
            img.src = url;
        });
    }
    function cloneImageData(id) {
        try { return new ImageData(new Uint8ClampedArray(id.data), id.width, id.height); }
        catch (e) {
            const c = document.createElement('canvas');
            c.width = id.width; c.height = id.height;
            const cx = c.getContext('2d'); cx.putImageData(id, 0, 0);
            return cx.getImageData(0, 0, id.width, id.height);
        }
    }
    function applyGrayscaleContrast(id, contrast) {
        const d = id.data; const intercept = 128 * (1 - contrast);
        for (let i = 0; i < d.length; i += 4) {
            let g = 0.299 * d[i] + 0.587 * d[i+1] + 0.114 * d[i+2];
            g = g * contrast + intercept;
            g = g < 0 ? 0 : g > 255 ? 255 : g;
            d[i] = d[i+1] = d[i+2] = g;
        }
        return id;
    }
    function applyBinarize(id, threshold) {
        const d = id.data;
        for (let i = 0; i < d.length; i += 4) {
            const g = 0.299 * d[i] + 0.587 * d[i+1] + 0.114 * d[i+2];
            const v = g > threshold ? 255 : 0;
            d[i] = d[i+1] = d[i+2] = v;
        }
        return id;
    }

    async function decodeQrFromImage(file) {
        const img = await loadImageFromFile(file);
        let detector = null;
        try {
            if ('BarcodeDetector' in window) {
                const fmts = await BarcodeDetector.getSupportedFormats();
                if (fmts && fmts.includes('qr_code')) detector = new BarcodeDetector({ formats: ['qr_code'] });
            }
        } catch (e) {}
        const longest = Math.max(img.width, img.height);
        const baseScale = longest > 1600 ? 1600 / longest : 1;
        const srcCanvas = document.createElement('canvas');
        srcCanvas.width  = Math.max(1, Math.round(img.width  * baseScale));
        srcCanvas.height = Math.max(1, Math.round(img.height * baseScale));
        srcCanvas.getContext('2d').drawImage(img, 0, 0, srcCanvas.width, srcCanvas.height);
        const workCanvas = document.createElement('canvas');
        const workCtx = workCanvas.getContext('2d', { willReadFrequently: true });
        const rotations = [0, 90, 180, 270];
        const scaleFactors = [1, 1.5, 0.75, 2];
        for (const rot of rotations) {
            const rotCanvas = document.createElement('canvas');
            const rotCtx = rotCanvas.getContext('2d', { willReadFrequently: true });
            if (rot === 0 || rot === 180) { rotCanvas.width = srcCanvas.width; rotCanvas.height = srcCanvas.height; }
            else { rotCanvas.width = srcCanvas.height; rotCanvas.height = srcCanvas.width; }
            rotCtx.translate(rotCanvas.width / 2, rotCanvas.height / 2);
            rotCtx.rotate(rot * Math.PI / 180);
            rotCtx.drawImage(srcCanvas, -srcCanvas.width / 2, -srcCanvas.height / 2);
            for (const s of scaleFactors) {
                const w = Math.round(rotCanvas.width * s);
                const h = Math.round(rotCanvas.height * s);
                if (w < 100 || h < 100 || w > 2500 || h > 2500) continue;
                workCanvas.width = w; workCanvas.height = h;
                workCtx.drawImage(rotCanvas, 0, 0, w, h);
                if (detector) {
                    try { const codes = await detector.detect(workCanvas); if (codes && codes.length) return codes[0].rawValue; } catch (e) {}
                }
                const orig = workCtx.getImageData(0, 0, w, h);
                try { const c = jsQR(orig.data, w, h, { inversionAttempts: 'attemptBoth' }); if (c && c.data) return c.data; } catch (e) {}
                const gcId = cloneImageData(orig); applyGrayscaleContrast(gcId, 1.6);
                try { const c = jsQR(gcId.data, w, h, { inversionAttempts: 'attemptBoth' }); if (c && c.data) return c.data; } catch (e) {}
                for (const t of [100, 128, 160, 190]) {
                    const bId = cloneImageData(orig); applyBinarize(bId, t);
                    try { const c = jsQR(bId.data, w, h, { inversionAttempts: 'attemptBoth' }); if (c && c.data) return c.data; } catch (e) {}
                }
            }
        }
        return null;
    }

    function handleQrFileInput(inputId) {
        const inputEl = document.getElementById(inputId);
        if (!inputEl) return;
        inputEl.addEventListener('change', async function(e) {
            const file = e.target.files && e.target.files[0];
            e.target.value = '';
            if (!file) return;
            if (statusDiv) statusDiv.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Reading image…</span>';
            await stopScanner();
            try {
                const decoded = await decodeQrFromImage(file);
                if (decoded) {
                    if (statusDiv) statusDiv.innerHTML = '<span style="color:var(--green);"><i class="bi bi-check-circle"></i> QR detected. Redirecting…</span>';
                    onScanSuccess(decoded);
                } else {
                    if (statusDiv) statusDiv.innerHTML = '<span style="color:var(--red);"><i class="bi bi-x-circle"></i> No QR code detected.</span>';
                    Swal.fire({ icon:'warning', title:'No QR detected', text:'Please try a clearer/closer photo.', confirmButtonColor:'#1e293b' });
                    startScanner();
                }
            } catch (err) { console.error('Decode error:', err); startScanner(); }
        });
    }

    handleQrFileInput('qrCameraInput');
    handleQrFileInput('qrFileInput');

    $(document).on('click', '#takePhotoBtn', function() { document.getElementById('qrCameraInput').click(); });
    $(document).on('click', '#uploadQrBtn', function() { document.getElementById('qrFileInput').click(); });

    $('#manualVerifyBtn').on('click', function() {
        const ref = $('#manualRef').val().trim();
        if (!ref) { Swal.fire({ icon:'warning', title:'Enter a reference', text:'Please enter a valid permit reference number.', confirmButtonColor:'#1e293b' }); return; }
        const isValid = /^BP-\d{4}-\d{4,6}$/i.test(ref) || /^\d{4}-\d{2}-\d{3,4}$/.test(ref);
        if (!isValid) {
            Swal.fire({ icon:'error', title:'Invalid format', html:'Accepted formats:<br><strong>BP-2026-00001</strong><br><strong>2026-08-001</strong>', confirmButtonColor:'#1e293b' });
            return;
        }
        window.location.href = 'view_application.php?ref=' + encodeURIComponent(ref);
    });

    $('#manualRef').on('keypress', function(e) { if (e.which === 13) $('#manualVerifyBtn').click(); });

    // ============================================================
    // EMBEDDED MODALS — Account Settings & Notifications
    // ============================================================
    let accountSettingsUpdated = false;
    let notificationsUpdated   = false;

    function matchesPage(href, page) {
        if (!href) return false;
        const clean = href.split('#')[0].split('?')[0];
        return clean === page || clean.endsWith('/' + page);
    }

    function openEmbeddedModal(opts) {
        const modalEl = document.getElementById(opts.modalId);
        const frame   = document.getElementById(opts.frameId);
        const loader  = document.getElementById(opts.loaderId);
        if (!modalEl || !frame || !loader) return;

        loader.innerHTML =
            '<div class="spinner-border text-secondary" role="status"></div>' +
            '<p class="text-muted small mt-2 mb-0">' + opts.loadingText + '</p>';
        loader.style.display = 'flex';
        frame.style.display  = 'none';
        frame.src = 'about:blank';

        let loaded = false;
        const timeoutId = setTimeout(() => {
            if (loaded) return;
            loader.innerHTML =
                '<div class="text-center p-4">' +
                    '<i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:2rem;"></i>' +
                    '<p class="text-muted small mt-3 mb-2">This page could not be displayed inline.</p>' +
                '</div>';
        }, 5000);

        frame.onload = function () {
            loaded = true;
            clearTimeout(timeoutId);
            loader.style.display = 'none';
            frame.style.display  = 'block';
            frame.onload = null;
        };

        frame.src = opts.url;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    // Intercept sidebar / header links → open in modal instead
    $(document).on('click', 'a', function (e) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.which === 2) return;

        const href = $(this).attr('href');
        if (!href || href === '#' || href.startsWith('javascript:')) return;

        if (matchesPage(href, 'account_settings.php')) {
            e.preventDefault();
            openEmbeddedModal({
                modalId:     'accountSettingsModal',
                frameId:     'accountSettingsFrame',
                loaderId:    'accountSettingsLoader',
                url:         'account_settings.php?embed=1',
                fallbackUrl: 'account_settings.php',
                loadingText: 'Loading account settings…'
            });
        } else if (matchesPage(href, 'notifications.php')) {
            e.preventDefault();
            openEmbeddedModal({
                modalId:     'notificationsModal',
                frameId:     'notificationsFrame',
                loaderId:    'notificationsLoader',
                url:         'notifications.php?embed=1',
                fallbackUrl: 'notifications.php',
                loadingText: 'Loading notifications…'
            });
        }
    });

    // Messages from embedded pages
    window.addEventListener('message', function (ev) {
        const data = ev.data || {};

        if (data.type === 'account-settings-updated') {
            accountSettingsUpdated = true;
            if (data.name) {
                const el = document.getElementById('welcomeName');
                if (el) el.textContent = String(data.name).trim().split(' ')[0];
            }
        }

        if (data.type === 'notifications-updated') {
            notificationsUpdated = true;
        }

        if (data.type === 'notifications-close-modal') {
            const modalEl = document.getElementById('notificationsModal');
            if (modalEl) {
                const inst = bootstrap.Modal.getInstance(modalEl);
                if (inst) inst.hide();
            }
        }
    });

    $('#accountSettingsModal').on('hidden.bs.modal', function () {
        const frame = document.getElementById('accountSettingsFrame');
        if (frame) frame.src = 'about:blank';
        if (accountSettingsUpdated) {
            accountSettingsUpdated = false;
            location.reload();
        }
    });

    $('#notificationsModal').on('hidden.bs.modal', function () {
        const frame = document.getElementById('notificationsFrame');
        if (frame) frame.src = 'about:blank';
        if (notificationsUpdated) {
            notificationsUpdated = false;
            location.reload();
        }
    });
});
</script>
</body>
</html>