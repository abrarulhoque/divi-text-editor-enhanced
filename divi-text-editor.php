<?php
/**
 * Plugin Name: Divi Text Editor
 * Description: Front-end inline text editor for Divi layouts.
 * Version: 0.2.0
 * Author: Abrar
 * License: GPL2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Divi_Text_Editor {

    public function __construct() {
        $this->define_constants();
        $this->includes();

        // Register assets early so they can be enqueued by the shortcode.
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

        // Shortcode for the front-end editor interface.
        add_shortcode( 'divi_text_editor', array( $this, 'render_shortcode' ) );

        // AJAX endpoints (logged-in users only).
        add_action( 'wp_ajax_dte_fetch_layout', array( 'Divi_Text_Editor_Ajax_Handler', 'fetch_layout_content' ) );
        add_action( 'wp_ajax_dte_save_layout',  array( 'Divi_Text_Editor_Ajax_Handler', 'save_layout_content' ) );
    }

    private function define_constants() {
        if ( ! defined( 'DTE_PLUGIN_VERSION' ) ) {
            define( 'DTE_PLUGIN_VERSION', '0.1.0' );
        }
        if ( ! defined( 'DTE_PLUGIN_DIR' ) ) {
            define( 'DTE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
        }
        if ( ! defined( 'DTE_PLUGIN_URL' ) ) {
            define( 'DTE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
        }
        // Optional debug flag. Set `define( 'DTE_DEBUG', true );` in wp-config.php to enable.
        if ( ! defined( 'DTE_DEBUG' ) ) {
            define( 'DTE_DEBUG', false );
        }
    }

    private function includes() {
        // Core classes for the plugin.
        require_once DTE_PLUGIN_DIR . 'includes/class-shortcode.php';
        require_once DTE_PLUGIN_DIR . 'includes/class-ajax-handler.php';
        
        // Divi extraction helpers
        require_once DTE_PLUGIN_DIR . 'includes/ajax_bootstrap.php';
        require_once DTE_PLUGIN_DIR . 'includes/divi_text_extractor.php';
        require_once DTE_PLUGIN_DIR . 'includes/regex_fallback.php';
    }

    public function register_assets() {
        // Register (but do not enqueue) the front-end JS. The shortcode will enqueue it when rendered.
        wp_register_script(
            'dte-frontend-editor',
            DTE_PLUGIN_URL . 'assets/js/frontend-editor.js',
            array( 'jquery' ),
            DTE_PLUGIN_VERSION,
            true
        );
    }

    public function render_shortcode( $atts ) {
        return Divi_Text_Editor_Shortcode::render_editor_interface( $atts );
    }
}

// Initialise the plugin after all other plugins are loaded.
add_action( 'plugins_loaded', function () {
    new Divi_Text_Editor();
} ); 