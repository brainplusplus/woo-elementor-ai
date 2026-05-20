<?php
namespace WooElementorAI\Editor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Context_Menu {

    public function __construct() {
        add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue(): void {
        wp_enqueue_script(
            'woo-ai-context-menu',
            WOO_ELEMENTOR_AI_PLUGIN_URL . 'assets/js/editor/ai-context-menu.js',
            [ 'jquery', 'elementor-editor', 'woo-ai-elementor-bridge' ],
            (string) filemtime( WOO_ELEMENTOR_AI_PLUGIN_DIR . 'assets/js/editor/ai-context-menu.js' ),
            true
        );
    }
}
