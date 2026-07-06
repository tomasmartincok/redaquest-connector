<?php
/**
 * Plugin Name: Redaquest Connector
 * Plugin URI: https://github.com/tomasmartincok/redaquest-connector
 * Description: Connect WordPress and WooCommerce to RedaQuest: sync content and products, and schedule social posts straight from the article editor.
 * Version: 3.0.12
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: RedaQuest
 * Author URI: https://redaquest.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: redaquest-connector
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('REDAQUEST_VERSION', '3.0.12');
define('REDAQUEST_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REDAQUEST_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REDAQUEST_SUPABASE_ANON_KEY', apply_filters('redaquest_supabase_anon_key', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZxbWFlcnFzdnNrcWJ5aWdlZmJlIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM1Njk3NTUsImV4cCI6MjA4OTE0NTc1NX0.VaXsFw1i-NBQLRj8VGBnlUv0NaqDo9L2IhZTStifMH0'));

class Redaquest_Connector {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'load_textdomain'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Load translations. For wordpress.org-hosted plugins WordPress also auto-loads
     * translations since 4.6, but we ship our own /languages bundle as a fallback.
     */
    public function load_textdomain() {
        load_plugin_textdomain('redaquest-connector', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_redaquest-connector') return;
        
        wp_add_inline_style('wp-admin', '
            .redaquest-settings { max-width: 1280px; }
            .redaquest-settings > .notice { margin: 0 0 16px; }
            .redaquest-settings .card { background: #fff; border: 1px solid #ccd0d4; padding: 24px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
            .redaquest-settings .card h2 { margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #eee; font-size: 16px; }
            .redaquest-settings .status-ok { color: #46b450; display: flex; align-items: center; gap: 6px; }
            .redaquest-settings .status-error { color: #dc3232; display: flex; align-items: center; gap: 6px; }
            .redaquest-settings .status-warning { color: #dba617; display: flex; align-items: center; gap: 6px; }
            .redaquest-settings .post-type-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-top: 15px; }
            .redaquest-settings .post-type-item { padding: 14px 16px; background: #f9f9f9; border-radius: 6px; border: 1px solid #e5e5e5; transition: border-color 0.2s, background 0.2s; }
            .redaquest-settings .post-type-item:hover { border-color: #0073aa; background: #f5f9fc; }
            .redaquest-settings .post-type-item.checked { border-color: #0073aa; background: #f0f6fc; }
            .redaquest-settings .post-type-item label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 500; }
            .redaquest-settings .post-type-item .post-type-meta { margin-left: 28px; margin-top: 6px; font-size: 12px; color: #666; }
            .redaquest-settings code { background: #f1f1f1; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-family: Monaco, Consolas, monospace; }
            .redaquest-settings .info-box { background: #f0f6fc; border-left: 4px solid #0073aa; padding: 14px 18px; margin: 15px 0; border-radius: 0 4px 4px 0; }
            .redaquest-settings .warning-box { background: #fff8e5; border-left: 4px solid #dba617; padding: 14px 18px; margin: 15px 0; border-radius: 0 4px 4px 0; }
            .redaquest-settings .api-key-field { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
            .redaquest-settings .api-key-field input { flex: 1; min-width: 300px; }
            .redaquest-settings .form-table th { width: 200px; padding-top: 20px; }
            .redaquest-settings .form-table td { padding-top: 15px; }
            .redaquest-settings .endpoints-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .redaquest-settings .endpoints-table th, .redaquest-settings .endpoints-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; }
            .redaquest-settings .endpoints-table th { background: #f9f9f9; font-weight: 600; }
            .redaquest-settings .endpoints-table code { font-size: 11px; }
            .redaquest-settings .nav-tab-wrapper { border-bottom: 1px solid #ccd0d4; margin-bottom: 20px; }
            .redaquest-settings .nav-tab { padding: 10px 20px; font-size: 14px; background: #f1f1f1; border: 1px solid #ccd0d4; border-bottom: none; margin-left: -1px; cursor: pointer; text-decoration: none; color: #555; }
            .redaquest-settings .nav-tab:first-child { margin-left: 0; }
            .redaquest-settings .nav-tab-active { background: #fff; border-bottom: 1px solid #fff; margin-bottom: -1px; color: #0073aa; font-weight: 500; }
            .redaquest-settings .nav-tab:hover:not(.nav-tab-active) { background: #fafafa; color: #0073aa; }
            .redaquest-settings .tab-content { display: none; }
            .redaquest-settings .tab-content.active { display: block; }
            .redaquest-settings .header-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
            .redaquest-settings .header-bar h1 { margin: 0; display: flex; align-items: center; gap: 10px; }
            .redaquest-settings .version-badge { background: #0073aa; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; }
            .redaquest-settings .two-column { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            @media (max-width: 1100px) { .redaquest-settings .two-column { grid-template-columns: 1fr; } }
        ');
        
        wp_add_inline_script('jquery-core', '
            jQuery(document).ready(function($) {
                // Tab switching
                $(".redaquest-settings .nav-tab").on("click", function(e) {
                    e.preventDefault();
                    var target = $(this).data("tab");
                    
                    $(".redaquest-settings .nav-tab").removeClass("nav-tab-active");
                    $(this).addClass("nav-tab-active");
                    
                    $(".redaquest-settings .tab-content").removeClass("active");
                    $("#tab-" + target).addClass("active");
                    
                    // Save to localStorage
                    localStorage.setItem("redaquest_active_tab", target);
                });
                
                // Restore last active tab
                var savedTab = localStorage.getItem("redaquest_active_tab");
                if (window.location.search.indexOf("redaquest_connected=1") !== -1) {
                    savedTab = "publish";
                }
                if (savedTab && $(".nav-tab[data-tab=\"" + savedTab + "\"]").length) {
                    $(".nav-tab[data-tab=\"" + savedTab + "\"]").trigger("click");
                }
                
                // Checkbox visual update
                $(".post-type-item input[type=checkbox]").on("change", function() {
                    $(this).closest(".post-type-item").toggleClass("checked", $(this).is(":checked"));
                });
                $(".post-type-item input[type=checkbox]:checked").closest(".post-type-item").addClass("checked");
            });
        ');
    }
    
    public function register_rest_routes() {
        $namespace = 'redaquest/v1';
        
        register_rest_route($namespace, '/verify', array(
            'methods' => 'GET',
            'callback' => array($this, 'verify_connection'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        register_rest_route($namespace, '/post-types', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post_types'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        register_rest_route($namespace, '/pages', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_pages'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        register_rest_route($namespace, '/content/(?P<post_type>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_content_by_type'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        register_rest_route($namespace, '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_products'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        register_rest_route($namespace, '/categories', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_categories'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        register_rest_route($namespace, '/custom-fields/(?P<post_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_custom_fields'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        register_rest_route($namespace, '/posts', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
            'permission_callback' => array($this, 'check_write_permission'),
        ));
        
        // GET single post (fresh content for approval sync)
        register_rest_route($namespace, '/posts/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        // UPDATE existing post
        register_rest_route($namespace, '/posts/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_post'),
            'permission_callback' => array($this, 'check_write_permission'),
        ));
        
        // GET post by Redaquest ID
        register_rest_route($namespace, '/posts/by-redaquest/(?P<redaquest_id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post_by_redaquest_id'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
    }
    
    public function check_api_key($request) {
        $api_key = $request->get_header('X-Redaquest-Key');
        if (empty($api_key)) $api_key = $request->get_param('api_key');
        
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is missing. Add the API key from your RedaQuest integration in the plugin settings.', 'redaquest-connector'), array('status' => 401));
        }

        $stored_key = get_option('redaquest_api_key');

        if (empty($stored_key)) {
            return new WP_Error('no_api_key_configured', __('No API key is configured in the plugin settings. Go to Settings → Redaquest Connector and add your API key.', 'redaquest-connector'), array('status' => 401));
        }

        if ($api_key !== $stored_key) {
            return new WP_Error('invalid_api_key', __('Invalid API key. Check that you pasted the correct API key from your RedaQuest integration.', 'redaquest-connector'), array('status' => 401));
        }
        
        return true;
    }
    
    public function check_write_permission($request) {
        $auth = $this->check_api_key($request);
        if (is_wp_error($auth)) return $auth;
        
        if (!get_option('redaquest_enable_write', 0)) {
            return new WP_Error('write_disabled', __('Writing is disabled. Enable content writing in the plugin settings.', 'redaquest-connector'), array('status' => 403));
        }
        
        return true;
    }
    
    private function get_enabled_post_types() {
        $enabled = get_option('redaquest_enabled_post_types', array('page', 'post'));
        
        // Ensure we have an array
        if (empty($enabled) || !is_array($enabled)) {
            return array('page', 'post');
        }
        
        // Validate each post type exists
        $valid_types = array();
        foreach ($enabled as $type) {
            if (post_type_exists($type)) {
                $valid_types[] = $type;
            }
        }
        
        // Fallback to defaults if no valid types
        if (empty($valid_types)) {
            return array('page', 'post');
        }
        
        return $valid_types;
    }

    private function is_post_type_enabled($post_type) {
        return in_array($post_type, $this->get_enabled_post_types(), true);
    }

    public function get_post($request) {
        $post_id = (int) $request->get_param('id');
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error('post_not_found', __('This post does not exist.', 'redaquest-connector'), array('status' => 404));
        }

        if (!$this->is_post_type_enabled($post->post_type)) {
            return new WP_Error('post_type_disabled', __('This content type is not enabled for sync.', 'redaquest-connector'), array('status' => 403));
        }

        $include_custom_fields = (bool) get_option('redaquest_include_custom_fields', 0);

        return rest_ensure_response(array(
            'success' => true,
            'data' => $this->format_content_item($post, $include_custom_fields),
        ));
    }
    
    public function verify_connection($request) {
        $enabled_types = $this->get_enabled_post_types();
        $counts = array();
        
        foreach ($enabled_types as $pt) {
            $count = wp_count_posts($pt);
            $counts[$pt] = isset($count->publish) ? (int) $count->publish : 0;
        }
        
        // Check for custom fields plugins
        $custom_fields_support = array(
            'acf' => class_exists('ACF') || function_exists('get_field'),
            'meta_box' => class_exists('RWMB_Loader'),
            'pods' => function_exists('pods'),
            'toolset' => defined('TYPES_VERSION'),
        );
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Connection successful.', 'redaquest-connector'),
            'site' => array(
                'name' => get_bloginfo('name'),
                'url' => get_site_url(),
                'description' => get_bloginfo('description'),
                'language' => get_locale(),
                'wordpress_version' => get_bloginfo('version'),
                'plugin_version' => REDAQUEST_VERSION,
                'woocommerce_active' => class_exists('WooCommerce'),
                'woocommerce_version' => class_exists('WooCommerce') ? WC()->version : null,
            ),
            'capabilities' => array(
                'read' => true,
                'write' => (bool) get_option('redaquest_enable_write', 0),
                'custom_fields' => $custom_fields_support,
            ),
            'enabled_post_types' => $enabled_types,
            'counts' => $counts,
            'timestamp' => current_time('mysql'),
        ));
    }
    
    public function get_post_types($request) {
        $post_types = get_post_types(array('public' => true), 'objects');
        $types = array();
        
        foreach ($post_types as $pt) {
            if (in_array($pt->name, array('attachment', 'revision', 'nav_menu_item', 'product'))) continue;
            
            $count = wp_count_posts($pt->name);
            $types[] = array(
                'name' => $pt->name,
                'label' => $pt->label,
                'singular_label' => $pt->labels->singular_name,
                'count' => isset($count->publish) ? (int) $count->publish : 0,
                'is_builtin' => $pt->_builtin,
                'has_archive' => $pt->has_archive,
                'supports' => get_all_post_type_supports($pt->name),
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'available' => $types,
                'enabled' => $this->get_enabled_post_types(),
            ),
        ));
    }
    
    public function get_pages($request) {
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 50));
        $status = $request->get_param('status') ?: 'publish';
        $include_custom_fields = (bool) $request->get_param('include_custom_fields');
        
        $enabled_types = $this->get_enabled_post_types();
        
        if (empty($enabled_types)) {
            return rest_ensure_response(array(
                'success' => true,
                'data' => array('items' => array(), 'total' => 0, 'pages' => 0, 'posts' => 0, 'pages_count' => 0),
            ));
        }
        
        $args = array(
            'post_type' => $enabled_types,
            'post_status' => $status === 'any' ? array('publish', 'draft', 'pending') : $status,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        );
        
        $query = new WP_Query($args);
        $items = array();
        
        foreach ($query->posts as $post) {
            $items[] = $this->format_content_item($post, $include_custom_fields);
        }
        
        // Celkové počty
        $total_pages = 0;
        $total_posts = 0;
        $cpt_counts = array();
        
        foreach ($enabled_types as $pt) {
            $count = wp_count_posts($pt);
            $publish_count = isset($count->publish) ? (int) $count->publish : 0;
            
            if ($pt === 'page') {
                $total_pages = $publish_count;
            } elseif ($pt === 'post') {
                $total_posts = $publish_count;
            } else {
                // Track CPT counts separately
                $cpt_counts[$pt] = $publish_count;
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'items' => $items,
                'total' => (int) $query->found_posts,
                'pages' => $total_pages,
                'posts' => $total_posts,
                'cpt_counts' => $cpt_counts,
                'pages_count' => (int) $query->max_num_pages,
                'current_page' => $page,
                'per_page' => $per_page,
            ),
        ));
    }
    
    public function get_content_by_type($request) {
        $post_type = $request->get_param('post_type');
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 50));
        $status = $request->get_param('status') ?: 'publish';
        $include_custom_fields = (bool) $request->get_param('include_custom_fields');
        
        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', __('This post type does not exist.', 'redaquest-connector'), array('status' => 400));
        }

        $args = array(
            'post_type' => $post_type,
            'post_status' => $status === 'any' ? array('publish', 'draft', 'pending') : $status,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        );
        
        $query = new WP_Query($args);
        $items = array();
        
        foreach ($query->posts as $post) {
            $items[] = $this->format_content_item($post, $include_custom_fields);
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'items' => $items,
                'total' => (int) $query->found_posts,
                'pages_count' => (int) $query->max_num_pages,
                'current_page' => $page,
                'post_type' => $post_type,
            ),
        ));
    }
    
    private function format_content_item($post, $include_custom_fields = false) {
        $post_type_obj = get_post_type_object($post->post_type);
        
        // Kategórie
        $categories = array();
        $taxonomies = get_object_taxonomies($post->post_type, 'names');
        foreach ($taxonomies as $taxonomy) {
            if (in_array($taxonomy, array('post_tag', 'post_format'))) continue;
            $terms = get_the_terms($post->ID, $taxonomy);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $categories[] = $term->name;
                }
            }
        }
        
        // Tagy
        $tags = array();
        $post_tags = get_the_tags($post->ID);
        if ($post_tags && !is_wp_error($post_tags)) {
            foreach ($post_tags as $tag) {
                $tags[] = $tag->name;
            }
        }
        
        // Featured image
        $featured_image = has_post_thumbnail($post->ID) ? get_the_post_thumbnail_url($post->ID, 'large') : null;
        
        // Autor
        $author = get_userdata($post->post_author);
        $author_name = $author ? $author->display_name : '';
        
        // Typ pre Redaquest - ALWAYS use custom: prefix for non-builtin types
        $type = $post->post_type;
        if (!in_array($post->post_type, array('page', 'post'))) {
            $type = 'custom:' . $post->post_type;
        }
        
        $item = array(
            'id' => (string) $post->ID,
            'type' => $type,
            'post_type' => $post->post_type,
            'post_type_label' => $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 55),
            'permalink' => get_permalink($post->ID),
            'status' => $post->post_status,
            'author' => $author_name,
            'featured_image_url' => $featured_image,
            'categories' => $categories,
            'tags' => $tags,
            'published_at' => $post->post_date,
            'modified_at' => $post->post_modified,
            'meta_title' => get_post_meta($post->ID, '_yoast_wpseo_title', true) ?: get_post_meta($post->ID, 'rank_math_title', true) ?: '',
            'meta_description' => get_post_meta($post->ID, '_yoast_wpseo_metadesc', true) ?: get_post_meta($post->ID, 'rank_math_description', true) ?: '',
        );
        
        // Include custom fields if requested
        if ($include_custom_fields) {
            $item['custom_fields'] = $this->get_all_custom_fields($post->ID);
        }
        
        return $item;
    }
    
    /**
     * Get custom fields for a specific post
     */
    public function get_custom_fields($request) {
        $post_id = (int) $request->get_param('post_id');
        
        if (!get_post($post_id)) {
            return new WP_Error('post_not_found', __('This post does not exist.', 'redaquest-connector'), array('status' => 404));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $this->get_all_custom_fields($post_id),
        ));
    }
    
    /**
     * Get all custom fields from all supported plugins
     */
    private function get_all_custom_fields($post_id) {
        $fields = array();
        
        // 1. ACF Fields
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post_id);
            if ($acf_fields && is_array($acf_fields)) {
                foreach ($acf_fields as $key => $value) {
                    $fields['acf_' . $key] = array(
                        'source' => 'acf',
                        'key' => $key,
                        'value' => $value,
                    );
                }
            }
        }
        
        // 2. Meta Box Fields
        if (class_exists('RWMB_Loader')) {
            $meta_boxes = rwmb_get_registry('meta_box')->all();
            foreach ($meta_boxes as $meta_box) {
                if (isset($meta_box->fields)) {
                    foreach ($meta_box->fields as $field) {
                        if (isset($field['id'])) {
                            $value = rwmb_meta($field['id'], array(), $post_id);
                            if ($value !== '' && $value !== null) {
                                $fields['mb_' . $field['id']] = array(
                                    'source' => 'meta_box',
                                    'key' => $field['id'],
                                    'label' => isset($field['name']) ? $field['name'] : $field['id'],
                                    'value' => $value,
                                );
                            }
                        }
                    }
                }
            }
        }
        
        // 3. Pods Fields
        if (function_exists('pods')) {
            $post = get_post($post_id);
            if ($post) {
                $pod = pods($post->post_type, $post_id);
                if ($pod && !is_wp_error($pod)) {
                    $pod_fields = $pod->fields();
                    foreach ($pod_fields as $field_name => $field_data) {
                        $value = $pod->field($field_name);
                        if ($value !== '' && $value !== null) {
                            $fields['pods_' . $field_name] = array(
                                'source' => 'pods',
                                'key' => $field_name,
                                'label' => isset($field_data['label']) ? $field_data['label'] : $field_name,
                                'value' => $value,
                            );
                        }
                    }
                }
            }
        }
        
        // 4. Native WordPress post meta (excluding internal/private)
        $all_meta = get_post_meta($post_id);
        $exclude_prefixes = array('_', 'acf_', 'mb_', 'pods_');
        $exclude_keys = array('_edit_lock', '_edit_last', '_thumbnail_id', '_wp_page_template');
        
        foreach ($all_meta as $key => $values) {
            // Skip internal meta
            $skip = false;
            foreach ($exclude_prefixes as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip || in_array($key, $exclude_keys)) continue;
            
            // Already added from other sources
            if (isset($fields['acf_' . $key]) || isset($fields['mb_' . $key]) || isset($fields['pods_' . $key])) continue;
            
            $fields['meta_' . $key] = array(
                'source' => 'native',
                'key' => $key,
                'value' => count($values) === 1 ? $values[0] : $values,
            );
        }
        
        return $fields;
    }
    
    public function get_products($request) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_not_active', __('WooCommerce is not active.', 'redaquest-connector'), array('status' => 400));
        }
        
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 50));
        $category = $request->get_param('category');
        $status = $request->get_param('status') ?: 'publish';
        $include_custom_fields = (bool) $request->get_param('include_custom_fields');
        
        $args = array(
            'post_type' => 'product',
            'post_status' => $status === 'any' ? array('publish', 'draft', 'pending') : $status,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        );
        
        if ($category) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Optional category filter, bounded by per_page (max 100); acceptable for an on-demand sync endpoint.
            $args['tax_query'] = array(array('taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $category));
        }
        
        $query = new WP_Query($args);
        $products = array();
        
        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $products[] = $this->format_product($product, $include_custom_fields);
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'products' => $products,
                'total' => (int) $query->found_posts,
                'pages' => (int) $query->max_num_pages,
                'current_page' => $page,
            ),
        ));
    }
    
    private function format_product($product, $include_custom_fields = false) {
        $categories = array();
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) $categories[] = $term->name;
        }
        
        $tags = array();
        $product_tags = get_the_terms($product->get_id(), 'product_tag');
        if ($product_tags && !is_wp_error($product_tags)) {
            foreach ($product_tags as $tag) $tags[] = $tag->name;
        }
        
        $gallery = array();
        foreach ($product->get_gallery_image_ids() as $image_id) {
            $gallery[] = wp_get_attachment_url($image_id);
        }
        
        $attributes = array();
        foreach ($product->get_attributes() as $attr) {
            if (is_object($attr)) {
                $attr_name = $attr->get_name();
                // Get proper attribute label
                if ($attr->is_taxonomy()) {
                    $taxonomy = $attr->get_taxonomy_object();
                    $attr_name = $taxonomy ? $taxonomy->attribute_label : $attr_name;
                }
                $attributes[$attr_name] = $attr->get_options();
            }
        }
        
        // Get variations for variable products
        $variations = array();
        if ($product->is_type('variable')) {
            $variation_ids = $product->get_children();
            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $variations[] = array(
                        'id' => $variation_id,
                        'sku' => $variation->get_sku(),
                        'price' => (float) $variation->get_price(),
                        'regular_price' => (float) $variation->get_regular_price(),
                        'sale_price' => $variation->get_sale_price() ? (float) $variation->get_sale_price() : null,
                        'stock_status' => $variation->get_stock_status(),
                        'stock_quantity' => $variation->get_stock_quantity(),
                        'attributes' => $variation->get_variation_attributes(),
                    );
                }
            }
        }
        
        $item = array(
            'id' => (string) $product->get_id(),
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'price' => (float) $product->get_price(),
            'regular_price' => (float) $product->get_regular_price(),
            'sale_price' => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
            'currency' => get_woocommerce_currency(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->managing_stock(),
            'weight' => $product->get_weight(),
            'dimensions' => array(
                'length' => $product->get_length(),
                'width' => $product->get_width(),
                'height' => $product->get_height(),
            ),
            'status' => $product->get_status(),
            'type' => $product->get_type(),
            'permalink' => $product->get_permalink(),
            'image_url' => wp_get_attachment_url($product->get_image_id()),
            'gallery_urls' => $gallery,
            'categories' => $categories,
            'tags' => $tags,
            'attributes' => $attributes,
            'variations' => $variations,
            'average_rating' => $product->get_average_rating(),
            'review_count' => $product->get_review_count(),
        );
        
        // Include custom fields if requested
        if ($include_custom_fields) {
            $item['custom_fields'] = $this->get_all_custom_fields($product->get_id());
        }
        
        return $item;
    }
    
    public function get_categories($request) {
        $type = $request->get_param('type') ?: 'all';
        $hide_empty = (bool) $request->get_param('hide_empty');
        $categories = array();
        
        if ($type === 'all' || $type === 'post') {
            $post_cats = get_terms(array('taxonomy' => 'category', 'hide_empty' => $hide_empty));
            if (!is_wp_error($post_cats)) {
                foreach ($post_cats as $cat) {
                    $categories[] = array('id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug, 'type' => 'post', 'count' => $cat->count);
                }
            }
        }
        
        if (($type === 'all' || $type === 'product') && class_exists('WooCommerce')) {
            $product_cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => $hide_empty));
            if (!is_wp_error($product_cats)) {
                foreach ($product_cats as $cat) {
                    $categories[] = array('id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug, 'type' => 'product', 'count' => $cat->count);
                }
            }
        }
        
        return rest_ensure_response(array('success' => true, 'data' => $categories));
    }
    
    public function create_post($request) {
        $params = $request->get_params();
        $post_type = !empty($params['post_type']) ? $params['post_type'] : 'post';
        
        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', __('This post type does not exist.', 'redaquest-connector'), array('status' => 400));
        }

        $post_data = array(
            'post_title' => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content']),
            'post_status' => $params['status'] ?? 'draft',
            'post_type' => $post_type,
            'post_author' => $this->get_default_author(),
        );
        
        if (!empty($params['excerpt'])) {
            $post_data['post_excerpt'] = sanitize_textarea_field($params['excerpt']);
        }
        
        if (!empty($params['publish_date']) && $params['status'] === 'future') {
            $post_data['post_date'] = gmdate('Y-m-d H:i:s', strtotime($params['publish_date']));
            $post_data['post_date_gmt'] = get_gmt_from_date($post_data['post_date']);
        }
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return new WP_Error('post_creation_failed', $post_id->get_error_message(), array('status' => 500));
        }
        
        if (!empty($params['categories']) && is_object_in_taxonomy($post_type, 'category')) {
            wp_set_post_categories($post_id, array_map('intval', $params['categories']));
        }
        
        if (!empty($params['tags']) && is_object_in_taxonomy($post_type, 'post_tag')) {
            wp_set_post_tags($post_id, $params['tags']);
        }
        
        if (!empty($params['featured_image_url'])) {
            $image_id = $this->upload_image_from_url($params['featured_image_url'], $post_id);
            if ($image_id && !is_wp_error($image_id)) {
                set_post_thumbnail($post_id, $image_id);
            }
        }
        
        if (!empty($params['meta_title'])) {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($params['meta_title']));
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($params['meta_title']));
        }
        
        if (!empty($params['meta_description'])) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field($params['meta_description']));
            update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($params['meta_description']));
        }
        
        if (!empty($params['redaquest_post_id'])) {
            update_post_meta($post_id, '_redaquest_post_id', sanitize_text_field($params['redaquest_post_id']));
        }
        
        // Save custom fields if provided
        if (!empty($params['custom_fields']) && is_array($params['custom_fields'])) {
            foreach ($params['custom_fields'] as $key => $value) {
                // ACF fields
                if (function_exists('update_field') && strpos($key, 'acf_') === 0) {
                    update_field(substr($key, 4), $value, $post_id);
                } else {
                    update_post_meta($post_id, sanitize_key($key), sanitize_text_field($value));
                }
            }
        }
        
        $post = get_post($post_id);
        $post_type_obj = get_post_type_object($post_type);
        
        return rest_ensure_response(array(
            'success' => true,
            /* translators: %s: singular post type label, e.g. "Post" or "Page". */
            'message' => sprintf(__('%s was created.', 'redaquest-connector'), $post_type_obj->labels->singular_name),
            'data' => array(
                'id' => $post_id,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'permalink' => get_permalink($post_id),
                'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
            ),
        ));
    }

    /**
     * Update existing post
     */
    public function update_post($request) {
        $post_id = (int) $request->get_param('id');
        $params = $request->get_params();
        
        $existing_post = get_post($post_id);
        if (!$existing_post) {
            return new WP_Error('post_not_found', __('This post does not exist.', 'redaquest-connector'), array('status' => 404));
        }
        
        $post_data = array(
            'ID' => $post_id,
        );
        
        if (!empty($params['title'])) {
            $post_data['post_title'] = sanitize_text_field($params['title']);
        }
        
        if (!empty($params['content'])) {
            $post_data['post_content'] = wp_kses_post($params['content']);
        }
        
        if (!empty($params['status'])) {
            $post_data['post_status'] = sanitize_key($params['status']);
        }
        
        if (!empty($params['excerpt'])) {
            $post_data['post_excerpt'] = sanitize_textarea_field($params['excerpt']);
        }
        
        if (!empty($params['publish_date']) && isset($params['status']) && $params['status'] === 'future') {
            $post_data['post_date'] = gmdate('Y-m-d H:i:s', strtotime($params['publish_date']));
            $post_data['post_date_gmt'] = get_gmt_from_date($post_data['post_date']);
        }
        
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            return new WP_Error('post_update_failed', $result->get_error_message(), array('status' => 500));
        }
        
        // Update categories if provided
        if (!empty($params['categories']) && is_object_in_taxonomy($existing_post->post_type, 'category')) {
            wp_set_post_categories($post_id, array_map('intval', $params['categories']));
        }
        
        // Update tags if provided
        if (!empty($params['tags']) && is_object_in_taxonomy($existing_post->post_type, 'post_tag')) {
            wp_set_post_tags($post_id, $params['tags']);
        }
        
        // Update featured image if provided
        if (!empty($params['featured_image_url'])) {
            $image_id = $this->upload_image_from_url($params['featured_image_url'], $post_id);
            if ($image_id && !is_wp_error($image_id)) {
                set_post_thumbnail($post_id, $image_id);
            }
        }
        
        // Update SEO meta
        if (!empty($params['meta_title'])) {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($params['meta_title']));
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($params['meta_title']));
        }
        
        if (!empty($params['meta_description'])) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field($params['meta_description']));
            update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($params['meta_description']));
        }
        
        // Update Redaquest post ID reference
        if (!empty($params['redaquest_post_id'])) {
            update_post_meta($post_id, '_redaquest_post_id', sanitize_text_field($params['redaquest_post_id']));
        }
        
        // Update custom fields if provided
        if (!empty($params['custom_fields']) && is_array($params['custom_fields'])) {
            foreach ($params['custom_fields'] as $key => $value) {
                if (function_exists('update_field') && strpos($key, 'acf_') === 0) {
                    update_field(substr($key, 4), $value, $post_id);
                } else {
                    update_post_meta($post_id, sanitize_key($key), sanitize_text_field($value));
                }
            }
        }
        
        $post = get_post($post_id);
        $post_type_obj = get_post_type_object($post->post_type);
        
        return rest_ensure_response(array(
            'success' => true,
            /* translators: %s: singular post type label, e.g. "Post" or "Page". */
            'message' => sprintf(__('%s was updated.', 'redaquest-connector'), $post_type_obj->labels->singular_name),
            'data' => array(
                'id' => $post_id,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'permalink' => get_permalink($post_id),
                'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
            ),
        ));
    }
    
    /**
     * Get post by Redaquest ID
     */
    public function get_post_by_redaquest_id($request) {
        $redaquest_id = sanitize_text_field($request->get_param('redaquest_id'));
        
        $posts = get_posts(array(
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Rare reconciliation lookup by RedaQuest ID, single row (numberposts=1); a custom-indexed table would be overkill here.
            'meta_key' => '_redaquest_post_id',
            'meta_value' => $redaquest_id,
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => 1,
        ));
        
        if (empty($posts)) {
            return rest_ensure_response(array(
                'success' => true,
                'found' => false,
                'data' => null,
            ));
        }
        
        $post = $posts[0];
        
        return rest_ensure_response(array(
            'success' => true,
            'found' => true,
            'data' => array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'permalink' => get_permalink($post->ID),
                'published_at' => $post->post_date,
                'modified_at' => $post->post_modified,
                'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
            ),
        ));
    }
    
    private function get_default_author() {
        $default_author = get_option('redaquest_default_author');
        if ($default_author) return (int) $default_author;
        $admins = get_users(array('role' => 'administrator', 'number' => 1));
        return !empty($admins) ? $admins[0]->ID : 1;
    }
    
    private function upload_image_from_url($url, $post_id = 0) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $tmp = download_url($url);
        if (is_wp_error($tmp)) return $tmp;
        
        $file_array = array('name' => basename(wp_parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp);
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file_array['name'])) {
            $file_array['name'] .= '.jpg';
        }

        $attachment_id = media_handle_sideload($file_array, $post_id);
        if (file_exists($tmp)) {
            wp_delete_file($tmp);
        }
        return $attachment_id;
    }
    
    public function add_admin_menu() {
        add_options_page(
            __('Redaquest Connector', 'redaquest-connector'),
            __('Redaquest Connector', 'redaquest-connector'),
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
    }
    
    /**
     * Sanitize API key - keep existing if empty submitted
     */
    public function sanitize_api_key($value) {
        $value = sanitize_text_field($value);
        // If empty, keep existing key (don't overwrite)
        if (empty($value)) {
            return get_option('redaquest_api_key');
        }
        return $value;
    }
    
    public function sanitize_post_types($value) {
        if (!is_array($value)) return array('page', 'post');
        return array_map('sanitize_key', $value);
    }
    
    public function render_settings_page() {
        $api_key = get_option('redaquest_api_key');
        $enabled_types = $this->get_enabled_post_types();
        $enable_write = get_option('redaquest_enable_write', 0);
        $default_author = get_option('redaquest_default_author');
        $available_types = get_post_types(array('public' => true), 'objects');
        $exclude = array('attachment', 'revision', 'nav_menu_item', 'product');
        $users = get_users(array('role__in' => array('administrator', 'editor', 'author')));
        $woo_active = class_exists('WooCommerce');
        $acf_active = function_exists('get_fields');
        $studio_connected = class_exists('Redaquest_Connect') && Redaquest_Connect::is_connected();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flags from our own redirects.
        if (isset($_GET['redaquest_connected'])) {
            add_settings_error(
                'redaquest_settings',
                'connected',
                __('Your site is connected to RedaQuest. Review publishing settings below.', 'redaquest-connector'),
                'success'
            );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flags from our own redirects.
        if (isset($_GET['redaquest_disconnected'])) {
            add_settings_error(
                'redaquest_settings',
                'disconnected',
                __('The RedaQuest connection was removed.', 'redaquest-connector'),
                'info'
            );
        }
        ?>
        <div class="wrap redaquest-settings">
            <?php settings_errors('redaquest_settings'); ?>
            <?php settings_errors('redaquest_connect'); ?>
            <div class="header-bar">
                <h1><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e('Redaquest Connector', 'redaquest-connector'); ?></h1>
                <span class="version-badge">v<?php echo esc_html(REDAQUEST_VERSION); ?></span>
            </div>

            <p class="description" style="margin: -8px 0 16px;">
                <?php esc_html_e('Connect = schedule social posts from the editor. API key = sync content and products.', 'redaquest-connector'); ?>
            </p>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper">
                <a href="#" class="nav-tab nav-tab-active" data-tab="connection"><?php esc_html_e('Connection', 'redaquest-connector'); ?></a>
                <a href="#" class="nav-tab" data-tab="sync"><?php esc_html_e('Sync', 'redaquest-connector'); ?></a>
                <a href="#" class="nav-tab" data-tab="publish"><?php esc_html_e('Publishing', 'redaquest-connector'); ?></a>
                <a href="#" class="nav-tab" data-tab="api"><?php esc_html_e('API & Debug', 'redaquest-connector'); ?></a>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields('redaquest_settings'); ?>

                <!-- Tab: Connection -->
                <div id="tab-connection" class="tab-content active">
                    <?php
                    // OAuth "Connect" card (social scheduling) — rendered first. No nested <form>: disconnect uses a nonce link.
                    if (class_exists('Redaquest_Connect')) {
                        Redaquest_Connect::get_instance()->render_connection_section();
                    }
                    ?>
                    <div class="two-column">
                        <div class="card">
                            <h2><?php esc_html_e('Sync status', 'redaquest-connector'); ?></h2>
                            <?php if ($api_key): ?>
                                <p class="status-ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('API key is configured', 'redaquest-connector'); ?></p>
                            <?php else: ?>
                                <p class="status-error"><span class="dashicons dashicons-warning"></span> <?php esc_html_e('API key is not configured', 'redaquest-connector'); ?></p>
                            <?php endif; ?>

                            <p style="margin-top: 15px;"><strong><?php esc_html_e('API endpoint:', 'redaquest-connector'); ?></strong><br><code><?php echo esc_url(get_rest_url(null, 'redaquest/v1/')); ?></code></p>

                            <div style="margin-top: 15px; display: flex; gap: 15px; flex-wrap: wrap;">
                                <div>
                                    <?php if ($woo_active): ?>
                                        <span class="status-ok"><span class="dashicons dashicons-yes"></span> WooCommerce</span>
                                    <?php else: ?>
                                        <span class="status-warning"><span class="dashicons dashicons-minus"></span> WooCommerce</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if ($acf_active): ?>
                                        <span class="status-ok"><span class="dashicons dashicons-yes"></span> ACF</span>
                                    <?php else: ?>
                                        <span class="status-warning"><span class="dashicons dashicons-minus"></span> ACF</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <h2><?php esc_html_e('Content sync key', 'redaquest-connector'); ?></h2>
                            <?php if ($studio_connected && $api_key): ?>
                                <p class="status-ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Configured automatically via Connect.', 'redaquest-connector'); ?></p>
                                <p class="description"><?php esc_html_e('RedaQuest set up the content-sync key when you connected. You do not need to paste anything.', 'redaquest-connector'); ?></p>
                            <?php else: ?>
                                <div class="info-box">
                                    <?php
                                    printf(
                                        /* translators: %s: location of the API key inside the RedaQuest app, already wrapped in <strong>. */
                                        esc_html__('Tip: connecting above sets this up automatically. To configure sync manually instead, get your API key in the RedaQuest app under %s.', 'redaquest-connector'),
                                        '<strong>' . esc_html__('Settings → Integrations', 'redaquest-connector') . '</strong>'
                                    );
                                    ?>
                                </div>
                                <?php if ($api_key): ?>
                                    <div class="api-key-field" id="api-key-display">
                                        <input type="text" value="<?php echo esc_attr(substr($api_key, 0, 8)); ?>••••••••••••••••" class="regular-text" readonly disabled style="background: #f9f9f9; color: #666;">
                                        <button type="button" class="button" onclick="document.getElementById('api-key-display').style.display='none'; document.getElementById('api-key-input').style.display='block';">
                                            <?php esc_html_e('Change key', 'redaquest-connector'); ?>
                                        </button>
                                    </div>
                                    <div id="api-key-input" style="display: none;">
                                        <input type="password" id="redaquest_api_key" name="redaquest_api_key" value="" class="regular-text" placeholder="<?php esc_attr_e('Enter a new API key', 'redaquest-connector'); ?>">
                                        <p class="description"><?php esc_html_e('Enter a new key to replace the existing one.', 'redaquest-connector'); ?></p>
                                    </div>
                                <?php else: ?>
                                    <input type="password" id="redaquest_api_key" name="redaquest_api_key" value="" class="regular-text" placeholder="<?php esc_attr_e('Paste the API key from RedaQuest', 'redaquest-connector'); ?>">
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tab: Sync -->
                <div id="tab-sync" class="tab-content">
                        <div class="card">
                            <h2><?php esc_html_e('Content types to sync', 'redaquest-connector'); ?></h2>
                            <p><?php esc_html_e('Choose which content types you want to sync with RedaQuest.', 'redaquest-connector'); ?></p>
                            <div class="post-type-list">
                                <?php foreach ($available_types as $pt): ?>
                                    <?php if (in_array($pt->name, $exclude)) continue; ?>
                                    <div class="post-type-item <?php echo in_array($pt->name, $enabled_types) ? 'checked' : ''; ?>">
                                        <label>
                                            <input type="checkbox" name="redaquest_enabled_post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $enabled_types)); ?>>
                                            <strong><?php echo esc_html($pt->label); ?></strong>
                                            <?php if (!$pt->_builtin): ?><span style="color: #0073aa; font-size: 11px; font-weight: 500;">(CPT)</span><?php endif; ?>
                                            <?php $count = wp_count_posts($pt->name); ?>
                                            <span style="color: #999; font-size: 12px;">(<?php echo (int) ($count->publish ?? 0); ?>)</span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($woo_active): ?>
                        <div class="card">
                            <h2>WooCommerce</h2>
                            <p class="status-ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('WooCommerce is active — products are synced automatically.', 'redaquest-connector'); ?></p>
                            <?php
                            $product_count = wp_count_posts('product');
                            $product_cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
                            ?>
                            <div style="display: flex; gap: 30px; margin-top: 15px;">
                                <div><strong><?php esc_html_e('Products:', 'redaquest-connector'); ?></strong> <?php echo (int) ($product_count->publish ?? 0); ?></div>
                                <div><strong><?php esc_html_e('Categories:', 'redaquest-connector'); ?></strong> <?php echo (int) (!is_wp_error($product_cats) ? count($product_cats) : 0); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab: Publishing -->
                    <div id="tab-publish" class="tab-content">
                        <div class="card">
                            <h2><?php esc_html_e('Content writing', 'redaquest-connector'); ?></h2>
                            <p><?php esc_html_e('Allow creating posts directly from RedaQuest.', 'redaquest-connector'); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e('Enable writing', 'redaquest-connector'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="redaquest_enable_write" value="1" <?php checked($enable_write, 1); ?>>
                                            <?php esc_html_e('Allow creating and updating posts from RedaQuest', 'redaquest-connector'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('Lets you publish content from RedaQuest straight to your site.', 'redaquest-connector'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="redaquest_default_author"><?php esc_html_e('Default author', 'redaquest-connector'); ?></label></th>
                                    <td>
                                        <select id="redaquest_default_author" name="redaquest_default_author">
                                            <option value=""><?php esc_html_e('— Automatic (admin) —', 'redaquest-connector'); ?></option>
                                            <?php foreach ($users as $user): ?>
                                                <option value="<?php echo (int) $user->ID; ?>" <?php selected($default_author, $user->ID); ?>><?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_login); ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                <?php submit_button(__('Save settings', 'redaquest-connector')); ?>
            </form>

            <!-- Tab: API & Debug -->
            <div id="tab-api" class="tab-content">
                <div class="two-column">
                    <div class="card">
                        <h2><?php esc_html_e('Available API endpoints', 'redaquest-connector'); ?></h2>
                        <p><?php esc_html_e('RedaQuest uses these endpoints to communicate with your site:', 'redaquest-connector'); ?></p>
                        <table class="widefat" style="margin-top: 10px;">
                            <thead><tr><th><?php esc_html_e('Endpoint', 'redaquest-connector'); ?></th><th><?php esc_html_e('Method', 'redaquest-connector'); ?></th><th><?php esc_html_e('Description', 'redaquest-connector'); ?></th></tr></thead>
                            <tbody>
                                <tr><td><code>/redaquest/v1/verify</code></td><td>GET</td><td><?php esc_html_e('Verify connection', 'redaquest-connector'); ?></td></tr>
                                <tr><td><code>/redaquest/v1/post-types</code></td><td>GET</td><td><?php esc_html_e('List of content types', 'redaquest-connector'); ?></td></tr>
                                <tr><td><code>/redaquest/v1/pages</code></td><td>GET</td><td><?php esc_html_e('Pages, posts, CPTs', 'redaquest-connector'); ?></td></tr>
                                <tr><td><code>/redaquest/v1/content/{type}</code></td><td>GET</td><td><?php esc_html_e('A specific type', 'redaquest-connector'); ?></td></tr>
                                <tr><td><code>/redaquest/v1/products</code></td><td>GET</td><td><?php esc_html_e('WooCommerce products', 'redaquest-connector'); ?></td></tr>
                                <tr><td><code>/redaquest/v1/categories</code></td><td>GET</td><td><?php esc_html_e('Categories', 'redaquest-connector'); ?></td></tr>
                                <tr><td><code>/redaquest/v1/custom-fields/{id}</code></td><td>GET</td><td><?php esc_html_e('Custom fields', 'redaquest-connector'); ?></td></tr>
                                <tr><td><code>/redaquest/v1/posts</code></td><td>POST</td><td><?php esc_html_e('Create a post', 'redaquest-connector'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="card">
                        <h2><?php esc_html_e('Custom fields support', 'redaquest-connector'); ?></h2>
                        <p><?php esc_html_e('The plugin automatically detects and syncs custom fields from these plugins:', 'redaquest-connector'); ?></p>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li><strong>Advanced Custom Fields (ACF)</strong></li>
                            <li><strong>Meta Box</strong></li>
                            <li><strong>Pods</strong></li>
                            <li><strong><?php esc_html_e('Native WordPress', 'redaquest-connector'); ?></strong> <?php esc_html_e('post meta', 'redaquest-connector'); ?></li>
                        </ul>
                        <p class="description" style="margin-top: 15px;"><?php esc_html_e('Parameter:', 'redaquest-connector'); ?> <code>?include_custom_fields=1</code></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

Redaquest_Connector::get_instance();

// v3 — Social scheduling layer: Connect flow + REST proxy + Gutenberg editor panel.
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-redaquest-connect.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-redaquest-proxy.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-redaquest-gutenberg.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-redaquest-api-client.php';
require_once REDAQUEST_PLUGIN_DIR . 'includes/class-redaquest-post-metabox.php';
Redaquest_Connect::get_instance();
Redaquest_Proxy::get_instance();
Redaquest_Gutenberg::get_instance();
Redaquest_Post_Metabox::init();
