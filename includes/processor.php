<?php
if (!defined('ABSPATH')) { exit; }

final class CMSG_Processor {

    private static function progress_message($percent, $message) {
        return '[' . intval($percent) . '%] ' . $message;
    }

    private static function update_progress($job_id, $percent, $message, $extra = []) {
        $data = array_merge([
            'log_text' => self::progress_message($percent, $message),
        ], $extra);

        CMSG_Jobs::update_job($job_id, $data);
    }

    private static function is_closed_caption_mode($job) {
        return isset($job->caption_mode) && (string)$job->caption_mode === 'closed_caption';
    }

    private static function final_ready_message($job, $email_sent) {
        if (!$email_sent) {
            return 'Subtitle files are ready. Email delivery could not be confirmed, but download links are available below.';
        }

        if (self::is_closed_caption_mode($job)) {
            return 'Closed Caption files are ready. SRT and VTT files include caption formatting where available, and a delivery email has been sent.';
        }

        return 'Subtitle files are ready. SRT and VTT download links are available, and a delivery email has been sent.';
    }

    public static function resolve_video_path($job) {
        $video_path = '';

        if (!empty($job->video_path)) {
            $video_path = $job->video_path;
        } elseif (!empty($job->file_path)) {
            $video_path = $job->file_path;
        } elseif (!empty($job->file_reference)) {
            $video_path = $job->file_reference;
        }

        if (!empty($video_path) && strpos($video_path, 'drive.google.com') !== false) {
            return self::download_google_drive_file(
                $video_path,
                !empty($job->original_filename) ? $job->original_filename : 'google-drive-video.mp4'
            );
        }

        if (!empty($job->storage_provider) && $job->storage_provider === 'gcs') {
            $object_key = '';

            if (!empty($job->object_key)) {
                $object_key = $job->object_key;
            } elseif (!empty($job->source_reference)) {
                $object_key = $job->source_reference;
            } elseif (!empty($job->file_reference)) {
                $object_key = $job->file_reference;
            } elseif (!empty($job->video_path)) {
                $object_key = $job->video_path;
            }

            if (empty($object_key)) {
                return new WP_Error('cmsg_gcs_missing_object', 'Missing GCS object key.');
            }

            $uploads = wp_upload_dir();
            $cache_dir = trailingslashit($uploads['basedir']) . 'cmsg-gcs-cache';

            if (!is_dir($cache_dir)) {
                wp_mkdir_p($cache_dir);
            }

            $local_path = trailingslashit($cache_dir) . basename($object_key);

            if (!file_exists($local_path)) {
                $settings = CMSG_Plugin::settings();
                $bucket = $settings['gcs_bucket_name'] ?? '';

                if (empty($bucket)) {
                    return new WP_Error('cmsg_gcs_missing_bucket', 'Missing GCS bucket name.');
                }

                putenv('CLOUDSDK_CONFIG=/var/www/.config/gcloud');

                $cmd = 'CLOUDSDK_CONFIG=/var/www/.config/gcloud gcloud storage cp ' .
                    escapeshellarg('gs://' . $bucket . '/' . ltrim($object_key, '/')) . ' ' .
                    escapeshellarg($local_path) . ' 2>&1';

                $output = [];
                $code = 0;
                exec($cmd, $output, $code);

                if ($code !== 0 || !file_exists($local_path)) {
                    return new WP_Error(
                        'cmsg_gcs_download_failed',
                        'Failed to download GCS file: ' . implode("\n", $output)
                    );
                }

                @chown($local_path, 'www-data');
                @chmod($local_path, 0664);
            }

            return $local_path;
        }

        return $video_path;
    }

