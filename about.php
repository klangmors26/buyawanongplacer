<?php
require_once 'config/connect.php';
require_once 'config/settings.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>About | Buyawanong Placer</title>

    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand-dark: #1e293b;
            --brand-yellow: #fbbf24;
            --brand-yellow-hover: #f59e0b;
            --bg-light: #f8fafc;
            --text-muted: #64748b;
            --card-radius: 16px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--brand-dark);
            overflow-x: hidden;
        }

        .hero-section {
            background-color: var(--brand-dark);
            color: white;
            position: relative;
            padding: 100px 0 80px;
        }
        .hero-section h1 {
            font-weight: 800;
            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1.1;
            letter-spacing: -0.02em;
            margin-bottom: 20px;
        }
        .hero-section p {
            color: #94a3b8;
            font-size: clamp(1rem, 1.5vw, 1.125rem);
            max-width: 600px;
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .btn-warning-custom {
            background-color: #fbbf24 !important;
            color: #1e293b !important;
            border: 1px solid #fbbf24 !important;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .btn-warning-custom:hover {
            background-color: #f59e0b !important;
            border-color: #f59e0b !important;
            color: #1e293b !important;
            transform: translateY(-2px);
        }

        .btn-outline-light-custom {
            background-color: transparent !important;
            border: 1px solid rgba(255, 255, 255, 0.5) !important;
            color: #ffffff !important;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .btn-outline-light-custom:hover {
            background-color: rgba(255, 255, 255, 0.1) !important;
            border-color: #ffffff !important;
            color: #ffffff !important;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -1px; left: 0;
            width: 100%; height: 30px;
            background-image: radial-gradient(circle at 50% 0%, transparent 25px, var(--bg-light) 26px);
            background-size: 60px 30px;
            background-repeat: repeat-x;
            z-index: 1;
        }

        .info-grid-item h5 {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--brand-dark);
            margin-bottom: 8px;
        }
        .info-grid-item p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 0;
            line-height: 1.5;
        }

        .mission-block {
            background-color: var(--brand-dark);
            border-radius: var(--card-radius);
            padding: 2.5rem;
            color: white;
        }
        .mission-block h3 { font-weight: 700; font-size: 1.5rem; margin-bottom: 1rem; }
        .mission-block p { color: #94a3b8; font-size: 0.95rem; line-height: 1.6; margin-bottom: 0; }

        .key-features h3 { font-weight: 700; font-size: 1.8rem; margin-bottom: 1.5rem; }
        .key-features h5 { font-weight: 700; font-size: 1rem; color: var(--brand-dark); margin-bottom: 6px; }
        .key-features p { color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0; line-height: 1.5; }

        @media (max-width: 768px) {
            .hero-section { padding: 60px 0 50px; }
            .hero-section::after { height: 20px; background-size: 40px 20px; background-image: radial-gradient(circle at 50% 0%, transparent 15px, var(--bg-light) 16px); }
            .mission-block { padding: 1.5rem; }
        }
    </style>
</head>
<body>

<?php include 'header/nav.php'; ?>

<section class="hero-section">
    <div class="container position-relative" style="z-index: 2;">
        <div class="row">
            <div class="col-lg-8">
                <h1 class="mb-3"><?= e_setting($conn, 'about_hero_title', 'About Buyawanong Placer') ?></h1>
                <p class="mb-4"><?= e_setting($conn, 'about_hero_subtitle', 'A web portal for the Municipality of Placer, Surigao del Norte. It brings municipal services online so residents spend less time on paperwork and queues.') ?></p>
                <div class="d-flex flex-wrap gap-3">
                    <a href="#features-section" class="btn btn-warning-custom text-decoration-none">See features</a>
                    <a href="index.php" class="btn btn-outline-light-custom text-decoration-none">Back to home</a>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="container py-5" id="features-section">

    <div class="row g-4 mb-5 border-top border-dark pt-4">
        <?php for ($i = 1; $i <= 3; $i++): ?>
        <div class="col-md-4 info-grid-item">
            <h5><?= e_setting($conn, "about_info_{$i}_title") ?></h5>
            <p><?= e_setting($conn, "about_info_{$i}_desc") ?></p>
        </div>
        <?php endfor; ?>
    </div>

    <div class="mission-block mb-5">
        <h3><?= e_setting($conn, 'about_mission_title', 'Our mission') ?></h3>
        <p><?= e_setting($conn, 'about_mission_text') ?></p>
    </div>

    <div class="key-features border-top border-dark pt-4 mt-5">
        <h3>Key features</h3>
        <div class="row g-4">
            <?php for ($i = 1; $i <= 4; $i++): ?>
            <div class="col-md-6 col-lg-3">
                <h5><?= e_setting($conn, "about_feature_{$i}_title") ?></h5>
                <p><?= e_setting($conn, "about_feature_{$i}_desc") ?></p>
            </div>
            <?php endfor; ?>
        </div>
    </div>

</div>

<?php include 'footer/footer.php' ?>
</body>
</html>