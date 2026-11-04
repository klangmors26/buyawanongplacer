<?php
session_start();
require_once __DIR__ . '/../config/connect.php';
require_once __DIR__ . '/../notifications_helper.php';

if (!isset($_SESSION['user_id']) || !in_array((int)$_SESSION['user_type'], [3, 4, 5])) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$embed  = (isset($_GET['embed']) && $_GET['embed'] === '1');   // opened inside the dashboard modal

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all') {
    markAllNotificationsRead($conn, $userId);
    header('Location: notifications.php' . ($embed ? '?embed=1&marked=1' : ''));
    exit;
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$rows  = getAllNotifications($conn, $userId, $perPage, $offset);
$total = countAllNotifications($conn, $userId);
$pages = max(1, (int)ceil($total / $perPage));
$unread = countUnreadNotifications($conn, $userId);

// Group this page's rows: Today / Yesterday / Earlier
$groups = ['Today' => [], 'Yesterday' => [], 'Earlier' => []];
$todayKey     = date('Y-m-d');
$yesterdayKey = date('Y-m-d', strtotime('-1 day'));
foreach ($rows as $n) {
    $dayKey = date('Y-m-d', strtotime($n['created_at']));
    if ($dayKey === $todayKey)         $groups['Today'][]     = $n;
    elseif ($dayKey === $yesterdayKey) $groups['Yesterday'][] = $n;
    else                               $groups['Earlier'][]   = $n;
}

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
        :root {
            --navy-dark: #0f2038;
            --navy-hover: #1e3a5f;
            --yellow-accent: #fbbf24;
            --yellow-hover: #f59e0b;
            --content-bg: #f1f5f9;
            --border: #e2e8f0;
            --text-dark: #1e293b;
            --text-muted: #64748b;
        }
        body { font-family: 'Inter', sans-serif; background: var(--content-bg); color: var(--text-dark); }

        /* ---------- Top bar (same look as BPLO dashboard) ---------- */
        .top-bar { background: var(--navy-dark); color: #fff; padding: .75rem 1.5rem; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,.12); }
        .top-bar .back-link { color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 10px; font-weight: 600; font-size: .95rem; padding: .4rem .75rem; border-radius: 10px; transition: background .15s; }
        .top-bar .back-link:hover { background: rgba(255,255,255,.08); color: #fff; }
        .top-bar .role-tag { font-size: .7rem; color: #94a3b8; display: block; font-weight: 500; }
        .user-chip { background: rgba(255,255,255,.1); border-radius: 999px; padding: .35rem 1rem; font-weight: 500; font-size: .9rem; color: #fff; text-decoration: none; }
        .user-chip:hover { background: rgba(255,255,255,.16); color: #fff; }

        /* ---------- Page header ---------- */
        .page-title { font-weight: 700; letter-spacing: -.01em; color: var(--navy-dark); }
        .pill { display: inline-flex; align-items: center; gap: 6px; font-size: .75rem; font-weight: 600; padding: .25rem .7rem; border-radius: 999px; }
        .pill-total { background: #e0e7ef; color: var(--navy-hover); }
        .pill-unread { background: #fef3c7; color: #92400e; }
        .btn-navy { background: var(--navy-dark); border-color: var(--navy-dark); color: #fff; font-weight: 600; border-radius: 10px; }
        .btn-navy:hover { background: var(--navy-hover); border-color: var(--navy-hover); color: #fff; }
        .btn-yellow { background: var(--yellow-accent); border-color: var(--yellow-accent); color: var(--navy-dark); font-weight: 700; border-radius: 10px; }
        .btn-yellow:hover { background: var(--yellow-hover); border-color: var(--yellow-hover); color: var(--navy-dark); }

        /* ---------- Filter tabs ---------- */
        .filter-tabs { display: inline-flex; background: #fff; border-radius: 12px; padding: 4px; box-shadow: 0 1px 4px rgba(15,32,56,.06); }
        .filter-tabs button { border: 0; background: transparent; padding: .4rem 1rem; border-radius: 9px; font-size: .82rem; font-weight: 600; color: var(--text-muted); transition: all .15s; }
        .filter-tabs button.active { background: var(--navy-dark); color: #fff; }
        .filter-tabs .badge-n { background: var(--yellow-accent); color: var(--navy-dark); border-radius: 999px; font-size: .65rem; padding: 1px 6px; margin-left: 4px; font-weight: 700; }

        /* ---------- Groups + cards ---------- */
        .group-label { font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #8a94a3; margin: 1.5rem .25rem .6rem; }
        .notif-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 14px rgba(15,32,56,.07); overflow: hidden; }
        .notif-row { position: relative; display: flex; gap: 14px; align-items: flex-start; padding: 1rem 1.25rem 1rem 1.4rem; border-bottom: 1px solid #eef1f5; cursor: pointer; transition: background .15s; }
        .notif-row:last-child { border-bottom: none; }
        .notif-row:hover { background: #f8fafc; }
        .notif-row.unread { background: #fffbeb; }
        .notif-row.unread:hover { background: #fff7d6; }
        .notif-row.unread::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--yellow-accent); }
        .notif-icon { flex-shrink: 0; width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; color: #fff; }
        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-size: .95rem; font-weight: 600; margin: 0 0 3px; color: var(--navy-dark); }
        .notif-row.unread .notif-title { font-weight: 700; }
        .notif-msg { font-size: .85rem; color: var(--text-muted); margin: 0 0 6px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .notif-time { font-size: .75rem; color: #94a3b8; }
        .notif-side { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0; }
        .unread-dot { width: 9px; height: 9px; background: var(--yellow-hover); border-radius: 50%; box-shadow: 0 0 0 4px rgba(251,191,36,.25); }
        .view-hint { font-size: .75rem; font-weight: 600; color: var(--navy-hover); display: inline-flex; align-items: center; gap: 4px; opacity: 0; transition: opacity .15s; }
        .notif-row:hover .view-hint { opacity: 1; }
        .empty-state { text-align: center; padding: 3.5rem 1rem; color: #94a3b8; }
        .empty-state i { font-size: 3rem; color: #cbd5e1; }

        /* ---------- Modal ---------- */
        .nv-modal .modal-content { border: 0; border-radius: 16px; overflow: hidden; }
        .nv-modal .nv-head { display: flex; gap: 14px; align-items: center; padding: 1.25rem 1.5rem; background: var(--navy-dark); color: #fff; }
        .nv-modal .nv-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #fff; flex-shrink: 0; }
        .nv-modal .nv-title { font-size: 1.05rem; font-weight: 700; margin: 0; }
        .nv-modal .nv-date { font-size: .78rem; opacity: .75; margin: 2px 0 0; }
        .nv-modal .nv-message { white-space: pre-wrap; line-height: 1.65; font-size: .95rem; color: #334155; }
        .nv-modal .btn-close { filter: invert(1) brightness(2); margin-left: auto; }

        @media (max-width: 575.98px) {
            .top-bar { padding: .6rem 1rem; }
            .notif-row { padding: .9rem 1rem .9rem 1.15rem; gap: 12px; }
            .notif-icon { width: 38px; height: 38px; font-size: 1rem; }
            .view-hint { display: none; }
        }
    </style>
</head>
<body>

<?php if (!$embed): ?>
<header class="top-bar">
    <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="back-link">
        <i class="bi bi-arrow-left fs-5"></i>
        <span>Back to <?php echo htmlspecialchars($dashboardLabel); ?>
            <small class="role-tag"><?php echo htmlspecialchars($role_label); ?></small>
        </span>
    </a>
    <div class="dropdown">
        <a class="dropdown-toggle user-chip" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($staff_name); ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
        </ul>
    </div>
</header>
<?php endif; ?>

<div class="container <?php echo $embed ? 'py-3' : 'py-4 py-md-5'; ?>" style="max-width: 900px;">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h3 class="page-title mb-2"><i class="bi bi-bell-fill" style="color:var(--yellow-hover);"></i> Notifications</h3>
            <span class="pill pill-total"><i class="bi bi-inbox"></i> <?php echo $total; ?> total</span>
            <span class="pill pill-unread ms-1"><i class="bi bi-circle-fill" style="font-size:.4rem;"></i> <span id="unreadCount"><?php echo $unread; ?></span> unread</span>
        </div>
        <?php if ($total > 0): ?>
            <form method="post" class="m-0" action="notifications.php<?php echo $embed ? '?embed=1' : ''; ?>">
                <input type="hidden" name="action" value="mark_all">
                <button class="btn btn-navy btn-sm px-3"><i class="bi bi-check2-all"></i> Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($total > 0): ?>
        <div class="filter-tabs mb-1">
            <button type="button" class="active" data-filter="all">All</button>
            <button type="button" data-filter="unread">Unread<span class="badge-n" id="unreadTabBadge"><?php echo $unread; ?></span></button>
        </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="notif-card mt-3">
            <div class="empty-state">
                <i class="bi bi-bell-slash"></i>
                <p class="mt-3 mb-0">No notifications yet.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($groups as $label => $items): if (empty($items)) continue; ?>
            <div data-group>
                <div class="group-label"><?php echo $label; ?></div>
                <div class="notif-card">
                    <?php foreach ($items as $n):
                        $meta        = notificationMeta($n['type']);
                        $isClickable = notifIsClickable($n['link']);
                        $fullDate    = date('l, F j, Y \a\t g:i A', strtotime($n['created_at']));
                    ?>
                        <div class="notif-row <?php echo $n['is_read'] ? '' : 'unread'; ?>"
                             role="button" tabindex="0"
                             data-notif-id="<?php echo (int)$n['id']; ?>"
                             data-read="<?php echo $n['is_read'] ? '1' : '0'; ?>"
                             data-title="<?php echo htmlspecialchars($n['title'] ?? '', ENT_QUOTES); ?>"
                             data-message="<?php echo htmlspecialchars($n['message'] ?? '', ENT_QUOTES); ?>"
                             data-date="<?php echo htmlspecialchars($fullDate, ENT_QUOTES); ?>"
                             data-color="<?php echo htmlspecialchars($meta['color'], ENT_QUOTES); ?>"
                             data-icon="<?php echo htmlspecialchars($meta['icon'], ENT_QUOTES); ?>">
                            <div class="notif-icon" style="background: <?php echo $meta['color']; ?>;">
                                <i class="bi <?php echo $meta['icon']; ?>"></i>
                            </div>
                            <div class="notif-body">
                                <p class="notif-title"><?php echo htmlspecialchars($n['title']); ?></p>
                                <?php if (!empty($n['message'])): ?>
                                    <p class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></p>
                                <?php endif; ?>
                                <span class="notif-time"><i class="bi bi-clock"></i> <?php echo timeAgo($n['created_at']); ?></span>
                            </div>
                            <div class="notif-side">
                                <?php if (!$n['is_read']): ?><span class="unread-dot"></span><?php endif; ?>
                                <span class="view-hint">View <i class="bi bi-chevron-right"></i></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div id="noUnreadMsg" class="notif-card mt-3" style="display:none;">
            <div class="empty-state">
                <i class="bi bi-check2-circle"></i>
                <p class="mt-3 mb-0">You're all caught up. No unread notifications.</p>
            </div>
        </div>
    <?php endif; ?>

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

<!-- Full-message viewer -->
<div class="modal fade nv-modal" id="notifViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="nv-head">
                <div class="nv-icon" id="nvIcon"><i class="bi bi-bell"></i></div>
                <div>
                    <p class="nv-title" id="nvTitle">Notification</p>
                    <p class="nv-date" id="nvDate"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="nv-message mb-0" id="nvMessage"></p>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4">
                <button type="button" class="btn btn-yellow btn-sm px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const IS_EMBED = <?php echo $embed ? 'true' : 'false'; ?>;
    const modal = new bootstrap.Modal(document.getElementById('notifViewModal'));

    // Tell the parent dashboard that something changed (so it can refresh the badge)
    function notifyParent() {
        if (IS_EMBED && window.parent !== window) {
            window.parent.postMessage({ type: 'notifications-updated' }, '*');
        }
    }
    <?php if ($embed && isset($_GET['marked'])): ?>notifyParent();<?php endif; ?>

    function updateUnreadUI(delta) {
        const countEl = document.getElementById('unreadCount');
        const tabEl   = document.getElementById('unreadTabBadge');
        const n = Math.max(0, (parseInt(countEl.textContent, 10) || 0) + delta);
        countEl.textContent = n;
        if (tabEl) tabEl.textContent = n;
    }

    function markRead(row) {
        if (row.dataset.read === '1') return;
        fetch('../mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: row.dataset.notifId }),
            keepalive: true
        }).catch(() => {});
        row.dataset.read = '1';
        row.classList.remove('unread');
        const dot = row.querySelector('.unread-dot');
        if (dot) dot.remove();
        updateUnreadUI(-1);
        notifyParent();
    }

    function openNotification(row) {
        document.getElementById('nvTitle').textContent   = row.dataset.title || 'Notification';
        document.getElementById('nvMessage').textContent = row.dataset.message || '(No message)';
        document.getElementById('nvDate').textContent    = row.dataset.date || '';
        const icon = document.getElementById('nvIcon');
        icon.style.background = row.dataset.color || '#64748b';
        icon.innerHTML = '<i class="bi ' + (row.dataset.icon || 'bi-bell') + '"></i>';
        modal.show();
        markRead(row);
    }

    document.querySelectorAll('.notif-row').forEach(row => {
        row.addEventListener('click', () => openNotification(row));
        row.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openNotification(row); }
        });
    });

    document.querySelectorAll('.filter-tabs button').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.filter-tabs button').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const onlyUnread = this.dataset.filter === 'unread';
            let anyVisible = false;
            document.querySelectorAll('[data-group]').forEach(group => {
                let groupVisible = false;
                group.querySelectorAll('.notif-row').forEach(row => {
                    const hide = onlyUnread && row.dataset.read === '1';
                    row.style.display = hide ? 'none' : '';
                    if (!hide) groupVisible = true;
                });
                group.style.display = groupVisible ? '' : 'none';
                if (groupVisible) anyVisible = true;
            });
            const msg = document.getElementById('noUnreadMsg');
            if (msg) msg.style.display = (onlyUnread && !anyVisible) ? '' : 'none';
        });
    });
</script>
</body>
</html>