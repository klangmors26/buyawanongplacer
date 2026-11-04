<?php
/**
 * notifications_helper.php — MySQLi version
 * User types: 1 = owner, 3 = treasury, 4 = BPLO, 5 = admin
 */

/**
 * Create a new notification for a user.
 */
function createNotification(
    mysqli $conn,
    int $userId,
    string $title,
    string $message = '',
    ?string $link = null,
    string $type = 'info'
): int {
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, link)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$stmt) return 0;
    $stmt->bind_param("issss", $userId, $type, $title, $message, $link);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Notify all admins (user_type = 5).
 */
function notifyAllAdmins(
    mysqli $conn,
    string $title,
    string $message = '',
    ?string $link = null,
    string $type = 'info'
): int {
    return notifyByUserType($conn, [5], $title, $message, $link, $type);
}

/**
 * Notify all users matching any of the given user_types.
 */
function notifyByUserType(
    mysqli $conn,
    array $userTypes,
    string $title,
    string $message = '',
    ?string $link = null,
    string $type = 'info'
): int {
    if (empty($userTypes)) return 0;

    $userTypes    = array_values(array_map('intval', $userTypes));
    $placeholders = implode(',', array_fill(0, count($userTypes), '?'));
    $typesStr     = str_repeat('i', count($userTypes));

    $sql  = "SELECT id FROM accounts WHERE user_type IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;

    $stmt->bind_param($typesStr, ...$userTypes);
    $stmt->execute();
    $result = $stmt->get_result();

    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $stmt->close();

    $count = 0;
    foreach ($ids as $uid) {
        createNotification($conn, $uid, $title, $message, $link, $type);
        $count++;
    }
    return $count;
}

/**
 * Get unread notifications for a user (latest first).
 */
function getUnreadNotifications(mysqli $conn, int $userId, int $limit = 5): array
{
    $rows = [];
    $stmt = $conn->prepare("
        SELECT id, type, title, message, link, created_at
        FROM notifications
        WHERE user_id = ? AND is_read = 0
        ORDER BY created_at DESC
        LIMIT ?
    ");
    if (!$stmt) return $rows;
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/**
 * Count unread notifications for a user.
 */
function countUnreadNotifications(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    if (!$stmt) return 0;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($result['c'] ?? 0);
}

/**
 * Get all notifications for a user (paginated).
 */
function getAllNotifications(mysqli $conn, int $userId, int $limit = 15, int $offset = 0): array
{
    $rows = [];
    $stmt = $conn->prepare("
        SELECT id, type, title, message, link, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    if (!$stmt) return $rows;
    $stmt->bind_param("iii", $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/**
 * Count all notifications for a user.
 */
function countAllNotifications(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($result['c'] ?? 0);
}

/**
 * Mark a single notification as read (only if it belongs to the user).
 */
function markNotificationRead(mysqli $conn, int $notifId, int $userId): bool
{
    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1, read_at = NOW()
        WHERE id = ? AND user_id = ? AND is_read = 0
    ");
    if (!$stmt) return false;
    $stmt->bind_param("ii", $notifId, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    return $changed;
}

/**
 * Mark all notifications as read for a user.
 */
function markAllNotificationsRead(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1, read_at = NOW()
        WHERE user_id = ? AND is_read = 0
    ");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed;
}

/**
 * Map notification type -> Bootstrap icon + color.
 */
function notificationMeta(string $type): array
{
    switch ($type) {
        case 'permit':  return ['icon' => 'bi-file-earmark-text-fill',    'color' => '#0d6efd'];
        case 'user':    return ['icon' => 'bi-person-plus-fill',          'color' => '#6f42c1'];
        case 'success': return ['icon' => 'bi-check-circle-fill',         'color' => '#00c389'];
        case 'warning': return ['icon' => 'bi-exclamation-triangle-fill', 'color' => '#ffc107'];
        case 'danger':  return ['icon' => 'bi-x-circle-fill',             'color' => '#dc3545'];
        case 'system':  return ['icon' => 'bi-gear-fill',                 'color' => '#6c757d'];
        default:        return ['icon' => 'bi-info-circle-fill',          'color' => '#00c389'];
    }
}

/**
 * Human-friendly time ago.
 */
function timeAgo(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}

/**
 * Return href attribute for a notification (empty if no link).
 */
function notifHref(?string $link): string
{
    return !empty($link) ? 'href="' . htmlspecialchars($link) . '"' : '';
}

/**
 * Return true if the notification has a link.
 */
function notifIsClickable(?string $link): bool
{
    return !empty($link);
}