    private static function download_google_drive_file($drive_url, $original_filename = '') {
        if (empty($drive_url)) {
            return new WP_Error('cmsg_drive_missing_url', 'Missing Google Drive URL.');
        }

        $uploads = wp_upload_dir();
        $cache_dir = trailingslashit($uploads['basedir']) . 'cmsg-drive-cache';

        if (!is_dir($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }

        $safe_name = !empty($original_filename)
            ? sanitize_file_name($original_filename)
            : 'google-drive-video.mp4';

        $local_path = trailingslashit($cache_dir) . time() . '-' . $safe_name;
        $python = '/opt/subgen-venv/bin/python';

        putenv('HOME=/var/www');
        putenv('XDG_CACHE_HOME=/var/www/.cache');

$cmd = 'timeout 600 ' .
    escapeshellcmd($python) . ' -m gdown ' .
    escapeshellarg($drive_url) . ' -O ' .
    escapeshellarg($local_path) . ' 2>&1';

$output = [];
$code = 0;

exec($cmd, $output, $code);

// cleanup incomplete Google Drive partial downloads
foreach (glob($cache_dir . '/*.part') as $part_file) {
    @unlink($part_file);
}


if ($code === 124) {
    return new WP_Error(
        'cmsg_drive_timeout',
        'Google Drive download timed out after 600 seconds. Please make sure the file is publicly accessible or use the Large File / Google Cloud option'
    );
}


        if ($code !== 0 || !file_exists($local_path)) {
            return new WP_Error(
                'cmsg_drive_download_failed',
                'Failed to download Google Drive file: ' . implode("\n", $output)
            );
        }

        @chown($local_path, 'www-data');
        @chmod($local_path, 0664);

        return $local_path;
    }

private static function send_completion_email($job_id, $srt_path) {
    $job = CMSG_Jobs::get_job($job_id);

    if (!$job) {
        error_log('CMSG EMAIL TEST: job not found for email, job_id=' . $job_id);
        return false;
    }

    $to = isset($job->requester_email) ? trim($job->requester_email) : '';

    if (empty($to) || !is_email($to)) {
        error_log('CMSG EMAIL TEST: invalid or empty recipient email: ' . $to);
        return false;
    }

    $subject = 'Your Subtitle / Closed Caption File is Ready';

    $srt_url = site_url('/wp-content/uploads/cmsg-gcs-cache/' . basename($srt_path));

    $vtt_url = '';
    if (!empty($job->vtt_path) && file_exists($job->vtt_path)) {
        $vtt_url = site_url('/wp-content/uploads/cmsg-gcs-cache/' . basename($job->vtt_path));
    }

$message = "Hello,\n\n";
$message .= "Your subtitle / closed-caption files are ready.\n\n";
$message .= "Download SRT Subtitle File:\n";
$message .= $srt_url . "\n\n";

if (!empty($vtt_url)) {
    $message .= "Download VTT Closed Caption File:\n";
    $message .= $vtt_url . "\n\n";
}

$message .= "Note: Your browser may show a blank page briefly while the download starts. If the file opens in the browser instead of downloading, right-click the link and choose 'Save link as...'.\n\n";
$message .= "Thank you,\n";
$message .= "Crossmarket Films";
    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: Crossmarket Films <noreply@crossmarketfilms.com>'
    ];

    error_log('CMSG EMAIL TEST: sending subtitle/CC email to ' . $to);

    $sent = wp_mail($to, $subject, $message, $headers);

    if ($sent) {
        error_log('CMSG EMAIL TEST: wp_mail returned true for ' . $to);
    } else {
        error_log('CMSG EMAIL TEST: wp_mail returned false for ' . $to);
    }

    return $sent;
}

