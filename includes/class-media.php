<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Media {

    public static function upload_from_url($url, $post_id = 0) {
        $url = esc_url_raw(trim($url));
        if (empty($url)) {
            return new WP_Error('invalid_image_url', __('Neplatná URL obrázka.', 'redaquest-connector'), array('status' => 400));
        }

        if (!wp_http_validate_url($url)) {
            return new WP_Error('invalid_image_url', __('Neplatná alebo zakázaná URL obrázka.', 'redaquest-connector'), array('status' => 400));
        }

        $parsed = wp_parse_url($url);
        if (empty($parsed['scheme']) || strtolower($parsed['scheme']) !== 'https') {
            return new WP_Error('insecure_image_url', __('URL obrázka musí používať HTTPS.', 'redaquest-connector'), array('status' => 400));
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $max_bytes = (int) apply_filters('redaquest_max_image_bytes', 10 * MB_IN_BYTES);
        $timeout = (int) apply_filters('redaquest_image_download_timeout', 30);

        $tmp = download_url($url, $timeout);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        if (!file_exists($tmp)) {
            return new WP_Error('image_download_failed', __('Nepodarilo sa stiahnuť obrázok.', 'redaquest-connector'), array('status' => 400));
        }

        $file_size = filesize($tmp);
        if (false === $file_size || $file_size > $max_bytes) {
            wp_delete_file($tmp);
            return new WP_Error('image_too_large', __('Obrázok prekračuje povolenú veľkosť.', 'redaquest-connector'), array('status' => 400));
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        $filename = $path ? basename($path) : '';
        if (empty($filename)) {
            $filename = 'redaquest-image.jpg';
        }

        $filetype = wp_check_filetype_and_ext($tmp, $filename);
        $allowed_mimes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
        if (empty($filetype['type']) || !in_array($filetype['type'], $allowed_mimes, true)) {
            wp_delete_file($tmp);
            return new WP_Error('invalid_image_type', __('Nepodporovaný typ obrázka.', 'redaquest-connector'), array('status' => 400));
        }

        if (empty($filetype['ext'])) {
            $filename .= '.jpg';
        }

        $file_array = array(
            'name' => sanitize_file_name($filename),
            'tmp_name' => $tmp,
            'type' => $filetype['type'],
        );

        $attachment_id = media_handle_sideload($file_array, $post_id);
        if (file_exists($tmp)) {
            wp_delete_file($tmp);
        }
        return $attachment_id;
    }
}
