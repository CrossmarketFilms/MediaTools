<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_Jobs {
    public static function table() { global $wpdb; return $wpdb->prefix . 'cmsg_jobs'; }
    public static function create_job_from_authorized_draft($draft_id, $authorization_id) {
        global $wpdb; $draft = CMSG_Drafts::get($draft_id); if (!$draft) return new WP_Error('cmsg_draft_missing', 'Draft not found.');
        $now = current_time('mysql');
        $wpdb->insert(self::table(), [
            'created_at'=>$now,'updated_at'=>$now,'status'=>'queued','source_type'=>$draft->source_type, 'caption_mode'=>sanitize_text_field($draft->caption_mode ?? 'subtitle'), 'storage_provider'=>$draft->storage_provider,'object_key'=>$draft->object_key,
            'original_filename'=>$draft->original_filename ?: basename($draft->file_reference),
            'video_path'=>$draft->file_reference,'source_reference'=>$draft->source_reference ?: $draft->file_reference,

            'language_code'=>$draft->language_code,

            'source_language'=>$draft->source_language ?? 'auto',
            'output_language'=>$draft->output_language ?? 'same',
            'translation_mode'=>$draft->translation_mode ?? 'none',

            'model_size'=>$draft->model_size,

            'caption_mode'=>sanitize_text_field($draft->caption_mode ?? 'subtitle'),
            'requester_email'=>$draft->request_email,'minutes_estimate'=>$draft->runtime_minutes,
            'estimated_price'=>$draft->amount,'payment_status'=>'paid','draft_id'=>$draft->id,'payment_authorization_id'=>$authorization_id,
            'log_text'=>'[10%] Payment confirmed. Job received and queued for processing.',
        ]);
        $job_id = (int)$wpdb->insert_id;
        if ($job_id) wp_schedule_single_event(time() + 10, 'cmsg_process_job', [$job_id]);
        return $job_id;
    }
    public static function get_job($job_id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $job_id)); }
    public static function update_job($job_id, $data) { global $wpdb; $data['updated_at']=current_time('mysql'); $wpdb->update(self::table(), $data, ['id'=>$job_id]); }
    public static function can_job_be_processed($job) { return !empty($job) && $job->payment_status === 'paid' && !empty($job->payment_authorization_id); }
    public static function path_to_url($path) { $upload = wp_get_upload_dir(); if (strpos($path, $upload['basedir']) === 0) return str_replace($upload['basedir'], $upload['baseurl'], $path); return ''; }
}
