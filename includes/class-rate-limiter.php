<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Rate_Limiter {

    /**
     * @param string $api_key
     * @return true|WP_Error
     */
    public static function check($api_key) {
        $limit = (int) apply_filters('redaquest_rate_limit_per_minute', 120);
        if ($limit <= 0) {
            return true;
        }

        $bucket = 'redaquest_rl_' . md5($api_key . '|' . self::get_client_ip());
        $count = (int) get_transient($bucket);

        if ($count >= $limit) {
            return new WP_Error(
                'rate_limit_exceeded',
                __('Prekročený limit požiadaviek. Skúste znova o chvíľu.', 'redaquest-connector'),
                array('status' => 429)
            );
        }

        set_transient($bucket, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    private static function get_client_ip() {
        $forwarded = filter_input(INPUT_SERVER, 'HTTP_X_FORWARDED_FOR', FILTER_UNSAFE_RAW);
        if (is_string($forwarded) && $forwarded !== '') {
            $parts = explode(',', wp_unslash($forwarded));
            return sanitize_text_field(trim($parts[0]));
        }

        $remote_addr = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_UNSAFE_RAW);
        if (is_string($remote_addr) && $remote_addr !== '') {
            return sanitize_text_field(wp_unslash($remote_addr));
        }

        return 'unknown';
    }
}
