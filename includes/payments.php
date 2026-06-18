<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_Payments {
    public static function table() { global $wpdb; return $wpdb->prefix . 'cmsg_payment_authorizations'; }
    public static function issue_authorization($draft, $paypal_order_id, $paypal_capture_id, $kind) {
        global $wpdb; $table = self::table(); $now = current_time('mysql'); $s = CMSG_Plugin::settings();
        $token = wp_generate_password(48, false, false); $hash = hash('sha256', $token);
        $expires = gmdate('Y-m-d H:i:s', time() + (int)$s['payment_authorization_expiry_minutes'] * 60);
        $wpdb->insert($table, [
            'created_at'=>$now,'updated_at'=>$now,'status'=>'active',
            'user_id'=>CMSG_Validation::current_user_id_or_null(),
            'session_fingerprint'=>CMSG_Validation::get_session_fingerprint(),
            'draft_id'=>$draft->id,
            'authorization_token_hash'=>$hash,
            'paypal_order_id'=>$paypal_order_id,
            'paypal_capture_id'=>$paypal_capture_id,
            'amount'=>$draft->amount,
            'currency'=>$draft->currency,
            'kind'=>$kind,
            'request_fingerprint'=>$draft->request_fingerprint,
            'expires_at'=>$expires,
        ]);
        $id = (int)$wpdb->insert_id;
        if ($id) CMSG_Drafts::authorize($draft->id, $id);
        return ['id'=>$id,'token'=>$token,'expires_at'=>$expires];
    }
    public static function get_by_id($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id)); }
    public static function get_by_token($token) {
        global $wpdb; $hash = hash('sha256', (string)$token); return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE authorization_token_hash=%s", $hash));
    }
    public static function validate_authorization($token, $draft) {
        $auth = self::get_by_token($token); if (!$auth) return new WP_Error('cmsg_payment_invalid', 'Invalid or missing payment token.');
        if ($auth->status !== 'active') return new WP_Error('cmsg_payment_invalid', 'Payment authorization has already been used or revoked.');
        if (strtotime($auth->expires_at) < time()) return new WP_Error('cmsg_payment_invalid', 'Payment authorization has expired.');
        if ((int)$auth->draft_id !== (int)$draft->id) return new WP_Error('cmsg_payment_invalid', 'Payment authorization does not match this request.');
        if ((string)$auth->session_fingerprint !== (string)CMSG_Validation::get_session_fingerprint()) return new WP_Error('cmsg_payment_invalid', 'Payment authorization does not belong to this user or session.');
        if ((string)$auth->request_fingerprint !== (string)$draft->request_fingerprint) return new WP_Error('cmsg_payment_invalid', 'Payment authorization does not match this draft.');
        if (round((float)$auth->amount,2) !== round((float)$draft->amount,2) || (string)$auth->currency !== (string)$draft->currency) return new WP_Error('cmsg_payment_invalid', 'Payment authorization amount does not match this request.');
        return $auth;
    }
    public static function consume_authorization($auth_id) {
        global $wpdb;
        return $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET status='used', used_at=%s, updated_at=%s WHERE id=%d AND status='active'", current_time('mysql'), current_time('mysql'), $auth_id));
    }
}
