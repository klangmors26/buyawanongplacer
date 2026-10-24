<?php
session_start();
require_once 'config/connect.php';

header('Content-Type: application/json; charset=utf-8');

/* ---------- configurable permit year ---------- */
define('PERMIT_VALIDITY_YEAR', 2026);   // <-- change to your target year

/* ---------- helpers ---------- */
function formatDateNice($d) {
    if (empty($d)) return '';
    return date('M j, Y', strtotime($d));
}
function formatDateTimeNice($d) {
    if (empty($d)) return '';
    return date('F j, Y — g:i A', strtotime($d));
}
function getExpiryStatus($expiry_date) {
    if (empty($expiry_date)) return ['label' => 'N/A', 'class' => 'secondary'];
    $now    = new DateTime();
    $expiry = new DateTime($expiry_date);
    $days   = (int)$now->diff($expiry)->format('%r%a');
    if ($days < 0)   return ['label' => 'Expired',      'class' => 'danger'];
    if ($days <= 30) return ['label' => 'Expires soon', 'class' => 'warning'];
    return ['label' => 'Valid', 'class' => 'success'];
}

/* ---------- input ---------- */
$ref = isset($_GET['ref']) ? trim($_GET['ref']) : '';

if ($ref !== '' && (strpos($ref, 'ref=') !== false || preg_match('#^https?://#i', $ref))) {
    if (preg_match('/[?&]ref=([^&]+)/', $ref, $m)) {
        $ref = urldecode($m[1]);
    }
}

if ($ref === '') {
    echo json_encode(['success' => false, 'error' => 'No reference number provided.']);
    exit;
}

/* ---------- lookup ---------- */
$sql = "SELECT 
            ba.id,
            ba.reference_no,
            ba.status,
            ba.submitted_at,
            ba.application_date,
            ba.created_at,
            ba.expiry_date,
            bid.business_name,
            bid.trade_name,
            bid.first_name,
            bid.middle_name,
            bid.last_name,
            atd.application_type,
            atd.payment_mode,
            atd.tin_no,
            bad.business_address,
            bad.email,
            bad.mobile
        FROM business_applications ba
        LEFT JOIN business_info_data bid    ON ba.id = bid.application_id
        LEFT JOIN application_type_data atd ON ba.id = atd.application_id
        LEFT JOIN business_address_data bad ON ba.id = bad.application_id
        WHERE ba.reference_no = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $ref);
$stmt->execute();
$application = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$application) {
    echo json_encode([
        'success' => false,
        'error'   => 'No permit or application matches reference ' . $ref . '.'
    ]);
    exit;
}

$status        = strtolower($application['status']);
$applicationId = (int)$application['id'];

/* ---------- payment ---------- */
$paymentConfirmed = false;
$paymentDate = 'Pending';

$stmt = $conn->prepare("SELECT * FROM payments WHERE application_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i', $applicationId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($payment && $payment['status'] === 'paid') {
    $paymentConfirmed = true;
    $paymentDate = !empty($payment['paid_at']) ? formatDateTimeNice($payment['paid_at']) : 'Completed';
} elseif (in_array($status, ['payment_confirmed','paid','under_review','approved','claimed','verified'])) {
    $paymentConfirmed = true;
    $paymentDate = 'Completed';
}

/* ---------- step states ---------- */
$submittedState = !empty($application['submitted_at']) ? 'completed' : 'active';

$docsVerified = !in_array($status, ['submitted']);

$underReview = in_array($status, ['under_review','approved','claimed','verified']);
$isRejected  = in_array($status, ['rejected','cancelled']);
$isIssued    = in_array($status, ['approved','claimed','verified']);

/* active step index (0..4) */
$activeIndex = 0;
if ($submittedState !== 'completed') $activeIndex = 0;
elseif (!$docsVerified)              $activeIndex = 1;
elseif (!$paymentConfirmed)          $activeIndex = 2;
elseif (!$underReview)               $activeIndex = 3;
else                                 $activeIndex = 4;

/* ---------- steps array ---------- */
$steps = [
    [
        'key'   => 'submitted',
        'label' => 'Submitted',
        'icon'  => 'bi-file-earmark-text',
        'date'  => !empty($application['submitted_at'])
                    ? formatDateTimeNice($application['submitted_at'])
                    : 'Pending',
        'state' => $submittedState,
    ],
    [
        'key'   => 'verified',
        'label' => 'Documents Verified',
        'icon'  => 'bi-clipboard-check',
        'date'  => $docsVerified ? 'Completed' : 'Pending',
        'state' => $docsVerified ? 'completed' : ($activeIndex === 1 ? 'active' : 'pending'),
    ],
    [
        'key'   => 'payment',
        'label' => 'Payment Confirmed',
        'icon'  => 'bi-credit-card-2-front',
        'date'  => $paymentDate,
        'state' => $paymentConfirmed ? 'completed' : ($activeIndex === 2 ? 'active' : 'pending'),
    ],
    [
        'key'   => 'review',
        'label' => 'Under Review',
        'icon'  => 'bi-search',
        'date'  => $underReview ? 'In progress' : 'Pending',
        'state' => $underReview ? 'completed' : ($activeIndex === 3 ? 'active' : 'pending'),
    ],
    [
        'key'   => 'issued',
        'label' => 'Permit Issued',
        'icon'  => 'bi-patch-check-fill',
        'date'  => $isIssued ? 'Completed' : ($isRejected ? 'Not approved' : 'Pending'),
        'state' => $isRejected ? 'rejected' : ($isIssued ? 'completed' : ($activeIndex === 4 ? 'active' : 'pending')),
    ],
];

