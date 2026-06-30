<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Admin_Settings {

    public function enqueue_scripts($hook) {
        if ('settings_page_redaquest-connector' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'redaquest-admin-settings',
            REDAQUEST_PLUGIN_URL . 'admin/css/settings.css',
            array(),
            REDAQUEST_VERSION
        );

        wp_enqueue_script(
            'redaquest-admin',
            REDAQUEST_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            REDAQUEST_VERSION,
            true
        );

        wp_localize_script('redaquest-admin', 'redaquestAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('redaquest_admin'),
            'strings' => array(
                'testing' => __('Testujem pripojenie…', 'redaquest-connector'),
                'testOk' => __('Pripojenie funguje.', 'redaquest-connector'),
                'testFail' => __('Test pripojenia zlyhal.', 'redaquest-connector'),
                'copied' => __('Skopírované do schránky.', 'redaquest-connector'),
                'disconnectConfirm' => __('Naozaj chcete odpojiť Redaquest z tohto webu?', 'redaquest-connector'),
                'disconnecting' => __('Odpájam…', 'redaquest-connector'),
            ),
        ));
    }

    public function register_menu() {
        add_options_page(
            __('Redaquest Connector', 'redaquest-connector'),
            __('Redaquest', 'redaquest-connector'),
            'manage_options',
            'redaquest-connector',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('redaquest_settings', 'redaquest_api_key', array('sanitize_callback' => array($this, 'sanitize_api_key')));
        register_setting('redaquest_settings', 'redaquest_enabled_post_types', array('sanitize_callback' => array($this, 'sanitize_post_types')));
        register_setting('redaquest_settings', 'redaquest_enable_write', array('sanitize_callback' => 'absint'));
        register_setting('redaquest_settings', 'redaquest_default_author', array('sanitize_callback' => 'absint'));
        register_setting('redaquest_settings', 'redaquest_sync_woocommerce', array('sanitize_callback' => 'absint'));
        register_setting('redaquest_settings', 'redaquest_include_custom_fields', array('sanitize_callback' => 'absint'));
    }

    public function sanitize_api_key($value) {
        $value = sanitize_text_field($value);
        if (empty($value)) {
            return get_option('redaquest_api_key');
        }
        set_transient('redaquest_notice_' . get_current_user_id(), 'api_key_updated', 30);
        return $value;
    }

    public function sanitize_post_types($value) {
        $present = filter_input(INPUT_POST, 'redaquest_enabled_post_types_present', FILTER_VALIDATE_INT);
        if (1 === $present) {
            if (!is_array($value)) {
                set_transient('redaquest_notice_' . get_current_user_id(), 'post_types_empty', 30);
                return array();
            }
            $sanitized = array_values(array_filter(array_map('sanitize_key', $value)));
            set_transient('redaquest_notice_' . get_current_user_id(), 'post_types_updated', 30);
            return $sanitized;
        }
        if (!is_array($value)) {
            return get_option('redaquest_enabled_post_types', array('page', 'post'));
        }
        return array_map('sanitize_key', $value);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_key = get_option('redaquest_api_key');
        $is_connected = Redaquest_OAuth_Connect::is_connected();
        $connection_meta = Redaquest_OAuth_Connect::get_connection_meta();
        $enabled_types = Redaquest_Helpers::get_enabled_post_types();
        $enable_write = get_option('redaquest_enable_write', 0);
        $sync_woocommerce = get_option('redaquest_sync_woocommerce', 1);
        $include_custom_fields = get_option('redaquest_include_custom_fields', 0);
        $default_author = get_option('redaquest_default_author');
        $available_types = get_post_types(array('public' => true), 'objects');
        $exclude = array('attachment', 'revision', 'nav_menu_item', 'product');
        $users = get_users(array('role__in' => array('administrator', 'editor', 'author')));
        $woo_active = class_exists('WooCommerce');
        $acf_active = function_exists('get_fields');
        $last_test = get_option('redaquest_last_test_result', array());
        $endpoint_url = get_rest_url(null, 'redaquest/v1/');
        $connect_url = Redaquest_OAuth_Connect::get_connect_url();

        ?>
        <div class="wrap redaquest-settings">
            <div class="redaquest-admin-notices">
                <?php $this->render_notices(); ?>
            </div>
            <div class="header-bar">
                <h1><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e('Redaquest Connector', 'redaquest-connector'); ?></h1>
                <span class="version-badge">v<?php echo esc_html(REDAQUEST_VERSION); ?></span>
            </div>

            <?php $this->render_setup_checklist($is_connected, $enabled_types, $last_test); ?>

            <nav class="nav-tab-wrapper">
                <a href="#" class="nav-tab nav-tab-active" data-tab="connection"><?php esc_html_e('Pripojenie', 'redaquest-connector'); ?></a>
                <a href="#" class="nav-tab" data-tab="sync"><?php esc_html_e('Synchronizácia', 'redaquest-connector'); ?></a>
                <a href="#" class="nav-tab" data-tab="publish"><?php esc_html_e('Publikovanie', 'redaquest-connector'); ?></a>
                <a href="#" class="nav-tab" data-tab="api"><?php esc_html_e('Diagnostika', 'redaquest-connector'); ?></a>
            </nav>

            <form method="post" action="options.php" id="redaquest-settings-form">
                <?php settings_fields('redaquest_settings'); ?>

                <div id="tab-connection" class="tab-content active">
                    <?php $this->render_connection_tab($is_connected, $connection_meta, $connect_url, $api_key, $enable_write, $endpoint_url, $last_test, $woo_active, $acf_active); ?>
                </div>

                <div id="tab-sync" class="tab-content">
                    <?php $this->render_sync_tab($available_types, $exclude, $enabled_types, $woo_active, $sync_woocommerce, $include_custom_fields, $acf_active); ?>
                </div>

                <div id="tab-publish" class="tab-content">
                    <?php $this->render_publish_tab($enable_write, $default_author, $users); ?>
                </div>

                <?php submit_button(__('Uložiť nastavenia', 'redaquest-connector')); ?>
            </form>

            <div id="tab-api" class="tab-content">
                <?php $this->render_diagnostics_tab($endpoint_url, $last_test, $is_connected); ?>
            </div>
        </div>
        <?php
    }

    private function render_notices() {
        $shown = false;

        $flash = Redaquest_OAuth_Connect::consume_flash_notice();
        if ($flash) {
            $class = ('success' === $flash['type']) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($flash['message']) . '</p></div>';
            $shown = true;
        }

        $notice = get_transient('redaquest_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('redaquest_notice_' . get_current_user_id());
            $messages = array(
                'api_key_updated' => __('API kľúč bol aktualizovaný. Odporúčame otestovať pripojenie.', 'redaquest-connector'),
                'post_types_updated' => __('Typy obsahu na synchronizáciu boli aktualizované.', 'redaquest-connector'),
                'post_types_empty' => __('Upozornenie: nie je vybraný žiadny typ obsahu — synchronizácia nebude posielať články ani stránky.', 'redaquest-connector'),
            );
            if (isset($messages[$notice])) {
                $class = ('post_types_empty' === $notice) ? 'notice-warning' : 'notice-success';
                echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
                $shown = true;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$shown && isset($_GET['settings-updated']) && 'true' === $_GET['settings-updated']) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Nastavenia boli uložené.', 'redaquest-connector') . '</p></div>';
        }
    }

    private function render_setup_checklist($is_connected, $enabled_types, $last_test) {
        $tested = !empty($last_test['success']);
        $has_types = count($enabled_types) > 0;
        $steps = array(
            array('done' => true, 'label' => __('Plugin je aktívny', 'redaquest-connector')),
            array('done' => $is_connected, 'label' => __('Redaquest je pripojený', 'redaquest-connector')),
            array('done' => $tested, 'label' => __('Pripojenie bolo otestované', 'redaquest-connector')),
            array('done' => $has_types, 'label' => __('Vybraný aspoň jeden typ obsahu', 'redaquest-connector')),
        );
        $done_count = count(array_filter($steps, function ($s) { return $s['done']; }));
        if ($done_count === count($steps)) {
            return;
        }
        ?>
        <div class="card setup-checklist">
            <h2><?php esc_html_e('Prvé nastavenie', 'redaquest-connector'); ?></h2>
            <p><?php esc_html_e('Dokončite tieto kroky pre plnú synchronizáciu s Redaquest.', 'redaquest-connector'); ?></p>
            <ul class="checklist">
                <?php foreach ($steps as $step) : ?>
                    <li class="<?php echo $step['done'] ? 'done' : 'pending'; ?>">
                        <span class="dashicons <?php echo $step['done'] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
                        <?php echo esc_html($step['label']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    private function render_connection_tab($is_connected, $connection_meta, $connect_url, $api_key, $enable_write, $endpoint_url, $last_test, $woo_active, $acf_active) {
        ?>
        <div class="two-column">
            <div class="card">
                <h2><?php esc_html_e('Stav pripojenia', 'redaquest-connector'); ?></h2>
                <?php if ($is_connected) : ?>
                    <p class="status-ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Pripojené k Redaquest', 'redaquest-connector'); ?></p>
                    <?php if (!empty($connection_meta['workspace_name'])) : ?>
                        <p><strong><?php esc_html_e('Workspace:', 'redaquest-connector'); ?></strong> <?php echo esc_html($connection_meta['workspace_name']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($connection_meta['connected_at'])) : ?>
                        <p class="description"><?php esc_html_e('Pripojené:', 'redaquest-connector'); ?> <?php echo esc_html($connection_meta['connected_at']); ?></p>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="status-error"><span class="dashicons dashicons-warning"></span> <?php esc_html_e('Nie je pripojené k Redaquest', 'redaquest-connector'); ?></p>
                <?php endif; ?>

                <div class="badge-row">
                    <span class="sync-badge <?php echo $enable_write ? 'badge-write' : 'badge-read'; ?>">
                        <?php echo $enable_write
                            ? esc_html__('Obojsmerná sync (čítanie + zápis)', 'redaquest-connector')
                            : esc_html__('Iba čítanie', 'redaquest-connector'); ?>
                    </span>
                </div>

                <div class="action-row">
                    <?php if ($is_connected) : ?>
                        <button type="button" class="button button-primary" id="redaquest-test-connection"><?php esc_html_e('Otestovať pripojenie', 'redaquest-connector'); ?></button>
                        <button type="button" class="button" id="redaquest-disconnect"><?php esc_html_e('Odpojiť', 'redaquest-connector'); ?></button>
                    <?php else : ?>
                        <a href="<?php echo esc_url($connect_url); ?>" class="button button-primary button-hero"><?php esc_html_e('Pripojiť k Redaquest', 'redaquest-connector'); ?></a>
                    <?php endif; ?>
                </div>

                <div id="redaquest-test-result" class="test-result" aria-live="polite"></div>

                <p style="margin-top: 15px;"><strong><?php esc_html_e('API Endpoint:', 'redaquest-connector'); ?></strong><br>
                    <code id="redaquest-endpoint-url"><?php echo esc_url($endpoint_url); ?></code>
                    <button type="button" class="button button-small" id="redaquest-copy-endpoint"><?php esc_html_e('Kopírovať', 'redaquest-connector'); ?></button>
                </p>

                <div class="plugin-detect-row">
                    <?php if ($woo_active) : ?>
                        <span class="status-ok"><span class="dashicons dashicons-yes"></span> WooCommerce</span>
                    <?php else : ?>
                        <span class="status-warning"><span class="dashicons dashicons-minus"></span> WooCommerce</span>
                    <?php endif; ?>
                    <?php if ($acf_active) : ?>
                        <span class="status-ok"><span class="dashicons dashicons-yes"></span> ACF</span>
                    <?php else : ?>
                        <span class="status-warning"><span class="dashicons dashicons-minus"></span> ACF</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Pripojenie cez Redaquest', 'redaquest-connector'); ?></h2>
                <div class="info-box">
                    <?php esc_html_e('Kliknite na „Pripojiť k Redaquest“, prihláste sa a vyberte workspace. API kľúč sa nastaví automaticky — nemusíte ho kopírovať ručne.', 'redaquest-connector'); ?>
                </div>
                <ol class="connect-steps">
                    <li><?php esc_html_e('Kliknite „Pripojiť k Redaquest“', 'redaquest-connector'); ?></li>
                    <li><?php esc_html_e('Prihláste sa do Redaquest a vyberte workspace', 'redaquest-connector'); ?></li>
                    <li><?php esc_html_e('Po návrate otestujte pripojenie', 'redaquest-connector'); ?></li>
                </ol>

                <details class="advanced-section">
                    <summary><?php esc_html_e('Pokročilé: manuálny API kľúč', 'redaquest-connector'); ?></summary>
                    <p class="description"><?php esc_html_e('Len ak nepoužívate prihlásenie cez Redaquest (napr. vlastná integrácia).', 'redaquest-connector'); ?></p>
                    <?php if ($api_key) : ?>
                        <div class="api-key-field" id="api-key-display">
                            <input type="text" value="<?php echo esc_attr(substr($api_key, 0, 8)); ?>••••••••••••••••" class="regular-text" readonly disabled>
                            <button type="button" class="button" onclick="document.getElementById('api-key-display').style.display='none'; document.getElementById('api-key-input').style.display='block';">
                                <?php esc_html_e('Zmeniť kľúč', 'redaquest-connector'); ?>
                            </button>
                        </div>
                        <div id="api-key-input" style="display: none;">
                            <input type="password" id="redaquest_api_key" name="redaquest_api_key" value="" class="regular-text" placeholder="<?php esc_attr_e('Zadajte nový API kľúč', 'redaquest-connector'); ?>">
                        </div>
                    <?php else : ?>
                        <input type="password" id="redaquest_api_key" name="redaquest_api_key" value="" class="regular-text" placeholder="<?php esc_attr_e('Vložte API kľúč z Redaquest', 'redaquest-connector'); ?>">
                    <?php endif; ?>
                </details>
            </div>
        </div>
        <?php
    }

    private function render_sync_tab($available_types, $exclude, $enabled_types, $woo_active, $sync_woocommerce, $include_custom_fields, $acf_active) {
        ?>
        <div class="card">
            <h2><?php esc_html_e('Typy obsahu na synchronizáciu', 'redaquest-connector'); ?></h2>
            <p><?php esc_html_e('Vyberte typy obsahu, ktoré chcete synchronizovať s Redaquest.', 'redaquest-connector'); ?></p>

            <?php if (empty($enabled_types)) : ?>
                <div class="warning-box">
                    <?php esc_html_e('Nie je vybraný žiadny typ obsahu. Redaquest nebude synchronizovať stránky ani články, kým aspoň jeden nezaškrtnete.', 'redaquest-connector'); ?>
                </div>
            <?php endif; ?>

            <div class="post-type-actions">
                <button type="button" class="button button-small" id="redaquest-select-all-types"><?php esc_html_e('Vybrať všetko', 'redaquest-connector'); ?></button>
                <button type="button" class="button button-small" id="redaquest-deselect-all-types"><?php esc_html_e('Zrušiť výber', 'redaquest-connector'); ?></button>
            </div>

            <input type="hidden" name="redaquest_enabled_post_types_present" value="1">
            <div class="post-type-list">
                <?php foreach ($available_types as $pt) : ?>
                    <?php if (in_array($pt->name, $exclude, true)) continue; ?>
                    <div class="post-type-item <?php echo in_array($pt->name, $enabled_types, true) ? 'checked' : ''; ?>">
                        <label>
                            <input type="checkbox" name="redaquest_enabled_post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $enabled_types, true)); ?>>
                            <strong><?php echo esc_html($pt->label); ?></strong>
                            <?php if (!$pt->_builtin) : ?><span class="cpt-badge">CPT</span><?php endif; ?>
                            <?php $count = wp_count_posts($pt->name); ?>
                            <span class="count-badge">(<?php echo isset($count->publish) ? (int) $count->publish : 0; ?>)</span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2><?php esc_html_e('Custom fields', 'redaquest-connector'); ?></h2>
            <label>
                <input type="hidden" name="redaquest_include_custom_fields" value="0">
                <input type="checkbox" name="redaquest_include_custom_fields" value="1" <?php checked($include_custom_fields, 1); ?>>
                <?php esc_html_e('Zahrnúť custom fields pri synchronizácii (ACF, Meta Box, Pods, native meta)', 'redaquest-connector'); ?>
            </label>
            <?php if (!$acf_active) : ?>
                <p class="description"><?php esc_html_e('ACF nie je aktívny — native meta a iné pluginy budú stále podporované.', 'redaquest-connector'); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($woo_active) : ?>
        <div class="card">
            <h2>WooCommerce</h2>
            <label>
                <input type="hidden" name="redaquest_sync_woocommerce" value="0">
                <input type="checkbox" name="redaquest_sync_woocommerce" value="1" <?php checked($sync_woocommerce, 1); ?>>
                <?php esc_html_e('Synchronizovať WooCommerce produkty', 'redaquest-connector'); ?>
            </label>
            <?php
            $product_count = wp_count_posts('product');
            $product_cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            ?>
            <div class="woo-stats">
                <div><strong><?php esc_html_e('Produkty:', 'redaquest-connector'); ?></strong> <?php echo isset($product_count->publish) ? (int) $product_count->publish : 0; ?></div>
                <div><strong><?php esc_html_e('Kategórie:', 'redaquest-connector'); ?></strong> <?php echo !is_wp_error($product_cats) ? count($product_cats) : 0; ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    private function render_publish_tab($enable_write, $default_author, $users) {
        ?>
        <div class="card">
            <h2><?php esc_html_e('Zápis obsahu', 'redaquest-connector'); ?></h2>
            <?php if ($enable_write) : ?>
                <div class="warning-box">
                    <?php esc_html_e('Redaquest môže vytvárať a upravovať príspevky priamo na tomto webe.', 'redaquest-connector'); ?>
                </div>
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Povoliť zápis', 'redaquest-connector'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="redaquest_enable_write" value="0">
                            <input type="checkbox" name="redaquest_enable_write" value="1" <?php checked($enable_write, 1); ?>>
                            <?php esc_html_e('Povoliť vytváranie a úpravu článkov z Redaquest', 'redaquest-connector'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="redaquest_default_author"><?php esc_html_e('Predvolený autor', 'redaquest-connector'); ?></label></th>
                    <td>
                        <select id="redaquest_default_author" name="redaquest_default_author">
                            <option value=""><?php esc_html_e('— Automaticky (admin) —', 'redaquest-connector'); ?></option>
                            <?php foreach ($users as $user) : ?>
                                <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($default_author, $user->ID); ?>><?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_login); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    private function render_diagnostics_tab($endpoint_url, $last_test, $is_connected) {
        ?>
        <div class="two-column">
            <div class="card">
                <h2><?php esc_html_e('Rýchla diagnostika', 'redaquest-connector'); ?></h2>
                <?php if ($is_connected) : ?>
                    <button type="button" class="button button-primary" id="redaquest-test-connection-diag"><?php esc_html_e('Otestovať pripojenie', 'redaquest-connector'); ?></button>
                    <div id="redaquest-test-result-diag" class="test-result" aria-live="polite"></div>
                    <?php if (!empty($last_test['success']) && !empty($last_test['data'])) : ?>
                        <?php $this->render_test_summary($last_test['data']); ?>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="status-error"><?php esc_html_e('Najprv pripojte Redaquest v tabe Pripojenie.', 'redaquest-connector'); ?></p>
                <?php endif; ?>
            </div>

            <details class="card support-section">
                <summary><h2 style="display:inline;"><?php esc_html_e('Technické detaily (pre podporu)', 'redaquest-connector'); ?></h2></summary>
                <table class="widefat endpoints-table">
                    <thead><tr><th><?php esc_html_e('Endpoint', 'redaquest-connector'); ?></th><th><?php esc_html_e('Metóda', 'redaquest-connector'); ?></th></tr></thead>
                    <tbody>
                        <tr><td><code>/redaquest/v1/verify</code></td><td>GET</td></tr>
                        <tr><td><code>/redaquest/v1/pages</code></td><td>GET</td></tr>
                        <tr><td><code>/redaquest/v1/products</code></td><td>GET</td></tr>
                        <tr><td><code>/redaquest/v1/posts</code></td><td>POST</td></tr>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('Base URL:', 'redaquest-connector'); ?> <code><?php echo esc_url($endpoint_url); ?></code></p>
                <p class="description"><?php esc_html_e('Verzia pluginu:', 'redaquest-connector'); ?> <?php echo esc_html(REDAQUEST_VERSION); ?> · WordPress <?php echo esc_html(get_bloginfo('version')); ?></p>
            </details>
        </div>
        <?php
    }

    private function render_test_summary($data) {
        if (empty($data['counts']) || !is_array($data['counts'])) {
            return;
        }
        ?>
        <div class="test-summary">
            <h3><?php esc_html_e('Posledný test — nájdený obsah', 'redaquest-connector'); ?></h3>
            <ul>
                <?php foreach ($data['counts'] as $type => $count) : ?>
                    <li><strong><?php echo esc_html($type); ?>:</strong> <?php echo (int) $count; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
}
