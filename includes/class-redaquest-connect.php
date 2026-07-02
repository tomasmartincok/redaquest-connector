<?php
/**
 * RedaQuest "Connect" flow (WordPress -> RedaQuest, OAuth-style).
 *
 * Lets a site admin link this WordPress site to a RedaQuest workspace. The site never
 * generates or types a key: the admin approves the connection inside RedaQuest, which
 * redirects back here with a one-time code that we exchange server-side for a bearer
 * token (stored in wp_options). That token authenticates later calls to the wp-bridge
 * edge function (social scheduling). The raw token never travels through the browser.
 *
 * The connection UI is rendered into the unified Redaquest Connector settings page
 * (Settings -> Redaquest Connector, Connection tab) via render_connection_section();
 * this class no longer registers its own admin menu.
 *
 * @package Redaquest_Connector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Connect {

    const OPT_TOKEN        = 'redaquest_studio_token';
    const OPT_WORKSPACE    = 'redaquest_studio_workspace';
    const OPT_WORKSPACE_NAME = 'redaquest_studio_workspace_name';
    const OPT_SCOPES       = 'redaquest_studio_scopes';
    const OPT_SITE         = 'redaquest_studio_site';
    const OPT_CONNECTED_AT = 'redaquest_studio_connected_at';

    /** Slug of the unified settings page that hosts the Connection tab. */
    const PAGE_SLUG = 'redaquest-connector';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'maybe_handle_callback'));
        add_action('admin_post_redaquest_disconnect', array($this, 'handle_disconnect'));
    }

    /** RedaQuest web app base URL (hosts the /connect/wordpress approval screen). */
    public static function app_url() {
        return rtrim(apply_filters('redaquest_app_url', 'https://app.redaquest.com'), '/');
    }

    /** RedaQuest edge functions base URL. */
    public static function functions_url() {
        return rtrim(apply_filters('redaquest_functions_url', 'https://fqmaerqsvskqbyigefbe.supabase.co/functions/v1'), '/');
    }

    public static function get_token() {
        return (string) get_option(self::OPT_TOKEN, '');
    }

    public static function is_connected() {
        return '' !== self::get_token();
    }

    private function admin_page_url() {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG);
    }

    /** Full RedaQuest approval-screen URL (site + return URL + a fresh CSRF state). Public so the
     *  Gutenberg panel can link straight to it (one click) instead of bouncing via the settings page. */
    public static function build_connect_url() {
        $args = array(
            'site'      => home_url(),
            'site_name' => get_bloginfo('name'),
            'redirect'  => admin_url('options-general.php?page=' . self::PAGE_SLUG),
            'state'     => wp_create_nonce('redaquest_connect_state'),
        );
        return self::app_url() . '/connect/wordpress?' . http_build_query($args);
    }

    /**
     * When RedaQuest redirects back to the connect page with ?code=&state=, verify the
     * state nonce and exchange the code for a bearer token (server-side).
     */
    public function maybe_handle_callback() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page routing only; the connection itself is verified by the 'state' nonce below.
        if (!isset($_GET['page']) || self::PAGE_SLUG !== sanitize_text_field(wp_unslash($_GET['page']))) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; verified by the 'state' nonce below.
        if (!isset($_GET['code'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 'state' IS the CSRF nonce; it is verified on the next line.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!wp_verify_nonce($state, 'redaquest_connect_state')) {
            add_settings_error('redaquest_connect', 'state', __('Invalid or expired connection state. Please try again.', 'redaquest-connector'), 'error');
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Request authenticity is established by the verified 'state' nonce above.
        $code = sanitize_text_field(wp_unslash($_GET['code']));

        $response = wp_remote_post(self::functions_url() . '/wp-connect', array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'action'  => 'exchange',
                'code'    => $code,
                'siteUrl' => home_url(),
            )),
        ));

        if (is_wp_error($response)) {
            add_settings_error('redaquest_connect', 'http', __('Could not reach RedaQuest. Please try again.', 'redaquest-connector'), 'error');
            return;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        $token = isset($body['token']) ? sanitize_text_field($body['token']) : '';
        if ('' === $token && !empty($body['apiKey'])) {
            $token = sanitize_text_field($body['apiKey']);
        }

        if (200 !== $status || '' === $token) {
            add_settings_error('redaquest_connect', 'exchange', __('The connection could not be completed. Please try again from RedaQuest.', 'redaquest-connector'), 'error');
            return;
        }

        $workspace_id = !empty($body['workspaceId']) ? sanitize_text_field($body['workspaceId']) : '';

        update_option(self::OPT_TOKEN, $token);
        update_option(self::OPT_WORKSPACE, $workspace_id);
        if (!empty($body['workspaceName'])) {
            update_option(self::OPT_WORKSPACE_NAME, sanitize_text_field($body['workspaceName']));
        }
        update_option(self::OPT_SITE, isset($body['siteUrl']) ? esc_url_raw($body['siteUrl']) : home_url());
        update_option(self::OPT_SCOPES, (isset($body['scopes']) && is_array($body['scopes'])) ? array_map('sanitize_text_field', $body['scopes']) : array());
        update_option(self::OPT_CONNECTED_AT, current_time('mysql'));

        // Unified Connect: RedaQuest also provisions the content-sync API key, so the user
        // never has to generate or paste one. Store it for the redaquest/v1 endpoints.
        if (!empty($body['apiKey'])) {
            update_option('redaquest_api_key', sanitize_text_field($body['apiKey']));
        }

        $enable_write = !isset($body['enableWrite']) || $body['enableWrite'];
        update_option('redaquest_enable_write', $enable_write ? 1 : 0);

        $default_author = (int) get_option('redaquest_default_author', 0);
        if (!$default_author) {
            update_option('redaquest_default_author', get_current_user_id());
        }

        wp_safe_redirect(add_query_arg('redaquest_connected', '1', $this->admin_page_url()));
        exit;
    }

    public function handle_disconnect() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'redaquest-connector'));
        }
        check_admin_referer('redaquest_disconnect');

        delete_option(self::OPT_TOKEN);
        delete_option(self::OPT_WORKSPACE);
        delete_option(self::OPT_WORKSPACE_NAME);
        delete_option(self::OPT_SCOPES);
        delete_option(self::OPT_SITE);
        delete_option(self::OPT_CONNECTED_AT);

        wp_safe_redirect(add_query_arg('redaquest_disconnected', '1', $this->admin_page_url()));
        exit;
    }

    /**
     * Render the RedaQuest connection card. Designed to be embedded inside the unified
     * settings page (no <div class="wrap">, no <h1>, no nested <form> — disconnect uses a
     * nonce-protected link so this can live inside the settings options form).
     */
    public function render_connection_section() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $connected         = self::is_connected();
        $workspace         = (string) get_option(self::OPT_WORKSPACE, '');
        $workspace_name    = (string) get_option(self::OPT_WORKSPACE_NAME, '');
        $connected_at      = (string) get_option(self::OPT_CONNECTED_AT, '');
        ?>
        <div class="card" style="max-width:none;">
            <h2><span class="dashicons dashicons-rest-api" style="color:#0073aa;"></span> <?php esc_html_e('Schedule social posts (RedaQuest Connect)', 'redaquest-connector'); ?></h2>

            <?php if ($connected) : ?>
                <p class="status-ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Connected', 'redaquest-connector'); ?></p>
                <?php if ($workspace_name) : ?>
                    <p>
                        <strong><?php esc_html_e('Workspace:', 'redaquest-connector'); ?></strong>
                        <?php echo esc_html($workspace_name); ?>
                    </p>
                <?php endif; ?>
                <p>
                    <?php esc_html_e('Workspace ID:', 'redaquest-connector'); ?>
                    <code><?php echo esc_html($workspace); ?></code>
                </p>
                <?php if ($connected_at) : ?>
                    <p class="description">
                        <?php
                        /* translators: %s: date and time the site was connected. */
                        echo esc_html(sprintf(__('Connected: %s', 'redaquest-connector'), $connected_at));
                        ?>
                    </p>
                <?php endif; ?>
                <p style="margin-top:12px;">
                    <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=redaquest_disconnect'), 'redaquest_disconnect')); ?>">
                        <?php esc_html_e('Disconnect', 'redaquest-connector'); ?>
                    </a>
                </p>
            <?php else : ?>
                <p><?php esc_html_e('Connect this site so you can generate and schedule social posts from the article editor. You will be redirected to RedaQuest to approve and pick a workspace; a RedaQuest account is required.', 'redaquest-connector'); ?></p>
                <p style="margin-top:12px;">
                    <a class="button button-primary button-hero" href="<?php echo esc_url(self::build_connect_url()); ?>">
                        <?php esc_html_e('Connect RedaQuest', 'redaquest-connector'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
