# Woo Elementor AI Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a WordPress plugin that adds AI-powered page generation, per-element editing, and multi-turn chat to Elementor, using OpenAI-compatible APIs.

**Architecture:** Modular OOP PHP backend with vanilla JS frontend. Plugin hooks into Elementor via its official PHP hooks (`elementor/widgets/register`, `elementor/element/{stack}/{section}/*`, `elementor/editor/after_enqueue_scripts`) and JS APIs (`$e` command bus, `elementor.hooks`). All AI communication through WP REST API endpoints. Chat history in user meta.

**Tech Stack:** PHP 7.4+, WordPress 6.x, Elementor 3.6+, Vanilla JS (IIFE pattern), WP REST API, Server-Sent Events for streaming, OpenAI-compatible chat completions API, Unsplash/Pexels/OpenAI image generation.

**Design Doc:** `docs/plans/2026-05-12-woo-elementor-ai-design.md`

---

## Phase 1: Plugin Skeleton & Settings

### Task 1: Plugin Bootstrap & Core Orchestrator

**Files:**
- Create: `woo-elementor-ai.php`
- Create: `includes/class-plugin.php`
- Create: `uninstall.php`

**Step 1: Create main plugin file**

```php
<?php
/**
 * Plugin Name: Woo Elementor AI
 * Plugin URI:  https://github.com/user/woo-elementor-ai
 * Description: AI-powered page generation and editing for Elementor using OpenAI-compatible APIs.
 * Version:     1.0.0
 * Author:      Developer
 * Text Domain: woo-elementor-ai
 * Domain Path: /languages
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WOO_ELEMENTOR_AI_VERSION', '1.0.0' );
define( 'WOO_ELEMENTOR_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_ELEMENTOR_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_ELEMENTOR_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', function() {
    \WooElementorAI\Plugin::get_instance();
} );
```

**Step 2: Create core orchestrator**

```php
<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    private static $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies(): void {
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/class-settings.php';
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/class-ai-service.php';
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/class-elementor-data.php';
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/class-chat-session.php';
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/class-page-generator.php';
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/admin/class-admin-page.php';
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/editor/class-editor-integration.php';
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/editor/class-panel-injection.php';
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/editor/class-context-menu.php';
        require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/api/class-rest-controller.php';
    }

    private function init_hooks(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );

        // Init modules
        new \WooElementorAI\Settings();
        new \WooElementorAI\Admin\Admin_Page();
        new \WooElementorAI\Editor\Editor_Integration();
        new \WooElementorAI\API\REST_Controller();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'woo-elementor-ai',
            false,
            dirname( WOO_ELEMENTOR_AI_PLUGIN_BASENAME ) . '/languages'
        );
    }
}
```

**Step 3: Create uninstall.php**

```php
<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove settings
delete_option( 'woo_elementor_ai_settings' );

// Remove chat histories for all users
global $wpdb;
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( '_woo_elementor_ai_chat_' ) . '%'
    )
);
```

**Step 4: Verify** — activate plugin in WordPress, confirm no errors.

**Step 5: Commit** — `feat: plugin bootstrap with core orchestrator`

---

### Task 2: Settings Page

**Files:**
- Create: `includes/class-settings.php`

**Step 1: Create Settings class**

