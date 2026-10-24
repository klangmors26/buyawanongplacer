<?php
/**
 * mark_notification_read.php
 * POST { "id": 123 }   -> marks single notification as read
 * POST { "all": true } -> marks all as read
 */
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/connect.php';
require_once __DIR__ . '/notifications_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    if (!empty($input['all'])) {
        $n = markAllNotificationsRead($conn, $userId);
        echo json_encode(['ok' => true, 'marked' => $n]);
        exit;
    }

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid id']);
        exit;
    }

    $ok = markNotificationRead($conn, $id, $userId);
    echo json_encode(['ok' => $ok]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}