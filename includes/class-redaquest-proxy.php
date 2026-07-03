<?php
/**
 * REST proxy: redaquest/v2/* endpoints the block editor calls.
 *
 * The Gutenberg panel (JS) talks ONLY to these same-site routes, authenticated by the
 * editor's cookie + REST nonce and a capability check. Each route forwards to the
 * RedaQuest `wp-bridge` edge function server-side, attaching the stored bearer token.
 * The token therefore never reaches the browser.
 *
 * @package Redaquest_Connector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Redaquest_Proxy {

    const NS = 'redaquest/v2';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        $perm = array($this, 'can_edit');
        register_rest_route(self::NS, '/status',   array('methods' => 'GET',  'callback' => array($this, 'get_status'),   'permission_callback' => $perm));
        register_rest_route(self::NS, '/accounts', array('methods' => 'GET',  'callback' => array($this, 'get_accounts'), 'permission_callback' => $perm));
        register_rest_route(self::NS, '/generate', array('methods' => 'POST', 'callback' => array($this, 'post_generate'), 'permission_callback' => $perm));
        register_rest_route(self::NS, '/generate-image', array('methods' => 'POST', 'callback' => array($this, 'post_generate_image'), 'permission_callback' => $perm));
        register_rest_route(self::NS, '/schedule', array('methods' => 'POST', 'callback' => array($this, 'post_schedule'), 'permission_callback' => $perm));
        register_rest_route(self::NS, '/blog/outline', array('methods' => 'POST', 'callback' => array($this, 'post_blog_outline'), 'permission_callback' => $perm));
        register_rest_route(self::NS, '/blog/draft', array('methods' => 'POST', 'callback' => array($this, 'post_blog_draft'), 'permission_callback' => $perm));
        register_rest_route(self::NS, '/blog/apply-meta', array('methods' => 'POST', 'callback' => array($this, 'post_blog_apply_meta'), 'permission_callback' => $perm));
        register_rest_route(self::NS, '/brand/personas', array('methods' => 'GET', 'callback' => array($this, 'get_brand_personas'), 'permission_callback' => $perm));
    }

    /** Blog writer: fetch the workspace personas (from the active communication manual) for the picker. */
    public function get_brand_personas(WP_REST_Request $request) {
        $r = $this->call_bridge(array('action' => 'brand_personas'), 20);
        if (is_wp_error($r)) {
            return $r;
        }
        if (200 !== $r['status']) {
            return new WP_Error('redaquest_personas_failed', __('Could not load personas.', 'redaquest-connector'), array('status' => 502));
        }
        return rest_ensure_response($r['body']);
    }

    /** Editor-only. The REST cookie nonce is validated by core before this runs. */
    public function can_edit() {
        return current_user_can('edit_posts');
    }

    /**
     * Outline/draft AI calls can run 90–120s upstream. Without this, default PHP
     * max_execution_time (30–60s) kills the REST handler and the editor sees
     * "The response is not a valid JSON response."
     */
    private function extend_runtime($seconds = 300) {
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit((int) $seconds);
        }
    }

    /** Strip invalid UTF-8 so wp_json_encode does not break the REST envelope. */
    private function sanitize_for_json($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitize_for_json($value);
            }
            return $data;
        }
        if (is_string($data)) {
            if (function_exists('mb_convert_encoding')) {
                $clean = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
                return false !== $clean ? $clean : '';
            }
            return wp_check_invalid_utf8($data, true);
        }
        return $data;
    }

    /** Wrap a successful wp-bridge payload for REST output. */
    private function rest_bridge_body($body) {
        if (!is_array($body)) {
            return new WP_Error(
                'redaquest_upstream',
                __('RedaQuest returned an empty response.', 'redaquest-connector'),
                array('status' => 502)
            );
        }
        return rest_ensure_response($this->sanitize_for_json($body));
    }

    public function get_status() {
        return rest_ensure_response(array(
            'connected'   => Redaquest_Connect::is_connected(),
            'workspaceId' => (string) get_option(Redaquest_Connect::OPT_WORKSPACE, ''),
            'connectUrl'  => Redaquest_Connect::build_connect_url(),
            'appUrl'      => Redaquest_Connect::app_url(),
        ));
    }

    /**
     * Forward a wp-bridge action with the stored token.
     *
     * @return array{status:int,body:array}|WP_Error
     */
    private function call_bridge($payload, $timeout = 60) {
        $token = Redaquest_Connect::get_token();
        if ('' === $token) {
            return new WP_Error('redaquest_not_connected', __('This site is not connected to RedaQuest.', 'redaquest-connector'), array('status' => 409));
        }

        $response = wp_remote_post(Redaquest_Connect::functions_url() . '/wp-bridge', array(
            'timeout' => $timeout,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            $msg = ('http_request_failed' === $response->get_error_code())
                ? __('Could not reach RedaQuest (timeout or network error).', 'redaquest-connector')
                : __('Could not reach RedaQuest.', 'redaquest-connector');
            return new WP_Error('redaquest_upstream', $msg, array('status' => 502));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if (401 === $status) {
            return new WP_Error('redaquest_not_connected', __('The connection expired. Please reconnect your site.', 'redaquest-connector'), array('status' => 409));
        }

        $raw_body = wp_remote_retrieve_body($response);
        $decoded  = json_decode($raw_body, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            return new WP_Error(
                'redaquest_upstream',
                __('RedaQuest returned an invalid response.', 'redaquest-connector'),
                array('status' => 502)
            );
        }

        return array('status' => $status, 'body' => $decoded);
    }

    public function get_accounts() {
        $r = $this->call_bridge(array('action' => 'list_accounts'), 20);
        if (is_wp_error($r)) {
            return $r;
        }
        if (200 !== $r['status']) {
            return new WP_Error('redaquest_upstream', __('RedaQuest returned an error.', 'redaquest-connector'), array('status' => 502));
        }
        $body = $r['body'];
        return rest_ensure_response(array('accounts' => isset($body['accounts']) && is_array($body['accounts']) ? $body['accounts'] : array()));
    }

    public function post_generate(WP_REST_Request $request) {
        $article = $request->get_param('article');
        if (!is_array($article) || empty($article['title']) || !isset($article['body'])) {
            return new WP_Error('redaquest_bad_request', __('Missing article (title and content).', 'redaquest-connector'), array('status' => 400));
        }

        $payload = array(
            'action'  => 'generate',
            'article' => array(
                'title'   => sanitize_text_field($article['title']),
                'body'    => sanitize_textarea_field($article['body']), // plain text for the AI
            ),
        );
        if (!empty($article['url'])) {
            $payload['article']['url'] = esc_url_raw($article['url']);
        }
        if (!empty($article['excerpt'])) {
            $payload['article']['excerpt'] = sanitize_textarea_field($article['excerpt']);
        }
        $platforms = $request->get_param('platforms');
        if (is_array($platforms)) {
            $payload['platforms'] = array_values(array_map('sanitize_key', $platforms));
        }

        $r = $this->call_bridge($payload, 90); // generation can take tens of seconds
        if (is_wp_error($r)) {
            return $r;
        }
        if (402 === $r['status']) {
            return new WP_REST_Response($r['body'], 402); // insufficient credits, forwarded
        }
        if (200 !== $r['status']) {
            $code = ( is_array($r['body']) && ! empty($r['body']['error_code']) ) ? ' (' . $r['body']['error_code'] . ')' : '';
            return new WP_Error('redaquest_generate_failed', __('Generation failed.', 'redaquest-connector') . $code, array('status' => 502));
        }
        return rest_ensure_response($r['body']);
    }

    public function post_generate_image(WP_REST_Request $request) {
        $article = $request->get_param('article');
        if (!is_array($article) || empty($article['title']) || !isset($article['body'])) {
            return new WP_Error('redaquest_bad_request', __('Missing article (title and content).', 'redaquest-connector'), array('status' => 400));
        }

        $payload = array(
            'action'  => 'generate_image',
            'article' => array(
                'title' => sanitize_text_field($article['title']),
                'body'  => sanitize_textarea_field($article['body']),
            ),
        );
        if (!empty($article['excerpt'])) {
            $payload['article']['excerpt'] = sanitize_textarea_field($article['excerpt']);
        }
        $instruction = $request->get_param('instruction');
        if (is_string($instruction) && '' !== trim($instruction)) {
            $payload['instruction'] = sanitize_textarea_field($instruction);
        }
        $type = $request->get_param('type');
        if (is_string($type) && '' !== trim($type)) {
            $payload['type'] = sanitize_text_field($type);
        }
        $style = $request->get_param('style');
        if (is_string($style) && '' !== trim($style)) {
            $payload['style'] = sanitize_text_field($style);
        }
        $quality = $request->get_param('imageQuality');
        if ('fast' === $quality || 'quality' === $quality) {
            $payload['imageQuality'] = $quality;
        }

        $r = $this->call_bridge($payload, 120); // image generation can take a while
        if (is_wp_error($r)) {
            return $r;
        }
        if (402 === $r['status']) {
            return new WP_REST_Response($r['body'], 402);
        }
        if (200 !== $r['status'] || empty($r['body']['imageUrl'])) {
            $code = ( is_array($r['body']) && ! empty($r['body']['error_code']) ) ? ' (' . $r['body']['error_code'] . ')' : '';
            return new WP_Error('redaquest_image_failed', __('Image generation failed.', 'redaquest-connector') . $code, array('status' => 502));
        }

        $image_url = $r['body']['imageUrl'];
        $alt       = isset($r['body']['altText']) ? sanitize_text_field($r['body']['altText']) : '';
        $post_id   = (int) $request->get_param('postId');

        // Alt / caption / description from the article (or section) text we sent — already in the
        // generation language — so the media item is described properly, not left blank.
        $article_in  = $request->get_param('article');
        $art_title   = ( is_array($article_in) && ! empty($article_in['title']) ) ? sanitize_text_field($article_in['title']) : $alt;
        $art_excerpt = ( is_array($article_in) && ! empty($article_in['excerpt']) ) ? sanitize_textarea_field($article_in['excerpt']) : '';
        if ('' === $alt) {
            $alt = $art_title;
        }
        $caption     = $art_excerpt;
        $description = $art_excerpt;

        $set_featured_param = $request->get_param('setFeatured');
        $set_featured = (null === $set_featured_param) ? true : filter_var($set_featured_param, FILTER_VALIDATE_BOOLEAN);

        // Section image (setFeatured=false): add to the media library and return a block descriptor;
        // never touch the post's featured image. The editor inserts it as a core/image after the heading.
        if ($post_id && !$set_featured) {
            $att_id = $this->sideload_compressed_image($image_url, $post_id, $alt, $caption, $description);
            if (!is_wp_error($att_id)) {
                return rest_ensure_response(array(
                    'mediaId'  => (int) $att_id,
                    'mediaUrl' => wp_get_attachment_url($att_id),
                    'altText'  => $alt,
                    'imageUrl' => $image_url,
                ));
            }
            return rest_ensure_response(array('imageUrl' => $image_url, 'altText' => $alt));
        }

        // Download, RE-ENCODE to WebP (or JPEG) to cut the size, add to the media library and set it as
        // the post's featured image (shows immediately + becomes the social post media).
        if ($post_id) {
            $att_id = $this->sideload_compressed_image($image_url, $post_id, $alt, $caption, $description);
            if (!is_wp_error($att_id)) {
                set_post_thumbnail($post_id, (int) $att_id);
                // Keep the ORIGINAL (robust PNG/JPEG) for the social post; the WP featured stays a small
                // WebP for the site. On schedule we send the original to RedaQuest, not the WebP.
                update_post_meta($post_id, '_redaquest_social_image', esc_url_raw($image_url));
                update_post_meta($post_id, '_redaquest_social_image_att', (int) $att_id);
                return rest_ensure_response(array(
                    'featuredMediaId' => (int) $att_id,
                    'imageUrl'        => wp_get_attachment_image_url($att_id, 'medium'),
                    'altText'         => $alt,
                ));
            }
        }

        // Couldn't sideload (e.g. unsaved post) — return the remote URL so the panel can still use it.
        return rest_ensure_response(array('imageUrl' => $image_url, 'altText' => $alt));
    }

    /**
     * Download a remote image and re-encode it to WebP (falling back to JPEG) before adding it to the
     * media library. The generator returns a large PNG (~1 MB); WebP/JPEG at q82 is a fraction of that.
     *
     * @return int|WP_Error attachment ID on success.
     */
    private function sideload_compressed_image($image_url, $post_id, $alt, $caption = '', $description = '') {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $editor = wp_get_image_editor($tmp);
        if (is_wp_error($editor)) {
            wp_delete_file($tmp);
            return $editor;
        }

        // Prefer WebP; fall back to JPEG when the server lacks WebP support.
        $mime = $editor->supports_mime_type('image/webp') ? 'image/webp' : 'image/jpeg';
        $ext  = ('image/webp' === $mime) ? 'webp' : 'jpg';

        // Featured-image sizing: cap dimensions, then step quality down until the file is <= 150 KB.
        $editor->resize(1200, 1200, false);

        $uploads  = wp_upload_dir();
        $filename = 'redaquest-' . $post_id . '-' . time() . '.' . $ext;
        $dest     = trailingslashit($uploads['path']) . $filename;

        $max_bytes = 150 * 1024;
        $quality   = 80;
        $saved     = null;
        while (true) {
            $editor->set_quality($quality);
            $saved = $editor->save($dest, $mime);
            if (is_wp_error($saved)) {
                wp_delete_file($tmp);
                return $saved;
            }
            $size = @filesize($saved['path']);
            if (false === $size || $size <= $max_bytes || $quality <= 40) {
                break;
            }
            $quality -= 12;
        }
        wp_delete_file($tmp);

        $attachment = array(
            'post_mime_type' => $saved['mime-type'],
            'post_title'     => $alt ? $alt : sanitize_file_name(pathinfo($saved['path'], PATHINFO_FILENAME)),
            'post_excerpt'   => $caption,     // WP media "Caption"
            'post_content'   => $description, // WP media "Description"
            'post_status'    => 'inherit',
        );
        $att_id = wp_insert_attachment($attachment, $saved['path'], $post_id);
        if (is_wp_error($att_id) || ! $att_id) {
            wp_delete_file($saved['path']);
            return is_wp_error($att_id) ? $att_id : new WP_Error('redaquest_attach_failed', __('Could not save the image.', 'redaquest-connector'));
        }

        wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $saved['path']));
        if ($alt) {
            update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
        }
        return (int) $att_id;
    }

    public function post_schedule(WP_REST_Request $request) {
        $source_post_id = $request->get_param('sourcePostId');

        // Resolve media. An explicit media array (e.g. a generated image) wins; otherwise, if the
        // panel asked for it, attach the article's featured image resolved SERVER-SIDE (reliable,
        // no dependency on the editor's async media store).
        $media = $request->get_param('media');
        if (!is_array($media) || empty($media)) {
            $media = null;
        }
        if (null === $media && $request->get_param('useFeaturedImage') && $source_post_id) {
            $thumb_id = get_post_thumbnail_id((int) $source_post_id);
            if ($thumb_id) {
                // If the featured image is the one WE generated, send its ORIGINAL (robust PNG/JPEG) to
                // social — the WP featured itself is an optimized WebP. For a user's own featured image,
                // send that image as-is.
                $social_att = (int) get_post_meta((int) $source_post_id, '_redaquest_social_image_att', true);
                $social_url = (string) get_post_meta((int) $source_post_id, '_redaquest_social_image', true);
                $alt        = (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true);

                if ($social_url && $social_att === (int) $thumb_id) {
                    $media = array(array(
                        'url'  => esc_url_raw($social_url),
                        'type' => 'image',
                        'alt'  => sanitize_text_field($alt),
                    ));
                } else {
                    $url = wp_get_attachment_image_url($thumb_id, 'large');
                    if ($url) {
                        $media = array(array(
                            'url'  => esc_url_raw($url),
                            'type' => 'image',
                            'alt'  => sanitize_text_field($alt),
                        ));
                    }
                }
            }
        }

        $payload = array(
            'action'             => 'schedule',
            'title'              => sanitize_text_field((string) $request->get_param('title')),
            'content'            => $request->get_param('content'),
            'firstComments'      => $request->get_param('firstComments'),
            'contentTypes'       => $request->get_param('contentTypes'),
            'platforms'          => $request->get_param('platforms'),
            'selectedAccountIds' => $request->get_param('selectedAccountIds'),
            'scheduledDate'      => sanitize_text_field((string) $request->get_param('scheduledDate')),
            'media'              => $media,
            'sourceUrl'          => $request->get_param('sourceUrl') ? esc_url_raw($request->get_param('sourceUrl')) : null,
            'sourcePostId'       => ( null !== $source_post_id ) ? (string) $source_post_id : null,
        );
        // Drop null optionals so the upstream strict schema doesn't reject them.
        $payload = array_filter($payload, static function ($v) {
            return null !== $v;
        });

        $r = $this->call_bridge($payload, 90);
        if (is_wp_error($r)) {
            return $r;
        }
        if (402 === $r['status']) {
            return new WP_REST_Response($r['body'], 402);
        }
        if (200 !== $r['status']) {
            $code = ( is_array($r['body']) && ! empty($r['body']['error_code']) ) ? ' (' . $r['body']['error_code'] . ')' : '';
            return new WP_Error('redaquest_schedule_failed', __('Scheduling failed.', 'redaquest-connector') . $code, array('status' => 502));
        }
        return rest_ensure_response($r['body']);
    }

    /**
     * Blog writer: generate a GEO article OUTLINE (brake step). Forwards to wp-bridge.
     */
    public function post_blog_outline(WP_REST_Request $request) {
        $this->extend_runtime(300);

        $topic = trim((string) $request->get_param('topic'));
        if ('' === $topic) {
            return new WP_Error('redaquest_bad_request', __('Missing topic.', 'redaquest-connector'), array('status' => 400));
        }

        $payload = array('action' => 'blog_outline', 'topic' => sanitize_text_field($topic));
        $this->fill_blog_inputs($payload, $request);
        $payload['webResearch'] = (bool) $request->get_param('webResearch');
        $urls = $request->get_param('sourceUrls');
        if (is_array($urls)) {
            $payload['sourceUrls'] = array_values(array_filter(array_map('esc_url_raw', $urls)));
        }
        $notes = $request->get_param('notes');
        if (is_string($notes) && '' !== trim($notes)) {
            $payload['notes'] = sanitize_textarea_field($notes);
        }

        $r = $this->call_bridge($payload, 180); // research + model can exceed 90s
        if (is_wp_error($r)) {
            return $r;
        }
        if (402 === $r['status']) {
            return new WP_REST_Response($r['body'], 402);
        }
        if (200 !== $r['status']) {
            return new WP_Error('redaquest_blog_outline_failed', __('Outline generation failed, please try again.', 'redaquest-connector') . $this->blog_error_reason($r['body']), array('status' => 502));
        }
        return $this->rest_bridge_body($r['body']);
    }

    /**
     * Blog writer: generate the full article from an approved outline. Forwards to wp-bridge.
     */
    public function post_blog_draft(WP_REST_Request $request) {
        $this->extend_runtime(360);

        $topic = trim((string) $request->get_param('topic'));
        $outline = (string) $request->get_param('approvedOutline');
        if ('' === $topic || '' === trim($outline)) {
            return new WP_Error('redaquest_bad_request', __('Missing topic or outline.', 'redaquest-connector'), array('status' => 400));
        }

        $payload = array(
            'action'          => 'blog_draft',
            'topic'           => sanitize_text_field($topic),
            'approvedOutline' => sanitize_textarea_field($outline),
        );
        $this->fill_blog_inputs($payload, $request);
        $research = $request->get_param('researchContext');
        if (is_string($research) && '' !== $research) {
            $payload['researchContext'] = sanitize_textarea_field($research);
        }
        $sources = $request->get_param('sources');
        if (is_array($sources)) {
            $clean = array();
            foreach ($sources as $s) {
                if (!empty($s['url'])) {
                    $clean[] = array(
                        'title' => isset($s['title']) ? sanitize_text_field($s['title']) : '',
                        'url'   => esc_url_raw($s['url']),
                    );
                }
            }
            if ($clean) $payload['sources'] = $clean;
        }

        $r = $this->call_bridge($payload, 240); // long-form generation
        if (is_wp_error($r)) {
            return $r;
        }
        if (402 === $r['status']) {
            return new WP_REST_Response($r['body'], 402);
        }
        if (200 !== $r['status']) {
            return new WP_Error('redaquest_blog_draft_failed', __('Article generation failed, please try again.', 'redaquest-connector') . $this->blog_error_reason($r['body']), array('status' => 502));
        }
        return $this->rest_bridge_body($r['body']);
    }

    /**
     * Blog writer: write SEO meta + excerpt + slug onto the post (server-side, so we can target
     * whichever SEO plugin is active). The article body itself is inserted by the editor (JS).
     */
    public function post_blog_apply_meta(WP_REST_Request $request) {
        $post_id = (int) $request->get_param('postId');
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return new WP_Error('redaquest_forbidden', __('You cannot edit this post.', 'redaquest-connector'), array('status' => 403));
        }

        $meta_title = sanitize_text_field((string) $request->get_param('metaTitle'));
        $meta_desc  = sanitize_textarea_field((string) $request->get_param('metaDescription'));
        $excerpt    = sanitize_textarea_field((string) $request->get_param('excerpt'));
        $slug       = sanitize_title((string) $request->get_param('slug'));

        // Detect the active SEO plugin (for the editor notice). The meta itself is written to every
        // known plugin's storage below, so there is no duplicate "RedaQuest SEO" UI — the values land
        // directly in whatever SEO plugin the user already runs. Order = popularity.
        $seo_plugin = '';
        if (defined('WPSEO_VERSION')) {
            $seo_plugin = 'Yoast SEO';
        } elseif (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            $seo_plugin = 'Rank Math';
        } elseif (defined('SEOPRESS_VERSION')) {
            $seo_plugin = 'SEOPress';
        } elseif (defined('AIOSEO_VERSION') || function_exists('aioseo')) {
            $seo_plugin = 'All in One SEO';
        } elseif (defined('THE_SEO_FRAMEWORK_VERSION')) {
            $seo_plugin = 'The SEO Framework';
        } elseif (defined('SLIM_SEO_VER')) {
            $seo_plugin = 'Slim SEO';
        }

        // Meta-key storage (a write for an inactive plugin is harmless — it just leaves unused post meta).
        if ('' !== $meta_title) {
            update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);    // Yoast SEO
            update_post_meta($post_id, 'rank_math_title', $meta_title);        // Rank Math
            update_post_meta($post_id, '_seopress_titles_title', $meta_title); // SEOPress
            update_post_meta($post_id, '_genesis_title', $meta_title);         // The SEO Framework
        }
        if ('' !== $meta_desc) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
            update_post_meta($post_id, 'rank_math_description', $meta_desc);
            update_post_meta($post_id, '_seopress_titles_desc', $meta_desc);
            update_post_meta($post_id, '_genesis_description', $meta_desc);
        }

        // Slim SEO keeps everything in a single array meta.
        if (defined('SLIM_SEO_VER') && ('' !== $meta_title || '' !== $meta_desc)) {
            $ss = get_post_meta($post_id, 'slim_seo', true);
            if (!is_array($ss)) {
                $ss = array();
            }
            if ('' !== $meta_title) {
                $ss['title'] = $meta_title;
            }
            if ('' !== $meta_desc) {
                $ss['description'] = $meta_desc;
            }
            update_post_meta($post_id, 'slim_seo', $ss);
        }

        // All in One SEO v4 stores SEO in its own table. Best-effort: only UPDATE an existing row
        // (AIOSEO creates the row itself), so we never risk a failing NOT NULL insert.
        if ((defined('AIOSEO_VERSION') || function_exists('aioseo')) && ('' !== $meta_title || '' !== $meta_desc)) {
            global $wpdb;
            $table = $wpdb->prefix . 'aioseo_posts';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table-existence probe
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($table_exists) {
                $fields = array();
                if ('' !== $meta_title) {
                    $fields['title'] = $meta_title;
                }
                if ('' !== $meta_desc) {
                    $fields['description'] = $meta_desc;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix
                $row_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %d", $post_id));
                if ($row_id && $fields) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- single targeted SEO update
                    $wpdb->update($table, $fields, array('post_id' => $post_id));
                }
            }
        }

        $post_update = array('ID' => $post_id);
        if ('' !== $excerpt) $post_update['post_excerpt'] = $excerpt;
        if ('' !== $slug)    $post_update['post_name'] = $slug;
        if (count($post_update) > 1) {
            wp_update_post($post_update);
        }

        return rest_ensure_response(array('success' => true, 'seoPlugin' => $seo_plugin));
    }

    /** Extract a human-readable reason from a failed wp-bridge blog response (surfaces the real upstream cause). */
    private function blog_error_reason($body) {
        if (!is_array($body)) {
            return '';
        }
        if (!empty($body['error_meta']['upstream'])) {
            return ' (' . sanitize_text_field((string) $body['error_meta']['upstream']) . ')';
        }
        if (!empty($body['error_code'])) {
            return ' (' . sanitize_text_field((string) $body['error_code']) . ')';
        }
        return '';
    }

    /** Shared optional blog inputs (angle, keywords, length, language) from the request into a payload. */
    private function fill_blog_inputs(array &$payload, WP_REST_Request $request) {
        $angle = $request->get_param('angle');
        if (is_array($angle)) {
            $clean = array();
            foreach (array('thesis', 'example', 'audience') as $k) {
                if (!empty($angle[$k])) $clean[$k] = sanitize_textarea_field($angle[$k]);
            }
            if ($clean) $payload['angle'] = $clean;
        }
        $keywords = $request->get_param('keywords');
        if (is_array($keywords)) {
            $payload['keywords'] = array_values(array_filter(array_map('sanitize_text_field', $keywords)));
        }
        $length = $request->get_param('length');
        if ('pillar' === $length || 'spoke' === $length) {
            $payload['length'] = $length;
        }
        $language = $request->get_param('language');
        if (is_string($language) && '' !== $language) {
            $payload['language'] = sanitize_text_field($language);
        }
        // Existing material the user pasted/uploaded — the article is built on it.
        $source_content = $request->get_param('sourceContent');
        if (is_string($source_content) && '' !== trim($source_content)) {
            $payload['sourceContent'] = sanitize_textarea_field($source_content);
        }
    }
}
