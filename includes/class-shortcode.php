<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Divi_Text_Editor_Shortcode {

    /**
     * Render the shortcode interface.
     *
     * @param array $atts Shortcode attributes.
     *
     * @return string HTML output or empty string for unauthorized users.
     */
    public static function render_editor_interface( $atts = array() ) {
        // Capability check – only administrators (or users with manage_options) may see the editor.
        if ( ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        // Register + enqueue script (registered earlier in main plugin file).
        wp_enqueue_script( 'dte-frontend-editor' );

        // Nonce for AJAX security.
        $nonce = wp_create_nonce( 'dte_editor_nonce' );

        // Pass data to JS.
        wp_localize_script( 'dte-frontend-editor', 'DTE', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => $nonce,
        ) );

        // Fetch all regular pages/posts that use the Divi Builder on the front-end.
        $layouts = get_posts( array(
            'post_type'      => 'page',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_key'       => '_et_pb_use_builder',
            'meta_value'     => 'on',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        ob_start();
        ?>
        <div class="dte-editor-wrap">
            <label for="dte-layout-selector" style="display:block;margin-bottom:6px;">Select Page:</label>
            <select id="dte-layout-selector" style="min-width:250px;">
                <option value="">— Select a Page —</option>
                <?php foreach ( $layouts as $layout ) : ?>
                    <option value="<?php echo esc_attr( $layout->ID ); ?>">
                        <?php echo esc_html( $layout->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div id="dte-text-editors" style="margin-top:20px;"></div>

            <button id="dte-save-button" style="margin-top:16px; display:none;" disabled>Save Changes</button>

            <div id="dte-message" style="margin-top:10px;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
} 