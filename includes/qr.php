<?php
/**
 * includes/qr.php — one place that decides what every QR code in the system contains.
 *
 * The QR always holds the full, absolute public tracking link, e.g.
 *   https://your-site/placer/track.php?ref=2026-11-001
 * so a phone camera can open it directly, and the in-app scanners (which read the ?ref= part) still work.
 *
 * IMPORTANT: a QR created while browsing on "localhost" can only be opened on that same computer.
 * Set APP_BASE_URL (config/paymongo.local.php) to the address phones can reach, e.g. http://192.168.1.10/placer
 * or the real domain, so scanned codes open from any device.
 */

function qr_base_url(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $cfg = @include __DIR__ . '/../config/paymongo.php';
    if (is_array($cfg) && !empty($cfg['app_base_url'])) {
        return $cached = rtrim($cfg['app_base_url'], '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Work out the project folder from the file system (not from the page that happens to be running),
    // so a QR made from /staff/ or /admin/ still points at <project>/track.php.
    $dir     = null;
    $root    = realpath(__DIR__ . '/..');
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($root && $docRoot) {
        $r = str_replace('\\', '/', $root);
        $d = rtrim(str_replace('\\', '/', $docRoot), '/');
        if ($d !== '' && strpos($r, $d) === 0) $dir = substr($r, strlen($d));
    }
    if ($dir === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    }
    $dir = rtrim($dir, '/');
    if ($dir === '.' ) $dir = '';

    return $cached = ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/** The text stored inside the QR code for an application's reference number. */
function qr_track_url(string $referenceNo): string
{
    return qr_base_url() . '/track.php?ref=' . rawurlencode($referenceNo);
}
