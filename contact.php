<?php
require_once 'config/connect.php';
require_once 'config/settings.php';

$conn->query("CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    topic VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$success_message = '';
$error_message = '';
$old = ['name' => '', 'email' => '', 'topic' => 'Business permit', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $topic   = trim($_POST['topic'] ?? '');
    $message = trim($_POST['message'] ?? '');

    $old = ['name' => $name, 'email' => $email, 'topic' => $topic, 'message' => $message];

    if (empty($name) || empty($email) || empty($topic) || empty($message)) {
        $error_message = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, topic, message, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssss", $name, $email, $topic, $message);

        if ($stmt->execute()) {
            $success_message = '✓ Message sent successfully! We will get back to you soon.';
            $old = ['name' => '', 'email' => '', 'topic' => 'Business permit', 'message' => ''];
        } else {
            $error_message = 'Failed to send message. Please try again.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Contact | Buyawanong Placer</title>

    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
            margin-bottom: 0;
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

        .info-block {
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }
        .info-block:last-child { border-bottom: none; margin-bottom: 0; }
        .info-block h5 {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--brand-dark);
            margin-bottom: 6px;
        }
        .info-block p, .info-block a {
            color: var(--text-muted);
            font-size: 1rem;
            margin-bottom: 0;
            text-decoration: none;
        }
        .info-block a { text-decoration: underline; transition: color 0.2s; }
        .info-block a:hover { color: var(--brand-dark); }

        .form-card {
            background-color: #ffffff;
            border-radius: var(--card-radius);
            padding: 2.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            border: 1px solid #f1f5f9;
        }
        .form-card h3 { font-weight: 700; font-size: 1.5rem; margin-bottom: 4px; }
        .form-card .subtitle { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem; }

        .form-label { font-weight: 600; font-size: 0.85rem; color: var(--brand-dark); margin-bottom: 6px; }
        .form-control, .form-select {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 0.95rem;
            color: var(--brand-dark);
            transition: all 0.2s ease;
        }
        .form-control:focus, .form-select:focus {
            background-color: #ffffff;
            border-color: var(--brand-dark);
            box-shadow: 0 0 0 3px rgba(30, 41, 59, 0.1);
        }
        .form-control::placeholder { color: #94a3b8; }

        .btn-submit {
            background-color: var(--brand-dark);
            color: white;
            font-weight: 600;
            padding: 14px;
            border-radius: 8px;
            width: 100%;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-submit:hover { background-color: #0f172a; color: white; }

        @media (max-width: 768px) {
            .hero-section { padding: 60px 0 50px; }
            .hero-section::after { height: 20px; background-size: 40px 20px; background-image: radial-gradient(circle at 50% 0%, transparent 15px, var(--bg-light) 16px); }
            .form-card { padding: 1.5rem; }
        }
    </style>
</head>
<body>

<?php include 'header/nav.php'; ?>

<section class="hero-section">
    <div class="container position-relative" style="z-index: 2;">
        <div class="row">
            <div class="col-lg-8">
                <h1 class="mb-3"><?= e_setting($conn, 'contact_hero_title', 'Contact the municipal office') ?></h1>
                <p class="mb-4"><?= e_setting($conn, 'contact_hero_subtitle', 'Send your question here or visit the Municipal Hall on a weekday.') ?></p>
            </div>
        </div>
    </div>
</section>

<div class="container py-5">
    <div class="row g-5">

        <!-- ============================================================
             LEFT COLUMN — NOW 100% EDITABLE FROM ADMIN PANEL
             ============================================================ -->
        <div class="col-lg-5">
            <div class="info-block">
                <h5><?= e_setting($conn, 'contact_visit_label', 'Visit') ?></h5>
                <p><?= e_setting($conn, 'contact_visit_value', 'Municipal Hall, Placer, Surigao del Norte') ?></p>
            </div>

            <div class="info-block">
                <h5><?= e_setting($conn, 'contact_hours_label', 'Office hours') ?></h5>
                <p><?= e_setting($conn, 'contact_hours_value', 'Monday to Friday, 8:00 AM to 5:00 PM') ?></p>
            </div>

            <div class="info-block">
                <h5><?= e_setting($conn, 'contact_permit_label', 'Permit questions') ?></h5>
                <?php $permitEmail = e_setting($conn, 'contact_permit_email', 'bplo.placer@gmail.com'); ?>
                <a href="mailto:<?= $permitEmail ?>"><?= $permitEmail ?></a>
            </div>

            <div class="info-block">
                <h5><?= e_setting($conn, 'contact_payment_label', 'Payment questions') ?></h5>
                <?php $payEmail = e_setting($conn, 'contact_payment_email', 'mto.placer@gmail.com'); ?>
                <a href="mailto:<?= $payEmail ?>"><?= $payEmail ?></a>
            </div>
        </div>

        <!-- Right Column: Contact Form -->
        <div class="col-lg-7">
            <div class="form-card">
                <h3><?= e_setting($conn, 'contact_form_title', 'Send a message') ?></h3>
                <p class="subtitle"><?= e_setting($conn, 'contact_form_subtitle', 'We reply by email.') ?></p>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form id="contactForm" method="POST" action="">
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" name="name"
                               placeholder="Juan Dela Cruz"
                               value="<?= htmlspecialchars($old['name']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email"
                               placeholder="juan@email.com"
                               value="<?= htmlspecialchars($old['email']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="topic" class="form-label">Topic</label>
                        <select class="form-select" id="topic" name="topic" required>
                            <option value="Business permit" <?= $old['topic'] === 'Business permit' ? 'selected' : '' ?>>Business permit</option>
                            <option value="Payment inquiry" <?= $old['topic'] === 'Payment inquiry' ? 'selected' : '' ?>>Payment inquiry</option>
                            <option value="General question" <?= $old['topic'] === 'General question' ? 'selected' : '' ?>>General question</option>
                            <option value="Tourism" <?= $old['topic'] === 'Tourism' ? 'selected' : '' ?>>Tourism</option>
                            <option value="Other" <?= $old['topic'] === 'Other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label for="message" class="form-label">Message</label>
                        <textarea class="form-control" id="message" name="message" rows="5"
                                  placeholder="Type your message here..." required><?= htmlspecialchars($old['message']) ?></textarea>
                    </div>

                    <button type="submit" class="btn-submit">Send message</button>
                </form>
            </div>
        </div>

    </div>
</div>

<?php include 'footer/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const contactForm = document.getElementById('contactForm');

        <?php if (!empty($success_message)): ?>
            Swal.fire({
                icon: 'success',
                title: 'Sent!',
                text: '<?= addslashes($success_message) ?>',
                confirmButtonColor: '#1e293b'
            });
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Oops...',
                text: '<?= addslashes($error_message) ?>',
                confirmButtonColor: '#1e293b'
            });
        <?php endif; ?>

        if (contactForm) {
            contactForm.addEventListener('submit', function() {
                const btn = this.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
                }
            });
        }
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>