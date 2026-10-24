<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/* ============================================================
   NOTIFICATION LOGIC (owners = user_type 1)
   ============================================================ */
$isOwner = isset($_SESSION['user_id'])
        && isset($_SESSION['user_type'])
        && (int)$_SESSION['user_type'] === 1;

// Determine the current page so the public navigation highlights the correct link.
$currentPage = basename(parse_url($_SERVER['PHP_SELF'] ?? 'index.php', PHP_URL_PATH));

$unreadCount   = 0;
$notifications = [];

if ($isOwner) {
    require_once __DIR__ . '/../config/connect.php';
    require_once __DIR__ . '/../notifications_helper.php';

    $userId        = (int)$_SESSION['user_id'];
    $notifications = getUnreadNotifications($conn, $userId, 5);
    $unreadCount   = countUnreadNotifications($conn, $userId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <title>Buyawanong Placer | Municipality of Placer</title>
    <style>
        :root { 
            --primary-color: #172233;
            --primary-color-dark: #0f1722;
            --primary-color-light: #e9ecef;
            --primary-color-pale: #f8f9fa;
            --dark-blue: #172233; 
            --nav-active-bg: #f1f3f5;
            --auth-btn-dark: #14213d;
        }
        
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        
        /* Top and Bottom Lines */
        .top-line { height: 4px; background: var(--primary-color); }
        .bottom-line { height: 3px; background: var(--dark-blue); }
        
        /* Navbar Base */
        .navbar { background: #fff; }
        .dashboard-header { border-bottom: 1px solid rgba(23, 34, 51, 0.2); box-shadow: 0 2px 8px rgba(23, 34, 51, 0.05); }
        
        /* Brand / Logo Area */
        .brand-title { font-size: 1.1rem; font-weight: 700; margin: 0; color: var(--dark-blue); }
        .brand-subtitle { font-size: .75rem; color: #6c757d; margin: 0; }
        
        /* Navigation Links */
        .nav-link-custom { 
            border: none; padding: .5rem 1rem !important; border-radius: 8px; margin: 0 .15rem; 
            color: var(--dark-blue) !important; font-size: .95rem; font-weight: 500; transition: all .2s; 
        }
        .nav-link-custom:hover { background: var(--primary-color-light); color: var(--primary-color) !important; }
        .nav-link-custom.active { background: var(--nav-active-bg); color: var(--dark-blue) !important; font-weight: 600; }
        
        /* Login Button */
        .login-btn { 
            background: var(--dark-blue); border: 1px solid var(--dark-blue); border-radius: 8px; 
            padding: .5rem 1.2rem; text-decoration: none; color: #fff; display: flex; 
            align-items: center; gap: 8px; transition: all .2s; font-weight: 500; 
        }
        .login-btn:hover { background: #2a3b52; border-color: #2a3b52; color: #fff; }
        
        /* Auth Modal Styling (Matching Screenshots) */
        .auth-modal .modal-content { border-radius: 16px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .auth-modal .modal-header { border-bottom: none; padding: 1.5rem 1.5rem 0.5rem; }
        .auth-modal .modal-title { font-weight: 700; color: var(--dark-blue); font-size: 1.5rem; }
        .auth-modal .nav-pills { background: #f1f3f5; border-radius: 10px; padding: 4px; margin-bottom: 1.5rem; }
        .auth-modal .nav-pills .nav-link { border-radius: 8px; color: #495057; font-weight: 600; font-size: 0.9rem; padding: 0.6rem 1rem; }
        .auth-modal .nav-pills .nav-link.active { background: #fff; color: var(--dark-blue); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .auth-modal .form-control { border-radius: 8px; padding: 0.75rem 1rem; border: 1px solid #dee2e6; background: #f8f9fa; font-size: 0.9rem; }
        .auth-modal .form-control:focus { border-color: var(--dark-blue); box-shadow: 0 0 0 3px rgba(23,34,51,0.1); background: #fff; }
        .auth-modal .form-label { font-size: 0.8rem; font-weight: 600; color: #343a40; margin-bottom: 0.4rem; }
        .auth-modal .info-note { background: #f8f9fa; border-left: 4px solid var(--primary-color); padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.8rem; color: #495057; margin-bottom: 1.5rem; }
        .auth-modal .btn-dark-blue { background: var(--auth-btn-dark); color: #fff; border-radius: 8px; padding: 0.75rem; font-weight: 600; width: 100%; border: none; transition: all 0.2s; }
        .auth-modal .btn-dark-blue:hover { background: #0d1629; }
        .auth-modal .btn-outline-cancel { background: #fff; color: #495057; border: 1px solid #ced4da; border-radius: 8px; padding: 0.75rem; font-weight: 600; width: 100%; transition: all 0.2s; }
        .auth-modal .btn-outline-cancel:hover { background: #f8f9fa; }

        /* Notification Bell & Dropdowns */
        .notif-bell { position: relative; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; border-radius: 50%; color: var(--dark-blue); text-decoration: none; transition: background .2s, color .2s; margin-left: 0.5rem; border: none; background: transparent; }
        .notif-bell:hover { background: var(--primary-color-light); color: var(--primary-color); outline: none; }
        .notif-bell i { font-size: 1.25rem; }
        .notif-badge { position: absolute; top: 4px; right: 4px; min-width: 18px; height: 18px; padding: 0 5px; background: #dc3545; color: #fff; font-size: 0.65rem; font-weight: 700; border-radius: 9px; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; line-height: 1; }
        .notif-dropdown { width: 340px; max-width: calc(100vw - 2rem); padding: 0; border: none; box-shadow: 0 8px 24px rgba(23, 34, 51, 0.12); border-radius: 10px; overflow: hidden; }
        .notif-header { display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1rem; background: var(--dark-blue); color: #fff; }
        .notif-header h6 { margin: 0; font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .notif-header .badge { background: var(--primary-color) !important; font-size: 0.7rem; }
        .notif-list { max-height: 360px; overflow-y: auto; }
        .notif-item { display: flex; gap: 10px; padding: 0.75rem 1rem; border-bottom: 1px solid #f1f3f5; text-decoration: none; color: inherit; transition: background .15s; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: var(--primary-color-pale); }
        .notif-icon { flex-shrink: 0; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; color: #fff; }
        .notif-content { flex: 1; min-width: 0; }
        .notif-title { font-size: 0.85rem; font-weight: 600; color: var(--dark-blue); margin: 0 0 2px; }
        .notif-msg { font-size: 0.78rem; color: #6c757d; margin: 0 0 4px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .notif-time { font-size: 0.7rem; color: #adb5bd; }
        .notif-footer { padding: 0.6rem 1rem; text-align: center; background: #f8f9fa; border-top: 1px solid #f1f3f5; }
        .notif-footer a { font-size: 0.8rem; font-weight: 600; color: var(--primary-color); text-decoration: none; }
        
        /* User greeting & dropdown */
        .user-greeting { background: var(--primary-color); border-radius: 40px; padding: 0.5rem 1.2rem; font-size: 0.9rem; font-weight: 500; color: #fff !important; margin-left: 0.5rem; border: none; }
        .user-greeting:hover { background: var(--primary-color-dark); color: #fff !important; }
        .dropdown-item:active { background: var(--primary-color); color: #fff; }

        /* OTP Inputs */
        .otp-input-group { display: flex; gap: 0.5rem; justify-content: center; margin: 1.5rem 0; }
        .otp-digit { width: 50px; height: 60px; text-align: center; font-size: 1.5rem; font-weight: 700; border: 2px solid #dee2e6; border-radius: 10px; background: #f8f9fa; }
        .otp-digit:focus { border-color: var(--auth-btn-dark); box-shadow: 0 0 0 3px rgba(20, 33, 61, 0.1); outline: none; background: #fff; }

        /* Password reveal toggle */
        .password-wrapper { position: relative; }
        .password-wrapper .form-control { padding-right: 2.75rem; }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 0.5rem;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            color: #6c757d;
            border-radius: 50%;
            cursor: pointer;
            transition: color .2s, background .2s;
            z-index: 5;
        }
        .password-toggle:hover { color: var(--dark-blue); background: rgba(23,34,51,.06); }
        .password-toggle:focus { outline: none; box-shadow: 0 0 0 3px rgba(23,34,51,.1); }

        .forgot-link {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--primary-color);
            text-decoration: none;
        }
        .forgot-link:hover { text-decoration: underline; color: var(--primary-color-dark); }

        .step-pane { animation: fadeIn .25s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        /* Responsive navbar: keep the session name and dropdown aligned */
        .navbar-nav > .nav-item.dropdown { position: relative; }
        .user-greeting {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            max-width: 260px;
            white-space: normal;
            overflow-wrap: anywhere;
            line-height: 1.35;
        }
        #userMenu { min-width: 200px; }

        @media (max-width: 991.98px) {
            .navbar-collapse {
                margin-top: .75rem;
                padding-bottom: .5rem;
            }
            .navbar-collapse .navbar-nav {
                width: 100%;
                align-items: stretch !important;
            }
            .navbar-collapse .navbar-nav > .nav-item {
                width: 100%;
            }
            .nav-link-custom {
                display: flex;
                align-items: center;
                justify-content: center;
                margin: .3rem 0;
                text-align: center;
                border-radius: 8px;
            }
            .login-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                margin-top: .5rem;
                text-align: center;
            }
            .navbar-collapse .nav-item.dropdown {
                width: 100%;
            }
            .user-greeting {
                display: flex;
                width: 100%;
                max-width: 100%;
                min-height: 42px;
                margin: .5rem 0 0 !important;
                padding: .65rem .9rem;
                text-align: center;
                box-sizing: border-box;
            }
            .notif-bell {
                margin: .5rem auto 0;
            }
            .notif-dropdown,
            #userMenu {
                width: 100%;
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }
        }

        @media (max-width: 400px) {
            .navbar-brand { gap: .4rem !important; }
            .navbar-brand img { width: 38px !important; height: 38px !important; }
            .brand-title { font-size: .95rem; }
            .brand-subtitle { font-size: .65rem; }
            .navbar-toggler { padding: .25rem .45rem; }
        }

        .back-to-top { position: fixed; bottom: 25px; right: 25px; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transform: translateY(20px); transition: all .25s ease; z-index: 999; background: var(--primary-color); border: none; color: #fff; }
        .back-to-top:hover { background: var(--primary-color-dark); color: #fff; }
        .back-to-top.show { opacity: 1; visibility: visible; transform: translateY(0); }
    </style>
</head>
<body>

<div class="top-line"></div>

<nav class="navbar navbar-expand-lg shadow-sm <?php echo isset($_SESSION['user_id']) ? 'dashboard-header' : ''; ?>">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <img src="img/official-logo.png" alt="Municipality of Placer Logo" style="height: 45px; width: 45px; border-radius: 50%; object-fit: cover;">
            <div>
                <h6 class="brand-title">Buyawanong Placer</h6>
                <p class="brand-subtitle">Municipality of Placer, Surigao del Norte</p>
            </div>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="mainNavbar">
            <?php if (isset($_SESSION['user_id'])): ?>
                <ul class="navbar-nav align-items-lg-center">
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                    </li>
                    <?php if ($isOwner): ?>
                        <li class="nav-item dropdown" style="position: relative;">
                            <button type="button" class="notif-bell" id="notifDropdown" aria-expanded="false" aria-label="Notifications">
                                <i class="bi bi-bell<?php echo $unreadCount > 0 ? '-fill' : ''; ?>"></i>
                                <?php if ($unreadCount > 0): ?>
                                    <span class="notif-badge" id="notifBadge"><?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?></span>
                                <?php endif; ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end notif-dropdown" id="notifMenu">
                                <li class="notif-header">
                                    <h6><i class="bi bi-bell-fill"></i> Notifications</h6>
                                    <?php if ($unreadCount > 0): ?><span class="badge rounded-pill"><?php echo $unreadCount; ?> new</span><?php endif; ?>
                                </li>
                                <li>
                                    <div class="notif-list">
                                        <?php if (!empty($notifications)): ?>
                                            <?php foreach ($notifications as $n):
                                                $meta = notificationMeta($n['type']); $isClickable = notifIsClickable($n['link']); $tag = $isClickable ? 'a' : 'div';
                                            ?>
                                                <<?php echo $tag; ?> class="notif-item<?php echo $isClickable ? '' : ' notif-no-link'; ?>" <?php echo notifHref($n['link']); ?> data-notif-id="<?php echo (int)$n['id']; ?>">
                                                    <div class="notif-icon" style="background: <?php echo $meta['color']; ?>;"><i class="bi <?php echo $meta['icon']; ?>"></i></div>
                                                    <div class="notif-content">
                                                        <p class="notif-title"><?php echo htmlspecialchars($n['title']); ?></p>
                                                        <?php if (!empty($n['message'])): ?><p class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></p><?php endif; ?>
                                                        <span class="notif-time"><i class="bi bi-clock"></i> <?php echo timeAgo($n['created_at']); ?></span>
                                                    </div>
                                                </<?php echo $tag; ?>>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="notif-empty text-center py-4 text-muted"><i class="bi bi-bell-slash fs-3 d-block mb-2"></i>No new notifications</div>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <li class="notif-footer"><a href="notifications.php">View all notifications</a></li>
                            </ul>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item dropdown" style="position: relative;">
                        <button type="button" class="nav-link user-greeting dropdown-toggle" id="userDropdown" aria-expanded="false">
                            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" id="userMenu">
                            <li><a class="dropdown-item" href="account_settings.php"><i class="bi bi-gear"></i> Account Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            <?php else: ?>
                <ul class="navbar-nav align-items-lg-center">
                    <li class="nav-item"><a class="nav-link nav-link-custom <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" href="index.php" <?php echo $currentPage === 'index.php' ? 'aria-current="page"' : ''; ?>>Home</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom <?php echo $currentPage === 'permit.php' ? 'active' : ''; ?>" href="permit.php" <?php echo $currentPage === 'permit.php' ? 'aria-current="page"' : ''; ?>>Permits</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom <?php echo $currentPage === 'about.php' ? 'active' : ''; ?>" href="about.php" <?php echo $currentPage === 'about.php' ? 'aria-current="page"' : ''; ?>>About</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom <?php echo $currentPage === 'contact.php' ? 'active' : ''; ?>" href="contact.php" <?php echo $currentPage === 'contact.php' ? 'aria-current="page"' : ''; ?>>Contact</a></li>
                    <li class="nav-item ms-lg-3">
                        <!-- Changed to trigger the modal instead of direct link -->
                        <button type="button" class="login-btn" data-bs-toggle="modal" data-bs-target="#authModal">
                            <i class="bi bi-box-arrow-in-right"></i> Log in
                        </button>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="bottom-line"></div>

<!-- ================= AUTH MODAL (LOGIN & REGISTER) ================= -->
<div class="modal fade auth-modal" id="authModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Business permit portal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 pb-4">
                <!-- Tabs -->
                <ul class="nav nav-pills nav-fill" id="authTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="login-tab" data-bs-toggle="pill" data-bs-target="#login-pane" type="button" role="tab">Log in</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="register-tab" data-bs-toggle="pill" data-bs-target="#register-pane" type="button" role="tab">Create account</button>
                    </li>
                </ul>

                <div class="tab-content" id="authTabsContent">
                    <!-- LOGIN FORM -->
                    <div class="tab-pane fade show active" id="login-pane" role="tabpanel">
                        <div class="info-note">Only registered business owners in Placer can open the permit form.</div>
                        <form id="modalLoginForm">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" placeholder="you@email.com" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Password</label>
                                <div class="password-wrapper">
                                    <input type="password" class="form-control" name="password" id="modalLoginPassword" placeholder="At least 8 characters" required>
                                    <button type="button" class="password-toggle" data-target="modalLoginPassword" tabindex="-1" aria-label="Show password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-4 text-end">
                                <a href="#" class="forgot-link" id="openForgotPassword">Forgot password?</a>
                            </div>
                            <button type="submit" class="btn btn-dark-blue mb-2">Log in</button>
                            <button type="button" class="btn btn-outline-cancel" data-bs-dismiss="modal">Cancel</button>
                        </form>
                    </div>

                    <!-- REGISTER FORM -->
                    <div class="tab-pane fade" id="register-pane" role="tabpanel">
                        <div class="info-note">Only business owners operating in Placer may register.</div>
                        <form id="modalRegisterForm">
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">First name</label>
                                    <input type="text" class="form-control" name="firstName" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Last name</label>
                                    <input type="text" class="form-control" name="lastName" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Mobile number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">+63</span>
                                    <input type="tel" class="form-control" name="contact" placeholder="912 345 6789" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" placeholder="you@email.com" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <div class="password-wrapper">
                                    <input type="password" class="form-control" name="password" id="modalRegisterPassword" placeholder="At least 8 characters" required minlength="8">
                                    <button type="button" class="password-toggle" data-target="modalRegisterPassword" tabindex="-1" aria-label="Show password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Confirm password</label>
                                <div class="password-wrapper">
                                    <input type="password" class="form-control" name="confirm_password" id="modalConfirmPassword" placeholder="Re-enter password" required minlength="8">
                                    <button type="button" class="password-toggle" data-target="modalConfirmPassword" tabindex="-1" aria-label="Show password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-dark-blue mb-2">Create account</button>
                            <button type="button" class="btn btn-outline-cancel" data-bs-dismiss="modal">Cancel</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= FORGOT PASSWORD MODAL ================= -->
<div class="modal fade auth-modal" id="forgotPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reset your password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 pb-4">

                <!-- STEP 1: Email -->
                <div class="step-pane" id="fpStep1">
                    <div class="info-note">Enter the email address linked to your account. We'll send a 6-digit code to verify it's really you.</div>
                    <form id="fpEmailForm" autocomplete="off">
                        <div class="mb-4">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" placeholder="you@email.com" required>
                        </div>
                        <button type="submit" class="btn btn-dark-blue mb-2">Send verification code</button>
                        <button type="button" class="btn btn-outline-cancel" data-bs-dismiss="modal">Cancel</button>
                    </form>
                </div>

                <!-- STEP 2: OTP -->
                <div class="step-pane" id="fpStep2" style="display:none;">
                    <p class="mb-2 text-center">We've sent a verification code to</p>
                    <p class="fw-bold text-dark text-center" id="fpEmailDisplay">your@email.com</p>
                    <div class="otp-input-group">
                        <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" id="fpOtp1" autofocus>
                        <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" id="fpOtp2">
                        <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" id="fpOtp3">
                        <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" id="fpOtp4">
                        <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" id="fpOtp5">
                        <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" id="fpOtp6">
                    </div>
                    <div class="mt-3 text-center">
                        <p class="timer-text small text-muted" id="fpTimerText">
                            <i class="bi bi-clock-history"></i> Code expires in <span id="fpTimerCountdown">02:00</span>
                        </p>
                        <a href="#" class="text-decoration-none fw-bold" id="fpResendLink" style="color: var(--primary-color);">Didn't receive the code? Resend</a>
                    </div>
                    <div id="fpOtpError" class="alert alert-danger small mt-3" style="display:none;"></div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-cancel" id="fpBackBtn">Back</button>
                        <button type="button" class="btn btn-dark-blue" id="fpVerifyBtn">Verify code</button>
                    </div>
                </div>

                <!-- STEP 3: New password -->
                <div class="step-pane" id="fpStep3" style="display:none;">
                    <div class="info-note">Choose a new password. It must be at least 8 characters long.</div>
                    <form id="fpResetForm" autocomplete="off">
                        <div class="mb-3">
                            <label class="form-label">New password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" name="password" id="fpNewPassword" placeholder="At least 8 characters" required>
                                <button type="button" class="password-toggle" data-target="fpNewPassword" tabindex="-1" aria-label="Show password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Confirm new password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" name="confirm_password" id="fpConfirmPassword" placeholder="Re-enter password" required>
                                <button type="button" class="password-toggle" data-target="fpConfirmPassword" tabindex="-1" aria-label="Show password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div id="fpResetError" class="alert alert-danger small" style="display:none;"></div>
                        <button type="submit" class="btn btn-dark-blue mb-2">Reset password</button>
                        <button type="button" class="btn btn-outline-cancel" data-bs-dismiss="modal">Cancel</button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ================= OTP MODAL ================= -->
<div class="modal fade" id="otpModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; border: none;">
            <div class="modal-header" style="border-bottom: 2px solid var(--primary-color);">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-envelope-paper fs-4" style="color: var(--primary-color);"></i>
                    <h5 class="modal-title fw-bold" style="color: var(--dark-blue);">Email Verification</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mb-2">We've sent a one-time password (OTP) to</p>
                <p class="fw-bold text-dark" id="otpEmailDisplay">your@email.com</p>
                <div class="otp-input-group">
                    <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp1" autofocus>
                    <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp2">
                    <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp3">
                    <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp4">
                    <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp5">
                    <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp6">
                </div>
                <div class="mt-3">
                    <p class="timer-text small text-muted" id="timerText"><i class="bi bi-clock-history"></i> Code expires in <span id="timerCountdown">02:00</span></p>
                    <a href="#" class="text-decoration-none fw-bold" id="resendOtpLink" style="color: var(--primary-color);">Didn't receive the code? Resend</a>
                </div>
                <div id="otpError" class="alert alert-danger small mt-3" style="display: none;"></div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-dark-blue" id="verifyOtpBtn" style="width: auto; padding: 0.5rem 1.5rem;">Verify & Register</button>
            </div>
        </div>
    </div>
</div>

<button id="backToTop" class="back-to-top rounded-circle shadow">
    <i class="bi bi-arrow-up"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
(function () {
    'use strict';

    // Back to top
    var backToTop = document.getElementById('backToTop');
    if (backToTop) {
        window.addEventListener('scroll', function () {
            if (window.scrollY > 300) backToTop.classList.add('show');
            else backToTop.classList.remove('show');
        });
        backToTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Auto-close mobile menu
        var navbarCollapse = document.getElementById('mainNavbar');
        if (navbarCollapse) {
            var navLinks = document.querySelectorAll('#mainNavbar .navbar-nav > .nav-item > a.nav-link-custom');
            navLinks.forEach(function (link) {
                link.addEventListener('click', function () {
                    if (navbarCollapse.classList.contains('show') && typeof bootstrap !== 'undefined') {
                        var inst = bootstrap.Collapse.getInstance(navbarCollapse);
                        if (inst) inst.hide();
                    }
                });
            });
        }

        // ============================================================
        // MANUAL DROPDOWN SETUP (Notifications & User Menu)
        // ============================================================
        function setupDropdown(triggerId, menuId) {
            var trigger = document.getElementById(triggerId);
            var menu    = document.getElementById(menuId);
            if (!trigger || !menu) return;
            var isOpen = false;

            function openMenu() {
                isOpen = true; menu.classList.add('show'); menu.style.display = 'block';
                menu.style.position = 'absolute';
                menu.style.top = '100%';
                menu.style.right = '0';
                menu.style.left = 'auto';
                menu.style.marginTop = '0.4rem';
                menu.style.zIndex = '1055';
                /* On mobile, the parent nav item spans the available menu width. */
                if (window.matchMedia('(max-width: 991.98px)').matches) {
                    menu.style.width = '100%';
                    menu.style.maxWidth = '100%';
                    menu.style.minWidth = '0';
                } else {
                    menu.style.width = '';
                    menu.style.maxWidth = '';
                    menu.style.minWidth = '';
                }
                trigger.setAttribute('aria-expanded', 'true');
            }
            function closeMenu() {
                isOpen = false; menu.classList.remove('show'); menu.style.display = '';
                trigger.setAttribute('aria-expanded', 'false');
            }
            trigger.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); isOpen ? closeMenu() : openMenu(); });
            menu.addEventListener('click', function (e) { if (e.target.closest('a.dropdown-item')) return; e.stopPropagation(); });
            document.addEventListener('click', function (e) { if (isOpen && !trigger.contains(e.target) && !menu.contains(e.target)) closeMenu(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen) closeMenu(); });
            window.addEventListener('resize', function () {
                if (isOpen) closeMenu();
            });
        }
        setupDropdown('notifDropdown', 'notifMenu');
        setupDropdown('userDropdown', 'userMenu');

        // Mark notification as read
        document.querySelectorAll('.notif-item[data-notif-id]').forEach(function (item) {
            item.addEventListener('click', function () {
                var id = this.getAttribute('data-notif-id');
                if (!id) return;
                fetch('mark_notification_read.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id }), keepalive: true
                }).catch(function () {});
                var badge = document.getElementById('notifBadge');
                if (badge) {
                    var count = parseInt(badge.textContent, 10) || 0;
                    count = Math.max(0, count - 1);
                    if (count === 0) badge.remove(); else badge.textContent = count > 99 ? '99+' : count;
                }
            });
        });

        // ============================================================
        // AUTH MODAL LOGIC (LOGIN & REGISTER)
        // ============================================================
        
        // 1. Handle Login Form Submission
        const loginForm = document.getElementById('modalLoginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Logging in...';
                btn.disabled = true;

                const formData = new FormData(this);
                try {
                    const response = await fetch('login.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        Swal.fire({
                            icon: 'success', title: 'Login Successful!',
                            text: 'Welcome back, ' + result.name + '!',
                            timer: 1500, showConfirmButton: false
                        }).then(() => { window.location.href = result.redirect; });
                    } else {
                        Swal.fire('Error', result.error, 'error');
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                } catch (error) {
                    Swal.fire('Error', 'Something went wrong. Please try again.', 'error');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
        }

        // 2. Handle Register Form Submission (Send OTP)
        const registerForm = document.getElementById('modalRegisterForm');
        if (registerForm) {
            registerForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                // Validate passwords match
                const pwd = this.querySelector('input[name="password"]').value;
                const confirmPwd = this.querySelector('input[name="confirm_password"]').value;
                if (pwd !== confirmPwd) {
                    Swal.fire('Error', 'Passwords do not match.', 'error');
                    return;
                }

                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending OTP...';
                btn.disabled = true;

                const formData = new FormData(this);
                formData.append('action', 'send_otp');

                try {
                    const response = await fetch('register.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        // Hide auth modal, show OTP modal
                        bootstrap.Modal.getInstance(document.getElementById('authModal')).hide();
                        document.getElementById('otpEmailDisplay').innerText = formData.get('email');
                        resetOtpModalState();
                        startResendCooldown(30);
                        
                        const otpModal = new bootstrap.Modal(document.getElementById('otpModal'));
                        otpModal.show();
                        setTimeout(() => document.getElementById('otp1').focus(), 300);
                    } else {
                        Swal.fire('Registration Error', result.errors.join('\n'), 'error');
                    }
                } catch (error) {
                    Swal.fire('Error', 'Failed to connect to the server.', 'error');
                } finally {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
        }

        // 3. OTP Handling Logic
        let timerInterval = null;
        let timeLeft = 120;
        let resendCooldownInterval = null;

        function setupOtpInputs() {
            const inputs = ['otp1','otp2','otp3','otp4','otp5','otp6'];
            inputs.forEach((id, idx) => {
                const inp = document.getElementById(id);
                if(!inp) return;
                inp.addEventListener('input', function() {
                    if (this.value.length === 1 && idx < 5) document.getElementById(inputs[idx+1]).focus();
                });
                inp.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && this.value.length === 0 && idx > 0) document.getElementById(inputs[idx-1]).focus();
                });
            });
        }

        function startTimer() {
            if (timerInterval) clearInterval(timerInterval);
            timeLeft = 120;
            updateTimerDisplay();
            timerInterval = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(timerInterval); timerInterval = null;
                    document.getElementById('timerText').innerHTML = '<i class="bi bi-hourglass-split"></i> Code expired. Please request a new one.';
                    document.querySelectorAll('.otp-digit').forEach(inp => inp.disabled = true);
                } else {
                    timeLeft--; updateTimerDisplay();
                }
            }, 1000);
        }

        function updateTimerDisplay() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            document.getElementById('timerCountdown').innerText = `${minutes.toString().padStart(2,'0')}:${seconds.toString().padStart(2,'0')}`;
        }

        function getEnteredOTP() {
            let otp = '';
            for (let i=1; i<=6; i++) {
                const val = document.getElementById(`otp${i}`).value;
                if (!val.match(/[0-9]/)) return '';
                otp += val;
            }
            return otp;
        }

        function startResendCooldown(seconds = 30) {
            const link = document.getElementById('resendOtpLink');
            if (resendCooldownInterval) clearInterval(resendCooldownInterval);
            let remaining = seconds;
            link.classList.add('disabled');
            link.style.pointerEvents = 'none';
            link.style.opacity = '0.55';
            link.innerText = `Resend available in ${remaining}s`;
            resendCooldownInterval = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(resendCooldownInterval); resendCooldownInterval = null;
                    link.classList.remove('disabled');
                    link.style.pointerEvents = 'auto';
                    link.style.opacity = '1';
                    link.innerText = "Didn't receive the code? Resend";
                } else {
                    link.innerText = `Resend available in ${remaining}s`;
                }
            }, 1000);
        }

        function resetOtpModalState() {
            document.querySelectorAll('.otp-digit').forEach(inp => { inp.value = ''; inp.disabled = false; });
            document.getElementById('otpError').style.display = 'none';
            document.getElementById('timerText').innerHTML = '<i class="bi bi-clock-history"></i> Code expires in <span id="timerCountdown">02:00</span>';
            startTimer();
        }

        setupOtpInputs();

        // Verify OTP
        document.getElementById('verifyOtpBtn')?.addEventListener('click', async function() {
            const otp = getEnteredOTP();
            if (otp.length !== 6) {
                document.getElementById('otpError').innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Please enter the complete 6-digit code.';
                document.getElementById('otpError').style.display = 'block';
                return;
            }
            const btn = this;
            btn.disabled = true; btn.innerText = 'Verifying...';
            
            const formBody = new URLSearchParams();
            formBody.append('action', 'verify_otp');
            formBody.append('otp', otp);

            try {
                const response = await fetch('register.php', {
                    method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formBody
                });
                const result = await response.json();
                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('otpModal')).hide();
                    Swal.fire({
                        icon: 'success', title: 'Registration Successful!',
                        text: 'Your account has been created. Redirecting to dashboard...',
                        timer: 2000, showConfirmButton: false
                    }).then(() => { window.location.href = result.redirect; });
                } else {
                    document.getElementById('otpError').innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> ${result.errors.join('<br>')}`;
                    document.getElementById('otpError').style.display = 'block';
                    for (let i=1;i<=6;i++) document.getElementById(`otp${i}`).value = '';
                    document.getElementById('otp1').focus();
                }
            } catch (error) {
                document.getElementById('otpError').innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Server error. Please try again.';
                document.getElementById('otpError').style.display = 'block';
            } finally {
                btn.disabled = false; btn.innerText = 'Verify & Register';
            }
        });

        // Resend OTP
        document.getElementById('resendOtpLink')?.addEventListener('click', async function(e) {
            e.preventDefault();
            if (this.classList.contains('disabled')) return;
            
            Swal.fire({ title: 'Resending OTP...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            const formBody = new URLSearchParams();
            formBody.append('action', 'resend_otp');

            try {
                const response = await fetch('register.php', {
                    method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formBody
                });
                const result = await response.json();
                Swal.close();
                if (result.success) {
                    resetOtpModalState();
                    startResendCooldown(30);
                    document.getElementById('otp1').focus();
                    Swal.fire('Sent!', 'A new OTP has been sent to your email.', 'success');
                } else {
                    Swal.fire('Error', result.errors.join('\n'), 'error');
                }
            } catch (error) {
                Swal.close();
                Swal.fire('Error', 'Failed to resend OTP. Please try again.', 'error');
            }
        });
    });
})();

// ============================================================
// PASSWORD REVEAL TOGGLE (works for all current + future fields)
// ============================================================
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.password-toggle');
    if (!btn) return;
    e.preventDefault();
    const input = document.getElementById(btn.getAttribute('data-target'));
    if (!input) return;
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
        btn.setAttribute('aria-label', 'Hide password');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
        btn.setAttribute('aria-label', 'Show password');
    }
});

// ============================================================
// FORGOT PASSWORD FLOW
// ============================================================
(function initForgotPassword() {
    const authModalEl = document.getElementById('authModal');
    const fpModalEl   = document.getElementById('forgotPasswordModal');
    if (!fpModalEl) return;

    const fpModal = new bootstrap.Modal(fpModalEl);

    const step1 = document.getElementById('fpStep1');
    const step2 = document.getElementById('fpStep2');
    const step3 = document.getElementById('fpStep3');

    const emailDisplay = document.getElementById('fpEmailDisplay');
    const otpError     = document.getElementById('fpOtpError');
    const resetError   = document.getElementById('fpResetError');

    let fpTimerInterval = null;
    let fpCooldownInterval = null;
    let fpTimeLeft = 120;

    function showStep(n) {
        step1.style.display = n === 1 ? '' : 'none';
        step2.style.display = n === 2 ? '' : 'none';
        step3.style.display = n === 3 ? '' : 'none';
    }

    function resetToStep1() {
        showStep(1);
        document.getElementById('fpEmailForm').reset();
        for (let i = 1; i <= 6; i++) {
            const inp = document.getElementById('fpOtp' + i);
            inp.value = ''; inp.disabled = false;
        }
        document.getElementById('fpResetForm').reset();
        otpError.style.display = 'none';
        resetError.style.display = 'none';
        if (fpTimerInterval) { clearInterval(fpTimerInterval); fpTimerInterval = null; }
        if (fpCooldownInterval) { clearInterval(fpCooldownInterval); fpCooldownInterval = null; }
    }

    // Open from login form
    document.getElementById('openForgotPassword')?.addEventListener('click', function (e) {
        e.preventDefault();
        const inst = bootstrap.Modal.getInstance(authModalEl);
        if (inst) inst.hide();
        setTimeout(() => { resetToStep1(); fpModal.show(); }, 300);
    });

    // Clear state on close
    fpModalEl.addEventListener('hidden.bs.modal', function () {
        if (fpTimerInterval) { clearInterval(fpTimerInterval); fpTimerInterval = null; }
        if (fpCooldownInterval) { clearInterval(fpCooldownInterval); fpCooldownInterval = null; }
    });

    // ---------- Step 1: send OTP ----------
    document.getElementById('fpEmailForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';

        const body = new URLSearchParams();
        body.append('action', 'send_reset_otp');
        body.append('email', this.email.value.trim());

        try {
            const res = await fetch('forgot_password.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body
            });
            const data = await res.json();
            if (data.success) {
                emailDisplay.innerText = data.email;
                showStep(2);
                startFpTimer();
                startFpResendCooldown(30);
                setTimeout(() => document.getElementById('fpOtp1').focus(), 200);
            } else {
                Swal.fire('Error', (data.errors || ['Something went wrong.']).join('\n'), 'error');
            }
        } catch (err) {
            Swal.fire('Error', 'Failed to connect to the server.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });

    // ---------- Step 2: OTP inputs ----------
    (function setupFpOtpInputs() {
        const ids = ['fpOtp1','fpOtp2','fpOtp3','fpOtp4','fpOtp5','fpOtp6'];
        ids.forEach((id, idx) => {
            const inp = document.getElementById(id);
            if (!inp) return;
            inp.addEventListener('input', function () {
                if (this.value.length === 1 && idx < 5) document.getElementById(ids[idx + 1]).focus();
            });
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && this.value.length === 0 && idx > 0) {
                    document.getElementById(ids[idx - 1]).focus();
                }
            });
        });
    })();

    function getFpOtp() {
        let otp = '';
        for (let i = 1; i <= 6; i++) {
            const v = document.getElementById('fpOtp' + i).value;
            if (!/[0-9]/.test(v)) return '';
            otp += v;
        }
        return otp;
    }

    function startFpTimer() {
        if (fpTimerInterval) clearInterval(fpTimerInterval);
        fpTimeLeft = 120;
        updateFpTimerDisplay();
        document.getElementById('fpTimerText').innerHTML =
            '<i class="bi bi-clock-history"></i> Code expires in <span id="fpTimerCountdown">02:00</span>';
        fpTimerInterval = setInterval(() => {
            if (fpTimeLeft <= 0) {
                clearInterval(fpTimerInterval); fpTimerInterval = null;
                document.getElementById('fpTimerText').innerHTML =
                    '<i class="bi bi-hourglass-split"></i> Code expired. Please request a new one.';
                document.querySelectorAll('#fpStep2 .otp-digit').forEach(i => i.disabled = true);
            } else {
                fpTimeLeft--;
                updateFpTimerDisplay();
            }
        }, 1000);
    }
    function updateFpTimerDisplay() {
        const m = Math.floor(fpTimeLeft / 60);
        const s = fpTimeLeft % 60;
        const el = document.getElementById('fpTimerCountdown');
        if (el) el.innerText = `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    }

    function startFpResendCooldown(sec) {
        const link = document.getElementById('fpResendLink');
        if (fpCooldownInterval) clearInterval(fpCooldownInterval);
        let remaining = sec;
        link.style.pointerEvents = 'none';
        link.style.opacity = '0.55';
        link.innerText = `Resend available in ${remaining}s`;
        fpCooldownInterval = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(fpCooldownInterval); fpCooldownInterval = null;
                link.style.pointerEvents = 'auto';
                link.style.opacity = '1';
                link.innerText = "Didn't receive the code? Resend";
            } else {
                link.innerText = `Resend available in ${remaining}s`;
            }
        }, 1000);
    }

    // Verify OTP
    document.getElementById('fpVerifyBtn').addEventListener('click', async function () {
        const otp = getFpOtp();
        if (otp.length !== 6) {
            otpError.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Please enter the complete 6-digit code.';
            otpError.style.display = 'block';
            return;
        }
        const btn = this;
        const original = btn.innerText;
        btn.disabled = true;
        btn.innerText = 'Verifying...';

        const body = new URLSearchParams();
        body.append('action', 'verify_reset_otp');
        body.append('otp', otp);

        try {
            const res = await fetch('forgot_password.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body
            });
            const data = await res.json();
            if (data.success) {
                otpError.style.display = 'none';
                if (fpTimerInterval) { clearInterval(fpTimerInterval); fpTimerInterval = null; }
                showStep(3);
                setTimeout(() => document.getElementById('fpNewPassword').focus(), 200);
            } else {
                otpError.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> ${(data.errors || []).join('<br>')}`;
                otpError.style.display = 'block';
                for (let i = 1; i <= 6; i++) document.getElementById('fpOtp' + i).value = '';
                document.getElementById('fpOtp1').focus();
            }
        } catch (err) {
            otpError.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Server error. Please try again.';
            otpError.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.innerText = original;
        }
    });

    // Back button
    document.getElementById('fpBackBtn').addEventListener('click', function () {
        if (fpTimerInterval) { clearInterval(fpTimerInterval); fpTimerInterval = null; }
        showStep(1);
    });

    // Resend
    document.getElementById('fpResendLink').addEventListener('click', async function (e) {
        e.preventDefault();
        if (this.style.pointerEvents === 'none') return;

        Swal.fire({ title: 'Resending code...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const body = new URLSearchParams();
        body.append('action', 'resend_reset_otp');

        try {
            const res = await fetch('forgot_password.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body
            });
            const data = await res.json();
            Swal.close();
            if (data.success) {
                for (let i = 1; i <= 6; i++) {
                    const inp = document.getElementById('fpOtp' + i);
                    inp.value = ''; inp.disabled = false;
                }
                otpError.style.display = 'none';
                startFpTimer();
                startFpResendCooldown(30);
                document.getElementById('fpOtp1').focus();
                Swal.fire('Sent!', 'A new code has been sent to your email.', 'success');
            } else {
                Swal.fire('Error', (data.errors || ['Failed.']).join('\n'), 'error');
            }
        } catch (err) {
            Swal.close();
            Swal.fire('Error', 'Failed to resend. Please try again.', 'error');
        }
    });

    // ---------- Step 3: Reset password ----------
    document.getElementById('fpResetForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const pw = this.password.value;
        const cpw = this.confirm_password.value;

        if (pw.length < 8) {
            resetError.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Password must be at least 8 characters.';
            resetError.style.display = 'block';
            return;
        }
        if (pw !== cpw) {
            resetError.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Passwords do not match.';
            resetError.style.display = 'block';
            return;
        }
        resetError.style.display = 'none';

        const btn = this.querySelector('button[type="submit"]');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Resetting...';

        const body = new URLSearchParams();
        body.append('action', 'reset_password');
        body.append('password', pw);
        body.append('confirm_password', cpw);

        try {
            const res = await fetch('forgot_password.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body
            });
            const data = await res.json();
            if (data.success) {
                fpModal.hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Password reset!',
                    text: 'You can now log in with your new password.',
                    timer: 1800,
                    showConfirmButton: false
                }).then(() => {
                    // reopen login modal
                    const loginInst = new bootstrap.Modal(authModalEl);
                    loginInst.show();
                });
            } else {
                resetError.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> ${(data.errors || []).join('<br>')}`;
                resetError.style.display = 'block';
            }
        } catch (err) {
            resetError.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Server error. Please try again.';
            resetError.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });
})();
</script>

</body>
</html>