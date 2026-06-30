<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Admin_Ajax {

    public static function init() {
        add_action('wp_ajax_redaquest_test_connection', array(__CLASS__, 'test_connection'));
        add_action('wp_ajax_redaquest_disconnect', array(__CLASS__, 'disconnect'));
    }

    public static function test_connection() {
        check_ajax_referer('redaquest_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nemáte oprávnenie.', 'redaquest-connector')), 403);
        }

        $api_key = get_option('redaquest_api_key');
        if (!$api_key) {
            wp_send_json_error(array('message' => __('Najprv pripojte Redaquest.', 'redaquest-connector')));
        }

        $request = new WP_REST_Request('GET', '/redaquest/v1/verify');
        $request->set_header('X-Redaquest-Key', $api_key);
        $response = rest_do_request($request);

        if ($response->is_error()) {
            $error = $response->as_error();
            update_option('redaquest_last_test_at', current_time('mysql'), false);
            update_option('redaquest_last_test_result', array('success' => false), false);
            wp_send_json_error(array('message' => $error->get_error_message()));
        }

        $data = $response->get_data();
        update_option('redaquest_last_test_at', current_time('mysql'), false);
        update_option('redaquest_last_test_result', array('success' => true, 'data' => $data), false);

        wp_send_json_success($data);
    }

    public static function disconnect() {
        check_ajax_referer('redaquest_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nemáte oprávnenie.', 'redaquest-connector')), 403);
        }

        Redaquest_OAuth_Connect::disconnect();
        wp_send_json_success(array('message' => __('Pripojenie bolo zrušené.', 'redaquest-connector')));
    }
}
