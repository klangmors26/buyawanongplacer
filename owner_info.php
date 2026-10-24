<?php
session_start();
require_once 'includes/functions.php';
require_once 'config/connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

$appType = $_SESSION['application_type'] ?? 'new';
$isNew = ($appType === 'new');
$isRenewal = ($appType === 'renewal');

$application = getOrCreateDraftApplication($user_id);
$app_id = $application['id'];

// --- Validation: ensure steps 2, 3, 4 are complete ---
$missingStep = getMissingStep($app_id, $conn);
$currentStep = 5;
if ($missingStep > 0 && $missingStep < $currentStep) {
    $stepPages = [
        2 => 'application_type.php',
        3 => 'business_info.php',
        4 => 'business_address.php',
        5 => 'owner_info.php',
        6 => 'additional_info.php',
        7 => 'documents.php'
    ];
    if (isset($stepPages[$missingStep])) {
        header('Location: ' . $stepPages[$missingStep]);
        exit;
    }
}

// Load existing data from owner_info_data table
$existing = null;
$sql = "SELECT * FROM owner_info_data WHERE application_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $app_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'last_name'        => trim($_POST['last_name'] ?? ''),
        'first_name'       => trim($_POST['first_name'] ?? ''),
        'middle_name'      => trim($_POST['middle_name'] ?? ''),
        'owner_address'    => trim($_POST['owner_address'] ?? ''),
        'postal_code'      => trim($_POST['postal_code'] ?? ''),
        'email'            => trim($_POST['email'] ?? ''),
        'telephone'        => trim($_POST['telephone'] ?? ''),
        'mobile'           => trim($_POST['mobile'] ?? ''),
        'emergency_name'   => trim($_POST['emergency_name'] ?? ''),
        'emergency_phone'  => trim($_POST['emergency_phone'] ?? ''),
        'emergency_email'  => trim($_POST['emergency_email'] ?? '')
    ];

    if (empty($data['postal_code'])) $data['postal_code'] = '8407';

    $errors = [];
    if (empty($data['last_name']))  $errors[] = 'Last name is required.';
    if (empty($data['first_name'])) $errors[] = 'First name is required.';
    if (empty($data['owner_address'])) $errors[] = "Owner's address is required.";
    if (empty($data['email']))      $errors[] = 'Email address is required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required.';
    if (empty($data['mobile']))     $errors[] = 'Mobile number is required.';

    if (empty($errors)) {
        if (saveOwnerInfo($app_id, $data)) {
            updateApplicationStep($app_id, 6);
            header('Location: additional_info.php');
            exit;
        } else {
            $errorMsg = "Database error. Please try again.";
        }
    } else {
        $errorMsg = implode('<br>', $errors);
    }
}

