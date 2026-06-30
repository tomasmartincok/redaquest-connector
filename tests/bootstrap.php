<?php

define('ABSPATH', __DIR__ . '/../');
define('REDAQUEST_VERSION', '2.7.0');
define('MINUTE_IN_SECONDS', 60);

$transients = array();
$redaquest_filters = array();

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = array();
        public function __construct($code = '', $message = '', $data = '') {
            $this->errors[$code][] = $message;
        }
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback) {
        global $redaquest_filters;
        $redaquest_filters[$tag] = $callback;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        global $redaquest_filters;
        if (isset($redaquest_filters[$tag])) {
            return call_user_func($redaquest_filters[$tag], $value);
        }
        return $value;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) {
        return strip_tags((string) $data);
    }
}

if (!function_exists('map_deep')) {
    function map_deep($value, $callback) {
        if (is_array($value)) {
            foreach ($value as $index => $item) {
                $value[$index] = map_deep($item, $callback);
            }
            return $value;
        }
        return call_user_func($callback, $value);
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key) {
        global $transients;
        return isset($transients[$key]) ? $transients[$key] : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration) {
        global $transients;
        $transients[$key] = $value;
        return true;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

require_once __DIR__ . '/../includes/class-custom-fields.php';
require_once __DIR__ . '/../includes/class-rate-limiter.php';
require_once __DIR__ . '/../includes/class-rest-schema.php';
