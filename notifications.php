<?php
session_start();
require_once __DIR__ . '/config/connect.php';
require_once __DIR__ . '/notifications_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$embed  = (isset($_GET['embed']) && $_GET['embed'] === '1');
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all') {
    markAllNotificationsRead($conn, $userId);
    if ($embed) {
        echo "<script>if(window.parent!==window){window.parent.postMessage({type:'notifications-updated'},'*');}</script>";
    }
    header('Location: notifications.php' . ($embed ? '?embed=1' : ''));
    exit;
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$rows  = getAllNotifications($conn, $userId, $perPage, $offset);
$total = countAllNotifications($conn, $userId);
$pages = max(1, (int)ceil($total / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Buyawanong Placer</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-green: #00c389; --primary-green-dark: #00996e; --primary-green-light: #e6f9f2; --dark-blue: #172233; }
        body { font-family: 'Inter', sans-serif; background: #f8f9fa; }
        .notif-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(23,34,51,.06); overflow: hidden; }
        .notif-row { display: flex; gap: 14px; padding: 1rem 1.25rem; border-bottom: 1px solid #f1f3f5; text-decoration: none; color: inherit; transition: background .15s; }
        .notif-row:last-child { border-bottom: none; }
        .notif-row:hover { background: var(--primary-green-light); }
        .notif-row.unread { background: #f0fcf7; }
        .notif-row.notif-no-link { cursor: default; }
        .notif-row.notif-no-link:hover { background: #f8f9fa; }
        .notif-icon { flex-shrink: 0; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #fff; }
        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-size: .95rem; font-weight: 600; color: var(--dark-blue); margin: 0 0 3px; }
        .notif-msg { font-size: .85rem; color: #6c757d; margin: 0 0 4px; }
        .notif-time { font-size: .75rem; color: #adb5bd; }
        .unread-dot { width: 8px; height: 8px; background: var(--primary-green); border-radius: 50%; flex-shrink: 0; margin-top: 6px; }
        .btn-green { background: var(--primary-green); border-color: var(--primary-green); color: #fff; }
        .btn-green:hover { background: var(--primary-green-dark); border-color: var(--primary-green-dark); color: #fff; }
    </style>
</head>
<body>
<?php if (!$embed) { include __DIR__ . '/header/nav.php'; } ?>

<div class="container <?php echo $embed ? 'py-3' : 'py-5'; ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="mb-0 fw-bold" style="color:var(--dark-blue);">
                <i class="bi bi-bell-fill text-success"></i> Notifications
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
                <i class="bi bi-bell-slash" style="font-size:2.5rem; color:var(--primary-green-light);"></i>
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
                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo $embed ? '&embed=1' : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<script>
    const IS_EMBED = <?php echo $embed ? 'true' : 'false'; ?>;

    document.querySelectorAll('.notif-row[data-notif-id]').forEach(row => {
        row.addEventListener('click', function() {
            const id = this.getAttribute('data-notif-id');
            if (!id) return;
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id }),
                keepalive: true
            }).catch(() => {});
        });
    });

    if (IS_EMBED) {
        // Keep notification links from opening inside the small iframe
        document.querySelectorAll('.notif-row[href]').forEach(a => {
            a.setAttribute('target', '_top');
        });

        // Let the parent dashboard know something changed so it can refresh the badge
        window.addEventListener('click', function () {
            if (window.parent !== window) {
                window.parent.postMessage({ type: 'notifications-updated' }, '*');
            }
        });
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>