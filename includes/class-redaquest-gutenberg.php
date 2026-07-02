<?php
/**
 * Loads the RedaQuest block-editor sidebar panel.
 *
 * The panel source lives in src/ and is built with @wordpress/scripts into build/
 * (build/index.js + build/index.asset.php). The build artifacts are produced by
 * `npm run build` and are not committed; if they are missing we simply do not enqueue,
 * so the plugin stays harmless until the panel is built.
 *
 * @package Redaquest_Connector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Gutenberg {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('enqueue_block_editor_assets', array($this, 'enqueue'));
    }

    public function enqueue() {
        $build_js    = REDAQUEST_PLUGIN_DIR . 'build/index.js';
        $asset_php   = REDAQUEST_PLUGIN_DIR . 'build/index.asset.php';

        if (!file_exists($build_js) || !file_exists($asset_php)) {
            return;
        }

        $asset = require $asset_php;

        wp_enqueue_script(
            'redaquest-editor',
            plugins_url('build/index.js', REDAQUEST_PLUGIN_DIR . 'redaquest-connector.php'),
            $asset['dependencies'],
            $asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('redaquest-editor', 'redaquest-connector', REDAQUEST_PLUGIN_DIR . 'languages');
        }

        $editor_css = REDAQUEST_PLUGIN_DIR . 'assets/editor.css';
        if (file_exists($editor_css)) {
            wp_enqueue_style(
                'redaquest-editor',
                plugins_url('assets/editor.css', REDAQUEST_PLUGIN_DIR . 'redaquest-connector.php'),
                array(),
                $asset['version']
            );
        }
    }
}
