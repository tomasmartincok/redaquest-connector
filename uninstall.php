<?php
/**
 * Uninstall cleanup for Redaquest Connector.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('redaquest_api_key');
delete_option('redaquest_enabled_post_types');
delete_option('redaquest_enable_write');
delete_option('redaquest_default_author');
delete_option('redaquest_sync_woocommerce');
delete_option('redaquest_include_custom_fields');
delete_option('redaquest_connection_meta');
delete_option('redaquest_last_test_at');
delete_option('redaquest_last_test_result');
delete_option('redaquest_setup_oauth_done');
delete_option('redaquest_db_version');

global $wpdb;

$wpdb->delete(
    $wpdb->postmeta,
    array('meta_key' => '_redaquest_post_id'),
    array('%s')
);
