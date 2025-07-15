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
        if ( ! $post || 'et_pb_layout' !== $post->post_type ) {
            wp_send_json_error( 'Layout not found', 404 );
        }

        $pattern = '#(\[et_pb_text[^\]]*\])(.*?)(\[/et_pb_text\])#s';
        preg_match_all( $pattern, $post->post_content, $matches );

        $texts = array();
        if ( ! empty( $matches[2] ) ) {
            $texts = $matches[2]; // Group 2 contains the inner content.
        }

        $debug_data = array();
        if ( DTE_DEBUG ) {
            $debug_data = array(
                'match_count'   => count( $texts ),
                'layout_id'     => $layout_id,
                'regex_pattern' => $pattern,
            );
            // Log to debug.log as well.
            error_log( '[DTE] Fetch layout ' . $layout_id . ' – found ' . count( $texts ) . ' matches.' );
        }

        wp_send_json_success( array(
            'texts' => $texts,
            'debug' => $debug_data,
        ) );
    }

    /**
     * Saves modified text content back to the selected Divi layout.
     */
    public static function save_layout_content() {
        self::verify_request();

        $layout_id = isset( $_POST['layout_id'] ) ? absint( $_POST['layout_id'] ) : 0;
        $texts     = isset( $_POST['texts'] ) && is_array( $_POST['texts'] ) ? array_map( 'wp_kses_post', $_POST['texts'] ) : array();

        if ( ! $layout_id ) {
            wp_send_json_error( 'Invalid layout ID', 400 );
        }

        $post = get_post( $layout_id );
        if ( ! $post || 'et_pb_layout' !== $post->post_type ) {
            wp_send_json_error( 'Layout not found', 404 );
        }

        $pattern = '#(\[et_pb_text[^\]]*\])(.*?)(\[/et_pb_text\])#s';
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

        $debug_data = array();
        if ( DTE_DEBUG ) {
            $debug_data = array(
                'updated_post_id' => $layout_id,
                'text_count'      => count( $texts ),
            );
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