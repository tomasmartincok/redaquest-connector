<?php

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_REST_Controller {
    public function register_routes() {
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
            'args' => Redaquest_REST_Schema::list_query_args(),
        ));
        
        register_rest_route($namespace, '/content/(?P<post_type>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_content_by_type'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => Redaquest_REST_Schema::list_query_args(),
        ));
        
        register_rest_route($namespace, '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_products'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => Redaquest_REST_Schema::products_args(),
        ));
        
        register_rest_route($namespace, '/categories', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_categories'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => Redaquest_REST_Schema::categories_args(),
        ));
        
        register_rest_route($namespace, '/custom-fields/(?P<post_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_custom_fields'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => Redaquest_REST_Schema::custom_fields_args(),
        ));
        
        register_rest_route($namespace, '/posts', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
            'permission_callback' => array($this, 'check_write_permission'),
            'args' => Redaquest_REST_Schema::create_post_args(),
        ));
        
        register_rest_route($namespace, '/posts/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_post'),
            'permission_callback' => array($this, 'check_write_permission'),
            'args' => Redaquest_REST_Schema::update_post_args(),
        ));
        
        register_rest_route($namespace, '/posts/by-redaquest/(?P<redaquest_id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post_by_redaquest_id'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => Redaquest_REST_Schema::by_redaquest_id_args(),
        ));
    }
    
    public function check_api_key($request) {
        $api_key = $request->get_header('X-Redaquest-Key');

        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('Chýba API kľúč. Vložte API kľúč z Redaquest integrácie do nastavení pluginu.', 'redaquest-connector'), array('status' => 401));
        }

        $stored_key = get_option('redaquest_api_key');

        if (empty($stored_key)) {
            return new WP_Error('no_api_key_configured', __('API kľúč nie je nakonfigurovaný v nastaveniach pluginu. Prejdite do Nastavenia → Redaquest a vložte API kľúč.', 'redaquest-connector'), array('status' => 401));
        }

        if (!hash_equals($stored_key, $api_key)) {
            return new WP_Error('invalid_api_key', __('Neplatný API kľúč. Skontrolujte, či ste vložili správny API kľúč z Redaquest integrácie.', 'redaquest-connector'), array('status' => 401));
        }

        $rate_limit = Redaquest_Rate_Limiter::check($api_key);
        if (is_wp_error($rate_limit)) {
            return $rate_limit;
        }

        return true;
    }
    
    public function check_write_permission($request) {
        $auth = $this->check_api_key($request);
        if (is_wp_error($auth)) return $auth;
        
        if (!get_option('redaquest_enable_write', 0)) {
            return new WP_Error('write_disabled', __('Zápis nie je povolený. Povoľte zápis v nastaveniach pluginu.', 'redaquest-connector'), array('status' => 403));
        }
        
        return true;
    }
    public function verify_connection($request) {
        $enabled_types = Redaquest_Helpers::get_enabled_post_types();
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
            'message' => __('Pripojenie úspešné.', 'redaquest-connector'),
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
                'woocommerce_sync' => Redaquest_Helpers::is_woocommerce_sync_enabled(),
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
                'enabled' => Redaquest_Helpers::get_enabled_post_types(),
            ),
        ));
    }
    
    public function get_pages($request) {
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 50));
        $status = $request->get_param('status') ?: 'publish';
        $include_custom_fields = Redaquest_Helpers::should_include_custom_fields($request->get_param('include_custom_fields'));
        
        $enabled_types = Redaquest_Helpers::get_enabled_post_types();
        
        if (empty($enabled_types)) {
            return rest_ensure_response(array(
                'success' => true,
                'data' => array('items' => array(), 'total' => 0, 'pages' => 0, 'posts' => 0, 'pages_count' => 0),
            ));
        }
        
        $args = array(
            'post_type' => $enabled_types,
            'post_status' => Redaquest_Helpers::resolve_read_statuses($status),
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
        $include_custom_fields = Redaquest_Helpers::should_include_custom_fields($request->get_param('include_custom_fields'));
        
        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', __('Post type neexistuje.', 'redaquest-connector'), array('status' => 400));
        }

        if (!Redaquest_Helpers::is_post_type_enabled($post_type)) {
            return new WP_Error('post_type_disabled', __('Typ obsahu nie je povolený pre synchronizáciu.', 'redaquest-connector'), array('status' => 403));
        }
        
        $args = array(
            'post_type' => $post_type,
            'post_status' => Redaquest_Helpers::resolve_read_statuses($status),
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
            $item['custom_fields'] = Redaquest_Custom_Fields::get_all($post->ID);
        }
        
        return $item;
    }
    
    /**
     * Get custom fields for a specific post
     */
    public function get_custom_fields($request) {
        $post_id = (int) $request->get_param('post_id');
        
        if (!get_post($post_id)) {
            return new WP_Error('post_not_found', __('Príspevok neexistuje.', 'redaquest-connector'), array('status' => 404));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => Redaquest_Custom_Fields::get_all($post_id),
        ));
    }
    
    public function get_products($request) {
        if (!Redaquest_Helpers::is_woocommerce_sync_enabled()) {
            return new WP_Error('woocommerce_sync_disabled', __('Synchronizácia WooCommerce produktov je vypnutá v nastaveniach pluginu.', 'redaquest-connector'), array('status' => 403));
        }

        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_not_active', __('WooCommerce nie je aktívny.', 'redaquest-connector'), array('status' => 400));
        }
        
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 50));
        $category = $request->get_param('category');
        $status = $request->get_param('status') ?: 'publish';
        $include_custom_fields = Redaquest_Helpers::should_include_custom_fields($request->get_param('include_custom_fields'));
        
        $args = array(
            'post_type' => 'product',
            'post_status' => Redaquest_Helpers::resolve_read_statuses($status),
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        );
        
        if ($category) {
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
            $item['custom_fields'] = Redaquest_Custom_Fields::get_all($product->get_id());
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
        $post_type = !empty($params['post_type']) ? sanitize_key($params['post_type']) : 'post';

        if (empty($params['title'])) {
            return new WP_Error('missing_title', __('Chýba nadpis príspevku.', 'redaquest-connector'), array('status' => 400));
        }
        
        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', __('Post type neexistuje.', 'redaquest-connector'), array('status' => 400));
        }

        if (!Redaquest_Helpers::is_post_type_enabled($post_type)) {
            return new WP_Error('post_type_disabled', __('Typ obsahu nie je povolený pre synchronizáciu.', 'redaquest-connector'), array('status' => 403));
        }

        $write_status = Redaquest_Helpers::resolve_write_status($params['status'] ?? 'draft');
        if (is_wp_error($write_status)) {
            return $write_status;
        }
        
        $post_data = array(
            'post_title' => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content'] ?? ''),
            'post_status' => $write_status,
            'post_type' => $post_type,
            'post_author' => Redaquest_Helpers::get_default_author(),
        );
        
        if (!empty($params['excerpt'])) {
            $post_data['post_excerpt'] = sanitize_textarea_field($params['excerpt']);
        }
        
        if (!empty($params['publish_date']) && $write_status === 'future') {
            $post_data['post_date'] = wp_date('Y-m-d H:i:s', strtotime($params['publish_date']));
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
            wp_set_post_tags($post_id, Redaquest_Helpers::sanitize_tags_input($params['tags']));
        }
        
        if (!empty($params['featured_image_url'])) {
            $image_id = Redaquest_Media::upload_from_url($params['featured_image_url'], $post_id);
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
            Redaquest_Custom_Fields::save_fields($post_id, $params['custom_fields']);
        }
        
        $post = get_post($post_id);
        $post_type_obj = get_post_type_object($post_type);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => sprintf(
                /* translators: %s: post type singular label */
                __('%s bol vytvorený.', 'redaquest-connector'),
                $post_type_obj->labels->singular_name
            ),
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
            return new WP_Error('post_not_found', __('Článok neexistuje.', 'redaquest-connector'), array('status' => 404));
        }

        if (!Redaquest_Helpers::is_post_type_enabled($existing_post->post_type)) {
            return new WP_Error('post_type_disabled', __('Typ obsahu nie je povolený pre synchronizáciu.', 'redaquest-connector'), array('status' => 403));
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
            $write_status = Redaquest_Helpers::resolve_write_status($params['status']);
            if (is_wp_error($write_status)) {
                return $write_status;
            }
            $post_data['post_status'] = $write_status;
        }
        
        if (!empty($params['excerpt'])) {
            $post_data['post_excerpt'] = sanitize_textarea_field($params['excerpt']);
        }
        
        $effective_status = isset($post_data['post_status']) ? $post_data['post_status'] : $existing_post->post_status;
        if (!empty($params['publish_date']) && $effective_status === 'future') {
            $post_data['post_date'] = wp_date('Y-m-d H:i:s', strtotime($params['publish_date']));
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
            wp_set_post_tags($post_id, Redaquest_Helpers::sanitize_tags_input($params['tags']));
        }
        
        // Update featured image if provided
        if (!empty($params['featured_image_url'])) {
            $image_id = Redaquest_Media::upload_from_url($params['featured_image_url'], $post_id);
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
            Redaquest_Custom_Fields::save_fields($post_id, $params['custom_fields']);
        }
        
        $post = get_post($post_id);
        $post_type_obj = get_post_type_object($post->post_type);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => sprintf(
                /* translators: %s: post type singular label */
                __('%s bol aktualizovaný.', 'redaquest-connector'),
                $post_type_obj->labels->singular_name
            ),
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
}
