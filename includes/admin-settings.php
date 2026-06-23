<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_Admin_Settings {
    public static function register_menu() {
        add_menu_page('Crossmarket Media Ops', 'Media Ops', 'manage_options', 'cmsg-jobs', [__CLASS__, 'render_jobs_page'], 'dashicons-format-video', 58);
        add_submenu_page('cmsg-jobs', 'Subtitle Jobs', 'Subtitle Jobs', 'manage_options', 'cmsg-jobs', [__CLASS__, 'render_jobs_page']);
        add_submenu_page('cmsg-jobs', 'Service Requests', 'Service Requests', 'manage_options', 'cmsg-service-requests', [__CLASS__, 'render_service_requests_page']);
        add_submenu_page('cmsg-jobs', 'Poster Jobs', 'Poster Jobs', 'manage_options', 'cmsg-poster-jobs', [__CLASS__, 'render_poster_jobs_page']);
        add_submenu_page('cmsg-jobs', 'Trailer Jobs', 'Trailer Jobs', 'manage_options', 'cmsg-trailer-jobs', [__CLASS__, 'render_trailer_jobs_page']);
        add_submenu_page('cmsg-jobs', 'Settings', 'Settings', 'manage_options', 'cmsg-settings', [__CLASS__, 'render_settings_page']);
    }
    public static function register_settings() {
        register_setting('cmsg_settings_group', CMSG_Plugin::OPTION_KEY, [__CLASS__, 'sanitize_settings']);
        add_settings_section('cmsg_general', 'General Settings', '__return_false', 'cmsg-settings');
        self::field('brand_name', 'Brand name');
        self::field('support_email', 'Support email');
        self::field('from_email', 'From email');
        self::field('currency_symbol', 'Currency symbol');
        self::field('accent_color', 'Accent color');
        self::field('small_upload_limit_gb', 'Small upload limit (GB)', 'text', 'Browser upload is for files under this limit. Larger files should use the configured server-side directory workflow.');
        self::field('large_file_directory', 'Large file directory', 'text', 'Large files must already exist in this server directory before they can be selected in the UI.');
        self::field('payment_authorization_expiry_minutes', 'Payment authorization expiry (minutes)');
        self::field('download_grant_expiry_minutes', 'Download grant expiry (minutes)');
        self::field('one_time_download_grants', 'One-time download grants', 'checkbox');
        self::field('hero_heading', 'Hero heading');
        self::field('hero_subheading', 'Hero subheading', 'textarea');
        self::field('enable_secure_gcs_upload', 'Enable secure Google Cloud upload', 'checkbox');
        self::field('gcs_bucket_name', 'GCS bucket name');
        self::field('gcs_project_id', 'GCS project ID');
        self::field('gcs_service_account_json', 'GCS service account JSON path', 'text', 'Absolute path on server to a Google service account JSON file used for secure upload and import operations.');
        self::field('gcs_signed_upload_expiry_seconds', 'GCS signed upload expiry seconds');
        self::field('gcs_signer_url', 'GCS signer service URL');
        self::field('gcs_signer_shared_secret', 'GCS signer shared secret');
        self::field('gcs_signed_upload_expiry_seconds', 'GCS signed upload expiry seconds');
        self::field('gcs_subtitle_upload_prefix', 'GCS subtitle upload prefix');
        self::field('gcs_subtitle_import_prefix', 'GCS subtitle import prefix');
        self::field('gcs_subtitle_output_prefix', 'GCS subtitle output prefix');
        self::field('enable_google_drive_import', 'Enable Google Drive import', 'checkbox');
        self::field('google_drive_link_import_only', 'Drive import via share link only', 'checkbox');
        self::field('enable_poster_studio', 'Enable poster studio', 'checkbox');
        self::field('poster_base_price', 'Poster base price');
        self::field('poster_preview_count', 'Poster preview count');
        self::field('poster_preview_watermark_text', 'Poster preview watermark text');
        self::field('openai_api_key', 'OpenAI API key');
        self::field('enable_trailer_studio', 'Enable trailer studio', 'checkbox');
        self::field('trailer_request_base_price', 'Trailer request base price');
        add_settings_section('cmsg_pricing', 'Pricing', '__return_false', 'cmsg-settings');
        self::field('subtitle_price_per_minute', 'Subtitle price per minute');
        self::field('poster_base_price', 'Poster creation base price');
        self::field('trailer_base_price', 'Trailer creation base price');
        self::field('consulting_base_price', 'Consulting base price');
        self::field('enable_service_orders', 'Enable service orders', 'checkbox');
        self::field('enable_pricing', 'Show front-end pricing', 'checkbox');
        add_settings_section('cmsg_engine', 'Processing Engine', '__return_false', 'cmsg-settings');
        self::field('python_binary', 'Python binary');
        self::field('ffmpeg_binary', 'FFmpeg binary');
        self::field('whisper_mode', 'Whisper engine', 'select', '', ['faster-whisper'=>'faster-whisper','openai-whisper'=>'openai-whisper']);
        self::field('default_model', 'Default model', 'select', '', ['tiny'=>'tiny','base'=>'base','small'=>'small','medium'=>'medium']);
        add_settings_section('cmsg_checkout', 'Checkout & Redirects', '__return_false', 'cmsg-settings');
        self::field('thank_you_url', 'Thank-you / redirect URL');
        self::field('paypal_enabled', 'Enable PayPal checkout', 'checkbox');
        self::field('paypal_mode', 'PayPal mode', 'select', '', ['live'=>'live','sandbox'=>'sandbox']);
        self::field('paypal_currency', 'PayPal currency');
        self::field('paypal_client_id', 'PayPal client ID');
        self::field('paypal_client_secret', 'PayPal client secret');
    }
    private static function field($key, $label, $type='text', $help='', $options=[]) {
        add_settings_field($key, $label, [__CLASS__, 'render_field'], 'cmsg-settings', strpos($key, 'paypal_')===0 || $key==='thank_you_url' ? 'cmsg_checkout' : (in_array($key, ['subtitle_price_per_minute','poster_base_price','trailer_base_price','consulting_base_price','enable_service_orders','enable_pricing']) ? 'cmsg_pricing' : (in_array($key, ['python_binary','ffmpeg_binary','whisper_mode','default_model']) ? 'cmsg_engine' : 'cmsg_general')), compact('key','type','help','options'));
    }
    public static function render_field($args) {
        $s=CMSG_Plugin::settings(); $key=$args['key']; $type=$args['type']; $help=$args['help']; $options=$args['options']; $name=CMSG_Plugin::OPTION_KEY.'['.$key.']';
        if ($type==='textarea') echo '<textarea class="large-text" rows="4" name="'.esc_attr($name).'">'.esc_textarea($s[$key]).'</textarea>';
        elseif ($type==='checkbox') echo '<label><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($s[$key],'1',false).'> Enabled</label>';
        elseif ($type==='select') { echo '<select name="'.esc_attr($name).'">'; foreach($options as $value=>$label){ echo '<option value="'.esc_attr($value).'" '.selected($s[$key],$value,false).'>'.esc_html($label).'</option>'; } echo '</select>'; }
        else echo '<input type="text" class="regular-text" name="'.esc_attr($name).'" value="'.esc_attr($s[$key]).'">';
        if ($help) echo '<p class="description">'.esc_html($help).'</p>';
    }
    public static function sanitize_settings($input) {
        $defaults=CMSG_Plugin::defaults(); $clean=[];
        foreach($defaults as $key=>$default){
            if (in_array($key,['enable_service_orders','enable_pricing','paypal_enabled','one_time_download_grants'],true)) $clean[$key]=!empty($input[$key])?'1':'0';
            elseif ($key==='accent_color') $clean[$key]=sanitize_hex_color($input[$key] ?? $default) ?: $default;
            elseif (in_array($key,['small_upload_limit_gb','payment_authorization_expiry_minutes','download_grant_expiry_minutes'],true)) { $value=floatval($input[$key] ?? $default); $clean[$key]=$value>0?(string)$value:$default; }
            elseif ($key==='large_file_directory') { $value=trim((string)($input[$key] ?? $default)); $clean[$key]=$value!==''?untrailingslashit($value):$default; }
            else $clean[$key]=sanitize_text_field($input[$key] ?? $default);
        }
        return $clean;
    }
    public static function render_settings_page(){ if(!current_user_can('manage_options')) return; echo '<div class="wrap"><h1>Crossmarket Subtitle Generator Pro</h1><form method="post" action="options.php">'; settings_fields('cmsg_settings_group'); do_settings_sections('cmsg-settings'); submit_button('Save settings'); echo '</form></div>'; }
    private static function maybe_admin_reset_subtitle_job() {
        if (!current_user_can('manage_options')) return;
        if (empty($_GET['cmsg_admin_action']) || $_GET['cmsg_admin_action'] !== 'reset_subtitle_job') return;

        $job_id = (int)($_GET['job_id'] ?? 0);

        if (!$job_id || !check_admin_referer('cmsg_admin_reset_subtitle_job_' . $job_id)) {
            return;
        }

        $job = CMSG_Jobs::get_job($job_id);

        if (!$job || $job->payment_status !== 'paid' || empty($job->payment_authorization_id)) {
            add_settings_error('cmsg_jobs', 'cmsg_reset_unavailable', 'This subtitle job cannot be reset because paid authorization was not found.', 'error');
            return;
        }

        CMSG_Jobs::update_job($job_id, [
            'status' => 'queued',
            'srt_path' => '',
            'vtt_path' => '',
            'log_text' => '[10%] Administrator reset requested. Reusing the existing paid authorization with no repayment required.',
        ]);

        wp_schedule_single_event(time() + 5, 'cmsg_process_job', [$job_id]);
        add_settings_error('cmsg_jobs', 'cmsg_reset_started', 'Subtitle job reset and queued without requiring repayment.', 'updated');
    }

    public static function render_jobs_page(){
        if(!current_user_can('manage_options')) return;

        self::maybe_admin_reset_subtitle_job();

        global $wpdb;
        $table=$wpdb->prefix.'cmsg_jobs';
        $jobs=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100");

        echo '<div class="wrap"><h1>Subtitle Jobs</h1>';
        settings_errors('cmsg_jobs');
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Status</th><th>Source</th><th>Video</th><th>Email</th><th>Language</th><th>Minutes</th><th>Price</th><th>Payment</th><th>Created</th><th>SRT</th><th>Actions</th></tr></thead><tbody>';

        if($jobs) foreach($jobs as $job){
            $download='';
            if(!empty($job->srt_path) && $job->payment_status==='paid' && $job->status==='completed') $download='Protected';

            $actions = '';
            if ($job->payment_status === 'paid' && !empty($job->payment_authorization_id) && in_array($job->status, ['retry_available', 'failed'], true)) {
                $reset_url = wp_nonce_url(
                    add_query_arg([
                        'page' => 'cmsg-jobs',
                        'cmsg_admin_action' => 'reset_subtitle_job',
                        'job_id' => (int)$job->id,
                    ], admin_url('admin.php')),
                    'cmsg_admin_reset_subtitle_job_' . (int)$job->id
                );
                $actions = '<a class="button button-small" href="' . esc_url($reset_url) . '">Reset / Retry without repayment</a>';
            }

            echo '<tr><td>'.intval($job->id).'</td><td>'.esc_html($job->status).'</td><td>'.esc_html($job->source_type ?: 'browser_upload').'</td><td>'.esc_html($job->original_filename).'</td><td>'.esc_html($job->requester_email).'</td><td>'.esc_html($job->language_code).'</td><td>'.esc_html($job->minutes_estimate).'</td><td>'.esc_html(self::money($job->estimated_price)).'</td><td>'.esc_html($job->payment_status).'</td><td>'.esc_html($job->created_at).'</td><td>'.$download.'</td><td>'.$actions.'</td></tr>';
        } else {
            echo '<tr><td colspan="12">No jobs yet.</td></tr>';
        }

        echo '</tbody></table></div>';
    }
    public static function render_service_requests_page(){ if(!current_user_can('manage_options')) return; global $wpdb; $table=$wpdb->prefix.'cmsg_service_requests'; $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100"); echo '<div class="wrap"><h1>Service Requests</h1><table class="widefat striped"><thead><tr><th>ID</th><th>Status</th><th>Service</th><th>Name</th><th>Email</th><th>Budget</th><th>Price</th><th>Payment</th><th>Created</th></tr></thead><tbody>'; if($rows) foreach($rows as $r){ echo '<tr><td>'.intval($r->id).'</td><td>'.esc_html($r->status).'</td><td>'.esc_html($r->service_type).'</td><td>'.esc_html($r->requester_name).'</td><td>'.esc_html($r->requester_email).'</td><td>'.esc_html($r->budget_range).'</td><td>'.esc_html(self::money($r->estimated_price)).'</td><td>'.esc_html($r->payment_status).'</td><td>'.esc_html($r->created_at).'</td></tr>'; } else echo '<tr><td colspan="9">No service requests yet.</td></tr>'; echo '</tbody></table></div>'; }
    public static function money($amount){ $s=CMSG_Plugin::settings(); return $s['currency_symbol'].number_format((float)$amount,2); }
}
