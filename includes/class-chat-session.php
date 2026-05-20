<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Chat_Session {

    public function get_history( int $post_id ): array {
        $user_id = get_current_user_id();
        $meta_key = '_woo_elementor_ai_chat_' . $post_id;
        $history = get_user_meta( $user_id, $meta_key, true );
        return is_array( $history ) ? $history : [];
    }

    public function add_message( int $post_id, string $role, string $content ): void {
        $history = $this->get_history( $post_id );
        $history[] = [
            'role'      => $role,
            'content'   => $content,
            'timestamp' => time(),
        ];
        $this->save_history( $post_id, $history );
    }

    public function clear_history( int $post_id ): void {
        $user_id = get_current_user_id();
        $meta_key = '_woo_elementor_ai_chat_' . $post_id;
        delete_user_meta( $user_id, $meta_key );
    }

    public function get_messages_for_api( int $post_id, int $max_tokens = 8000 ): array {
        $history = $this->get_history( $post_id );
        $messages = [];
        $total_chars = 0;
        $max_chars = $max_tokens * 4;

        $reversed = array_reverse( $history );
        $kept = [];

        foreach ( $reversed as $msg ) {
            $chars = strlen( $msg['content'] ?? '' );
            if ( $total_chars + $chars > $max_chars ) break;
            $total_chars += $chars;
            $kept[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        return array_reverse( $kept );
    }

    public function build_context_string( int $post_id, ?string $target_element_id = null ): string {
        $elementor_data = new Elementor_Data();
        $condensed = $elementor_data->get_condensed_page_data( $post_id );

        if ( empty( $condensed ) ) {
            return 'Empty page, no elements yet.';
        }

        $context = "Current page structure:\n";
        $context .= $this->format_condensed( $condensed, 0 );

        if ( $target_element_id ) {
            $context .= "\n\nTarget element ID: {$target_element_id}";
            $raw = get_post_meta( $post_id, '_elementor_data', true );
            $elements = json_decode( $raw, true );
            if ( is_array( $elements ) ) {
                $target = $this->find_element_by_id( $elements, $target_element_id );
                if ( $target ) {
                    $context .= "\nTarget element data:\n" . wp_json_encode( $target, JSON_PRETTY_PRINT );
                }
            }
        }

        return $context;
    }

    private function format_condensed( array $elements, int $depth ): string {
        $output = '';
        $indent = str_repeat( '  ', $depth );
        foreach ( $elements as $el ) {
            $type = $el['elType'] ?? 'unknown';
            $widget = ! empty( $el['widgetType'] ) ? " ({$el['widgetType']})" : '';
            $id = $el['id'] ?? '?';
            $output .= "{$indent}- {$type}{$widget} [{$id}]\n";
            if ( ! empty( $el['elements'] ) ) {
                $output .= $this->format_condensed( $el['elements'], $depth + 1 );
            }
        }
        return $output;
    }

    private function find_element_by_id( array $elements, string $id ): ?array {
        foreach ( $elements as $el ) {
            if ( ( $el['id'] ?? '' ) === $id ) {
                return $el;
            }
            if ( ! empty( $el['elements'] ) ) {
                $found = $this->find_element_by_id( $el['elements'], $id );
                if ( $found ) return $found;
            }
        }
        return null;
    }

    private function save_history( int $post_id, array $history ): void {
        $user_id = get_current_user_id();
        $meta_key = '_woo_elementor_ai_chat_' . $post_id;

        if ( count( $history ) > 100 ) {
            $history = array_slice( $history, -50 );
        }

        update_user_meta( $user_id, $meta_key, $history );
    }
}
