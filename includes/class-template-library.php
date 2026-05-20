<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Library {
	const TEMPLATES_DIR = 'templates/packs';

	public function get_templates(): array {
		$dir = WOO_ELEMENTOR_AI_PLUGIN_DIR . self::TEMPLATES_DIR;
		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$templates = [];
		$files     = glob( $dir . '/*.json' );

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$data    = json_decode( $content, true );
			if ( $data && isset( $data['name'] ) ) {
				$templates[] = [
					'id'          => basename( $file, '.json' ),
					'name'        => $data['name'],
					'description' => $data['description'] ?? '',
					'category'    => $data['category'] ?? 'general',
					'preview'     => $data['preview'] ?? '',
					'type'        => $data['type'] ?? 'page',
					'file'        => basename( $file ),
				];
			}
		}

		return $templates;
	}

	public function get_template( string $id ): ?array {
		$file = WOO_ELEMENTOR_AI_PLUGIN_DIR . self::TEMPLATES_DIR . '/' . sanitize_file_name( $id ) . '.json';
		if ( ! file_exists( $file ) ) {
			return null;
		}

		$content = file_get_contents( $file );
		return json_decode( $content, true );
	}

	public function save_template( string $name, string $description, array $elements, string $category = 'ai-generated' ): array {
		$id   = sanitize_title( $name ) . '-' . substr( md5( uniqid() ), 0, 6 );
		$file = WOO_ELEMENTOR_AI_PLUGIN_DIR . self::TEMPLATES_DIR . '/' . $id . '.json';

		$data = [
			'name'        => $name,
			'description' => $description,
			'category'    => $category,
			'type'        => 'page',
			'version'     => WOO_ELEMENTOR_AI_VERSION,
			'created_at'  => current_time( 'mysql' ),
			'content'     => $elements,
		];

		$written = file_put_contents( $file, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		if ( false === $written ) {
			return [ 'success' => false, 'message' => 'Failed to save template.' ];
		}

		return [ 'success' => true, 'id' => $id ];
	}

	public function delete_template( string $id ): bool {
		$file = WOO_ELEMENTOR_AI_PLUGIN_DIR . self::TEMPLATES_DIR . '/' . sanitize_file_name( $id ) . '.json';
		if ( file_exists( $file ) ) {
			return wp_delete_file( $file );
		}
		return false;
	}
}
