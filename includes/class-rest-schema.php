<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_REST_Schema {

    public static function pagination_args() {
        return array(
            'page' => array(
                'default' => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => array(__CLASS__, 'validate_page'),
            ),
            'per_page' => array(
                'default' => 50,
                'sanitize_callback' => 'absint',
                'validate_callback' => array(__CLASS__, 'validate_per_page'),
            ),
        );
    }

    public static function list_query_args() {
        return array_merge(self::pagination_args(), array(
            'status' => array(
                'default' => 'publish',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => array(__CLASS__, 'validate_read_status'),
            ),
            'include_custom_fields' => array(
                'default' => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
        ));
    }

    public static function content_type_args() {
        return array_merge(self::list_query_args(), array(
            'post_type' => array(
                'required' => true,
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => array(__CLASS__, 'validate_post_type_slug'),
            ),
        ));
    }

    public static function products_args() {
        return array_merge(self::list_query_args(), array(
            'category' => array(
                'sanitize_callback' => 'sanitize_title',
            ),
        ));
    }

    public static function categories_args() {
        return array(
            'type' => array(
                'default' => 'all',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => array(__CLASS__, 'validate_category_type'),
            ),
            'hide_empty' => array(
                'default' => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
        );
    }

    public static function custom_fields_args() {
        return array(
            'post_id' => array(
                'required' => true,
                'sanitize_callback' => 'absint',
                'validate_callback' => array(__CLASS__, 'validate_post_id'),
            ),
        );
    }

    public static function create_post_args() {
        return array(
            'title' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'content' => array(
                'type' => 'string',
            ),
            'excerpt' => array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ),
            'status' => array(
                'default' => 'draft',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => array(__CLASS__, 'validate_write_status'),
            ),
            'post_type' => array(
                'default' => 'post',
                'sanitize_callback' => 'sanitize_key',
            ),
            'publish_date' => array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'categories' => array(
                'type' => 'array',
            ),
            'tags' => array(
                'type' => 'array',
            ),
            'featured_image_url' => array(
                'type' => 'string',
                'format' => 'uri',
                'sanitize_callback' => 'esc_url_raw',
            ),
            'meta_title' => array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'meta_description' => array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ),
            'redaquest_post_id' => array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'custom_fields' => array(
                'type' => 'object',
            ),
        );
    }

    public static function update_post_args() {
        $args = self::create_post_args();
        unset($args['title']['required']);
        $args['id'] = array(
            'required' => true,
            'sanitize_callback' => 'absint',
            'validate_callback' => array(__CLASS__, 'validate_post_id'),
        );
        return $args;
    }

    public static function by_redaquest_id_args() {
        return array(
            'redaquest_id' => array(
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    public static function validate_page($value) {
        return is_numeric($value) && (int) $value >= 1;
    }

    public static function validate_per_page($value) {
        $number = (int) $value;
        return $number >= 1 && $number <= 100;
    }

    public static function validate_read_status($value) {
        return in_array($value, array('publish', 'draft', 'pending', 'future', 'any'), true);
    }

    public static function validate_write_status($value) {
        return in_array($value, array('draft', 'pending', 'future', 'publish'), true);
    }

    public static function validate_post_type_slug($value) {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]+$/', $value);
    }

    public static function validate_category_type($value) {
        return in_array($value, array('all', 'post', 'product'), true);
    }

    public static function validate_post_id($value) {
        return is_numeric($value) && (int) $value > 0 && get_post((int) $value);
    }
}
