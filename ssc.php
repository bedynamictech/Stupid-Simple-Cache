<?php
/**
 * Plugin Name: Stupid Simple Cache
 * Description: Browser caching, HTML minification, static HTML file caching, lazy load images.
 * Version: 1.3
 * Author: Dynamic Technologies
 * Author URI: https://bedynamic.tech
 * Plugin URI: https://github.com/bedynamictech/Stupid-Simple-Cache
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Activation: create cache dir and lock it down
register_activation_hook( __FILE__, 'sscache_activate' );
function sscache_activate() {
    $dir = WP_CONTENT_DIR . '/cache/stupid-simple-cache';
    if ( wp_mkdir_p( $dir ) ) {
        file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
    }
}

// Add main menu and submenu
add_action( 'admin_menu', 'sscache_add_menu' );
function sscache_add_menu() {
    global $menu;
    $parent_exists = false;
    foreach ( $menu as $item ) {
        if ( ! empty( $item[2] ) && $item[2] === 'stupidsimple' ) {
            $parent_exists = true;
            break;
        }
    }

    if ( ! $parent_exists ) {
        add_menu_page(
            'Stupid Simple',
            'Stupid Simple',
            'manage_options',
            'stupidsimple',
            'sscache_settings_page',
            'dashicons-hammer',
            99
        );
    }

    add_submenu_page(
        'stupidsimple',
        'Cache',
        'Cache',
        'manage_options',
        'sscache',
        'sscache_settings_page'
    );
}

// Add Settings link
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sscache_action_links' );
function sscache_action_links( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=sscache' ) . '">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

// Register settings
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

// Render whitelist textarea
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

// Add Clear Cache button to admin bar
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

// Handle Clear Cache action
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

// HTML Minification: buffer output and compress
add_action( 'template_redirect', 'sscache_start_buffer', 10 );
function sscache_start_buffer() {
    if ( ! is_admin() && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
        ob_start( 'sscache_minify_html' );
    }
}

function sscache_minify_html( $html ) {
    return preg_replace( array( '/>\s+</', '/\s{2,}/' ), array( '><', ' ' ), $html );
}

// Static HTML caching
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
            $min = sscache_minify_html( $buffer );
            file_put_contents( $file, $min );
            return $min;
        } );
    }
}

// Lazy-load images
add_filter( 'the_content', 'sscache_lazy_load' );
function sscache_lazy_load( $content ) {
    return preg_replace( '/<img(.*?)src=/i', '<img$1loading="lazy" src=', $content );
}

// Browser caching headers
add_action( 'send_headers', 'sscache_browser_cache_headers' );
function sscache_browser_cache_headers() {
    if ( ! is_admin() ) {
        header( 'Cache-Control: public, max-age=' . DAY_IN_SECONDS );
    }
}

// Enable GZIP compression if possible
add_action( 'init', 'sscache_enable_gzip' );
function sscache_enable_gzip() {
    if ( ! is_admin() && ! ini_get( 'zlib.output_compression' ) && extension_loaded( 'zlib' ) ) {
        ob_start( 'ob_gzhandler' );
    }
}

// Disable emoji scripts and styles
add_action( 'init', 'sscache_disable_emojis' );
function sscache_disable_emojis() {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
}

// Remove wp-embed script
add_action( 'wp_footer', 'sscache_remove_wp_embed' );
function sscache_remove_wp_embed() {
    wp_deregister_script( 'wp-embed' );
}
