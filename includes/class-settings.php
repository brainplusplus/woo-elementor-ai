<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {
    const OPTION_KEY = 'woo_elementor_ai_settings';

    private License $license;

    private array $defaults = [
        'base_url'             => 'https://api.openai.com',
        'api_key'              => '',
        'model'                => 'gpt-4o',
        'max_tokens'           => 64000,
        'temperature'          => 0.7,
        'chat_max_context'     => 8000,
        'image_source'         => 'none',
        'unsplash_api_key'     => '',
        'pexels_api_key'       => '',
        'image_base_url'       => '',
        'image_api_key'        => '',
        'image_model'          => 'dall-e-3',
        'image_endpoint'       => '/v1/images/generations',
        'log_purge_limit'      => 1000,
        'ai_processing_mode'   => 'curl',
    ];

    public function __construct() {
        $this->license = new License();
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_woo_elementor_ai_activate_license', [ $this, 'ajax_activate_license' ] );
        add_action( 'wp_ajax_woo_elementor_ai_deactivate_license', [ $this, 'ajax_deactivate_license' ] );
    }

    public function get_license(): License {
        return $this->license;
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
        $s = $this->get_settings();
        return ! empty( $s['base_url'] ) && ! empty( $s['api_key'] ) && ! empty( $s['model'] );
    }

    public function is_image_configured(): bool {
        $s      = $this->get_settings();
        $source = $s['image_source'] ?? 'none';

        if ( 'none' === $source ) {
            return false;
        }
        if ( 'unsplash' === $source ) {
            return ! empty( $s['unsplash_api_key'] );
        }
        if ( 'pexels' === $source ) {
            return ! empty( $s['pexels_api_key'] );
        }
        if ( 'openai_compatible' === $source ) {
            return ! empty( $s['image_base_url'] ) && ! empty( $s['image_api_key'] ) && ! empty( $s['image_model'] );
        }
        return false;
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
        $sanitized['base_url']         = esc_url_raw( rtrim( $input['base_url'] ?? '', '/' ) );
        $sanitized['api_key']          = sanitize_text_field( $input['api_key'] ?? '' );
        $sanitized['model']            = sanitize_text_field( $input['model'] ?? 'gpt-4o' );
        $sanitized['max_tokens']       = absint( $input['max_tokens'] ?? 64000 );
        $sanitized['temperature']      = round( floatval( $input['temperature'] ?? 0.7 ), 1 );
        $sanitized['chat_max_context'] = absint( $input['chat_max_context'] ?? 8000 );

        $valid_sources              = [ 'none', 'unsplash', 'pexels', 'openai_compatible' ];
        $sanitized['image_source']  = in_array( $input['image_source'] ?? 'none', $valid_sources, true )
            ? $input['image_source'] : 'none';
        $sanitized['unsplash_api_key'] = sanitize_text_field( $input['unsplash_api_key'] ?? '' );
        $sanitized['pexels_api_key']   = sanitize_text_field( $input['pexels_api_key'] ?? '' );
        $sanitized['image_base_url']   = esc_url_raw( rtrim( $input['image_base_url'] ?? '', '/' ) );
        $sanitized['image_api_key']    = sanitize_text_field( $input['image_api_key'] ?? '' );
        $sanitized['image_model']      = sanitize_text_field( $input['image_model'] ?? 'dall-e-3' );
        $sanitized['image_endpoint']   = sanitize_text_field( $input['image_endpoint'] ?? '/v1/images/generations' );

        $sanitized['log_purge_limit'] = absint( $input['log_purge_limit'] ?? 1000 );
        if ( $sanitized['log_purge_limit'] < 100 ) $sanitized['log_purge_limit'] = 100;

        $valid_modes = [ 'curl', 'exec_curl', 'frontend' ];
        $sanitized['ai_processing_mode'] = in_array( $input['ai_processing_mode'] ?? 'curl', $valid_modes, true )
            ? $input['ai_processing_mode'] : 'curl';

        if ( $sanitized['temperature'] < 0 ) $sanitized['temperature'] = 0.0;
        if ( $sanitized['temperature'] > 2.0 ) $sanitized['temperature'] = 2.0;

        return $sanitized;
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = $this->get_settings();
        include WOO_ELEMENTOR_AI_PLUGIN_DIR . 'templates/settings-page.php';
    }

    public function ajax_activate_license(): void {
        check_ajax_referer( 'woo_elementor_ai_license_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $license_key = sanitize_text_field( $_POST['license_key'] ?? '' );
        $result      = $this->license->activate_license( $license_key );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result );
    }

    public function ajax_deactivate_license(): void {
        check_ajax_referer( 'woo_elementor_ai_license_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $this->license->deactivate_license();
        wp_send_json_success( [ 'message' => __( 'License deactivated.', 'woo-elementor-ai' ) ] );
    }
}
