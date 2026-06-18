<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_DB {
    public static function activate() {
        global $wpdb; $charset = $wpdb->get_charset_collate(); require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $jobs = $wpdb->prefix . 'cmsg_jobs';
        $services = $wpdb->prefix . 'cmsg_service_requests';
        $drafts = $wpdb->prefix . 'cmsg_drafts';
        $auth = $wpdb->prefix . 'cmsg_payment_authorizations';
        $downloads = $wpdb->prefix . 'cmsg_download_grants';
        dbDelta("CREATE TABLE {$jobs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            status VARCHAR(50) NOT NULL,
            source_type VARCHAR(30) DEFAULT 'browser_upload',
            original_filename VARCHAR(255) NOT NULL,
            video_path TEXT NOT NULL,
            source_reference TEXT NULL,
            srt_path TEXT NULL,
            log_text LONGTEXT NULL,
            language_code VARCHAR(20) DEFAULT 'auto',
            model_size VARCHAR(50) DEFAULT 'small',
            requester_email VARCHAR(190) DEFAULT '',
            minutes_estimate DECIMAL(12,2) DEFAULT 0,
            estimated_price DECIMAL(12,2) DEFAULT 0,
            payment_status VARCHAR(30) DEFAULT 'pending',
            draft_id BIGINT UNSIGNED NULL,
            payment_authorization_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$services} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            requester_name VARCHAR(190) DEFAULT '',
            requester_email VARCHAR(190) DEFAULT '',
            company_name VARCHAR(190) DEFAULT '',
            service_type VARCHAR(80) DEFAULT '',
            budget_range VARCHAR(80) DEFAULT '',
            project_stage VARCHAR(80) DEFAULT '',
            details LONGTEXT NULL,
            attachment_url TEXT NULL,
            payment_status VARCHAR(30) DEFAULT 'pending',
            estimated_price DECIMAL(12,2) DEFAULT 0,
            status VARCHAR(40) DEFAULT 'new',
            PRIMARY KEY (id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$drafts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            user_id BIGINT UNSIGNED NULL,
            session_fingerprint VARCHAR(190) NOT NULL,
            request_email VARCHAR(190) DEFAULT '',
            source_type VARCHAR(30) NOT NULL,
            file_reference TEXT NOT NULL,
            file_hash VARCHAR(190) DEFAULT '',
            original_filename VARCHAR(255) DEFAULT '',
            language_code VARCHAR(20) DEFAULT 'auto',
            model_size VARCHAR(50) DEFAULT 'small',
            runtime_minutes DECIMAL(12,2) DEFAULT 0,
            amount DECIMAL(12,2) DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'USD',
            request_fingerprint VARCHAR(190) NOT NULL,
            payment_authorization_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$auth} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            user_id BIGINT UNSIGNED NULL,
            session_fingerprint VARCHAR(190) NOT NULL,
            draft_id BIGINT UNSIGNED NOT NULL,
            authorization_token_hash VARCHAR(190) NOT NULL,
            paypal_order_id VARCHAR(190) NOT NULL,
            paypal_capture_id VARCHAR(190) NOT NULL,
            amount DECIMAL(12,2) DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'USD',
            kind VARCHAR(30) DEFAULT 'subtitle',
            request_fingerprint VARCHAR(190) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY paypal_order_id (paypal_order_id),
            UNIQUE KEY paypal_capture_id (paypal_capture_id),
            UNIQUE KEY authorization_token_hash (authorization_token_hash)
        ) {$charset};");
        dbDelta("CREATE TABLE {$downloads} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            job_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            session_fingerprint VARCHAR(190) NOT NULL,
            grant_token_hash VARCHAR(190) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY grant_token_hash (grant_token_hash)
        ) {$charset};");
    }
}


if (!class_exists('CMSG_DB_V261')) {
final class CMSG_DB_V261 {
    public static function migrate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $poster = $wpdb->prefix . 'cmsg_poster_jobs';
        dbDelta("CREATE TABLE {$poster} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            draft_id BIGINT UNSIGNED NULL,
            payment_authorization_id BIGINT UNSIGNED NULL,
            request_email VARCHAR(190) DEFAULT '',
            title VARCHAR(255) DEFAULT '',
            tagline TEXT NULL,
            genre VARCHAR(120) DEFAULT '',
            mood VARCHAR(120) DEFAULT '',
            style_preset VARCHAR(120) DEFAULT '',
            status VARCHAR(40) DEFAULT 'draft',
            payment_status VARCHAR(30) DEFAULT 'pending',
            preview_manifest LONGTEXT NULL,
            final_manifest LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};");
        $drafts = $wpdb->prefix . 'cmsg_drafts';
        $jobs = $wpdb->prefix . 'cmsg_jobs';
        $wpdb->query("ALTER TABLE {$drafts} ADD COLUMN module_type VARCHAR(40) DEFAULT 'subtitle'");
        $wpdb->query("ALTER TABLE {$drafts} ADD COLUMN storage_provider VARCHAR(40) DEFAULT 'local'");
        $wpdb->query("ALTER TABLE {$drafts} ADD COLUMN source_reference TEXT NULL");
        $wpdb->query("ALTER TABLE {$drafts} ADD COLUMN object_key TEXT NULL");
        $wpdb->query("ALTER TABLE {$jobs} ADD COLUMN storage_provider VARCHAR(40) DEFAULT 'local'");
        $wpdb->query("ALTER TABLE {$jobs} ADD COLUMN object_key TEXT NULL");
    }
}}


