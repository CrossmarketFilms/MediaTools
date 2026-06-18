<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_Google_Drive {
    public static function enabled() { return CMSG_Plugin::settings()['enable_google_drive_import'] === '1'; }

    public static function parse_link($url) {
        $url = trim((string)$url);
        if (!$url || stripos($url, 'drive.google.com') === false) return new WP_Error('cmsg_drive_invalid', 'Please provide a valid Google Drive link.');
        if (preg_match('~/file/d/([^/]+)~', $url, $m)) return $m[1];
        if (preg_match('~id=([a-zA-Z0-9_-]+)~', $url, $m)) return $m[1];
        return new WP_Error('cmsg_drive_invalid', 'Unable to extract a Google Drive file ID from this link.');
    }

    public static function validate_link($url) {
        if (!self::enabled()) return new WP_Error('cmsg_drive_disabled', 'Google Drive import is not enabled.');
        $file_id = self::parse_link($url);
        if (is_wp_error($file_id)) return $file_id;
        return [
            'file_id' => $file_id,
            'filename' => 'Google Drive file',
            'message' => 'Drive link accepted. Production setup still requires a service account or public file access to import the file.',
        ];
    }

    public static function import_into_draft($draft, $url) {
        $validated = self::validate_link($url);
        if (is_wp_error($validated)) return $validated;
        $file_id = $validated['file_id'];
        global $wpdb;
        $object_key = trim(CMSG_Plugin::settings()['gcs_subtitle_import_prefix'], '/') . '/' . gmdate('Y/m/d') . '/' . $file_id;
        $wpdb->update(CMSG_Drafts::table(), [
            'source_type' => 'google_drive_import',
            'storage_provider' => 'gcs',
            'source_reference' => $url,
            'object_key' => $object_key,
            'file_reference' => $object_key,
            'updated_at' => current_time('mysql'),
        ], ['id' => $draft->id]);
        return [
            'file_id' => $file_id,
            'object_key' => $object_key,
            'message' => 'Drive file reference has been registered. Connect a Google service account to automate import into Cloud Storage.',
        ];
    }
}