```php
<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {
    const OPTION_KEY = 'woo_elementor_ai_settings';

    private array $defaults = [
        'base_url'             => 'https://api.openai.com',
        'api_key'              => '',
        'model'                => 'gpt-4o',
        'max_tokens'           => 4096,
        'temperature'          => 0.7,
        'chat_max_context'     => 8000,
        // Image generation settings
        'image_source'         => 'none',         // 'none' | 'unsplash' | 'pexels' | 'openai_compatible'
        'unsplash_api_key'     => '',
        'pexels_api_key'       => '',
        'image_base_url'       => '',
        'image_api_key'        => '',
        'image_model'          => 'dall-e-3',
        'image_endpoint'       => '/v1/images/generations',
    ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function get_settings(): array {
        $saved = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( $saved, $this->defaults );
    }

    public function get( string $key, $default = null ) {
        $settings = $this->get_settings();
        return $settings[ $key ] ?? $default;
    }

    public function is_configured(): bool {
        $settings = $this->get_settings();
        return ! empty( $settings['base_url'] ) && ! empty( $settings['api_key'] ) && ! empty( $settings['model'] );
    }

    public function add_menu_page(): void {
        add_menu_page(
            __( 'Woo Elementor AI', 'woo-elementor-ai' ),
            __( 'Woo Elementor AI', 'woo-elementor-ai' ),
            'manage_options',
            'woo-elementor-ai',
            [ $this, 'render_settings_page' ],
            'dashicons-superhero',
            59
        );
    }

    public function register_settings(): void {
        register_setting( 'woo_elementor_ai_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( array $input ): array {
        $sanitized = [];
        $sanitized['base_url']    = esc_url_raw( rtrim( $input['base_url'] ?? '', '/' ) );
        $sanitized['api_key']     = sanitize_text_field( $input['api_key'] ?? '' );
        $sanitized['model']       = sanitize_text_field( $input['model'] ?? 'gpt-4o' );
        $sanitized['max_tokens']  = absint( $input['max_tokens'] ?? 4096 );
        $sanitized['temperature'] = floatval( $input['temperature'] ?? 0.7 );
        $sanitized['chat_max_context'] = absint( $input['chat_max_context'] ?? 8000 );
        // Image generation
        $sanitized['image_source']     = sanitize_text_field( $input['image_source'] ?? 'none' );
        $sanitized['unsplash_api_key'] = sanitize_text_field( $input['unsplash_api_key'] ?? '' );
        $sanitized['pexels_api_key']   = sanitize_text_field( $input['pexels_api_key'] ?? '' );
        $sanitized['image_base_url']   = esc_url_raw( rtrim( $input['image_base_url'] ?? '', '/' ) );
        $sanitized['image_api_key']    = sanitize_text_field( $input['image_api_key'] ?? '' );
        $sanitized['image_model']      = sanitize_text_field( $input['image_model'] ?? 'dall-e-3' );
        $sanitized['image_endpoint']   = sanitize_text_field( $input['image_endpoint'] ?? '/v1/images/generations' );
        return $sanitized;
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = $this->get_settings();
        include WOO_ELEMENTOR_AI_PLUGIN_DIR . 'templates/settings-page.php';
    }
}
```

**Step 2: Create settings page template**

Create: `templates/settings-page.php` — full HTML form with:

**Section 1: AI Chat Configuration**
- Base URL text input
- API Key password input with toggle visibility + Test Connection button
- Model text input with datalist suggestions (gpt-4o, gpt-4o-mini, gpt-4-turbo, gpt-3.5-turbo, claude-3-opus, claude-3-sonnet)

**Section 2: Image Generation**
- Image Source dropdown (None / Unsplash / Pexels / OpenAI Compatible)
- Conditional sections shown/hidden via JS based on dropdown:
  - **Unsplash**: API Key input + help link to https://unsplash.com/developers
  - **Pexels**: API Key input + help link to https://www.pexels.com/api/
  - **OpenAI Compatible**: Base URL, API Key, Model, Image Endpoint (default `/v1/images/generations`)

**Section 3: Generation Defaults**
- Max Tokens number input
- Temperature range slider (0.0 - 2.0, step 0.1)
- Chat Max Context number input
- Save Changes button

**Step 3: Verify** — settings page appears in admin sidebar, can save/load values.

**Step 4: Commit** — `feat: settings page with API configuration`

---

## Phase 2: AI Service & Core Backend

### Task 3: AI Service Client

**Files:**
- Create: `includes/class-ai-service.php`

**Step 1: Create AI_Service class**