// Sidebar steps
$steps = [
    1 => 'Requirements',
    2 => 'Application type',
    3 => 'Business info',
    4 => 'Business address',
    5 => 'Owner info',
    6 => 'Additional info',
    7 => 'Documents',
    8 => 'Review and submit'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Owner Information | <?php echo $isRenewal ? 'Renew' : 'New'; ?> Business Permit | Buyawanong Placer</title>
    
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

    <!-- Leaflet map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        :root {
            --brand-dark: #1e293b;
            --brand-dark-hover: #0f172a;
            --brand-yellow: #fbbf24;
            --bg-light: #f8fafc;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --green: #10b981;
        }

        * { font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-light); color: var(--brand-dark); }

        .back-link {
            color: var(--brand-dark);
            text-decoration: underline;
            text-decoration-color: #cbd5e1;
            text-underline-offset: 3px;
            text-decoration-thickness: 1px;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        .back-link:hover {
            color: var(--brand-dark);
            text-decoration-color: var(--brand-dark);
        }

        .page-title {
            font-weight: 800;
            font-size: clamp(1.9rem, 4vw, 2.6rem);
            letter-spacing: -0.02em;
            line-height: 1.1;
            color: var(--brand-dark);
            margin-bottom: 6px;
        }
        .page-subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        /* Sidebar step list */
        .step-list { list-style: none; padding: 0; margin: 0; }
        .step-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 7px 0;
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        .step-list li .num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #ffffff;
            border: 1.5px solid var(--border-color);
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .step-list li.completed .num {
            background: var(--green);
            border-color: var(--green);
            color: #ffffff;
        }
        .step-list li.active {
            color: var(--brand-dark);
            font-weight: 700;
        }
        .step-list li.active .num {
            background: var(--brand-yellow);
            border-color: var(--brand-yellow);
            color: var(--brand-dark);
        }

        /* Section headings */
        .section-heading {
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--brand-dark);
            margin-bottom: 4px;
        }
        .section-subheading {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 22px;
        }
        .subsection-heading {
            font-weight: 700;
            font-size: 1rem;
            color: var(--brand-dark);
            margin: 28px 0 14px;
        }

        /* Field labels */
        .field-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--brand-dark);
            margin-bottom: 6px;
            display: block;
        }
        .field-label .req { color: #ef4444; }
        .field-label .opt { color: var(--text-muted); font-weight: 400; font-size: 0.78rem; }

        /* Text inputs */
        .form-control {
            border-radius: 8px;
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            font-size: 0.9rem;
            color: var(--brand-dark);
            transition: all 0.15s ease;
        }
        .form-control:focus {
            background: #ffffff;
            border-color: var(--brand-dark);
            box-shadow: 0 0 0 3px rgba(30, 41, 59, 0.08);
        }
        .form-control::placeholder { color: #94a3b8; }
        .form-control[readonly] {
            background-color: #f1f5f9;
            color: var(--text-muted);
            cursor: not-allowed;
        }

        .form-control.auto-filled {
            background-color: #ecfdf5;
            border-color: var(--green);
            animation: pulse-green 1.2s ease;
        }
        @keyframes pulse-green {
            0%   { background-color: #d1fae5; }
            100% { background-color: #ecfdf5; }
        }

        .field-hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 4px;
            display: block;
        }
        .field-hint.auto { color: var(--green); font-weight: 500; }

        /* Map */
        #map {
            height: 300px;
            width: 100%;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            z-index: 1;
        }
        .map-wrapper { position: relative; margin-bottom: 12px; }

        .map-hint {
            background: #ecfdf5;
            border-left: 4px solid var(--green);
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 0.8rem;
            color: #065f46;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .map-hint i { font-size: 1.1rem; }

        .map-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .btn-map-action {
            background: #ffffff;
            border: 1px solid var(--border-color);
            color: var(--brand-dark);
            font-weight: 600;
            font-size: 0.8rem;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-map-action:hover {
            background: #f1f5f9;
            border-color: var(--brand-dark);
        }
        .btn-map-action:disabled { opacity: 0.6; cursor: not-allowed; }

        /* Back + Continue buttons */
        .btn-back {
            background: #ffffff;
            color: var(--brand-dark);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 32px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .btn-back:hover {
            background: #f1f5f9;
            border-color: var(--brand-dark);
            color: var(--brand-dark);
        }
        .btn-continue {
            background-color: var(--brand-dark);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 12px 32px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .btn-continue:hover {
            background-color: var(--brand-dark-hover);
            color: #ffffff;
            transform: translateY(-2px);
        }

        .alert { border-radius: 8px; font-size: 0.9rem; }

        @media (max-width: 991px) {
            .step-list { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 28px; }
            .step-list li { padding: 4px 10px 4px 4px; background: #ffffff; border: 1px solid var(--border-color); border-radius: 2rem; }
            .step-list li.completed { background: #ecfdf5; border-color: var(--green); }
            .step-list li.active { background: #fffbeb; border-color: var(--brand-yellow); }
            .step-list li span:not(.num) { font-size: 0.78rem; }
        }
        @media (max-width: 576px) {
            .btn-back, .btn-continue { width: 100%; }
            #map { height: 240px; }
        }
    </style>
</head>
<body>

<?php include 'header/nav.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 1080px;">

    <!-- Back link -->
    <div class="mb-3">
        <a href="dashboard.php" class="back-link">Back to dashboard</a>
    </div>

    <!-- Title -->
    <div class="mb-4">
        <h1 class="page-title">
            <?php echo $isRenewal ? 'Renew business permit application' : 'New business permit application'; ?>
        </h1>
        <p class="page-subtitle">Tax year 2026, Municipality of Placer</p>
    </div>

    <div class="row g-4 g-lg-5">

        <!-- LEFT: Sidebar -->
        <div class="col-lg-4 col-xl-3">
            <ul class="step-list">
                <?php foreach ($steps as $num => $label):
                    $cls = '';
                    if ($num < 5) $cls = 'completed';
                    elseif ($num === 5) $cls = 'active';
                ?>
                    <li class="<?php echo $cls; ?>">
                        <span class="num">
                            <?php if ($num < 5): ?>
                                <i class="bi bi-check-lg" style="font-size:0.7rem;"></i>
                            <?php else: ?>
                                <?php echo $num; ?>
                            <?php endif; ?>
                        </span>
                        <span><?php echo htmlspecialchars($label); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- RIGHT: Content -->
        <div class="col-lg-8 col-xl-9">

            <?php if (isset($errorMsg)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <?php echo $errorMsg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="ownerInfoForm">

                <!-- Heading -->
                <h2 class="section-heading">Owner information</h2>
                <p class="section-subheading">Personal and contact details of the owner.</p>

                <!-- Name row -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label for="lastName" class="field-label">Last name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="lastName" name="last_name"
                               value="<?php echo htmlspecialchars($existing['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="firstName" class="field-label">First name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="firstName" name="first_name"
                               value="<?php echo htmlspecialchars($existing['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="middleName" class="field-label">Middle name</label>
                        <input type="text" class="form-control" id="middleName" name="middle_name"
                               value="<?php echo htmlspecialchars($existing['middle_name'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Home address heading -->
                <label class="field-label">Home address <span class="req">*</span></label>

                <!-- Map hint -->
                <div class="map-hint">
                    <i class="bi bi-geo-alt-fill"></i>
                    <span>Click on the map to pin your home address, or type it below and the map will find it for you.</span>
                </div>

                <!-- Map -->
                <div class="map-wrapper">
                    <div id="map"></div>
                </div>

                <!-- Map actions -->
                <div class="map-actions mb-4">
                    <button type="button" class="btn-map-action" id="useCurrentLocationBtn">
                        <i class="bi bi-crosshair"></i> Use my current location
                    </button>
                    <button type="button" class="btn-map-action" id="resetMapBtn">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </button>
                </div>

                <!-- Hidden lat/lng -->
                <input type="hidden" name="latitude" id="latitude" value="<?php echo htmlspecialchars($existing['latitude'] ?? ''); ?>">
                <input type="hidden" name="longitude" id="longitude" value="<?php echo htmlspecialchars($existing['longitude'] ?? ''); ?>">

                <!-- Home address input -->
                <div class="mb-4">
                    <input type="text" class="form-control" id="ownerAddress" name="owner_address"
                           placeholder="Type the address or click on the map"
                           value="<?php echo htmlspecialchars($existing['owner_address'] ?? ''); ?>"
                           autocomplete="off" required>
                    <small class="field-hint" id="addressHint">Click on the map or type the address</small>
                </div>

                <!-- Row: Postal, Email, Telephone -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label for="postalCode" class="field-label">Postal code</label>
                        <input type="text" class="form-control" id="postalCode" name="postal_code"
                               value="<?php echo htmlspecialchars($existing['postal_code'] ?? '8407'); ?>"
                               readonly>
                        <small class="field-hint auto" id="postalHint">Auto-detected from map</small>
                    </div>
                    <div class="col-md-5">
                        <label for="emailAddress" class="field-label">Email <span class="req">*</span></label>
                        <input type="email" class="form-control" id="emailAddress" name="email"
                               value="<?php echo htmlspecialchars($existing['email'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="telephone" class="field-label">Telephone</label>
                        <input type="text" class="form-control" id="telephone" name="telephone"
                               value="<?php echo htmlspecialchars($existing['telephone'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Mobile no. -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="mobileNo" class="field-label">Mobile no. <span class="req">*</span></label>
                        <input type="tel" class="form-control" id="mobileNo" name="mobile"
                               placeholder="+63"
                               value="<?php echo htmlspecialchars($existing['mobile'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- Emergency contact -->
                <h3 class="subsection-heading">Emergency contact</h3>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label for="emergencyName" class="field-label">Name</label>
                        <input type="text" class="form-control" id="emergencyName" name="emergency_name"
                               value="<?php echo htmlspecialchars($existing['emergency_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="emergencyPhone" class="field-label">Phone</label>
                        <input type="text" class="form-control" id="emergencyPhone" name="emergency_phone"
                               value="<?php echo htmlspecialchars($existing['emergency_phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="emergencyEmail" class="field-label">Email</label>
                        <input type="email" class="form-control" id="emergencyEmail" name="emergency_email"
                               value="<?php echo htmlspecialchars($existing['emergency_email'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Back + Continue -->
                <div class="d-flex justify-content-between mt-5 flex-wrap gap-2">
                    <button type="button" class="btn-back" id="backBtn">Back</button>
                    <button type="submit" class="btn-continue" id="continueBtn">Continue</button>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- Footer -->
<footer class="border-top py-4 mt-5">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <small class="text-muted">© <?php echo date('Y'); ?> Municipality of Placer, Surigao del Norte.</small>
        <div>
            <a href="#" class="text-decoration-none text-muted small me-3">Privacy Policy</a>
            <a href="#" class="text-decoration-none text-muted small">Contact Us</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
/* ============================================================
   MAP + ADDRESS + POSTAL CODE BIDIRECTIONAL SYNC
   ============================================================ */

const PLACER_CENTER = [9.6583, 125.5994];
const DEFAULT_ZOOM  = 14;
const DEFAULT_POSTAL = '8407';

const savedLat = <?php echo json_encode($existing['latitude'] ?? null); ?>;
const savedLng = <?php echo json_encode($existing['longitude'] ?? null); ?>;

const mapEl        = document.getElementById('map');
const addressInput = document.getElementById('ownerAddress');
const postalInput  = document.getElementById('postalCode');
const postalHint   = document.getElementById('postalHint');
const addressHint  = document.getElementById('addressHint');
const latInput     = document.getElementById('latitude');
const lngInput     = document.getElementById('longitude');

let startLat = PLACER_CENTER[0];
let startLng = PLACER_CENTER[1];
if (savedLat && savedLng && !isNaN(parseFloat(savedLat)) && !isNaN(parseFloat(savedLng))) {
    startLat = parseFloat(savedLat);
    startLng = parseFloat(savedLng);
}

// Init map
const map = L.map(mapEl).setView([startLat, startLng], DEFAULT_ZOOM);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Pin icon
const customIcon = L.divIcon({
    className: 'custom-pin',
    html: '<div style="background:#1e293b;width:22px;height:22px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.4);"></div>',
    iconSize: [22, 22],
    iconAnchor: [11, 11]
});

let marker = null;
let suppressAddressListener = false;

function placeMarker(lat, lng) {
    if (marker) {
        marker.setLatLng([lat, lng]);
    } else {
        marker = L.marker([lat, lng], { draggable: true, icon: customIcon }).addTo(map);
        marker.on('dragend', function () {
            const p = marker.getLatLng();
            updateLatLng(p.lat, p.lng);
            reverseGeocode(p.lat, p.lng);
        });
    }
    updateLatLng(lat, lng);
}

function updateLatLng(lat, lng) {
    latInput.value = lat.toFixed(6);
    lngInput.value = lng.toFixed(6);
}

/* ---------- Reverse geocode: lat/lng → address ---------- */
let reverseController = null;
function reverseGeocode(lat, lng) {
    if (reverseController) reverseController.abort();
    reverseController = new AbortController();

    addressHint.textContent = 'Looking up address...';
    addressHint.classList.remove('auto');

    const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=en`;

    fetch(url, {
        signal: reverseController.signal,
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data || !data.address) {
            addressHint.textContent = 'Address not found. Please type it manually.';
            return;
        }

        const formatted = formatAddress(data.address);
        suppressAddressListener = true;
        addressInput.value = formatted;
        suppressAddressListener = false;

        addressInput.classList.add('auto-filled');
        setTimeout(() => addressInput.classList.remove('auto-filled'), 1200);

        addressHint.textContent = 'Auto-filled from map. You can still edit.';
        addressHint.classList.add('auto');

        const postal = data.address.postcode || DEFAULT_POSTAL;
        setPostalCode(postal, 'Auto-detected from map');
    })
    .catch(err => {
        if (err.name === 'AbortError') return;
        addressHint.textContent = 'Address lookup failed. Please type it manually.';
    });
}

/* ---------- Forward geocode: address → lat/lng ---------- */
let forwardController = null;
let addressDebounceTimer = null;
function forwardGeocode(query) {
    if (!query || query.length < 5) return;

    if (forwardController) forwardController.abort();
    forwardController = new AbortController();

    addressHint.textContent = 'Searching the address...';
    addressHint.classList.remove('auto');

    const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&countrycodes=ph&limit=1&addressdetails=1&accept-language=en`;

    fetch(url, {
        signal: forwardController.signal,
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(results => {
        if (!results || results.length === 0) {
            addressHint.textContent = 'Address not found on the map. You can still keep what you typed.';
            return;
        }
        const hit = results[0];
        const lat = parseFloat(hit.lat);
        const lng = parseFloat(hit.lon);

        map.setView([lat, lng], 17);
        placeMarker(lat, lng);

        if (hit.address && hit.address.postcode) {
            setPostalCode(hit.address.postcode, 'Auto-detected from address');
        } else {
            setPostalCode(DEFAULT_POSTAL, 'Default (Placer)');
        }

        addressHint.textContent = 'Location found on the map.';
        addressHint.classList.add('auto');
    })
    .catch(err => {
        if (err.name === 'AbortError') return;
        addressHint.textContent = 'Address lookup failed. You can still keep what you typed.';
    });
}

/* ---------- Postal code helper ---------- */
function setPostalCode(value, hintText) {
    if (!value) value = DEFAULT_POSTAL;
    postalInput.value = value;
    postalInput.classList.add('auto-filled');
    postalHint.textContent = hintText || 'Auto-detected';
    postalHint.classList.add('auto');
    setTimeout(() => postalInput.classList.remove('auto-filled'), 1200);
}

/* ---------- Address formatter ---------- */
function formatAddress(addr) {
    const parts = [];
    if (addr.house_number && addr.road) parts.push(`${addr.house_number} ${addr.road}`);
    else if (addr.road) parts.push(addr.road);

    const brgy = addr.barangay || addr.suburb || addr.neighbourhood || addr.village || addr.hamlet;
    if (brgy) parts.push(brgy);

    const city = addr.city || addr.town || addr.municipality || addr.county;
    if (city) parts.push(city);

    const province = addr.state || addr.province;
    if (province) parts.push(province);

    if (addr.country) parts.push(addr.country);

    return parts.join(', ');
}

/* ---------- Map events ---------- */
map.on('click', function (e) {
    placeMarker(e.latlng.lat, e.latlng.lng);
    reverseGeocode(e.latlng.lat, e.latlng.lng);
});

if (savedLat && savedLng) {
    placeMarker(startLat, startLng);
}

/* ---------- Address input → forward geocode (debounced) ---------- */
addressInput.addEventListener('input', function () {
    if (suppressAddressListener) return;
    const query = this.value.trim();

    if (addressDebounceTimer) clearTimeout(addressDebounceTimer);
    addressDebounceTimer = setTimeout(() => {
        if (query.length >= 5) forwardGeocode(query);
    }, 800);
});

/* ---------- "Use my current location" ---------- */
document.getElementById('useCurrentLocationBtn').addEventListener('click', function () {
    if (!navigator.geolocation) {
        Swal.fire({ icon: 'warning', title: 'Not supported', text: 'Your browser does not support geolocation.', confirmButtonColor: '#1e293b' });
        return;
    }
    const btn = this;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Locating...';

    navigator.geolocation.getCurrentPosition(
        pos => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            map.setView([lat, lng], 17);
            placeMarker(lat, lng);
            reverseGeocode(lat, lng);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        },
        err => {
            let msg = 'Unable to get your location.';
            if (err.code === 1) msg = 'Location access denied. Please enable it in your browser settings.';
            if (err.code === 2) msg = 'Location unavailable. Please try again.';
            if (err.code === 3) msg = 'Location request timed out.';
            Swal.fire({ icon: 'warning', title: 'Location error', text: msg, confirmButtonColor: '#1e293b' });
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
});

/* ---------- "Reset" ---------- */
document.getElementById('resetMapBtn').addEventListener('click', function () {
    map.setView(PLACER_CENTER, DEFAULT_ZOOM);
    if (marker) { map.removeLayer(marker); marker = null; }
    latInput.value = '';
    lngInput.value = '';
    suppressAddressListener = true;
    addressInput.value = '';
    suppressAddressListener = false;
    addressHint.textContent = 'Click on the map or type the address';
    addressHint.classList.remove('auto');
    setPostalCode(DEFAULT_POSTAL, 'Default (Placer)');
});

/* ---------- Back button ---------- */
document.getElementById('backBtn').addEventListener('click', function () {
    window.location.href = 'business_address.php';
});

/* ---------- Client-side validation ---------- */
document.getElementById('ownerInfoForm').addEventListener('submit', async function (e) {
    const lastName = document.getElementById('lastName').value.trim();
    const firstName = document.getElementById('firstName').value.trim();
    const ownerAddress = document.getElementById('ownerAddress').value.trim();
    const email = document.getElementById('emailAddress').value.trim();
    const mobile = document.getElementById('mobileNo').value.trim();

    if (!lastName || !firstName || !ownerAddress || !email || !mobile) {
        e.preventDefault();
        await Swal.fire({ icon: 'warning', title: 'Missing fields', text: 'Please fill in all required fields.', confirmButtonColor: '#1e293b' });
        return;
    }
    if (!email.includes('@')) {
        e.preventDefault();
        await Swal.fire({ icon: 'warning', title: 'Invalid email', text: 'Please enter a valid email address.', confirmButtonColor: '#1e293b' });
        return;
    }
});
</script>

</body>
</html>