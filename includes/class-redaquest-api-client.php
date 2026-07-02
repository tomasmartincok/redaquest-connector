<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Api_Client {

    /**
     * @return array|\WP_Error
     */
    public static function request($action, $payload = array()) {
        $api_key = get_option('redaquest_api_key');
        if (empty($api_key)) {
            return new WP_Error('not_connected', __('Redaquest nie je pripojený.', 'redaquest-connector'));
        }

        $functions_url = class_exists('Redaquest_Connect')
            ? Redaquest_Connect::functions_url()
            : rtrim(apply_filters('redaquest_functions_url', 'https://fqmaerqsvskqbyigefbe.supabase.co/functions/v1'), '/');

        $anon_key = defined('REDAQUEST_SUPABASE_ANON_KEY')
            ? REDAQUEST_SUPABASE_ANON_KEY
            : apply_filters('redaquest_supabase_anon_key', '');

        $body = array_merge(array('action' => $action), $payload);
        $response = wp_remote_post(
            $functions_url . '/wp-article-approval',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Redaquest-Key' => $api_key,
                    'apikey' => $anon_key,
                ),
                'body' => wp_json_encode($body),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($status >= 400 || empty($data['success'])) {
            $message = isset($data['error']) ? $data['error'] : __('Redaquest požiadavka zlyhala.', 'redaquest-connector');
            return new WP_Error('redaquest_api_error', $message, array('status' => $status));
        }

        return $data;
    }

    /**
     * @return array|\WP_Error
     */
    public static function submit_for_approval($post_id) {
        return self::request('submit', array('wp_post_id' => (string) $post_id));
    }

    /**
     * @return array|\WP_Error
     */
    public static function get_approval_status($post_id) {
        return self::request('status', array('wp_post_id' => (string) $post_id));
    }
}