    private static function create_vtt_from_srt($srt_path) {
        if (empty($srt_path) || !file_exists($srt_path)) {
            return '';
        }

        $vtt_path = preg_replace('/\.srt$/i', '.vtt', $srt_path);

        if ($vtt_path === $srt_path) {
            $vtt_path = $srt_path . '.vtt';
        }

        $content = (string) file_get_contents($srt_path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(',', '.', $content);

        file_put_contents($vtt_path, "WEBVTT\n\n" . $content);
        @chmod($vtt_path, 0664);

        return $vtt_path;
    }

    private static function add_basic_atmospheric_cues_to_vtt($vtt_path, $video_path = '') {
        if (empty($vtt_path) || !file_exists($vtt_path)) {
            return false;
        }

        $content = file_get_contents($vtt_path);

        if ($content === false || strpos($content, 'WEBVTT') !== 0) {
            return false;
        }

        if (strpos($content, '[atmospheric cue]') !== false) {
            return true;
        }

        $cue_text = '[atmospheric cue] ambient sound';

        $content .= "\n\n";
        $content .= "00:00:02.000 --> 00:00:05.000\n";
        $content .= $cue_text . "\n";

        return file_put_contents($vtt_path, $content) !== false;
    }

    public static function process_job($job_id) {
        $job = CMSG_Jobs::get_job($job_id);

        if (!$job) {
            return;
        }

        if (!CMSG_Jobs::can_job_be_processed($job)) {
            return;
        }

        $auth = CMSG_Payments::get_by_id((int) $job->payment_authorization_id);

        if (!$auth || !in_array($auth->status, ['used', 'active'], true)) {
            CMSG_Jobs::update_job($job_id, [
                'status' => 'failed',
                'log_text' => 'Processing blocked because payment authorization is not valid.'
            ]);
            return;
        }

        self::update_progress($job_id, 20, 'Preparing media file for subtitle processing.', [
            'status' => 'processing',
        ]);

        $resolved_video_path = self::resolve_video_path($job);

        if (is_wp_error($resolved_video_path)) {
            CMSG_Jobs::update_job($job_id, [
                'status' => 'failed',
                'log_text' => $resolved_video_path->get_error_message()
            ]);
            return;
        }

        if (empty($resolved_video_path) || !file_exists($resolved_video_path)) {
            CMSG_Jobs::update_job($job_id, [
                'status' => 'failed',
                'log_text' => 'Subtitle generation failed. Video file not found: ' . $resolved_video_path
            ]);
            return;
        }

        if (!empty($job->storage_provider) && $job->storage_provider === 'gcs') {
            self::update_progress($job_id, 20, 'Preparing media file. Downloaded cloud upload to local processing cache.');
        }

        $s = CMSG_Plugin::settings();

        $python = escapeshellcmd($s['python_binary']);
        $script = escapeshellarg(CMSG_DIR . 'bin/generate_subtitles.py');
        $video = escapeshellarg($resolved_video_path);
        $source_language = !empty($job->source_language) ? $job->source_language : (!empty($job->language_code) ? $job->language_code : 'auto');
        $lang = escapeshellarg($source_language);
        $model = escapeshellarg(!empty($job->model_size) ? $job->model_size : $s['default_model']);
        $ffmpeg = escapeshellarg($s['ffmpeg_binary']);
        $mode = escapeshellarg($s['whisper_mode']);

        $command = "{$python} {$script} --video {$video} --language {$lang} --model {$model} --ffmpeg {$ffmpeg} --mode {$mode} 2>&1";

        self::update_progress($job_id, 30, 'Extracting audio and preparing speech recognition.');
        self::update_progress($job_id, 45, 'Running speech recognition. Longer videos may remain on this step while transcription completes.');

        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);

        $log = implode("\n", $output);

        if ($return_var !== 0) {
            CMSG_Jobs::update_job($job_id, [
                'status' => 'failed',
                'log_text' => "Subtitle generation failed.\n" . $log
            ]);
            return;
        }

        $srt = '';

        foreach ($output as $line) {
            if (strpos($line, 'SRT_PATH=') === 0) {
                $srt = trim(substr($line, 9));
            }
        }

        if (empty($srt) || !file_exists($srt)) {
            CMSG_Jobs::update_job($job_id, [
                'status' => 'failed',
                'log_text' => 'Subtitle generation failed. No SRT output was created.'
            ]);
            return;
        }

        self::update_progress($job_id, 65, 'Building transcript segments from speech recognition output.');

        $caption_mode = isset($job->caption_mode) ? (string) $job->caption_mode : 'subtitle';

$output_language = !empty($job->output_language) ? (string) $job->output_language : 'same';
$translation_mode = !empty($job->translation_mode) ? (string) $job->translation_mode : 'none';

$should_translate = (
    $translation_mode !== 'none'
    && $output_language !== 'same'
    && $output_language !== ''
    && $output_language !== $source_language
);

$translation_note = '';

if ($source_language === 'yo' && file_exists($srt)) {
    self::update_progress($job_id, 75, 'Applying localization cleanup before final subtitle formatting.');

    $cleaned_yoruba_srt = preg_replace('/\.srt$/i', '-yo-cleaned.srt', $srt);

    $cleanup_script = escapeshellarg(CMSG_DIR . 'bin/cleanup_yoruba_srt.py');
    $cleanup_input  = escapeshellarg($srt);
    $cleanup_output = escapeshellarg($cleaned_yoruba_srt);
    $cleanup_key    = escapeshellarg(CMSG_Plugin::settings()['openai_api_key'] ?? '');

    $cleanup_cmd = "python3 {$cleanup_script} --input {$cleanup_input} --output {$cleanup_output} --api-key {$cleanup_key} 2>&1";

    $cleanup_output_lines = [];
    $cleanup_return = 0;
    exec($cleanup_cmd, $cleanup_output_lines, $cleanup_return);

    if ($cleanup_return === 0 && file_exists($cleaned_yoruba_srt)) {
        $srt = $cleaned_yoruba_srt;
        $translation_note .= "\nYoruba transcript cleanup completed before translation.";
    } else {
        $translation_note .= "\nYoruba transcript cleanup failed:\n" . implode("\n", $cleanup_output_lines);
    }
}

if ($should_translate && file_exists($srt)) {
    self::update_progress($job_id, 75, 'Applying translation/localization to subtitle segments.');

    $translated_srt = preg_replace('/\.srt$/i', '-' . sanitize_file_name($output_language) . '.srt', $srt);

    $translate_script = escapeshellarg(CMSG_DIR . 'bin/translate_srt.py');
    $translate_input  = escapeshellarg($srt);
    $translate_output = escapeshellarg($translated_srt);
    $translate_target = escapeshellarg($output_language);
    $translate_key    = escapeshellarg(CMSG_Plugin::settings()['openai_api_key'] ?? '');

    $translate_cmd = "python3 {$translate_script} --input {$translate_input} --output {$translate_output} --target {$translate_target} --api-key {$translate_key} 2>&1";

    $translate_output_lines = [];
    $translate_return = 0;
    exec($translate_cmd, $translate_output_lines, $translate_return);

    if ($translate_return === 0 && file_exists($translated_srt)) {
        $srt = $translated_srt;
        $translation_note = "\nTranslation completed: {$source_language} → {$output_language}.";
if ($output_language === 'yo' && file_exists($srt)) {
    $cleaned_yoruba_output = preg_replace('/\.srt$/i', '-cleaned.srt', $srt);

    $cleanup_script = escapeshellarg(CMSG_DIR . 'bin/cleanup_yoruba_srt.py');
    $cleanup_input  = escapeshellarg($srt);
    $cleanup_output = escapeshellarg($cleaned_yoruba_output);
    $cleanup_key    = escapeshellarg(CMSG_Plugin::settings()['openai_api_key'] ?? '');

    $cleanup_cmd = "python3 {$cleanup_script} --input {$cleanup_input} --output {$cleanup_output} --api-key {$cleanup_key} 2>&1";

    $cleanup_output_lines = [];
    $cleanup_return = 0;
    exec($cleanup_cmd, $cleanup_output_lines, $cleanup_return);

    if ($cleanup_return === 0 && file_exists($cleaned_yoruba_output)) {
        $srt = $cleaned_yoruba_output;
        $translation_note .= "\nYoruba output cleanup completed.";
    } else {
        $translation_note .= "\nYoruba output cleanup failed:\n" . implode("\n", $cleanup_output_lines);
    }
}

 } else {
        $translation_note = "\nTranslation requested but failed:\n" . implode("\n", $translate_output_lines);
    }
}
        self::update_progress($job_id, 85, 'Formatting subtitle timestamps.');
        self::update_progress($job_id, 90, 'Creating SRT file.');

        @chmod($srt, 0664);

        self::update_progress($job_id, 93, 'Creating VTT file.');
        $vtt = self::create_vtt_from_srt($srt);

if ($caption_mode === 'closed_caption' && !empty($vtt)) {
    self::update_progress($job_id, 95, 'Adding closed-caption cues to VTT file.');

    $cue_result = self::add_basic_detected_cues_to_vtt($vtt, $resolved_video_path);

    if (!$cue_result) {
        $translation_note .= "\nClosed-caption cue detection was skipped or unavailable; SRT and VTT files were still created.";
    }
}

        self::update_progress($job_id, 97, 'Saving final subtitle files.', [
            'srt_path' => $srt,
            'vtt_path' => !empty($vtt) ? $vtt : '',
        ]);

        self::update_progress($job_id, 98, 'Preparing secure download links.');
        self::update_progress($job_id, 99, 'Sending delivery email.');

        $email_sent = self::send_completion_email($job_id, $srt);

        CMSG_Jobs::update_job($job_id, [
            'status' => 'completed',
            'srt_path' => $srt,
            'vtt_path' => !empty($vtt) ? $vtt : '',
            'log_text' => self::progress_message(100, self::final_ready_message($job, $email_sent) . $translation_note),
        ]);
    }
private static function add_basic_detected_cues_to_vtt($vtt_path, $video_path) {
    if (empty($vtt_path) || !file_exists($vtt_path)) {
        return false;
    }

    if (empty($video_path) || !file_exists($video_path)) {
        return false;
    }

    $settings = CMSG_Plugin::settings();
    $python = !empty($settings['python_binary']) ? $settings['python_binary'] : '/opt/subgen-venv/bin/python';
    $ffmpeg = !empty($settings['ffmpeg_binary']) ? $settings['ffmpeg_binary'] : '/usr/bin/ffmpeg';

    $script = CMSG_DIR . 'bin/basic_audio_cues.py';

    if (!file_exists($script)) {
        return false;
    }

    $cmd = escapeshellcmd($python)
        . ' ' . escapeshellarg($script)
        . ' --video ' . escapeshellarg($video_path)
        . ' --ffmpeg ' . escapeshellarg($ffmpeg)
        . ' --max-cues 300'
        . ' 2>&1';

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);

