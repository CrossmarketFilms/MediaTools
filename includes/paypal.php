<?php
if (!defined('ABSPATH')) { exit; }
final class CMSG_PayPal {
    private static function token() {
        $s = CMSG_Plugin::settings();
        if ($s['paypal_enabled'] !== '1' || empty($s['paypal_client_id']) || empty($s['paypal_client_secret'])) return false;
        $endpoint = $s['paypal_mode'] === 'sandbox' ? 'https://api-m.sandbox.paypal.com/v1/oauth2/token' : 'https://api-m.paypal.com/v1/oauth2/token';
        $response = wp_remote_post($endpoint, ['headers'=>['Authorization'=>'Basic '.base64_encode($s['paypal_client_id'].':'.$s['paypal_client_secret']),'Content-Type'=>'application/x-www-form-urlencoded'],'body'=>['grant_type'=>'client_credentials'],'timeout'=>45]);
        if (is_wp_error($response)) return false; $data = json_decode(wp_remote_retrieve_body($response), true); return !empty($data['access_token']) ? $data['access_token'] : false;
    }
    public static function create_order_for_draft($draft) {
        $s = CMSG_Plugin::settings(); $token = self::token(); if (!$token) return new WP_Error('cmsg_paypal_config', 'PayPal is not configured.');
        $endpoint = $s['paypal_mode'] === 'sandbox' ? 'https://api-m.sandbox.paypal.com/v2/checkout/orders' : 'https://api-m.paypal.com/v2/checkout/orders';
        $body = ['intent'=>'CAPTURE','purchase_units'=>[[ 'amount'=>['currency_code'=>strtoupper($draft->currency),'value'=>number_format((float)$draft->amount,2,'.','')], 'description'=>'Crossmarket draft #'.$draft->id ]]];
        $response = wp_remote_post($endpoint, ['headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'body'=>wp_json_encode($body),'timeout'=>45]);
        if (is_wp_error($response)) return new WP_Error('cmsg_paypal_error', $response->get_error_message());
        $code = wp_remote_retrieve_response_code($response); $data=json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($data['id'])) return new WP_Error('cmsg_paypal_error', 'PayPal order creation failed.');
        return $data['id'];
    }
    public static function capture_and_verify_order($draft, $order_id, $kind) {
        $s = CMSG_Plugin::settings(); $token = self::token(); if (!$token) return new WP_Error('cmsg_paypal_config', 'PayPal is not configured.');
        $endpoint = $s['paypal_mode'] === 'sandbox' ? 'https://api-m.sandbox.paypal.com/v2/checkout/orders/' . rawurlencode($order_id) . '/capture' : 'https://api-m.paypal.com/v2/checkout/orders/' . rawurlencode($order_id) . '/capture';
        $response = wp_remote_post($endpoint, ['headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'body'=>'{}','timeout'=>45]);
        if (is_wp_error($response)) return new WP_Error('cmsg_paypal_error', $response->get_error_message());
        $code = wp_remote_retrieve_response_code($response); $data=json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) return new WP_Error('cmsg_paypal_error', 'PayPal capture failed.');
        if (($data['status'] ?? '') !== 'COMPLETED') return new WP_Error('cmsg_paypal_error', 'PayPal payment was not completed.');
        $pu = $data['purchase_units'][0] ?? []; $capture = $pu['payments']['captures'][0] ?? [];
        if (($capture['status'] ?? '') !== 'COMPLETED') return new WP_Error('cmsg_paypal_error', 'PayPal capture status is not completed.');
        $amount = (float)($capture['amount']['value'] ?? 0); $currency = (string)($capture['amount']['currency_code'] ?? '');
        if (round($amount,2) !== round((float)$draft->amount,2) || strtoupper($currency) !== strtoupper($draft->currency)) return new WP_Error('cmsg_paypal_error', 'PayPal capture amount does not match the draft.');
        return CMSG_Payments::issue_authorization($draft, $order_id, (string)($capture['id'] ?? ''), $kind);
    }
}
