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
        $this->init_modules();
    }

    private function load_dependencies(): void {
        $base = WOO_ELEMENTOR_AI_PLUGIN_DIR . 'includes/';
        require_once $base . 'class-license.php';
        require_once $base . 'class-settings.php';
        require_once $base . 'class-ai-service.php';
        require_once $base . 'class-image-service.php';
        require_once $base . 'class-elementor-data.php';
        require_once $base . 'class-chat-session.php';
        require_once $base . 'class-page-generator.php';
        require_once $base . 'class-template-library.php';
        require_once $base . 'class-template-exporter.php';
        require_once $base . 'class-log-service.php';
        require_once $base . 'admin/class-admin-page.php';
        require_once $base . 'admin/class-templates-page.php';
        require_once $base . 'admin/class-logs-page.php';
        require_once $base . 'editor/class-editor-integration.php';
        require_once $base . 'editor/class-panel-injection.php';
        require_once $base . 'editor/class-context-menu.php';
        require_once $base . 'api/class-rest-controller.php';
    }

    private function init_modules(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        $settings = new Settings();
        $license  = $settings->get_license();

        if ( $license->is_licensed() ) {
            new Admin\Admin_Page();
            new Admin\Templates_Page();
            new Admin\Logs_Page();
            new Editor\Editor_Integration();
            new API\REST_Controller();
        }
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'woo-elementor-ai',
            false,
            dirname( WOO_ELEMENTOR_AI_PLUGIN_BASENAME ) . '/languages'
        );
    }
}
