<?php
namespace WooElementorAI\Admin;

use WooElementorAI\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Page {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_footer', [ $this, 'inject_modal_template' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( 'edit.php' !== $hook ) return;

        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, [ 'page', 'post' ], true ) ) return;

        wp_enqueue_style(
            'woo-elementor-ai-admin',
            WOO_ELEMENTOR_AI_PLUGIN_URL . 'assets/css/admin.css',
            [],
            (string) filemtime( WOO_ELEMENTOR_AI_PLUGIN_DIR . 'assets/css/admin.css' )
        );

        wp_enqueue_script(
            'woo-elementor-ai-admin-modal',
            WOO_ELEMENTOR_AI_PLUGIN_URL . 'assets/js/admin/new-page-modal.js',
            [ 'jquery' ],
            (string) filemtime( WOO_ELEMENTOR_AI_PLUGIN_DIR . 'assets/js/admin/new-page-modal.js' ),
            true
        );

        wp_localize_script( 'woo-elementor-ai-admin-modal', 'wooAiAdmin', [
            'apiUrl'    => rest_url( 'woo-elementor-ai/v1' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'postType'  => $screen->post_type,
            'isConfigured' => ( new Settings() )->is_configured(),
            'aiMode'    => ( new Settings() )->get( 'ai_processing_mode', 'curl' ),
            'i18n'      => [
                'newWithAi'   => $screen->post_type === 'page'
                    ? __( 'New Page with AI', 'woo-elementor-ai' )
                    : __( 'New Post with AI', 'woo-elementor-ai' ),
                'generate'    => __( 'Generate', 'woo-elementor-ai' ),
                'cancel'      => __( 'Cancel', 'woo-elementor-ai' ),
                'refine'      => __( 'Refine', 'woo-elementor-ai' ),
                'title'       => $screen->post_type === 'page'
                    ? __( 'Generate Page with AI', 'woo-elementor-ai' )
                    : __( 'Generate Post with AI', 'woo-elementor-ai' ),
                'titleLabel'  => $screen->post_type === 'page'
                    ? __( 'Page Title', 'woo-elementor-ai' )
                    : __( 'Post Title', 'woo-elementor-ai' ),
                'descLabel'   => __( 'Describe your page:', 'woo-elementor-ai' ),
                'generating'  => __( 'Generating...', 'woo-elementor-ai' ),
                'refining'    => __( 'Refining prompt...', 'woo-elementor-ai' ),
                'notConfigured' => __( 'AI is not configured. Go to Woo Elementor AI settings.', 'woo-elementor-ai' ),
            ],
        ] );
    }

    public function inject_modal_template(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'edit' !== $screen->base ) return;
        if ( ! in_array( $screen->post_type, [ 'page', 'post' ], true ) ) return;

        include WOO_ELEMENTOR_AI_PLUGIN_DIR . 'templates/admin-new-page-modal.php';
    }
}
