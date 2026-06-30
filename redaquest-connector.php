<?php
/**
 * Plugin Name: Redaquest Connector
 * Description: Official Redaquest connector — OAuth link, sync posts, pages, CPTs and WooCommerce products to your content marketing workspace.
 * Version: 2.8.3
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Author: Redaquest
 * Author URI: https://redaquest.com
 * License: GPLv2 or later
 * Text Domain: redaquest-connector
 */

if (!defined('ABSPATH')) {
    exit;
}

define('REDAQUEST_VERSION', '2.8.3');
define('REDAQUEST_PLUGIN_FILE', __FILE__);
define('REDAQUEST_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REDAQUEST_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REDAQUEST_APP_URL', apply_filters('redaquest_app_url', 'https://app.redaquest.com'));
define('REDAQUEST_FUNCTIONS_URL', apply_filters('redaquest_functions_url', 'https://fqmaerqsvskqbyigefbe.supabase.co/functions/v1'));
define('REDAQUEST_SUPABASE_ANON_KEY', apply_filters('redaquest_supabase_anon_key', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZxbWFlcnFzdnNrcWJ5aWdlZmJlIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM1Njk3NTUsImV4cCI6MjA4OTE0NTc1NX0.VaXsFw1i-NBQLRj8VGBnlUv0NaqDo9L2IhZTStifMH0'));

require_once REDAQUEST_PLUGIN_DIR . 'includes/class-helpers.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-media.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-custom-fields.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-oauth-connect.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-admin-ajax.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-rest-schema.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-connector.php';

/**
 * Set default options on first activation.
 */
function redaquest_connector_activate() {
    if (false === get_option('redaquest_enabled_post_types', false)) {
        add_option('redaquest_enabled_post_types', array('page', 'post'));
    }

    if (false === get_option('redaquest_enable_write', false)) {
        add_option('redaquest_enable_write', 0);
    }

    if (false === get_option('redaquest_sync_woocommerce', false)) {
        add_option('redaquest_sync_woocommerce', 1);
    }

    if (false === get_option('redaquest_include_custom_fields', false)) {
        add_option('redaquest_include_custom_fields', 0);
    }

    update_option('redaquest_db_version', REDAQUEST_VERSION);
}

register_activation_hook(__FILE__, 'redaquest_connector_activate');

Redaquest_Connector::get_instance();
