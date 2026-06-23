<?php
/**
 * Plugin Name: Crossmarket Creative Studio Core v3.0.1
 * Description: Unified Subtitle Studio, Poster Studio, and structured Trailer Studio workflows for Crossmarket Films.
 * Version: 3.2.1
 * Author: OpenAI for Crossmarket Films
 * Text Domain: crossmarket-subtitle-generator
 */

if (!defined('ABSPATH')) { exit; }

define('CMSG_VERSION', '3.2.1');
define('CMSG_FILE', __FILE__);
define('CMSG_DIR', plugin_dir_path(__FILE__));
define('CMSG_URL', plugin_dir_url(__FILE__));

require_once CMSG_DIR . 'includes/db.php';
require_once CMSG_DIR . 'includes/validation.php';
require_once CMSG_DIR . 'includes/file-browser.php';
require_once CMSG_DIR . 'includes/drafts.php';
require_once CMSG_DIR . 'includes/payments.php';
require_once CMSG_DIR . 'includes/downloads.php';
require_once CMSG_DIR . 'includes/gcs.php';
require_once CMSG_DIR . 'includes/google-drive.php';
require_once CMSG_DIR . 'includes/posters.php';
require_once CMSG_DIR . 'includes/poster-jobs.php';
require_once CMSG_DIR . 'includes/poster-ai.php';
require_once CMSG_DIR . 'includes/trailers.php';
require_once CMSG_DIR . 'includes/trailer-jobs.php';
require_once CMSG_DIR . 'includes/jobs.php';
require_once CMSG_DIR . 'includes/processor.php';
require_once CMSG_DIR . 'includes/paypal.php';
require_once CMSG_DIR . 'includes/ajax.php';
require_once CMSG_DIR . 'includes/admin-settings.php';

final class CMSG_Plugin {
    const OPTION_KEY = 'cmsg_settings';

    public static function defaults() {
        return [
            'brand_name' => 'Crossmarket Films',
            'support_email' => get_option('admin_email'),
            'from_email' => get_option('admin_email'),
            'subtitle_price_per_minute' => '3.50',
            'poster_base_price' => '150',
            'trailer_base_price' => '350',
            'consulting_base_price' => '200',
            'enable_service_orders' => '1',
            'enable_pricing' => '1',
            'python_binary' => '/opt/subgen-venv/bin/python',
            'ffmpeg_binary' => '/usr/bin/ffmpeg',
            'whisper_mode' => 'faster-whisper',
            'default_model' => 'small',
            'thank_you_url' => '',
            'currency_symbol' => '$',
            'accent_color' => '#d81414',
            'hero_heading' => 'Create Subtitles, Closed Captions, Posters, and Creative Assets.',
            'hero_subheading' => 'Upload short videos directly, or use the secure large-file workflow for feature-length masters.',
            'small_upload_limit_gb' => '2',
            'large_file_directory' => '/var/www/html/wp-content/uploads/large-videos',
            'paypal_enabled' => '0',
            'paypal_client_id' => '',
            'paypal_client_secret' => '',
            'paypal_mode' => 'live',
            'paypal_currency' => 'USD',
            'payment_authorization_expiry_minutes' => '30',
            'download_grant_expiry_minutes' => '10',
            'one_time_download_grants' => '1',
            'enable_secure_gcs_upload' => '0',
            'gcs_bucket_name' => '',
            'gcs_project_id' => '',
            'gcs_service_account_json' => '',
            'gcs_signed_upload_expiry_seconds' => '900',
            'gcs_signer_url' => '',
            'gcs_signer_shared_secret' => '',
            'gcs_signed_upload_expiry_seconds' => '900',
            'gcs_subtitle_upload_prefix' => 'subtitles/uploads/',
            'gcs_subtitle_import_prefix' => 'subtitles/imports/',
            'gcs_subtitle_output_prefix' => 'subtitles/output/',
            'enable_google_drive_import' => '0',
            'google_drive_link_import_only' => '1',
            'enable_poster_studio' => '1',
            'poster_base_price' => '49',
            'poster_preview_count' => '3',
            'poster_preview_watermark_text' => 'CROSSMARKET PREVIEW',
            'openai_api_key' => '',
            'enable_trailer_studio' => '1',
            'trailer_request_base_price' => '250',
        ];
    }

