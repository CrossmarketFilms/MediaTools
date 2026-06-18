<?php
if (!defined('ABSPATH')) { exit; }

final class CMSG_Posters {
    public static function preview_watermark() {
        return CMSG_Plugin::settings()['poster_preview_watermark_text'] ?: 'CROSSMARKET PREVIEW';
    }

    public static function price() {
        return round((float) CMSG_Plugin::settings()['poster_base_price'], 2);
    }

    public static function create_draft($payload) {
        $payload['module_type'] = 'poster';
        $payload['source_type'] = 'poster_brief';
        $payload['amount'] = self::price();
        $payload['currency'] = CMSG_Plugin::settings()['paypal_currency'];
        $payload['request_email'] = sanitize_email($payload['request_email'] ?? '');
        return CMSG_Drafts::create($payload);
    }

    public static function generate_preview_manifest($draft, $brief) {
        return CMSG_Poster_AI::generate_previews($brief, $draft->id);
    }

    public static function generate_final_manifest($job, $brief = [], $selected_concept = 0) {
        return CMSG_Poster_AI::generate_final_files($brief, $job->id, $selected_concept);
    }
}
