<?php
session_start();
require_once __DIR__ . '/../config/connect.php';
require_once __DIR__ . '/../notifications_helper.php';

if (!isset($_SESSION['user_id']) || !in_array((int)$_SESSION['user_type'], [3, 4, 5])) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all') {
    markAllNotificationsRead($conn, $userId);
    header('Location: notifications.php');
    exit;
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$rows  = getAllNotifications($conn, $userId, $perPage, $offset);
$total = countAllNotifications($conn, $userId);
$pages = max(1, (int)ceil($total / $perPage));

$staff_name = $_SESSION['user_name'] ?? 'Staff';
$staff_type = (int)$_SESSION['user_type'];
$role_label = ($staff_type == 3) ? 'Treasury Staff' : (($staff_type == 4) ? 'BPLO Staff' : 'Administrator');

// ------------------------------------------------------------
// Resolve the correct dashboard for this user type
// ------------------------------------------------------------
switch ($staff_type) {
    case 3:
        $dashboardUrl   = 'dashboard_treasury.php';
        $dashboardLabel = 'Treasury Dashboard';
        break;
    case 4:
        $dashboardUrl   = 'dashboard_bplo.php';
        $dashboardLabel = 'BPLO Dashboard';
        break;
    case 5:
    default:
        $dashboardUrl   = 'index.php';
        $dashboardLabel = 'Admin Dashboard';
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | <?php echo htmlspecialchars($role_label); ?></title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; }
        .gov-header { background: #1e4b2e; color: white; padding: 0.7rem 1.5rem; border-bottom: 3px solid #ffc107; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .user-greeting { background: rgba(255,255,255,0.15); border-radius: 40px; padding: 0.3rem 1rem; font-weight: 500; cursor: pointer; }
        .notif-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(30,75,46,.08); overflow: hidden; }
        .notif-row { display: flex; gap: 14px; padding: 1rem 1.25rem; border-bottom: 1px solid #f1f3f5; text-decoration: none; color: inherit; transition: background .15s; }
        .notif-row:last-child { border-bottom: none; }
        .notif-row:hover { background: #e9f5ef; }
        .notif-row.unread { background: #f0f8ff; }
        .notif-row.notif-no-link { cursor: default; }
        .notif-row.notif-no-link:hover { background: #f8f9fa; }
        .notif-icon { flex-shrink: 0; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #fff; }
        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-size: .95rem; font-weight: 600; color: #1e4b2e; margin: 0 0 3px; }
        .notif-msg { font-size: .85rem; color: #6c757d; margin: 0 0 4px; }
        .notif-time { font-size: .75rem; color: #adb5bd; }
        .unread-dot { width: 8px; height: 8px; background: #1e4b2e; border-radius: 50%; flex-shrink: 0; margin-top: 6px; }
        .btn-green { background: #1e4b2e; border-color: #1e4b2e; color: #fff; }
        .btn-green:hover { background: #0f5937; border-color: #0f5937; color: #fff; }
    </style>
</head>
<body>

<header class="gov-header d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="text-white text-decoration-none d-flex align-items-center gap-2">
            <i class="bi bi-arrow-left fs-5"></i>
            <h5 class="mb-0 fw-bold">Back to <?php echo htmlspecialchars($dashboardLabel); ?></h5>
        </a>
    </div>
    <div class="dropdown">
        <a class="dropdown-toggle user-greeting text-decoration-none text-white" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($staff_name); ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
        </ul>
    </div>
</header>

<div class="container py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="mb-0 fw-bold" style="color:#1e4b2e;">
                <i class="bi bi-bell-fill"></i> Notifications
            </h3>
            <p class="text-muted mb-0 small"><?php echo $total; ?> total</p>
        </div>
        <?php if ($total > 0): ?>
            <form method="post" class="m-0">
                <input type="hidden" name="action" value="mark_all">
                <button class="btn btn-green btn-sm"><i class="bi bi-check2-all"></i> Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="notif-card">
        <?php if (empty($rows)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-bell-slash" style="font-size:2.5rem; color:#d4edda;"></i>
                <p class="mt-3 mb-0">No notifications yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($rows as $n):
                $meta        = notificationMeta($n['type']);
                $isClickable = notifIsClickable($n['link']);
                $tag         = $isClickable ? 'a' : 'div';
            ?>
                <<?php echo $tag; ?> class="notif-row <?php echo $n['is_read'] ? '' : 'unread'; ?><?php echo $isClickable ? '' : ' notif-no-link'; ?>"
                   <?php echo notifHref($n['link']); ?>
                   data-notif-id="<?php echo (int)$n['id']; ?>">
                    <div class="notif-icon" style="background: <?php echo $meta['color']; ?>;">
                        <i class="bi <?php echo $meta['icon']; ?>"></i>
                    </div>
                    <div class="notif-body">
                        <p class="notif-title"><?php echo htmlspecialchars($n['title']); ?></p>
                        <?php if (!empty($n['message'])): ?>
                            <p class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></p>
                        <?php endif; ?>
                        <span class="notif-time">
                            <i class="bi bi-clock"></i> <?php echo timeAgo($n['created_at']); ?>
                            <?php if (!$isClickable): ?> &bull; <em>Info only</em><?php endif; ?>
                        </span>
                    </div>
                    <?php if (!$n['is_read']): ?>
                        <div class="unread-dot"></div>
                    <?php endif; ?>
                </<?php echo $tag; ?>>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<script>
    document.querySelectorAll('.notif-row[data-notif-id]').forEach(row => {
        row.addEventListener('click', function() {
            const id = this.getAttribute('data-notif-id');
            if (!id) return;
            fetch('../mark_notification_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id }),
                keepalive: true
            }).catch(() => {});
        });
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>