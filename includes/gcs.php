<?php
if (!defined('ABSPATH')) { exit; }

final class CMSG_GCS {

    public static function enabled() {
        $s = CMSG_Plugin::settings();
        return $s['enable_secure_gcs_upload'] === '1'
            && !empty($s['gcs_bucket_name'])
            && !empty($s['gcs_signer_url'])
            && !empty($s['gcs_signer_shared_secret']);
    }

public static function signed_upload_policy($draft, $filename = null, $content_type = null) {
    if (!self::enabled()) {
        return new WP_Error('cmsg_gcs_disabled', 'Secure Google Cloud upload is not configured.');
    }

    $s = CMSG_Plugin::settings();

    $filename = $filename ?: ($draft->original_filename ?: 'upload.bin');
    $content_type = $content_type ?: 'application/octet-stream';

    $response = wp_remote_post(trailingslashit($s['gcs_signer_url']) . 'sign-upload', [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Plugin-Secret' => $s['gcs_signer_shared_secret'],
        ],
        'body' => wp_json_encode([
            'filename' => sanitize_file_name($filename),
            'content_type' => sanitize_text_field($content_type),
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('cmsg_gcs_error', $response->get_error_message());
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($data['upload_url']) || empty($data['object_key'])) {
        return new WP_Error('cmsg_gcs_error', 'Invalid signer response.');
    }

    return [
        'upload_url' => $data['upload_url'],
        'object_key' => $data['object_key'],
    ];

    }

    public static function confirm_uploaded_object($draft, $object_key) {
        if (!$object_key) {
            return new WP_Error('cmsg_gcs_missing', 'Missing uploaded object key.');
        }

        global $wpdb;

        $wpdb->update(CMSG_Drafts::table(), [
            'storage_provider' => 'gcs',
            'source_reference' => $object_key,
            'object_key' => $object_key,
            'file_reference' => $object_key,
            'updated_at' => current_time('mysql'),
        ], ['id' => $draft->id]);

        return true;
    }

    public static function output_reference($filename) {
        $s = CMSG_Plugin::settings();
        return trim($s['gcs_subtitle_output_prefix'], '/') . '/' . basename($filename);
    }
}
