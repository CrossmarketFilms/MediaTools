<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_Ajax {

private static function subtitle_amount($runtime_minutes) {
    return round((float) $runtime_minutes * (float) CMSG_Plugin::settings()['subtitle_price_per_minute'], 2);
}

private static function subtitle_progress_from_log($log_text, $status = '') {
    $log_text = trim((string)$log_text);
    $progress = 0;
    $message = $log_text;

    if (preg_match('/^\[(\d{1,3})%\]\s*(.*)$/s', $log_text, $matches)) {
        $progress = max(0, min(100, (int)$matches[1]));
        $message = trim($matches[2]);
    } elseif ($status === 'completed') {
        $progress = 100;
    } elseif ($status === 'retry_available') {
        $progress = 95;
    } elseif ($status === 'queued') {
        $progress = 10;
    } elseif ($status === 'processing') {
        $progress = 45;
    }

    if (
        $status === 'retry_available'
        && strpos($log_text, 'The selected spoken language is not directly supported.') !== 0
        && strpos($log_text, CMSG_Processor::speech_timeout_retry_message()) === false
    ) {
        $message = CMSG_Processor::retry_available_message();
    }

    if ($message === '') {
        $message = $status === 'completed'
            ? 'Subtitle files are ready.'
            : 'Processing subtitle job...';
    }

    return [
        'progress' => $progress,
        'message' => $message,
    ];
}

private static function normalize_subtitle_language($language, $fallback = 'auto') {
    $language = strtolower(trim((string)$language));
    $language = preg_replace('/[^a-z_-]/', '', $language);

    return $language !== '' ? $language : $fallback;
}

private static function infer_translation_mode($source_language, $output_language, $posted_mode = '') {
    $posted_mode = sanitize_text_field($posted_mode);

    if (in_array($posted_mode, ['translate_to_english', 'custom_output'], true)) {
        return $posted_mode;
    }

    if ($output_language === '' || $output_language === 'same' || $output_language === $source_language) {
        return 'none';
    }

    return 'custom_output';
}

public static function create_draft() {
    check_ajax_referer('cmsg_nonce', 'nonce');

    $source_type  = sanitize_text_field(wp_unslash($_POST['source_type'] ?? 'browser_upload'));
    $email        = sanitize_email(wp_unslash($_POST['request_email'] ?? ''));
    $runtime      = floatval(wp_unslash($_POST['runtime_minutes'] ?? 0));
    $legacy_language = self::normalize_subtitle_language(wp_unslash($_POST['language_code'] ?? 'auto'));
    $source_language = self::normalize_subtitle_language(wp_unslash($_POST['source_language'] ?? $legacy_language));
    $output_language = self::normalize_subtitle_language(wp_unslash($_POST['output_language'] ?? 'same'), 'same');
    $translation_mode = self::infer_translation_mode(
        $source_language,
        $output_language,
        wp_unslash($_POST['translation_mode'] ?? '')
    );
    $language = $source_language;

    $model        = sanitize_text_field(wp_unslash($_POST['model_size'] ?? CMSG_Plugin::settings()['default_model']));
    $caption_mode = sanitize_text_field(wp_unslash($_POST['caption_mode'] ?? 'subtitle'));

    if (!in_array($caption_mode, ['subtitle', 'closed_caption'], true)) {
        $caption_mode = 'subtitle';
    }

    $file_reference = '';
    $file_hash = '';
    $original_filename = '';

if ($source_type === 'server_file') {

        $validated = CMSG_Validation::validate_server_file(wp_unslash($_POST['server_file'] ?? ''));

        if (is_wp_error($validated)) {
            wp_send_json_error(['message' => $validated->get_error_message()], 400);
        }

        $file_reference = $validated;
        $file_hash = hash_file('sha256', $validated);
        $original_filename = basename($validated);

        $server_runtime = CMSG_Processor::detect_runtime_minutes_from_file($validated);

        if ($server_runtime > 0) {
            $runtime = $server_runtime;
        }

    } elseif ($source_type === 'google_drive') {

        $drive_link = esc_url_raw(wp_unslash($_POST['drive_link'] ?? ($_POST['drive_url'] ?? '')));

        if (empty($drive_link)) {
            wp_send_json_error(['message' => 'Google Drive link is required.'], 400);
        }

        if ($runtime > 120) {
            wp_send_json_error([
                'message' => 'Google Drive import is limited to short/medium files. For videos over 120 minutes or large files over 2GB, please use the Large File / Google Cloud upload option.'
            ], 400);
        }

        $file_reference = $drive_link;
        $file_hash = hash('sha256', $drive_link);
        $original_filename = sanitize_file_name(
            wp_unslash($_POST['original_filename'] ?? 'google-drive-video.mp4')
        );

    } else {

        if (empty($_POST['original_filename'])) {
            wp_send_json_error(['message' => 'File metadata is required to create a draft.'], 400);
        }

        $original_filename = sanitize_file_name(wp_unslash($_POST['original_filename']));
        $file_reference = 'browser_pending:' . $original_filename;
        $file_hash = hash('sha256', $original_filename . '|' . (string) ($_POST['file_size'] ?? ''));
    }

    if (!$email || $runtime <= 0) {
        wp_send_json_error(['message' => 'Email and runtime are required.'], 400);
    }

    $currency = CMSG_Plugin::settings()['paypal_currency'];
    $amount = self::subtitle_amount($runtime);

    $draft_id = CMSG_Drafts::create([
        'request_email'     => $email,
        'source_type'       => $source_type,
        'file_reference'    => $file_reference,
        'file_hash'         => $file_hash,
        'original_filename' => $original_filename,
        'runtime_minutes'   => $runtime,
 
        'language_code'     => $language,
        'source_language'   => $source_language,
        'output_language'   => $output_language,
        'translation_mode'  => $translation_mode,
        'model_size'        => $model,
        'caption_mode'      => $caption_mode,

        'amount'            => $amount,
        'currency'          => $currency,
    ]);

    wp_send_json_success([
        'draft_id' => $draft_id,
        'amount'   => $amount,
        'currency' => $currency,
        'runtime_minutes' => $runtime
    ]);
}
public static function create_paypal_order() {
    check_ajax_referer('cmsg_nonce', 'nonce');

    $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));

    if (!$draft || $draft->status !== 'draft') {
        wp_send_json_error(['message' => 'Draft not found or no longer payable.'], 404);
    }

    $order = CMSG_PayPal::create_order_for_draft($draft);

    if (is_wp_error($order)) {
        wp_send_json_error(['message' => $order->get_error_message()], 400);
    }

