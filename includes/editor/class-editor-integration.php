<?php
namespace WooElementorAI\Editor;

use WooElementorAI\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Editor_Integration {

    public function __construct() {
        add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_styles' ] );
        add_action( 'elementor/editor/footer', [ $this, 'inject_chat_panel' ] );
    }

    public function enqueue_scripts(): void {
        $url   = WOO_ELEMENTOR_AI_PLUGIN_URL . 'assets/js/editor/';
        $dir   = WOO_ELEMENTOR_AI_PLUGIN_DIR . 'assets/js/editor/';
        $deps  = [ 'jquery', 'elementor-editor' ];

        wp_enqueue_script( 'woo-ai-elementor-bridge', $url . 'elementor-bridge.js', $deps, (string) filemtime( $dir . 'elementor-bridge.js' ), true );
        wp_enqueue_script( 'woo-ai-chat-panel', $url . 'ai-chat-panel.js', array_merge( $deps, [ 'woo-ai-elementor-bridge' ] ), (string) filemtime( $dir . 'ai-chat-panel.js' ), true );
        wp_enqueue_script( 'woo-ai-element-controls', $url . 'ai-element-controls.js', array_merge( $deps, [ 'woo-ai-elementor-bridge' ] ), (string) filemtime( $dir . 'ai-element-controls.js' ), true );
        wp_enqueue_script( 'woo-ai-context-menu', $url . 'ai-context-menu.js', array_merge( $deps, [ 'woo-ai-elementor-bridge' ] ), (string) filemtime( $dir . 'ai-context-menu.js' ), true );

        $settings = new Settings();
        wp_localize_script( 'woo-ai-elementor-bridge', 'wooElementorAI', [
            'apiUrl'       => rest_url( 'woo-elementor-ai/v1' ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'postId'       => get_the_ID(),
            'isConfigured' => $settings->is_configured(),
            'aiMode'       => $settings->get( 'ai_processing_mode', 'curl' ),
        ] );
    }

    public function enqueue_styles(): void {
        wp_enqueue_style(
            'woo-elementor-ai-editor',
            WOO_ELEMENTOR_AI_PLUGIN_URL . 'assets/css/editor.css',
            [],
            (string) filemtime( WOO_ELEMENTOR_AI_PLUGIN_DIR . 'assets/css/editor.css' )
        );
    }

    public function inject_chat_panel(): void {
        if ( ! get_the_ID() ) return;
        include WOO_ELEMENTOR_AI_PLUGIN_DIR . 'templates/editor-chat-panel.php';
    }
}