if (!class_exists('CMSG_DB_V270')) {
final class CMSG_DB_V270 {
    public static function migrate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $trailer = $wpdb->prefix . 'cmsg_trailer_jobs';
        dbDelta("CREATE TABLE {$trailer} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            draft_id BIGINT UNSIGNED NULL,
            payment_authorization_id BIGINT UNSIGNED NULL,
            request_email VARCHAR(190) DEFAULT '',
            title VARCHAR(255) DEFAULT '',
            description LONGTEXT NULL,
            runtime_target VARCHAR(50) DEFAULT '',
            trailer_type VARCHAR(80) DEFAULT '',
            status VARCHAR(40) DEFAULT 'draft',
            payment_status VARCHAR(30) DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};");
    }
}}

if (!class_exists('CMSG_DB_V282')) {
final class CMSG_DB_V282 {
    public static function migrate() {
        global $wpdb;
        $jobs = $wpdb->prefix . 'cmsg_jobs';
        $drafts = $wpdb->prefix . 'cmsg_drafts';
        $poster = $wpdb->prefix . 'cmsg_poster_jobs';
        $wpdb->query("ALTER TABLE {$drafts} ADD COLUMN caption_mode VARCHAR(30) DEFAULT 'subtitle'");
        $wpdb->query("ALTER TABLE {$jobs} ADD COLUMN caption_mode VARCHAR(30) DEFAULT 'subtitle'");
        $wpdb->query("ALTER TABLE {$jobs} ADD COLUMN vtt_path TEXT NULL");
        $wpdb->query("ALTER TABLE {$jobs} ADD COLUMN caption_json LONGTEXT NULL");
        $wpdb->query("ALTER TABLE {$poster} ADD COLUMN selected_concept INT DEFAULT 0");
        $wpdb->query("ALTER TABLE {$poster} ADD COLUMN selected_preview_path TEXT NULL");
    }
}}

if (!class_exists('CMSG_DB_V300')) {
final class CMSG_DB_V300 {
    public static function migrate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $trailer = $wpdb->prefix . 'cmsg_trailer_jobs';
        dbDelta("CREATE TABLE {$trailer} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            draft_id BIGINT UNSIGNED NULL,
            payment_authorization_id BIGINT UNSIGNED NULL,
            request_email VARCHAR(190) DEFAULT '',
            title VARCHAR(255) DEFAULT '',
            description LONGTEXT NULL,
            runtime_target VARCHAR(50) DEFAULT '',
            trailer_type VARCHAR(80) DEFAULT '',
            genre VARCHAR(120) DEFAULT '',
            tone VARCHAR(120) DEFAULT '',
            target_audience VARCHAR(190) DEFAULT '',
            required_elements LONGTEXT NULL,
            music_style VARCHAR(190) DEFAULT '',
            text_cards LONGTEXT NULL,
            cta VARCHAR(190) DEFAULT '',
            asset_links LONGTEXT NULL,
            brief_json LONGTEXT NULL,
            deliverable_manifest LONGTEXT NULL,
            status_message TEXT NULL,
            status VARCHAR(40) DEFAULT 'draft',
            payment_status VARCHAR(30) DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};");
        $columns = [
            'genre' => "ALTER TABLE {$trailer} ADD COLUMN genre VARCHAR(120) DEFAULT ''",
            'tone' => "ALTER TABLE {$trailer} ADD COLUMN tone VARCHAR(120) DEFAULT ''",
            'target_audience' => "ALTER TABLE {$trailer} ADD COLUMN target_audience VARCHAR(190) DEFAULT ''",
            'required_elements' => "ALTER TABLE {$trailer} ADD COLUMN required_elements LONGTEXT NULL",
            'music_style' => "ALTER TABLE {$trailer} ADD COLUMN music_style VARCHAR(190) DEFAULT ''",
            'text_cards' => "ALTER TABLE {$trailer} ADD COLUMN text_cards LONGTEXT NULL",
            'cta' => "ALTER TABLE {$trailer} ADD COLUMN cta VARCHAR(190) DEFAULT ''",
            'asset_links' => "ALTER TABLE {$trailer} ADD COLUMN asset_links LONGTEXT NULL",
            'brief_json' => "ALTER TABLE {$trailer} ADD COLUMN brief_json LONGTEXT NULL",
            'deliverable_manifest' => "ALTER TABLE {$trailer} ADD COLUMN deliverable_manifest LONGTEXT NULL",
            'status_message' => "ALTER TABLE {$trailer} ADD COLUMN status_message TEXT NULL",
        ];
        foreach ($columns as $col => $sql) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$trailer} LIKE %s", $col));
            if (!$exists) { $wpdb->query($sql); }
        }
    }
}}
