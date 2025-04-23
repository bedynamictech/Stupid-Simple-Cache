<?php
/**
Plugin Name: Stupid Simple Cache
Description: Simple caching plugin: HTML minification, static HTML file caching, lazy load images, browser caching.
Version: 1.0.3
Author: Dynamic Technologies
Author URI: https://bedynamic.tech
Plugin URI: https://github.com/bedynamictech/StupidSimpleCache
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Stupid_Simple_Cache {
    private static $instance;
    private $options;

    private function __construct() {
        $this->options = get_option( 'sscache_settings', array() );

        // Admin menu & settings
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'settings_init' ) );
        add_action( 'admin_menu', array( $this, 'reorder_submenus' ), 999 );

        // Admin bar Clear Cache button
        add_action( 'admin_bar_menu', array( $this, 'add_clear_cache_button' ), 100 );
        add_action( 'admin_post_sscache_clear_cache', array( $this, 'clear_cache_action' ) );

        // Front-end modules
        if ( ! empty( $this->options['static_cache'] ) ) {
            add_action( 'template_redirect', array( $this, 'serve_or_create_static_cache' ), 1 );
        }
        if ( ! empty( $this->options['minify'] ) ) {
            add_action( 'template_redirect', array( $this, 'start_html_buffer' ), 10 );
        }
        if ( ! empty( $this->options['lazy_load'] ) ) {
            add_filter( 'the_content', array( $this, 'lazy_load_images' ) );
        }
        if ( ! empty( $this->options['browser_cache'] ) ) {
            add_action( 'send_headers', array( $this, 'set_browser_cache_headers' ) );
        }
    }

    public static function instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function add_admin_menu() {
        add_submenu_page(
            'stupidsimple',
            'Cache Settings',
            'Cache',
            'manage_options',
            'stupid-simple-cache',
            array( $this, 'settings_page' )
        );
    }

    public function reorder_submenus() {
        global $submenu;
        if ( isset( $submenu['stupidsimple'] ) && is_array( $submenu['stupidsimple'] ) ) {
            $first_item = array_shift( $submenu['stupidsimple'] );
            uasort( $submenu['stupidsimple'], function( $a, $b ) {
                return strcasecmp( $a[0], $b[0] );
            } );
            array_unshift( $submenu['stupidsimple'], $first_item );
        }
    }
    public function settings_init() {
        register_setting( 'sscache', 'sscache_settings', array( $this, 'sanitize' ) );
        add_settings_section( 'sscache_section', '', '__return_null', 'sscache' );

        $modules = array(
            'minify'       => 'HTML Minification',
            'static_cache' => 'Static HTML Caching',
            'lazy_load'    => 'Lazy Load Images',
            'browser_cache'=> 'Browser Caching',
        );
        foreach ( $modules as $key => $label ) {
            add_settings_field( "sscache_{$key}", $label, array( $this, 'render_checkbox' ), 'sscache', 'sscache_section', array( 'key' => $key ) );
        }
        add_settings_field( 'sscache_whitelist', 'Whitelist URLs', array( $this, 'render_textarea' ), 'sscache', 'sscache_section' );
    }

    public function sanitize( $input ) {
        $clean = array();
        foreach ( array( 'minify', 'static_cache', 'lazy_load', 'browser_cache' ) as $key ) {
            $clean[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
        }
        $lines = preg_split( '/[\r\n]+/', sanitize_textarea_field( $input['whitelist'] ) );
        $clean['whitelist'] = implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
        return $clean;
    }

    public function render_checkbox( $args ) {
        $key = $args['key'];
        printf(
            '<input type="checkbox" id="sscache_%1$s" name="sscache_settings[%1$s]" value="1" %2$s />',
            esc_attr( $key ),
            checked( 1, ! empty( $this->options[ $key ] ), false )
        );
    }

    public function render_textarea() {
        printf(
            '<textarea id="sscache_whitelist" name="sscache_settings[whitelist]" rows="5" cols="50">%s</textarea><p class="description">One URL per line.</p>',
            esc_textarea( $this->options['whitelist'] ?? '' )
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Cache Settings</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'sscache' );
                do_settings_sections( 'sscache' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function start_html_buffer() {
        ob_start( array( $this, 'minify_html' ) );
    }

    public function minify_html( $html ) {
        return preg_replace( array( '/>\s+</', '/\s{2,}/' ), array( '><', ' ' ), $html );
    }

    public function serve_or_create_static_cache() {
        if ( ! $this->should_cache() ) {
            return;
        }
        $cache_dir  = WP_CONTENT_DIR . '/cache/stupid-simple-cache/';
        $cache_file = $cache_dir . md5( $_SERVER['REQUEST_URI'] ) . '.html';

        if ( file_exists( $cache_file ) && ( time() - filemtime( $cache_file ) < HOUR_IN_SECONDS ) ) {
            echo file_get_contents( $cache_file );
            exit;
        }

        if ( ! file_exists( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
        }

        ob_start(function( $buffer ) use ( $cache_file ) {
            file_put_contents( $cache_file, $buffer );
            return $buffer;
        });
    }

    private function should_cache() {
        if ( is_admin() ) {
            return false;
        }
        global $pagenow;
        if ( isset( $pagenow ) && $pagenow === 'wp-login.php' ) {
            return false;
        }
        foreach ( explode( "\n", $this->options['whitelist'] ) as $url ) {
            if ( '' !== trim( $url ) && false !== strpos( $_SERVER['REQUEST_URI'], trim( $url ) ) ) {
                return false;
            }
        }
        return true;
    }

    public function lazy_load_images( $content ) {
        return preg_replace( '/<img(.*?)src=/i', '<img$1loading="lazy" src=', $content );
    }

    public function set_browser_cache_headers() {
        if ( is_admin() ) {
            return;
        }
        header( 'Cache-Control: public, max-age=' . DAY_IN_SECONDS );
    }

    public function add_clear_cache_button( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $wp_admin_bar->add_node( array(
            'id'    => 'sscache_clear',
            'title' => 'Clear Cache',
            'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=sscache_clear_cache' ), 'sscache_clear' ),
        ) );
    }

    /** Handle cache clearing */
    public function clear_cache_action() {
        // Verify permissions and nonce
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sscache_clear' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'sscache' ), esc_html__( 'Error', 'sscache' ), array( 'response' => 403 ) );
        }

        $cache_dir = WP_CONTENT_DIR . '/cache/stupid-simple-cache/';
        if ( is_dir( $cache_dir ) ) {
            $files = glob( $cache_dir . '*.html' );
            if ( is_array( $files ) ) {
                foreach ( $files as $file ) {
                    @unlink( $file );
                }
            }
        }

        // Redirect back
        wp_safe_redirect( wp_get_referer() ?: home_url() );
        exit;
    }
}

add_action( 'init', array( 'Stupid_Simple_Cache', 'instance' ) );