    public static function settings() {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) $saved = [];
        return wp_parse_args($saved, self::defaults());
    }

    public static function activate() {
        CMSG_DB::activate(); if (class_exists('CMSG_DB_V261')) CMSG_DB_V261::migrate(); if (class_exists('CMSG_DB_V270')) CMSG_DB_V270::migrate(); if (class_exists('CMSG_DB_V282')) CMSG_DB_V282::migrate(); if (class_exists('CMSG_DB_V300')) CMSG_DB_V300::migrate();
        if (!get_option(self::OPTION_KEY)) add_option(self::OPTION_KEY, self::defaults());
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('cmsg_process_job');
    }

    public static function init() {
        add_action('init', [__CLASS__, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_menu', ['CMSG_Admin_Settings', 'register_menu']);
        add_action('admin_init', ['CMSG_Admin_Settings', 'register_settings']);

        add_action('wp_ajax_cmsg_create_draft', ['CMSG_Ajax', 'create_draft']);
        add_action('wp_ajax_nopriv_cmsg_create_draft', ['CMSG_Ajax', 'create_draft']);
        add_action('wp_ajax_cmsg_create_paypal_order', ['CMSG_Ajax', 'create_paypal_order']);
        add_action('wp_ajax_nopriv_cmsg_create_paypal_order', ['CMSG_Ajax', 'create_paypal_order']);
        add_action('wp_ajax_cmsg_capture_paypal_order', ['CMSG_Ajax', 'capture_paypal_order']);
        add_action('wp_ajax_nopriv_cmsg_capture_paypal_order', ['CMSG_Ajax', 'capture_paypal_order']);
        add_action('wp_ajax_cmsg_finalize_paid_draft', ['CMSG_Ajax', 'finalize_paid_draft']);
        add_action('wp_ajax_nopriv_cmsg_finalize_paid_draft', ['CMSG_Ajax', 'finalize_paid_draft']);
        add_action('wp_ajax_cmsg_job_status', ['CMSG_Ajax', 'job_status']);
        add_action('wp_ajax_nopriv_cmsg_job_status', ['CMSG_Ajax', 'job_status']);
        add_action('wp_ajax_cmsg_retry_subtitle_job', ['CMSG_Ajax', 'retry_subtitle_job']);
        add_action('wp_ajax_nopriv_cmsg_retry_subtitle_job', ['CMSG_Ajax', 'retry_subtitle_job']);
        add_action('wp_ajax_cmsg_list_server_files', ['CMSG_Ajax', 'list_server_files']);
        add_action('wp_ajax_nopriv_cmsg_list_server_files', ['CMSG_Ajax', 'list_server_files']);
        add_action('wp_ajax_cmsg_issue_download_grant', ['CMSG_Ajax', 'issue_download_grant']);
        add_action('wp_ajax_nopriv_cmsg_issue_download_grant', ['CMSG_Ajax', 'issue_download_grant']);
        add_action('wp_ajax_cmsg_download_srt', ['CMSG_Ajax', 'download_srt']);
        add_action('wp_ajax_cmsg_detect_server_file_runtime', ['CMSG_Ajax', 'detect_server_file_runtime']);
        add_action('wp_ajax_nopriv_cmsg_detect_server_file_runtime', ['CMSG_Ajax', 'detect_server_file_runtime']);
        add_action('wp_ajax_cmsg_get_gcs_signed_upload', ['CMSG_Ajax', 'get_gcs_signed_upload']);
        add_action('wp_ajax_nopriv_cmsg_get_gcs_signed_upload', ['CMSG_Ajax', 'get_gcs_signed_upload']);
        add_action('wp_ajax_cmsg_confirm_gcs_upload', ['CMSG_Ajax', 'confirm_gcs_upload']);
        add_action('wp_ajax_nopriv_cmsg_confirm_gcs_upload', ['CMSG_Ajax', 'confirm_gcs_upload']);
        add_action('wp_ajax_cmsg_validate_drive_link', ['CMSG_Ajax', 'validate_drive_link']);
        add_action('wp_ajax_nopriv_cmsg_validate_drive_link', ['CMSG_Ajax', 'validate_drive_link']);
        add_action('wp_ajax_cmsg_import_drive_file', ['CMSG_Ajax', 'import_drive_file']);
        add_action('wp_ajax_nopriv_cmsg_import_drive_file', ['CMSG_Ajax', 'import_drive_file']);
        add_action('wp_ajax_cmsg_create_poster_draft', ['CMSG_Ajax', 'create_poster_draft']);
        add_action('wp_ajax_nopriv_cmsg_create_poster_draft', ['CMSG_Ajax', 'create_poster_draft']);
        add_action('wp_ajax_cmsg_generate_poster_previews', ['CMSG_Ajax', 'generate_poster_previews']);
        add_action('wp_ajax_nopriv_cmsg_generate_poster_previews', ['CMSG_Ajax', 'generate_poster_previews']);
        add_action('wp_ajax_cmsg_blend_selected_actor_face', ['CMSG_Ajax', 'blend_selected_actor_face']);
        add_action('wp_ajax_nopriv_cmsg_blend_selected_actor_face', ['CMSG_Ajax', 'blend_selected_actor_face']);
        add_action('wp_ajax_cmsg_create_poster_paypal_order', ['CMSG_Ajax', 'create_poster_paypal_order']);
        add_action('wp_ajax_nopriv_cmsg_create_poster_paypal_order', ['CMSG_Ajax', 'create_poster_paypal_order']);
        add_action('wp_ajax_cmsg_capture_poster_paypal_order', ['CMSG_Ajax', 'capture_poster_paypal_order']);
        add_action('wp_ajax_nopriv_cmsg_capture_poster_paypal_order', ['CMSG_Ajax', 'capture_poster_paypal_order']);
        add_action('wp_ajax_cmsg_finalize_paid_poster_draft', ['CMSG_Ajax', 'finalize_paid_poster_draft']);
        add_action('wp_ajax_nopriv_cmsg_finalize_paid_poster_draft', ['CMSG_Ajax', 'finalize_paid_poster_draft']);
        add_action('wp_ajax_cmsg_poster_job_status', ['CMSG_Ajax', 'poster_job_status']);
        add_action('wp_ajax_nopriv_cmsg_poster_job_status', ['CMSG_Ajax', 'poster_job_status']);
        add_action('wp_ajax_cmsg_issue_poster_download_grant', ['CMSG_Ajax', 'issue_poster_download_grant']);
        add_action('wp_ajax_nopriv_cmsg_issue_poster_download_grant', ['CMSG_Ajax', 'issue_poster_download_grant']);
        add_action('wp_ajax_cmsg_download_poster_asset', ['CMSG_Ajax', 'download_poster_asset']);
        add_action('wp_ajax_nopriv_cmsg_download_poster_asset', ['CMSG_Ajax', 'download_poster_asset']);
        add_action('wp_ajax_cmsg_create_trailer_draft', ['CMSG_Ajax', 'create_trailer_draft']);
        add_action('wp_ajax_nopriv_cmsg_create_trailer_draft', ['CMSG_Ajax', 'create_trailer_draft']);
        add_action('wp_ajax_cmsg_create_trailer_paypal_order', ['CMSG_Ajax', 'create_trailer_paypal_order']);
        add_action('wp_ajax_nopriv_cmsg_create_trailer_paypal_order', ['CMSG_Ajax', 'create_trailer_paypal_order']);
        add_action('wp_ajax_cmsg_capture_trailer_paypal_order', ['CMSG_Ajax', 'capture_trailer_paypal_order']);
        add_action('wp_ajax_nopriv_cmsg_capture_trailer_paypal_order', ['CMSG_Ajax', 'capture_trailer_paypal_order']);
        add_action('wp_ajax_cmsg_finalize_paid_trailer_draft', ['CMSG_Ajax', 'finalize_paid_trailer_draft']);
        add_action('wp_ajax_nopriv_cmsg_finalize_paid_trailer_draft', ['CMSG_Ajax', 'finalize_paid_trailer_draft']);
        add_action('wp_ajax_cmsg_trailer_job_status', ['CMSG_Ajax', 'trailer_job_status']);
        add_action('wp_ajax_nopriv_cmsg_trailer_job_status', ['CMSG_Ajax', 'trailer_job_status']);
        add_action('wp_ajax_nopriv_cmsg_download_srt', ['CMSG_Ajax', 'download_srt']);
        add_action('wp_ajax_cmsg_submit_service_request', ['CMSG_Ajax', 'submit_service_request']);
        add_action('wp_ajax_nopriv_cmsg_submit_service_request', ['CMSG_Ajax', 'submit_service_request']);

        add_action('cmsg_process_job', ['CMSG_Processor', 'process_job'], 10, 1);
    }

    public static function register_shortcodes() {
        add_shortcode('cm_subtitle_generator', [__CLASS__, 'render_upload_ui']);
        add_shortcode('cm_service_hub', [__CLASS__, 'render_service_hub']);
        add_shortcode('cm_media_tools_dashboard', [__CLASS__, 'render_media_tools_dashboard']);
        add_shortcode('cm_poster_studio', [__CLASS__, 'render_poster_studio']);
        add_shortcode('cm_trailer_studio', [__CLASS__, 'render_trailer_studio']);
    }

    public static function enqueue_assets() {
        wp_register_style('cmsg-style', CMSG_URL . 'assets/css/admin.css', [], CMSG_VERSION);
        wp_register_style('cmsg-dashboard-style', CMSG_URL . 'assets/css/dashboard.css', [], CMSG_VERSION);
        wp_register_style('cmsg-poster-style', CMSG_URL . 'assets/css/poster-ui.css', [], CMSG_VERSION);
        wp_register_script('cmsg-ui', CMSG_URL . 'assets/js/subtitle-ui.js', ['jquery'], CMSG_VERSION, true);
        wp_register_script('cmsg-shared-ui', CMSG_URL . 'assets/js/shared-ui.js', ['jquery'], CMSG_VERSION, true);
        wp_register_script('cmsg-poster-ui', CMSG_URL . 'assets/js/poster-ui.js', ['jquery', 'cmsg-shared-ui'], CMSG_VERSION, true);
        wp_register_style('cmsg-trailer-style', CMSG_URL . 'assets/css/trailer-ui.css', [], CMSG_VERSION);
        wp_register_script('cmsg-trailer-ui', CMSG_URL . 'assets/js/trailer-ui.js', ['jquery', 'cmsg-shared-ui'], CMSG_VERSION, true);
        $s = self::settings();
        $cmsg_data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cmsg_nonce'),
            'pricing' => [
                'subtitlePerMinute' => (float)$s['subtitle_price_per_minute'],
                'posterBase' => (float)$s['poster_base_price'],
                'trailerBase' => (float)$s['trailer_base_price'],
                'consultingBase' => (float)$s['consulting_base_price'],
                'trailerRequestBase' => (float)$s['trailer_request_base_price'],
                'currency' => $s['currency_symbol'],
            ],
            'smallUploadLimitGB' => (float)$s['small_upload_limit_gb'],
            'largeFileDirectory' => $s['large_file_directory'],
            'paypal' => [
                'enabled' => $s['paypal_enabled'] === '1',
                'clientId' => $s['paypal_client_id'],
                'currency' => $s['paypal_currency'],
                'mode' => $s['paypal_mode'],
            ],
        ];
        foreach (['cmsg-ui', 'cmsg-shared-ui', 'cmsg-poster-ui', 'cmsg-trailer-ui'] as $handle) {
            wp_localize_script($handle, 'cmsgData', $cmsg_data);
        }
        if ($s['paypal_enabled'] === '1' && !empty($s['paypal_client_id'])) {
            $sdk = 'https://www.paypal.com/sdk/js?client-id=' . rawurlencode($s['paypal_client_id']) . '&currency=' . rawurlencode($s['paypal_currency']) . '&intent=capture';
            wp_enqueue_script('paypal-sdk', $sdk, [], null, true);
        }
    }

    public static function render_upload_ui() {
        wp_enqueue_style('cmsg-style');
        wp_enqueue_script('cmsg-ui');
        $settings = self::settings();
        ob_start();
        include CMSG_DIR . 'templates/upload-form.php';
        return ob_get_clean();
    }

    public static function render_service_hub() {
        wp_enqueue_style('cmsg-style');
        wp_enqueue_script('cmsg-ui');
        $s = self::settings();
        if ($s['enable_service_orders'] !== '1') return '<p>Service ordering is currently disabled.</p>';
        ob_start(); ?>
        <section id="cmsg-service-hub" class="cmsg-shell" style="--cmsg-accent: <?php echo esc_attr($s['accent_color']); ?>;">
            <div class="cmsg-service-head"><span class="cmsg-kicker">Crossmarket Films Services</span><h2>Request production and distribution support</h2><p>Bundle subtitle creation, poster design, trailer editing, metadata prep, and consulting in one workflow.</p></div>
            <div class="cmsg-services">
                <article class="cmsg-service-card"><h3>Subtitle / closed captions</h3><p>AI-assisted .SRT generation for feature films, shorts, and trailers.</p><strong><?php echo esc_html(CMSG_Admin_Settings::money($s['subtitle_price_per_minute'])); ?>/minute</strong></article>
                <article class="cmsg-service-card"><h3>Poster creation</h3><p>Streaming-ready key art, thumbnails, and ad assets.</p><strong>From <?php echo esc_html(CMSG_Admin_Settings::money($s['poster_base_price'])); ?></strong></article>
                <article class="cmsg-service-card"><h3>Trailer creation</h3><p>Hook-first trailers cut for platforms, pitches, and social ads.</p><strong>From <?php echo esc_html(CMSG_Admin_Settings::money($s['trailer_base_price'])); ?></strong></article>
                <article class="cmsg-service-card"><h3>Consulting</h3><p>Distribution, packaging, AVOD strategy, and launch planning.</p><strong>From <?php echo esc_html(CMSG_Admin_Settings::money($s['consulting_base_price'])); ?></strong></article>
            </div>
            <div class="cmsg-card"><h3>Request services</h3><form id="cmsg-service-form" enctype="multipart/form-data"><div class="cmsg-grid">
                <label><span>Name</span><input type="text" name="requester_name" required></label>
                <label><span>Email</span><input type="email" name="requester_email" required></label>
                <label><span>Company</span><input type="text" name="company_name"></label>
                <label><span>Service needed</span><select name="service_type" id="cmsg-service-type" required><option value="">Select service</option><option value="subtitle_creation">Subtitle creation</option><option value="poster_creation">Poster creation</option><option value="trailer_creation">Trailer creation</option><option value="consulting">Consulting</option><option value="metadata_optimization">Metadata optimization</option></select></label>
                <label><span>Budget range</span><select name="budget_range"><option value="">Select budget</option><option value="under_250">Under $250</option><option value="250_500">$250 - $500</option><option value="500_1000">$500 - $1,000</option><option value="1000_plus">$1,000+</option></select></label>
                <label><span>Project stage</span><select name="project_stage"><option value="">Select stage</option><option value="development">Development</option><option value="post_production">Post-production</option><option value="completed">Completed</option><option value="re_release">Re-release / repackaging</option></select></label>
                <label class="cmsg-file"><span>Reference file (optional)</span><input type="file" name="service_attachment"></label>
                <label class="cmsg-file"><span>Project details</span><textarea name="details" rows="5"></textarea></label>
            </div>
            <div class="cmsg-pricing-inline">Estimated service starting price: <strong id="cmsg-service-estimate"><?php echo esc_html(CMSG_Admin_Settings::money(0)); ?></strong></div>
            <?php if ($s['paypal_enabled'] === '1') : ?><div class="cmsg-paywall"><div class="cmsg-paywall__copy"><span class="cmsg-kicker">Payment</span><h4>Complete payment with PayPal</h4><p>Service requests use the same draft → authorization → finalize flow.</p></div><div id="cmsg-paypal-service" class="cmsg-paypal-buttons"></div></div><?php endif; ?>
            <div class="cmsg-actions"><button type="submit" class="cmsg-btn cmsg-btn--primary">Create Service Order</button></div></form><div id="cmsg-service-status" class="cmsg-status"></div></div>
        </section>
        <?php return ob_get_clean();
    }
    public static function render_media_tools_dashboard() {
        wp_enqueue_style('cmsg-style');
        wp_enqueue_style('cmsg-dashboard-style');
        wp_enqueue_style('cmsg-poster-style');
        wp_enqueue_style('cmsg-trailer-style');
        wp_enqueue_script('cmsg-ui');
        wp_enqueue_script('cmsg-shared-ui');
        wp_enqueue_script('cmsg-poster-ui');
        wp_enqueue_script('cmsg-trailer-ui');
        $settings = self::settings();
        ob_start();
        include CMSG_DIR . 'templates/media-tools-dashboard.php';
        return ob_get_clean();
    }

    public static function render_poster_studio() {
        wp_enqueue_style('cmsg-style');
        wp_enqueue_style('cmsg-poster-style');
        wp_enqueue_script('cmsg-shared-ui');
        wp_enqueue_script('cmsg-poster-ui');
        $settings = self::settings();
        ob_start();
        include CMSG_DIR . 'templates/poster-form.php';
        return ob_get_clean();
    }

    public static function render_trailer_studio() {
        wp_enqueue_style('cmsg-style');
        wp_enqueue_style('cmsg-trailer-style');
        wp_enqueue_script('cmsg-shared-ui');
        wp_enqueue_script('cmsg-trailer-ui');
        $settings = self::settings();
        ob_start();
        include CMSG_DIR . 'templates/trailer-form.php';
        return ob_get_clean();
    }
}
register_activation_hook(__FILE__, ['CMSG_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['CMSG_Plugin', 'deactivate']);
CMSG_Plugin::init();
