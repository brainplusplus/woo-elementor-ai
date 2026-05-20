<?php
namespace WooElementorAI\Admin;

use WooElementorAI\Log_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logs_Page {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'wp_ajax_woo_elementor_ai_clear_logs', [ $this, 'ajax_clear_logs' ] );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			'woo-elementor-ai',
			__( 'Logs', 'woo-elementor-ai' ),
			__( 'Logs', 'woo-elementor-ai' ),
			'manage_options',
			'woo-elementor-ai-logs',
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include WOO_ELEMENTOR_AI_PLUGIN_DIR . 'templates/logs-page.php';
	}

	public function ajax_clear_logs(): void {
		check_ajax_referer( 'woo_elementor_ai_logs_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ] );
		}

		Log_Service::get_instance()->clear_all();
		wp_send_json_success( [ 'message' => 'Logs cleared.' ] );
	}
}
