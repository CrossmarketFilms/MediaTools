<?php
if (!defined('ABSPATH')) { exit; }

final class CMSG_Trailers {
    public static function price() {
        return round((float) CMSG_Plugin::settings()['trailer_request_base_price'], 2);
    }

    public static function sanitize_brief($payload) {
        $lines = function($value) {
            $value = is_array($value) ? implode("\n", $value) : (string) $value;
            $parts = preg_split('/\r\n|\r|\n/', $value);
            $clean = [];
            foreach ($parts as $part) {
                $part = trim(wp_strip_all_tags($part));
                if ($part !== '') { $clean[] = $part; }
            }
            return $clean;
        };

        return [
            'title' => sanitize_text_field($payload['title'] ?? ''),
            'request_email' => sanitize_email($payload['request_email'] ?? ''),
            'trailer_type' => sanitize_text_field($payload['trailer_type'] ?? 'official_trailer'),
            'runtime_target' => sanitize_text_field($payload['runtime_target'] ?? '60_sec'),
            'genre' => sanitize_text_field($payload['genre'] ?? ''),
            'tone' => sanitize_text_field($payload['tone'] ?? ''),
            'target_audience' => sanitize_text_field($payload['target_audience'] ?? ''),
            'music_style' => sanitize_text_field($payload['music_style'] ?? ''),
            'cta' => sanitize_text_field($payload['cta'] ?? ''),
            'description' => sanitize_textarea_field($payload['description'] ?? ''),
            'required_elements' => $lines($payload['required_elements'] ?? ''),
            'text_cards' => $lines($payload['text_cards'] ?? ''),
            'asset_links' => $lines($payload['asset_links'] ?? ''),
        ];
    }

    public static function validate_brief($brief) {
        if (empty($brief['request_email']) || !is_email($brief['request_email'])) {
            return new WP_Error('cmsg_trailer_email', 'A valid email address is required.');
        }
        if (empty($brief['title'])) {
            return new WP_Error('cmsg_trailer_title', 'Project title is required.');
        }
        if (empty($brief['description']) && empty($brief['required_elements']) && empty($brief['text_cards'])) {
            return new WP_Error('cmsg_trailer_brief', 'Add a trailer description, required elements, or text cards so Trailer Studio can build a useful brief.');
        }
        return true;
    }

    public static function create_draft($payload) {
        $brief = self::sanitize_brief($payload);
        $valid = self::validate_brief($brief);
        if (is_wp_error($valid)) { return $valid; }

        $amount = self::price();
        $brief_json = wp_json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return CMSG_Drafts::create([
            'module_type' => 'trailer',
            'source_type' => 'trailer_brief',
            'request_email' => $brief['request_email'],
            'file_reference' => $brief_json,
            'file_hash' => hash('sha256', $brief_json),
            'original_filename' => sanitize_file_name($brief['title'] . '-trailer-brief.json'),
            'runtime_minutes' => 0,
            'amount' => $amount,
            'currency' => CMSG_Plugin::settings()['paypal_currency'],
        ]);
    }

    public static function runtime_seconds($runtime_target) {
        $map = [
            '15_sec' => 15,
            '30_sec' => 30,
            '60_sec' => 60,
            '90_sec' => 90,
            '120_sec' => 120,
        ];
        return $map[$runtime_target] ?? 60;
    }

    public static function build_beat_map($brief) {
        $duration = self::runtime_seconds($brief['runtime_target'] ?? '60_sec');
        $beats = [];
        $segments = [
            ['Setup / world', 0.00, 0.18],
            ['Character / emotional hook', 0.18, 0.34],
            ['Conflict / stakes', 0.34, 0.54],
            ['Escalation montage', 0.54, 0.78],
            ['Final impact / CTA', 0.78, 1.00],
        ];
        $required = array_values((array)($brief['required_elements'] ?? []));
        $cards = array_values((array)($brief['text_cards'] ?? []));

        foreach ($segments as $index => $seg) {
            $start = (int) floor($duration * $seg[1]);
            $end = max($start + 1, (int) floor($duration * $seg[2]));
            $beats[] = [
                'beat' => $seg[0],
                'start_second' => $start,
                'end_second' => $end,
                'required_element' => $required[$index] ?? '',
                'text_card' => $cards[$index] ?? '',
                'direction' => self::direction_for_beat($seg[0], $brief),
            ];
        }
        return $beats;
    }