$order_id = is_array($order)
    ? ($order['orderID'] ?? $order['id'] ?? '')
    : $order;

         if (empty($order_id)) {
    wp_send_json_error(['message' => 'PayPal order ID missing.'], 500);
}

wp_send_json_success([
    'orderID' => $order_id
]);

}
    public static function capture_paypal_order() {
        check_ajax_referer('cmsg_nonce', 'nonce'); $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        $kind = sanitize_text_field(wp_unslash($_POST['kind'] ?? 'subtitle'));
        if (!$draft || $draft->status !== 'draft') wp_send_json_error(['message'=>'Draft not found or no longer payable.'],404);
        $auth = CMSG_PayPal::capture_and_verify_order($draft, sanitize_text_field(wp_unslash($_POST['order_id'] ?? '')), $kind);
        if (is_wp_error($auth)) wp_send_json_error(['message'=>$auth->get_error_message()],400);
        wp_send_json_success(['payment_token'=>$auth['token'],'expires_at'=>$auth['expires_at']]);
    }
    public static function finalize_paid_draft() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        if (!$draft || !in_array($draft->status, ['authorized'], true)) wp_send_json_error(['message'=>'Draft is not authorized for finalization.'],403);
        $auth = CMSG_Payments::validate_authorization(wp_unslash($_POST['payment_token'] ?? ''), $draft); if (is_wp_error($auth)) wp_send_json_error(['message'=>$auth->get_error_message()],403);

        if ($draft->source_type === 'browser_upload') {
            if (empty($_FILES['video_file'])) wp_send_json_error(['message'=>'No video file uploaded.'],400);
            $valid = CMSG_Validation::validate_browser_file($_FILES['video_file']); if (is_wp_error($valid)) wp_send_json_error(['message'=>$valid->get_error_message()],400);
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $uploaded = wp_handle_upload($_FILES['video_file'], ['test_form'=>false]); if (!empty($uploaded['error'])) wp_send_json_error(['message'=>$uploaded['error']],400);
            global $wpdb; $wpdb->update(CMSG_Drafts::table(), ['file_reference'=>$uploaded['file'],'original_filename'=>sanitize_file_name($_FILES['video_file']['name']),'updated_at'=>current_time('mysql')], ['id'=>$draft->id]);
            $draft = CMSG_Drafts::get($draft->id);
        }
        $consumed = CMSG_Payments::consume_authorization((int)$auth->id);
        if (!$consumed) wp_send_json_error(['message'=>'This payment authorization has already been used.'],409);
        $draft_consumed = CMSG_Drafts::consume($draft->id);
        if (!$draft_consumed) wp_send_json_error(['message'=>'This draft has already been finalized or expired.'],409);
        $job_id = CMSG_Jobs::create_job_from_authorized_draft($draft->id, (int)$auth->id);
        if (is_wp_error($job_id)) wp_send_json_error(['message'=>$job_id->get_error_message()],400);
        wp_send_json_success(['job_id'=>$job_id,'message'=>'Payment confirmed. Your subtitle job has been created and queued.']);
    }
    public static function list_server_files() { check_ajax_referer('cmsg_nonce', 'nonce'); wp_send_json_success(['files'=>CMSG_File_Browser::list_files()]); }
    public static function job_status() {
        check_ajax_referer('cmsg_nonce', 'nonce'); $job=CMSG_Jobs::get_job((int)($_POST['job_id'] ?? 0)); if(!$job) wp_send_json_error(['message'=>'Job not found.'],404);
        $progress = self::subtitle_progress_from_log($job->log_text, $job->status);
        wp_send_json_success([
            'status'=>$job->status,
            'message'=>$progress['message'],
            'progress'=>$progress['progress'],
            'raw_message'=>$job->log_text,
            'caption_mode'=> isset($job->caption_mode) ? $job->caption_mode : 'subtitle',
            'can_download'=>($job->payment_status==='paid' && $job->status==='completed'),
            'can_retry'=>($job->payment_status==='paid' && $job->status==='retry_available')
        ]);
    }
    public static function retry_subtitle_job() {
        check_ajax_referer('cmsg_nonce', 'nonce');

        $job = CMSG_Jobs::get_job((int)($_POST['job_id'] ?? 0));

        if (!$job) {
            wp_send_json_error(['message' => 'Job not found.'], 404);
        }

        if ($job->status !== 'retry_available') {
            wp_send_json_error(['message' => 'This job is not currently available for retry.'], 409);
        }

        if ($job->payment_status !== 'paid' || empty($job->payment_authorization_id)) {
            wp_send_json_error(['message' => 'Retry is only available for paid subtitle jobs.'], 403);
        }

        $auth = CMSG_Payments::get_by_id((int)$job->payment_authorization_id);

        if (!$auth || !in_array($auth->status, ['used', 'active'], true)) {
            wp_send_json_error(['message' => 'Payment authorization could not be confirmed for retry.'], 403);
        }

        CMSG_Jobs::update_job((int)$job->id, [
            'status' => 'queued',
            'srt_path' => '',
            'vtt_path' => '',
            'log_text' => '[10%] Retry requested. Reusing the existing paid authorization and queued media file.',
        ]);

        wp_schedule_single_event(time() + 5, 'cmsg_process_job', [(int)$job->id]);

        wp_send_json_success([
            'job_id' => (int)$job->id,
            'message' => 'Retry started. Your existing payment and upload are being reused.',
        ]);
    }
    public static function detect_server_file_runtime() {
        check_ajax_referer('cmsg_nonce', 'nonce');

        $path = wp_unslash($_POST['server_file'] ?? '');
        $validated = CMSG_Validation::validate_server_file($path);
        if (is_wp_error($validated)) {
            wp_send_json_error(['message' => $validated->get_error_message()], 400);
        }

        $ffmpeg = CMSG_Plugin::settings()['ffmpeg_binary'];
        $ffprobe = preg_replace('/ffmpeg$/', 'ffprobe', $ffmpeg);
        if (!$ffprobe || !file_exists($ffprobe)) {
            $ffprobe = 'ffprobe';
        }

        $command = escapeshellcmd($ffprobe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($validated) . ' 2>&1';
        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);

        if ($return_var !== 0 || empty($output[0])) {
            wp_send_json_error(['message' => 'Unable to detect runtime for the selected server file.'], 500);
        }

        $seconds = floatval(trim($output[0]));
        if ($seconds <= 0) {
            wp_send_json_error(['message' => 'The selected file returned an invalid runtime.'], 500);
        }

        wp_send_json_success([
            'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60),
        ]);
    }

    public static function issue_download_grant() {
        check_ajax_referer('cmsg_nonce', 'nonce'); $job=CMSG_Jobs::get_job((int)($_POST['job_id'] ?? 0));
if (!$job || $job->payment_status !== 'paid' || $job->status !== 'completed' || empty($job->srt_path) || !file_exists($job->srt_path)) {
    wp_send_json_error(['message' => 'Download is not available.'], 403);
}

$grant = CMSG_Downloads::issue_grant($job);

$srt_url = add_query_arg([
    'action' => 'cmsg_download',
    'job_id' => $job->id,
    'file_type' => 'srt',
    'grant_token' => $grant['token']
], admin_url('admin-ajax.php'));

$vtt_url = '';

if (!empty($job->vtt_path) && file_exists($job->vtt_path)) {
    $vtt_url = add_query_arg([
        'action' => 'cmsg_download',
        'job_id' => $job->id,
        'file_type' => 'vtt',
        'grant_token' => $grant['token']
    ], admin_url('admin-ajax.php'));
}
$srt_direct_url = site_url('/wp-content/uploads/cmsg-gcs-cache/' . basename($job->srt_path));

$vtt_direct_url = '';
if (!empty($job->vtt_path) && file_exists($job->vtt_path)) {
    $vtt_direct_url = site_url('/wp-content/uploads/cmsg-gcs-cache/' . basename($job->vtt_path));
}

wp_send_json_success([
    'download_url' => $srt_direct_url,
    'srt_download_url' => $srt_direct_url,
    'vtt_download_url' => $vtt_direct_url,
    'protected_srt_download_url' => $srt_url,
    'protected_vtt_download_url' => $vtt_url,
    'expires_at' => $grant['expires_at']
]);

    }
    public static function download_srt() {
$job = CMSG_Jobs::get_job((int)($_GET['job_id'] ?? 0));
if (!$job) {
    wp_die('Download not found.', 404);
}

$file_type = sanitize_text_field(wp_unslash($_GET['file_type'] ?? 'srt'));
$file_type = in_array($file_type, ['srt', 'vtt'], true) ? $file_type : 'srt';

$file_path = $job->srt_path;
$content_type = 'application/x-subrip';

if ($file_type === 'vtt') {
    $file_path = !empty($job->vtt_path) ? $job->vtt_path : '';
    $content_type = 'text/vtt';
}

if ($job->payment_status !== 'paid' || $job->status !== 'completed' || empty($file_path) || !file_exists($file_path)) {
    wp_die('Download not available.', 403);
}

$grant = CMSG_Downloads::validate_grant($job, sanitize_text_field(wp_unslash($_GET['grant_token'] ?? '')));
if (is_wp_error($grant)) {
    wp_die($grant->get_error_message(), 403);
}

if (CMSG_Plugin::settings()['one_time_download_grants'] === '1') {
    CMSG_Downloads::consume_grant((int)$grant->id);
}

nocache_headers();
header('Content-Type: ' . $content_type);
header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
header('Content-Length: ' . filesize($file_path));
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

readfile($file_path);
exit;
    }
    public static function submit_service_request() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $name=sanitize_text_field(wp_unslash($_POST['requester_name'] ?? '')); $email=sanitize_email(wp_unslash($_POST['requester_email'] ?? '')); $service=sanitize_text_field(wp_unslash($_POST['service_type'] ?? ''));
        if(!$name || !$email || !$service) wp_send_json_error(['message'=>'Name, email, and service type are required.'],400);
        $price = 0; $s=CMSG_Plugin::settings();
        $service_prices=['poster_creation'=>(float)$s['poster_base_price'],'trailer_creation'=>(float)$s['trailer_base_price'],'consulting'=>(float)$s['consulting_base_price'],'subtitle_creation'=>(float)$s['subtitle_price_per_minute']*90,'metadata_optimization'=>max(75,(float)$s['consulting_base_price']/2)]; $price = $service_prices[$service] ?? 0;
        global $wpdb; $now=current_time('mysql');
        $wpdb->insert($wpdb->prefix.'cmsg_service_requests',['created_at'=>$now,'updated_at'=>$now,'requester_name'=>$name,'requester_email'=>$email,'company_name'=>sanitize_text_field(wp_unslash($_POST['company_name'] ?? '')),'service_type'=>$service,'budget_range'=>sanitize_text_field(wp_unslash($_POST['budget_range'] ?? '')),'project_stage'=>sanitize_text_field(wp_unslash($_POST['project_stage'] ?? '')),'details'=>sanitize_textarea_field(wp_unslash($_POST['details'] ?? '')),'payment_status'=>'pending','estimated_price'=>$price,'status'=>'new']);
        wp_send_json_success(['request_id'=>(int)$wpdb->insert_id,'message'=>'Service request recorded.']);
    }

