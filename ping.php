<?php
/**
 * ping.php — lightweight "is the server (and database) up?" check used by the offline-sync script.
 * 204 = up, 503 = down. No session, no output.
 */
http_response_code(503);                       // stays 503 if connect.php die()s (database down)
header('Cache-Control: no-store, max-age=0');
ob_start(function () { return ''; });          // swallow any "Connection failed" text from connect.php
require __DIR__ . '/config/connect.php';
ob_end_clean();
if (isset($conn) && !$conn->connect_error) {
    http_response_code(204);
}
