<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Custom_Fields {

    /**
     * Sensitive meta key substrings — never export or accept via API.
     */
    private static function sensitive_patterns() {
        return apply_filters('redaquest_sensitive_meta_patterns', array(
            'password',
            'secret',
            'api_key',
            'api-key',
            'token',
            'private_key',
            'credential',
            'auth_key',
            'license_key',
        ));
    }

    public static function is_exportable_meta_key($key) {
        if (strpos($key, '_') === 0) {
            return false;
        }

        $key_lower = strtolower($key);
        foreach (self::sensitive_patterns() as $pattern) {
            if (strpos($key_lower, $pattern) !== false) {
                return false;
            }
        }

        return (bool) apply_filters('redaquest_is_exportable_meta_key', true, $key);
    }

    public static function get_all($post_id) {
        $fields = array();

        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post_id);
            if ($acf_fields && is_array($acf_fields)) {
                foreach ($acf_fields as $key => $value) {
                    if (!self::is_exportable_meta_key($key)) {
                        continue;
                    }
                    $fields['acf_' . $key] = array(
                        'source' => 'acf',
                        'key' => $key,
                        'value' => $value,
                    );
                }
            }
        }

        if (class_exists('RWMB_Loader')) {
            $meta_boxes = rwmb_get_registry('meta_box')->all();
            foreach ($meta_boxes as $meta_box) {
                if (!isset($meta_box->fields)) {
                    continue;
                }
                foreach ($meta_box->fields as $field) {
                    if (!isset($field['id']) || !self::is_exportable_meta_key($field['id'])) {
                        continue;
                    }
                    $value = rwmb_meta($field['id'], array(), $post_id);
                    if ($value !== '' && $value !== null) {
                        $fields['mb_' . $field['id']] = array(
                            'source' => 'meta_box',
                            'key' => $field['id'],
                            'label' => isset($field['name']) ? $field['name'] : $field['id'],
                            'value' => $value,
                        );
                    }
                }
            }
        }

        if (function_exists('pods')) {
            $post = get_post($post_id);
            if ($post) {
                $pod = pods($post->post_type, $post_id);
                if ($pod && !is_wp_error($pod)) {
                    foreach ($pod->fields() as $field_name => $field_data) {
                        if (!self::is_exportable_meta_key($field_name)) {
                            continue;
                        }
                        $value = $pod->field($field_name);
                        if ($value !== '' && $value !== null) {
                            $fields['pods_' . $field_name] = array(
                                'source' => 'pods',
                                'key' => $field_name,
                                'label' => isset($field_data['label']) ? $field_data['label'] : $field_name,
                                'value' => $value,
                            );
                        }
                    }
                }
            }
        }

        $all_meta = get_post_meta($post_id);
        $exclude_keys = array('_edit_lock', '_edit_last', '_thumbnail_id', '_wp_page_template', '_redaquest_post_id');

        foreach ($all_meta as $key => $values) {
            if (!self::is_exportable_meta_key($key) || in_array($key, $exclude_keys, true)) {
                continue;
            }
            if (isset($fields['acf_' . $key]) || isset($fields['mb_' . $key]) || isset($fields['pods_' . $key])) {
                continue;
            }

            $fields['meta_' . $key] = array(
                'source' => 'native',
                'key' => $key,
                'value' => count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values),
            );
        }

        return apply_filters('redaquest_custom_fields_export', $fields, $post_id);
    }

    public static function save_fields($post_id, $fields) {
        if (!is_array($fields)) {
            return;
        }

        foreach ($fields as $key => $value) {
            $key = (string) $key;

            if (strpos($key, 'acf_') === 0 && function_exists('update_field')) {
                $acf_key = substr($key, 4);
                if (self::is_exportable_meta_key($acf_key)) {
                    update_field($acf_key, self::sanitize_value($value), $post_id);
                }
                continue;
            }

            $meta_key = self::normalize_meta_key($key);
            if (!$meta_key || !self::is_exportable_meta_key($meta_key)) {
                continue;
            }

            update_post_meta($post_id, $meta_key, self::sanitize_value($value));
        }
    }

    private static function normalize_meta_key($key) {
        $key = preg_replace('/^(meta_|mb_|pods_)/', '', $key);
        return sanitize_key($key);
    }

    public static function sanitize_value($value) {
        if (is_array($value)) {
            return map_deep($value, array(__CLASS__, 'sanitize_scalar'));
        }
        return self::sanitize_scalar($value);
    }

    public static function sanitize_scalar($value) {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        if (strpos($value, '<') !== false && strpos($value, '>') !== false) {
            return wp_kses_post($value);
        }
        return sanitize_text_field($value);
    }
}
