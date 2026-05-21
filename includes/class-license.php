<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class License {
	const LICENSE_OPTION_KEY    = 'woo_elementor_ai_license';
	const LICENSE_TRANSIENT_KEY = 'woo_elementor_ai_license_valid';
	const LICENSE_TRANSIENT_TTL = DAY_IN_SECONDS;

	private const PUBLIC_KEY_BASE64 = 'PLACEHOLDER_PUBLIC_KEY';
	private const REQUIRE_LICENSE   = 'PLACEHOLDER_REQUIRE_LICENSE';

	/**
	 * Whether license verification is required.
	 * Set to 'false' in .env.build via build pipeline to bypass licensing.
	 */
	public function is_license_required(): bool {
		return filter_var( self::REQUIRE_LICENSE, FILTER_VALIDATE_BOOLEAN );
	}

	public function get_machine_key(): string {
		global $wpdb;
		$domain   = parse_url( site_url(), PHP_URL_HOST );
		$abspath  = ABSPATH;
		$db_name  = $wpdb->dbname;
		$auth_key = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		return hash( 'sha256', $domain . '|' . $abspath . '|' . $db_name . '|' . $auth_key );
	}

	public function verify_license( string $license_key ): bool {
		if ( empty( $license_key ) ) {
			return false;
		}
		$decoded = base64_decode( $license_key, true );
		if ( false === $decoded ) {
			return false;
		}
		$parts = explode( '|', $decoded, 2 );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		$stored_hash = $parts[0];
		$signature   = $parts[1];
		$machine_key   = $this->get_machine_key();
		$expected_hash = substr( hash( 'sha256', $machine_key ), 0, 16 );
		if ( ! hash_equals( $expected_hash, $stored_hash ) ) {
			return false;
		}
		$public_key_raw = base64_decode( self::PUBLIC_KEY_BASE64, true );
		if ( false === $public_key_raw ) {
			return false;
		}
		return sodium_crypto_sign_verify_detached( $signature, $stored_hash, $public_key_raw );
	}

	public function is_licensed(): bool {
		// Bypass license check when not required (REQUIRED_LICENSE_KEY=false in build)
		if ( ! $this->is_license_required() ) {
			return true;
		}

		$cached = get_transient( self::LICENSE_TRANSIENT_KEY );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$license_key = get_option( self::LICENSE_OPTION_KEY, '' );
		$valid       = $this->verify_license( $license_key );
		set_transient( self::LICENSE_TRANSIENT_KEY, $valid ? '1' : '0', self::LICENSE_TRANSIENT_TTL );
		return $valid;
	}

	public function activate_license( string $license_key ): array {
		if ( $this->verify_license( $license_key ) ) {
			update_option( self::LICENSE_OPTION_KEY, $license_key );
			set_transient( self::LICENSE_TRANSIENT_KEY, '1', self::LICENSE_TRANSIENT_TTL );
			return [ 'success' => true, 'message' => __( 'License activated successfully.', 'woo-elementor-ai' ) ];
		}
		return [ 'success' => false, 'message' => __( 'Invalid license key for this domain.', 'woo-elementor-ai' ) ];
	}

	public function deactivate_license(): void {
		delete_option( self::LICENSE_OPTION_KEY );
		delete_transient( self::LICENSE_TRANSIENT_KEY );
	}

	public function get_masked_license(): string {
		$key = get_option( self::LICENSE_OPTION_KEY, '' );
		if ( empty( $key ) ) { return ''; }
		$len = strlen( $key );
		if ( $len <= 12 ) { return str_repeat( '*', $len ); }
		return substr( $key, 0, 8 ) . str_repeat( '*', $len - 16 ) . substr( $key, -8 );
	}
}