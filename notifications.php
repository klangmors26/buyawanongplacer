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
    header('Location: notifications.php' . ($embed ? '?embed=1&marked=1' : ''));
    exit;
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$rows   = getAllNotifications($conn, $userId, $perPage, $offset);
$total  = countAllNotifications($conn, $userId);
$unread = countUnreadNotifications($conn, $userId);
$pages  = max(1, (int)ceil($total / $perPage));

// Group the rows of this page: Today / Yesterday / Earlier
$groups = ['Today' => [], 'Yesterday' => [], 'Earlier' => []];
$todayKey     = date('Y-m-d');
$yesterdayKey = date('Y-m-d', strtotime('-1 day'));
foreach ($rows as $n) {
    $dayKey = date('Y-m-d', strtotime($n['created_at']));
    if ($dayKey === $todayKey)          $groups['Today'][]     = $n;
    elseif ($dayKey === $yesterdayKey)  $groups['Yesterday'][] = $n;
    else                                $groups['Earlier'][]   = $n;
}
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
        :root {
            --primary-green: #00c389;
            --primary-green-dark: #00996e;
            --primary-green-light: #e6f9f2;
            --dark-blue: #172233;
            --muted: #6b7686;
            --line: #eef1f4;
        }
        body { font-family: 'Inter', sans-serif; background: #f4f6f9; color: var(--dark-blue); }

        /* ---------- Page header ---------- */
        .page-head h3 { font-weight: 700; letter-spacing: -.01em; }
        .count-pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--primary-green-light); color: var(--primary-green-dark);
            font-size: .75rem; font-weight: 600; padding: .25rem .65rem; border-radius: 999px;
        }
        .btn-green { background: var(--primary-green); border-color: var(--primary-green); color: #fff; font-weight: 600; border-radius: 10px; }
        .btn-green:hover { background: var(--primary-green-dark); border-color: var(--primary-green-dark); color: #fff; }

        /* ---------- Filter tabs ---------- */
        .filter-tabs { display: inline-flex; background: #fff; border-radius: 12px; padding: 4px; box-shadow: 0 1px 4px rgba(23,34,51,.06); }
        .filter-tabs button {
            border: 0; background: transparent; padding: .4rem 1rem; border-radius: 9px;
            font-size: .82rem; font-weight: 600; color: var(--muted); transition: all .15s;
        }
        .filter-tabs button.active { background: var(--dark-blue); color: #fff; }
        .filter-tabs .badge-n { background: var(--primary-green); color: #fff; border-radius: 999px; font-size: .65rem; padding: 1px 6px; margin-left: 4px; }

        /* ---------- Groups + cards ---------- */
        .group-label { font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #8a94a3; margin: 1.5rem .25rem .6rem; }
        .notif-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 14px rgba(23,34,51,.06); overflow: hidden; }

        .notif-row {
            position: relative; display: flex; gap: 14px; align-items: flex-start;
            padding: 1rem 1.25rem 1rem 1.4rem; border-bottom: 1px solid var(--line);
            cursor: pointer; transition: background .15s, transform .15s;
        }
        .notif-row:last-child { border-bottom: none; }
        .notif-row:hover { background: #f7fbf9; }
        .notif-row.unread { background: #f3fcf8; }
        .notif-row.unread::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--primary-green);
        }
        .notif-icon {
            flex-shrink: 0; width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 1.15rem; color: #fff;
        }
        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-size: .95rem; font-weight: 600; margin: 0 0 3px; color: var(--dark-blue); }
        .notif-row.unread .notif-title { font-weight: 700; }
        .notif-msg {
            font-size: .85rem; color: var(--muted); margin: 0 0 6px;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .notif-time { font-size: .75rem; color: #9aa4b2; }
        .notif-side { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0; }
        .unread-dot { width: 9px; height: 9px; background: var(--primary-green); border-radius: 50%; box-shadow: 0 0 0 4px rgba(0,195,137,.15); }
        .view-hint { font-size: .75rem; font-weight: 600; color: var(--primary-green-dark); display: inline-flex; align-items: center; gap: 4px; opacity: 0; transition: opacity .15s; }
        .notif-row:hover .view-hint { opacity: 1; }

        .empty-state { text-align: center; padding: 3.5rem 1rem; color: #9aa4b2; }
        .empty-state i { font-size: 3rem; color: #cfeee3; }

        /* ---------- Modal ---------- */
        .nv-modal .modal-content { border: 0; border-radius: 16px; overflow: hidden; }
        .nv-modal .nv-head { display: flex; gap: 14px; align-items: center; padding: 1.25rem 1.5rem; background: var(--dark-blue); color: #fff; }
        .nv-modal .nv-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #fff; flex-shrink: 0; }
        .nv-modal .nv-title { font-size: 1.05rem; font-weight: 700; margin: 0; }
        .nv-modal .nv-date { font-size: .78rem; opacity: .75; margin: 2px 0 0; }
        .nv-modal .nv-message { white-space: pre-wrap; line-height: 1.65; font-size: .95rem; color: #334155; }
        .nv-modal .btn-close { filter: invert(1) brightness(2); margin-left: auto; }

        @media (max-width: 575.98px) {
            .notif-row { padding: .9rem 1rem .9rem 1.15rem; gap: 12px; }
            .notif-icon { width: 38px; height: 38px; font-size: 1rem; }
            .view-hint { display: none; }
        }
    </style>
</head>
<body>
<?php if (!$embed) { include __DIR__ . '/header/nav.php'; } ?>

<div class="container <?php echo $embed ? 'py-3' : 'py-5'; ?>" style="max-width: 860px;">

    <!-- Header -->
    <div class="page-head d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h3 class="mb-1"><i class="bi bi-bell-fill text-success"></i> Notifications</h3>
            <span class="count-pill"><i class="bi bi-inbox"></i> <?php echo $total; ?> total</span>
            <span class="count-pill ms-1" style="background:#fff3e0;color:#b45309;"><i class="bi bi-circle-fill" style="font-size:.4rem;"></i> <span id="unreadCount"><?php echo $unread; ?></span> unread</span>
        </div>
        <?php if ($total > 0): ?>
            <form method="post" class="m-0">
                <input type="hidden" name="action" value="mark_all">
                <button class="btn btn-green btn-sm px-3"><i class="bi bi-check2-all"></i> Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($total > 0): ?>
        <div class="filter-tabs mb-1" role="tablist">
            <button type="button" class="active" data-filter="all">All</button>
            <button type="button" data-filter="unread">Unread<span class="badge-n" id="unreadTabBadge"><?php echo $unread; ?></span></button>
        </div>
    <?php endif; ?>

    <!-- List -->
    <?php if (empty($rows)): ?>
        <div class="notif-card mt-3">
            <div class="empty-state">
                <i class="bi bi-bell-slash"></i>
                <p class="mt-3 mb-0">No notifications yet.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($groups as $label => $items): if (empty($items)) continue; ?>
            <div class="group" data-group>
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
                             data-icon="<?php echo htmlspecialchars($meta['icon'], ENT_QUOTES); ?>"
                             data-link="<?php echo $isClickable ? htmlspecialchars($n['link'], ENT_QUOTES) : ''; ?>">
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

    <!-- Pagination -->
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
                <a href="#" id="nvGo" class="btn btn-green btn-sm px-3" style="display:none;"><i class="bi bi-box-arrow-up-right"></i> Go to page</a>
                <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal" style="border-radius:10px;">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const IS_EMBED = <?php echo $embed ? 'true' : 'false'; ?>;
    const modalEl  = document.getElementById('notifViewModal');
    const modal    = new bootstrap.Modal(modalEl);

    function notifyParent() {
        if (IS_EMBED && window.parent !== window) {
            window.parent.postMessage({ type: 'notifications-updated' }, '*');
        }
    }

    <?php if ($embed && isset($_GET['marked'])): ?>notifyParent();<?php endif; ?>

    function updateUnreadUI(delta) {
        const countEl = document.getElementById('unreadCount');
        const tabEl   = document.getElementById('unreadTabBadge');
        let n = Math.max(0, (parseInt(countEl.textContent, 10) || 0) + delta);
        countEl.textContent = n;
        if (tabEl) tabEl.textContent = n;
    }

    function markRead(row) {
        if (row.dataset.read === '1') return;
        const id = row.dataset.notifId;
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id }),
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

        const go = document.getElementById('nvGo');
        if (row.dataset.link) {
            go.href = row.dataset.link;
            if (IS_EMBED) go.setAttribute('target', '_top'); else go.removeAttribute('target');
            go.style.display = '';
        } else {
            go.style.display = 'none';
        }
        modal.show();
        markRead(row);
    }

    document.querySelectorAll('.notif-row').forEach(row => {
        row.addEventListener('click', () => openNotification(row));
        row.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openNotification(row); }
        });
    });

    // All / Unread filter
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