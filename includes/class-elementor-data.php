<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Elementor_Data {

    public function generate_element_id(): string {
        return substr( md5( uniqid( mt_rand(), true ) ), 0, 8 );
    }

    public function validate_and_parse( string $ai_response, int $post_id = 0 ): array {
        $log = \WooElementorAI\Log_Service::get_instance();

        $log->log( 'json_parse', 'info', 'validate_and_parse called', [
            'raw_length' => strlen( $ai_response ),
            'raw_first100' => substr( $ai_response, 0, 100 ),
            'raw_last100' => substr( $ai_response, -100 ),
            'has_json_fence' => strpos( $ai_response, '```json' ) !== false || strpos( $ai_response, '```JSON' ) !== false,
            'has_backticks' => strpos( $ai_response, '```' ) !== false,
        ], $post_id );

        $data = $this->try_json_parse( $ai_response );
        if ( null !== $data ) {
            $validated = $this->validate_elements( $data );
            $log->log( 'json_parse', 'success', 'Direct JSON parse succeeded', [
                'elements_count' => count( $validated ),
                'raw_length' => strlen( $ai_response ),
                'raw_response' => $ai_response,
            ], $post_id );
            return $validated;
        }

        $log->log( 'json_parse', 'info', 'try_json_parse failed, trying extract_json_from_markdown', [
            'raw_length' => strlen( $ai_response ),
        ], $post_id );

        $data = $this->extract_json_from_markdown( $ai_response );
        if ( null !== $data ) {
            $validated = $this->validate_elements( $data );
            $log->log( 'json_parse', 'success', 'JSON extracted/repaired from markdown', [
                'elements_count' => count( $validated ),
                'raw_length' => strlen( $ai_response ),
                'raw_response' => $ai_response,
            ], $post_id );
            return $validated;
        }

        $log->log( 'json_parse', 'error', 'All JSON parse attempts failed, using HTML fallback', [
            'raw_length' => strlen( $ai_response ),
            'raw_response' => $ai_response,
        ], $post_id );

        return $this->html_fallback( $ai_response );
    }

    private function try_json_parse( string $text ): ?array {
        $log = \WooElementorAI\Log_Service::get_instance();
        $text = trim( $text );

        $text = $this->strip_markdown_fences( $text );

        $log->log( 'json_parse', 'info', 'try_json_parse: after strip_markdown_fences', [
            'stripped_first100' => substr( $text, 0, 100 ),
            'stripped_last100' => substr( $text, -100 ),
            'stripped_length' => strlen( $text ),
        ] );

        $decoded = json_decode( $text, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            return $decoded;
        }

        $log->log( 'json_parse', 'info', 'try_json_parse: first decode failed', [
            'json_error' => json_last_error_msg(),
        ] );

        $text = $this->escape_control_chars_in_strings( $text );
        $decoded = json_decode( $text, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            return $decoded;
        }

        $log->log( 'json_parse', 'info', 'try_json_parse: second decode (after escape) also failed', [
            'json_error' => json_last_error_msg(),
        ] );

        return null;
    }

    /**
     * Strip markdown code fences from AI response.
     * Handles: ```json ... ```, ``` ... ```, unclosed fences.
     */
    private function strip_markdown_fences( string $text ): string {
        // Remove complete code fences with optional language tag
        $text = preg_replace( '/^```(?:json|JSON)?\s*\r?\n?/m', '', $text );
        $text = preg_replace( '/^```\s*$/m', '', $text );
        // Remove trailing partial fences
        $text = preg_replace( '/```+\s*$/', '', $text );
        return trim( $text );
    }

    private function extract_json_from_markdown( string $text ): ?array {
        // 1. Try markdown code block with closing fence
        if ( preg_match( '/```(?:json|JSON)?\s*([\s\S]*?)```/', $text, $matches ) ) {
            $block = $this->escape_control_chars_in_strings( trim( $matches[1] ) );
            $decoded = json_decode( $block, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $decoded;
            }
            // Repair attempt on code block content
            $repaired = $this->repair_json( trim( $matches[1] ) );
            if ( null !== $repaired ) {
                return $repaired;
            }
        }

        // 1b. Unclosed markdown fence (truncated response: ```json... but no ```)
        //     Strip opening fence, try parse the rest
        if ( preg_match( '/^```(?:json|JSON)?\s*([\s\S]+)$/m', $text, $matches ) ) {
            $remainder = rtrim( $matches[1] );
            // Remove trailing ``` if partial
            $remainder = preg_replace( '/```+\s*$/', '', $remainder );
            $block = $this->escape_control_chars_in_strings( trim( $remainder ) );
            $decoded = json_decode( $block, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $decoded;
            }
            $repaired = $this->repair_json( trim( $remainder ) );
            if ( null !== $repaired ) {
                return $repaired;
            }
        }

        // 1c. Strip all markdown fences from text, try parse what's left
        $stripped = preg_replace( '/^```(?:json|JSON)?\s*/m', '', $text );
        $stripped = preg_replace( '/^```\s*$/m', '', $stripped );
        $stripped = trim( $stripped );
        if ( $stripped !== trim( $text ) ) {
            $block = $this->escape_control_chars_in_strings( $stripped );
            $decoded = json_decode( $block, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $decoded;
            }
            $repaired = $this->repair_json( $stripped );
            if ( null !== $repaired ) {
                return $repaired;
            }
        }

        // 2. Find outermost [ ... ] bracket range
        $start = strpos( $text, '[' );
        $end   = strrpos( $text, ']' );
        if ( false !== $start && false !== $end && $end > $start ) {
            $json = $this->escape_control_chars_in_strings( substr( $text, $start, $end - $start + 1 ) );
            $decoded = json_decode( $json, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $decoded;
            }
            // Repair attempt on extracted substring
            $repaired = $this->repair_json( substr( $text, $start, $end - $start + 1 ) );
            if ( null !== $repaired ) {
                return $repaired;
            }
        }

        // 3. Also try { ... } in case AI returned a single object
        $start_obj = strpos( $text, '{' );
        $end_obj   = strrpos( $text, '}' );
        if ( false !== $start_obj && false !== $end_obj && $end_obj > $start_obj ) {
            $json = $this->escape_control_chars_in_strings( substr( $text, $start_obj, $end_obj - $start_obj + 1 ) );
            $decoded = json_decode( $json, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $decoded;
            }
            $repaired = $this->repair_json( substr( $text, $start_obj, $end_obj - $start_obj + 1 ) );
            if ( null !== $repaired ) {
                return $repaired;
            }
        }

        return null;
    }

    /**
     * Attempt to repair common AI JSON syntax errors and decode.
     *
     * Known errors from gpt-5-mini-free:
     *  - Missing commas between ] and { at container boundaries: ]{
     *  - "el" instead of "elType"
     *  - Malformed objects: "id": "step1 "elType":"  (missing closing quote + comma)
     *  - Unclosed strings: typography_font_family without opening quote
     *  - Truncated JSON (missing closing brackets/braces)
     *  - Single quotes instead of double quotes
     *  - Trailing commas before ] or }
     */
    private function repair_json( string $json ): ?array {
        $json = trim( $json );

        // Pass 0: Escape raw control characters inside string values.
        // AI often outputs literal newlines/tabs inside JSON strings (e.g. in "editor" HTML content).
        // json_decode rejects control chars U+00-U+1F inside strings.
        $json = $this->escape_control_chars_in_strings( $json );

        // Pass 1: Fix "el" → "elType" (common AI key error)
        $json = preg_replace(
            '/"el"\s*:/',
            '"elType":',
            $json
        );

        // Pass 2: Missing comma between ] and { — e.g. ]{  or ]  {
        $json = preg_replace(
            '/\]\s*\{/',
            '],{',
            $json
        );

        // Pass 3: Missing comma between } and { — e.g. }{  or }  {
        $json = preg_replace(
            '/\}\s*\{/',
            '},{',
            $json
        );

        // Pass 4: Missing comma between ] and [ — e.g. ][  or ]  [
        $json = preg_replace(
            '/\]\s*\[/',
            '],[',
            $json
        );

        // Pass 5: Missing comma between } and [ — e.g. }[  or }  [
        $json = preg_replace(
            '/\}\s*\[/',
            '},[',
            $json
        );

        // Pass 6: Trailing commas before ] or }
        $json = preg_replace(
            '/,\s*([}\]])/',
            '$1',
            $json
        );

        // Pass 7: Single quotes → double quotes (outside of already-quoted strings)
        // Simple approach: only if no double quotes found at all
        if ( strpos( $json, '"' ) === false && strpos( $json, "'" ) !== false ) {
            $json = str_replace( "'", '"', $json );
        }

        // Pass 8: Balance brackets — auto-close truncated JSON
        $json = $this->balance_brackets( $json );

        // Try decode
        $decoded = json_decode( $json, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            return $decoded;
        }

        // Pass 9: Aggressive — try to find the last valid complete element
        // by progressively truncating from the end
        $decoded = $this->try_truncated_decode( $json );
        if ( null !== $decoded ) {
            return $decoded;
        }

        return null;
    }

    /**
     * Escape raw control characters (U+00-U+1F) that appear inside JSON string values.
     * AI models frequently output literal newlines and tabs inside "editor" HTML content.
     * json_decode rejects unescaped control chars inside strings per RFC 8259.
     */
    private function escape_control_chars_in_strings( string $json ): string {
        $result = '';
        $len    = strlen( $json );
        $in_str = false;
        $escape = false;

        for ( $i = 0; $i < $len; $i++ ) {
            $ch  = $json[ $i ];
            $ord = ord( $ch );

            if ( $escape ) {
                $result .= $ch;
                $escape  = false;
                continue;
            }

            if ( '\\' === $ch ) {
                $result .= $ch;
                $escape  = true;
                continue;
            }

            if ( '"' === $ch ) {
                $in_str  = ! $in_str;
                $result .= $ch;
                continue;
            }

            if ( $in_str && $ord < 32 ) {
                // Escape control characters inside strings
                if ( 10 === $ord ) {
                    $result .= '\\n';
                } elseif ( 13 === $ord ) {
                    $result .= '\\r';
                } elseif ( 9 === $ord ) {
                    $result .= '\\t';
                } else {
                    $result .= '\\u' . sprintf( '%04x', $ord );
                }
                continue;
            }

            $result .= $ch;
        }

        return $result;
    }

    /**
     * Auto-close unmatched brackets/braces in potentially truncated JSON.
     */
    private function balance_brackets( string $json ): string {
        $len    = strlen( $json );
        $stack  = [];
        $in_str = false;
        $escape = false;

        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $json[ $i ];
            if ( $escape ) {
                $escape = false;
                continue;
            }
            if ( '\\' === $ch ) {
                $escape = true;
                continue;
            }
            if ( '"' === $ch ) {
                $in_str = ! $in_str;
                continue;
            }
            if ( $in_str ) {
                continue;
            }
            if ( '{' === $ch || '[' === $ch ) {
                $stack[] = $ch;
            } elseif ( '}' === $ch ) {
                if ( ! empty( $stack ) && '{' === end( $stack ) ) {
                    array_pop( $stack );
                }
            } elseif ( ']' === $ch ) {
                if ( ! empty( $stack ) && '[' === end( $stack ) ) {
                    array_pop( $stack );
                }
            }
        }

        // Close any unclosed string
        if ( $in_str ) {
            $json .= '"';
        }

        // Close remaining open brackets in reverse order
        while ( ! empty( $stack ) ) {
            $open = array_pop( $stack );
            $json .= ( '{' === $open ) ? '}' : ']';
        }

        return $json;
    }

    /**
     * Try progressively truncating JSON to find a valid parse.
     * Removes incomplete trailing elements by finding the last complete
     * } or ] boundary and attempting decode.
     */
    private function try_truncated_decode( string $json ): ?array {
        // If it starts with [, find last complete element boundary
        if ( '[' === $json[0] ) {
            // Try removing content after last complete }, ]
            for ( $i = strlen( $json ) - 1; $i >= 1; $i-- ) {
                $ch = $json[ $i ];
                if ( '}' === $ch || ']' === $ch ) {
                    $candidate = substr( $json, 0, $i + 1 );
                    // Ensure we close the outer array
                    $candidate = $this->balance_brackets( $candidate );
                    // Remove trailing commas before closing
                    $candidate = preg_replace( '/,\s*\]/', ']', $candidate );
                    $decoded = json_decode( $candidate, true );
                    if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                        return $decoded;
                    }
                }
            }
        }

        // If it starts with {, try finding last complete boundary
        if ( '{' === $json[0] ) {
            for ( $i = strlen( $json ) - 1; $i >= 1; $i-- ) {
                if ( '}' === $ch ?? '' ) {
                    $candidate = substr( $json, 0, $i + 1 );
                    $candidate = $this->balance_brackets( $candidate );
                    $decoded = json_decode( $candidate, true );
                    if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                        return $decoded;
                    }
                }
            }
        }

        return null;
    }

    private function validate_elements( array $elements ): array {
        if ( ! $this->is_indexed_array( $elements ) ) {
            if ( isset( $elements['content'] ) && is_array( $elements['content'] ) ) {
                $elements = $elements['content'];
            } else {
                $elements = [ $elements ];
            }
        }

        $valid = [];
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) continue;
            $cleaned = $this->clean_element( $element );
            if ( $cleaned ) {
                $valid[] = $cleaned;
            }
        }

        // Elementor silently drops orphan widgets at root level.
        // Only 'container' or 'section' may exist at root.
        // Wrap any orphan widgets in a container.
        return $this->ensure_wrapped( $valid );
    }

    /**
     * Ensure no widget elements sit at the root level.
     * Elementor requires root-level elements to be 'container' or 'section'.
     * Orphan widgets are wrapped in a container with flex_direction: column.
     */
    private function ensure_wrapped( array $elements ): array {
        if ( empty( $elements ) ) {
            return $elements;
        }

        $wrapped = [];
        $orphans = [];

        foreach ( $elements as $element ) {
            if ( 'widget' === ( $element['elType'] ?? '' ) ) {
                $orphans[] = $element;
            } else {
                // Flush collected orphans into a container first
                if ( ! empty( $orphans ) ) {
                    $wrapped[] = $this->wrap_orphan_widgets( $orphans );
                    $orphans = [];
                }
                // Recursively ensure children are also properly wrapped
                if ( ! empty( $element['elements'] ) ) {
                    $element['elements'] = $this->ensure_wrapped( $element['elements'] );
                }
                $wrapped[] = $element;
            }
        }

        if ( ! empty( $orphans ) ) {
            $wrapped[] = $this->wrap_orphan_widgets( $orphans );
        }

        return $wrapped;
    }

    private function wrap_orphan_widgets( array $widgets ): array {
        return [
            'id'       => $this->generate_element_id(),
            'elType'   => 'container',
            'isInner'  => false,
            'settings' => [ 'flex_direction' => 'column' ],
            'elements' => $widgets,
        ];
    }

    private function clean_element( array $element ): ?array {
        if ( empty( $element['elType'] ) ) return null;

        $valid_types = [ 'container', 'section', 'column', 'widget' ];
        if ( ! in_array( $element['elType'], $valid_types, true ) ) return null;

        $cleaned = [
            'id'       => $element['id'] ?? $this->generate_element_id(),
            'elType'   => $element['elType'],
            'isInner'  => $element['isInner'] ?? false,
            'settings' => ! empty( $element['settings'] ) && is_array( $element['settings'] )
                ? $element['settings'] : [],
            'elements' => [],
        ];

        if ( 'widget' === $cleaned['elType'] ) {
            $cleaned['widgetType'] = $element['widgetType'] ?? 'text-editor';
        }

        if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
            foreach ( $element['elements'] as $child ) {
                $child_cleaned = $this->clean_element( $child );
                if ( $child_cleaned ) {
                    $cleaned['elements'][] = $child_cleaned;
                }
            }
        }

        return $cleaned;
    }

    private function html_fallback( string $html ): array {
        $blocks = preg_split( '/\n{2,}/', trim( $html ) );
        $elements = [];

        foreach ( $blocks as $block ) {
            $block = trim( $block );
            if ( empty( $block ) ) continue;

            $elements[] = [
                'id'       => $this->generate_element_id(),
                'elType'   => 'widget',
                'widgetType' => 'text-editor',
                'isInner'  => false,
                'settings' => [
                    'editor' => wp_kses_post( $block ),
                ],
                'elements' => [],
            ];
        }

        if ( empty( $elements ) ) {
            $elements[] = [
                'id'       => $this->generate_element_id(),
                'elType'   => 'widget',
                'widgetType' => 'text-editor',
                'isInner'  => false,
                'settings' => [
                    'editor' => '<p>' . esc_html( substr( $html, 0, 500 ) ) . '</p>',
                ],
                'elements' => [],
            ];
        }

        $container_id = $this->generate_element_id();
        return [
            [
                'id'       => $container_id,
                'elType'   => 'container',
                'isInner'  => false,
                'settings' => [ 'flex_direction' => 'column' ],
                'elements' => $elements,
            ],
        ];
    }

    public function create_elementor_post( string $title, array $elements, string $post_type = 'page' ): int {
        // Use Elementor Document API when available
        if ( class_exists( '\Elementor\Plugin' ) ) {
            $doc_type = ( 'page' === $post_type ) ? 'wp-page' : 'wp-post';

            $document = \Elementor\Plugin::$instance->documents->create(
                $doc_type,
                [
                    'post_title'  => $title,
                    'post_status' => 'draft',
                    'post_type'   => $post_type,
                ]
            );

            if ( is_wp_error( $document ) ) {
                return 0;
            }

            $document->save( [
                'elements' => $elements,
            ] );

            return $document->get_id();
        }

        // Fallback: manual meta writes when Elementor not active
        $post_id = wp_insert_post( [
            'post_title'   => $title,
            'post_status'  => 'draft',
            'post_type'    => $post_type,
            'post_content' => '',
        ] );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        $this->save_elementor_data( $post_id, $elements );

        return $post_id;
    }

    public function save_elementor_data( int $post_id, array $elements ): void {
        // Use Elementor Document API when available
        if ( class_exists( '\Elementor\Plugin' ) ) {
            $document = \Elementor\Plugin::$instance->documents->get( $post_id );

            if ( $document ) {
                $document->save( [
                    'elements' => $elements,
                ] );
                return;
            }
        }

        // Fallback: manual meta writes when Elementor not active
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
        update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
        update_post_meta( $post_id, '_elementor_version', '3.21.0' );
        update_post_meta( $post_id, '_wp_page_template', 'default' );
    }

    public function get_condensed_page_data( int $post_id ): array {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( empty( $raw ) ) return [];

        $elements = json_decode( $raw, true );
        if ( ! is_array( $elements ) ) return [];

        return $this->condense_elements( $elements );
    }

    private function condense_elements( array $elements ): array {
        $result = [];
        foreach ( $elements as $el ) {
            $item = [
                'id'     => $el['id'] ?? '',
                'elType' => $el['elType'] ?? '',
            ];
            if ( ! empty( $el['widgetType'] ) ) {
                $item['widgetType'] = $el['widgetType'];
            }
            if ( ! empty( $el['elements'] ) ) {
                $item['elements'] = $this->condense_elements( $el['elements'] );
            }
            $result[] = $item;
        }
        return $result;
    }

    public function apply_element_changes( int $post_id, array $actions ): array {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        $elements = json_decode( $raw, true );
        if ( ! is_array( $elements ) ) {
            return [ 'success' => false, 'message' => 'No Elementor data found' ];
        }

        foreach ( $actions as $action ) {
            $type = $action['type'] ?? '';
            switch ( $type ) {
                case 'element_create':
                    $parent_id = $action['parent_id'] ?? null;
                    $new_el    = $action['element'] ?? null;
                    if ( $new_el ) {
                        if ( empty( $new_el['id'] ) ) {
                            $new_el['id'] = $this->generate_element_id();
                        }
                        if ( $parent_id ) {
                            $this->walk_and_insert( $elements, $parent_id, $new_el );
                        } else {
                            $elements[] = $new_el;
                        }
                    }
                    break;

                case 'element_update':
                    $element_id = $action['element_id'] ?? null;
                    $settings   = $action['settings'] ?? [];
                    if ( $element_id && $settings ) {
                        $this->walk_and_update( $elements, $element_id, $settings );
                    }
                    break;

                case 'element_delete':
                    $element_id = $action['element_id'] ?? null;
                    if ( $element_id ) {
                        $elements = $this->walk_and_delete( $elements, $element_id );
                    }
                    break;

                case 'element_replace':
                    $element_id = $action['element_id'] ?? null;
                    $new_el     = $action['element'] ?? null;
                    if ( $element_id && $new_el ) {
                        if ( empty( $new_el['id'] ) ) {
                            $new_el['id'] = $this->generate_element_id();
                        }
                        $this->walk_and_replace( $elements, $element_id, $new_el );
                    }
                    break;
            }
        }

        $this->save_elementor_data( $post_id, $elements );

        return [ 'success' => true, 'elements' => $elements ];
    }

    private function walk_and_insert( array &$elements, string $parent_id, array $new_element ): bool {
        foreach ( $elements as &$el ) {
            if ( ( $el['id'] ?? '' ) === $parent_id ) {
                $el['elements'][] = $new_element;
                return true;
            }
            if ( ! empty( $el['elements'] ) ) {
                if ( $this->walk_and_insert( $el['elements'], $parent_id, $new_element ) ) {
                    return true;
                }
            }
        }
        unset( $el );
        return false;
    }

    private function walk_and_update( array &$elements, string $element_id, array $settings ): bool {
        foreach ( $elements as &$el ) {
            if ( ( $el['id'] ?? '' ) === $element_id ) {
                $el['settings'] = array_merge( $el['settings'] ?? [], $settings );
                return true;
            }
            if ( ! empty( $el['elements'] ) ) {
                if ( $this->walk_and_update( $el['elements'], $element_id, $settings ) ) {
                    return true;
                }
            }
        }
        unset( $el );
        return false;
    }

    private function walk_and_delete( array &$elements, string $element_id ): array {
        $result = [];
        foreach ( $elements as $el ) {
            if ( ( $el['id'] ?? '' ) === $element_id ) continue;
            if ( ! empty( $el['elements'] ) ) {
                $el['elements'] = $this->walk_and_delete( $el['elements'], $element_id );
            }
            $result[] = $el;
        }
        return $result;
    }

    private function walk_and_replace( array &$elements, string $element_id, array $new_element ): bool {
        foreach ( $elements as $key => &$el ) {
            if ( ( $el['id'] ?? '' ) === $element_id ) {
                $elements[ $key ] = $new_element;
                return true;
            }
            if ( ! empty( $el['elements'] ) ) {
                if ( $this->walk_and_replace( $el['elements'], $element_id, $new_element ) ) {
                    return true;
                }
            }
        }
        unset( $el );
        return false;
    }

    private function is_indexed_array( array $arr ): bool {
        if ( empty( $arr ) ) return true;
        return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
    }
}
