<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Post_Metabox {

    public static function init() {
        add_action('add_meta_boxes', array(__CLASS__, 'register_metabox'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        Redaquest_Post_Metabox_Ajax::init();
    }

    public static function register_metabox() {
        $enabled_types = Redaquest_Helpers::get_enabled_post_types();
        foreach ($enabled_types as $post_type) {
            if (in_array($post_type, array('page', 'attachment'), true)) {
                continue;
            }
            add_meta_box(
                'redaquest-approval',
                __('Redaquest schválenie', 'redaquest-connector'),
                array(__CLASS__, 'render_metabox'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    public static function enqueue_scripts($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        wp_enqueue_script(
            'redaquest-post-metabox',
            REDAQUEST_PLUGIN_URL . 'admin/js/post-metabox.js',
            array('jquery'),
            REDAQUEST_VERSION,
            true
        );

        wp_localize_script('redaquest-post-metabox', 'redaquestPostMetabox', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('redaquest_post_metabox'),
            'strings' => array(
                'submitting' => __('Odosielam na schválenie…', 'redaquest-connector'),
                'submitSuccess' => __('Článok bol odoslaný na schválenie v Redaqueste.', 'redaquest-connector'),
                'submitFail' => __('Odoslanie zlyhalo.', 'redaquest-connector'),
                'writeDisabled' => __('Povolenie zápisu musí byť zapnuté v nastaveniach Redaquest.', 'redaquest-connector'),
                'notConnected' => __('Najprv pripojte Redaquest.', 'redaquest-connector'),
            ),
        ));
    }

    public static function render_metabox($post) {
        if (!Redaquest_OAuth_Connect::is_connected()) {
            echo '<p class="description">' . esc_html__('Najprv pripojte Redaquest v Nastaveniach.', 'redaquest-connector') . '</p>';
            return;
        }

        $enable_write = (int) get_option('redaquest_enable_write', 0);
        $status = self::get_cached_status($post->ID);
        $status_label = self::format_status_label($status);
        ?>
        <div class="redaquest-metabox" data-post-id="<?php echo esc_attr($post->ID); ?>">
            <p><strong><?php esc_html_e('Stav schválenia:', 'redaquest-connector'); ?></strong>
                <span id="redaquest-approval-status"><?php echo esc_html($status_label); ?></span>
            </p>
            <?php if (!$enable_write) : ?>
                <p class="description"><?php esc_html_e('Pre publikovanie po schválení zapnite zápis v Nastaveniach → Redaquest → Publikovanie.', 'redaquest-connector'); ?></p>
            <?php endif; ?>
            <p>
                <button type="button" class="button button-primary" id="redaquest-submit-approval">
                    <?php esc_html_e('Odoslať na schválenie', 'redaquest-connector'); ?>
                </button>
            </p>
            <p class="description">
                <?php esc_html_e('Klient uvidí článok v Redaqueste a môže ho schváliť alebo okomentovať.', 'redaquest-connector'); ?>
            </p>
            <div id="redaquest-approval-message" aria-live="polite"></div>
        </div>
        <?php
    }

    private static function get_cached_status($post_id) {
        $cached = get_post_meta($post_id, '_redaquest_approval_status', true);
        if (!empty($cached)) {
            return sanitize_key($cached);
        }
        return '';
    }

    private static function format_status_label($status) {
        $labels = array(
            'waiting' => __('Čaká na schválenie', 'redaquest-connector'),
            'approved' => __('Schválené', 'redaquest-connector'),
            'rejected' => __('Zamietnuté', 'redaquest-connector'),
        );
        if (isset($labels[$status])) {
            return $labels[$status];
        }
        return __('Neodoslané', 'redaquest-connector');
    }
}

class Redaquest_Post_Metabox_Ajax {

    public static function init() {
        add_action('wp_ajax_redaquest_submit_approval', array(__CLASS__, 'submit_approval'));
        add_action('wp_ajax_redaquest_approval_status', array(__CLASS__, 'approval_status'));
    }

    public static function submit_approval() {
        check_ajax_referer('redaquest_post_metabox', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Nemáte oprávnenie.', 'redaquest-connector')), 403);
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(array('message' => __('Príspevok neexistuje.', 'redaquest-connector')));
        }

        $result = Redaquest_Api_Client::submit_for_approval($post_id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        update_post_meta($post_id, '_redaquest_approval_status', 'waiting');

        wp_send_json_success(array(
            'message' => __('Článok bol odoslaný na schválenie.', 'redaquest-connector'),
            'approval_status' => 'waiting',
        ));
    }

    public static function approval_status() {
        check_ajax_referer('redaquest_post_metabox', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Nemáte oprávnenie.', 'redaquest-connector')), 403);
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(array('message' => __('Chýba ID príspevku.', 'redaquest-connector')));
        }

        $result = Redaquest_Api_Client::get_approval_status($post_id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        if (!empty($result['approval_status'])) {
            update_post_meta($post_id, '_redaquest_approval_status', sanitize_key($result['approval_status']));
        }

        wp_send_json_success($result);
    }
}
