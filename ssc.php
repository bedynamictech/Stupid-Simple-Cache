<?php
/*
Plugin Name: Stupid Simple Cache
Description: Browser caching, HTML minification, static HTML file caching, lazy load images.
Version: 1.1
Author: Dynamic Technologies
Author URI: https://bedynamic.tech
Plugin URI: https://github.com/bedynamictech/Stupid-Simple-Cache
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Activation: create cache dir and lock it down
register_activation_hook( __FILE__, 'sscache_activate' );
function sscache_activate() {
    $dir = WP_CONTENT_DIR . '/cache/stupid-simple-cache';
    if ( wp_mkdir_p( $dir ) ) {
        file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
}

// Admin Menu: Stupid Simple > Cache
add_action( 'admin_menu', 'sscache_add_menu' );
function sscache_add_menu() {
    add_menu_page(
        'Stupid Simple',
        'Stupid Simple',
        'manage_options',
        'stupidsimple',
        'sscache_settings_page',
        'dashicons-hammer',
        99
    );
    add_submenu_page(
        'stupidsimple',
        'Cache',
        'Cache',
        'manage_options',
        'sscache',
        'sscache_settings_page'
    );
}

// Settings Init: whitelist only
add_action( 'admin_init', 'sscache_settings_init' );
function sscache_settings_init() {
    register_setting( 'sscache_group', 'sscache_whitelist', 'sanitize_textarea_field' );
    add_settings_section( 'sscache_section', '', '__return_null', 'sscache' );
    add_settings_field(
        'sscache_whitelist',
        'Whitelist URLs',
        'sscache_render_textarea',
        'sscache',
        'sscache_section'
    );
}

// Whitelist textarea renderer
function sscache_render_textarea() {
    $whitelist = get_option( 'sscache_whitelist', '' );
    echo '<textarea id="sscache_whitelist" name="sscache_whitelist" rows="5" cols="50">' . esc_textarea( $whitelist ) . '</textarea>';
    echo '<p class="description">Enter one URL path per line to exclude from caching.</p>';
}

// Settings page output
function sscache_settings_page() {
    echo '<div class="wrap"><h1>Cache</h1><form action="options.php" method="post">';
    settings_fields( 'sscache_group' );
    do_settings_sections( 'sscache' );
    submit_button();
    echo '</form></div>';
}

// Clear Cache button in admin bar
add_action( 'admin_bar_menu', 'sscache_add_clear_button', 100 );
function sscache_add_clear_button( $wp_admin_bar ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $wp_admin_bar->add_node( array(
        'id'    => 'sscache_clear',
        'title' => 'Clear Cache',
        'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=sscache_clear_cache' ), 'sscache_clear' ),
    ) );
}

// Handle Clear Cache
add_action( 'admin_post_sscache_clear_cache', 'sscache_clear_cache_action' );
function sscache_clear_cache_action() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sscache_clear' ) ) {
        wp_die( 'Unauthorized', '', array( 'response' => 403 ) );
    }
    $dir = WP_CONTENT_DIR . '/cache/stupid-simple-cache';
    if ( is_dir( $dir ) ) {
        $files = glob( $dir . '/*.html' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                @unlink( $file );
            }
        }
    }
    wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=sscache' ) );
    exit;
}

// HTML Minification: buffer and compress
add_action( 'template_redirect', 'sscache_start_buffer', 10 );
function sscache_start_buffer() {
    if ( ! is_admin() && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
        ob_start( 'sscache_minify_html' );
    }
}
function sscache_minify_html( $html ) {
    return preg_replace( array( '/>\s+</', '/\s{2,}/' ), array( '><', ' ' ), $html );
}

// Static HTML Caching: always on and stores minified output
add_action( 'template_redirect', 'sscache_static_cache', 1 );
function sscache_static_cache() {
    if ( is_admin() || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
        return;
    }
    $uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
    $paths = preg_split( '/[\r\n]+/', get_option( 'sscache_whitelist', '' ) );
    foreach ( $paths as $path ) {
        if ( '' !== trim( $path ) && false !== strpos( $uri, trim( $path ) ) ) {
            return;
        }
    }
    $dir  = WP_CONTENT_DIR . '/cache/stupid-simple-cache';
    $file = $dir . '/' . md5( $uri ) . '.html';
    if ( file_exists( $file ) && time() - filemtime( $file ) < HOUR_IN_SECONDS ) {
        // Send ETag and handle 304
        $etag = '"' . md5_file( $file ) . '"';
        header( 'Etag: ' . $etag );
        if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && stripslashes( $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag ) {
            status_header( 304 );
            exit;
        }
        readfile( $file );
        exit;
    }
    if ( wp_mkdir_p( $dir ) ) {
        ob_start( function( $buffer ) use ( $file ) {
            $min  = sscache_minify_html( $buffer );
            file_put_contents( $file, $min );
            return $min;
        } );
    }
}

// Lazy-load images: always on
add_filter( 'the_content', 'sscache_lazy_load' );
function sscache_lazy_load( $content ) {
    return preg_replace( '/<img(.*?)src=/i', '<img$1loading="lazy" src=', $content );
}

// Browser caching via headers: always on
add_action( 'send_headers', 'sscache_browser_cache_headers' );
function sscache_browser_cache_headers() {
    if ( ! is_admin() ) {
        header( 'Cache-Control: public, max-age=' . DAY_IN_SECONDS );
    }
}