/* ---------- vertical timeline ---------- */
$timeline = [];
$timeline[] = [
    'step' => 'Application submitted',
    'date' => !empty($application['submitted_at']) ? formatDateTimeNice($application['submitted_at']) : 'Pending',
    'state'=> !empty($application['submitted_at']) ? 'completed' : 'active',
];
$timeline[] = [
    'step' => 'Documents verified',
    'date' => $docsVerified ? 'Completed' : 'Pending',
    'state'=> $docsVerified ? 'completed' : 'pending',
];
$timeline[] = [
    'step' => 'Payment confirmed',
    'date' => $paymentDate,
    'state'=> $paymentConfirmed ? 'completed' : 'pending',
];
$timeline[] = [
    'step' => 'Under review by licensing office',
    'date' => $underReview ? 'In progress' : 'Pending',
    'state'=> $underReview ? 'completed' : 'pending',
];
if ($status === 'verified') {
    $timeline[] = ['step'=>'✅ VERIFIED — Permit ready for issuance','date'=>'Completed','state'=>'verified'];
} elseif ($isRejected) {
    $timeline[] = [
        'step'  => ($status === 'cancelled') ? '❌ CANCELLED' : '❌ REJECTED',
        'date'  => 'Completed',
        'state' => 'rejected'
    ];
} elseif ($isIssued) {
    $timeline[] = ['step'=>'🏆 Permit issued & QR code generated','date'=>'Completed','state'=>'issued'];
} else {
    $timeline[] = ['step'=>'Permit issuance pending','date'=>'Pending','state'=>'pending'];
}

/* ---------- header summary ---------- */
$ownerName = trim(
    ($application['first_name']  ?? '') . ' ' .
    ($application['middle_name'] ?? '') . ' ' .
    ($application['last_name']   ?? '')
);

$statusLabels = [
    'submitted'         => 'Submitted',
    'pending_payment'   => 'Payment pending',
    'payment_confirmed' => 'Payment confirmed',
    'under_review'      => 'Under review',
    'approved'          => 'Approved',
    'claimed'           => 'Claimed',
    'verified'          => 'Verified',
    'rejected'          => 'Rejected',
    'cancelled'         => 'Cancelled',
];

/* ============================================================
   ✅ NORMALIZE EXPIRY DATE
   ----------------------------------------------------------
   Use the DB expiry_date ONLY if its year matches
   PERMIT_VALIDITY_YEAR. Otherwise fall back to Dec 31 of the
   permit year. The same normalized value feeds BOTH the info
   strip ("Expected") and the detail row ("Expiry Date").
   ============================================================ */
$rawExpiry   = $application['expiry_date'] ?? null;
$rawExpiryYr = !empty($rawExpiry) ? (int)date('Y', strtotime($rawExpiry)) : 0;

if (!empty($rawExpiry) && date('m-d', strtotime($rawExpiry)) === '12-31') {
    $canonicalExpiry = $rawExpiry;                          // trust DB (always Dec 31, any year)
} elseif ($rawExpiryYr > 0) {
    $canonicalExpiry = $rawExpiryYr . '-12-31';             // always Dec 31 of that year
} else {
    $canonicalExpiry = PERMIT_VALIDITY_YEAR . '-12-31';     // fall back
}

$expectedDate  = formatDateNice($canonicalExpiry);
$displayExpiry = formatDateNice($canonicalExpiry);
$expiryStatus  = getExpiryStatus($canonicalExpiry);

/* ---------- response ---------- */
echo json_encode([
    'success' => true,
    'data' => [
        'reference_no'     => $application['reference_no'],
        'business_name'    => $application['business_name'] ?: '—',
        'trade_name'       => $application['trade_name'] ?: '',
        'business_address' => $application['business_address'] ?: '',
        'application_type' => $application['application_type'] ?: '—',
        'payment_mode'     => $application['payment_mode'] ?: '—',
        'tin_no'           => $application['tin_no'] ?: '—',
        'email'            => $application['email'] ?: '—',
        'mobile'           => $application['mobile'] ?: '—',
        'owner_name'       => $ownerName ?: '—',
        'status'           => $status,
        'status_label'     => $statusLabels[$status] ?? ucfirst(str_replace('_',' ',$status)),
        'created_at'       => formatDateNice($application['created_at']),
        'submitted_at'     => !empty($application['submitted_at']) ? formatDateTimeNice($application['submitted_at']) : '',

        /* ✅ both use the normalized value now */
        'expiry_date'      => $displayExpiry,
        'expiry_status'    => $expiryStatus,
        'expected_date'    => $expectedDate,

        'active_index'     => $activeIndex,
        'steps'            => $steps,
        'timeline'         => $timeline,
        'qr_url'           => 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($application['reference_no']),
    ]
]);