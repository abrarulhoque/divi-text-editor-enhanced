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

        // Capture inner content for any Divi module (excluding structural ones like section/row/column). This works even when the builder isn't loaded.
        $pattern = '#(\[et_pb_(?!section|row|column)[\w-]+[^\]]*\])(.*?)(\[/et_pb_[^\]]*\])#si';
        preg_match_all( $pattern, $post->post_content, $matches );

        $texts  = ! empty( $matches[2] ) ? $matches[2] : array();
        $blocks = ! empty( $matches[0] ) ? $matches[0] : array();

        // Build debug information – always included but large strings are trimmed.
        $debug_data = array(
            'match_count'   => count( $texts ),
            'layout_id'     => $layout_id,
            'regex_pattern' => $pattern,
            'blocks'        => array_map( function( $b ) {
                return ( strlen( $b ) > 200 ) ? substr( $b, 0, 200 ) . '…' : $b;
            }, $blocks ),
        );

        if ( DTE_DEBUG ) {
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