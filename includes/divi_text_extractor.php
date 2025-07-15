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
        // ensure divi shortcodes loaded
        DTE_Ajax_Bootstrap::ensure_divi_loaded();
        
        // use wordpress's get_shortcode_regex if available
        if (function_exists('get_shortcode_regex')) {
            $pattern = get_shortcode_regex(array_keys(self::$text_modules));
        } else {
            // fallback to simple regex
            $pattern = self::get_fallback_regex();
        }

        $editable_texts  = [];
        $original_blocks = [];
        $debug_blocks    = [];

        if (preg_match_all("/$pattern/s", $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $full_block = $match[0];
                $tag        = $match[2];
                
                // skip non-text modules
                if (!isset(self::$text_modules[$tag])) {
                    continue;
                }
                
                $module_info = self::$text_modules[$tag];

                // ensure block is registered and determine its index
                $block_index = array_search($full_block, $original_blocks, true);
                if ($block_index === false) {
                    $original_blocks[] = $full_block;
                    $block_index       = count($original_blocks) - 1;
                }

                // extract attributes
                $attributes = shortcode_parse_atts($match[3]);

                // collect text from attributes
                foreach ($module_info['text_attributes'] as $attr) {
                    if (!empty($attributes[$attr])) {
                        $editable_texts[] = [
                            'original_block_index' => $block_index,
                            'shortcode_tag'        => $tag,
                            'type'                 => 'attribute',
                            'key'                  => $attr,
                            'value'                => $attributes[$attr],
                        ];
                    }
                }

                // capture inner content if relevant
                $inner_content = isset($match[5]) ? $match[5] : '';
                if ($module_info['uses_inner_content'] && $inner_content !== '') {
                    // check for nested shortcodes
                    if (strpos($inner_content, '[et_pb_') !== false) {
                        // recursively extract nested content
                        $nested = self::extract($inner_content);

                        // remap nested blocks into our master lists
                        foreach ($nested['blocks'] as $nested_idx => $nested_block) {
                            $existing_index = array_search($nested_block, $original_blocks, true);
                            if ($existing_index === false) {
                                $original_blocks[] = $nested_block;
                                $mapped_index      = count($original_blocks) - 1;
                            } else {
                                $mapped_index = $existing_index;
                            }

                            // find all texts in nested that reference this block index
                            foreach ($nested['texts'] as $nested_text) {
                                if ($nested_text['original_block_index'] === $nested_idx) {
                                    $nested_text['original_block_index'] = $mapped_index;
                                    $editable_texts[] = $nested_text;
                                }
                            }
                        }
                    } else {
                        $editable_texts[] = [
                            'original_block_index' => $block_index,
                            'shortcode_tag'        => $tag,
                            'type'                 => 'inner_content',
                            'key'                  => 'content',
                            'value'                => $inner_content,
                        ];
                    }
                }

                // handle self-closing with content attribute fallback
                if ($module_info['uses_inner_content'] && $inner_content === '' && !empty($attributes['content'])) {
                    $editable_texts[] = [
                        'original_block_index' => $block_index,
                        'shortcode_tag'        => $tag,
                        'type'                 => 'attribute',
                        'key'                  => 'content',
                        'value'                => $attributes['content'],
                    ];
                }

                // collect debug information
                if (defined('DTE_DEBUG') && DTE_DEBUG) {
                    $debug_blocks[] = [
                        'block' => $full_block,
                        'tag'   => $tag,
                    ];
                }
            }
        }

        return [
            'texts'  => $editable_texts,
            'blocks' => $original_blocks,
            'debug'  => [
                'match_count'      => count($editable_texts),
                'shortcode_exists' => shortcode_exists('et_pb_text'),
                'blocks'           => (defined('DTE_DEBUG') && DTE_DEBUG) ? $debug_blocks : [],
            ],
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