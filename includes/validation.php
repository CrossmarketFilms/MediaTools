<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_Validation {
    public static function browser_upload_limit_bytes() { $s = CMSG_Plugin::settings(); return max(1, (float)$s['small_upload_limit_gb']) * 1024 * 1024 * 1024; }
    public static function validate_browser_file($file) {
        if (empty($file['tmp_name']) || empty($file['name'])) return new WP_Error('cmsg_missing_file', 'No file was uploaded.');
        if ((int)$file['size'] > self::browser_upload_limit_bytes()) return new WP_Error('cmsg_file_too_large', 'This file exceeds the browser upload limit. Use the Large File / SFTP option instead.');
        return true;
    }
    public static function validate_large_directory() {
        $dir = untrailingslashit(CMSG_Plugin::settings()['large_file_directory']);
        if ($dir === '') return new WP_Error('cmsg_bad_dir', 'Large file directory is not configured.');
        if (!is_dir($dir) || !is_readable($dir)) return new WP_Error('cmsg_bad_dir', 'Large file directory is missing or unreadable.');
        return $dir;
    }
   public static function validate_server_file($path) {
    $path = wp_unslash($path);
    $path = trim($path);

    if ($path === '') {
        return new WP_Error('cmsg_bad_path', 'No file was selected.');
    }

    $uploads = wp_upload_dir();

    $allowed_dirs = [
        trailingslashit($uploads['basedir']) . 'large-videos',
        trailingslashit($uploads['basedir']) . 'cmsg-gcs-cache',
        trailingslashit($uploads['basedir']) . 'cmsg-drive-cache',
    ];

    $real_path = realpath($path);

    if (!$real_path || !file_exists($real_path)) {
        return new WP_Error('cmsg_file_missing', 'Selected file was not found.');
    }

    $is_allowed = false;

    foreach ($allowed_dirs as $allowed_dir) {
        $real_allowed = realpath($allowed_dir);

        if ($real_allowed && strpos($real_path, $real_allowed) === 0) {
            $is_allowed = true;
            break;
        }
    }

    if (!$is_allowed) {
        return new WP_Error(
            'cmsg_file_not_allowed',
            'Selected file is outside the allowed large-file directories.'
        );
    }

    return $real_path;
}

    public static function get_session_fingerprint() {
        $user_id = get_current_user_id();
        if ($user_id) return 'user:' . $user_id;
        if (empty($_COOKIE['cmsg_guest_id'])) {
            $guest = wp_generate_password(20, false, false);
            setcookie('cmsg_guest_id', $guest, time() + WEEK_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE['cmsg_guest_id'] = $guest;
        }
        return 'guest:' . sanitize_text_field($_COOKIE['cmsg_guest_id']);
    }
    public static function current_user_id_or_null() {
        $user_id = get_current_user_id();
        return $user_id ? (int)$user_id : null;
    }
    public static function build_request_fingerprint($payload) {
        $parts = [
            self::get_session_fingerprint(),
            sanitize_email($payload['request_email'] ?? ''),
            sanitize_text_field($payload['source_type'] ?? ''),
            (string)($payload['file_reference'] ?? ''),
            (string)($payload['runtime_minutes'] ?? ''),
            (string)($payload['amount'] ?? ''),
            (string)($payload['currency'] ?? ''),
            sanitize_text_field($payload['language_code'] ?? ''),
            sanitize_text_field($payload['model_size'] ?? ''),
        ];
        return hash('sha256', implode('|', $parts));
    }
}
