<?php
namespace WooElementorAI\API;

use WooElementorAI\Settings;
use WooElementorAI\AI_Service;
use WooElementorAI\Page_Generator;
use WooElementorAI\Chat_Session;
use WooElementorAI\Template_Exporter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class REST_Controller {
    const NAMESPACE = 'woo-elementor-ai/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_page' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/generate/stream', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_page_stream' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/generate/element', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_element' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/generate/element/stream', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_element_stream' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/chat', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'chat' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/chat/stream', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'chat_stream' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/chat/clear', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'chat_clear' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/chat/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'chat_history' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/refine', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'refine' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/refine/stream', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'refine_stream' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/settings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_settings' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/settings/test', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'test_connection' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/templates/export/(?P<id>[a-zA-Z0-9\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'export_template' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/logs', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_logs' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/logs/clear', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'clear_logs' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/ai-config', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_ai_config' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/generate/process', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_process' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/generate/element/process', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_element_process' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/chat/process', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'chat_process' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/refine/process', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'refine_process' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }

    public function generate_process( \WP_REST_Request $request ): \WP_REST_Response {
        $title     = sanitize_text_field( $request->get_param( 'title' ) );
        $content   = $request->get_param( 'content' );
        $post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?: 'page' );

        if ( empty( $title ) || empty( $content ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Title and content required.' ], 400 );
        }

        $elementor_data = new \WooElementorAI\Elementor_Data();
        $elements = $elementor_data->validate_and_parse( $content );

        if ( empty( $elements ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Invalid Elementor data.' ], 500 );
        }

        $settings_obj = new Settings();
        if ( $settings_obj->is_image_configured() ) {
            $image_service = new \WooElementorAI\Image_Service();
            $image_service->resolve_images_in_elements( $elements );
        }

        $post_id = $elementor_data->create_elementor_post( $title, $elements, $post_type );

        if ( ! $post_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Failed to create page.' ], 500 );
        }

        return new \WP_REST_Response( [
            'success'  => true,
            'post_id'  => $post_id,
            'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
        ], 200 );
    }

    public function generate_element_process( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id  = absint( $request->get_param( 'post_id' ) );
        $content  = $request->get_param( 'content' );

        if ( ! $post_id || empty( $content ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'post_id and content required.' ], 400 );
        }

        $elementor_data = new \WooElementorAI\Elementor_Data();
        $parsed = $elementor_data->validate_and_parse( $content, $post_id );

        if ( empty( $parsed ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Invalid element data.' ], 500 );
        }

        $new_element = $parsed[0];
        if ( empty( $new_element['id'] ) ) {
            $new_element['id'] = $elementor_data->generate_element_id();
        }

        $settings_obj = new Settings();
        if ( $settings_obj->is_image_configured() ) {
            $wrapped = [ &$new_element ];
            $image_service = new \WooElementorAI\Image_Service();
            $image_service->resolve_images_in_elements( $wrapped );
            unset( $wrapped );
        }

        return new \WP_REST_Response( [
            'success'    => true,
            'element_id' => $new_element['id'],
            'element'    => $new_element,
        ], 200 );
    }

    public function chat_process( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id  = absint( $request->get_param( 'post_id' ) );
        $content  = $request->get_param( 'content' );
        $message  = sanitize_textarea_field( $request->get_param( 'message' ) );

        if ( ! $post_id || empty( $content ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'post_id and content required.' ], 400 );
        }

        $chat_session = new Chat_Session();
        if ( $message ) {
            $chat_session->add_message( $post_id, 'user', $message );
        }
        $chat_session->add_message( $post_id, 'assistant', $content );

        $actions = $this->parse_actions_from_response( $content, $post_id );

        return new \WP_REST_Response( [
            'success' => true,
            'content' => $content,
            'actions' => $actions,
        ], 200 );
    }

    public function refine_process( \WP_REST_Request $request ): \WP_REST_Response {
        $content = $request->get_param( 'content' );

        if ( empty( $content ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Content required.' ], 400 );
        }

        return new \WP_REST_Response( [
            'success'        => true,
            'refined_prompt' => trim( $content ),
        ], 200 );
    }

    public function generate_page( \WP_REST_Request $request ): \WP_REST_Response {
        // AI generation may take a while — extend PHP execution limit if possible
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        $title     = sanitize_text_field( $request->get_param( 'title' ) );
        $prompt    = sanitize_textarea_field( $request->get_param( 'prompt' ) );
        $post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?: 'page' );

        if ( empty( $title ) || empty( $prompt ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'data'    => [ 'code' => 'missing_params', 'message' => __( 'Title and prompt are required.', 'woo-elementor-ai' ) ],
            ], 400 );
        }

        $generator = new Page_Generator();
        $result = $generator->generate( $title, $prompt, $post_type );

        return new \WP_REST_Response( [ 'success' => $result['success'], 'data' => $result['data'] ?? $result ], $result['success'] ? 200 : 500 );
    }

    public function generate_page_stream( \WP_REST_Request $request ): void {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $title     = sanitize_text_field( $request->get_param( 'title' ) );
        $prompt    = sanitize_textarea_field( $request->get_param( 'prompt' ) );
        $post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?: 'page' );
        $method    = sanitize_text_field( $request->get_param( 'copywriting_method' ) ?? '' );
        $language  = sanitize_text_field( $request->get_param( 'language' ) ?? 'id' );

        if ( empty( $title ) || empty( $prompt ) ) {
            header( 'Content-Type: text/event-stream' );
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'Title and prompt are required.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $this->sse_generate( $title, $prompt, $post_type, $method, $language );
    }

    public function refine_stream( \WP_REST_Request $request ): void {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $prompt   = sanitize_textarea_field( $request->get_param( 'prompt' ) );
        $context  = sanitize_text_field( $request->get_param( 'context' ) ?: 'page' );
        $method   = sanitize_text_field( $request->get_param( 'copywriting_method' ) ?? '' );
        $language = sanitize_text_field( $request->get_param( 'language' ) ?? 'id' );

        if ( empty( $prompt ) ) {
            header( 'Content-Type: text/event-stream' );
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'Prompt is required.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $this->sse_refine( $prompt, $context, $method, $language );
    }

    private function sse_generate( string $title, string $prompt, string $post_type, string $method = '', string $language = 'id' ): void {
        $log = \WooElementorAI\Log_Service::get_instance();
        $log->log( 'api_endpoint', 'info', "sse_generate called: title='{$title}' method={$method} lang={$language}", [ 'title' => $title, 'method' => $method, 'language' => $language, 'post_type' => $post_type ] );

        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );

        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();

        if ( empty( $settings['api_key'] ) || empty( $settings['base_url'] ) ) {
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'AI API not configured.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $generator     = new Page_Generator();
        $system_prompt = $generator->build_page_system_prompt_public();
        $user_prompt   = "Create a page titled \"{$title}\" with the following description:\n\n{$prompt}";

        // Append copywriting method and language instructions to user prompt
        $user_prompt .= $this->build_method_language_suffix( $method, $language );

        $ai      = new AI_Service();
        $full_content = '';
        $chunk_count = 0;

        $ai->chat_stream_sse(
            [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user', 'content' => $user_prompt ],
            ],
            [
                'max_tokens' => 64000,
                'on_chunk' => function( string $delta_content, ?string $delta_reasoning ) use ( &$full_content, &$chunk_count ) {
                    $chunk_count++;
                    if ( $delta_content !== '' ) {
                        $full_content .= $delta_content;
                    }
                    echo "data: " . wp_json_encode( [
                        'type'      => 'chunk',
                        'content'   => $delta_content,
                        'reasoning' => $delta_reasoning,
                        'tokens'    => $chunk_count,
                    ] ) . "\n\n";
                    ob_flush(); flush();
                },
            ],
            $settings
        );

        // Stream done — now create the page
        if ( empty( $full_content ) ) {
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'AI returned empty content.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $elementor_data = new \WooElementorAI\Elementor_Data();
        $elements = $elementor_data->validate_and_parse( $full_content );

        if ( empty( $elements ) ) {
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'AI returned invalid Elementor data. Please try again.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        if ( $settings_obj->is_image_configured() ) {
            $image_service = new \WooElementorAI\Image_Service();
            $image_service->resolve_images_in_elements( $elements );
        }

        $post_id = $elementor_data->create_elementor_post( $title, $elements, $post_type );

        if ( ! $post_id ) {
            $log->log( 'api_endpoint', 'error', 'sse_generate failed: create_elementor_post returned 0', [ 'title' => $title, 'elements_count' => count( $elements ) ] );
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'Failed to create page.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $log->log( 'api_endpoint', 'success', "sse_generate completed: post_id={$post_id}", [ 'post_id' => $post_id ] );

        echo "data: " . wp_json_encode( [
            'type'     => 'complete',
            'post_id'  => $post_id,
            'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
        ] ) . "\n\n";
        ob_flush(); flush();
    }

    private function sse_refine( string $prompt, string $context, string $method = '', string $language = 'id' ): void {
        $log = \WooElementorAI\Log_Service::get_instance();
        $log->log( 'api_endpoint', 'info', "sse_refine called: context={$context}", [ 'context' => $context, 'method' => $method, 'language' => $language ] );

        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );

        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();

        if ( empty( $settings['api_key'] ) || empty( $settings['base_url'] ) ) {
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'AI API not configured.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

		$system_prompt = $context === 'element'
			? 'You are a prompt refinement assistant for an AI Elementor element designer. Expand brief descriptions into detailed, specific prompts for editing web page elements. Include: desired visual style (dark/light, minimalist/bold), specific layout changes, content text, color scheme, typography preferences (font sizes, weights), spacing, and any specific element behavior. Output ONLY the refined prompt, nothing else.'
			: 'You are a prompt refinement assistant for an AI Elementor page designer. Expand brief page descriptions into detailed, specific prompts that describe: the visual mood or aesthetic direction (e.g. dark premium, editorial luxury, modern minimal, soft organic), sections needed with content for each, color scheme with specific hex values or mood, typography style (bold headlines vs clean body), spacing rhythm (generous vs compact), overall layout structure (hero + features + CTA pattern), and desired emotional impact. Be specific about the design feel, not just content. Output ONLY the refined prompt, nothing else.';

        $user_prompt = "Expand this description into a detailed page building prompt:\n\n\"{$prompt}\"";
        $user_prompt .= $this->build_method_language_suffix( $method, $language );

        $ai           = new AI_Service();
        $full_content = '';

        $ai->chat_stream_sse(
            [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user', 'content' => $user_prompt ],
            ],
            [
                'max_tokens'  => 64000,
                'temperature' => 0.8,
                'on_chunk' => function( string $delta_content, ?string $delta_reasoning ) use ( &$full_content ) {
                    if ( $delta_content !== '' ) {
                        $full_content .= $delta_content;
                    }
                    echo "data: " . wp_json_encode( [
                        'type'      => 'chunk',
                        'content'   => $delta_content,
                        'reasoning' => $delta_reasoning,
                    ] ) . "\n\n";
                    ob_flush(); flush();
                },
            ],
            $settings
        );

        if ( empty( $full_content ) ) {
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'AI returned empty content.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $log->log( 'api_endpoint', 'success', 'sse_refine completed', [ 'refined_length' => strlen( trim( $full_content ) ) ] );

        echo "data: " . wp_json_encode( [
            'type'           => 'complete',
            'refined_prompt' => trim( $full_content ),
        ] ) . "\n\n";
        ob_flush(); flush();
    }

    public function generate_element( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id        = absint( $request->get_param( 'post_id' ) );
        $element_id     = sanitize_text_field( $request->get_param( 'element_id' ) );
        $prompt         = sanitize_textarea_field( $request->get_param( 'prompt' ) );
        $element_context = $request->get_param( 'element_context' );

        if ( ! $post_id || empty( $element_id ) || empty( $prompt ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'data'    => [ 'code' => 'missing_params', 'message' => __( 'post_id, element_id, and prompt are required.', 'woo-elementor-ai' ) ],
            ], 400 );
        }

        $generator = new Page_Generator();
        $result = $generator->generate_element( $post_id, $element_id, $prompt, is_array( $element_context ) ? $element_context : [] );

        return new \WP_REST_Response( [ 'success' => $result['success'], 'data' => $result['data'] ?? $result ], $result['success'] ? 200 : 500 );
    }

    public function generate_element_stream( \WP_REST_Request $request ): void {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $post_id        = absint( $request->get_param( 'post_id' ) );
        $element_id     = sanitize_text_field( $request->get_param( 'element_id' ) );
        $prompt         = sanitize_textarea_field( $request->get_param( 'prompt' ) );
        $element_context = $request->get_param( 'element_context' );

        if ( ! $post_id || empty( $element_id ) || empty( $prompt ) ) {
            header( 'Content-Type: text/event-stream' );
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'post_id, element_id, and prompt are required.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $this->sse_generate_element( $post_id, $element_id, $prompt, is_array( $element_context ) ? $element_context : [] );
    }

    private function sse_generate_element( int $post_id, string $element_id, string $prompt, array $element_context ): void {
        $log = \WooElementorAI\Log_Service::get_instance();
        $log->log( 'api_endpoint', 'info', "sse_generate_element called: post_id={$post_id} element_id={$element_id}", [ 'post_id' => $post_id, 'element_id' => $element_id, 'prompt' => $prompt ] );

        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );

        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();

        if ( empty( $settings['api_key'] ) || empty( $settings['base_url'] ) ) {
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'AI API not configured.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $generator     = new Page_Generator();
        $system_prompt = $generator->build_element_system_prompt_public( $element_context );
        $user_prompt   = $prompt;

        $ai           = new AI_Service();
        $full_content = '';

        $ai->chat_stream_sse(
            [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user', 'content' => $user_prompt ],
            ],
            [
                'max_tokens' => 64000,
                'on_chunk' => function( string $delta_content, ?string $delta_reasoning ) use ( &$full_content ) {
                    if ( $delta_content !== '' ) {
                        $full_content .= $delta_content;
                    }
                    echo "data: " . wp_json_encode( [
                        'type'      => 'chunk',
                        'content'   => $delta_content,
                        'reasoning' => $delta_reasoning,
                    ] ) . "\n\n";
                    ob_flush(); flush();
                },
            ],
            $settings
        );

        if ( empty( $full_content ) ) {
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'AI returned empty content.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $elementor_data = new \WooElementorAI\Elementor_Data();
        $parsed = $elementor_data->validate_and_parse( $full_content, $post_id );

        if ( empty( $parsed ) ) {
            echo "data: " . wp_json_encode( [ 'type' => 'error', 'message' => 'AI returned invalid element data.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $new_element = $parsed[0];
        if ( empty( $new_element['id'] ) ) {
            $new_element['id'] = $elementor_data->generate_element_id();
        }

        if ( $settings_obj->is_image_configured() ) {
            $wrapped = [ &$new_element ];
            $image_service = new \WooElementorAI\Image_Service();
            $image_service->resolve_images_in_elements( $wrapped );
            unset( $wrapped );
        }

        $log->log( 'api_endpoint', 'success', "sse_generate_element completed: new_element_id={$new_element['id']}", [ 'post_id' => $post_id ] );

        echo "data: " . wp_json_encode( [
            'type'       => 'complete',
            'element_id' => $new_element['id'],
            'element'    => $new_element,
        ] ) . "\n\n";
        ob_flush(); flush();
    }

    public function chat( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id      = absint( $request->get_param( 'post_id' ) );
        $message      = sanitize_textarea_field( $request->get_param( 'message' ) );
        $context      = sanitize_text_field( $request->get_param( 'context' ) ?: 'page' );
        $target_id    = sanitize_text_field( $request->get_param( 'target_element_id' ) );

        if ( ! $post_id || empty( $message ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'data'    => [ 'code' => 'missing_params', 'message' => __( 'post_id and message are required.', 'woo-elementor-ai' ) ],
            ], 400 );
        }

        $chat_session = new Chat_Session();
        $chat_session->add_message( $post_id, 'user', $message );

        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();

        $history  = $chat_session->get_messages_for_api( $post_id, $settings['chat_max_context'] );
        $context_str = $chat_session->build_context_string( $post_id, $context === 'element' ? $target_id : null );

        $system_msg = $this->build_chat_system_prompt( $context, $context_str );
        array_unshift( $history, [ 'role' => 'system', 'content' => $system_msg ] );

        $ai     = new AI_Service();
        $result = $ai->chat( $history, [ 'max_tokens' => 64000 ] );

        if ( ! $result['success'] ) {
            return new \WP_REST_Response( [ 'success' => false, 'data' => $result ], 500 );
        }

        $content = $result['content'] ?? '';
        if ( empty( $content ) ) {
            $reasoning = $result['reasoning_content'] ?? '';
            $msg = __( 'AI returned empty content.', 'woo-elementor-ai' );
            if ( ! empty( $reasoning ) ) {
                $msg = __( 'AI used all tokens for reasoning. Try increasing max_tokens.', 'woo-elementor-ai' );
            }
            return new \WP_REST_Response( [ 'success' => false, 'data' => [ 'message' => $msg ] ], 500 );
        }

        $chat_session->add_message( $post_id, 'assistant', $content );

        $actions = $this->parse_actions_from_response( $content, $post_id );

        return new \WP_REST_Response( [
            'success' => true,
            'data'    => [
                'content' => $content,
                'actions' => $actions,
            ],
        ], 200 );
    }

    public function chat_stream( \WP_REST_Request $request ): void {
        $post_id   = absint( $request->get_param( 'post_id' ) );
        $message   = sanitize_textarea_field( $request->get_param( 'message' ) );
        $context   = sanitize_text_field( $request->get_param( 'context' ) ?: 'page' );
        $target_id = sanitize_text_field( $request->get_param( 'target_element_id' ) );
        $method    = sanitize_text_field( $request->get_param( 'copywriting_method' ) ?? '' );
        $language  = sanitize_text_field( $request->get_param( 'language' ) ?? 'id' );

        $log = \WooElementorAI\Log_Service::get_instance();
        $log->log( 'api_endpoint', 'info', "chat_stream called: post_id={$post_id} context={$context} method={$method}", [ 'post_id' => $post_id, 'context' => $context, 'method' => $method, 'language' => $language ] );

        $chat_session = new Chat_Session();
        if ( $message ) {
            $chat_session->add_message( $post_id, 'user', $message );
        }

        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();
        $history      = $chat_session->get_messages_for_api( $post_id, $settings['chat_max_context'] );
        $context_str  = $chat_session->build_context_string( $post_id, $context === 'element' ? $target_id : null );
        $system_msg   = $this->build_chat_system_prompt( $context, $context_str, $method, $language );
        array_unshift( $history, [ 'role' => 'system', 'content' => $system_msg ] );

        // SSE headers
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );

        if ( empty( $settings['api_key'] ) || empty( $settings['base_url'] ) ) {
            echo "data: " . wp_json_encode( [ 'error' => 'AI API not configured.' ] ) . "\n\n";
            ob_flush(); flush();
            return;
        }

        $ai = new AI_Service();
        $full_content = '';

        $ai->chat_stream_sse(
            $history,
            [
                'max_tokens' => 64000,
                'on_chunk' => function( string $delta_content, ?string $delta_reasoning ) use ( &$full_content ) {
                    if ( $delta_content !== '' ) {
                        $full_content .= $delta_content;
                    }
                    // Forward delta to client in same format as old chat_stream()
                    $delta_data = [];
                    if ( $delta_content !== '' ) {
                        $delta_data['content'] = $delta_content;
                    }
                    if ( $delta_reasoning !== null && $delta_reasoning !== '' ) {
                        $delta_data['reasoning'] = $delta_reasoning;
                    }
                    if ( ! empty( $delta_data ) ) {
                        echo "data: " . wp_json_encode( $delta_data ) . "\n\n";
                        ob_flush(); flush();
                    }
                },
            ],
            $settings
        );

        echo "data: [DONE]\n\n";
        ob_flush(); flush();

        // Save assistant response to chat history
        if ( ! empty( $full_content ) ) {
            $chat_session->add_message( $post_id, 'assistant', $full_content );
            $log->log( 'api_endpoint', 'success', "chat_stream completed: post_id={$post_id}", [
                'post_id' => $post_id,
                'content_length' => strlen( $full_content ),
            ] );
        }
    }

    public function chat_clear( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = absint( $request->get_param( 'post_id' ) );
        $chat_session = new Chat_Session();
        $chat_session->clear_history( $post_id );
        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function chat_history( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = absint( $request->get_param( 'post_id' ) );
        $chat_session = new Chat_Session();
        return new \WP_REST_Response( [ 'success' => true, 'data' => $chat_session->get_history( $post_id ) ], 200 );
    }

    public function refine( \WP_REST_Request $request ): \WP_REST_Response {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 60 );
        }

        $prompt  = sanitize_textarea_field( $request->get_param( 'prompt' ) );
        $context = sanitize_text_field( $request->get_param( 'context' ) ?: 'page' );

        if ( empty( $prompt ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'data'    => [ 'code' => 'missing_params', 'message' => __( 'Prompt is required.', 'woo-elementor-ai' ) ],
            ], 400 );
        }

        $generator = new Page_Generator();
        $result = $generator->refine_prompt( $prompt, $context );

        return new \WP_REST_Response( [ 'success' => $result['success'], 'data' => $result['data'] ?? $result ], $result['success'] ? 200 : 500 );
    }

    public function get_ai_config( \WP_REST_Request $request ): \WP_REST_Response {
        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();
        return new \WP_REST_Response( [
            'success' => true,
            'data'    => [
                'base_url'   => rtrim( $settings['base_url'], '/' ) . '/',
                'api_key'    => $settings['api_key'],
                'model'      => $settings['model'],
                'max_tokens' => (int) $settings['max_tokens'],
                'mode'       => $settings['ai_processing_mode'] ?? 'curl',
            ],
        ], 200 );
    }

    public function get_logs( \WP_REST_Request $request ): \WP_REST_Response {
        $log_service = \WooElementorAI\Log_Service::get_instance();
        $result = $log_service->query( [
            'channel'  => sanitize_text_field( $request->get_param( 'channel' ) ?? '' ),
            'level'    => sanitize_text_field( $request->get_param( 'level' ) ?? '' ),
            'search'   => sanitize_text_field( $request->get_param( 'search' ) ?? '' ),
            'post_id'  => absint( $request->get_param( 'post_id' ) ?? 0 ),
            'per_page' => absint( $request->get_param( 'per_page' ) ?? 50 ),
            'page'     => absint( $request->get_param( 'page' ) ?? 1 ),
        ] );
        return new \WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    public function clear_logs( \WP_REST_Request $request ): \WP_REST_Response {
        $log_service = \WooElementorAI\Log_Service::get_instance();
        $log_service->clear_all();
        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();
        $settings['api_key']          = ! empty( $settings['api_key'] ) ? str_repeat( '•', 8 ) : '';
        $settings['image_api_key']    = ! empty( $settings['image_api_key'] ) ? str_repeat( '•', 8 ) : '';
        $settings['unsplash_api_key'] = ! empty( $settings['unsplash_api_key'] ) ? str_repeat( '•', 8 ) : '';
        $settings['pexels_api_key']   = ! empty( $settings['pexels_api_key'] ) ? str_repeat( '•', 8 ) : '';
        return new \WP_REST_Response( [ 'success' => true, 'data' => $settings ], 200 );
    }

    public function test_connection( \WP_REST_Request $request ): \WP_REST_Response {
        $ai = new AI_Service();
        $result = $ai->test_connection();
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
    }

    public function export_template( \WP_REST_Request $request ): \WP_REST_Response {
        $id       = sanitize_text_field( $request->get_param( 'id' ) );
        $exporter = new Template_Exporter();
        $result   = $exporter->export_template_by_id( $id );

        if ( ! $result['success'] ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => $result['message'] ], 400 );
        }

        return new \WP_REST_Response( $result['zip_data'], 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ] );
    }

	private function build_chat_system_prompt( string $context, string $context_str, string $method = '', string $language = 'id' ): string {
		$lang_instruction = $this->get_language_instruction( $language );
		$method_instruction = $this->get_method_instruction( $method );

		return <<<PROMPT
You are a senior Elementor designer AI inside the page editor. You help users build, edit, and refine web pages with strong visual design quality. Every change you make should feel intentionally crafted — not auto-generated.

Current page context:
{$context_str}

{$lang_instruction}
{$method_instruction}

CRITICAL CONTENT RULES:
- Write REAL, specific, persuasive content. NEVER use "Lorem ipsum" or placeholder text.
- Use the user's language for all text content.

DESIGN AWARENESS — apply to every element you create or modify:
- Maintain visual consistency with the existing page. Match colors, spacing, and typography style already in use.
- Use strong visual hierarchy: large bold headings (36-56px), medium subheadings (20-24px), readable body (14-16px).
- Generous spacing is premium: use 60-100px section padding, 20-40px between elements. Avoid cramped layouts.
- Buttons should stand out: contrasting background, padding 14px 36px, border-radius for softness.
- Alternate section backgrounds for visual rhythm — avoid monotonous flat pages.
- Dark backgrounds require light text (#fff/#f0f0f0). Light backgrounds require dark text (#1a1a1a/#333).
- When adding images, pick alt text that describes the visual subject accurately.

INSTRUCTIONS:
- When the user asks to create or modify elements, respond with a JSON action block wrapped in ```elementor-actions ... ```
- Each action is a JSON object with: type, and type-specific fields
- Action types:
  1. {"type": "element_create", "parent_id": "container_id_or_null", "element": {Elementor element JSON}}
  2. {"type": "element_update", "element_id": "id", "settings": {changed settings}}
  3. {"type": "element_delete", "element_id": "id"}
  4. {"type": "element_replace", "element_id": "id", "element": {full new element JSON}}
- For element_create with parent_id null, element is added at page root level
- Include a human-readable explanation before the action block
- For image widgets: set "image": {"url": "", "alt": "1-3 English keywords"}
  Leave url as empty string "" — system auto-fills. Alt is a stock photo search query: max 3 words, English only.
  Example: "gold trophy", "modern office", "team meeting"
- Use Container layout (elType: "container") with flex_direction
- You can include multiple actions in one response
- If the user just asks a question (not requesting changes), answer normally without action blocks
PROMPT;
	}

    private function parse_actions_from_response( string $content, int $post_id ): array {
        $actions = [];

        if ( preg_match_all( '/```elementor-actions\s*([\s\S]*?)```/', $content, $matches ) ) {
            foreach ( $matches[1] as $match ) {
                $parsed = json_decode( trim( $match ), true );
                if ( json_last_error() === JSON_ERROR_NONE ) {
                    if ( isset( $parsed['type'] ) ) {
                        $actions[] = $parsed;
                    } elseif ( $this->is_indexed( $parsed ) ) {
                        $actions = array_merge( $actions, $parsed );
                    }
                }
            }
        }

        if ( ! empty( $actions ) ) {
            $elementor_data = new \WooElementorAI\Elementor_Data();
            $apply_result = $elementor_data->apply_element_changes( $post_id, $actions );
            if ( ! $apply_result['success'] ) {
                return [];
            }
        }

        return $actions;
    }

    private function is_indexed( $arr ): bool {
        if ( ! is_array( $arr ) || empty( $arr ) ) return false;
        return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
    }

    private function get_language_instruction( string $language ): string {
        $map = [
            'id'     => 'Write ALL text content (headings, paragraphs, buttons, labels) in Indonesian (Bahasa Indonesia).',
            'en'     => 'Write ALL text content in English.',
            'mixed'  => 'Write content in a mix of Indonesian and English. Use Indonesian for main copy, English for technical terms and CTAs.',
        ];
        return $map[ $language ] ?? $map['id'];
    }

    private function get_method_instruction( string $method ): string {
        $map = [
            'AIDA'   => 'Use the AIDA copywriting framework: Attention (stop scrolling with powerful headline) → Interest (build curiosity, explain the problem) → Desire (show benefits, results, proof) → Action (clear CTA to buy/contact).',
            'PAS'    => 'Use the PAS copywriting framework: Problem (identify the audience pain point) → Agitate (amplify the consequences of not solving it) → Solution (present the product as the answer).',
            'FAB'    => 'Use the FAB copywriting framework: Features (what the product does) → Advantages (how it compares to alternatives) → Benefits (what the user actually gains).',
            'BAB'    => 'Use the BAB copywriting framework: Before (describe the current painful situation) → After (paint the ideal outcome) → Bridge (show how the product gets them there).',
            '4Ps'    => 'Use the 4Ps copywriting framework: Promise (make a bold claim) → Picture (paint the result vividly) → Proof (testimonials, data, social proof) → Push (urgency-driven CTA).',
            'QUEST'  => 'Use the QUEST copywriting framework: Qualify (identify who this is for) → Understand (show empathy with their problem) → Educate (teach about the solution) → Stimulate (build desire) → Transition (CTA to become a customer).',
            'ACCA'   => 'Use the ACCA copywriting framework: Awareness (make them aware of the problem) → Comprehension (ensure they fully understand it) → Conviction (prove your solution works) → Action (clear CTA).',
            'IDCA'   => 'Use the IDCA copywriting framework: Interest (hook attention) → Desire (build wanting) → Conviction (add proof/data) → Action (CTA).',
            'PASTOR' => 'Use the PASTOR copywriting framework: Problem (identify pain) → Amplify (make it urgent) → Story/Solution (tell how it gets solved) → Transformation (show the change) → Offer (present the deal) → Response (ask for action).',
            'SSS'    => 'Use the SSS copywriting framework: Star (introduce a relatable character/customer) → Story (their journey and struggle) → Solution (your product as the hero).',
            'SLAP'   => 'Use the SLAP copywriting framework: Stop (interrupt scrolling with bold visual/headline) → Look (draw them into the message) → Act (engagement step) → Purchase (direct path to buy).',
        ];
        return $map[ $method ] ?? '';
    }

    private function build_method_language_suffix( string $method, string $language ): string {
        $suffix = "\n\n";

        if ( ! empty( $method ) ) {
            $suffix .= "COPYWRITING METHOD: " . $this->get_method_instruction( $method ) . "\n\n";
        }

        $suffix .= $this->get_language_instruction( $language );

        return $suffix;
    }
}
