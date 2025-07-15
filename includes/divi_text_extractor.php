<?php
/**
 * bulletproof divi text extraction for ajax context
 */

class Divi_Text_Extractor {
    
    // modules with text content
    private static $text_modules = [
        'et_pb_text' => ['uses_inner_content' => true, 'text_attributes' => []],
        'et_pb_blurb' => ['uses_inner_content' => false, 'text_attributes' => ['title', 'content']],
        'et_pb_button' => ['uses_inner_content' => false, 'text_attributes' => ['button_text']],
        'et_pb_fullwidth_header' => ['uses_inner_content' => false, 'text_attributes' => ['title', 'subhead', 'content', 'button_one_text', 'button_two_text']],
        'et_pb_testimonial' => ['uses_inner_content' => false, 'text_attributes' => ['author', 'job_title', 'company_name', 'content']],
        'et_pb_accordion_item' => ['uses_inner_content' => false, 'text_attributes' => ['title', 'content']],
        'et_pb_toggle' => ['uses_inner_content' => false, 'text_attributes' => ['title', 'content']],
        'et_pb_slide' => ['uses_inner_content' => false, 'text_attributes' => ['heading', 'button_text', 'content']],
        'et_pb_cta' => ['uses_inner_content' => false, 'text_attributes' => ['title', 'button_text', 'content']],
        'et_pb_countdown_timer' => ['uses_inner_content' => false, 'text_attributes' => ['title']],
        'et_pb_pricing_table' => ['uses_inner_content' => true, 'text_attributes' => ['title', 'subtitle', 'currency', 'per', 'sum', 'button_text']],
        'et_pb_tab' => ['uses_inner_content' => true, 'text_attributes' => ['title']],
        'et_pb_number_counter' => ['uses_inner_content' => false, 'text_attributes' => ['title', 'number']],
        'et_pb_team_member' => ['uses_inner_content' => true, 'text_attributes' => ['name', 'position']],
        'et_pb_contact_form' => ['uses_inner_content' => true, 'text_attributes' => ['title', 'success_message', 'submit_button_text']],
        'et_pb_search' => ['uses_inner_content' => false, 'text_attributes' => ['button_text', 'placeholder']],
        'et_pb_signup_custom_field' => ['uses_inner_content' => false, 'text_attributes' => ['field_title']],
    ];
    
    /**
     * main extraction method for ajax handler
     * returns format expected by frontend:
     *   [
     *     'texts'  => [
     *         [
     *             'original_block_index' => int,
     *             'shortcode_tag'        => string,
     *             'type'                 => 'attribute'|'inner_content',
     *             'key'                  => string,
     *             'value'                => string,
     *         ],
     *         ...
     *     ],
     *     'blocks' => [ 'full shortcode 1', 'full shortcode 2', ... ],
     *     'debug'  => [ ... ],
     *   ]
     */
    public static function extract($content) {
        // ensure all Divi (and 3rd-party) shortcodes are available for parsing
        DTE_Ajax_Bootstrap::ensure_divi_loaded();

        // use WordPress helper to capture *all* shortcodes in a single pass
        $regex   = '/' . get_shortcode_regex() . '/s';
        $matches = [];
        preg_match_all( $regex, $content, $matches, PREG_SET_ORDER );

        $texts  = [];
        $blocks = [];

        foreach ( $matches as $sc ) {
            $tag     = $sc[2];   // shortcode tag e.g. et_pb_text, dipi_typing_text
            $attsRaw = $sc[3];   // raw attribute string inside opening tag
            $inner   = $sc[5];   // inner content between tags (may be empty)
            $full    = $sc[0];   // full shortcode string – will be stored verbatim

            // skip Divi layout wrappers – we never want to expose those for editing
            if ( in_array( $tag, [ 'et_pb_section', 'et_pb_row', 'et_pb_column' ], true ) ) {
                continue;
            }

            // store the block and remember its index for later reference
            $blocks[]     = $full;
            $block_index  = count( $blocks ) - 1;

            // parse attributes into key/value pairs
            $atts = shortcode_parse_atts( $attsRaw );
            if ( is_array( $atts ) ) {
                foreach ( $atts as $key => $val ) {
                    if ( is_string( $val ) && trim( $val ) !== '' ) {
                        $texts[] = [
                            'original_block_index' => $block_index,
                            'shortcode_tag'        => $tag,
                            'type'                 => 'attribute',
                            'key'                  => $key,
                            'value'                => $val,
                        ];
                    }
                }
            }

            // capture inner content (if any)
            if ( trim( $inner ) !== '' ) {
                $texts[] = [
                    'original_block_index' => $block_index,
                    'shortcode_tag'        => $tag,
                    'type'                 => 'inner_content',
                    'key'                  => 'content',
                    'value'                => $inner,
                ];
            }
        }

        return [
            'texts'  => $texts,
            'blocks' => $blocks,
            'debug'  => [ 'match_count' => count( $texts ) ],
        ];
    }
    
    /**
     * fallback regex when get_shortcode_regex unavailable
     */
    private static function get_fallback_regex() {
        $tags = implode('|', array_map('preg_quote', array_keys(self::$text_modules)));
        
        // wordpress shortcode pattern
        return '\\['                             // opening bracket
             . '(\\[?)'                          // 1: optional second bracket for escaping
             . '(' . $tags . ')'                 // 2: shortcode name
             . '(?![\\w-])'                      // not followed by word character
             . '('                               // 3: attributes
             .     '[^\\]\\/]*'                  // not a closing bracket or forward slash
             .     '(?:'
             .         '\\/(?!\\])'              // self-closing
             .         '[^\\]\\/]*'               // not a closing bracket or forward slash
             .     ')*?'
             . ')'
             . '(?:'
             .     '(\\/)'                       // 4: self-closing
             .     '\\]'                         // closing bracket
             .     '\\]?'                        // optional second bracket for escaping
             . '|'
             .     '\\]'                         // closing bracket
             .     '(?:'
             .         '('                       // 5: content
             .             '[^\\[]*+'            // not an opening bracket
             .             '(?:'
             .                 '\\[(?!\\/\\2\\])' // opening bracket not followed by closing
             .                 '[^\\[]*+'         // not an opening bracket
             .             ')*+'
             .         ')'
             .         '\\[\\/\\2\\]'            // closing shortcode
             .     ')?'
             .     '\\]?'                        // optional second bracket for escaping
             . ')';
    }
}