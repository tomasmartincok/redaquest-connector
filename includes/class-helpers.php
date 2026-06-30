<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Helpers {

    public static function get_enabled_post_types() {
        $enabled = get_option('redaquest_enabled_post_types', array('page', 'post'));

        if (!is_array($enabled)) {
            return array();
        }

        if (empty($enabled)) {
            return array();
        }

        $valid_types = array();
        foreach ($enabled as $type) {
            if (post_type_exists($type)) {
                $valid_types[] = $type;
            }
        }

        return $valid_types;
    }

    public static function is_woocommerce_sync_enabled() {
        if (!class_exists('WooCommerce')) {
            return false;
        }
        return (bool) get_option('redaquest_sync_woocommerce', 1);
    }

    public static function should_include_custom_fields($request_param = null) {
        if (null !== $request_param && '' !== $request_param) {
            return (bool) $request_param;
        }
        return (bool) get_option('redaquest_include_custom_fields', 0);
    }

    public static function is_post_type_enabled($post_type) {
        return in_array($post_type, self::get_enabled_post_types(), true);
    }

    public static function resolve_read_statuses($status) {
        $status = $status ? sanitize_key($status) : 'publish';
        if ($status === 'any') {
            return array('publish', 'draft', 'pending', 'future');
        }
        $allowed = array('publish', 'draft', 'pending', 'future');
        return in_array($status, $allowed, true) ? array($status) : array('publish');
    }

    public static function resolve_write_status($status, $default = 'draft') {
        $allowed = array('draft', 'pending', 'future', 'publish');
        $status = sanitize_key($status ?: $default);
        if (!in_array($status, $allowed, true)) {
            return new WP_Error('invalid_status', __('Neplatný stav príspevku.', 'redaquest-connector'), array('status' => 400));
        }
        return $status;
    }

    public static function sanitize_tags_input($tags) {
        if (is_array($tags)) {
            return array_filter(array_map('sanitize_text_field', $tags));
        }
        if (is_string($tags)) {
            return array_filter(array_map('sanitize_text_field', array_map('trim', explode(',', $tags))));
        }
        return array();
    }

    public static function get_default_author() {
        $default_author = get_option('redaquest_default_author');
        if ($default_author) {
            $user = get_user_by('id', (int) $default_author);
            if ($user && user_can($user, 'publish_posts')) {
                return (int) $user->ID;
            }
        }
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
        return !empty($admins) ? (int) $admins[0] : 1;
    }
}
