<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Divi_Text_Editor_Ajax_Handler {

    /**
     * Fetches text content from a selected Divi layout.
     */
    public static function fetch_layout_content() {
        self::verify_request();

        $layout_id = isset( $_POST['layout_id'] ) ? absint( $_POST['layout_id'] ) : 0;
        if ( ! $layout_id ) {
            wp_send_json_error( 'Invalid layout ID', 400 );
        }

        $post = get_post( $layout_id );
        if ( ! $post ) {
            wp_send_json_error( 'Page not found', 404 );
        }

        // Additional capability check for this specific post.
        if ( ! current_user_can( 'edit_post', $layout_id ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        // fire up divi shortcodes so parser can use them
        DTE_Ajax_Bootstrap::ensure_divi_loaded();

        // let the new extractor do its thing
        $result = Divi_Text_Extractor::extract( $post->post_content );

        if ( DTE_DEBUG ) {
            error_log( '[DTE] Fetch layout ' . $layout_id . ' – found ' . count( $result['texts'] ) . ' matches.' );
        }

        wp_send_json_success( $result );
    }

    /**
     * Saves modified text content back to the selected Divi layout.
     */
    public static function save_layout_content() {
        self::verify_request();

        $layout_id = isset( $_POST['layout_id'] ) ? absint( $_POST['layout_id'] ) : 0;

        // Handle the new object-based payload: each item should contain a 'value'.
        $text_items = isset( $_POST['texts'] ) && is_array( $_POST['texts'] ) ? $_POST['texts'] : array();

        $texts = array_map( function ( $item ) {
            $raw = is_array( $item ) && isset( $item['value'] ) ? $item['value'] : '';
            return wp_kses_post( $raw );
        }, $text_items );

        if ( ! $layout_id ) {
            wp_send_json_error( 'Invalid layout ID', 400 );
        }

        $post = get_post( $layout_id );
        if ( ! $post ) {
            wp_send_json_error( 'Page not found', 404 );
        }

        if ( ! current_user_can( 'edit_post', $layout_id ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $pattern = '#(\[et_pb_(?!section|row|column)[\w-]+[^\]]*\])(.*?)(\[/et_pb_[^\]]*\])#si';
        $i       = 0;
        $updated_content = preg_replace_callback( $pattern, function ( $m ) use ( &$i, $texts ) {
            $replacement = isset( $texts[ $i ] ) ? $texts[ $i ] : $m[2];
            $i++;
            return $m[1] . $replacement . $m[3];
        }, $post->post_content );

        if ( null === $updated_content ) {
            wp_send_json_error( 'Regex error', 500 );
        }

        $result = wp_update_post( array(
            'ID'           => $layout_id,
            'post_content' => $updated_content,
        ), true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message(), 500 );
        }

        // Optional: clear Divi static resources cache.
        if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
            ET_Core_PageResource::remove_static_resources( 'all', 'all' );
        }

        $debug_data = array(
            'updated_post_id' => $layout_id,
            'text_count'      => count( $texts ),
        );

        if ( DTE_DEBUG ) {
            error_log( '[DTE] Saved layout ' . $layout_id . ' – replaced ' . count( $texts ) . ' blocks.' );
        }

        wp_send_json_success( array( 'debug' => $debug_data ) );
    }

    /**
     * Common verification for AJAX requests.
     */
    private static function verify_request() {
        // Nonce check (expects 'nonce' field in POST).
        check_ajax_referer( 'dte_editor_nonce', 'nonce' );

        // Capability check.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
    }
} 