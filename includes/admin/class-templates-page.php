<?php
namespace WooElementorAI\Admin;

use WooElementorAI\Template_Library;
use WooElementorAI\Template_Exporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Templates_Page {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'handle_export' ] );
		add_action( 'wp_ajax_woo_elementor_ai_save_template', [ $this, 'ajax_save_template' ] );
		add_action( 'wp_ajax_woo_elementor_ai_delete_template', [ $this, 'ajax_delete_template' ] );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			'woo-elementor-ai',
			__( 'Templates', 'woo-elementor-ai' ),
			__( 'Templates', 'woo-elementor-ai' ),
			'manage_options',
			'woo-elementor-ai-templates',
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include WOO_ELEMENTOR_AI_PLUGIN_DIR . 'templates/templates-page.php';
	}

	public function handle_export(): void {
		if ( ! isset( $_GET['woo_ai_export_template'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'woo_ai_export_template' );

		$template_id = sanitize_text_field( $_GET['woo_ai_export_template'] );
		$exporter    = new Template_Exporter();
		$result      = $exporter->export_template_by_id( $template_id );

		if ( ! $result['success'] ) {
			wp_die( esc_html( $result['message'] ) );
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
		header( 'Content-Length: ' . strlen( $result['zip_data'] ) );
		echo $result['zip_data'];
		exit;
	}

	public function ajax_save_template(): void {
		check_ajax_referer( 'woo_elementor_ai_templates_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ] );
		}

		$name        = sanitize_text_field( $_POST['name'] ?? '' );
		$description = sanitize_textarea_field( $_POST['description'] ?? '' );
		$elements    = json_decode( wp_unslash( $_POST['elements'] ?? '[]' ), true );

		if ( empty( $name ) || empty( $elements ) ) {
			wp_send_json_error( [ 'message' => 'Name and elements required.' ] );
		}

		$library = new Template_Library();
		$result  = $library->save_template( $name, $description, $elements );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( [ 'message' => 'Failed to save template.' ] );
	}

	public function ajax_delete_template(): void {
		check_ajax_referer( 'woo_elementor_ai_templates_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ] );
		}

		$id      = sanitize_text_field( $_POST['template_id'] ?? '' );
		$library = new Template_Library();

		if ( $library->delete_template( $id ) ) {
			wp_send_json_success( [ 'message' => 'Template deleted.' ] );
		}
		wp_send_json_error( [ 'message' => 'Failed to delete template.' ] );
	}
}