    private static function direction_for_beat($beat, $brief) {
        $tone = $brief['tone'] ?: 'cinematic';
        $genre = $brief['genre'] ?: 'film';
        switch ($beat) {
            case 'Setup / world':
                return "Open with a {$tone} visual that immediately establishes the {$genre} world and central mood.";
            case 'Character / emotional hook':
                return 'Prioritize close-ups, emotional reactions, and moments that make the audience care.';
            case 'Conflict / stakes':
                return 'Reveal the core problem without giving away the ending. Build urgency.';
            case 'Escalation montage':
                return 'Cut faster, use movement, action, reaction shots, and rising music intensity.';
            default:
                return 'End with the strongest title card, CTA, logo, or final provocative image.';
        }
    }

    public static function create_deliverable_package($job_id, $brief) {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'cmsg-trailer-jobs/job-' . (int)$job_id;
        $url = trailingslashit($upload['baseurl']) . 'cmsg-trailer-jobs/job-' . (int)$job_id;
        if (!is_dir($dir)) { wp_mkdir_p($dir); }

        $beats = self::build_beat_map($brief);
        $brief['beat_map'] = $beats;
        $brief['generated_at'] = current_time('mysql');
        $brief['version'] = '3.0.0';

        $brief_path = $dir . '/trailer-brief.json';
        file_put_contents($brief_path, wp_json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $plan = self::render_plan_text($brief, $beats);
        $plan_path = $dir . '/trailer-edit-plan.txt';
        file_put_contents($plan_path, $plan);

        $csv_path = $dir . '/trailer-beat-map.csv';
        $fh = fopen($csv_path, 'w');
        fputcsv($fh, ['start_second', 'end_second', 'beat', 'required_element', 'text_card', 'direction']);
        foreach ($beats as $beat) {
            fputcsv($fh, [$beat['start_second'], $beat['end_second'], $beat['beat'], $beat['required_element'], $beat['text_card'], $beat['direction']]);
        }
        fclose($fh);

        $manifest = [
            ['label' => 'Trailer Brief JSON', 'path' => $brief_path, 'url' => $url . '/trailer-brief.json'],
            ['label' => 'Trailer Edit Plan', 'path' => $plan_path, 'url' => $url . '/trailer-edit-plan.txt'],
            ['label' => 'Trailer Beat Map CSV', 'path' => $csv_path, 'url' => $url . '/trailer-beat-map.csv'],
        ];

        return $manifest;
    }

    private static function render_plan_text($brief, $beats) {
        $out = [];
        $out[] = 'Crossmarket Trailer Studio v3.0';
        $out[] = '================================';
        $out[] = '';
        $out[] = 'Project: ' . ($brief['title'] ?? 'Untitled');
        $out[] = 'Type: ' . ($brief['trailer_type'] ?? '');
        $out[] = 'Runtime Target: ' . ($brief['runtime_target'] ?? '');
        $out[] = 'Genre: ' . ($brief['genre'] ?? '');
        $out[] = 'Tone: ' . ($brief['tone'] ?? '');
        $out[] = 'Target Audience: ' . ($brief['target_audience'] ?? '');
        $out[] = 'Music Style: ' . ($brief['music_style'] ?? '');
        $out[] = 'CTA / End Card: ' . ($brief['cta'] ?? '');
        $out[] = '';
        $out[] = 'Creative Description';
        $out[] = '--------------------';
        $out[] = $brief['description'] ?? '';
        $out[] = '';
        $out[] = 'Required Elements';
        $out[] = '-----------------';
        foreach ((array)($brief['required_elements'] ?? []) as $item) { $out[] = '- ' . $item; }
        $out[] = '';
        $out[] = 'Text Cards';
        $out[] = '----------';
        foreach ((array)($brief['text_cards'] ?? []) as $item) { $out[] = '- ' . $item; }
        $out[] = '';
        $out[] = 'Beat Map';
        $out[] = '--------';
        foreach ($beats as $beat) {
            $out[] = $beat['start_second'] . 's-' . $beat['end_second'] . 's | ' . $beat['beat'];
            if ($beat['required_element']) { $out[] = '  Required: ' . $beat['required_element']; }
            if ($beat['text_card']) { $out[] = '  Text Card: ' . $beat['text_card']; }
            $out[] = '  Direction: ' . $beat['direction'];
        }
        $out[] = '';
        $out[] = 'Asset Links / Notes';
        $out[] = '--------------------';
        foreach ((array)($brief['asset_links'] ?? []) as $item) { $out[] = '- ' . $item; }
        $out[] = '';
        $out[] = 'Production Note: This package turns the user description and required elements into structured trailer direction for editing and future automated assembly.';
        return implode("\n", $out) . "\n";
    }
}
