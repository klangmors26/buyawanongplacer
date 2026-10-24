<?php
/**
 * sidebar.php — Vertical navigation (sidebar) version of nav.php
 * ------------------------------------------------------------------
 * USAGE:
 *   <?php include 'sidebar.php'; ?>   // adjust path as needed
 *
 * REQUIREMENTS (must be loaded by the parent page):
 *   - Bootstrap 5.3 CSS + JS bundle
 *   - Bootstrap Icons
 *   - SweetAlert2
 *   - Inter font (optional)
 *
 * This file outputs ONLY the sidebar + modals + styles/scripts.
 * It does NOT output <html>, <head>, or <body>.
 * ------------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/* ============================================================
   NOTIFICATION LOGIC (owners = user_type 1)
   ============================================================ */
$isOwner = isset($_SESSION['user_id'])
        && isset($_SESSION['user_type'])
        && (int)$_SESSION['user_type'] === 1;

$unreadCount   = 0;
$notifications = [];

if ($isOwner) {
    require_once __DIR__ . '/../config/connect.php';
    require_once __DIR__ . '/../notifications_helper.php';

    $userId        = (int)$_SESSION['user_id'];
    $notifications = getUnreadNotifications($conn, $userId, 5);
    $unreadCount   = countUnreadNotifications($conn, $userId);
}

/* Active-link helper */
$currentPage = basename($_SERVER['PHP_SELF']);
if (!function_exists('sideActive')) {
    function sideActive($file, $current) {
        return $file === $current ? ' active' : '';
    }
}
?>