public static function get_gcs_signed_upload() {
    check_ajax_referer('cmsg_nonce', 'nonce');

    $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
    if (!$draft || $draft->status !== 'draft') {
        wp_send_json_error(['message' => 'Draft not found.'], 404);
    }

    $filename = sanitize_file_name(wp_unslash($_POST['filename'] ?? 'upload.bin'));
    $content_type = sanitize_text_field(wp_unslash($_POST['content_type'] ?? 'application/octet-stream'));

    $policy = CMSG_GCS::signed_upload_policy($draft, $filename, $content_type);

    if (is_wp_error($policy)) {
        wp_send_json_error(['message' => $policy->get_error_message()], 400);
    }

    wp_send_json_success($policy);

    }

public static function confirm_gcs_upload() {
    check_ajax_referer('cmsg_nonce', 'nonce');

    $draft = CMSG_Drafts::get((int) ($_POST['draft_id'] ?? 0));

    if (!$draft || $draft->status !== 'draft') {
        wp_send_json_error(['message' => 'Draft not found.'], 404);
    }

    $object_key = sanitize_text_field(wp_unslash($_POST['object_key'] ?? ''));

    $confirmed = CMSG_GCS::confirm_uploaded_object($draft, $object_key);

    if (is_wp_error($confirmed)) {
        wp_send_json_error(['message' => $confirmed->get_error_message()], 400);
    }

    $uploads = wp_upload_dir();
    $cache_dir = trailingslashit($uploads['basedir']) . 'cmsg-gcs-cache';
    $local_path = trailingslashit($cache_dir) . basename($object_key);

if (!file_exists($local_path)) {
    $settings = CMSG_Plugin::settings();
    $bucket = $settings['gcs_bucket_name'] ?? '';

    if (empty($bucket)) {
        wp_send_json_error([
            'message' => 'Missing GCS bucket name. Unable to verify runtime.'
        ], 400);
    }

    if (!is_dir($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }

    putenv('CLOUDSDK_CONFIG=/var/www/.config/gcloud');

    $cmd = 'CLOUDSDK_CONFIG=/var/www/.config/gcloud gcloud storage cp ' .
        escapeshellarg('gs://' . $bucket . '/' . ltrim($object_key, '/')) . ' ' .
        escapeshellarg($local_path) . ' 2>&1';

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);

    if ($code !== 0 || !file_exists($local_path)) {
        wp_send_json_error([
            'message' => 'Uploaded file was confirmed, but could not be downloaded for runtime verification: ' . implode("\n", $output)
        ], 400);
    }

    @chown($local_path, 'www-data');
    @chmod($local_path, 0664);
}

    $runtime = CMSG_Processor::detect_runtime_minutes_from_file($local_path);

    if ($runtime <= 0) {
        wp_send_json_error([
            'message' => 'Unable to verify video runtime. Please retry or use a different file.'
        ], 400);
    }

    $amount = self::subtitle_amount($runtime);

CMSG_Drafts::update_draft($draft->id, [
    'runtime_minutes' => $runtime,
    'amount' => $amount
]);

    wp_send_json_success([
        'message' => 'Cloud upload confirmed. Runtime verified and price recalculated.',
        'runtime_minutes' => $runtime,
        'amount' => $amount
    ]);
}

    public static function validate_drive_link() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $result = CMSG_Google_Drive::validate_link(wp_unslash($_POST['drive_link'] ?? ''));
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
        wp_send_json_success($result);
    }

    public static function import_drive_file() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        if (!$draft || $draft->status !== 'draft') wp_send_json_error(['message' => 'Draft not found.'], 404);
        $result = CMSG_Google_Drive::import_into_draft($draft, wp_unslash($_POST['drive_link'] ?? ''));
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
        wp_send_json_success($result);
    }

    private static function poster_allowed_image_extensions() {
        return ['jpg', 'jpeg', 'png', 'webp'];
    }

    private static function poster_upload_file($file, $label) {
        if (empty($file['name'])) {
            return '';
        }

        if (!empty($file['error']) && (int)$file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('cmsg_poster_upload_error', $label . ' could not be uploaded.');
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::poster_allowed_image_extensions(), true)) {
            return new WP_Error('cmsg_poster_invalid_image_type', $label . ' must be JPG, JPEG, PNG, or WEBP. HEIC, HEIF, SVG, PDF, and other files are not supported.');
        }

        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        $mime = $checked['type'] ?? '';
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];

        if (!in_array($mime, $allowed_mimes, true)) {
            return new WP_Error('cmsg_poster_invalid_image_mime', $label . ' must be a valid JPG, JPEG, PNG, or WEBP image.');
        }

        $uploaded = wp_handle_upload($file, ['test_form' => false]);
        if (!empty($uploaded['error']) || empty($uploaded['file'])) {
            return new WP_Error('cmsg_poster_upload_failed', $label . ' upload failed: ' . ($uploaded['error'] ?? 'Unknown upload error.'));
        }

        return $uploaded['file'];
    }

    private static function save_poster_uploads() {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $saved = [
            'style_reference' => '',
            'poster_assets' => [],
            'cast_actor_1' => '',
            'cast_actor_2' => '',
            'cast_actor_3' => '',
            'cast_members' => [],
        ];

        if (!empty($_FILES['style_reference']['name'])) {
            $uploaded = self::poster_upload_file($_FILES['style_reference'], 'Style reference');
            if (is_wp_error($uploaded)) return $uploaded;
            $saved['style_reference'] = $uploaded;
        }

        foreach (['cast_actor_1', 'cast_actor_2', 'cast_actor_3'] as $cast_key) {
            if (!empty($_FILES[$cast_key]['name'])) {
                $uploaded = self::poster_upload_file($_FILES[$cast_key], 'Actor reference');
                if (is_wp_error($uploaded)) return $uploaded;
                $saved[$cast_key] = $uploaded;
            }
        }

        if (!empty($_FILES['cast_members']['name']) && is_array($_FILES['cast_members']['name'])) {
            foreach ($_FILES['cast_members']['name'] as $i => $fields) {
                $name = is_array($fields) ? ($fields['image'] ?? '') : '';
                if (empty($name)) continue;

                $file = [
                    'name'     => $_FILES['cast_members']['name'][$i]['image'] ?? '',
                    'type'     => $_FILES['cast_members']['type'][$i]['image'] ?? '',
                    'tmp_name' => $_FILES['cast_members']['tmp_name'][$i]['image'] ?? '',
                    'error'    => $_FILES['cast_members']['error'][$i]['image'] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $_FILES['cast_members']['size'][$i]['image'] ?? 0,
                ];

                $uploaded = self::poster_upload_file($file, 'Cast Member ' . ((int)$i + 1) . ' actor reference');
                if (is_wp_error($uploaded)) return $uploaded;
                $saved['cast_members'][(int)$i] = $uploaded;
            }
        }

        if (!empty($_FILES['poster_assets']['name']) && is_array($_FILES['poster_assets']['name'])) {
            foreach ($_FILES['poster_assets']['name'] as $i => $name) {
                if (empty($name)) continue;
                $file = [
                    'name'     => $_FILES['poster_assets']['name'][$i],
                    'type'     => $_FILES['poster_assets']['type'][$i],
                    'tmp_name' => $_FILES['poster_assets']['tmp_name'][$i],
                    'error'    => $_FILES['poster_assets']['error'][$i],
                    'size'     => $_FILES['poster_assets']['size'][$i],
                ];
                $uploaded = self::poster_upload_file($file, 'Poster asset');
                if (is_wp_error($uploaded)) return $uploaded;
                $saved['poster_assets'][] = $uploaded;
            }
        }
        return $saved;
    }

    private static function posted_cast_members($uploads = [], $meta = []) {
        $raw = wp_unslash($_POST['cast_members'] ?? '');
        $posted = [];

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $posted = $decoded;
            }
        }

        if (empty($posted) && !empty($meta['cast_members']) && is_array($meta['cast_members'])) {
            $posted = $meta['cast_members'];
        }

        $members = [];
        $max = 10;

        for ($i = 0; $i < $max; $i++) {
            $row = isset($posted[$i]) && is_array($posted[$i]) ? $posted[$i] : [];
            $role = sanitize_key($row['role'] ?? ($i < 2 ? 'lead' : 'supporting'));
            $role = $role === 'lead' ? 'lead' : 'supporting';
            $meta_member = isset($meta['cast_members'][$i]) && is_array($meta['cast_members'][$i]) ? $meta['cast_members'][$i] : [];
            $image = $uploads['cast_members'][$i] ?? ($row['image'] ?? ($meta_member['image'] ?? ($meta['cast_actor_' . ($i + 1)] ?? '')));
            $image = is_string($image) ? sanitize_text_field($image) : '';

            $member = [
                'name' => sanitize_text_field($row['name'] ?? ''),
                'role' => $role,
                'instruction' => sanitize_text_field($row['instruction'] ?? ''),
                'image' => $image,
            ];

            if ($member['name'] !== '' || $member['instruction'] !== '' || $member['image'] !== '') {
                $members[] = $member;
            }
        }

        if (!empty($members)) {
            return $members;
        }

        for ($i = 1; $i <= 3; $i++) {
            $image = $uploads['cast_actor_' . $i] ?? ($meta['cast_actor_' . $i] ?? '');
            $instruction = sanitize_text_field(wp_unslash($_POST['cast_actor_' . $i . '_instruction'] ?? ($meta['cast_actor_' . $i . '_instruction'] ?? '')));

            if ($image === '' && $instruction === '') continue;

            $members[] = [
                'name' => '',
                'role' => $i <= 2 ? 'lead' : 'supporting',
                'instruction' => $instruction,
                'image' => $image,
            ];
        }

        return $members;
    }

    private static function poster_meta_from_draft($draft) {
        $meta = [];
        if (!empty($draft->source_reference)) {
            $decoded = json_decode((string)$draft->source_reference, true);
            if (is_array($decoded)) $meta = $decoded;
        }
        return $meta;
    }

    private static function poster_scene_direction($meta = []) {
        $scene = sanitize_textarea_field(wp_unslash($_POST['poster_description'] ?? ''));
        if ($scene !== '') return $scene;

        $legacy = sanitize_textarea_field(wp_unslash($_POST['cast_scene_instruction'] ?? ''));
        if ($legacy !== '') return $legacy;

        if (!empty($meta['poster_description'])) {
            return sanitize_textarea_field($meta['poster_description']);
        }

        if (!empty($meta['cast_scene_instruction'])) {
            return sanitize_textarea_field($meta['cast_scene_instruction']);
        }

        return '';
    }

    private static function poster_layout($cast_members = [], $meta = []) {
        $layout = sanitize_key(wp_unslash($_POST['poster_layout'] ?? ($meta['poster_layout'] ?? '')));
        $allowed = [
            'solo_hero',
            'dual_lead',
            'three_character_triangle',
            'ensemble_portrait_grid',
            'floating_heads_ensemble',
            'no_cast_background_only',
        ];

        if (in_array($layout, $allowed, true)) {
            return $layout;
        }

        $count = is_array($cast_members) ? count($cast_members) : 0;
        if ($count > 5) return 'ensemble_portrait_grid';
        if ($count === 0) return 'no_cast_background_only';
        if ($count === 1) return 'solo_hero';
        if ($count === 2) return 'dual_lead';
        if ($count === 3) return 'three_character_triangle';
        return 'floating_heads_ensemble';
    }

    private static function poster_generation_mode($cast_members = [], $meta = []) {
        $mode = sanitize_key(wp_unslash($_POST['poster_generation_mode'] ?? ($meta['poster_generation_mode'] ?? 'auto')));
        $allowed = ['auto', 'single_pass'];

        if (!in_array($mode, $allowed, true)) {
            $mode = 'auto';
        }

        return 'single_pass';
    }

    private static function url_to_upload_path($url) {
        $uploads = wp_upload_dir();
        if (strpos($url, $uploads['baseurl']) === 0) {
            return str_replace($uploads['baseurl'], $uploads['basedir'], $url);
        }
        return '';
    }

              public static function create_poster_draft() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        if (CMSG_Plugin::settings()['enable_poster_studio'] !== '1') {
            wp_send_json_error(['message' => 'Poster studio is disabled.'], 403);
        }

        $uploads = self::save_poster_uploads();
        if (is_wp_error($uploads)) {
            wp_send_json_error(['message' => $uploads->get_error_message()], 400);
        }
        $poster_scene_direction = self::poster_scene_direction();
        $cast_members = self::posted_cast_members($uploads);
        $poster_layout = self::poster_layout($cast_members);
        $poster_generation_mode = self::poster_generation_mode($cast_members);
        $payload = [
            'request_email' => sanitize_email(wp_unslash($_POST['request_email'] ?? '')),
            'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'tagline' => sanitize_text_field(wp_unslash($_POST['tagline'] ?? '')),
            'title_font_style' => sanitize_text_field(wp_unslash($_POST['title_font_style'] ?? 'cinematic_bold')),
            'tagline_font_style' => sanitize_text_field(wp_unslash($_POST['tagline_font_style'] ?? 'clean_sans')),
            'title_position' => sanitize_text_field(wp_unslash($_POST['title_position'] ?? 'bottom_cinematic')),            
            'preserve_identity' => !empty($_POST['preserve_identity']), 
            'genre' => sanitize_text_field(wp_unslash($_POST['genre'] ?? '')),
            'mood' => sanitize_text_field(wp_unslash($_POST['mood'] ?? '')),
            'style_preset' => sanitize_text_field(wp_unslash($_POST['style_preset'] ?? '')),
            'poster_layout' => $poster_layout,
            'poster_generation_mode' => $poster_generation_mode,
            'poster_description' => $poster_scene_direction,

            'source_reference' => wp_json_encode([
            'style_reference' => $uploads['style_reference'],
            'poster_assets' => $uploads['poster_assets'],
            'poster_layout' => $poster_layout,
            'poster_generation_mode' => $poster_generation_mode,
            'poster_description' => $poster_scene_direction,
            'cast_members' => $cast_members,

            'cast_actor_1' => $cast_members[0]['image'] ?? $uploads['cast_actor_1'],
            'cast_actor_2' => $cast_members[1]['image'] ?? $uploads['cast_actor_2'],
            'cast_actor_3' => $cast_members[2]['image'] ?? $uploads['cast_actor_3'],

            'cast_actor_1_instruction' => $cast_members[0]['instruction'] ?? sanitize_text_field(wp_unslash($_POST['cast_actor_1_instruction'] ?? '')),
            'cast_actor_2_instruction' => $cast_members[1]['instruction'] ?? sanitize_text_field(wp_unslash($_POST['cast_actor_2_instruction'] ?? '')),
            'cast_actor_3_instruction' => $cast_members[2]['instruction'] ?? sanitize_text_field(wp_unslash($_POST['cast_actor_3_instruction'] ?? '')),
]),

        ];

        if (empty($payload['request_email']) || empty($payload['title'])) {
            wp_send_json_error(['message' => 'Poster title and email are required.'], 400);
        }

        $draft_id = CMSG_Posters::create_draft($payload);
        wp_send_json_success([
            'draft_id' => $draft_id,
            'amount' => CMSG_Posters::price(),
            'currency' => CMSG_Plugin::settings()['paypal_currency'],
        ]);
    }

    public static function generate_poster_previews() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        if (!$draft) wp_send_json_error(['message' => 'Poster draft not found.'], 404);
        $meta = self::poster_meta_from_draft($draft);

        $poster_scene_direction = self::poster_scene_direction($meta);
        $cast_members = self::posted_cast_members([], $meta);
        $poster_layout = self::poster_layout($cast_members, $meta);
        $poster_generation_mode = self::poster_generation_mode($cast_members, $meta);

        $brief = [
            'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'tagline' => sanitize_text_field(wp_unslash($_POST['tagline'] ?? '')),
            'title_font_style' => sanitize_text_field(wp_unslash($_POST['title_font_style'] ?? 'cinematic_bold')),
            'tagline_font_style' => sanitize_text_field(wp_unslash($_POST['tagline_font_style'] ?? 'clean_sans')),
            'title_position' => sanitize_text_field(wp_unslash($_POST['title_position'] ?? 'bottom_cinematic')),
            'preserve_identity' => !empty($_POST['preserve_identity']), 
            'genre' => sanitize_text_field(wp_unslash($_POST['genre'] ?? '')),
            'mood' => sanitize_text_field(wp_unslash($_POST['mood'] ?? '')),
            'style_preset' => sanitize_text_field(wp_unslash($_POST['style_preset'] ?? '')),
            'poster_layout' => $poster_layout,
            'poster_generation_mode' => $poster_generation_mode,
            'poster_description' => $poster_scene_direction,
            'cast_members' => $cast_members,

            'cast_actor_1' => $cast_members[0]['image'] ?? ($meta['cast_actor_1'] ?? ''),
            'cast_actor_2' => $cast_members[1]['image'] ?? ($meta['cast_actor_2'] ?? ''),
            'cast_actor_3' => $cast_members[2]['image'] ?? ($meta['cast_actor_3'] ?? ''),

            'cast_actor_1_instruction' => $cast_members[0]['instruction'] ?? sanitize_text_field(wp_unslash($_POST['cast_actor_1_instruction'] ?? ($meta['cast_actor_1_instruction'] ?? ''))),
            'cast_actor_2_instruction' => $cast_members[1]['instruction'] ?? sanitize_text_field(wp_unslash($_POST['cast_actor_2_instruction'] ?? ($meta['cast_actor_2_instruction'] ?? ''))),
            'cast_actor_3_instruction' => $cast_members[2]['instruction'] ?? sanitize_text_field(wp_unslash($_POST['cast_actor_3_instruction'] ?? ($meta['cast_actor_3_instruction'] ?? ''))),

            'style_reference' => $meta['style_reference'] ?? '',
            'poster_assets' => $meta['poster_assets'] ?? [],
];

        $previews = CMSG_Poster_AI::generate_previews($brief, $draft->id);
