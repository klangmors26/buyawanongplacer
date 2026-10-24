<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once 'config/connect.php';

// Fetch approved announcements
$announcements = [];
$annQuery = "SELECT id, title, content, created_at
             FROM announcements
             WHERE status = 'approved'
             ORDER BY created_at DESC
             LIMIT 10";
$annResult = $conn->query($annQuery);
if ($annResult) {
    while ($row = $annResult->fetch_assoc()) {
        $announcements[] = $row;
    }
}

// ---- Fetch admin-editable home page content ----
$homeSettings = [];
$hsResult = $conn->query(
    "SELECT setting_key, setting_value, setting_type
       FROM system_settings
      WHERE setting_group = 'home'"
);
if ($hsResult) {
    while ($row = $hsResult->fetch_assoc()) {
        $homeSettings[$row['setting_key']] = [
            'value' => $row['setting_value'],
            'type'  => $row['setting_type'],
        ];
    }
}

/** Escaped output — for plain text / textarea settings. */
function home_setting_text($key, $default = '') {
    global $homeSettings;
    $val = (isset($homeSettings[$key]) && $homeSettings[$key]['value'] !== '')
        ? $homeSettings[$key]['value']
        : $default;
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

/** Raw output — for settings typed as `html` (admins are trusted). */
function home_setting_html($key, $default = '') {
    global $homeSettings;
    return (isset($homeSettings[$key]) && $homeSettings[$key]['value'] !== '')
        ? $homeSettings[$key]['value']
        : $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyawanong Placer - Municipality of Placer</title>

    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>

    <style>
        :root {
            --navy-dark: #172233;
            --navy-light: #2a3b52;
            --yellow-accent: #FBBF24;
            --yellow-hover: #F59E0B;
            --text-dark: #111827;
            --text-muted: #6B7280;
            --bg-light: #F8F9FA;
            --white: #ffffff;
            --border-color: #E5E7EB;
            --primary-green: #00c389;
            --primary-green-dark: #00996e;

            --track-green: #7fbf7f;
            --track-green-dark: #5fa85f;
            --track-strip: #d9ebd9;
            --track-strip-text: #2f5d2f;
            --track-pending: #f08a8a;
            --track-pending-dark: #d96666;
            --track-rejected: #dc3545;
        }

        body { font-family: 'Inter', sans-serif; color: var(--text-dark); background-color: var(--bg-light); overflow-x: hidden; }

        .site-header { background-color: var(--white); padding: 1rem 0; border-bottom: 1px solid var(--border-color); }
        .logo-icon { width: 40px; height: 40px; background-color: var(--navy-dark); color: var(--white); border-radius: 50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.2rem; margin-right:12px; }
        .brand-text h1 { font-size:1rem; font-weight:700; margin:0; color:var(--navy-dark); line-height:1.2; }
        .brand-text p { font-size:0.75rem; color:var(--text-muted); margin:0; }
        .nav-link { color:var(--text-dark) !important; font-weight:500; font-size:0.9rem; padding:0.5rem 1rem !important; border-radius:6px; transition:all 0.2s; }
        .nav-link:hover, .nav-link.active { background-color: var(--bg-light); }
        .btn-login { background-color: var(--navy-dark); color:var(--white); font-weight:600; font-size:0.9rem; padding:0.5rem 1.5rem; border-radius:6px; border:none; transition:all 0.2s; }
        .btn-login:hover { background-color: var(--navy-light); color: var(--white); }

        .hero-section { background-color: var(--navy-dark); color: var(--white); position: relative; padding: 100px 0 80px; }
        .hero-title { font-size: 3.5rem; font-weight: 800; line-height: 1.1; margin-bottom: 1.5rem; }
        .hero-subtitle { font-size: 1.1rem; color: rgba(255,255,255,0.7); max-width: 500px; margin-bottom: 2rem; line-height: 1.6; }
        .btn-hero-primary { background-color:#FBBF24 !important; color:#172233 !important; border:1px solid #FBBF24 !important; font-weight:600; padding:0.75rem 2rem; border-radius:6px; transition:all 0.2s; }
        .btn-hero-primary:hover { background-color:#F59E0B !important; border-color:#F59E0B !important; color:#172233 !important; }
        .btn-hero-secondary { background-color:transparent !important; color:#ffffff !important; border:1px solid rgba(255,255,255,0.5) !important; font-weight:600; padding:0.75rem 2rem; border-radius:6px; transition:all 0.2s; }
        .btn-hero-secondary:hover { background-color:rgba(255,255,255,0.1) !important; border-color:#ffffff !important; color:#ffffff !important; }
        .permit-tracker-card { background-color: var(--white); border-radius: 12px; padding: 2rem; color: var(--text-dark); box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .permit-tracker-card h3 { font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem; }
        .permit-tracker-card p { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
        .hero-section::after { content:''; position:absolute; bottom:-1px; left:0; width:100%; height:30px; background-image:radial-gradient(circle at 50% 0%, transparent 25px, var(--bg-light) 26px); background-size:60px 30px; background-repeat:repeat-x; z-index:1; }

        .section-padding { padding: 5rem 0; }
        .section-title { font-size: 2rem; font-weight: 700; color: var(--navy-dark); margin-bottom: 0.5rem; }
        .section-subtitle { color: var(--text-muted); font-size: 1rem; margin-bottom: 2rem; }
        .divider { border-top: 2px solid var(--border-color); margin: 2rem 0; }

        .offer-item h5 { font-size: 1.1rem; font-weight: 700; color: var(--navy-dark); margin-bottom: 0.5rem; }
        .offer-item p { font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; }

        .places-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .place-card { position:relative; border-radius:12px; overflow:hidden; min-height:250px; background-size:cover; background-position:center; display:flex; align-items:flex-end; transition:transform 0.3s ease; }
        .place-card:hover { transform: scale(1.02); }
        .place-card-large { grid-row: span 2; min-height: 520px; }
        .place-card-overlay { position:absolute; inset:0; background:linear-gradient(to top, rgba(23,34,51,0.9) 0%, rgba(23,34,51,0.4) 40%, transparent 100%); }
        .place-card-content { position:relative; z-index:2; padding:2rem; color:var(--white); }
        .place-card-content h4 { font-weight:700; font-size:1.5rem; margin-bottom:0.5rem; }
        .place-card-content p { font-size:0.9rem; opacity:0.9; margin:0; max-width:80%; }
        .link-attractions { color:var(--navy-dark); font-weight:600; text-decoration:underline; font-size:0.9rem; }

        .announcement-list-item { display:flex; gap:1.5rem; padding:1.5rem 0; border-bottom:1px solid var(--border-color); }
        .announcement-list-item:last-child { border-bottom:none; }
        .ann-date-block { background-color: var(--navy-dark); color: var(--white); border-radius:8px; padding:10px 15px; text-align:center; min-width:70px; height:fit-content; }
        .ann-date-block .month { display:block; font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--yellow-accent); }
        .ann-date-block .day { display:block; font-size:1.5rem; font-weight:800; line-height:1; }
        .ann-content h6 { font-weight:700; color:var(--navy-dark); margin-bottom:0.25rem; font-size:1rem; }
        .ann-content p { font-size:0.85rem; color:var(--text-muted); margin:0; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .programs-box { background-color: var(--navy-dark); border-radius: 12px; padding: 2rem; color: var(--white); height: 100%; }
        .programs-box h4 { font-weight:700; margin-bottom:1.5rem; font-size:1.25rem; }
        .program-item { padding:1rem 0; border-bottom:1px solid rgba(255,255,255,0.1); }
        .program-item:last-child { border-bottom:none; padding-bottom:0; }
        .program-item h6 { font-weight:700; margin-bottom:0.25rem; font-size:0.95rem; }
        .program-item p { font-size:0.85rem; color:rgba(255,255,255,0.7); margin:0; }

        .modal-header { background-color: var(--bg-light); border-bottom: 2px solid var(--yellow-accent); }
        .modal-title { color: var(--navy-dark); font-weight: 700; }
        .btn-green { background-color: var(--navy-dark); border-color: var(--navy-dark); color: var(--white); }
        .btn-green:hover { background-color: var(--navy-light); border-color: var(--navy-light); color: var(--white); }

        #announcementCalendar { background: white; border-radius: 0.5rem; padding: 0.5rem; }
        .fc .fc-toolbar-title { font-size:1.2rem !important; color:var(--navy-dark); font-weight:700; }
        .fc .fc-button-primary { background-color:var(--navy-dark) !important; border-color:var(--navy-dark) !important; font-size:0.85rem; }
        .fc .fc-button-primary:hover { background-color:var(--navy-light) !important; border-color:var(--navy-light) !important; }
        .fc .fc-button-primary:not(:disabled).fc-button-active, .fc .fc-button-primary:not(:disabled):active { background-color:var(--navy-light) !important; border-color:var(--navy-light) !important; }
        .fc .fc-daygrid-day-number { color: var(--navy-dark); font-weight: 600; }
        .fc .fc-col-header-cell-cushion { color: var(--navy-dark); font-weight: 700; padding: 8px 4px; }
        .fc-event { cursor:pointer; border-radius:5px !important; padding:3px 5px !important; font-size:0.8rem; white-space:normal !important; }
        .fc-event-title { white-space:normal !important; word-break:break-word !important; line-height:1.2 !important; font-weight:600; }
        .fc .fc-day-today { background: #FEF3C7 !important; }
        .fc-daygrid-event { white-space:normal !important; align-items:flex-start !important; }
        .fc-daygrid-day-frame { min-height: 110px !important; }

        #qr-reader { width: 100%; }
        #qr-reader video { border-radius: .5rem; }

        .shopee-track { border-radius: 14px; overflow: hidden; background: #fafbfa; border: 1px solid #e7eae7; }
        .shopee-track-header { background: var(--track-green); color: #ffffff; padding: 1.1rem 1.5rem; text-align: center; font-weight: 700; font-size: 1.05rem; letter-spacing: 0.4px; text-transform: uppercase; }
        .shopee-track-info { background: var(--track-strip); padding: 0.85rem 1.5rem; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem 1.5rem; font-size: 0.85rem; color: var(--track-strip-text); }
        .shopee-track-info > div { display: flex; align-items: center; gap: 0.35rem; }
        .shopee-track-info strong { color: #1f4520; font-weight: 700; }
        .shopee-track-info .value { color: #2f5d2f; font-weight: 600; }

        .shopee-steps-wrap { padding: 2.25rem 1rem 1.75rem; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .shopee-steps { display: flex; align-items: flex-start; justify-content: space-between; min-width: 620px; position: relative; }
        .shopee-step { flex: 1 1 0; display: flex; flex-direction: column; align-items: center; position: relative; z-index: 2; text-align: center; min-width: 100px; }
        .shopee-step:not(:last-child)::after { content: ''; position: absolute; top: 26px; left: calc(50% + 30px); width: calc(100% - 60px); height: 4px; background: var(--track-pending); z-index: 1; border-radius: 2px; transition: background 0.3s; }
        .shopee-step.is-completed:not(:last-child)::after, .shopee-step.is-active:not(:last-child)::after { background: var(--track-green); }

        .shopee-step-circle { width: 52px; height: 52px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #ffffff; font-size: 1.35rem; background: var(--track-pending); border: 3px solid #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.08); z-index: 2; position: relative; transition: background 0.3s, transform 0.3s; }
        .shopee-step-circle i { line-height: 1; }

        .shopee-step.is-completed .shopee-step-circle { background: var(--track-green); }
        .shopee-step.is-active .shopee-step-circle { background: var(--track-green-dark); animation: pulse-circle 1.8s ease-in-out infinite; box-shadow: 0 0 0 0 rgba(127, 191, 127, 0.6); }
        .shopee-step.is-rejected .shopee-step-circle { background: var(--track-rejected); }

        @keyframes pulse-circle {
            0%   { box-shadow: 0 0 0 0 rgba(127, 191, 127, 0.65); }
            70%  { box-shadow: 0 0 0 12px rgba(127, 191, 127, 0); }
            100% { box-shadow: 0 0 0 0 rgba(127, 191, 127, 0); }
        }

        .shopee-step-label { margin-top: 0.85rem; font-size: 0.8rem; font-weight: 700; color: #333; max-width: 120px; line-height: 1.25; }
        .shopee-step.is-active .shopee-step-label { color: var(--track-green-dark); }
        .shopee-step.is-rejected .shopee-step-label { color: var(--track-rejected); }

        .shopee-step-date { margin-top: 0.3rem; font-size: 0.7rem; color: #8a8f8a; font-weight: 500; max-width: 120px; line-height: 1.2; }
        .shopee-step.is-active .shopee-step-date { color: var(--track-green-dark); }

        .track-detail-row { display:flex; padding: 0.55rem 0; border-bottom: 1px solid #eef2f5; font-size: 0.9rem; }
        .track-detail-row:last-child { border-bottom: none; }
        .track-detail-label { width: 42%; font-weight: 600; color: var(--navy-dark); flex-shrink: 0; }
        .track-detail-value { width: 58%; color: var(--text-dark); word-break: break-word; }

        @media (max-width: 768px) {
            .hero-section { padding: 60px 0 50px; }
            .hero-section::after { height: 20px; background-size: 40px 20px; background-image: radial-gradient(circle at 50% 0%, transparent 15px, var(--bg-light) 16px); }
            .hero-title { font-size: 2.2rem; }
            .places-grid { grid-template-columns: 1fr; }
            .place-card-large { min-height: 300px; }
            .fc .fc-toolbar { flex-wrap:wrap; justify-content:center; gap:0.5rem; }
            .fc .fc-toolbar-title { font-size:1rem !important; width:100%; text-align:center; }
            .fc-daygrid-day-frame { min-height: 80px !important; }
            .track-detail-label { width: 100%; }
            .track-detail-value { width: 100%; }
            .shopee-track-info { justify-content: flex-start; }
            .shopee-steps { min-width: 560px; }
            .shopee-step-circle { width: 46px; height: 46px; font-size: 1.15rem; }
            .shopee-step:not(:last-child)::after { top: 23px; left: calc(50% + 27px); width: calc(100% - 54px); }
            .shopee-step-label { font-size: 0.72rem; max-width: 90px; }
            .shopee-step-date  { font-size: 0.65rem; max-width: 90px; }
        }

        .place-card-view { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; padding: 4px 12px; background: rgba(251, 191, 36, 0.95); color: #172233; font-size: 0.75rem; font-weight: 700; border-radius: 2rem; opacity: 0; transform: translateY(6px); transition: opacity 0.25s, transform 0.25s; }
        .place-card:hover .place-card-view { opacity: 1; transform: translateY(0); }

        @media (hover: none) {
            .place-card-view { opacity: 1; transform: none; }
        }

        .attraction-card { cursor: pointer; transition: transform 0.25s, box-shadow 0.25s; }
        .attraction-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.10) !important; }
        .attraction-card .card-img-top { transition: transform 0.4s; }
        .attraction-card:hover .card-img-top { transform: scale(1.05); }

        .attraction-card .place-card-view { opacity: 1; transform: none; margin-top: 6px; }
    </style>
</head>
<body>

<?php include "header/nav.php"; ?>

<!-- HERO SECTION -->
<section class="hero-section">
    <div class="container position-relative" style="z-index: 2;">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <h1 class="hero-title"><?= home_setting_html('home_hero_title', 'Municipal<br>Services for<br>Placer, in one<br>Place.') ?></h1>
                <p class="hero-subtitle">
                    <?= home_setting_text('home_hero_subtitle', 'Apply for a business permit, follow your application, and see what is happening in town without a trip to the Municipal Hall.') ?>
                </p>
                <div class="d-flex gap-3 flex-wrap">
                    <button type="button" class="btn btn-hero-primary" data-bs-toggle="modal" data-bs-target="#authModal" data-tab="login"><?= home_setting_text('home_hero_primary_btn', 'Apply for a permit') ?></button>
                    <a href="#announcement-section" class="btn btn-hero-secondary"><?= home_setting_text('home_hero_secondary_btn', 'Read announcements') ?></a>
                     <a href="generate_user_manual.php" class="btn btn-hero-secondary" download>
                        <i class="bi bi-download me-1"></i> User Manual
                     </a>
                </div>
            </div>
            <div class="col-lg-5 mt-5 mt-lg-0">
                <div class="permit-tracker-card">
                    <h3><?= home_setting_text('home_tracker_title', 'Where is my permit?') ?></h3>
                    <p><?= home_setting_text('home_tracker_subtitle', 'No login needed. Enter your reference number or scan the QR code.') ?></p>

                    <div class="input-group mb-3" style="border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid var(--border-color);">
                        <input type="text" class="form-control border-0" id="trackRef" placeholder="BP-2026-00087" style="background:#ffffff;padding:1rem 1.2rem;font-size:1rem;box-shadow:none;">
                        <button class="btn btn-light border-0" type="button" id="scanQrBtn" title="Scan QR Code" data-bs-toggle="modal" data-bs-target="#qrScannerModal" style="background:#ffffff;color:var(--navy-dark);border-left:1px solid var(--border-color) !important;padding:0 15px;">
                            <i class="bi bi-qr-code-scan fs-4"></i>
                        </button>
                        <button class="btn" type="button" id="trackBtn" style="background:var(--navy-dark);color:white;font-weight:600;padding:0 2rem;font-size:1rem;border-radius:0;">Track</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- WHAT YOU CAN DO HERE -->
<section class="section-padding" id="what-we-offer">
    <div class="container">
        <h2 class="section-title"><?= home_setting_text('home_services_title', 'Explore our Services') ?></h2>
        <p class="section-subtitle"><?= home_setting_text('home_services_subtitle', 'Services for residents, business owners, and visitors.') ?></p>
        <div class="divider"></div>
        <div class="row g-4">
            <div class="col-md-3 offer-item">
                <h5><?= home_setting_text('home_service_1_title', 'Business permits') ?></h5>
                <p><?= home_setting_text('home_service_1_text', 'Apply or renew your business permit online, pay fees, and track your application status with a unique QR code for tracking and verification.') ?></p>
            </div>
            <div class="col-md-3 offer-item">
                <h5><?= home_setting_text('home_service_2_title', 'Tourist spots') ?></h5>
                <p><?= home_setting_text('home_service_2_text', 'Beaches, eco-parks, and heritage sites around Placer.') ?></p>
            </div>
            <div class="col-md-3 offer-item">
                <h5><?= home_setting_text('home_service_3_title', 'Community programs') ?></h5>
                <p><?= home_setting_text('home_service_3_text', 'Youth training, local events, and livelihood help.') ?></p>
            </div>
            <div class="col-md-3 offer-item">
                <h5><?= home_setting_text('home_service_4_title', 'Announcements') ?></h5>
                <p><?= home_setting_text('home_service_4_text', 'Official notices from the municipal office.') ?></p>
            </div>
        </div>
    </div>
</section>

<!-- PLACES WORTH THE TRIP -->
<section class="section-padding pt-0">
    <div class="container">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <h2 class="section-title"><?= home_setting_text('home_places_title', 'Places worth the trip') ?></h2>
                <p class="section-subtitle mb-0"><?= home_setting_text('home_places_subtitle', 'Start with these three, then see the full list.') ?></p>
            </div>
            <a href="#" class="link-attractions" data-bs-toggle="modal" data-bs-target="#seeMoreModal"><?= home_setting_text('home_places_link', 'All attractions') ?></a>
        </div>

        <div class="places-grid">
            <!-- Large Card: Panhutongan -->
            <div class="place-card place-card-large"
                 style="background-image: url('img/panhutongan.jpeg'); cursor: pointer;"
                 data-place-name="Panhutongan Archaeological Site"
                 data-place-desc="A local village where historical and archaeological artifacts have been found."
                 data-place-query="Panhutongan Archaeological Site, Placer, Surigao del Norte"
                 data-place-img="img/panhutongan.jpeg">
                <div class="place-card-overlay"></div>
                <div class="place-card-content">
                    <h4>Panhutongan Archaeological Site</h4>
                    <p>A local village where historical and archaeological artifacts have been found.</p>
                    <span class="place-card-view">
                        <i class="bi bi-geo-alt-fill"></i> View on map
                    </span>
                </div>
            </div>

            <!-- Small Card: Cagbantoy -->
            <div class="place-card"
                 style="background-image: url('img/cagbantoy.jpg'); cursor: pointer;"
                 data-place-name="Cagbantoy Beach"
                 data-place-desc="A calm coastal spot for swimming and sea breeze."
                 data-place-query="Cagbantoy Beach, Placer, Surigao del Norte"
                 data-place-img="img/cagbantoy.jpg">
                <div class="place-card-overlay"></div>
                <div class="place-card-content">
                    <h4>Cagbantoy Beach</h4>
                    <p>A calm coastal spot for swimming and sea breeze.</p>
                    <span class="place-card-view">
                        <i class="bi bi-geo-alt-fill"></i> View on map
                    </span>
                </div>
            </div>

            <!-- Small Card: Masapelid -->
            <div class="place-card"
                 style="background-image: url('img/masapelid.jpg'); cursor: pointer;"
                 data-place-name="Masapelid Island"
                 data-place-desc="A short boat ride from Placer, with quiet beaches."
                 data-place-query="Masapelid Island, Surigao del Norte"
                 data-place-img="img/masapelid.jpg">
                <div class="place-card-overlay"></div>
                <div class="place-card-content">
                    <h4>Masapelid Island</h4>
                    <p>A short boat ride from Placer, with quiet beaches.</p>
                    <span class="place-card-view">
                        <i class="bi bi-geo-alt-fill"></i> View on map
                    </span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ANNOUNCEMENTS & PROGRAMS -->
<section class="section-padding pt-0" id="announcement-section">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-6">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="section-title mb-0"><?= home_setting_text('home_announcements_title', 'Announcements') ?></h2>
                    <a href="#" class="link-attractions" data-bs-toggle="modal" data-bs-target="#announcementCalendarModal"><?= home_setting_text('home_announcements_link', 'See all') ?></a>
                </div>

                <?php if (count($announcements) > 0): ?>
                    <div class="announcement-list">
                        <?php foreach (array_slice($announcements, 0, 4) as $ann): ?>
                            <?php
                            $date  = new DateTime($ann['created_at']);
                            $day   = $date->format('d');
                            $month = strtoupper($date->format('M'));
                            $plainContent = trim(strip_tags($ann['content']));
                            ?>
                            <div class="announcement-list-item">
                                <div class="ann-date-block">
                                    <span class="month"><?php echo $month; ?></span>
                                    <span class="day"><?php echo $day; ?></span>
                                </div>
                                <div class="ann-content">
                                    <h6><?php echo htmlspecialchars($ann['title']); ?></h6>
                                    <p><?php echo htmlspecialchars($plainContent); ?></p>
                                    <?php if (mb_strlen($plainContent) > 180): ?>
                                        <button type="button" class="btn btn-link p-0 text-decoration-none small fw-bold mt-1" style="color: var(--yellow-hover);"
                                                data-bs-toggle="modal" data-bs-target="#announcementDetailModal"
                                                data-title="<?php echo htmlspecialchars($ann['title'], ENT_QUOTES); ?>"
                                                data-date="<?php echo htmlspecialchars($date->format('F j, Y'), ENT_QUOTES); ?>"
                                                data-content="<?php echo htmlspecialchars($plainContent, ENT_QUOTES); ?>">
                                            Read more <i class="bi bi-arrow-right"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center" role="alert">
                        <i class="bi bi-info-circle me-2"></i> No announcements available at this time.
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-6">
                <h2 class="section-title mb-4"><?= home_setting_text('home_programs_title', 'Programs') ?></h2>
                <div class="programs-box">
                    <h4><?= home_setting_text('home_programs_open_title', 'Open now') ?></h4>
                    <div class="program-item">
                        <h6><?= home_setting_text('home_program_1_title', 'Leadership and skills training') ?></h6>
                        <p><?= home_setting_text('home_program_1_text', 'Youth development. Builds young leaders through civic work.') ?></p>
                    </div>
                    <div class="program-item">
                        <h6><?= home_setting_text('home_program_2_title', 'Placer Fiesta 2026') ?></h6>
                        <p><?= home_setting_text('home_program_2_text', 'Local event. Culture and local enterprise on show.') ?></p>
                    </div>
                    <div class="program-item">
                        <h6><?= home_setting_text('home_program_3_title', 'Livelihood assistance') ?></h6>
                        <p><?= home_setting_text('home_program_3_text', 'Social services. Grants and micro-enterprise support for families.') ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================ -->
<!-- MODALS                                                       -->
<!-- ============================================================ -->

<!-- See More Modal -->
<div class="modal fade" id="seeMoreModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">More Tourist Attractions in Placer, Surigao del Norte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-4">Discover more beautiful destinations around Placer and nearby islands. Tap any card to see its location on the map.</p>
                <div class="row g-4">
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-0 attraction-card"
                             data-place-name="Tinago Island"
                             data-place-desc="A hidden gem with crystal-clear waters and pristine white sand beaches."
                             data-place-query="Tinago Island, Placer, Surigao del Norte"
                             data-place-img="img/tinago.jpg">
                            <img src="img/tinago.jpg" class="card-img-top" style="height:180px;object-fit:cover;">
                            <div class="card-body">
                                <h5 class="card-title fw-bold" style="color:var(--navy-dark);">Tinago Island</h5>
                                <p class="card-text small text-muted">A hidden gem with crystal-clear waters and pristine white sand beaches.</p>
                                <span class="place-card-view">
                                    <i class="bi bi-geo-alt-fill"></i> View on map
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-0 attraction-card"
                             data-place-name="Bangkay Island"
                             data-place-desc="A tranquil island escape featuring untouched beaches and stunning sunsets."
                             data-place-query="Bangkay Island, Surigao del Norte"
                             data-place-img="img/bangkay.jpg">
                            <img src="img/bangkay.jpg" class="card-img-top" style="height:180px;object-fit:cover;">
                            <div class="card-body">
                                <h5 class="card-title fw-bold" style="color:var(--navy-dark);">Bangkay Island</h5>
                                <p class="card-text small text-muted">A tranquil island escape featuring untouched beaches and stunning sunsets.</p>
                                <span class="place-card-view">
                                    <i class="bi bi-geo-alt-fill"></i> View on map
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-0 attraction-card"
                             data-place-name="Mahaba Island"
                             data-place-desc="A long stretch of powdery white sand beach, ideal for swimming and camping."
                             data-place-query="Mahaba Island, Surigao del Norte"
                             data-place-img="img/mahaba.jpg">
                            <img src="img/mahaba.jpg" class="card-img-top" style="height:180px;object-fit:cover;">
                            <div class="card-body">
                                <h5 class="card-title fw-bold" style="color:var(--navy-dark);">Mahaba Island</h5>
                                <p class="card-text small text-muted">A long stretch of powdery white sand beach, ideal for swimming and camping.</p>
                                <span class="place-card-view">
                                    <i class="bi bi-geo-alt-fill"></i> View on map
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-0 attraction-card"
                             data-place-name="Placer Municipal Park"
                             data-place-desc="A well-maintained public park in the town center, perfect for family picnics."
                             data-place-query="Placer Municipal Park, Placer, Surigao del Norte"
                             data-place-img="img/park.jpg">
                            <img src="img/park.jpg" class="card-img-top" style="height:180px;object-fit:cover;">
                            <div class="card-body">
                                <h5 class="card-title fw-bold" style="color:var(--navy-dark);">Placer Municipal Park</h5>
                                <p class="card-text small text-muted">A well-maintained public park in the town center, perfect for family picnics.</p>
                                <span class="place-card-view">
                                    <i class="bi bi-geo-alt-fill"></i> View on map
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-0 attraction-card"
                             data-place-name="Mangrove Eco-Park"
                             data-place-desc="A protected mangrove forest offering boardwalk trails and eco-tours."
                             data-place-query="Mangrove Eco-Park, Placer, Surigao del Norte"
                             data-place-img="img/eco.jpg">
                            <img src="img/eco.jpg" class="card-img-top" style="height:180px;object-fit:cover;">
                            <div class="card-body">
                                <h5 class="card-title fw-bold" style="color:var(--navy-dark);">Mangrove Eco-Park</h5>
                                <p class="card-text small text-muted">A protected mangrove forest offering boardwalk trails and eco-tours.</p>
                                <span class="place-card-view">
                                    <i class="bi bi-geo-alt-fill"></i> View on map
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-0 attraction-card"
                             data-place-name="Placer Heritage Museum"
                             data-place-desc="Showcasing the rich cultural heritage and historical narratives of Placer."
                             data-place-query="Placer Heritage Museum, Placer, Surigao del Norte"
                             data-place-img="img/museo.jpg">
                            <img src="img/museo.jpg" class="card-img-top" style="height:180px;object-fit:cover;">
                            <div class="card-body">
                                <h5 class="card-title fw-bold" style="color:var(--navy-dark);">Placer Heritage Museum</h5>
                                <p class="card-text small text-muted">Showcasing the rich cultural heritage and historical narratives of Placer.</p>
                                <span class="place-card-view">
                                    <i class="bi bi-geo-alt-fill"></i> View on map
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-green" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- PLACE MAP MODAL — lazy-loads the map (no lag) -->
<div class="modal fade" id="placeMapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 16px; border: none; overflow: hidden;">
            <div class="modal-header" style="background: var(--navy-dark); border-bottom: none; color: white;">
                <h5 class="modal-title" style="color: white;">
                    <i class="bi bi-geo-alt-fill me-2" style="color: var(--yellow-accent);"></i>
                    <span id="placeMapTitle">Location</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 0;">
                <div id="placeMapBanner" style="height: 180px; background-size: cover; background-position: center; position: relative;">
                    <div style="position: absolute; inset: 0; background: linear-gradient(to top, rgba(23,34,51,0.85) 0%, rgba(23,34,51,0.25) 55%, transparent 100%);"></div>
                    <div style="position: absolute; bottom: 1rem; left: 1.25rem; right: 1.25rem; color: white;">
                        <h5 class="fw-bold mb-1" id="placeMapBannerTitle">—</h5>
                        <p class="mb-0 small" id="placeMapBannerDesc" style="opacity: 0.9;"></p>
                    </div>
                </div>
                <div style="position: relative; width: 100%; height: 420px; background: #eef1f4;">
                    <div id="placeMapPlaceholder"
                         style="position:absolute; inset:0; display:flex; flex-direction:column;
                                align-items:center; justify-content:center; text-align:center; padding:1.5rem;">
                        <div style="width:64px;height:64px;border-radius:50%;background:#172233;color:#fff;
                                display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin-bottom:1rem;">
                            <i class="bi bi-map"></i>
                        </div>
                        <h6 class="fw-bold mb-1" style="color:#172233;">Map is ready to load</h6>
                        <p class="text-muted small mb-3" style="max-width:340px;">
                            We load the map only when you need it — this keeps the page fast.
                        </p>
                        <button type="button" id="placeMapLoadBtn" class="btn btn-green">
                            <i class="bi bi-geo-alt-fill me-1"></i> Load Map
                        </button>
                    </div>
                    <iframe id="placeMapIframe" src="about:blank"
                        style="border:0; width:100%; height:100%; display:block; position:absolute; inset:0; opacity:0; pointer-events:none; transition:opacity .3s;"
                        allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #e5e7eb;">
                <a id="placeMapOpenBtn" href="#" target="_blank" rel="noopener" class="btn btn-green">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Open in Google Maps
                </a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ANNOUNCEMENT DETAIL MODAL -->
<div class="modal fade" id="announcementDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-megaphone-fill me-2" style="color: var(--yellow-hover);"></i>
                    <span id="annDetailTitle">Announcement</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3"><i class="bi bi-calendar3 me-1"></i><span id="annDetailDate"></span></p>
                <hr>
                <p id="annDetailContent" style="white-space: pre-wrap; line-height: 1.8; color: #2c4e6e;"></p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-green" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- ANNOUNCEMENT CALENDAR MODAL -->
<div class="modal fade" id="announcementCalendarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1200px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar3 me-2"></i>Announcements & Events Calendar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    View all municipal announcements along with Philippine holidays.
                    <span class="badge ms-2" style="background: var(--navy-dark); color: white;">Announcement</span>
                    <span class="badge ms-2 bg-danger">Holiday</span>
                </p>
                <div id="announcementCalendar"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-green" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- TRACKING RESULT MODAL — SHOPEE-STYLE TRACKER -->
<div class="modal fade" id="trackResultModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 16px; border: none; overflow: hidden;">
            <div class="modal-header" style="background: var(--navy-dark); border-bottom: none; color: white;">
                <h5 class="modal-title" style="color: white;">
                    <i class="bi bi-truck me-2"></i>Permit Tracking
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="trackResultBody" style="padding: 1.5rem;">
                <div id="trackLoading" class="text-center py-5">
                    <div class="spinner-border text-warning" role="status" style="width: 3rem; height: 3rem;"></div>
                    <p class="text-muted mt-3 mb-0">Looking up your application…</p>
                </div>
                <div id="trackError" class="text-center py-4" style="display:none;">
                    <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i>
                    <h5 class="mt-3 fw-bold">Not found</h5>
                    <p class="text-muted mb-0" id="trackErrorMsg"></p>
                </div>
                <div id="trackData" style="display:none;">
                    <div class="shopee-track mb-4">
                        <div class="shopee-track-header">
                            Order Tracking: <span id="trackRefHeader">—</span>
                        </div>
                        <div class="shopee-track-info">
                            <div>
                                <i class="bi bi-shop"></i>
                                <strong>Applied Via:</strong>
                                <span class="value" id="trackAppliedVia">Online</span>
                            </div>
                            <div>
                                <i class="bi bi-info-circle"></i>
                                <strong>Status:</strong>
                                <span class="value" id="trackStatusText">—</span>
                            </div>
                            <div>
                                <i class="bi bi-calendar-event"></i>
                                <strong>Expected:</strong>
                                <span class="value" id="trackExpected">—</span>
                            </div>
                        </div>
                        <div class="shopee-steps-wrap">
                            <div class="shopee-steps" id="shopeeSteps"></div>
                        </div>
                    </div>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6 class="text-uppercase small fw-bold text-muted mb-2">
                                <i class="bi bi-briefcase me-1"></i>Business Details
                            </h6>
                            <div class="track-detail-row"><span class="track-detail-label">Business Name</span><span class="track-detail-value" id="tdBusinessName">—</span></div>
                            <div class="track-detail-row"><span class="track-detail-label">Trade Name</span><span class="track-detail-value" id="tdTradeName">—</span></div>
                            <div class="track-detail-row"><span class="track-detail-label">Address</span><span class="track-detail-value" id="tdAddress">—</span></div>
                            <div class="track-detail-row"><span class="track-detail-label">Application Type</span><span class="track-detail-value" id="tdAppType">—</span></div>
                            <div class="track-detail-row"><span class="track-detail-label">Payment Mode</span><span class="track-detail-value" id="tdPaymentMode">—</span></div>
                            <div class="track-detail-row"><span class="track-detail-label">TIN</span><span class="track-detail-value" id="tdTin">—</span></div>
                            <div class="track-detail-row" id="tdExpiryRow" style="display:none;">
                                <span class="track-detail-label">Expiry Date</span>
                                <span class="track-detail-value" id="tdExpiry">—</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-uppercase small fw-bold text-muted mb-2">
                                <i class="bi bi-person-badge me-1"></i>Owner / Contact
                            </h6>
                            <div class="track-detail-row"><span class="track-detail-label">Owner</span><span class="track-detail-value" id="tdOwner">—</span></div>
                            <div class="track-detail-row"><span class="track-detail-label">Email</span><span class="track-detail-value" id="tdEmail">—</span></div>
                            <div class="track-detail-row"><span class="track-detail-label">Mobile</span><span class="track-detail-value" id="tdMobile">—</span></div>
                            <div class="track-detail-row"><span class="track-detail-label">Filed on</span><span class="track-detail-value" id="tdCreated">—</span></div>
                            <div class="track-detail-row" id="tdSubmittedRow" style="display:none;">
                                <span class="track-detail-label">Submitted on</span>
                                <span class="track-detail-value" id="tdSubmitted">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 p-3 bg-light rounded-3 d-flex align-items-center gap-3">
                        <img id="trackQrImg" src="" alt="QR Code" style="width:90px;height:90px;border:1px solid #e5e7eb;border-radius:8px;padding:4px;background:#fff;">
                        <div>
                            <div class="fw-bold text-dark"><i class="bi bi-qr-code me-1"></i>Scan to verify</div>
                            <small class="text-muted">Anyone can scan this QR code to confirm the permit is authentic.</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #e5e7eb;">
                <button type="button" class="btn btn-green" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- QR SCANNER MODAL -->
<div class="modal fade" id="qrScannerModal" tabindex="-1" aria-labelledby="qrScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border: none;">
            <div class="modal-header" style="background: #f8f9fa; border-bottom: 2px solid #FBBF24; border-radius: 12px 12px 0 0;">
                <h5 class="modal-title" id="qrScannerModalLabel" style="color: #172233; font-weight: 700;">
                    <i class="bi bi-qr-code-scan me-2"></i>Scan QR Code
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center bg-light">
                <div id="qr-reader" class="mb-2"></div>
                <div id="qr-reader-results"></div>
                <div class="d-md-none mt-3">
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-dark" id="switchCamBtn" style="border-color:#172233;color:#172233;">
                            <i class="bi bi-arrow-repeat"></i> Switch Camera
                        </button>
                        <button type="button" class="btn btn-outline-dark" id="takePhotoBtn" style="border-color:#172233;color:#172233;">
                            <i class="bi bi-camera"></i> Take Photo
                        </button>
                        <button type="button" class="btn btn-outline-dark" id="uploadQrBtn" style="border-color:#172233;color:#172233;">
                            <i class="bi bi-image"></i> Upload Image (Gallery / Screenshot)
                        </button>
                    </div>
                    <input type="file" id="qrCameraInput" accept="image/*" capture="environment" class="d-none">
                    <input type="file" id="qrFileInput" accept="image/*" class="d-none">
                    <small class="text-muted d-block mt-2">
                        Tip: If the QR is on another screen or blurred, use <strong>Take Photo</strong> or <strong>Upload Image</strong>.
                    </small>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid rgba(23,34,51,0.1); border-radius: 0 0 12px 12px;">
                <button type="button" class="btn btn-dark" data-bs-dismiss="modal" style="background:#172233;border:none;padding:0.5rem 2rem;">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<!-- SCRIPT 1: Announcements + FullCalendar -->
<script>
(function () {
    'use strict';

    const annDetailModal = document.getElementById('announcementDetailModal');
    if (annDetailModal) {
        annDetailModal.addEventListener('show.bs.modal', function(event) {
            const b = event.relatedTarget;
            if (!b) return;
            document.getElementById('annDetailTitle').textContent   = b.getAttribute('data-title') || 'Announcement';
            document.getElementById('annDetailDate').textContent    = b.getAttribute('data-date') || '';
            document.getElementById('annDetailContent').textContent = b.getAttribute('data-content') || '';
        });
    }

    const phHolidays = [
        { date:'2025-01-01', title:'🎉 New Year\'s Day', type:'regular' },
        { date:'2025-01-29', title:'🏮 Chinese New Year', type:'special' },
        { date:'2025-02-25', title:'📜 EDSA Revolution Anniversary', type:'special' },
        { date:'2025-04-09', title:'⚔️ Araw ng Kagitingan', type:'regular' },
        { date:'2025-04-17', title:'✝️ Maundy Thursday', type:'regular' },
        { date:'2025-04-18', title:'✝️ Good Friday', type:'regular' },
        { date:'2025-04-19', title:'✝️ Black Saturday', type:'special' },
        { date:'2025-05-01', title:'🛠️ Labor Day', type:'regular' },
        { date:'2025-06-12', title:'🇵🇭 Independence Day', type:'regular' },
        { date:'2025-08-21', title:'🕊️ Ninoy Aquino Day', type:'special' },
        { date:'2025-08-25', title:'🦸 National Heroes Day', type:'regular' },
        { date:'2025-11-01', title:'🕯️ All Saints\' Day', type:'special' },
        { date:'2025-11-30', title:'🎖️ Bonifacio Day', type:'regular' },
        { date:'2025-12-08', title:'🙏 Immaculate Conception', type:'special' },
        { date:'2025-12-25', title:'🎄 Christmas Day', type:'regular' },
        { date:'2025-12-30', title:'🏛️ Rizal Day', type:'regular' },
        { date:'2025-12-31', title:'🎆 Last Day of the Year', type:'special' },
        { date:'2026-01-01', title:'🎉 New Year\'s Day', type:'regular' },
        { date:'2026-02-17', title:'🏮 Chinese New Year', type:'special' },
        { date:'2026-02-25', title:'📜 EDSA Revolution Anniversary', type:'special' },
        { date:'2026-04-02', title:'✝️ Maundy Thursday', type:'regular' },
        { date:'2026-04-03', title:'✝️ Good Friday', type:'regular' },
        { date:'2026-04-04', title:'✝️ Black Saturday', type:'special' },
        { date:'2026-04-09', title:'⚔️ Araw ng Kagitingan', type:'regular' },
        { date:'2026-05-01', title:'🛠️ Labor Day', type:'regular' },
        { date:'2026-06-12', title:'🇵🇭 Independence Day', type:'regular' },
        { date:'2026-08-21', title:'🕊️ Ninoy Aquino Day', type:'special' },
        { date:'2026-08-31', title:'🦸 National Heroes Day', type:'regular' },
        { date:'2026-11-01', title:'🕯️ All Saints\' Day', type:'special' },
        { date:'2026-11-30', title:'🎖️ Bonifacio Day', type:'regular' },
        { date:'2026-12-08', title:'🙏 Immaculate Conception', type:'special' },
        { date:'2026-12-25', title:'🎄 Christmas Day', type:'regular' },
        { date:'2026-12-30', title:'🏛️ Rizal Day', type:'regular' },
        { date:'2026-12-31', title:'🎆 Last Day of the Year', type:'special' }
    ];

    const announcementsData = <?php
        echo json_encode(
            array_map(function($a) {
                return [
                    'id'      => (int)$a['id'],
                    'title'   => $a['title'],
                    'content' => $a['content'],
                    'date'    => $a['created_at']
                ];
            }, $announcements),
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    ?>;

    const events = [];
    announcementsData.forEach(function(ann) {
        const dateOnly = (ann.date || '').split(' ')[0];
        if (!dateOnly) return;
        events.push({
            id: 'ann-' + ann.id,
            title: '📢 ' + ann.title,
            start: dateOnly,
            allDay: true,
            backgroundColor: '#172233',
            borderColor: '#2a3b52',
            textColor: '#ffffff',
            extendedProps: { type:'announcement', content: ann.content, created_at: ann.date }
        });
    });
    phHolidays.forEach(function(h, i) {
        events.push({
            id: 'hol-' + i,
            title: h.title,
            start: h.date,
            allDay: true,
            backgroundColor: '#dc3545',
            borderColor: '#b02a37',
            textColor: '#ffffff',
            extendedProps: { type:'holiday', holidayType: h.type }
        });
    });

    let announcementCalendar = null;

    function initAnnouncementCalendar() {
        if (announcementCalendar) { setTimeout(function(){ announcementCalendar.updateSize(); }, 150); return; }
        const el = document.getElementById('announcementCalendar');
        if (!el || typeof FullCalendar === 'undefined') return;

        announcementCalendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            headerToolbar: { left:'prev,next today', center:'title', right:'dayGridMonth,listMonth' },
            height: 'auto',
            navLinks: true,
            editable: false,
            selectable: false,
            dayMaxEvents: 4,
            events: events,
            eventTimeFormat: { hour:'numeric', minute:'2-digit', meridiem:'short' },
            dayHeaderFormat: { weekday:'short' },
            eventDisplay: 'block',
            eventContent: function(arg) {
                const p = arg.event.extendedProps;
                const titleEl = document.createElement('div');
                titleEl.innerHTML = arg.event.title;
                titleEl.style.fontWeight = p.type === 'holiday' ? '700' : '600';
                titleEl.style.fontSize = '0.75rem';
                titleEl.style.whiteSpace = 'normal';
                titleEl.style.wordBreak = 'break-word';
                titleEl.style.lineHeight = '1.2';
                return { domNodes: [titleEl] };
            },
            eventClick: function(info) {
                const p = info.event.extendedProps;
                const eventDate = info.event.start.toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
                if (p.type === 'holiday') {
                    Swal.fire({
                        title: '<i class="bi bi-calendar-x text-danger"></i> Philippine Holiday',
                        html: '<div class="text-start"><h5 class="fw-bold" style="color:#dc3545;">'+info.event.title+'</h5>'+
                              '<p class="mb-2"><strong>Date:</strong> '+eventDate+'</p>'+
                              '<p class="mb-0"><strong>Type:</strong> <span class="badge bg-danger">'+(p.holidayType==='regular'?'Regular Holiday':'Special Non-Working Day')+'</span></p></div>',
                        confirmButtonColor: '#172233', confirmButtonText: 'Close', width: 500
                    });
                } else {
                    Swal.fire({
                        title: '<i class="bi bi-megaphone-fill text-success"></i> Announcement',
                        html: '<div class="text-start"><h5 class="fw-bold" style="color:#172233;">'+info.event.title.replace('📢 ','')+'</h5>'+
                              '<p class="text-muted small mb-3"><i class="bi bi-calendar3 me-1"></i>'+eventDate+'</p><hr>'+
                              '<p style="white-space:pre-wrap;text-align:left;">'+(p.content||'No content')+'</p></div>',
                        confirmButtonColor: '#172233', confirmButtonText: 'Close', width: 600
                    });
                }
            }
        });
        announcementCalendar.render();
    }

    const calendarModalEl = document.getElementById('announcementCalendarModal');
    if (calendarModalEl) {
        calendarModalEl.addEventListener('shown.bs.modal', function() {
            setTimeout(initAnnouncementCalendar, 100);
        });
    }
})();
</script>

<!-- SCRIPT 2: Track (Shopee-style modal) + QR Scanner -->
<script>
(function () {
    'use strict';

    /* ---------- AUTH MODAL TAB AUTO-SWITCH ---------- */
    document.addEventListener('DOMContentLoaded', function() {
        const authModal = document.getElementById('authModal');
        if (authModal) {
            authModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                if (!button) return;
                const tab = button.getAttribute('data-tab');
                if (tab === 'register') {
                    const t = document.getElementById('register-tab');
                    if (t) t.click();
                } else if (tab === 'login') {
                    const t = document.getElementById('login-tab');
                    if (t) t.click();
                }
            });
        }
    });

    /* ---------- DOM refs ---------- */
    const trackRefEl    = document.getElementById('trackRef');
    const trackBtnEl    = document.getElementById('trackBtn');
    const trackModalEl  = document.getElementById('trackResultModal');
    const trackModal    = (trackModalEl && typeof bootstrap !== 'undefined')
                              ? bootstrap.Modal.getOrCreateInstance(trackModalEl)
                              : null;

    const trackLoading  = document.getElementById('trackLoading');
    const trackError    = document.getElementById('trackError');
    const trackErrorMsg = document.getElementById('trackErrorMsg');
    const trackData     = document.getElementById('trackData');

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value || '—';
    }

    function renderShopeeSteps(steps) {
        const wrap = document.getElementById('shopeeSteps');
        if (!wrap) return;
        wrap.innerHTML = '';

        steps.forEach(function(step) {
            const state = step.state || 'pending';
            const stateClass =
                state === 'completed' ? 'is-completed' :
                state === 'active'    ? 'is-active'    :
                state === 'rejected'  ? 'is-rejected'  : 'is-pending';

            const el = document.createElement('div');
            el.className = 'shopee-step ' + stateClass;
            el.innerHTML =
                '<div class="shopee-step-circle">' +
                    '<i class="bi ' + step.icon + '"></i>' +
                '</div>' +
                '<div class="shopee-step-label">' + step.label + '</div>' +
                '<div class="shopee-step-date">' + (step.date || '') + '</div>';
            wrap.appendChild(el);
        });
    }

    function renderTrackResult(payload) {
        const d = payload.data;

        setText('trackRefHeader', d.reference_no);
        setText('trackAppliedVia', 'Online');
        setText('trackStatusText', d.status_label || '—');
        setText('trackExpected',  d.expected_date || '—');

        renderShopeeSteps(d.steps || []);

        setText('tdBusinessName', d.business_name);
        setText('tdTradeName',    d.trade_name || '—');
        setText('tdAddress',      d.business_address || '—');
        setText('tdAppType',      d.application_type);
        setText('tdPaymentMode',  d.payment_mode);
        setText('tdTin',          d.tin_no);

        const expiryRow = document.getElementById('tdExpiryRow');
        if (d.expiry_date) {
            expiryRow.style.display = 'flex';
            const expEl = document.getElementById('tdExpiry');
            expEl.innerHTML = d.expiry_date +
                ' <span class="badge bg-' + d.expiry_status.class + ' ms-1">' + d.expiry_status.label + '</span>';
        } else {
            expiryRow.style.display = 'none';
        }

        setText('tdOwner',   d.owner_name);
        setText('tdEmail',   d.email);
        setText('tdMobile',  d.mobile);
        setText('tdCreated', d.created_at);

        const submittedRow = document.getElementById('tdSubmittedRow');
        if (d.submitted_at) {
            submittedRow.style.display = 'flex';
            setText('tdSubmitted', d.submitted_at);
        } else {
            submittedRow.style.display = 'none';
        }

        const qrImg = document.getElementById('trackQrImg');
        if (qrImg) qrImg.src = d.qr_url;
    }

    async function performTrack(ref) {
        ref = (ref || '').trim();
        if (!ref) {
            alert('Please enter a reference number (e.g., BP-2026-00123)');
            return;
        }
        if (!trackModal) return;

        trackLoading.style.display = 'block';
        trackError.style.display   = 'none';
        trackData.style.display    = 'none';
        trackModal.show();

        try {
            const res = await fetch('track_api.php?ref=' + encodeURIComponent(ref), {
                headers: { 'Accept': 'application/json' }
            });
            const payload = await res.json();

            trackLoading.style.display = 'none';

            if (!payload.success) {
                trackError.style.display = 'block';
                trackErrorMsg.textContent = payload.error || 'Unknown error.';
                return;
            }

            renderTrackResult(payload);
            trackData.style.display = 'block';
        } catch (err) {
            console.error('Track fetch error:', err);
            trackLoading.style.display = 'none';
            trackError.style.display   = 'block';
            trackErrorMsg.textContent  = 'Could not reach the server. Please try again.';
        }
    }

    if (trackBtnEl) {
        trackBtnEl.addEventListener('click', function () {
            performTrack(trackRefEl ? trackRefEl.value : '');
        });
    }
    if (trackRefEl) {
        trackRefEl.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performTrack(trackRefEl.value);
            }
        });
    }

    /* ---------- QR SCANNER ---------- */
    const qrScannerModalEl = document.getElementById('qrScannerModal');
    const qrResultsEl      = document.getElementById('qr-reader-results');

    let html5QrCode       = null;
    let isScanning        = false;
    let currentFacingMode = 'environment';
    let scanLocked        = false;

    function setQrMessage(html) { if (qrResultsEl) qrResultsEl.innerHTML = html || ''; }

    function startQrScanner() {
        if (isScanning) return;
        if (typeof Html5Qrcode === 'undefined') {
            setQrMessage('<div class="alert alert-warning small mb-0">QR library not loaded.</div>');
            return;
        }
        if (!html5QrCode) html5QrCode = new Html5Qrcode('qr-reader');

        const config = { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 };
        html5QrCode
            .start({ facingMode: currentFacingMode }, config, onScanSuccess, onScanError)
            .then(function(){ isScanning = true; })
            .catch(function(err){
                isScanning = false;
                console.error('Camera start error:', err);
                setQrMessage('<div class="alert alert-warning small mb-0">Unable to start the camera. Please allow camera access, or use "Take Photo" / "Upload Image" instead.</div>');
            });
    }

    function stopQrScanner() {
        if (html5QrCode && isScanning) {
            return html5QrCode.stop()
                .then(function(){ isScanning = false; })
                .catch(function(err){ isScanning = false; console.error('Stop error:', err); });
        }
        return Promise.resolve();
    }

    if (qrScannerModalEl) {
        qrScannerModalEl.addEventListener('shown.bs.modal', function () {
            scanLocked = false;
            setQrMessage('');
            setTimeout(startQrScanner, 200);
        });
        qrScannerModalEl.addEventListener('hidden.bs.modal', function () {
            stopQrScanner();
            setQrMessage('');
            scanLocked = false;
        });
    }

    const switchCamBtn = document.getElementById('switchCamBtn');
    if (switchCamBtn) {
        switchCamBtn.addEventListener('click', function () {
            currentFacingMode = (currentFacingMode === 'environment') ? 'user' : 'environment';
            setQrMessage('');
            stopQrScanner().then(startQrScanner);
        });
    }

    function loadImageFromFile(file) {
        return new Promise(function(resolve, reject){
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload  = function(){ URL.revokeObjectURL(url); resolve(img); };
            img.onerror = function(e){ URL.revokeObjectURL(url); reject(e); };
            img.src = url;
        });
    }
    function cloneImageData(id) {
        try { return new ImageData(new Uint8ClampedArray(id.data), id.width, id.height); }
        catch (e) {
            const c = document.createElement('canvas');
            c.width = id.width; c.height = id.height;
            const cx = c.getContext('2d');
            cx.putImageData(id, 0, 0);
            return cx.getImageData(0, 0, id.width, id.height);
        }
    }
    function applyGrayscaleContrast(id, contrast) {
        const d = id.data, intercept = 128 * (1 - contrast);
        for (let i = 0; i < d.length; i += 4) {
            let g = 0.299*d[i] + 0.587*d[i+1] + 0.114*d[i+2];
            g = g * contrast + intercept;
            g = g < 0 ? 0 : g > 255 ? 255 : g;
            d[i] = d[i+1] = d[i+2] = g;
        }
        return id;
    }
    function applyBinarize(id, threshold) {
        const d = id.data;
        for (let i = 0; i < d.length; i += 4) {
            const g = 0.299*d[i] + 0.587*d[i+1] + 0.114*d[i+2];
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
                if (fmts && fmts.includes('qr_code')) detector = new BarcodeDetector({ formats:['qr_code'] });
            }
        } catch (e) {}

        const longest = Math.max(img.width, img.height);
        const baseScale = longest > 1600 ? 1600/longest : 1;
        const srcCanvas = document.createElement('canvas');
        srcCanvas.width  = Math.max(1, Math.round(img.width * baseScale));
        srcCanvas.height = Math.max(1, Math.round(img.height * baseScale));
        srcCanvas.getContext('2d').drawImage(img, 0, 0, srcCanvas.width, srcCanvas.height);

        const workCanvas = document.createElement('canvas');
        const workCtx    = workCanvas.getContext('2d', { willReadFrequently: true });

        const rotations    = [0, 90, 180, 270];
        const scaleFactors = [1, 1.5, 0.75, 2];

        for (let r = 0; r < rotations.length; r++) {
            const rot = rotations[r];
            const rotCanvas = document.createElement('canvas');
            const rotCtx    = rotCanvas.getContext('2d', { willReadFrequently: true });
            if (rot === 0 || rot === 180) {
                rotCanvas.width  = srcCanvas.width;
                rotCanvas.height = srcCanvas.height;
            } else {
                rotCanvas.width  = srcCanvas.height;
                rotCanvas.height = srcCanvas.width;
            }
            rotCtx.translate(rotCanvas.width/2, rotCanvas.height/2);
            rotCtx.rotate(rot * Math.PI / 180);
            rotCtx.drawImage(srcCanvas, -srcCanvas.width/2, -srcCanvas.height/2);

            for (let sIdx = 0; sIdx < scaleFactors.length; sIdx++) {
                const s = scaleFactors[sIdx];
                const w = Math.round(rotCanvas.width * s);
                const h = Math.round(rotCanvas.height * s);
                if (w < 100 || h < 100 || w > 2500 || h > 2500) continue;

                workCanvas.width  = w;
                workCanvas.height = h;
                workCtx.drawImage(rotCanvas, 0, 0, w, h);

                if (detector) {
                    try {
                        const codes = await detector.detect(workCanvas);
                        if (codes && codes.length) return codes[0].rawValue;
                    } catch (e) {}
                }
                const orig = workCtx.getImageData(0, 0, w, h);

                try { const c = jsQR(orig.data, w, h, { inversionAttempts:'attemptBoth' }); if (c && c.data) return c.data; } catch(e){}

                const gcId = cloneImageData(orig);
                applyGrayscaleContrast(gcId, 1.6);
                try { const c = jsQR(gcId.data, w, h, { inversionAttempts:'attemptBoth' }); if (c && c.data) return c.data; } catch(e){}

                const thresholds = [100, 128, 160, 190];
                for (let tIdx = 0; tIdx < thresholds.length; tIdx++) {
                    const bId = cloneImageData(orig);
                    applyBinarize(bId, thresholds[tIdx]);
                    try { const c = jsQR(bId.data, w, h, { inversionAttempts:'attemptBoth' }); if (c && c.data) return c.data; } catch(e){}
                }

                if (detector) {
                    try {
                        workCtx.putImageData(gcId, 0, 0);
                        const codes = await detector.detect(workCanvas);
                        if (codes && codes.length) return codes[0].rawValue;
                    } catch (e) {}
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

            setQrMessage('<div class="text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>Reading image…</div>');
            await stopQrScanner();

            try {
                const decoded = await decodeQrFromImage(file);
                if (decoded) {
                    setQrMessage('');
                    onScanSuccess(decoded);
                } else {
                    setQrMessage('<div class="alert alert-warning small mb-0">No QR code detected in that image. Try a clearer / closer photo, or crop the image so the QR fills more of it.</div>');
                    startQrScanner();
                }
            } catch (err) {
                console.error('Decode error:', err);
                setQrMessage('<div class="alert alert-warning small mb-0">Error reading image. Please try again.</div>');
                startQrScanner();
            }
        });
    }
    handleQrFileInput('qrCameraInput');
    handleQrFileInput('qrFileInput');

    const takePhotoBtn = document.getElementById('takePhotoBtn');
    if (takePhotoBtn) takePhotoBtn.addEventListener('click', function(){ document.getElementById('qrCameraInput').click(); });

    const uploadQrBtn = document.getElementById('uploadQrBtn');
    if (uploadQrBtn) uploadQrBtn.addEventListener('click', function(){ document.getElementById('qrFileInput').click(); });

    function onScanSuccess(decodedText) {
        if (scanLocked) return;
        scanLocked = true;

        let ref = (decodedText || '').trim();

        if (ref.includes('?') || ref.includes('ref=') || /^https?:\/\//i.test(ref)) {
            try {
                const url = new URL(ref, window.location.origin + window.location.pathname);
                const refParam = url.searchParams.get('ref');
                if (refParam) ref = refParam;
            } catch (e) {
                const match = ref.match(/[?&]ref=([^&]+)/);
                if (match) ref = decodeURIComponent(match[1]);
            }
        }

        if (!ref) {
            scanLocked = false;
            setQrMessage('<div class="alert alert-warning small mb-0">That QR code did not contain a reference number.</div>');
            return;
        }

        if (trackRefEl) trackRefEl.value = ref;

        stopQrScanner().then(function () {
            const qrInst = bootstrap.Modal.getOrCreateInstance(qrScannerModalEl);
            qrInst.hide();

            setTimeout(function () {
                performTrack(ref);
            }, 350);
        });
    }

    function onScanError() { /* ignore */ }

})();
</script>

<script>
(function () {
    'use strict';

    const placeMapModalEl = document.getElementById('placeMapModal');
    if (!placeMapModalEl) return;

    const placeMapModal = new bootstrap.Modal(placeMapModalEl);

    const titleEl       = document.getElementById('placeMapTitle');
    const bannerEl      = document.getElementById('placeMapBanner');
    const bannerTitleEl = document.getElementById('placeMapBannerTitle');
    const bannerDescEl  = document.getElementById('placeMapBannerDesc');
    const iframeEl      = document.getElementById('placeMapIframe');
    const openBtnEl     = document.getElementById('placeMapOpenBtn');
    const placeholderEl = document.getElementById('placeMapPlaceholder');
    const loadBtnEl     = document.getElementById('placeMapLoadBtn');

    let pendingQuery = '';
    let mapLoaded    = false;

    function loadMapNow() {
        if (!pendingQuery || mapLoaded) return;
        const encoded = encodeURIComponent(pendingQuery);
        iframeEl.src = 'https://www.google.com/maps?q=' + encoded + '&output=embed&z=14';
        mapLoaded = true;

        placeholderEl.style.display = 'none';
        iframeEl.style.opacity = '1';
        iframeEl.style.pointerEvents = 'auto';
    }

    function openPlaceMap(el) {
        const name  = el.getAttribute('data-place-name')  || 'Location';
        const desc  = el.getAttribute('data-place-desc')  || '';
        const query = el.getAttribute('data-place-query') || name;
        const img   = el.getAttribute('data-place-img')   || '';

        pendingQuery = query;
        mapLoaded = false;

        placeholderEl.style.display = 'flex';
        iframeEl.style.opacity = '0';
        iframeEl.style.pointerEvents = 'none';
        iframeEl.src = 'about:blank';

        titleEl.textContent = name;

        if (img) {
            bannerEl.style.backgroundImage = "url('" + img + "')";
            bannerEl.style.display = 'block';
        } else {
            bannerEl.style.display = 'none';
        }
        bannerTitleEl.textContent = name;
        bannerDescEl.textContent  = desc;

        openBtnEl.href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(query);

        placeMapModal.show();
    }

    if (loadBtnEl) {
        loadBtnEl.addEventListener('click', loadMapNow);
    }

    document.querySelectorAll('.place-card').forEach(function (card) {
        card.addEventListener('click', function () {
            openPlaceMap(card);
        });
    });

    document.querySelectorAll('.attraction-card').forEach(function (card) {
        card.addEventListener('click', function (e) {
            e.preventDefault();

            const seeMoreEl = document.getElementById('seeMoreModal');
            const seeMoreInst = seeMoreEl
                ? bootstrap.Modal.getOrCreateInstance(seeMoreEl)
                : null;

            if (seeMoreInst) {
                seeMoreEl.addEventListener('hidden.bs.modal', function onHidden() {
                    seeMoreEl.removeEventListener('hidden.bs.modal', onHidden);
                    openPlaceMap(card);
                });
                seeMoreInst.hide();
            } else {
                openPlaceMap(card);
            }
        });
    });

    placeMapModalEl.addEventListener('hidden.bs.modal', function () {
        iframeEl.src = 'about:blank';
        iframeEl.style.opacity = '0';
        iframeEl.style.pointerEvents = 'none';
        placeholderEl.style.display = 'flex';
        mapLoaded = false;
    });

})();
</script>

<?php include 'footer/footer.php'; ?>
</body>
</html>