```php
<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Service {
    public function chat( array $messages, array $options = [] ): array {
        $settings = \WooElementorAI\Plugin::get_instance()->get_settings(); // via Settings class

        $body = [
            'model'      => $options['model'] ?? $settings['model'],
            'messages'   => $messages,
            'max_tokens' => $options['max_tokens'] ?? $settings['max_tokens'],
            'temperature' => $options['temperature'] ?? $settings['temperature'],
        ];

        $response = wp_remote_post( rtrim( $settings['base_url'], '/' ) . '/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $settings['api_key'],
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'error'   => 'ai_connection_error',
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code ) {
            return [
                'success' => false,
                'error'   => 'ai_invalid_response',
                'message' => $body['error']['message'] ?? "HTTP {$code}",
            ];
        }

        $content = $body['choices'][0]['message']['content'] ?? '';

        return [
            'success' => true,
            'content' => $content,
        ];
    }

    public function chat_stream( array $messages, array $options = [] ): void {
        // SSE streaming implementation
        $settings = $this->get_settings();

        $body = [
            'model'      => $options['model'] ?? $settings['model'],
            'messages'   => $messages,
            'max_tokens' => $options['max_tokens'] ?? $settings['max_tokens'],
            'temperature' => $options['temperature'] ?? $settings['temperature'],
            'stream'     => true,
        ];

        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );

        // Use cURL for streaming
        $ch = curl_init( rtrim( $settings['base_url'], '/' ) . '/v1/chat/completions' );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['api_key'],
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_WRITEFUNCTION  => function( $ch, $data ) {
                $lines = explode( "\n", $data );
                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( strpos( $line, 'data: ' ) === 0 ) {
                        $json = substr( $line, 6 );
                        if ( $json === '[DONE]' ) {
                            echo "data: [DONE]\n\n";
                        } else {
                            $parsed = json_decode( $json, true );
                            $content = $parsed['choices'][0]['delta']['content'] ?? '';
                            if ( $content ) {
                                echo "data: " . wp_json_encode( [ 'content' => $content ] ) . "\n\n";
                            }
                        }
                        ob_flush();
                        flush();
                    }
                }
                return strlen( $data );
            },
        ] );
        curl_exec( $ch );
        curl_close( $ch );
        exit;
    }

    private function get_settings(): array {
        static $settings = null;
        if ( null === $settings ) {
            $s = new Settings();
            $settings = $s->get_settings();
        }
        return $settings;
    }
}
```

**Step 2: Verify** — test connection via settings Test Connection button.

**Step 3: Commit** — `feat: OpenAI-compatible API client with streaming`

---

### Task 3b: Image Generation Service

**Files:**
- Create: `includes/class-image-service.php`

**Step 1: Create Image_Service class**

