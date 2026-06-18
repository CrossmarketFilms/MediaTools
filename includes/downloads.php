<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_Downloads {
    public static function table() { global $wpdb; return $wpdb->prefix . 'cmsg_download_grants'; }
    public static function issue_grant($job) {
        global $wpdb; $s = CMSG_Plugin::settings(); $token = wp_generate_password(48, false, false); $hash = hash('sha256', $token); $now=current_time('mysql');
        $expires = gmdate('Y-m-d H:i:s', time() + (int)$s['download_grant_expiry_minutes'] * 60);
        $wpdb->insert(self::table(), [
            'created_at'=>$now,'updated_at'=>$now,'status'=>'active','job_id'=>$job->id,
            'user_id'=>CMSG_Validation::current_user_id_or_null(),'session_fingerprint'=>CMSG_Validation::get_session_fingerprint(),
            'grant_token_hash'=>$hash,'expires_at'=>$expires,
        ]);
        return ['token'=>$token,'expires_at'=>$expires];
    }
    public static function validate_grant($job, $token) {
        global $wpdb; $hash = hash('sha256', (string)$token); $grant = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE grant_token_hash=%s", $hash));
        if (!$grant) return new WP_Error('cmsg_download_invalid', 'Invalid download grant.');
        if ((int)$grant->job_id !== (int)$job->id) return new WP_Error('cmsg_download_invalid', 'Download grant does not match this job.');
        if ($grant->status !== 'active') return new WP_Error('cmsg_download_invalid', 'Download grant has already been used or revoked.');
        if (strtotime($grant->expires_at) < time()) return new WP_Error('cmsg_download_invalid', 'Download grant has expired.');
        if ((string)$grant->session_fingerprint !== (string)CMSG_Validation::get_session_fingerprint()) return new WP_Error('cmsg_download_invalid', 'Download grant does not belong to this user or session.');
        return $grant;
    }
    public static function consume_grant($grant_id) { global $wpdb; return $wpdb->update(self::table(), ['status'=>'used','used_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')], ['id'=>$grant_id,'status'=>'active']); }
}


if (!class_exists('CMSG_Poster_Downloads')) {
final class CMSG_Poster_Downloads {
    public static function issue_grant($job_id, $asset_path) {
        $token = wp_generate_password(48, false, false);
        $hash = hash('sha256', $token);
        $dir = wp_upload_dir()['basedir'] . '/poster-grants';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $file = $dir . '/grant-' . $job_id . '-' . md5($hash) . '.json';
        file_put_contents($file, wp_json_encode([
            'job_id' => $job_id,
            'asset_path' => $asset_path,
            'expires_at' => time() + 600,
            'hash' => $hash,
        ]));
        return add_query_arg([
            'action' => 'cmsg_download_poster_asset',
            'job_id' => $job_id,
            'token' => $token,
        ], admin_url('admin-ajax.php'));
    }
}}