<!-- ================= SIDEBAR STYLES ================= -->
<style>
    :root {
        --primary-color: #172233;
        --primary-color-dark: #0f1722;
        --primary-color-light: #e9ecef;
        --primary-color-pale: #f8f9fa;
        --dark-blue: #172233;
        --nav-active-bg: #f1f3f5;
        --auth-btn-dark: #14213d;
        --sidebar-width: 265px;
    }

    /* ---------- Layout ---------- */
    .sidebar {
        position: fixed;
        top: 0; left: 0; bottom: 0;
        width: var(--sidebar-width);
        background: var(--primary-color);
        color: #fff;
        display: flex;
        flex-direction: column;
        z-index: 1045;
        transition: transform .28s ease;
        box-shadow: 2px 0 12px rgba(23, 34, 51, .12);
    }
    .sidebar::-webkit-scrollbar { width: 6px; }
    .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.18); border-radius: 3px; }

    /* ---------- Brand ---------- */
    .sidebar-brand {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: 1.1rem 1rem;
        border-bottom: 1px solid rgba(255,255,255,.09);
        text-decoration: none;
        color: #fff;
        flex-shrink: 0;
    }
    .sidebar-brand:hover { color: #fff; }
    .sidebar-brand img {
        height: 44px; width: 44px; border-radius: 50%;
        object-fit: cover; background: #fff; padding: 2px;
        flex-shrink: 0;
    }
    .sidebar-brand .brand-title {
        font-size: 1rem; font-weight: 700; margin: 0;
        color: #fff; line-height: 1.2;
    }
    .sidebar-brand .brand-subtitle {
        font-size: .68rem; color: rgba(255,255,255,.6);
        margin: 0; line-height: 1.25;
    }
    .sidebar-close {
        margin-left: auto;
        background: transparent; border: none; color: rgba(255,255,255,.7);
        font-size: 1.2rem; line-height: 1; padding: .25rem .35rem;
        border-radius: 6px; transition: all .2s;
    }
    .sidebar-close:hover { background: rgba(255,255,255,.1); color: #fff; }

    /* ---------- Scrollable nav area ---------- */
    .sidebar-nav-wrap {
        flex: 1 1 auto;
        overflow-y: auto;
        overflow-x: hidden;
        padding: .9rem .7rem;
    }
    .sidebar-section-label {
        font-size: .68rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: rgba(255,255,255,.42);
        font-weight: 600;
        padding: 0 .6rem;
        margin: .4rem 0 .5rem;
    }

    .sidebar-nav { list-style: none; padding: 0; margin: 0; }
    .sidebar-nav li { margin-bottom: .2rem; }

    .sidebar-link {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .68rem .8rem;
        border-radius: 9px;
        color: rgba(255,255,255,.82) !important;
        text-decoration: none;
        font-size: .9rem;
        font-weight: 500;
        transition: background .18s, color .18s, transform .18s;
        position: relative;
    }
    .sidebar-link i:first-child {
        font-size: 1.05rem;
        width: 20px;
        text-align: center;
        flex-shrink: 0;
    }
    .sidebar-link:hover {
        background: rgba(255,255,255,.08);
        color: #fff !important;
    }
    .sidebar-link.active {
        background: rgba(255,255,255,.14);
        color: #fff !important;
        font-weight: 600;
    }
    .sidebar-link.active::before {
        content: '';
        position: absolute;
        left: 0; top: 50%;
        transform: translateY(-50%);
        width: 3px; height: 60%;
        background: #fff;
        border-radius: 0 3px 3px 0;
    }
    .sidebar-link .link-badge {
        margin-left: auto;
        background: #dc3545;
        color: #fff;
        font-size: .68rem;
        font-weight: 700;
        padding: .15rem .45rem;
        border-radius: 10px;
        line-height: 1.2;
    }

    /* ---------- Footer ---------- */
    .sidebar-footer {
        position: relative;
        flex-shrink: 0;
        padding: .8rem;
        border-top: 1px solid rgba(255,255,255,.09);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .sidebar-user-btn {
        flex: 1 1 auto;
        display: flex;
        align-items: center;
        gap: .6rem;
        background: rgba(255,255,255,.06);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 10px;
        padding: .5rem .65rem;
        color: #fff;
        text-align: left;
        transition: background .2s;
        min-width: 0;
    }
    .sidebar-user-btn:hover { background: rgba(255,255,255,.12); }
    .sidebar-user-btn > i.bi-person-circle { font-size: 1.5rem; flex-shrink: 0; }
    .sidebar-user-meta { min-width: 0; flex: 1 1 auto; }
    .sidebar-user-meta .name {
        display: block;
        font-size: .82rem;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.2;
    }
    .sidebar-user-meta .role {
        display: block;
        font-size: .68rem;
        color: rgba(255,255,255,.55);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar-icon-btn {
        position: relative;
        width: 42px; height: 42px;
        flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: rgba(255,255,255,.06);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 10px;
        color: #fff;
        font-size: 1.1rem;
        transition: background .2s, color .2s;
    }
    .sidebar-icon-btn:hover { background: rgba(255,255,255,.14); color: #fff; }

    .sidebar-icon-btn .notif-badge {
        position: absolute;
        top: -4px; right: -4px;
        min-width: 18px; height: 18px;
        padding: 0 5px;
        background: #dc3545;
        color: #fff;
        font-size: .65rem;
        font-weight: 700;
        border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        border: 2px solid var(--primary-color);
        line-height: 1;
    }

    /* ---------- Dropdowns (open upward, to the right of sidebar) ---------- */
    .sidebar-footer .dropdown-menu {
        position: absolute;
        bottom: calc(100% + 8px);
        top: auto;
        left: .8rem;
        right: auto;
        width: min(340px, calc(100vw - 2rem));
        padding: 0;
        border: none;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, .22);
        overflow: hidden;
        z-index: 1060;
    }

    /* Notification dropdown */
    .sidebar-footer .notif-dropdown { width: min(350px, calc(100vw - 2rem)); }

    .notif-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: .8rem 1rem;
        background: var(--dark-blue);
        color: #fff;
    }
    .notif-header h6 {
        margin: 0; font-size: .88rem; font-weight: 600;
        display: flex; align-items: center; gap: 6px;
    }
    .notif-header .badge { background: var(--primary-color) !important; font-size: .7rem; }

    .notif-list { max-height: 340px; overflow-y: auto; }
    .notif-item {
        display: flex; gap: 10px;
        padding: .75rem 1rem;
        border-bottom: 1px solid #f1f3f5;
        text-decoration: none;
        color: inherit;
        transition: background .15s;
    }
    .notif-item:last-child { border-bottom: none; }
    .notif-item:hover { background: var(--primary-color-pale); }
    .notif-item.notif-no-link { cursor: default; }
    .notif-icon {
        flex-shrink: 0; width: 36px; height: 36px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; color: #fff;
    }
    .notif-content { flex: 1; min-width: 0; }
    .notif-title { font-size: .85rem; font-weight: 600; color: var(--dark-blue); margin: 0 0 2px; }
    .notif-msg {
        font-size: .78rem; color: #6c757d; margin: 0 0 4px;
        overflow: hidden; text-overflow: ellipsis;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    }
    .notif-time { font-size: .7rem; color: #adb5bd; }
    .notif-footer {
        padding: .6rem 1rem; text-align: center;
        background: #f8f9fa; border-top: 1px solid #f1f3f5;
    }
    .notif-footer a {
        font-size: .8rem; font-weight: 600;
        color: var(--primary-color); text-decoration: none;
    }

    /* User dropdown */
    .sidebar-footer .user-menu { padding: .4rem; }
    .sidebar-footer .user-menu .dropdown-item {
        border-radius: 8px;
        font-size: .88rem;
        padding: .55rem .75rem;
        display: flex; align-items: center; gap: .6rem;
    }
    .sidebar-footer .user-menu .dropdown-item:active {
        background: var(--primary-color); color: #fff;
    }

    /* ---------- Mobile toggle + backdrop ---------- */
    .sidebar-toggle {
        position: fixed;
        top: 12px; left: 12px;
        z-index: 1035;
        width: 44px; height: 44px;
        display: none;
        align-items: center; justify-content: center;
        background: var(--primary-color);
        color: #fff;
        border: none;
        border-radius: 10px;
        font-size: 1.3rem;
        box-shadow: 0 4px 12px rgba(23, 34, 51, .25);
    }
    .sidebar-toggle:hover { background: var(--primary-color-dark); }

    .sidebar-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 34, .55);
        z-index: 1040;
        opacity: 0;
        visibility: hidden;
        transition: opacity .25s, visibility .25s;
    }
    .sidebar-backdrop.show { opacity: 1; visibility: visible; }

    /* ---------- Desktop ---------- */
    @media (min-width: 992px) {
        body.has-sidebar { padding-left: var(--sidebar-width); }
        .sidebar-close { display: none; }
        .sidebar-backdrop { display: none; }
    }

    /* ---------- Mobile ---------- */
    @media (max-width: 991.98px) {
        .sidebar { transform: translateX(-100%); }
        .sidebar.show { transform: translateX(0); }
        .sidebar-toggle { display: flex; }
        .sidebar-footer .dropdown-menu {
            left: .8rem;
            width: min(320px, calc(100vw - 2rem));
        }
    }

    /* ---------- Auth Modal Styling ---------- */
    .auth-modal .modal-content { border-radius: 16px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,.1); }
    .auth-modal .modal-header { border-bottom: none; padding: 1.5rem 1.5rem .5rem; }
    .auth-modal .modal-title { font-weight: 700; color: var(--dark-blue); font-size: 1.5rem; }
    .auth-modal .nav-pills { background: #f1f3f5; border-radius: 10px; padding: 4px; margin-bottom: 1.5rem; }
    .auth-modal .nav-pills .nav-link { border-radius: 8px; color: #495057; font-weight: 600; font-size: .9rem; padding: .6rem 1rem; }
    .auth-modal .nav-pills .nav-link.active { background: #fff; color: var(--dark-blue); box-shadow: 0 2px 4px rgba(0,0,0,.05); }
    .auth-modal .form-control { border-radius: 8px; padding: .75rem 1rem; border: 1px solid #dee2e6; background: #f8f9fa; font-size: .9rem; }
    .auth-modal .form-control:focus { border-color: var(--dark-blue); box-shadow: 0 0 0 3px rgba(23,34,51,.1); background: #fff; }
    .auth-modal .form-label { font-size: .8rem; font-weight: 600; color: #343a40; margin-bottom: .4rem; }
    .auth-modal .info-note {
        background: #f8f9fa; border-left: 4px solid var(--primary-color);
        padding: .75rem 1rem; border-radius: 6px; font-size: .8rem;
        color: #495057; margin-bottom: 1.5rem;
    }
    .auth-modal .btn-dark-blue {
        background: var(--auth-btn-dark); color: #fff; border-radius: 8px;
        padding: .75rem; font-weight: 600; width: 100%; border: none; transition: all .2s;
    }
    .auth-modal .btn-dark-blue:hover { background: #0d1629; }
    .auth-modal .btn-outline-cancel {
        background: #fff; color: #495057; border: 1px solid #ced4da;
        border-radius: 8px; padding: .75rem; font-weight: 600;
        width: 100%; transition: all .2s;
    }
    .auth-modal .btn-outline-cancel:hover { background: #f8f9fa; }

    /* OTP Inputs */
    .otp-input-group { display: flex; gap: .5rem; justify-content: center; margin: 1.5rem 0; }
    .otp-digit {
        width: 50px; height: 60px; text-align: center;
        font-size: 1.5rem; font-weight: 700;
        border: 2px solid #dee2e6; border-radius: 10px; background: #f8f9fa;
    }
    .otp-digit:focus {
        border-color: var(--auth-btn-dark);
        box-shadow: 0 0 0 3px rgba(20, 33, 61, .1);
        outline: none; background: #fff;
    }
    @media (max-width: 400px) {
        .otp-digit { width: 42px; height: 52px; font-size: 1.25rem; }
    }
</style>

<!-- ================= SIDEBAR TOGGLE (mobile) ================= -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
    <i class="bi bi-list"></i>
</button>

<!-- ================= SIDEBAR ================= -->
<aside class="sidebar" id="sidebar">

    <!-- Brand -->
    <a class="sidebar-brand" href="index.php">
        <img src="img/official-logo.png" alt="Municipality of Placer Logo">
        <div>
            <h6 class="brand-title">Buyawanong Placer</h6>
            <p class="brand-subtitle">Municipality of Placer, Surigao del Norte</p>
        </div>
        <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Close">
            <i class="bi bi-x-lg"></i>
        </button>
    </a>

    <!-- Nav -->
    <div class="sidebar-nav-wrap">
        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="sidebar-section-label">Menu</div>
            <ul class="sidebar-nav">
                <li>
                    <a class="sidebar-link<?php echo sideActive('dashboard.php', $currentPage); ?>" href="dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <?php if ($isOwner): ?>
                    <li>
                        <a class="sidebar-link<?php echo sideActive('notifications.php', $currentPage); ?>" href="notifications.php">
                            <i class="bi bi-bell"></i> Notifications
                            <?php if ($unreadCount > 0): ?>
                                <span class="link-badge"><?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>
                <li>
                    <a class="sidebar-link<?php echo sideActive('account_settings.php', $currentPage); ?>" href="account_settings.php">
                        <i class="bi bi-gear"></i> Account Settings
                    </a>
                </li>
            </ul>
        <?php else: ?>
            <div class="sidebar-section-label">Menu</div>
            <ul class="sidebar-nav">
                <li>
                    <a class="sidebar-link<?php echo sideActive('index.php', $currentPage); ?>" href="index.php">
                        <i class="bi bi-house-door"></i> Home
                    </a>
                </li>
                <li>
                    <a class="sidebar-link<?php echo sideActive('permit.php', $currentPage); ?>" href="permit.php">
                        <i class="bi bi-file-earmark-text"></i> Permits
                    </a>
                </li>
                <li>
                    <a class="sidebar-link<?php echo sideActive('about.php', $currentPage); ?>" href="about.php">
                        <i class="bi bi-info-circle"></i> About
                    </a>
                </li>
                <li>
                    <a class="sidebar-link<?php echo sideActive('contact.php', $currentPage); ?>" href="contact.php">
                        <i class="bi bi-telephone"></i> Contact
                    </a>
                </li>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="sidebar-footer">
        <?php if (isset($_SESSION['user_id'])): ?>

            <!-- Notifications (owners only) -->
            <?php if ($isOwner): ?>
                <button type="button" class="sidebar-icon-btn" id="notifDropdown"
                        aria-expanded="false" aria-label="Notifications">
                    <i class="bi bi-bell<?php echo $unreadCount > 0 ? '-fill' : ''; ?>"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notif-badge" id="notifBadge">
                            <?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?>
                        </span>
                    <?php endif; ?>
                </button>

                <ul class="dropdown-menu notif-dropdown" id="notifMenu">
                    <li class="notif-header">
                        <h6><i class="bi bi-bell-fill"></i> Notifications</h6>
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge rounded-pill"><?php echo $unreadCount; ?> new</span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <div class="notif-list">
                            <?php if (!empty($notifications)): ?>
                                <?php foreach ($notifications as $n):
                                    $meta        = notificationMeta($n['type']);
                                    $isClickable = notifIsClickable($n['link']);
                                    $tag         = $isClickable ? 'a' : 'div';
                                ?>
                                    <<?php echo $tag; ?> class="notif-item<?php echo $isClickable ? '' : ' notif-no-link'; ?>"
                                        <?php echo notifHref($n['link']); ?>
                                        data-notif-id="<?php echo (int)$n['id']; ?>">
                                        <div class="notif-icon" style="background: <?php echo $meta['color']; ?>;">
                                            <i class="bi <?php echo $meta['icon']; ?>"></i>
                                        </div>
                                        <div class="notif-content">
                                            <p class="notif-title"><?php echo htmlspecialchars($n['title']); ?></p>
                                            <?php if (!empty($n['message'])): ?>
                                                <p class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></p>
                                            <?php endif; ?>
                                            <span class="notif-time">
                                                <i class="bi bi-clock"></i> <?php echo timeAgo($n['created_at']); ?>
                                            </span>
                                        </div>
                                    </<?php echo $tag; ?>>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notif-empty text-center py-4 text-muted">
                                    <i class="bi bi-bell-slash fs-3 d-block mb-2"></i>No new notifications
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                    <li class="notif-footer"><a href="notifications.php">View all notifications</a></li>
                </ul>
            <?php endif; ?>

            <!-- User -->
            <button type="button" class="sidebar-user-btn" id="userDropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i>
                <span class="sidebar-user-meta">
                    <span class="name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                    <span class="role"><?php echo $isOwner ? 'Business Owner' : 'User'; ?></span>
                </span>
                <i class="bi bi-chevron-up small"></i>
            </button>

            <ul class="dropdown-menu user-menu" id="userMenu">
                <li>
                    <a class="dropdown-item" href="account_settings.php">
                        <i class="bi bi-gear"></i> Account Settings
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item text-danger" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </li>
            </ul>

        <?php else: ?>
            <button type="button" class="login-btn w-100" data-bs-toggle="modal" data-bs-target="#authModal"
                    style="background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.15);
                           border-radius: 10px; padding: .65rem 1rem; color: #fff; font-weight: 600;
                           display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="bi bi-box-arrow-in-right"></i> Log in
            </button>
        <?php endif; ?>
    </div>
</aside>

<!-- ================= BACKDROP (mobile) ================= -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- ================= AUTH MODAL (LOGIN & REGISTER) ================= -->
<div class="modal fade auth-modal" id="authModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Business permit portal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 pb-4">
                <ul class="nav nav-pills nav-fill" id="authTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="login-tab" data-bs-toggle="pill"
                                data-bs-target="#login-pane" type="button" role="tab">Log in</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="register-tab" data-bs-toggle="pill"
                                data-bs-target="#register-pane" type="button" role="tab">Create account</button>
                    </li>
                </ul>

                <div class="tab-content" id="authTabsContent">
                    <!-- LOGIN -->
                    <div class="tab-pane fade show active" id="login-pane" role="tabpanel">
                        <div class="info-note">Only registered business owners in Placer can open the permit form.</div>
                        <form id="modalLoginForm">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" placeholder="you@email.com" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Password</label>
                                <input type="password" class="form-control" name="password" placeholder="At least 8 characters" required>
                            </div>
                            <button type="submit" class="btn btn-dark-blue mb-2">Log in</button>
                            <button type="button" class="btn btn-outline-cancel" data-bs-dismiss="modal">Cancel</button>
                        </form>
                    </div>

                    <!-- REGISTER -->
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
                            <div class="mb-4">
                                <label class="form-label">Password</label>
                                <input type="password" class="form-control" name="password" placeholder="At least 8 characters" required>
                            </div>
                            <input type="hidden" name="confirm_password" id="modalConfirmPassword">
                            <button type="submit" class="btn btn-dark-blue mb-2">Create account</button>
                            <button type="button" class="btn btn-outline-cancel" data-bs-dismiss="modal">Cancel</button>
                        </form>
                    </div>
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
                    <p class="timer-text small text-muted" id="timerText">
                        <i class="bi bi-clock-history"></i> Code expires in <span id="timerCountdown">02:00</span>
                    </p>
                    <a href="#" class="text-decoration-none fw-bold" id="resendOtpLink"
                       style="color: var(--primary-color);">Didn't receive the code? Resend</a>
                </div>
                <div id="otpError" class="alert alert-danger small mt-3" style="display: none;"></div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-dark-blue" id="verifyOtpBtn"
                        style="width: auto; padding: .5rem 1.5rem;">Verify &amp; Register</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= SIDEBAR SCRIPTS ================= -->
<script>
(function () {
    'use strict';

    /* ---------- Ensure body has sidebar offset class ---------- */
    document.body.classList.add('has-sidebar');

    /* ---------- Sidebar open / close ---------- */
    var sidebar   = document.getElementById('sidebar');
    var backdrop  = document.getElementById('sidebarBackdrop');
    var toggleBtn = document.getElementById('sidebarToggle');
    var closeBtn  = document.getElementById('sidebarClose');

    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('show');
        if (backdrop) backdrop.classList.add('show');
    }
    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('show');
        if (backdrop) backdrop.classList.remove('show');
    }

    toggleBtn  && toggleBtn.addEventListener('click', openSidebar);
    closeBtn   && closeBtn.addEventListener('click', closeSidebar);
    backdrop   && backdrop.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });

    /* Auto-close sidebar on link click (mobile) */
    document.querySelectorAll('#sidebar .sidebar-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < 992) closeSidebar();
        });
    });

    /* ---------- Generic dropdown setup (opens upward) ---------- */
    function setupDropdown(triggerId, menuId) {
        var trigger = document.getElementById(triggerId);
        var menu    = document.getElementById(menuId);
        if (!trigger || !menu) return;

        var isOpen = false;

        function openMenu() {
            isOpen = true;
            menu.classList.add('show');
            trigger.setAttribute('aria-expanded', 'true');
        }
        function closeMenu() {
            isOpen = false;
            menu.classList.remove('show');
            trigger.setAttribute('aria-expanded', 'false');
        }

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            isOpen ? closeMenu() : openMenu();
        });

        menu.addEventListener('click', function (e) {
            if (e.target.closest('a.dropdown-item')) return;
            e.stopPropagation();
        });

        document.addEventListener('click', function (e) {
            if (isOpen && !trigger.contains(e.target) && !menu.contains(e.target)) closeMenu();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) closeMenu();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupDropdown('notifDropdown', 'notifMenu');
        setupDropdown('userDropdown', 'userMenu');

        /* ---------- Mark notification as read ---------- */
        document.querySelectorAll('.notif-item[data-notif-id]').forEach(function (item) {
            item.addEventListener('click', function () {
                var id = this.getAttribute('data-notif-id');
                if (!id) return;

                fetch('mark_notification_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id }),
                    keepalive: true
                }).catch(function () {});

                var badge = document.getElementById('notifBadge');
                if (badge) {
                    var count = parseInt(badge.textContent, 10) || 0;
                    count = Math.max(0, count - 1);
                    if (count === 0) badge.remove();
                    else badge.textContent = count > 99 ? '99+' : count;
                }
            });
        });

        /* ============================================================
           AUTH MODAL LOGIC
           ============================================================ */

        /* 1. Login */
        const loginForm = document.getElementById('modalLoginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', async function (e) {
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
                            icon: 'success',
                            title: 'Login Successful!',
                            text: 'Welcome back, ' + result.name + '!',
                            timer: 1500,
                            showConfirmButton: false
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

        /* 2. Register (send OTP) */
        const registerForm = document.getElementById('modalRegisterForm');
        if (registerForm) {
            registerForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                const pwd = this.querySelector('input[name="password"]').value;
                document.getElementById('modalConfirmPassword').value = pwd;

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

        /* ============================================================
           OTP HANDLING
           ============================================================ */
        let timerInterval = null;
        let timeLeft = 120;
        let resendCooldownInterval = null;

        function setupOtpInputs() {
            const inputs = ['otp1','otp2','otp3','otp4','otp5','otp6'];
            inputs.forEach((id, idx) => {
                const inp = document.getElementById(id);
                if (!inp) return;
                inp.addEventListener('input', function () {
                    if (this.value.length === 1 && idx < 5) {
                        document.getElementById(inputs[idx + 1]).focus();
                    }
                });
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && this.value.length === 0 && idx > 0) {
                        document.getElementById(inputs[idx - 1]).focus();
                    }
                });
            });
        }

        function startTimer() {
            if (timerInterval) clearInterval(timerInterval);
            timeLeft = 120;
            updateTimerDisplay();
            timerInterval = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                    document.getElementById('timerText').innerHTML =
                        '<i class="bi bi-hourglass-split"></i> Code expired. Please request a new one.';
                    document.querySelectorAll('.otp-digit').forEach(inp => inp.disabled = true);
                } else {
                    timeLeft--;
                    updateTimerDisplay();
                }
            }, 1000);
        }

        function updateTimerDisplay() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            document.getElementById('timerCountdown').innerText =
                `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }

        function getEnteredOTP() {
            let otp = '';
            for (let i = 1; i <= 6; i++) {
                const val = document.getElementById(`otp${i}`).value;
                if (!val.match(/[0-9]/)) return '';
                otp += val;
            }
            return otp;
        }

        function startResendCooldown(seconds = 30) {
            const link = document.getElementById('resendOtpLink');
            if (!link) return;
            if (resendCooldownInterval) clearInterval(resendCooldownInterval);
            let remaining = seconds;
            link.classList.add('disabled');
            link.style.pointerEvents = 'none';
            link.style.opacity = '0.55';
            link.innerText = `Resend available in ${remaining}s`;
            resendCooldownInterval = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(resendCooldownInterval);
                    resendCooldownInterval = null;
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
            document.querySelectorAll('.otp-digit').forEach(inp => {
                inp.value = '';
                inp.disabled = false;
            });
            document.getElementById('otpError').style.display = 'none';
            document.getElementById('timerText').innerHTML =
                '<i class="bi bi-clock-history"></i> Code expires in <span id="timerCountdown">02:00</span>';
            startTimer();
        }

        setupOtpInputs();

        /* Verify OTP */
        document.getElementById('verifyOtpBtn')?.addEventListener('click', async function () {
            const otp = getEnteredOTP();
            if (otp.length !== 6) {
                document.getElementById('otpError').innerHTML =
                    '<i class="bi bi-exclamation-triangle-fill me-1"></i> Please enter the complete 6-digit code.';
                document.getElementById('otpError').style.display = 'block';
                return;
            }

            const btn = this;
            btn.disabled = true;
            btn.innerText = 'Verifying...';

            const formBody = new URLSearchParams();
            formBody.append('action', 'verify_otp');
            formBody.append('otp', otp);

            try {
                const response = await fetch('register.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formBody
                });
                const result = await response.json();

                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('otpModal')).hide();
                    Swal.fire({
                        icon: 'success',
                        title: 'Registration Successful!',
                        text: 'Your account has been created. Redirecting to dashboard...',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => { window.location.href = result.redirect; });
                } else {
                    document.getElementById('otpError').innerHTML =
                        `<i class="bi bi-exclamation-triangle-fill me-1"></i> ${result.errors.join('<br>')}`;
                    document.getElementById('otpError').style.display = 'block';
                    for (let i = 1; i <= 6; i++) document.getElementById(`otp${i}`).value = '';
                    document.getElementById('otp1').focus();
                }
            } catch (error) {
                document.getElementById('otpError').innerHTML =
                    '<i class="bi bi-exclamation-triangle-fill me-1"></i> Server error. Please try again.';
                document.getElementById('otpError').style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.innerText = 'Verify & Register';
            }
        });

        /* Resend OTP */
        document.getElementById('resendOtpLink')?.addEventListener('click', async function (e) {
            e.preventDefault();
            if (this.classList.contains('disabled')) return;

            Swal.fire({
                title: 'Resending OTP...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const formBody = new URLSearchParams();
            formBody.append('action', 'resend_otp');

            try {
                const response = await fetch('register.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formBody
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
</script>