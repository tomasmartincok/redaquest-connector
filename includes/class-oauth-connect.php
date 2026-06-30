<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_OAuth_Connect {

    const STATE_TRANSIENT_PREFIX = 'redaquest_oauth_state_';

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'maybe_handle_callback'));
    }

    public static function is_connected() {
        return (bool) get_option('redaquest_api_key');
    }

    public static function get_connection_meta() {
        $meta = get_option('redaquest_connection_meta', array());
        return is_array($meta) ? $meta : array();
    }

    public static function get_connect_url() {
        $state = wp_generate_password(32, false);
        set_transient(self::STATE_TRANSIENT_PREFIX . $state, 1, 15 * MINUTE_IN_SECONDS);

        $params = array(
            'site' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'redirect' => admin_url('options-general.php?page=redaquest-connector'),
            'state' => $state,
        );

        if (class_exists('WooCommerce')) {
            $params['woo'] = '1';
        }

        return REDAQUEST_APP_URL . '/connect/wordpress?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function maybe_handle_callback() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['page']) || 'redaquest-connector' !== $_GET['page']) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if ('' === $code) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if ('' === $state || !get_transient(self::STATE_TRANSIENT_PREFIX . $state)) {
            self::redirect_with_notice('error', __('Neplatný stav pripojenia. Skúste to znova.', 'redaquest-connector'));
        }

        delete_transient(self::STATE_TRANSIENT_PREFIX . $state);

        $result = self::exchange_code($code, $state);
        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }

        update_option('redaquest_api_key', $result['api_key'], false);
        update_option('redaquest_connection_meta', array(
            'workspace_name' => $result['workspace_name'],
            'integration_name' => $result['integration_name'],
            'connected_at' => current_time('mysql'),
            'method' => 'oauth',
        ), false);
        update_option('redaquest_setup_oauth_done', 1);

        self::redirect_with_notice('success', __('Redaquest bol úspešne pripojený.', 'redaquest-connector'));
    }

    public static function exchange_code($code, $state = '') {
        $body = array(
            'action' => 'exchange',
            'code' => $code,
            'siteUrl' => home_url('/'),
        );

        if ($state) {
            $body['state'] = $state;
        }

        $response = wp_remote_post(
            REDAQUEST_FUNCTIONS_URL . '/wp-connect',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'apikey' => REDAQUEST_SUPABASE_ANON_KEY,
                ),
                'body' => wp_json_encode($body),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('connect_failed', __('Nepodarilo sa spojiť s Redaquest.', 'redaquest-connector'));
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 400 || empty($data['apiKey'])) {
            $message = isset($data['error']) ? $data['error'] : __('Výmena kódu zlyhala.', 'redaquest-connector');
            return new WP_Error('connect_failed', sanitize_text_field($message));
        }

        return array(
            'api_key' => sanitize_text_field($data['apiKey']),
            'workspace_name' => sanitize_text_field($data['workspaceName'] ?? ''),
            'integration_name' => sanitize_text_field($data['integrationName'] ?? ''),
        );
    }

    public static function disconnect() {
        delete_option('redaquest_api_key');
        delete_option('redaquest_connection_meta');
        delete_option('redaquest_last_test_result');
        delete_option('redaquest_last_test_at');
    }

    private static function redirect_with_notice($type, $message) {
        set_transient('redaquest_admin_flash_' . get_current_user_id(), array(
            'type' => $type,
            'message' => $message,
        ), 30);

        wp_safe_redirect(admin_url('options-general.php?page=redaquest-connector'));
        exit;
    }

    public static function consume_flash_notice() {
        $flash = get_transient('redaquest_admin_flash_' . get_current_user_id());
        if (!$flash || !is_array($flash)) {
            return null;
        }
        delete_transient('redaquest_admin_flash_' . get_current_user_id());
        return $flash;
    }
}
