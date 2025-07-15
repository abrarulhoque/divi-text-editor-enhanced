<?php
/**
 * lightweight regex-only divi text extraction (no builder dependencies)
 */

function extract_divi_text_regex_only($content) {
    $results = [];
    $block_index = 0;
    
    // pattern to match any divi module with closing tag (skip layout modules)
    $pattern = '#\[et_pb_(?!section|row|column|fullwidth_section)([\w-]+)([^\]]*)\](.*?)\[/et_pb_\1\]#s';
    
    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tag = 'et_pb_' . $match[1];
            $attrs_string = $match[2];
            $inner_content = $match[3];
            
            // parse attributes manually
            $attributes = [];
            if (preg_match_all('/(\w+)=["\']([^"\']*)["\']/', $attrs_string, $attr_matches, PREG_SET_ORDER)) {
                foreach ($attr_matches as $attr) {
                    $attributes[$attr[1]] = $attr[2];
                }
            }
            
            // common text attributes to check
            $text_attrs = ['title', 'heading', 'content', 'button_text', 'button_one_text', 
                          'button_two_text', 'subhead', 'name', 'position', 'author', 
                          'job_title', 'company_name', 'placeholder', 'field_title',
                          'subtitle', 'sum', 'number', 'success_message', 'submit_button_text'];
            
            // extract text from attributes
            foreach ($text_attrs as $attr) {
                if (!empty($attributes[$attr])) {
                    $results[] = [
                        'original_block_index' => $block_index,
                        'shortcode_tag' => $tag,
                        'type' => 'attribute',
                        'key' => $attr,
                        'value' => $attributes[$attr]
                    ];
                }
            }
            
            // check inner content
            if (!empty(trim($inner_content))) {
                // recursively extract nested modules
                $nested = extract_divi_text_regex_only($inner_content);
                
                if (empty($nested)) {
                    // no nested modules, use as content
                    $results[] = [
                        'original_block_index' => $block_index,
                        'shortcode_tag' => $tag,
                        'type' => 'inner_content',
                        'key' => 'content',
                        'value' => $inner_content
                    ];
                } else {
                    $results = array_merge($results, $nested);
                }
            }
            
            $block_index++;
        }
    }
    
    // also check self-closing shortcodes
    $self_closing_pattern = '#\[et_pb_(?!section|row|column|fullwidth_section)([\w-]+)([^\]]*)/\]#s';
    
    if (preg_match_all($self_closing_pattern, $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tag = 'et_pb_' . $match[1];
            $attrs_string = $match[2];
            
            // parse attributes
            $attributes = [];
            if (preg_match_all('/(\w+)=["\']([^"\']*)["\']/', $attrs_string, $attr_matches, PREG_SET_ORDER)) {
                foreach ($attr_matches as $attr) {
                    $attributes[$attr[1]] = $attr[2];
                }
            }
            
            // extract text attributes
            foreach ($text_attrs as $attr) {
                if (!empty($attributes[$attr])) {
                    $results[] = [
                        'original_block_index' => $block_index,
                        'shortcode_tag' => $tag,
                        'type' => 'attribute',
                        'key' => $attr,
                        'value' => $attributes[$attr]
                    ];
                }
            }
            
            $block_index++;
        }
    }
    
    return $results;
}

// one-liner version for quick testing
function quick_divi_text_scan($content) {
    preg_match_all('#\[et_pb_\w+[^\]]*(?:title|heading|content|button_text|name|author)=["\']([^"\']+)["\'][^\]]*\]#', $content, $m);
    return $m[1];
}