<?php
if (!defined('ABSPATH')) { exit; }

final class CMSG_Poster_Jobs {
    public static function table() { global $wpdb; return $wpdb->prefix . 'cmsg_poster_jobs'; }

    public static function create_from_authorized_draft($draft_id, $authorization_id, $brief) {
        global $wpdb;
        $draft = CMSG_Drafts::get($draft_id);
        if (!$draft) return new WP_Error('cmsg_poster_draft_missing', 'Poster draft not found.');

        $meta = [];
        if (!empty($draft->source_reference)) {
            $decoded = json_decode((string)$draft->source_reference, true);
            if (is_array($decoded)) $meta = $decoded;
        }

        $brief = wp_parse_args($brief, [
            'title' => '',
            'tagline' => '',
            'genre' => '',
            'mood' => '',
            'style_preset' => '',
            'poster_description' => '',
            'title_font_style' => 'cinematic_bold',
            'tagline_font_style' => 'clean_sans',
            'style_reference' => $meta['style_reference'] ?? '',
            'poster_assets' => $meta['poster_assets'] ?? [],
            'selected_concept' => 0,
        ]);

        $now = current_time('mysql');
        $wpdb->insert(self::table(), [
            'draft_id' => $draft_id,
            'payment_authorization_id' => $authorization_id,
            'request_email' => $draft->request_email,
            'title' => sanitize_text_field($brief['title'] ?? ''),
            'tagline' => sanitize_text_field($brief['tagline'] ?? ''),
            'genre' => sanitize_text_field($brief['genre'] ?? ''),
            'mood' => sanitize_text_field($brief['mood'] ?? ''),
            'style_preset' => sanitize_text_field($brief['style_preset'] ?? ''),
            'status' => 'processing',
            'payment_status' => 'paid',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $job_id = (int) $wpdb->insert_id;
        if ($job_id) {
            $job = self::get($job_id);
            $manifest = CMSG_Posters::generate_final_manifest($job, $brief, (int)($brief['selected_concept'] ?? 0));
            if (empty($manifest)) {
                $wpdb->update(self::table(), [
                    'status' => 'failed',
                    'updated_at' => current_time('mysql'),
                ], ['id' => $job_id]);
                return new WP_Error('cmsg_poster_final_failed', 'Final poster files could not be generated.');
            }
            $wpdb->update(self::table(), [
                'status' => 'completed',
                'final_manifest' => wp_json_encode($manifest),
                'updated_at' => current_time('mysql'),
            ], ['id' => $job_id]);
        }
        return $job_id;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
    }
}
