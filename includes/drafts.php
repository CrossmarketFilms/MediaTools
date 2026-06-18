<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_Drafts {
    public static function table() { global $wpdb; return $wpdb->prefix . 'cmsg_drafts'; }
    public static function create($payload) {
        global $wpdb; $table = self::table(); $now = current_time('mysql');
        $wpdb->insert($table, [
            'created_at'=>$now,'updated_at'=>$now,'status'=>'draft',
            'user_id'=>CMSG_Validation::current_user_id_or_null(),
            'session_fingerprint'=>CMSG_Validation::get_session_fingerprint(),
            'module_type'=>sanitize_text_field($payload['module_type'] ?? 'subtitle'),
'caption_mode'=>sanitize_text_field($payload['caption_mode'] ?? 'subtitle'),            
'request_email'=>sanitize_email($payload['request_email'] ?? ''),
            'source_type'=>sanitize_text_field($payload['source_type'] ?? 'browser_upload'),
            'storage_provider'=>sanitize_text_field($payload['storage_provider'] ?? 'local'),
            'source_reference'=>(string)($payload['source_reference'] ?? ($payload['file_reference'] ?? '')),
            'object_key'=>(string)($payload['object_key'] ?? ''),
            'file_reference'=>(string)($payload['file_reference'] ?? ''),
            'file_hash'=>sanitize_text_field($payload['file_hash'] ?? ''),
            'original_filename'=>sanitize_file_name($payload['original_filename'] ?? ''),
            'language_code'=>sanitize_text_field($payload['language_code'] ?? 'auto'),
            'source_language'=>sanitize_text_field($payload['source_language'] ?? 'auto'),
            'output_language'=>sanitize_text_field($payload['output_language'] ?? 'same'),
            'translation_mode'=>sanitize_text_field($payload['translation_mode'] ?? 'none'),
            'model_size'=>sanitize_text_field($payload['model_size'] ?? CMSG_Plugin::settings()['default_model']),
            'runtime_minutes'=>floatval($payload['runtime_minutes'] ?? 0),
            'amount'=>floatval($payload['amount'] ?? 0),
            'currency'=>sanitize_text_field($payload['currency'] ?? CMSG_Plugin::settings()['paypal_currency']),
            'request_fingerprint'=>CMSG_Validation::build_request_fingerprint($payload),
        ]);
        return (int)$wpdb->insert_id;
    }
    public static function get($draft_id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $draft_id)); }
    public static function authorize($draft_id, $auth_id) { global $wpdb; return $wpdb->update(self::table(), ['status'=>'authorized','payment_authorization_id'=>$auth_id,'updated_at'=>current_time('mysql')], ['id'=>$draft_id,'status'=>'draft']); }
    public static function consume($draft_id) { global $wpdb; return $wpdb->update(self::table(), ['status'=>'consumed','updated_at'=>current_time('mysql')], ['id'=>$draft_id,'status'=>'authorized']); }
    public static function update_draft($draft_id, $data) { global $wpdb; $data['updated_at'] = current_time('mysql'); return $wpdb->update(self::table(), $data, ['id'=>$draft_id]); }
}
