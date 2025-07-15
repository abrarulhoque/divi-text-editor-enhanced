<?php
/**
 * safe divi shortcode bootstrap for admin-ajax context
 * 
 * note: divi is gpl licensed, so using its functions is fine
 * but always check your specific license terms
 */

class DTE_Ajax_Bootstrap {
    
    /**
     * ensure divi shortcodes are loaded in ajax context
     */
    public static function ensure_divi_loaded() {
        // bail if shortcodes already loaded
        if (shortcode_exists('et_pb_text')) {
            return true;
        }
        
        // method 1: use divi's official init function (preferred)
        if (function_exists('et_builder_init')) {
            et_builder_init();
            return shortcode_exists('et_pb_text');
        }
        
        // method 2: manually trigger builder setup
        if (function_exists('et_setup_builder')) {
            et_setup_builder();
            return shortcode_exists('et_pb_text');
        }
        
        // method 3: load builder class directly
        if (!class_exists('ET_Builder_Element')) {
            // try common paths
            $possible_paths = [
                get_template_directory() . '/includes/builder/class-et-builder-element.php',
                get_stylesheet_directory() . '/includes/builder/class-et-builder-element.php',
                WP_CONTENT_DIR . '/themes/Divi/includes/builder/class-et-builder-element.php'
            ];
            
            foreach ($possible_paths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    break;
                }
            }
        }
        
        // method 4: minimal bootstrap - just register the shortcodes we need
        if (!shortcode_exists('et_pb_text') && defined('ET_BUILDER_DIR')) {
            // load only text-related modules to minimize overhead
            $text_modules = [
                'Text.php', 'Blurb.php', 'Button.php', 'FullwidthHeader.php',
                'Testimonial.php', 'Accordion.php', 'Toggle.php', 'Slider.php',
                'CallToAction.php', 'CountdownTimer.php', 'PricingTables.php',
                'Tabs.php', 'NumberCounter.php', 'TeamMember.php', 'ContactForm.php',
                'Search.php', 'Signup.php'
            ];
            
            foreach ($text_modules as $module) {
                $file = ET_BUILDER_DIR . 'module/' . $module;
                if (file_exists($file)) {
                    include_once $file;
                }
            }
        }
        
        return shortcode_exists('et_pb_text');
    }
}

// hook into admin_init for early ajax bootstrap (optional)
add_action('admin_init', function() {
    if (wp_doing_ajax() && isset($_REQUEST['action'])) {
        $our_actions = ['dte_fetch_layout', 'dte_save_layout'];
        if (in_array($_REQUEST['action'], $our_actions)) {
            DTE_Ajax_Bootstrap::ensure_divi_loaded();
        }
    }
});