<?php
/**
 * config/settings.php
 * Loads system_settings once into a static cache and exposes helpers.
 */

if (!function_exists('settings_load')) {
    function settings_load(mysqli $conn): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        $res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
            $res->free();
        }
        return $cache;
    }
}

if (!function_exists('setting')) {
    function setting(mysqli $conn, string $key, string $default = ''): string {
        $all = settings_load($conn);
        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== ''
            ? (string)$all[$key]
            : $default;
    }
}

if (!function_exists('e_setting')) {
    function e_setting(mysqli $conn, string $key, string $default = ''): string {
        return htmlspecialchars(setting($conn, $key, $default), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('raw_setting')) {
    function raw_setting(mysqli $conn, string $key, string $default = ''): string {
        return setting($conn, $key, $default);
    }
}