```php
<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Image_Service {
    /**
     * Resolve image_search keywords to actual WordPress media attachment.
     * Returns ['url' => '...', 'id' => 123] or null on failure.
     */
    public function resolve_image( string $keywords ): ?array {
        $settings = ( new Settings() )->get_settings();
        $source   = $settings['image_source'] ?? 'none';

        if ( 'none' === $source || empty( $keywords ) ) {
            return null;
        }

        $image_url = null;

        switch ( $source ) {
            case 'unsplash':
                $image_url = $this->search_unsplash( $keywords, $settings['unsplash_api_key'] ?? '' );
                break;
            case 'pexels':
                $image_url = $this->search_pexels( $keywords, $settings['pexels_api_key'] ?? '' );
                break;
            case 'openai_compatible':
                $image_url = $this->generate_openai_compatible( $keywords, $settings );
                break;
        }

        if ( ! $image_url ) {
            return null;
        }

        return $this->download_to_media( $image_url, $keywords );
    }

    private function search_unsplash( string $keywords, string $api_key ): ?string {
        $response = wp_remote_get( add_query_arg( [
            'query'    => $keywords,
            'per_page' => 1,
            'orientation' => 'landscape',
        ], 'https://api.unsplash.com/search/photos' ), [
            'headers' => [ 'Authorization' => 'Client-ID ' . $api_key ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) return null;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['results'][0]['urls']['regular'] ?? null;
    }

    private function search_pexels( string $keywords, string $api_key ): ?string {
        $response = wp_remote_get( add_query_arg( [
            'query'    => $keywords,
            'per_page' => 1,
            'orientation' => 'landscape',
        ], 'https://api.pexels.com/v1/search' ), [
            'headers' => [ 'Authorization' => $api_key ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) return null;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['photos'][0]['src']['large'] ?? null;
    }

    private function generate_openai_compatible( string $keywords, array $settings ): ?string {
        $url = rtrim( $settings['image_base_url'], '/' ) . $settings['image_endpoint'];
        $response = wp_remote_post( $url, [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $settings['image_api_key'],
            ],
            'body' => wp_json_encode( [
                'model'  => $settings['image_model'],
                'prompt' => $keywords . ', high quality, professional web design',
                'n'      => 1,
                'size'   => '1024x1024',
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return null;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['data'][0]['url'] ?? null;
    }

    private function download_to_media( string $url, string $description ): ?array {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) return null;

        $file_array = [
            'name'     => sanitize_file_name( 'ai-' . wp_generate_password( 8, false ) . '.jpg' ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0, $description );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return null;
        }

        return [
            'id'  => $attachment_id,
            'url' => wp_get_attachment_url( $attachment_id ),
        ];
    }

    /**
     * Walk Elementor elements tree, resolve all image_search fields.
     */
    public function resolve_images_in_elements( array &$elements ): void {
        foreach ( $elements as &$element ) {
            // Check widget for image_search
            if ( 'widget' === $element['elType'] && ! empty( $element['settings']['image_search'] ) ) {
                $result = $this->resolve_image( $element['settings']['image_search'] );
                if ( $result ) {
                    $element['settings']['image'] = [
                        'url' => $result['url'],
                        'id'  => (string) $result['id'],
                    ];
                }
                unset( $element['settings']['image_search'] );
            }
            // Check container backgrounds
            if ( ! empty( $element['settings']['background_image_search'] ) ) {
                $result = $this->resolve_image( $element['settings']['background_image_search'] );
                if ( $result ) {
                    $element['settings']['background_image'] = [
                        'url' => $result['url'],
                        'id'  => (string) $result['id'],
                    ];
                }
                unset( $element['settings']['background_image_search'] );
            }
            // Recurse into children
            if ( ! empty( $element['elements'] ) ) {
                $this->resolve_images_in_elements( $element['elements'] );
            }
        }
    }
}
```

**Step 2: Verify** — test with Pexels API key, confirm image download to media library.

**Step 3: Commit** — `feat: image generation service with Unsplash, Pexels, and OpenAI compatible support`

---

### Task 4: Elementor Data Helper

**Files:**
- Create: `includes/class-elementor-data.php`

**Step 1: Create Elementor_Data class**

