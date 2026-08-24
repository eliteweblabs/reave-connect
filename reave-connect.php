<?php
/**
 * Plugin Name:  Reave Connect
 * Plugin URI:   https://reave.app/
 * Description:  Secure REST API bridge for remote WordPress management via Reave Automation. Supports posts, pages, media, plugin install/activate, option updates, and auto-updates from reave.app.
 * Version:      1.1.0
 * Author:       Elite Web Labs
 * Author URI:   https://eliteweblabs.com/
 * License:      GPL-2.0+
 * Text Domain:  reave-connect
 * Update URI:   https://reave.app/api/wp-update/reave-connect
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'REAVE_CONNECT_VERSION', '1.1.0' );
define( 'REAVE_CONNECT_UPDATE_URL', 'https://reave.app/api/wp-update/reave-connect' );

// ---------------------------------------------------------------------------
// 1. Auto-update support (WordPress update API hook)
// ---------------------------------------------------------------------------

add_filter( 'plugins_api', 'reave_connect_plugins_api', 20, 3 );
function reave_connect_plugins_api( $result, $action, $args ) {
    if ( $action !== 'plugin_information' ) return $result;
    if ( ! isset( $args->slug ) || $args->slug !== 'reave-connect' ) return $result;

    $remote = reave_connect_fetch_update_info();
    if ( ! $remote ) return $result;

    $info = new stdClass();
    $info->name        = $remote['name'] ?? 'Reave Connect';
    $info->slug        = 'reave-connect';
    $info->version     = $remote['version'] ?? REAVE_CONNECT_VERSION;
    $info->author      = '<a href="https://eliteweblabs.com/">Elite Web Labs</a>';
    $info->download_link = $remote['download_url'] ?? '';
    $info->sections    = [ 'description' => $remote['description'] ?? '' ];
    return $info;
}

add_filter( 'site_transient_update_plugins', 'reave_connect_check_for_update' );
function reave_connect_check_for_update( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;

    $remote = reave_connect_fetch_update_info();
    if ( ! $remote ) return $transient;

    $plugin_file = plugin_basename( __FILE__ );
    $current_version = $transient->checked[ $plugin_file ] ?? REAVE_CONNECT_VERSION;

    if ( version_compare( $remote['version'], $current_version, '>' ) ) {
        $item = new stdClass();
        $item->slug         = 'reave-connect';
        $item->plugin       = $plugin_file;
        $item->new_version  = $remote['version'];
        $item->url          = 'https://reave.app/';
        $item->package      = $remote['download_url'];
        $item->tested       = $remote['tested'] ?? '';
        $item->requires_php = $remote['requires_php'] ?? '7.4';
        $item->icons        = [];
        $transient->response[ $plugin_file ] = $item;
    }

    return $transient;
}

function reave_connect_fetch_update_info(): ?array {
    $cached = get_transient( 'reave_connect_update_info' );
    if ( $cached ) return $cached;

    $response = wp_remote_get( REAVE_CONNECT_UPDATE_URL . '/info.json', [
        'timeout' => 10,
        'headers' => [ 'Accept' => 'application/json' ],
    ] );

    if ( is_wp_error( $response ) ) return null;
    if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return null;

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) ) return null;

    set_transient( 'reave_connect_update_info', $data, HOUR_IN_SECONDS * 6 );
    return $data;
}

// ---------------------------------------------------------------------------
// 2. REST API endpoint: /wp-json/reave/v1/exec
// ---------------------------------------------------------------------------

add_action( 'rest_api_init', function () {
    register_rest_route( 'reave/v1', '/exec', [
        'methods'             => 'POST',
        'callback'            => 'reave_connect_exec',
        'permission_callback' => 'reave_connect_auth',
    ] );

    register_rest_route( 'reave/v1', '/status', [
        'methods'             => 'GET',
        'callback'            => 'reave_connect_status',
        'permission_callback' => 'reave_connect_auth',
    ] );
} );

function reave_connect_auth( WP_REST_Request $request ): bool {
    $expected = defined( 'REAVE_API_KEY' ) ? REAVE_API_KEY : get_option( 'reave_api_key', '' );
    if ( ! $expected ) return false;

    $provided = $request->get_header( 'X-Reave-Key' );
    if ( ! $provided ) {
        // Also allow query param for testing
        $provided = $request->get_param( 'key' );
    }

    return hash_equals( $expected, (string) $provided );
}

function reave_connect_ensure_admin(): void {
    if ( get_current_user_id() ) return;
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
    if ( $admins ) wp_set_current_user( $admins[0]->ID );
}

function reave_connect_serialize_post( WP_Post $post ): array {
    return [
        'id'       => (int) $post->ID,
        'type'     => $post->post_type,
        'status'   => $post->post_status,
        'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
        'slug'     => $post->post_name,
        'excerpt'  => html_entity_decode( wp_strip_all_tags( $post->post_excerpt ), ENT_QUOTES, 'UTF-8' ),
        'url'      => get_permalink( $post ),
        'date'     => $post->post_date,
        'modified' => $post->post_modified,
        'featured_media' => (int) get_post_thumbnail_id( $post ),
    ];
}

function reave_connect_serialize_media( int $id ): ?array {
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== 'attachment' ) return null;
    $file = get_attached_file( $id );
    return [
        'id'       => $id,
        'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
        'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
        'caption'  => html_entity_decode( wp_strip_all_tags( $post->post_excerpt ), ENT_QUOTES, 'UTF-8' ),
        'mime'     => $post->post_mime_type,
        'url'      => wp_get_attachment_url( $id ),
        'filename' => $file ? basename( $file ) : '',
        'date'     => $post->post_date,
    ];
}

function reave_connect_status( WP_REST_Request $request ): WP_REST_Response {
    return new WP_REST_Response( [
        'ok'          => true,
        'site_url'    => get_site_url(),
        'wp_version'  => get_bloginfo( 'version' ),
        'plugin_version' => REAVE_CONNECT_VERSION,
        'php_version' => PHP_VERSION,
    ], 200 );
}

function reave_connect_exec( WP_REST_Request $request ): WP_REST_Response {
    $action = sanitize_text_field( $request->get_param( 'action' ) ?? '' );
    $params = $request->get_param( 'params' ) ?? [];

    if ( ! $action ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'action is required' ], 400 );
    }

    switch ( $action ) {

        // --- Options ---
        case 'get_option':
            $key = sanitize_text_field( $params['key'] ?? '' );
            return new WP_REST_Response( [ 'ok' => true, 'value' => get_option( $key ) ], 200 );

        case 'update_option':
            $key = sanitize_text_field( $params['key'] ?? '' );
            $val = $params['value'] ?? '';
            update_option( $key, $val );
            return new WP_REST_Response( [ 'ok' => true, 'key' => $key, 'value' => get_option( $key ) ], 200 );

        // --- Search Indexing ---
        case 'enable_indexing':
            update_option( 'blog_public', 1 );
            return new WP_REST_Response( [ 'ok' => true, 'blog_public' => 1, 'message' => 'Search engine indexing enabled.' ], 200 );

        case 'disable_indexing':
            update_option( 'blog_public', 0 );
            return new WP_REST_Response( [ 'ok' => true, 'blog_public' => 0, 'message' => 'Search engine indexing disabled.' ], 200 );

        case 'get_indexing_status':
            $pub = (int) get_option( 'blog_public', 1 );
            return new WP_REST_Response( [
                'ok'          => true,
                'blog_public' => $pub,
                'indexing'    => $pub === 1 ? 'enabled' : 'disabled',
            ], 200 );

        // --- Plugins ---
        case 'list_plugins':
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugins = get_plugins();
            $active  = get_option( 'active_plugins', [] );
            $out = [];
            foreach ( $plugins as $file => $data ) {
                $out[] = [
                    'file'    => $file,
                    'name'    => $data['Name'],
                    'version' => $data['Version'],
                    'active'  => in_array( $file, $active, true ),
                ];
            }
            return new WP_REST_Response( [ 'ok' => true, 'plugins' => $out ], 200 );

        case 'activate_plugin':
            if ( ! function_exists( 'activate_plugin' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $slug = sanitize_text_field( $params['slug'] ?? '' );
            $result = activate_plugin( $slug );
            if ( is_wp_error( $result ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => $result->get_error_message() ], 400 );
            }
            return new WP_REST_Response( [ 'ok' => true, 'activated' => $slug ], 200 );

        case 'deactivate_plugin':
            if ( ! function_exists( 'deactivate_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $slug = sanitize_text_field( $params['slug'] ?? '' );
            deactivate_plugins( $slug );
            return new WP_REST_Response( [ 'ok' => true, 'deactivated' => $slug ], 200 );

        case 'install_plugin':
            if ( ! current_user_can( 'install_plugins' ) ) {
                // Run as admin — bypass capability check via temp filter
            }
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

            $plugin_slug = sanitize_text_field( $params['slug'] ?? '' );
            $activate    = filter_var( $params['activate'] ?? true, FILTER_VALIDATE_BOOLEAN );

            // Fetch plugin info from WordPress.org
            include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            $api = plugins_api( 'plugin_information', [
                'slug'   => $plugin_slug,
                'fields' => [ 'download_link' => true ],
            ] );

            if ( is_wp_error( $api ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => $api->get_error_message() ], 400 );
            }

            // Suppress output during install
            ob_start();
            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );
            $result   = $upgrader->install( $api->download_link );
            ob_end_clean();

            if ( is_wp_error( $result ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => $result->get_error_message() ], 400 );
            }

            if ( $activate && $result ) {
                $plugin_file = $upgrader->plugin_info();
                if ( $plugin_file ) activate_plugin( $plugin_file );
            }

            return new WP_REST_Response( [
                'ok'        => true,
                'installed' => $plugin_slug,
                'activated' => $activate && $result,
            ], 200 );

        // --- Theme ---
        case 'get_active_theme':
            $theme = wp_get_theme();
            return new WP_REST_Response( [
                'ok'      => true,
                'name'    => $theme->get( 'Name' ),
                'version' => $theme->get( 'Version' ),
            ], 200 );

        // --- Cache (common plugins) ---
        case 'flush_cache':
            $flushed = [];
            if ( function_exists( 'w3tc_flush_all' ) )      { w3tc_flush_all(); $flushed[] = 'W3 Total Cache'; }
            if ( function_exists( 'wp_cache_flush' ) )       { wp_cache_flush(); $flushed[] = 'Object Cache'; }
            if ( function_exists( 'rocket_clean_domain' ) )  { rocket_clean_domain(); $flushed[] = 'WP Rocket'; }
            if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache(); $flushed[] = 'SG Optimizer'; }
            return new WP_REST_Response( [ 'ok' => true, 'flushed' => $flushed ], 200 );

        // --- Site info ---
        case 'site_info':
            return new WP_REST_Response( [
                'ok'          => true,
                'site_url'    => get_site_url(),
                'site_name'   => get_bloginfo( 'name' ),
                'admin_email' => get_option( 'admin_email' ),
                'wp_version'  => get_bloginfo( 'version' ),
                'php_version' => PHP_VERSION,
                'blog_public' => (int) get_option( 'blog_public', 1 ),
                'active_plugins' => get_option( 'active_plugins', [] ),
            ], 200 );

        // --- Posts / pages ---
        case 'list_content':
            $type = sanitize_key( $params['post_type'] ?? $params['type'] ?? 'page' );
            if ( ! in_array( $type, [ 'post', 'page' ], true ) ) $type = 'page';
            $status = sanitize_key( $params['status'] ?? '' );
            $search = sanitize_text_field( $params['search'] ?? $params['s'] ?? '' );
            $per_page = max( 1, min( 50, (int) ( $params['per_page'] ?? 20 ) ) );
            $page = max( 1, (int) ( $params['page'] ?? 1 ) );
            $query = new WP_Query( [
                'post_type'      => $type,
                'post_status'    => $status ?: [ 'publish', 'draft', 'pending', 'private', 'future' ],
                's'              => $search,
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            ] );
            $items = [];
            foreach ( $query->posts as $post ) {
                if ( $post instanceof WP_Post ) $items[] = reave_connect_serialize_post( $post );
            }
            return new WP_REST_Response( [
                'ok'         => true,
                'items'      => $items,
                'total'      => (int) $query->found_posts,
                'page'       => $page,
                'per_page'   => $per_page,
            ], 200 );

        case 'get_content':
            $id = (int) ( $params['id'] ?? 0 );
            $post = $id ? get_post( $id ) : null;
            if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
            }
            $row = reave_connect_serialize_post( $post );
            $row['content'] = $post->post_content;
            return new WP_REST_Response( [ 'ok' => true, 'item' => $row ], 200 );

        case 'create_content':
        case 'update_content':
            reave_connect_ensure_admin();
            $id = (int) ( $params['id'] ?? 0 );
            $type = sanitize_key( $params['post_type'] ?? $params['type'] ?? 'page' );
            if ( ! in_array( $type, [ 'post', 'page' ], true ) ) $type = 'page';
            $payload = [ 'post_type' => $type ];
            if ( $id ) $payload['ID'] = $id;
            if ( isset( $params['title'] ) ) $payload['post_title'] = wp_kses_post( (string) $params['title'] );
            if ( isset( $params['content'] ) ) $payload['post_content'] = wp_kses_post( (string) $params['content'] );
            if ( isset( $params['excerpt'] ) ) $payload['post_excerpt'] = wp_kses_post( (string) $params['excerpt'] );
            if ( isset( $params['slug'] ) ) $payload['post_name'] = sanitize_title( (string) $params['slug'] );
            if ( isset( $params['status'] ) ) {
                $st = sanitize_key( (string) $params['status'] );
                if ( in_array( $st, [ 'publish', 'draft', 'pending', 'private', 'future' ], true ) ) {
                    $payload['post_status'] = $st;
                }
            }
            if ( $action === 'create_content' && empty( $payload['post_title'] ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'title is required' ], 400 );
            }
            if ( $action === 'create_content' && empty( $payload['post_status'] ) ) {
                $payload['post_status'] = 'draft';
            }
            if ( $action === 'update_content' ) {
                if ( ! $id ) return new WP_REST_Response( [ 'ok' => false, 'error' => 'id is required' ], 400 );
                $existing = get_post( $id );
                if ( ! $existing || ! in_array( $existing->post_type, [ 'post', 'page' ], true ) ) {
                    return new WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
                }
                unset( $payload['post_type'] );
                $saved = wp_update_post( $payload, true );
            } else {
                $saved = wp_insert_post( $payload, true );
            }
            if ( is_wp_error( $saved ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => $saved->get_error_message() ], 400 );
            }
            $post = get_post( (int) $saved );
            return new WP_REST_Response( [
                'ok'   => true,
                'item' => $post instanceof WP_Post ? reave_connect_serialize_post( $post ) : [ 'id' => (int) $saved ],
            ], 200 );

        case 'delete_content':
            reave_connect_ensure_admin();
            $id = (int) ( $params['id'] ?? 0 );
            if ( ! $id ) return new WP_REST_Response( [ 'ok' => false, 'error' => 'id is required' ], 400 );
            $post = get_post( $id );
            if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
            }
            $force = filter_var( $params['force'] ?? false, FILTER_VALIDATE_BOOLEAN );
            $deleted = wp_delete_post( $id, $force );
            if ( ! $deleted ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not delete' ], 400 );
            }
            return new WP_REST_Response( [
                'ok'      => true,
                'id'      => $id,
                'trashed' => ! $force,
            ], 200 );

        // --- Media ---
        case 'list_media':
            $search = sanitize_text_field( $params['search'] ?? $params['s'] ?? '' );
            $per_page = max( 1, min( 50, (int) ( $params['per_page'] ?? 20 ) ) );
            $page = max( 1, (int) ( $params['page'] ?? 1 ) );
            $query = new WP_Query( [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                's'              => $search,
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ] );
            $items = [];
            foreach ( $query->posts as $post ) {
                if ( $post instanceof WP_Post ) {
                    $row = reave_connect_serialize_media( (int) $post->ID );
                    if ( $row ) $items[] = $row;
                }
            }
            return new WP_REST_Response( [
                'ok'       => true,
                'items'    => $items,
                'total'    => (int) $query->found_posts,
                'page'     => $page,
                'per_page' => $per_page,
            ], 200 );

        case 'get_media':
            $id = (int) ( $params['id'] ?? 0 );
            $row = $id ? reave_connect_serialize_media( $id ) : null;
            if ( ! $row ) return new WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
            return new WP_REST_Response( [ 'ok' => true, 'item' => $row ], 200 );

        case 'upload_media':
            reave_connect_ensure_admin();
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $title = sanitize_text_field( $params['title'] ?? '' );
            $alt = sanitize_text_field( $params['alt'] ?? '' );
            $parent = (int) ( $params['post_id'] ?? 0 );
            $url = esc_url_raw( (string) ( $params['url'] ?? '' ) );
            $filename = sanitize_file_name( (string) ( $params['filename'] ?? '' ) );
            $b64 = (string) ( $params['data_base64'] ?? $params['data'] ?? '' );

            $tmp = '';
            if ( $url ) {
                $tmp = download_url( $url, 30 );
                if ( is_wp_error( $tmp ) ) {
                    return new WP_REST_Response( [ 'ok' => false, 'error' => $tmp->get_error_message() ], 400 );
                }
                if ( ! $filename ) $filename = basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'upload.bin' );
            } elseif ( $b64 ) {
                if ( ! $filename ) $filename = 'upload.bin';
                $tmp = wp_tempnam( $filename );
                $decoded = base64_decode( $b64, true );
                if ( $decoded === false || $decoded === '' ) {
                    @unlink( $tmp );
                    return new WP_REST_Response( [ 'ok' => false, 'error' => 'Invalid base64 media data' ], 400 );
                }
                if ( strlen( $decoded ) > 8 * 1024 * 1024 ) {
                    @unlink( $tmp );
                    return new WP_REST_Response( [ 'ok' => false, 'error' => 'Media exceeds 8MB' ], 400 );
                }
                file_put_contents( $tmp, $decoded );
            } else {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'url or data_base64 is required' ], 400 );
            }

            $file_array = [ 'name' => $filename, 'tmp_name' => $tmp ];
            $saved = media_handle_sideload( $file_array, $parent, $title ?: null );
            if ( is_wp_error( $saved ) ) {
                @unlink( $tmp );
                return new WP_REST_Response( [ 'ok' => false, 'error' => $saved->get_error_message() ], 400 );
            }
            if ( $alt ) update_post_meta( (int) $saved, '_wp_attachment_image_alt', $alt );
            $row = reave_connect_serialize_media( (int) $saved );
            return new WP_REST_Response( [ 'ok' => true, 'item' => $row ], 200 );

        case 'set_featured_image':
            reave_connect_ensure_admin();
            $post_id = (int) ( $params['post_id'] ?? $params['id'] ?? 0 );
            $media_id = (int) ( $params['media_id'] ?? 0 );
            if ( ! $post_id || ! $media_id ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'post_id and media_id are required' ], 400 );
            }
            $post = get_post( $post_id );
            if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'Post not found' ], 404 );
            }
            if ( ! wp_attachment_is_image( $media_id ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'media_id is not an image' ], 400 );
            }
            $ok = set_post_thumbnail( $post_id, $media_id );
            if ( ! $ok ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not set featured image' ], 400 );
            }
            $updated = get_post( $post_id );
            return new WP_REST_Response( [
                'ok'      => true,
                'item'    => reave_connect_serialize_post( $updated instanceof WP_Post ? $updated : $post ),
                'media'   => reave_connect_serialize_media( $media_id ),
            ], 200 );

        default:
            return new WP_REST_Response( [
                'ok'    => false,
                'error' => "Unknown action: {$action}",
                'valid_actions' => [
                    'get_option', 'update_option',
                    'enable_indexing', 'disable_indexing', 'get_indexing_status',
                    'list_plugins', 'activate_plugin', 'deactivate_plugin', 'install_plugin',
                    'get_active_theme', 'flush_cache', 'site_info',
                    'list_content', 'get_content', 'create_content', 'update_content', 'delete_content',
                    'list_media', 'get_media', 'upload_media', 'set_featured_image',
                ],
            ], 400 );
    }
}

// ---------------------------------------------------------------------------
// 3. Settings page — store API key in DB as fallback (wp-config.php preferred)
// ---------------------------------------------------------------------------

add_action( 'admin_menu', function () {
    add_options_page(
        'Reave Connect',
        'Reave Connect',
        'manage_options',
        'reave-connect',
        'reave_connect_settings_page'
    );
} );

function reave_connect_settings_page() {
    if ( isset( $_POST['reave_api_key'] ) && check_admin_referer( 'reave_connect_save' ) ) {
        update_option( 'reave_api_key', sanitize_text_field( $_POST['reave_api_key'] ) );
        echo '<div class="notice notice-success"><p>API key saved.</p></div>';
    }
    $key = defined( 'REAVE_API_KEY' ) ? '(set via wp-config.php constant)' : esc_attr( get_option( 'reave_api_key', '' ) );
    ?>
    <div class="wrap">
        <h1>Reave Connect</h1>
        <p>This plugin allows <a href="https://reave.app/" target="_blank">Reave Automation</a> to manage this WordPress site remotely — posts, pages, media, plugins, cache, and options.</p>
        <form method="post">
            <?php wp_nonce_field( 'reave_connect_save' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="reave_api_key">API Key</label></th>
                    <td>
                        <input type="text" name="reave_api_key" id="reave_api_key"
                               value="<?php echo $key; ?>" class="regular-text"
                               <?php echo defined( 'REAVE_API_KEY' ) ? 'disabled' : ''; ?> />
                        <p class="description">
                            Recommended: define <code>REAVE_API_KEY</code> in <code>wp-config.php</code> instead.<br>
                            Endpoint: <code><?php echo esc_html( get_site_url() ); ?>/wp-json/reave/v1/exec</code>
                        </p>
                    </td>
                </tr>
            </table>
            <?php if ( ! defined( 'REAVE_API_KEY' ) ) submit_button( 'Save API Key' ); ?>
        </form>
    </div>
    <?php
}
