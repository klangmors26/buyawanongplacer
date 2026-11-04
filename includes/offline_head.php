<?php
/**
 * includes/offline_head.php — include just before </head> on pages that should survive server downtime.
 * Registers the service worker and loads the offline sync script (logged-in users only).
 */
if (!empty($_SESSION['user_id'])): ?>
<script>window.__OFFLINE_USER__ = <?php echo (int)$_SESSION['user_id']; ?>;</script>
<script src="assets/offline-sync.js" defer></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js').catch(function () {});
    });
}
</script>
<?php endif; ?>
