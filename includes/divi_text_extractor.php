<?php
// includes/divi_text_extractor.php
class Divi_Text_Extractor {

    /**
     * return [ 'texts' => [objects], 'blocks' => [strings], 'debug' => [] ]
     */
    public static function extract( string $content ): array {

        // if divi shortcodes aren't registered, jump straight to the pure regex helper
        if ( ! shortcode_exists( 'et_pb_text' ) ) {
            $texts = extract_divi_text_regex_only( $content );
            return [
                'texts'  => $texts,
                'blocks' => [], // we don't need them for save()
                'debug'  => [ 'source' => 'regex_fallback', 'match_count' => count( $texts ) ],
            ];
        }

        $regex   = '/' . get_shortcode_regex() . '/s';
        preg_match_all( $regex, $content, $all, PREG_SET_ORDER );

        $texts  = [];
        $blocks = [];

        foreach ( $all as $sc ) {

            $tag        = $sc[2];              // shortcode tag
            $attsString = $sc[3];              // raw attributes
            $inner      = $sc[5];              // inner content
            $full       = $sc[0];

            // skip layout wrappers
            if ( in_array( $tag, [ 'et_pb_section', 'et_pb_row', 'et_pb_column' ], true ) ) {
                continue;
            }

            $blockIndex = count( $blocks );
            $blocks[]   = $full;               // keep original for save()

            // attributes → text objects
            $atts = shortcode_parse_atts( $attsString );
            foreach ( $atts as $k => $v ) {
                if ( is_string( $v ) && trim( $v ) !== '' ) {
                    $texts[] = [
                        'original_block_index' => $blockIndex,
                        'shortcode_tag'        => $tag,
                        'type'                 => 'attribute',
                        'key'                  => $k,
                        'value'                => $v,
                    ];
                }
            }

            // inner content
            if ( trim( $inner ) !== '' ) {
                $texts[] = [
                    'original_block_index' => $blockIndex,
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
}