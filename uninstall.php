<?php
/**
 * Uninstall cleanup for Redaquest Connector.
 *
 * Runs only when the user deletes the plugin from the Plugins screen. Removes the
 * plugin's options. Per-post meta written by the connector (_redaquest_post_id,
 * _redaquest_social_image, _redaquest_social_image_att) is intentionally left in
 * place: it belongs to the user's content and mass-deleting post meta can be heavy.
 *
 * @package Redaquest_Connector
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$redaquest_options = array(
    'redaquest_api_key',
    'redaquest_enabled_post_types',
    'redaquest_enable_write',
    'redaquest_default_author',
    'redaquest_studio_token',
    'redaquest_studio_workspace',
    'redaquest_studio_workspace_name',
    'redaquest_studio_scopes',
    'redaquest_studio_site',
    'redaquest_studio_connected_at',
);

foreach ($redaquest_options as $redaquest_option) {
    delete_option($redaquest_option);
}
