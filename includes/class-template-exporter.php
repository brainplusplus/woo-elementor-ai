<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Exporter {

	public function export_to_zip( string $name, array $elements, string $post_type = 'page' ): string {
		$temp_dir     = wp_temp_dir() . 'woo-ai-export-' . uniqid() . '/';
		$template_dir = $temp_dir . 'template/';

		wp_mkdir_p( $template_dir );

		file_put_contents(
			$template_dir . 'content.json',
			wp_json_encode( [ 0 => $elements ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		file_put_contents(
			$template_dir . 'page.json',
			wp_json_encode( [
				'title'         => $name,
				'post_type'     => $post_type,
				'template_type' => 'wp-page',
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		$elementor_version = class_exists( '\Elementor\Plugin' ) ? ELEMENTOR_VERSION : '3.20.0';
		file_put_contents(
			$template_dir . 'manifest.json',
			wp_json_encode( [
				'name'              => $name,
				'version'           => WOO_ELEMENTOR_AI_VERSION,
				'type'              => 'page',
				'elementor_version' => $elementor_version,
				'created_at'        => gmdate( 'c' ),
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		$zip_path = $temp_dir . sanitize_file_name( $name ) . '.zip';
		$zip      = new \ZipArchive();

		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			$this->cleanup( $temp_dir );
			return '';
		}

		$files = [
			'template/content.json',
			'template/page.json',
			'template/manifest.json',
		];

		foreach ( $files as $file ) {
			$full_path = $temp_dir . $file;
			$zip->addFile( $full_path, $file );
		}

		$zip->close();

		$zip_content = file_get_contents( $zip_path );
		$this->cleanup( $temp_dir );

		return $zip_content;
	}

	public function export_template_by_id( string $template_id ): array {
		$library  = new Template_Library();
		$template = $library->get_template( $template_id );

		if ( ! $template ) {
			return [ 'success' => false, 'message' => 'Template not found.' ];
		}

		$elements = $template['content'] ?? [];
		$name     = $template['name'];
		$zip_data = $this->export_to_zip( $name, $elements );

		if ( empty( $zip_data ) ) {
			return [ 'success' => false, 'message' => 'Failed to create ZIP.' ];
		}

		return [
			'success'  => true,
			'zip_data' => $zip_data,
			'filename' => sanitize_file_name( $name ) . '.zip',
		];
	}

	public function export_page_to_zip( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [ 'success' => false, 'message' => 'Post not found.' ];
		}

		$raw      = get_post_meta( $post_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			return [ 'success' => false, 'message' => 'No Elementor data.' ];
		}

		$zip_data = $this->export_to_zip( $post->post_title, $elements, $post->post_type );

		if ( empty( $zip_data ) ) {
			return [ 'success' => false, 'message' => 'Failed to create ZIP.' ];
		}

		return [
			'success'  => true,
			'zip_data' => $zip_data,
			'filename' => sanitize_file_name( $post->post_title ) . '.zip',
		];
	}

	private function cleanup( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getRealPath() );
			} else {
				wp_delete_file( $file->getRealPath() );
			}
		}

		rmdir( $dir );
	}
}