Key methods:
- `validate_and_parse( string $ai_response ): array` — hybrid validation pipeline
- `generate_element_id(): string` — random 8-char hex ID
- `validate_element( array $element ): bool` — check required fields
- `html_to_elements( string $html ): array` — fallback HTML→text-editor widgets
- `extract_json_from_markdown( string $text ): ?array` — extract JSON from ```json blocks
- `build_elementor_page( array $content_elements, string $post_type = 'page' ): array` — wrap in page structure with all required metas
- `get_condensed_page_data( int $post_id ): array` — element types + IDs only (for chat context)
- `apply_element_changes( int $post_id, array $actions ): array` — walk tree, apply create/update/delete

**Step 2: Commit** — `feat: Elementor JSON validation, parsing, and manipulation helper`

---

### Task 5: Chat Session Manager

**Files:**
- Create: `includes/class-chat-session.php`

**Step 1: Create Chat_Session class**

Key methods:
- `get_history( int $post_id ): array` — from user meta `_woo_elementor_ai_chat_{post_id}`
- `add_message( int $post_id, string $role, string $content ): void`
- `clear_history( int $post_id ): void`
- `get_messages_for_api( int $post_id, int $max_tokens = 8000 ): array` — truncated history ready for API
- `build_context( int $post_id, ?string $target_element_id = null ): string` — build condensed page context string

**Step 2: Commit** — `feat: multi-turn chat session manager with token management`

---

### Task 6: Page Generator

**Files:**
- Create: `includes/class-page-generator.php`

**Step 1: Create Page_Generator class**

Key methods:
- `generate( string $title, string $prompt, string $post_type = 'page' ): array|WP_Error` — full flow: build prompt → call AI → parse → create post → return
- `build_page_system_prompt(): string` — the Elementor JSON rules system prompt
- `refine_prompt( string $raw_prompt, string $context = 'page' ): string` — expand user's brief description
- `create_elementor_post( string $title, array $elements, string $post_type ): int` — wp_insert_post + set all Elementor metas

System prompt for page generation (include in method):
- Output ONLY JSON array of Elementor elements
- Use container elType with flex_direction
- Support Section/Column layout too
- Each element: id (8-char hex), elType, isInner, settings, elements
- Available widget types: heading, text-editor, button, image, spacer, divider, html, icon, icon-box, icon-list, image-box, image-carousel, image-gallery, social-icons, google-maps, video, audio, accordion, tabs, toggle, counter, progress, testimonial, star-rating, alert
- Generate REAL content matching user description
- Include styling: colors, typography, spacing, backgrounds
- Use responsive suffixes (_tablet, _mobile) for critical layouts

**Step 2: Commit** — `feat: page generator with system prompt and Elementor post creation`

---

## Phase 3: REST API Endpoints

### Task 7: REST Controller & Endpoints

**Files:**
- Create: `includes/api/class-rest-controller.php`
- Create: `includes/api/class-generate-endpoint.php`
- Create: `includes/api/class-chat-endpoint.php`
- Create: `includes/api/class-refine-endpoint.php`

**Step 1: Create REST controller**

```php
<?php
namespace WooElementorAI\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class REST_Controller {
    const NAMESPACE = 'woo-elementor-ai/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        // Generate
        register_rest_route( self::NAMESPACE, '/generate', [
            'methods'             => 'POST',
            'callback'            => [ new Generate_Endpoint(), 'handle' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/generate/element', [
            'methods'             => 'POST',
            'callback'            => [ new Generate_Endpoint(), 'handle_element' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        // Chat
        register_rest_route( self::NAMESPACE, '/chat', [
            'methods'             => 'POST',
            'callback'            => [ new Chat_Endpoint(), 'handle' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/chat/stream', [
            'methods'             => 'GET',
            'callback'            => [ new Chat_Endpoint(), 'handle_stream' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/chat/clear', [
            'methods'             => 'POST',
            'callback'            => [ new Chat_Endpoint(), 'clear' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/chat/history', [
            'methods'             => 'GET',
            'callback'            => [ new Chat_Endpoint(), 'get_history' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        // Refine
        register_rest_route( self::NAMESPACE, '/refine', [
            'methods'             => 'POST',
            'callback'            => [ new Refine_Endpoint(), 'handle' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        // Settings
        register_rest_route( self::NAMESPACE, '/settings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_settings' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_settings' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/settings/test', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'test_connection' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
    }
    // ... settings methods
}
```

**Step 2: Create each endpoint class** — Generate_Endpoint, Chat_Endpoint, Refine_Endpoint with full request parsing, nonce verification, sanitization, and response formatting per design doc section 5.2.

**Step 3: Verify** — activate plugin, test `GET /wp-json/woo-elementor-ai/v1/settings` returns expected response.

**Step 4: Commit** — `feat: REST API endpoints for generate, chat, refine, and settings`

---

## Phase 4: Admin — "New Page with AI" Button & Modal

### Task 8: Admin Page Button + Modal

**Files:**
- Create: `includes/admin/class-admin-page.php`
- Create: `templates/admin-new-page-modal.php`
- Create: `assets/js/admin/new-page-modal.js`
- Create: `assets/js/admin/refine-prompt.js`
- Create: `assets/css/admin.css`

**Step 1: Create Admin_Page class**

Hooks into:
- `admin_enqueue_scripts` — enqueue modal JS/CSS on edit.php pages (pages/posts list)
- `manage_posts_columns` / `manage_pages_columns` — not needed, use admin_footer approach
- `admin_footer` — inject modal HTML template

Add "New Page with AI" / "New Post with AI" button next to "Add New" via JS injection targeting `.page-title-action` or `.wrap .page-title-action:first`.

**Step 2: Create modal template** — Title input, Description textarea, Refine button (✨), Cancel, Generate Page. Progress state with spinner.

**Step 3: Create JS** — modal open/close, form submission to `/generate` endpoint, refine button calls `/refine` endpoint and updates textarea, on success redirect to Elementor editor URL.

**Step 4: Create admin CSS** — modal overlay, form styling, spinner, responsive.

**Step 5: Verify** — go to Pages list, see "New Page with AI" button, click it, modal opens.

**Step 6: Commit** — `feat: "New with AI" button and modal on Pages/Posts list`

---

## Phase 5: Elementor Editor Integration

### Task 9: Editor Assets & Chat Panel HTML

**Files:**
- Create: `includes/editor/class-editor-integration.php`
- Create: `templates/editor-chat-panel.php`
- Create: `assets/css/editor.css`

**Step 1: Create Editor_Integration class**

```php
public function __construct() {
    add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_styles' ] );
    add_action( 'elementor/editor/footer', [ $this, 'inject_chat_panel' ] );
}
```

Enqueue all editor JS files with dependencies `['jquery', 'elementor-editor']`.

Pass config via `wp_localize_script`:
```php
wp_localize_script( 'woo-elementor-ai-editor-bridge', 'wooElementorAI', [
    'apiUrl'    => rest_url( 'woo-elementor-ai/v1' ),
    'nonce'     => wp_create_nonce( 'wp_rest' ),
    'postId'    => get_the_ID(),
    'isConfigured' => $settings->is_configured(),
] );
```

**Step 2: Create chat panel HTML template** — sidebar panel with: context selector dropdown (Page/Section/Widget), messages container, quick action buttons, input area with send button, clear chat button.

**Step 3: Create editor CSS** — chat panel positioning (right sidebar, 350px wide), messages styling, input area, context selector, responsive.

**Step 4: Commit** — `feat: editor integration with chat panel template and assets`

---

### Task 10: Elementor Bridge JS

**Files:**
- Create: `assets/js/editor/elementor-bridge.js`

**Step 1: Create bridge module**

```javascript
(function($) {
    'use strict';

    window.WooElementorAIBridge = {
        /**
         * Get current page Elementor data (condensed)
         */
        getPageData: function() {
            var elements = elementor.elements.toJSON();
            return this.condenseElements(elements);
        },

        /**
         * Get specific element data
         */
        getElementData: function(elementId) {
            var container = elementor.getContainer(elementId);
            if (!container) return null;
            return container.settings.toJSON();
        },

        /**
         * Condense elements to types + IDs only
         */
        condenseElements: function(elements) {
            return elements.map(function(el) {
                var condensed = {
                    id: el.id,
                    elType: el.elType
                };
                if (el.widgetType) condensed.widgetType = el.widgetType;
                if (el.elements && el.elements.length) {
                    condensed.elements = this.condenseElements(el.elements);
                }
                return condensed;
            }.bind(this));
        },

        /**
         * Apply element actions from AI response
         */
        applyActions: function(actions) {
            actions.forEach(function(action) {
                switch (action.type) {
                    case 'element_create':
                        $e.run('document/elements/create', {
                            container: elementor.getContainer(action.parent_id),
                            model: action.element
                        });
                        break;
                    case 'element_update':
                        $e.run('document/elements/settings', {
                            container: elementor.getContainer(action.element_id),
                            settings: action.settings
                        });
                        break;
                    case 'element_delete':
                        $e.run('document/elements/delete', {
                            container: elementor.getContainer(action.element_id)
                        });
                        break;
                }
            });
        },

        /**
         * Get currently selected element
         */
        getSelectedElement: function() {
            var preview = elementor.$previewContents;
            var selected = preview.find('.elementor-element.elementor-element-editable');
            if (!selected.length) return null;
            return selected.attr('data-id');
        }
    };
})(jQuery);
```

**Step 2: Commit** — `feat: Elementor bridge for reading/writing editor data via $e API`

---

### Task 11: Chat Panel JS

**Files:**
- Create: `assets/js/editor/ai-chat-panel.js`

**Step 1: Create chat panel module**

Key behaviors:
- Toggle chat panel open/close (button in editor top bar)
- Context selector changes scope (page/section/widget)
- Send message → POST `/chat` with message + context + target element ID
- Receive response → display in messages area
- Parse `actions` from response → call `WooElementorAIBridge.applyActions()`
- Quick action buttons send preset messages
- Clear chat → POST `/chat/clear`
- Auto-scroll to latest message
- Streaming support: EventSource connection to `/chat/stream`

**Step 2: Commit** — `feat: AI chat panel with multi-turn conversation and Elementor integration`

---

### Task 12: Per-Element AI Controls (Panel Injection)

**Files:**
- Create: `includes/editor/class-panel-injection.php`
- Create: `assets/js/editor/ai-element-controls.js`

**Step 1: Create Panel_Injection class**

```php
// Hook into EVERY widget to add AI Assistant section
add_action( 'elementor/element/common/_section_style/after_section_end', [ $this, 'add_ai_controls' ], 10, 2 );
```

Add controls to every element:
- `ai_prompt` — Textarea control
- `ai_refine` — Button control "Refine with AI"
- `ai_generate` — Button control "Generate"

**Step 2: Create ai-element-controls.js**

Listen for panel open events, bind click handlers on AI controls:
- Refine: read prompt textarea → POST `/refine` → update textarea
- Generate: read prompt + current element data → POST `/generate/element` → apply via bridge

**Step 3: Commit** — `feat: per-element AI assistant controls in widget settings panel`

---

### Task 13: Right-Click Context Menu

**Files:**
- Create: `includes/editor/class-context-menu.php`
- Create: `assets/js/editor/ai-context-menu.js`

**Step 1: Create Context_Menu class**

```php
add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue' ] );
add_action( 'elementor/editor/footer', [ $this, 'inject_menu_items' ] );
```

**Step 2: Create ai-context-menu.js**

Hook into Elementor's context menu system:
```javascript
elementor.hooks.addFilter('elements/context-menu/groups', function(groups, element) {
    groups.push({
        name: 'woo-ai',
        actions: [
            { name: 'edit-ai', title: 'Edit with AI', icon: 'eicon-code' },
            { name: 'generate-variations', title: 'Generate Variations', icon: 'eicon-code' },
            { name: 'improve-layout', title: 'Improve Layout', icon: 'eicon-code' }
        ]
    });
    return groups;
});
```

Each action opens appropriate UI:
- Edit with AI → opens prompt modal for that element
- Generate Variations → sends current element to AI for re-generation
- Improve Layout → one-click, no prompt needed

**Step 3: Commit** — `feat: right-click context menu with AI edit, variations, and improve layout`

---

## Phase 6: Integration & Polish

### Task 14: Streaming Chat Implementation

**Files:**
- Modify: `assets/js/editor/ai-chat-panel.js`

**Step 1: Add SSE streaming to chat**

When user sends message, establish EventSource connection to `/chat/stream`. Display tokens as they arrive. On `[DONE]`, parse full response and apply actions.

**Step 2: Commit** — `feat: SSE streaming for real-time chat responses`

---

### Task 15: Error Handling & Edge Cases

**Files:**
- Modify: all JS and PHP files

**Step 1: Add comprehensive error handling**

- API not configured → show notice in editor/admin
- API connection failed → show user-friendly error in chat/modal
- Invalid JSON from AI → fallback pipeline
- Rate limiting → show retry message
- Elementor not active → deactivate plugin with notice
- Insufficient permissions → hide AI buttons
- Network timeout → retry with exponential backoff

**Step 2: Add Elementor dependency check in bootstrap**

```php
add_action( 'admin_init', function() {
    if ( ! did_action( 'elementor/loaded' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="error"><p>';
            esc_html_e( 'Woo Elementor AI requires Elementor to be installed and active.', 'woo-elementor-ai' );
            echo '</p></div>';
        } );
    }
} );
```

**Step 3: Commit** — `feat: error handling, dependency checks, and edge cases`

---

### Task 16: Testing & Final Polish

**Files:**
- All files

**Step 1: Manual testing checklist**

- [ ] Activate plugin with Elementor active → no errors
- [ ] Activate plugin without Elementor → shows notice
- [ ] Settings page: save/load all fields
- [ ] Settings page: Test Connection button works
- [ ] Pages list: "New Page with AI" button appears
- [ ] Posts list: "New Post with AI" button appears
- [ ] Modal: opens, refine works, generate creates page, redirects to editor
- [ ] Editor: AI Chat button in top bar
- [ ] Editor: Chat panel opens, sends messages, receives responses
- [ ] Editor: Chat updates canvas (creates/edits elements)
- [ ] Editor: Chat context selector works (page/section/widget)
- [ ] Editor: Per-element AI controls appear in widget settings
- [ ] Editor: Per-element generate updates that element
- [ ] Editor: Right-click menu shows AI options
- [ ] Editor: "Generate Variations" works
- [ ] Editor: "Improve Layout" works
- [ ] Uninstall removes all data

**Step 2: Fix any issues found**

**Step 3: Final commit** — `release: Woo Elementor AI v1.0.0`

---

## Task Dependency Graph

```
Task 1 (Bootstrap) ─────────────────────────────────────────┐
Task 2 (Settings + Image) ─ depends on Task 1 ──────────────┤
Task 3 (AI Service) ─ depends on Task 2 ────────────────────┤
Task 3b (Image Service) ─ depends on Task 2 ────────────────┤
Task 4 (Elementor Data) ─ depends on Task 1 ────────────────┤
Task 5 (Chat Session) ─ depends on Task 1 ──────────────────┤
Task 6 (Page Generator) ─ depends on Task 3, 3b, 4 ────────┤
Task 7 (REST API) ─ depends on Task 3, 3b, 4, 5, 6 ────────┤
Task 8 (Admin Modal) ─ depends on Task 7 ───────────────────┤
Task 9 (Editor Integration) ─ depends on Task 1 ────────────┤
Task 10 (Elementor Bridge JS) ─ depends on Task 9 ──────────┤
Task 11 (Chat Panel JS) ─ depends on Task 9, 10 ────────────┤
Task 12 (Panel Injection) ─ depends on Task 9, 10 ──────────┤
Task 13 (Context Menu) ─ depends on Task 9, 10 ─────────────┤
Task 14 (Streaming) ─ depends on Task 11 ────────────────────┤
Task 15 (Error Handling) ─ depends on all above ────────────┤
Task 16 (Testing) ─ depends on all above ────────────────────┘
```

**Parallel execution opportunities:**
- Tasks 4, 5 can run in parallel (both depend only on Task 1)
- Tasks 3, 3b can run in parallel (both depend on Task 2)
- Tasks 10, 11, 12, 13 can be partially parallelized (share Task 9 dependency)
- Tasks 8, 9 can run in parallel (different surfaces)