if (is_wp_error($previews)) {
    wp_send_json_error(['message' => $previews->get_error_message()], 500);
}
            wp_send_json_success([
            'previews' => $previews,
            'previews_count' => is_array($previews) ? count($previews) : -1,
            'preview_quality_message' => CMSG_Poster_AI::last_preview_quality_message(),
            'watermark' => CMSG_Posters::preview_watermark(),
            'amount' => CMSG_Posters::price(),
            'prompt_preview' => CMSG_Poster_AI::build_prompt($brief),
            'preview_mode' => CMSG_Poster_AI::preview_mode() ? 'svg_fallback' : 'api',
            'debug_openai_key_present' => !empty(CMSG_Plugin::settings()['openai_api_key']) ? 'YES' : 'NO',
        ]);
    }

    public static function create_poster_paypal_order() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        if (!$draft || $draft->status !== 'draft' || ($draft->module_type ?? 'subtitle') !== 'poster') {
            wp_send_json_error(['message' => 'Poster draft not found or no longer payable.'], 404);
        }
        $order_id = CMSG_PayPal::create_order_for_draft($draft);
        if (is_wp_error($order_id)) wp_send_json_error(['message' => $order_id->get_error_message()], 400);
        wp_send_json_success(['orderID' => $order_id]);
    }

    public static function capture_poster_paypal_order() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        if (!$draft || $draft->status !== 'draft' || ($draft->module_type ?? 'subtitle') !== 'poster') {
            wp_send_json_error(['message' => 'Poster draft not found or no longer payable.'], 404);
        }
        $auth = CMSG_PayPal::capture_and_verify_order($draft, sanitize_text_field(wp_unslash($_POST['order_id'] ?? '')), 'poster');
        if (is_wp_error($auth)) wp_send_json_error(['message' => $auth->get_error_message()], 400);
        wp_send_json_success(['payment_token' => $auth['token'], 'expires_at' => $auth['expires_at']]);
    }

    public static function finalize_paid_poster_draft() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        if (!$draft || !in_array($draft->status, ['authorized'], true) || ($draft->module_type ?? 'subtitle') !== 'poster') {
            wp_send_json_error(['message' => 'Poster draft is not authorized for finalization.'], 403);
        }
        $auth = CMSG_Payments::validate_authorization(wp_unslash($_POST['payment_token'] ?? ''), $draft);
        if (is_wp_error($auth)) wp_send_json_error(['message' => $auth->get_error_message()], 403);
        $consumed = CMSG_Payments::consume_authorization((int)$auth->id);
        if (!$consumed) wp_send_json_error(['message' => 'This poster payment authorization has already been used.'], 409);
        $draft_consumed = CMSG_Drafts::consume($draft->id);
        if (!$draft_consumed) wp_send_json_error(['message' => 'This poster draft has already been finalized or expired.'], 409);

        $meta = self::poster_meta_from_draft($draft);
        $selected_preview_url = esc_url_raw(wp_unslash($_POST['selected_preview_url'] ?? ''));
        $selected_preview_path = self::url_to_upload_path($selected_preview_url);
        $poster_scene_direction = self::poster_scene_direction($meta);
        $cast_members = self::posted_cast_members([], $meta);
        $poster_layout = self::poster_layout($cast_members, $meta);
        $poster_generation_mode = self::poster_generation_mode($cast_members, $meta);

        error_log('CMSG POSTER FINALIZE AJAX TRACE: selected_preview_url=' . $selected_preview_url);
        error_log('CMSG POSTER FINALIZE AJAX TRACE: selected_preview_path=' . $selected_preview_path);

        $brief = [
            'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'tagline' => sanitize_text_field(wp_unslash($_POST['tagline'] ?? '')),
            'title_font_style' => sanitize_text_field(wp_unslash($_POST['title_font_style'] ?? 'cinematic_bold')),
            'tagline_font_style' => sanitize_text_field(wp_unslash($_POST['tagline_font_style'] ?? 'clean_sans')),
            'title_position' => sanitize_text_field(wp_unslash($_POST['title_position'] ?? 'bottom_cinematic')),
            'preserve_identity' => !empty($_POST['preserve_identity']),
            'genre' => sanitize_text_field(wp_unslash($_POST['genre'] ?? '')),
            'mood' => sanitize_text_field(wp_unslash($_POST['mood'] ?? '')),
            'style_preset' => sanitize_text_field(wp_unslash($_POST['style_preset'] ?? '')),
            'poster_layout' => $poster_layout,
            'poster_generation_mode' => $poster_generation_mode,
            'selected_concept' => (int)($_POST['selected_concept'] ?? 0),
            'selected_preview_path' => $selected_preview_path,
            'poster_description' => $poster_scene_direction,
            'cast_members' => $cast_members,

            'cast_actor_1' => $cast_members[0]['image'] ?? ($meta['cast_actor_1'] ?? ''),
            'cast_actor_2' => $cast_members[1]['image'] ?? ($meta['cast_actor_2'] ?? ''),
            'cast_actor_3' => $cast_members[2]['image'] ?? ($meta['cast_actor_3'] ?? ''),

            'cast_actor_1_instruction' => $cast_members[0]['instruction'] ?? sanitize_text_field(wp_unslash($_POST['cast_actor_1_instruction'] ?? ($meta['cast_actor_1_instruction'] ?? ''))),
            'cast_actor_2_instruction' => $cast_members[1]['instruction'] ?? sanitize_text_field(wp_unslash($_POST['cast_actor_2_instruction'] ?? ($meta['cast_actor_2_instruction'] ?? ''))),
            'cast_actor_3_instruction' => $cast_members[2]['instruction'] ?? sanitize_text_field(wp_unslash($_POST['cast_actor_3_instruction'] ?? ($meta['cast_actor_3_instruction'] ?? ''))),

            'style_reference' => $meta['style_reference'] ?? '',
            'poster_assets' => $meta['poster_assets'] ?? [],

        ];

        $job_id = CMSG_Poster_Jobs::create_from_authorized_draft($draft->id, (int)$auth->id, $brief);
        if (is_wp_error($job_id)) wp_send_json_error(['message' => $job_id->get_error_message()], 400);
        wp_send_json_success(['job_id' => $job_id, 'message' => 'Payment confirmed. Clean poster files are ready.']);
    }

    public static function poster_job_status() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $job = CMSG_Poster_Jobs::get((int)($_POST['job_id'] ?? 0));
        if (!$job) wp_send_json_error(['message' => 'Poster job not found.'], 404);
        wp_send_json_success(['status' => $job->status, 'can_download' => ($job->payment_status === 'paid' && $job->status === 'completed')]);
    }

    public static function issue_poster_download_grant() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $job = CMSG_Poster_Jobs::get((int)($_POST['job_id'] ?? 0));
        if (!$job || $job->payment_status !== 'paid' || $job->status !== 'completed') {
            wp_send_json_error(['message' => 'Poster assets are not available.'], 403);
        }
        $manifest = json_decode((string)$job->final_manifest, true);
        if (!$manifest || empty($manifest['vertical'])) {
            wp_send_json_error(['message' => 'Poster assets are missing.'], 404);
        }
        $downloads = [];
        foreach ($manifest as $key => $path) {
            if (!empty($path) && file_exists($path)) {
                $downloads[$key] = CMSG_Poster_Downloads::issue_grant($job->id, $path);
            }
        }
        self::send_poster_delivery_email($job, $downloads, $manifest);
        wp_send_json_success([
            'vertical' => $downloads['vertical'] ?? '',
            'square' => $downloads['square'] ?? '',
            'landscape' => $downloads['banner'] ?? '',
            'banner' => $downloads['banner'] ?? '',
            'downloads' => $downloads,
        ]);
    }

    private static function send_poster_delivery_email($job, $downloads, $manifest) {
        $to = sanitize_email($job->request_email ?? '');
        if (!$to) return;
        $subject = 'Your Crossmarket Poster Files Are Ready';
        $message = "Hello,\n\nYour clean poster files are ready.\n\n";
foreach ([
    'vertical' => 'Vertical poster',
    'banner'   => 'Landscape/Banner poster'
] as $key => $label) {
            if (!empty($downloads[$key])) $message .= $label . ': ' . $downloads[$key] . "\n";
        }
        $message .= "\nThank you,\nCrossmarket Films";
        $headers = ['Content-Type: text/plain; charset=UTF-8', 'From: Crossmarket Films <' . sanitize_email(CMSG_Plugin::settings()['from_email']) . '>'];
        $attachments = [];
        foreach ((array)$manifest as $path) {
            if (file_exists($path)) $attachments[] = $path;
        }
        wp_mail($to, $subject, $message, $headers, $attachments);
    }

    public static function download_poster_asset() {
        $job_id = intval($_GET['job_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        if ($job_id <= 0 || empty($token)) wp_die('Invalid request.', 400);
        $dir = wp_upload_dir()['basedir'] . '/poster-grants';
        foreach (glob($dir . '/grant-' . $job_id . '-*.json') as $file) {
            $payload = json_decode((string) @file_get_contents($file), true);
            if (!$payload) continue;
            if (($payload['expires_at'] ?? 0) < time()) continue;
            if (hash('sha256', $token) !== ($payload['hash'] ?? '')) continue;
            $asset = $payload['asset_path'] ?? '';
            if (!$asset || !file_exists($asset)) wp_die('Asset not available.', 404);
            nocache_headers();
            $mime = function_exists('mime_content_type') ? mime_content_type($asset) : '';
            if (!$mime) $mime = 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . basename($asset) . '"');
            readfile($asset);
            exit;
        }
        wp_die('Invalid or expired poster download link.', 403);
    }

    public static function create_trailer_draft() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        if (CMSG_Plugin::settings()['enable_trailer_studio'] !== '1') {
            wp_send_json_error(['message' => 'Trailer studio is disabled.'], 403);
        }

        $brief = CMSG_Trailers::sanitize_brief($_POST);
        $valid = CMSG_Trailers::validate_brief($brief);
        if (is_wp_error($valid)) {
            wp_send_json_error(['message' => $valid->get_error_message()], 400);
        }

        $draft_id = CMSG_Trailers::create_draft($brief);
        if (is_wp_error($draft_id)) {
            wp_send_json_error(['message' => $draft_id->get_error_message()], 400);
        }

        wp_send_json_success([
            'draft_id' => $draft_id,
            'amount' => CMSG_Trailers::price(),
            'currency' => CMSG_Plugin::settings()['paypal_currency'],
            'brief' => $brief,
        ]);
    }

    public static function create_trailer_paypal_order() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        if (!$draft || $draft->status !== 'draft' || ($draft->module_type ?? 'subtitle') !== 'trailer') wp_send_json_error(['message' => 'Trailer draft not found or no longer payable.'], 404);
        $order_id = CMSG_PayPal::create_order_for_draft($draft);
        if (is_wp_error($order_id)) wp_send_json_error(['message' => $order_id->get_error_message()], 400);
        wp_send_json_success(['orderID' => $order_id]);
    }

    public static function capture_trailer_paypal_order() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        if (!$draft || $draft->status !== 'draft' || ($draft->module_type ?? 'subtitle') !== 'trailer') wp_send_json_error(['message' => 'Trailer draft not found or no longer payable.'], 404);
        $auth = CMSG_PayPal::capture_and_verify_order($draft, sanitize_text_field(wp_unslash($_POST['order_id'] ?? '')), 'trailer');
        if (is_wp_error($auth)) wp_send_json_error(['message' => $auth->get_error_message()], 400);
        wp_send_json_success(['payment_token' => $auth['token'], 'expires_at' => $auth['expires_at']]);
    }

    public static function finalize_paid_trailer_draft() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $draft = CMSG_Drafts::get((int)($_POST['draft_id'] ?? 0));
        if (!$draft || !in_array($draft->status, ['authorized'], true) || ($draft->module_type ?? 'subtitle') !== 'trailer') {
            wp_send_json_error(['message' => 'Trailer draft is not authorized for finalization.'], 403);
        }
        $auth = CMSG_Payments::validate_authorization(wp_unslash($_POST['payment_token'] ?? ''), $draft);
        if (is_wp_error($auth)) {
            wp_send_json_error(['message' => $auth->get_error_message()], 403);
        }
        $consumed = CMSG_Payments::consume_authorization((int)$auth->id);
        if (!$consumed) {
            wp_send_json_error(['message' => 'This trailer payment authorization has already been used.'], 409);
        }
        $draft_consumed = CMSG_Drafts::consume($draft->id);
        if (!$draft_consumed) {
            wp_send_json_error(['message' => 'This trailer draft has already been finalized or expired.'], 409);
        }

        $brief = CMSG_Trailers::sanitize_brief($_POST);
        if (empty($brief['description']) && !empty($draft->file_reference)) {
            $stored = json_decode((string) $draft->file_reference, true);
            if (is_array($stored)) { $brief = wp_parse_args($brief, $stored); }
        }

        $job_id = CMSG_Trailer_Jobs::create_from_authorized_draft($draft->id, (int)$auth->id, $brief);
        if (is_wp_error($job_id)) {
            wp_send_json_error(['message' => $job_id->get_error_message()], 400);
        }
        $job = CMSG_Trailer_Jobs::get($job_id);
        wp_send_json_success([
            'job_id' => $job_id,
            'message' => 'Payment confirmed. Trailer Studio generated the structured trailer brief package.',
            'status' => $job ? $job->status : 'completed',
            'manifest' => $job ? CMSG_Trailer_Jobs::manifest($job) : [],
        ]);
    }

    public static function trailer_job_status() {
        check_ajax_referer('cmsg_nonce', 'nonce');
        $job = CMSG_Trailer_Jobs::get((int)($_POST['job_id'] ?? 0));
        if (!$job) {
            wp_send_json_error(['message' => 'Trailer job not found.'], 404);
        }
        wp_send_json_success([
            'status' => $job->status,
            'message' => $job->status_message ?: '',
            'can_download' => $job->status === 'completed',
            'manifest' => CMSG_Trailer_Jobs::manifest($job),
        ]);
    }
public static function blend_selected_actor_face() {
    check_ajax_referer('cmsg_nonce', 'nonce');

    $poster_path = sanitize_text_field($_POST['poster_path'] ?? '');
    $actor_path  = sanitize_text_field($_POST['actor_path'] ?? '');

    $face_box = [
        'x' => intval($_POST['x'] ?? 0),
        'y' => intval($_POST['y'] ?? 0),
        'w' => intval($_POST['w'] ?? 0),
        'h' => intval($_POST['h'] ?? 0),
    ];

    if (!$poster_path || !$actor_path || $face_box['w'] <= 0 || $face_box['h'] <= 0) {
        wp_send_json_error(['message' => 'Missing poster, actor, or face box.']);
    }

    if (!class_exists('CMSG_Poster_AI')) {
        wp_send_json_error(['message' => 'Poster AI class unavailable.']);
    }

    $result = CMSG_Poster_AI::blend_selected_actor_face($poster_path, $actor_path, $face_box);

    if (!$result) {
        wp_send_json_error(['message' => 'Face blend failed.']);
    }

    wp_send_json_success([
        'message' => 'Actor face blended.',
        'poster_path' => $poster_path,
    ]);
}

}
