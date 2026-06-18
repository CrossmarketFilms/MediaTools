<?php
if (!defined('ABSPATH')) { exit; }

final class CMSG_Trailer_Jobs {
    public static function table() { global $wpdb; return $wpdb->prefix . 'cmsg_trailer_jobs'; }

    public static function create_from_authorized_draft($draft_id, $authorization_id, $brief) {
        global $wpdb;
        $draft = CMSG_Drafts::get($draft_id);
        if (!$draft) { return new WP_Error('cmsg_trailer_draft_missing', 'Trailer draft not found.'); }

        $brief = CMSG_Trailers::sanitize_brief($brief);
        $valid = CMSG_Trailers::validate_brief($brief);
        if (is_wp_error($valid)) { return $valid; }

        $now = current_time('mysql');
        $brief_json = wp_json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $wpdb->insert(self::table(), [
            'draft_id' => $draft_id,
            'payment_authorization_id' => $authorization_id,
            'request_email' => $draft->request_email,
            'title' => sanitize_text_field($brief['title'] ?? ''),
            'description' => sanitize_textarea_field($brief['description'] ?? ''),
            'runtime_target' => sanitize_text_field($brief['runtime_target'] ?? ''),
            'trailer_type' => sanitize_text_field($brief['trailer_type'] ?? ''),
            'genre' => sanitize_text_field($brief['genre'] ?? ''),
            'tone' => sanitize_text_field($brief['tone'] ?? ''),
            'target_audience' => sanitize_text_field($brief['target_audience'] ?? ''),
            'required_elements' => wp_json_encode($brief['required_elements'] ?? []),
            'music_style' => sanitize_text_field($brief['music_style'] ?? ''),
            'text_cards' => wp_json_encode($brief['text_cards'] ?? []),
            'cta' => sanitize_text_field($brief['cta'] ?? ''),
            'asset_links' => wp_json_encode($brief['asset_links'] ?? []),
            'brief_json' => $brief_json,
            'status' => 'processing',
            'status_message' => 'Building structured trailer brief and edit plan.',
            'payment_status' => 'paid',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $job_id = (int) $wpdb->insert_id;
        if (!$job_id) { return new WP_Error('cmsg_trailer_job_create_failed', 'Unable to create trailer job.'); }

        $manifest = CMSG_Trailers::create_deliverable_package($job_id, $brief);
        $wpdb->update(self::table(), [
            'status' => 'completed',
            'status_message' => 'Trailer brief package is ready.',
            'deliverable_manifest' => wp_json_encode($manifest),
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id]);

        return $job_id;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d', $id));
    }

    public static function manifest($job) {
        if (!$job || empty($job->deliverable_manifest)) { return []; }
        $manifest = json_decode((string) $job->deliverable_manifest, true);
        return is_array($manifest) ? $manifest : [];
    }
}
