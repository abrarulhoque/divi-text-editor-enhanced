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

        // Generic parser – captures *any* Divi module.
        $pattern = '/\[(et_pb_[a-z_]+)([^\]]*)\](?:([\s\S]*?)\[\/\1\])?/i';

        preg_match_all( $pattern, $post->post_content, $matches, PREG_SET_ORDER );

        $attr_visible_keys = array( 'button_text', 'title', 'heading', 'subtitle', 'content', 'title_text', 'button_one_text', 'button_two_text', 'label' );

        $texts = array();
        $meta  = array();
        $blocks = array();

        foreach ( $matches as $m ) {
            $module  = $m[1];
            $attrStr = isset( $m[2] ) ? $m[2] : '';
            $inner   = isset( $m[3] ) ? $m[3] : '';

            $visible = '';
            $source  = '';
            $attrKey = '';

            if ( trim( $inner ) !== '' ) {
                $visible = trim( $inner );
                $source  = 'content';
            } else {
                // Look into attributes.
                $atts = shortcode_parse_atts( $attrStr );
                foreach ( $attr_visible_keys as $key ) {
                    if ( isset( $atts[ $key ] ) && trim( $atts[ $key ] ) !== '' ) {
                        $visible = $atts[ $key ];
                        $source  = 'attr';
                        $attrKey = $key;
                        break;
                    }
                }

                // Fallback – first attribute ending with _text or title.
                if ( '' === $visible ) {
                    foreach ( $atts as $k => $v ) {
                        if ( preg_match( '/(_text|title|heading)$/', $k ) && trim( $v ) !== '' ) {
                            $visible = $v;
                            $source  = 'attr';
                            $attrKey = $k;
                            break;
                        }
                    }
                }
            }

            // Only push if we actually found something visible.
            if ( '' !== $visible ) {
                $texts[] = $visible;
                $meta[]  = array(
                    'module' => $module,
                    'source' => $source,
                    'attr'   => $attrKey,
                );
            }

            $blocks[] = $m[0];
        }

        $debug_data = array(
            'match_count'   => count( $texts ),
            'layout_id'     => $layout_id,
            'regex_pattern' => 'generic',
            'blocks'        => array_map( function( $b ) {
                return ( strlen( $b ) > 200 ) ? substr( $b, 0, 200 ) . '…' : $b;
            }, $blocks ),
        );

        if ( DTE_DEBUG ) {
            error_log( '[DTE] Fetch layout ' . $layout_id . ' – found ' . count( $texts ) . ' matches.' );
        }

        wp_send_json_success( array(
            'texts' => $texts,
            'meta'  => $meta,
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
        $meta      = isset( $_POST['meta'] ) ? json_decode( wp_unslash( $_POST['meta'] ), true ) : array();

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

        $pattern = '/\[(et_pb_[a-z_]+)([^\]]*)\](?:([\s\S]*?)\[\/\1\])?/i';

        $idx = 0;
        $updated_content = preg_replace_callback( $pattern, function( $m ) use ( &$idx, $texts, $meta ) {
            // If no corresponding meta/text, return original.
            if ( ! isset( $texts[ $idx ] ) || ! isset( $meta[ $idx ] ) ) {
                $idx++;
                return $m[0];
            }

            $new_visible = $texts[ $idx ];
            $meta_item   = $meta[ $idx ];
            $idx++;

            $module  = $m[1];
            $attrStr = isset( $m[2] ) ? $m[2] : '';
            $inner   = isset( $m[3] ) ? $m[3] : '';

            if ( 'content' === $meta_item['source'] ) {
                // Replace inner content.
                return '[' . $module . $attrStr . ']' . $new_visible . '[/' . $module . ']';
            }

            // Attribute source – rebuild attribute list.
            $attr_key = $meta_item['attr'];
            $atts     = shortcode_parse_atts( $attrStr );
            $atts[ $attr_key ] = $new_visible;

            // Reconstruct attributes string.
            $new_attr_str = '';
            foreach ( $atts as $k => $v ) {
                $new_attr_str .= ' ' . $k . '="' . esc_attr( $v ) . '"';
            }

            return '[' . $module . $new_attr_str . ']' . $inner . '[/' . $module . ']';
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