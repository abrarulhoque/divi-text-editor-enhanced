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

        // 1) parse the actual page
        $result = Divi_Text_Extractor::extract( $post->post_content );
        $texts  = $result['texts'];
        $blocks = $result['blocks'];

        // 2) grab theme-builder templates attached to this page
        $tb_ids = [];

        // global header / body / footer
        if ( function_exists( 'et_theme_builder_get_global_header_id' ) ) {
            $tb_ids[] = et_theme_builder_get_global_header_id();
            $tb_ids[] = et_theme_builder_get_global_body_id();
            $tb_ids[] = et_theme_builder_get_global_footer_id();
        }

        // page-specific template (if you assigned one in the theme builder)
        if ( function_exists( 'et_theme_builder_get_all_used_templates' ) ) {
            $extra  = et_theme_builder_get_all_used_templates( $layout_id ); // returns IDs or empty
            $tb_ids = array_merge( $tb_ids, (array) $extra );
        }

        $tb_ids = array_unique( array_filter( $tb_ids ) );

        foreach ( $tb_ids as $tb_id ) {
            $tb_post = get_post( $tb_id );
            if ( ! $tb_post ) {
                continue;
            }
            $tb_result = Divi_Text_Extractor::extract( $tb_post->post_content );

            // tag each text so we know where to save it later
            foreach ( $tb_result['texts'] as &$t ) {
                $t['source']    = 'theme_builder';
                $t['layout_id'] = $tb_id;
            }

            // stash the blocks separately keyed by template id
            $blocks[ 'tb_' . $tb_id ] = $tb_result['blocks'];

            $texts = array_merge( $texts, $tb_result['texts'] );
        }

        wp_send_json_success( [
            'texts'  => $texts,
            'blocks' => $blocks,
            'debug'  => [
                'match_count' => count( $texts ),
                'tb_ids'      => $tb_ids,
            ],
        ] );
    }

    /**
     * Saves modified text content back to the selected Divi layout.
     */
    public static function save_layout_content() {
        self::verify_request();

        $layout_id = isset( $_POST['layout_id'] ) ? absint( $_POST['layout_id'] ) : 0;
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

        $incoming = isset( $_POST['texts'] ) && is_array( $_POST['texts'] ) ? $_POST['texts'] : [];

        // build per-post edit queues
        $edits = [];

        foreach ( $incoming as $item ) {
            // default to the page itself
            $target_id = $layout_id;

            if ( isset( $item['source'] ) && 'theme_builder' === $item['source'] && ! empty( $item['layout_id'] ) ) {
                $target_id = absint( $item['layout_id'] );
            }

            // sanitize the value but keep the entire object so we know what to update
            $sanitized          = $item;
            $sanitized['value'] = wp_kses_post( $item['value'] ?? '' );

            $edits[ $target_id ][] = $sanitized;
        }

        // run replacement for each affected post
        foreach ( $edits as $post_id => $texts ) {

            $target = get_post( $post_id );
            if ( ! $target || ! current_user_can( 'edit_post', $post_id ) ) {
                continue;
            }

            // generic shortcode matcher (skip section/row/column wrappers)
            $pattern = '#(\[(?!/?et_pb_(?:section|row|column)\b)[\w-]+[^\]]*\])(.*?)(\[/[\w-]+[^\]]*\])#si';
            $i       = 0;

            $updated_content = preg_replace_callback( $pattern, function ( $m ) use ( &$i, $texts ) {

                $block_str = $m[0];       // full shortcode
                $open_tag  = $m[1];
                $middle    = $m[2];       // inner content between opening/closing
                $close_tag = $m[3];

                if ( ! isset( $texts[ $i ] ) ) {
                    $i++;
                    return $block_str;
                }

                $item = $texts[ $i ];
                $i++;

                // handle attribute replacement
                if ( isset( $item['type'] ) && 'attribute' === $item['type'] ) {
                    $key = $item['key'] ?? '';
                    $val = $item['value'] ?? '';
                    if ( $key === '' ) {
                        return $block_str; // nothing to do
                    }

                    // if attribute exists, replace it; otherwise inject before closing bracket of opening tag
                    if ( preg_match( '/\s' . preg_quote( $key, '/' ) . '\="[^"]*"/i', $open_tag ) ) {
                        $open_tag = preg_replace( '/\s' . preg_quote( $key, '/' ) . '\="[^"]*"/i', ' ' . $key . '="' . $val . '"', $open_tag );
                    } else {
                        // inject new attribute right before closing bracket
                        $open_tag = preg_replace( '/^\[([^\]]+)/', '[$1 ' . $key . '="' . $val . '"', $open_tag );
                    }

                    return $open_tag . $middle . $close_tag;
                }

                // default: inner content replacement
                return $open_tag . ( $item['value'] ?? $middle ) . $close_tag;

            }, $target->post_content );

            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $updated_content,
            ] );
        }

        // Optional: clear Divi static resources cache.
        if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
            ET_Core_PageResource::remove_static_resources( 'all', 'all' );
        }

        wp_send_json_success();
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