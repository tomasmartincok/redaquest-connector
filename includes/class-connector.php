<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Connector {

    private static $instance = null;

    /** @var Redaquest_REST_Controller */
    private $rest;

    /** @var Redaquest_Admin_Settings */
    private $admin;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->rest = new Redaquest_REST_Controller();
        $this->admin = new Redaquest_Admin_Settings();

        add_action('rest_api_init', array($this->rest, 'register_routes'));
        add_action('admin_menu', array($this->admin, 'register_menu'));
        add_action('admin_init', array($this->admin, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_scripts'));

        Redaquest_OAuth_Connect::init();
        Redaquest_Admin_Ajax::init();
        Redaquest_Post_Metabox::init();
    }
}
