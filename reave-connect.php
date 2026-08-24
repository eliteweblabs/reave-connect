<?php
/**
 * Plugin Name:  Reave Connect
 * Plugin URI:   https://reave.app/
 * Description:  Secure REST API bridge for remote WordPress management via Reave Automation. Supports posts, pages, media, plugin install/activate, option updates, and auto-updates from reave.app.
 * Version:      1.2.0
 * Author:       Elite Web Labs
 * Author URI:   https://eliteweblabs.com/
 * License:      GPL-2.0+
 * Text Domain:  reave-connect
 * Update URI:   https://reave.app/api/wp-update/reave-connect
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'REAVE_CONNECT_VERSION', '1.2.0' );
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
            if ( reave_connect_option_blocked( $key ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'This option key is protected' ], 403 );
            }
            return new WP_REST_Response( [ 'ok' => true, 'value' => get_option( $key ) ], 200 );

        case 'update_option':
            $key = sanitize_text_field( $params['key'] ?? '' );
            if ( reave_connect_option_blocked( $key ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'This option key is protected' ], 403 );
            }
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
            return new WP_REST_Response( reave_connect_flush_cache(), 200 );

        case 'flush_rewrite':
            flush_rewrite_rules( true );
            return new WP_REST_Response( [ 'ok' => true, 'message' => 'Rewrite rules flushed.' ], 200 );

        case 'health':
            return new WP_REST_Response( reave_connect_health(), 200 );

        case 'search_replace':
            return reave_connect_search_replace( $params );

        case 'list_menus':
            return new WP_REST_Response( [ 'ok' => true, 'menus' => reave_connect_list_menus() ], 200 );

        case 'get_menu_items':
            $menu_id = (int) ( $params['id'] ?? $params['menu_id'] ?? 0 );
            $items = reave_connect_get_menu_items( $menu_id );
            if ( $items === null ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'Menu not found' ], 404 );
            }
            return new WP_REST_Response( [ 'ok' => true, 'items' => $items ], 200 );

        case 'update_menu_item':
            reave_connect_ensure_admin();
            return reave_connect_update_menu_item( $params );

        case 'list_redirects':
            return new WP_REST_Response( [ 'ok' => true, 'redirects' => reave_connect_list_redirects() ], 200 );

        case 'create_redirect':
            reave_connect_ensure_admin();
            return reave_connect_create_redirect( $params );

        case 'delete_redirect':
            reave_connect_ensure_admin();
            return reave_connect_delete_redirect( (int) ( $params['id'] ?? 0 ) );

        case 'get_post_meta':
            $id = (int) ( $params['id'] ?? 0 );
            if ( ! $id || ! get_post( $id ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
            }
            return new WP_REST_Response( [ 'ok' => true, 'id' => $id, 'meta' => reave_connect_flat_meta( $id ) ], 200 );

        case 'update_post_meta':
            reave_connect_ensure_admin();
            $id = (int) ( $params['id'] ?? 0 );
            $key = sanitize_key( (string) ( $params['key'] ?? '' ) );
            if ( ! $id || ! $key || ! get_post( $id ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'id and key are required' ], 400 );
            }
            update_post_meta( $id, $key, $params['value'] ?? '' );
            return new WP_REST_Response( [ 'ok' => true, 'id' => $id, 'key' => $key ], 200 );

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
            $row['meta'] = reave_connect_flat_meta( (int) $post->ID );
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
            $saved_id = (int) $saved;
            if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
                foreach ( $params['meta'] as $meta_key => $meta_value ) {
                    update_post_meta( $saved_id, sanitize_key( (string) $meta_key ), $meta_value );
                }
            }
            $post = get_post( $saved_id );
            return new WP_REST_Response( [
                'ok'   => true,
                'item' => $post instanceof WP_Post ? reave_connect_serialize_post( $post ) : [ 'id' => $saved_id ],
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
                    'get_active_theme', 'flush_cache', 'flush_rewrite', 'site_info', 'health',
                    'search_replace',
                    'list_content', 'get_content', 'create_content', 'update_content', 'delete_content',
                    'get_post_meta', 'update_post_meta',
                    'list_media', 'get_media', 'upload_media', 'set_featured_image',
                    'list_menus', 'get_menu_items', 'update_menu_item',
                    'list_redirects', 'create_redirect', 'delete_redirect',
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

function reave_connect_option_blocked( string $key ): bool {
    $blocked = [
        'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
        'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
        'reave_api_key',
    ];
    return in_array( sanitize_key( $key ), $blocked, true );
}

function reave_connect_flat_meta( int $id ): array {
    $raw = get_post_meta( $id );
    $out = [];
    foreach ( $raw as $key => $values ) {
        if ( strpos( (string) $key, '_acf_changed' ) === 0 ) continue;
        $val = count( $values ) === 1 ? $values[0] : $values;
        if ( is_string( $val ) && is_serialized( $val ) ) {
            $un = @unserialize( $val );
            if ( $un !== false ) $val = $un;
        }
        $out[ $key ] = $val;
    }
    return $out;
}

function reave_connect_health(): array {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all = get_plugins();
    $active = get_option( 'active_plugins', [] );
    $plugins = [];
    foreach ( $all as $file => $data ) {
        $plugins[] = [
            'file'    => $file,
            'name'    => $data['Name'],
            'version' => $data['Version'],
            'active'  => in_array( $file, $active, true ),
        ];
    }
    global $wpdb;
    $theme = wp_get_theme();
    return [
        'ok'           => true,
        'site_url'     => get_site_url(),
        'site_name'    => get_bloginfo( 'name' ),
        'tagline'      => get_bloginfo( 'description' ),
        'wp_version'   => get_bloginfo( 'version' ),
        'php_version'  => PHP_VERSION,
        'db_version'   => $wpdb->db_version(),
        'theme'        => $theme->get( 'Name' ),
        'timezone'     => get_option( 'timezone_string' ) ?: get_option( 'gmt_offset' ),
        'language'     => get_bloginfo( 'language' ),
        'plugins'      => $plugins,
        'memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
        'debug_mode'   => defined( 'WP_DEBUG' ) && WP_DEBUG,
        'plugin_version' => REAVE_CONNECT_VERSION,
    ];
}

function reave_connect_flush_cache(): array {
    $flushed = [];
    if ( function_exists( 'wp_cache_flush' ) && wp_cache_flush() ) $flushed[] = 'Object Cache';
    global $wpdb;
    $transients = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
    $flushed[] = "Transients ({$transients})";
    if ( function_exists( 'kinsta_cache_purge' ) ) { kinsta_cache_purge(); $flushed[] = 'Kinsta'; }
    if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); $flushed[] = 'W3 Total Cache'; }
    if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); $flushed[] = 'WP Rocket'; }
    if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache(); $flushed[] = 'SG Optimizer'; }
    if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); $flushed[] = 'WP Super Cache'; }
    if ( class_exists( 'LiteSpeed_Cache_API' ) ) { LiteSpeed_Cache_API::purge_all(); $flushed[] = 'LiteSpeed'; }
    return [ 'ok' => true, 'flushed' => $flushed ];
}

function reave_connect_search_replace( array $params ): WP_REST_Response {
    global $wpdb;
    $search  = (string) ( $params['search'] ?? '' );
    $replace = (string) ( $params['replace'] ?? '' );
    $dry_run = array_key_exists( 'dry_run', $params )
        ? filter_var( $params['dry_run'], FILTER_VALIDATE_BOOLEAN )
        : true;
    $tables  = is_array( $params['tables'] ?? null ) ? $params['tables'] : [];
    if ( $search === '' ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'search cannot be empty' ], 400 );
    }
    if ( empty( $tables ) ) {
        $tables = [ $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->comments, $wpdb->commentmeta, $wpdb->terms, $wpdb->termmeta ];
    }
    $report = [];
    foreach ( $tables as $table ) {
        $table = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table );
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
            DB_NAME,
            $table
        ) );
        if ( ! $exists ) {
            $report[ $table ] = 'skipped — table not found';
            continue;
        }
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        $count = 0;
        foreach ( $columns as $col ) {
            $col_name = $col['Field'];
            if ( ! preg_match( '/char|text|blob/', strtolower( $col['Type'] ) ) ) continue;
            $matches = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$col_name}` LIKE %s",
                '%' . $wpdb->esc_like( $search ) . '%'
            ) );
            if ( $matches < 1 ) continue;
            $count += $matches;
            if ( $dry_run ) continue;
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `{$col_name}` LIKE %s",
                '%' . $wpdb->esc_like( $search ) . '%'
            ), ARRAY_A );
            $pk_row = $wpdb->get_row( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
            $pk = $pk_row['Column_name'] ?? null;
            foreach ( $rows as $row ) {
                $old = $row[ $col_name ];
                $new = reave_connect_recursive_replace( $search, $replace, $old );
                if ( $new !== $old && $pk && isset( $row[ $pk ] ) ) {
                    $wpdb->update( $table, [ $col_name => $new ], [ $pk => $row[ $pk ] ] );
                }
            }
        }
        $report[ $table ] = [ 'rows_matched' => $count, 'updated' => $dry_run ? 'dry run' : $count ];
    }
    return new WP_REST_Response( [
        'ok'      => true,
        'dry_run' => $dry_run,
        'search'  => $search,
        'replace' => $dry_run ? '(not applied)' : $replace,
        'tables'  => $report,
    ], 200 );
}

function reave_connect_recursive_replace( $search, $replace, $data ) {
    if ( is_array( $data ) ) {
        foreach ( $data as $key => $value ) {
            $data[ $key ] = reave_connect_recursive_replace( $search, $replace, $value );
        }
        return $data;
    }
    if ( is_serialized( $data ) ) {
        $unserialized = @unserialize( $data );
        if ( $unserialized !== false ) {
            return serialize( reave_connect_recursive_replace( $search, $replace, $unserialized ) );
        }
    }
    return is_string( $data ) ? str_replace( $search, $replace, $data ) : $data;
}

function reave_connect_list_menus(): array {
    $menus = wp_get_nav_menus();
    $locations = get_nav_menu_locations();
    $loc_map = array_flip( $locations );
    $out = [];
    foreach ( $menus as $menu ) {
        $out[] = [
            'id'       => $menu->term_id,
            'name'     => $menu->name,
            'slug'     => $menu->slug,
            'count'    => $menu->count,
            'location' => $loc_map[ $menu->term_id ] ?? null,
        ];
    }
    return $out;
}

function reave_connect_get_menu_items( int $menu_id ): ?array {
    if ( ! $menu_id ) return null;
    $items = wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] );
    if ( $items === false ) return null;
    $out = [];
    foreach ( $items as $item ) {
        $out[] = [
            'id'        => (int) $item->ID,
            'title'     => $item->title,
            'url'       => $item->url,
            'target'    => $item->target,
            'parent'    => (int) $item->menu_item_parent,
            'order'     => (int) $item->menu_order,
            'object'    => $item->object,
            'object_id' => (int) $item->object_id,
            'type'      => $item->type,
        ];
    }
    return $out;
}

function reave_connect_update_menu_item( array $params ): WP_REST_Response {
    $menu_id = (int) ( $params['menu_id'] ?? $params['id'] ?? 0 );
    $item_id = (int) ( $params['item_id'] ?? 0 );
    $items = reave_connect_get_menu_items( $menu_id );
    if ( $items === null ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Menu not found' ], 404 );
    }
    $existing_items = wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] );
    $existing = null;
    foreach ( $existing_items ?: [] as $item ) {
        if ( (int) $item->ID === $item_id ) { $existing = $item; break; }
    }
    if ( ! $existing ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Menu item not found' ], 404 );
    }
    $args = [
        'menu-item-title'     => isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : $existing->title,
        'menu-item-url'       => isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : $existing->url,
        'menu-item-target'    => isset( $params['target'] ) ? sanitize_text_field( $params['target'] ) : $existing->target,
        'menu-item-status'    => 'publish',
        'menu-item-position'  => $existing->menu_order,
        'menu-item-parent-id' => $existing->menu_item_parent,
        'menu-item-object'    => $existing->object,
        'menu-item-object-id' => $existing->object_id,
        'menu-item-type'      => $existing->type,
    ];
    $result = wp_update_nav_menu_item( $menu_id, $item_id, $args );
    if ( is_wp_error( $result ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => $result->get_error_message() ], 400 );
    }
    return new WP_REST_Response( [ 'ok' => true, 'item_id' => $item_id ], 200 );
}

function reave_connect_has_redirection(): bool {
    return class_exists( 'Red_Item' );
}

function reave_connect_list_redirects(): array {
    global $wpdb;
    if ( reave_connect_has_redirection() ) {
        $table = $wpdb->prefix . 'redirection_items';
        $rows = $wpdb->get_results( "SELECT id, url AS `from`, action_data AS `to`, action_code AS code, status FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A );
        return $rows ?: [];
    }
    reave_connect_ensure_redirect_table();
    $table = $wpdb->prefix . 'reave_redirects';
    $rows = $wpdb->get_results( "SELECT id, from_url AS `from`, to_url AS `to`, code, created_at FROM {$table} ORDER BY id DESC", ARRAY_A );
    return $rows ?: [];
}

function reave_connect_create_redirect( array $params ): WP_REST_Response {
    global $wpdb;
    $from = (string) ( $params['from'] ?? $params['source'] ?? '' );
    $to = (string) ( $params['to'] ?? $params['target'] ?? '' );
    if ( $from === '' || $to === '' ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'from and to are required' ], 400 );
    }
    $from = '/' . ltrim( $from, '/' );
    $code = (int) ( $params['code'] ?? 301 );
    if ( ! in_array( $code, [ 301, 302, 307, 308 ], true ) ) $code = 301;

    if ( reave_connect_has_redirection() ) {
        $item = Red_Item::create( [
            'url'         => $from,
            'action_data' => [ 'url' => $to ],
            'action_code' => $code,
            'action_type' => 'url',
            'match_type'  => 'url',
            'group_id'    => 1,
        ] );
        if ( is_wp_error( $item ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => $item->get_error_message() ], 400 );
        }
        return new WP_REST_Response( [ 'ok' => true, 'id' => $item->get_id(), 'from' => $from, 'to' => $to, 'code' => $code ], 200 );
    }

    reave_connect_ensure_redirect_table();
    $table = $wpdb->prefix . 'reave_redirects';
    $wpdb->insert( $table, [
        'from_url'   => $from,
        'to_url'     => $to,
        'code'       => $code,
        'created_at' => current_time( 'mysql' ),
    ] );
    return new WP_REST_Response( [ 'ok' => true, 'id' => (int) $wpdb->insert_id, 'from' => $from, 'to' => $to, 'code' => $code ], 200 );
}

function reave_connect_delete_redirect( int $id ): WP_REST_Response {
    global $wpdb;
    if ( ! $id ) return new WP_REST_Response( [ 'ok' => false, 'error' => 'id is required' ], 400 );
    if ( reave_connect_has_redirection() ) {
        $item = Red_Item::get_by_id( $id );
        if ( ! $item ) return new WP_REST_Response( [ 'ok' => false, 'error' => 'Redirect not found' ], 404 );
        $item->delete();
        return new WP_REST_Response( [ 'ok' => true, 'deleted' => $id ], 200 );
    }
    $table = $wpdb->prefix . 'reave_redirects';
    $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
    if ( ! $deleted ) return new WP_REST_Response( [ 'ok' => false, 'error' => 'Redirect not found' ], 404 );
    return new WP_REST_Response( [ 'ok' => true, 'deleted' => $id ], 200 );
}

function reave_connect_ensure_redirect_table(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'reave_redirects';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        from_url    VARCHAR(2048)   NOT NULL,
        to_url      VARCHAR(2048)   NOT NULL,
        code        SMALLINT        NOT NULL DEFAULT 301,
        created_at  DATETIME        NOT NULL,
        PRIMARY KEY (id)
    ) {$charset};" );
}

function reave_connect_handle_redirect(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'reave_redirects';
    $exists = $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
        DB_NAME,
        $table
    ) );
    if ( ! $exists ) return;
    $path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT to_url, code FROM {$table} WHERE from_url = %s LIMIT 1", $path ) );
    if ( $row ) {
        wp_redirect( $row->to_url, (int) $row->code );
        exit;
    }
}
add_action( 'template_redirect', 'reave_connect_handle_redirect', 1 );