    if ($code !== 0 || empty($output)) {
        error_log('CMSG BASIC CUE DETECTOR FAILED: ' . implode("\n", $output));
        return false;
    }

    $json = trim(end($output));
    $decoded = json_decode($json, true);

    if (!is_array($decoded) || empty($decoded['cues']) || !is_array($decoded['cues'])) {
        return false;
    }

    return self::append_basic_cues_to_vtt($vtt_path, $decoded['cues']);
}

private static function append_basic_cues_to_vtt($vtt_path, $cues) {
    if (empty($vtt_path) || !file_exists($vtt_path) || empty($cues) || !is_array($cues)) {
        return false;
    }

    $content = file_get_contents($vtt_path);

    if ($content === false || strpos($content, 'WEBVTT') !== 0) {
        return false;
    }

    foreach (array_slice($cues, 0, 300) as $cue) {
        if (empty($cue['cue'])) {
            continue;
        }

        $start = self::seconds_to_vtt_time((float)($cue['start'] ?? 0));
        $end = self::seconds_to_vtt_time((float)($cue['end'] ?? (($cue['start'] ?? 0) + 2)));
        $label = sanitize_text_field($cue['cue']);

        $content .= "\n\n" . $start . ' --> ' . $end . "\n" . $label . "\n";
    }

    return file_put_contents($vtt_path, $content) !== false;
}

private static function seconds_to_vtt_time($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = floor($seconds % 60);
    $millis = floor(($seconds - floor($seconds)) * 1000);

    return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $millis);
}

public static function detect_runtime_minutes_from_file($file_path) {
    if (empty($file_path) || !file_exists($file_path)) {
        return 0;
    }

    $settings = CMSG_Plugin::settings();
    $ffmpeg = !empty($settings['ffmpeg_binary']) ? $settings['ffmpeg_binary'] : '/usr/bin/ffmpeg';
    $ffprobe = preg_replace('/ffmpeg$/', 'ffprobe', $ffmpeg);

    if (empty($ffprobe) || !file_exists($ffprobe)) {
        $ffprobe = 'ffprobe';
    }

    $cmd = escapeshellcmd($ffprobe)
        . ' -v error -show_entries format=duration '
        . ' -of default=noprint_wrappers=1:nokey=1 '
        . escapeshellarg($file_path)
        . ' 2>&1';

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);

    if ($code !== 0 || empty($output)) {
        return 0;
    }

    $seconds = floatval(trim($output[0]));

    if ($seconds <= 0) {
        return 0;
    }

    return (int) ceil($seconds / 60);
}
}
