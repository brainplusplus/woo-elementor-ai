# Template Generator + License System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add domain-bound license system with Ed25519 crypto and template generator with ZIP export to Woo Elementor AI plugin, plus a separate Go CLI tool for license key generation.

**Architecture:** Hybrid approach — PHP plugin handles license verification (libsodium), template generation (ZipArchive), and settings gating. Go CLI tool handles license key signing with private key. Critical PHP files obfuscated for distribution.

**Tech Stack:** PHP 7.4+ (WordPress), libsodium (Ed25519), ZipArchive, Go 1.21+ (CLI tool), YAK Pro or custom PHP-Parser obfuscator

**Design Doc:** `docs/plans/2026-05-12-template-generator-license-design.md`

---

## Phase 1: Go License Generator Tool (Independent — No Plugin Dependencies)

Build this first. It's standalone and needed before plugin license verification can be tested.

### Task 1: Initialize Go Project

**Files:**
- Create: `woo-ai-licensegen/go.mod`
- Create: `woo-ai-licensegen/main.go`

**Step 1: Create project directory and go.mod**

```bash
mkdir -p woo-ai-licensegen/keys
cd woo-ai-licensegen
go mod init woo-ai-licensegen
```

**Step 2: Write main.go skeleton**

Create `woo-ai-licensegen/main.go`:

```go
package main

import (
	"fmt"
	"os"
)

func main() {
	if len(os.Args) < 2 {
		printUsage()
		os.Exit(1)
	}

	command := os.Args[1]
	switch command {
	case "keygen":
		if err := runKeygen(); err != nil {
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			os.Exit(1)
		}
	case "sign":
		if err := runSign(); err != nil {
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			os.Exit(1)
		}
	case "pubkey":
		if err := runPubkey(); err != nil {
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			os.Exit(1)
		}
	default:
		fmt.Fprintf(os.Stderr, "Unknown command: %s\n", command)
		printUsage()
		os.Exit(1)
	}
}

func printUsage() {
	fmt.Println("Usage: licensegen <command>")
	fmt.Println("Commands:")
	fmt.Println("  keygen          Generate Ed25519 keypair in keys/ directory")
	fmt.Println("  sign --machine  Sign a machine key and output license key")
	fmt.Println("  pubkey          Print the public key (base64)")
}
```

**Step 3: Verify compiles**

Run: `cd woo-ai-licensegen && go build .`
Expected: builds without errors (sign.go and keygen.go not yet referenced — will add next)

**Step 4: Commit**

```bash
git add woo-ai-licensegen/
git commit -m "feat(licensegen): initialize Go project skeleton"
```

---

### Task 2: Implement Keypair Generation

**Files:**
- Create: `woo-ai-licensegen/keygen.go`
- Create: `woo-ai-licensegen/keys/` (directory, gitkeep)

**Step 1: Write keygen.go**

Create `woo-ai-licensegen/keygen.go`:

```go
package main

import (
	"crypto/ed25519"
	"encoding/base64"
	"fmt"
	"os"
	"path/filepath"
)

func runKeygen() error {
	keysDir := "keys"
	if err := os.MkdirAll(keysDir, 0700); err != nil {
		return fmt.Errorf("create keys dir: %w", err)
	}

	pubKey, privKey, err := ed25519.GenerateKey(nil)
	if err != nil {
		return fmt.Errorf("generate key: %w", err)
	}

	pubFile := filepath.Join(keysDir, "public.key")
	privFile := filepath.Join(keysDir, "private.key")

	// Write raw bytes (binary)
	if err := os.WriteFile(pubFile, pubKey, 0644); err != nil {
		return fmt.Errorf("write public key: %w", err)
	}
	if err := os.WriteFile(privFile, privKey, 0600); err != nil {
		return fmt.Errorf("write private key: %w", err)
	}

	fmt.Println("Keypair generated successfully.")
	fmt.Printf("Public key:  %s\n", base64.StdEncoding.EncodeToString(pubKey))
	fmt.Printf("Private key: %s\n", base64.StdEncoding.EncodeToString(privKey))
	fmt.Printf("Files: %s, %s\n", pubFile, privFile)

	return nil
}

func loadPrivateKey() (ed25519.PrivateKey, error) {
	data, err := os.ReadFile("keys/private.key")
	if err != nil {
		return nil, fmt.Errorf("read private key: %w (run 'licensegen keygen' first)", err)
	}
	return ed25519.PrivateKey(data), nil
}

func loadPublicKey() (ed25519.PublicKey, error) {
	data, err := os.ReadFile("keys/public.key")
	if err != nil {
		return nil, fmt.Errorf("read public key: %w (run 'licensegen keygen' first)", err)
	}
	return ed25519.PublicKey(data), nil
}
```

**Step 2: Build and test keygen**

Run: `cd woo-ai-licensegen && go build . && ./licensegen keygen`
Expected: Creates `keys/public.key` and `keys/private.key`, prints base64 of both keys

**Step 3: Verify key files exist**

Run: `ls -la keys/`
Expected: Two files, private.key (64 bytes), public.key (32 bytes)

**Step 4: Commit**

```bash
git add woo-ai-licensegen/
git commit -m "feat(licensegen): implement Ed25519 keypair generation"
```

---

### Task 3: Implement License Signing

**Files:**
- Create: `woo-ai-licensegen/sign.go`

**Step 1: Write sign.go**

Create `woo-ai-licensegen/sign.go`:

```go
package main

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"flag"
	"fmt"
	"strings"
)

func runSign() error {
	signCmd := flag.NewFlagSet("sign", flag.ExitOnError)
	machineKey := signCmd.String("machine", "", "Machine key to sign")
	signCmd.Parse(os.Args[2:])

	if *machineKey == "" {
		return fmt.Errorf("--machine flag is required")
	}

	privKey, err := loadPrivateKey()
	if err != nil {
		return err
	}

	// Compute machine key hash (first 16 chars of SHA-256)
	hash := sha256.Sum256([]byte(*machineKey))
	machineKeyHash := fmt.Sprintf("%x", hash)[:16]

	// Sign the hash
	signature := ed25519.Sign(privKey, []byte(machineKeyHash))

	// Encode: base64(machineKeyHash | "|" | signature)
	payload := machineKeyHash + "|" + string(signature)
	licenseKey := base64.StdEncoding.EncodeToString([]byte(payload))

	fmt.Println(licenseKey)

	return nil
}

func runPubkey() error {
	pubKey, err := loadPublicKey()
	if err != nil {
		return err
	}
	fmt.Println(base64.StdEncoding.EncodeToString(pubKey))
	return nil
}
```

**Step 2: Fix main.go imports** — `main.go` doesn't need changes since `runSign` and `runPubkey` are defined in sign.go and keygen.go respectively. The `flag` package import in sign.go uses local `flag.NewFlagSet`, so main.go stays clean.

**Step 3: Build and test signing**

Run:
```bash
cd woo-ai-licensegen
go build .
./licensegen sign --machine="test123"
```
Expected: Outputs a base64-encoded license key string

**Step 4: Test full round-trip (keygen → sign)**

```bash
./licensegen keygen
./licensegen sign --machine="a1b2c3d4e5f67890"
./licensegen pubkey
```
Expected: All three commands succeed without error

**Step 5: Commit**

```bash
git add woo-ai-licensegen/
git commit -m "feat(licensegen): implement license signing and pubkey display"
```

---

### Task 4: Add .gitignore for keys directory

**Files:**
- Create: `woo-ai-licensegen/.gitignore`

**Step 1: Create .gitignore**

```
keys/
licensegen
licensegen.exe
licensegen-mac
```

**Step 2: Commit**

```bash
git add woo-ai-licensegen/.gitignore
git commit -m "chore(licensegen): add .gitignore for keys and binaries"
```

---

## Phase 2: Plugin License System (PHP)

### Task 5: Create License Class

**Files:**
- Create: `includes/class-license.php`

**Step 1: Write class-license.php**

Create `includes/class-license.php`:

```php
<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class License {
	const LICENSE_OPTION_KEY = 'woo_elementor_ai_license';
	const LICENSE_TRANSIENT_KEY = 'woo_elementor_ai_license_valid';
	const LICENSE_TRANSIENT_TTL = DAY_IN_SECONDS;

	/**
	 * Ed25519 public key (base64 encoded, set during build).
	 * This will be replaced by build script with actual public key.
	 */
	private const PUBLIC_KEY_BASE64 = 'PLACEHOLDER_PUBLIC_KEY';

	/**
	 * Generate Machine Key for this WordPress installation.
	 * SHA-256(domain|ABSPATH|DB_NAME|AUTH_KEY)
	 */
	public function get_machine_key(): string {
		global $wpdb;

		$domain   = parse_url( site_url(), PHP_URL_HOST );
		$abspath  = ABSPATH;
		$db_name  = $wpdb->dbname;
		$auth_key = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';

		return hash( 'sha256', $domain . '|' . $abspath . '|' . $db_name . '|' . $auth_key );
	}

	/**
	 * Verify a license key against current Machine Key.
	 */
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

		// Compute expected hash from current Machine Key
		$machine_key   = $this->get_machine_key();
		$expected_hash = substr( hash( 'sha256', $machine_key ), 0, 16 );

		// Compare hashes (timing-safe)
		if ( ! hash_equals( $expected_hash, $stored_hash ) ) {
			return false;
		}

		// Verify Ed25519 signature
		$public_key_raw = base64_decode( self::PUBLIC_KEY_BASE64, true );
		if ( false === $public_key_raw ) {
			return false;
		}

		return sodium_crypto_sign_verify_detached(
			$signature,
			$stored_hash,
			$public_key_raw
		);
	}

	/**
	 * Check if plugin is currently licensed (with transient cache).
	 */
	public function is_licensed(): bool {
		$cached = get_transient( self::LICENSE_TRANSIENT_KEY );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$license_key = get_option( self::LICENSE_OPTION_KEY, '' );
		$valid       = $this->verify_license( $license_key );

		set_transient( self::LICENSE_TRANSIENT_KEY, $valid ? '1' : '0', self::LICENSE_TRANSIENT_TTL );

		return $valid;
	}

	/**
	 * Activate a license key.
	 */
	public function activate_license( string $license_key ): array {
		if ( $this->verify_license( $license_key ) ) {
			update_option( self::LICENSE_OPTION_KEY, $license_key );
			set_transient( self::LICENSE_TRANSIENT_KEY, '1', self::LICENSE_TRANSIENT_TTL );
			return [
				'success' => true,
				'message' => __( 'License activated successfully.', 'woo-elementor-ai' ),
			];
		}

		return [
			'success' => false,
			'message' => __( 'Invalid license key for this domain.', 'woo-elementor-ai' ),
		];
	}

	/**
	 * Deactivate current license.
	 */
	public function deactivate_license(): void {
		delete_option( self::LICENSE_OPTION_KEY );
		delete_transient( self::LICENSE_TRANSIENT_KEY );
	}

	/**
	 * Get stored license key (masked for display).
	 */
	public function get_masked_license(): string {
		$key = get_option( self::LICENSE_OPTION_KEY, '' );
		if ( empty( $key ) ) {
			return '';
		}
		$len = strlen( $key );
		if ( $len <= 12 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 8 ) . str_repeat( '*', $len - 16 ) . substr( $key, -8 );
	}
}
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-license.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add includes/class-license.php
git commit -m "feat(license): create License class with Ed25519 verification"
```

---

### Task 6: Integrate License into Settings Class

**Files:**
- Modify: `includes/class-settings.php`

**Step 1: Add license dependency and gating**

In `class-settings.php`, add after `class Settings {`:

```php
private License $license;

public function __construct() {
	$this->license = new License();
	add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
	add_action( 'admin_init', [ $this, 'register_settings' ] );
}

public function get_license(): License {
	return $this->license;
}
```

Add `require_once $base . 'class-license.php';` in `class-plugin.php` load_dependencies method (next task).

**Step 2: Add license AJAX handler registration**

In `class-settings.php`, add to `__construct`:

```php
add_action( 'wp_ajax_woo_elementor_ai_activate_license', [ $this, 'ajax_activate_license' ] );
add_action( 'wp_ajax_woo_elementor_ai_deactivate_license', [ $this, 'ajax_deactivate_license' ] );
```

**Step 3: Add AJAX handler methods**

```php
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
```

**Step 4: Verify PHP syntax**

Run: `php -l includes/class-settings.php`
Expected: No syntax errors

**Step 5: Commit**

```bash
git add includes/class-settings.php
git commit -m "feat(settings): integrate license verification and AJAX handlers"
```

---

### Task 7: Update Plugin Bootstrap

**Files:**
- Modify: `includes/class-plugin.php`

**Step 1: Add license dependency loading**

In `class-plugin.php`, in `load_dependencies()`, add after the existing require_once lines:

```php
require_once $base . 'class-license.php';
```

**Step 2: Add license gating to module init**

Modify `init_modules()` to gate non-essential modules behind license:

```php
private function init_modules(): void {
	add_action( 'init', [ $this, 'load_textdomain' ] );

	// Settings always loaded (contains license page)
	$settings = new Settings();

	// Only load functional modules if licensed
	$license = $settings->get_license();
	if ( $license->is_licensed() ) {
		new Admin\Admin_Page();
		new Editor\Editor_Integration();
		new API\REST_Controller();
	}
}
```

**Step 3: Verify PHP syntax**

Run: `php -l includes/class-plugin.php`
Expected: No syntax errors

**Step 4: Commit**

```bash
git add includes/class-plugin.php
git commit -m "feat(plugin): add license gating for functional modules"
```

---

### Task 8: Update Settings Page Template with License UI

**Files:**
- Modify: `templates/settings-page.php`

**Step 1: Add license section at top of settings page**

Insert before the existing `<h2>AI Chat Configuration</h2>`:

```php
<?php
$license       = $this->get_license();
$machine_key   = $license->get_machine_key();
$is_licensed   = $license->is_licensed();
$masked_key    = $license->get_masked_license();
$license_nonce = wp_create_nonce( 'woo_elementor_ai_license_nonce' );
?>

<div class="woo-ai-license-section" style="background:#f9f9f9;border:1px solid #ddd;padding:20px;margin-bottom:20px;border-radius:4px;">
	<h2 style="margin-top:0;">
		<?php if ( $is_licensed ) : ?>
			<span class="dashicons dashicons-yes-alt" style="color:green;"></span>
			<?php esc_html_e( 'License Active', 'woo-elementor-ai' ); ?>
		<?php else : ?>
			<span class="dashicons dashicons-lock" style="color:#d63638;"></span>
			<?php esc_html_e( 'License Activation', 'woo-elementor-ai' ); ?>
		<?php endif; ?>
	</h2>

	<table class="form-table">
		<tr>
			<th><label><?php esc_html_e( 'Your Machine Key', 'woo-elementor-ai' ); ?></label></th>
			<td>
				<div style="display:flex;align-items:center;gap:8px;">
					<input type="text" id="woo_ai_machine_key"
						   value="<?php echo esc_attr( $machine_key ); ?>"
						   class="regular-text" readonly
						   style="background:#eee;">
					<button type="button" class="button" onclick="wooAiCopyMachineKey()">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy', 'woo-elementor-ai' ); ?>
					</button>
				</div>
				<p class="description"><?php esc_html_e( 'Provide this key to receive your license key.', 'woo-elementor-ai' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="woo_ai_license_key"><?php esc_html_e( 'License Key', 'woo-elementor-ai' ); ?></label></th>
			<td>
				<div style="display:flex;align-items:center;gap:8px;">
					<?php if ( $is_licensed ) : ?>
						<input type="text" id="woo_ai_license_key"
							   value="<?php echo esc_attr( $masked_key ); ?>"
							   class="regular-text" readonly
							   style="background:#eee;">
						<button type="button" class="button" id="woo-ai-deactivate-license"
								onclick="wooAiDeactivateLicense()">
							<?php esc_html_e( 'Deactivate', 'woo-elementor-ai' ); ?>
						</button>
					<?php else : ?>
						<input type="text" id="woo_ai_license_key"
							   class="regular-text"
							   placeholder="<?php esc_attr_e( 'Enter your license key', 'woo-elementor-ai' ); ?>">
						<button type="button" class="button button-primary" id="woo-ai-activate-license"
								onclick="wooAiActivateLicense()">
							<?php esc_html_e( 'Activate License', 'woo-elementor-ai' ); ?>
						</button>
					<?php endif; ?>
					<span id="woo-ai-license-result"></span>
				</div>
			</td>
		</tr>
	</table>
</div>

<?php if ( ! $is_licensed ) : ?>
	<div class="notice notice-warning inline" style="margin-bottom:20px;">
		<p><strong><?php esc_html_e( 'Plugin functionality is locked until license is activated.', 'woo-elementor-ai' ); ?></strong></p>
	</div>
	</form> <?php // Close form early — no settings to save ?>
	</div> <?php // Close .wrap ?>
	<script>/* license JS only */</script>
	<?php return; ?>
<?php endif; ?>
```

**Step 2: Add license JavaScript functions**

Add to the `<script>` block at bottom:

```javascript
function wooAiCopyMachineKey() {
	var field = document.getElementById('woo_ai_machine_key');
	field.select();
	document.execCommand('copy');
	alert('Machine key copied!');
}

function wooAiActivateLicense() {
	var btn = document.getElementById('woo-ai-activate-license');
	var result = document.getElementById('woo-ai-license-result');
	var key = document.getElementById('woo_ai_license_key').value.trim();
	if (!key) { result.innerHTML = '<span style="color:red;">Enter a license key</span>'; return; }
	btn.disabled = true;
	result.textContent = 'Verifying...';

	fetch(ajaxurl, {
		method: 'POST',
		headers: {'Content-Type': 'application/x-www-form-urlencoded'},
		body: 'action=woo_elementor_ai_activate_license&nonce=<?php echo esc_js($license_nonce); ?>&license_key=' + encodeURIComponent(key)
	})
	.then(function(r) { return r.json(); })
	.then(function(data) {
		if (data.success) { location.reload(); }
		else { result.innerHTML = '<span style="color:red;">' + (data.data.message || 'Activation failed') + '</span>'; }
	})
	.catch(function() { result.innerHTML = '<span style="color:red;">Network error</span>'; })
	.finally(function() { btn.disabled = false; });
}

function wooAiDeactivateLicense() {
	if (!confirm('Deactivate license? Plugin features will be locked.')) return;
	var result = document.getElementById('woo-ai-license-result');

	fetch(ajaxurl, {
		method: 'POST',
		headers: {'Content-Type': 'application/x-www-form-urlencoded'},
		body: 'action=woo_elementor_ai_deactivate_license&nonce=<?php echo esc_js($license_nonce); ?>'
	})
	.then(function(r) { return r.json(); })
	.then(function(data) { if (data.success) { location.reload(); } })
	.catch(function() { result.innerHTML = '<span style="color:red;">Network error</span>'; });
}
```

**Step 3: Verify PHP syntax**

Run: `php -l templates/settings-page.php`
Expected: No syntax errors

**Step 4: Commit**

```bash
git add templates/settings-page.php
git commit -m "feat(settings): add license activation UI to settings page"
```

---

## Phase 3: Template Generator + ZIP Export

### Task 9: Create Template Library Class

**Files:**
- Create: `includes/class-template-library.php`

**Step 1: Write class-template-library.php**

```php
<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Library {
	const TEMPLATES_DIR = 'templates/packs';

	/**
	 * Get all available templates.
	 */
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

	/**
	 * Get single template data.
	 */
	public function get_template( string $id ): ?array {
		$file = WOO_ELEMENTOR_AI_PLUGIN_DIR . self::TEMPLATES_DIR . '/' . sanitize_file_name( $id ) . '.json';
		if ( ! file_exists( $file ) ) {
			return null;
		}

		$content = file_get_contents( $file );
		$data    = json_decode( $content, true );
		return $data;
	}

	/**
	 * Save generated elements as a template.
	 */
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

	/**
	 * Delete a template.
	 */
	public function delete_template( string $id ): bool {
		$file = WOO_ELEMENTOR_AI_PLUGIN_DIR . self::TEMPLATES_DIR . '/' . sanitize_file_name( $id ) . '.json';
		if ( file_exists( $file ) ) {
			return wp_delete_file( $file );
		}
		return false;
	}
}
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-template-library.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add includes/class-template-library.php
git commit -m "feat(templates): create Template_Library class for template management"
```

---

### Task 10: Create Template Exporter Class

**Files:**
- Create: `includes/class-template-exporter.php`

**Step 1: Write class-template-exporter.php**

```php
<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Exporter {
	/**
	 * Export a template as Elementor-compatible ZIP.
	 */
	public function export_to_zip( string $name, array $elements, string $post_type = 'page' ): string {
		$temp_dir = wp_temp_dir() . 'woo-ai-export-' . uniqid() . '/';
		$template_dir = $temp_dir . 'template/';

		wp_mkdir_p( $template_dir );

		// content.json — Elementor elements
		file_put_contents(
			$template_dir . 'content.json',
			wp_json_encode( [ 0 => $elements ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		// page.json — Page metadata
		file_put_contents(
			$template_dir . 'page.json',
			wp_json_encode( [
				'title'         => $name,
				'post_type'     => $post_type,
				'template_type' => 'wp-page',
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		// manifest.json — Template manifest
		$elementor_version = class_exists( '\Elementor\Plugin' ) ? ELEMENTOR_VERSION : '3.20.0';
		file_put_contents(
			$template_dir . 'manifest.json',
			wp_json_encode( [
				'name'             => $name,
				'version'          => WOO_ELEMENTOR_AI_VERSION,
				'type'             => 'page',
				'elementor_version' => $elementor_version,
				'created_at'       => gmdate( 'c' ),
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		// Create ZIP
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

		// Read ZIP content
		$zip_content = file_get_contents( $zip_path );
		$this->cleanup( $temp_dir );

		return $zip_content;
	}

	/**
	 * Export from template library by ID.
	 */
	public function export_template_by_id( string $template_id ): array {
		$library = new Template_Library();
		$template = $library->get_template( $template_id );

		if ( ! $template ) {
			return [ 'success' => false, 'message' => 'Template not found.' ];
		}

		$elements  = $template['content'] ?? [];
		$name      = $template['name'];
		$zip_data  = $this->export_to_zip( $name, $elements );

		if ( empty( $zip_data ) ) {
			return [ 'success' => false, 'message' => 'Failed to create ZIP.' ];
		}

		return [
			'success'  => true,
			'zip_data' => $zip_data,
			'filename' => sanitize_file_name( $name ) . '.zip',
		];
	}

	/**
	 * Export AI-generated page as template ZIP.
	 */
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

	/**
	 * Clean up temp directory.
	 */
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
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-template-exporter.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add includes/class-template-exporter.php
git commit -m "feat(templates): create Template_Exporter with ZIP generation"
```

---

### Task 11: Create Templates Admin Page

**Files:**
- Create: `includes/admin/class-templates-page.php`
- Create: `templates/templates-page.php`

**Step 1: Write class-templates-page.php**

```php
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
```

**Step 2: Write templates-page.php**

Create `templates/templates-page.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$library   = new \WooElementorAI\Template_Library();
$templates = $library->get_templates();
$nonce     = wp_create_nonce( 'woo_elementor_ai_templates_nonce' );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Elementor AI Templates', 'woo-elementor-ai' ); ?></h1>

	<?php if ( empty( $templates ) ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'No templates yet. Generate pages with AI and save them as templates, or add template JSON files to templates/packs/.', 'woo-elementor-ai' ); ?></p>
		</div>
	<?php else : ?>
		<div class="woo-ai-templates-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;margin-top:20px;">
			<?php foreach ( $templates as $tpl ) : ?>
				<div class="woo-ai-template-card" style="border:1px solid #ddd;border-radius:4px;padding:16px;background:#fff;">
					<?php if ( $tpl['preview'] ) : ?>
						<img src="<?php echo esc_url( $tpl['preview'] ); ?>" alt="" style="width:100%;height:160px;object-fit:cover;border-radius:4px;margin-bottom:12px;">
					<?php else : ?>
						<div style="width:100%;height:160px;background:#f0f0f0;border-radius:4px;margin-bottom:12px;display:flex;align-items:center;justify-content:center;color:#999;">
							<span class="dashicons dashicons-layout" style="font-size:48px;width:48px;height:48px;"></span>
						</div>
					<?php endif; ?>
					<h3 style="margin:0 0 4px;"><?php echo esc_html( $tpl['name'] ); ?></h3>
					<p style="color:#666;margin:0 0 12px;font-size:13px;"><?php echo esc_html( $tpl['description'] ); ?></p>
					<div style="display:flex;gap:8px;">
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=woo-elementor-ai-templates&woo_ai_export_template=' . $tpl['id'] ), 'woo_ai_export_template' ) ); ?>"
						   class="button button-primary">
							<span class="dashicons dashicons-download" style="vertical-align:middle;"></span>
							<?php esc_html_e( 'Export ZIP', 'woo-elementor-ai' ); ?>
						</a>
						<button type="button" class="button" onclick="wooAiDeleteTemplate('<?php echo esc_js( $tpl['id'] ); ?>', this)">
							<span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
						</button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<script>
function wooAiDeleteTemplate(id, btn) {
	if (!confirm('Delete this template?')) return;
	btn.disabled = true;
	fetch(ajaxurl, {
		method: 'POST',
		headers: {'Content-Type': 'application/x-www-form-urlencoded'},
		body: 'action=woo_elementor_ai_delete_template&nonce=<?php echo esc_js($nonce); ?>&template_id=' + encodeURIComponent(id)
	})
	.then(function(r) { return r.json(); })
	.then(function(data) { if (data.success) { location.reload(); } })
	.finally(function() { btn.disabled = false; });
}
</script>
```

**Step 3: Verify PHP syntax on both files**

Run: `php -l includes/admin/class-templates-page.php && php -l templates/templates-page.php`
Expected: No syntax errors

**Step 4: Commit**

```bash
git add includes/admin/class-templates-page.php templates/templates-page.php
git commit -m "feat(templates): add templates admin page with grid UI"
```

---

### Task 12: Create Sample Template Pack

**Files:**
- Create: `templates/packs/landing-page-hero.json`

**Step 1: Create packs directory and sample template**

Create `templates/packs/landing-page-hero.json`:

```json
{
  "name": "Hero Landing Page",
  "description": "A clean hero section with heading, subtitle, CTA button, and background.",
  "category": "landing",
  "type": "page",
  "preview": "",
  "version": "1.0.0",
  "created_at": "2026-05-12",
  "content": [
    {
      "id": "a1b2c3d4",
      "elType": "container",
      "isInner": false,
      "settings": {
        "flex_direction": "column",
        "content_width": "full",
        "min_height": "80vh",
        "background_background": "classic",
        "background_color": "#1a1a2e",
        "padding": {"unit": "px", "top": "80", "right": "40", "bottom": "80", "left": "40", "isLinked": false}
      },
      "elements": [
        {
          "id": "e5f6g7h8",
          "elType": "widget",
          "widgetType": "heading",
          "isInner": false,
          "settings": {
            "title": "Build Something Amazing",
            "align": "center",
            "title_color": "#ffffff",
            "typography_typography": "custom",
            "typography_font_size": {"unit": "px", "size": 64},
            "typography_font_weight": "700"
          },
          "elements": []
        },
        {
          "id": "i9j0k1l2",
          "elType": "widget",
          "widgetType": "text-editor",
          "isInner": false,
          "settings": {
            "editor": "<p style='text-align:center;color:#b0b0c0;font-size:20px;'>Create stunning pages with AI-powered Elementor templates. Fast, beautiful, ready to customize.</p>"
          },
          "elements": []
        },
        {
          "id": "m3n4o5p6",
          "elType": "widget",
          "widgetType": "button",
          "isInner": false,
          "settings": {
            "text": "Get Started",
            "align": "center",
            "button_background_color": "#e94560",
            "button_border_radius": {"unit": "px", "top": "8", "right": "8", "bottom": "8", "left": "8", "isLinked": true},
            "typography_typography": "custom",
            "typography_font_size": {"unit": "px", "size": 18},
            "button_text_color": "#ffffff"
          },
          "elements": []
        }
      ]
    }
  ]
}
```

**Step 2: Commit**

```bash
git add templates/packs/
git commit -m "feat(templates): add sample landing page hero template"
```

---

### Task 13: Wire Templates into Plugin Bootstrap

**Files:**
- Modify: `includes/class-plugin.php`

**Step 1: Add template dependencies and init**

In `load_dependencies()`, add:

```php
require_once $base . 'class-template-library.php';
require_once $base . 'class-template-exporter.php';
require_once $base . 'admin/class-templates-page.php';
```

In `init_modules()`, add inside the `if ( $license->is_licensed() )` block:

```php
new Admin\Templates_Page();
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-plugin.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add includes/class-plugin.php
git commit -m "feat(plugin): wire template library and admin page into bootstrap"
```

---

### Task 14: Add Export ZIP Endpoint to REST API

**Files:**
- Modify: `includes/api/class-rest-controller.php`

**Step 1: Read current REST controller to understand structure**

Read the file, then add a new route for template export.

Add route registration:
```php
register_rest_route( $namespace, '/templates/export/(?P<id>[a-zA-Z0-9\-]+)', [
	'methods'             => 'GET',
	'callback'            => [ $this, 'export_template' ],
	'permission_callback' => function() {
		return current_user_can( 'manage_options' );
	},
] );
```

Add handler method:
```php
public function export_template( \WP_REST_Request $request ): \WP_REST_Response {
	$id       = $request->get_param( 'id' );
	$exporter = new Template_Exporter();
	$result   = $exporter->export_template_by_id( $id );

	if ( ! $result['success'] ) {
		return new \WP_REST_Response( [ 'message' => $result['message'] ], 400 );
	}

	// Return as downloadable response
	return new \WP_REST_Response( $result['zip_data'], 200, [
		'Content-Type'        => 'application/zip',
		'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
	] );
}
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/api/class-rest-controller.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add includes/api/class-rest-controller.php
git commit -m "feat(api): add REST endpoint for template ZIP export"
```

---

## Phase 4: Build & Obfuscation Pipeline

### Task 15: Create Build Script

**Files:**
- Create: `build.ps1` (Windows PowerShell)

**Step 1: Write build.ps1**

**Files:**
- Create: `build.ps1` (Windows PowerShell)
- Create: `.env.build` (build configuration)

**Step 1: Create .env.build**

```
PLUGIN_VERSION=1.1.0
PUBLIC_KEY=
```

**Step 2: Write build.ps1**

```powershell
# Build script for Woo Elementor AI plugin distribution
# Reads config from .env.build
param(
    [string]$PublicKey
)

$ErrorActionPreference = "Stop"

# Load .env.build
if (-not (Test-Path ".env.build")) {
    Write-Host "Error: .env.build not found. Copy .env.build.example and configure." -ForegroundColor Red
    exit 1
}

Get-Content ".env.build" | ForEach-Object {
    if ($_ -match '^\s*([^#][^=]+)=(.*)$') {
        $key = $matches[1].Trim()
        $val = $matches[2].Trim()
        Set-Variable -Name "ENV_$key" -Value $val -Scope Script
    }
}

$Version = $ENV_PLUGIN_VERSION
if (-not $Version) {
    Write-Host "Error: PLUGIN_VERSION not set in .env.build" -ForegroundColor Red
    exit 1
}

# CLI param overrides .env
if ($PublicKey) { $ENV_PUBLIC_KEY = $PublicKey }

$DistDir = "dist\woo-elementor-ai"
$ZipName = "woo-elementor-ai-v$Version.zip"

Write-Host "=== Building Woo Elementor AI v$Version ===" -ForegroundColor Cyan

# Clean previous build
if (Test-Path $DistDir) { Remove-Item -Recurse -Force $DistDir }
if (Test-Path $ZipName) { Remove-Item -Force $ZipName }

# Copy source files
$excludeDirs = @('dist', 'docs', '.git', 'woo-ai-licensegen')
$sourceFiles = Get-ChildItem -Path . -Exclude $excludeDirs
New-Item -ItemType Directory -Path $DistDir -Force | Out-Null

foreach ($item in $sourceFiles) {
    Copy-Item -Path $item.FullName -Destination $DistDir -Recurse -Force
}

# Embed public key
if ($ENV_PUBLIC_KEY) {
    $licenseFile = "$DistDir\includes\class-license.php"
    $content = Get-Content $licenseFile -Raw
    $content = $content -replace 'PLACEHOLDER_PUBLIC_KEY', $ENV_PUBLIC_KEY
    Set-Content $licenseFile $content -NoNewline
    Write-Host "Public key embedded in license class." -ForegroundColor Green
}

# TODO: Add obfuscation step here when obfuscator is set up
# php obfuscator.phar --input $DistDir\includes\class-license.php --output $DistDir\includes\class-license.php
# php obfuscator.phar --input $DistDir\includes\class-settings.php --output $DistDir\includes\class-settings.php
# php obfuscator.phar --input $DistDir\includes\class-ai-service.php --output $DistDir\includes\class-ai-service.php

# Update version in main plugin file
$pluginFile = "$DistDir\woo-elementor-ai.php"
$pluginContent = Get-Content $pluginFile -Raw
$pluginContent = $pluginContent -replace "Version:     .+", "Version:     $Version"
$pluginContent = $pluginContent -replace "WOO_ELEMENTOR_AI_VERSION', '.+'", "WOO_ELEMENTOR_AI_VERSION', '$Version'"
Set-Content $pluginFile $pluginContent -NoNewline

# Create ZIP
Compress-Archive -Path $DistDir -DestinationPath $ZipName -Force
Write-Host "=== Build complete: $ZipName ===" -ForegroundColor Green
```

**Step 2: Commit**

```bash
git add build.ps1 .env.build
git commit -m "build: add PowerShell build script with .env.build config"
```

---

### Task 16: Version Bump

**Files:**
- Modify: `woo-elementor-ai.php`

**Step 1: Update version to 1.1.0**

Change in `woo-elementor-ai.php`:
- Line 6: `* Version:     1.1.0`
- Line 19: `define( 'WOO_ELEMENTOR_AI_VERSION', '1.1.0' );`

**Step 2: Commit**

```bash
git add woo-elementor-ai.php
git commit -m "chore: bump version to 1.1.0"
```

---

### Task 17: Create README.md

**Files:**
- Create: `README.md`
- Create: `woo-ai-licensegen/README.md`

**Step 1: Write plugin README.md**

Create `README.md`:

```markdown
# Woo Elementor AI

AI-powered page generation, editing, and chat for Elementor using OpenAI-compatible APIs with flexible image generation support.

## Features

- **AI Page Generation** — Describe a page, AI builds it in Elementor
- **AI Element Editing** — Select any element, describe changes
- **AI Chat Panel** — In-editor chat for iterative design
- **Template Library** — Browse, preview, export templates as ZIP
- **Template Export** — Generate Elementor-compatible ZIP files ready for import
- **Image Integration** — Unsplash, Pexels, or OpenAI-compatible image generation
- **License System** — Domain-bound licensing with offline verification

## Requirements

- WordPress 6.0+
- PHP 7.4+ (with sodium extension, bundled by default)
- Elementor (free or Pro)
- OpenAI-compatible API key

## Installation

1. Download the latest release ZIP
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Upload the ZIP and activate
4. Navigate to **Woo Elementor AI** in the admin menu
5. Enter your Machine Key to receive a License Key
6. After activation, configure your AI API settings

## License Activation

The plugin requires a license key bound to your domain.

1. Go to **Woo Elementor AI → Settings**
2. Copy your **Machine Key** (unique to your installation)
3. Provide the Machine Key to receive your License Key
4. Enter the License Key and click **Activate License**
5. All plugin features unlock upon successful activation

## Configuration

### AI Chat

| Setting | Description | Default |
|---|---|---|
| Base URL | OpenAI-compatible API endpoint | `https://api.openai.com` |
| API Key | Your API key | — |
| Model | Chat model to use | `gpt-4o` |

### Image Generation

| Source | Description |
|---|---|
| None | No image generation |
| Unsplash | Free stock photos via Unsplash API |
| Pexels | Free stock photos via Pexels API |
| OpenAI Compatible | DALL-E or compatible image generation |

### Generation Defaults

| Setting | Description | Default |
|---|---|---|
| Max Tokens | Maximum response tokens | `4096` |
| Temperature | Creativity (0-2) | `0.7` |
| Chat Max Context | Maximum context window | `8000` |

## Templates

1. Go to **Woo Elementor AI → Templates**
2. Browse available templates
3. Click **Export ZIP** to download
4. In any WordPress site: **Elementor → Templates → Import** → upload the ZIP

## Build

For developers building distributable packages:

```powershell
# Configure
Copy-Item .env.build.example .env.build
# Edit .env.build with your PUBLIC_KEY

# Build
.\build.ps1

# Output: woo-elementor-ai-vX.X.X.zip
```

## License Key Generator (Separate Tool)

See `woo-ai-licensegen/` for the Go CLI tool used to generate license keys.

## Changelog

### 1.1.0
- Added: License system with domain-bound Ed25519 verification
- Added: Template library with ZIP export
- Added: Template admin page with grid browser
- Added: REST API endpoint for template export

### 1.0.0
- Initial release
- AI page generation
- AI element editing
- AI chat panel
- Image generation integration
```

**Step 2: Write Go tool README.md**

Create `woo-ai-licensegen/README.md`:

```markdown
# License Generator for Woo Elementor AI

Go CLI tool for generating Ed25519-based license keys bound to WordPress site Machine Keys.

## Setup

```bash
go build -o licensegen .
```

## Usage

### Generate Keypair (one-time)

```bash
./licensegen keygen
```

Creates `keys/private.key` and `keys/public.key`. **Never share `private.key`.**

### Sign a Machine Key

```bash
./licensegen sign --machine="a1b2c3d4e5f6..."
```

Outputs a base64-encoded license key string. Give this to the customer.

### Display Public Key

```bash
./licensegen pubkey
```

Copy this into the plugin's `.env.build` as `PUBLIC_KEY=<base64 string>`.

## Cross-Platform Build

```bash
GOOS=windows GOARCH=amd64 go build -o licensegen.exe .
GOOS=linux   GOARCH=amd64 go build -o licensegen .
GOOS=darwin  GOARCH=arm64 go build -o licensegen-mac .
```

## Security

- Private key only exists in `keys/private.key` (gitignored)
- Public key embedded in plugin for offline verification
- License keys are cryptographically signed (Ed25519)
- Keys are domain-bound via Machine Key hash

## How It Works

1. WordPress plugin computes **Machine Key** from `SHA-256(domain|ABSPATH|DB_NAME|AUTH_KEY)`
2. Customer provides Machine Key to developer
3. Developer runs `licensegen sign --machine=XXX` → outputs License Key
4. Customer enters License Key in plugin settings
5. Plugin verifies offline using embedded public key
```

**Step 3: Commit**

```bash
git add README.md woo-ai-licensegen/README.md
git commit -m "docs: add README for plugin and license generator"
```

---

## Task Dependency Graph

```
Phase 1 (Go Tool — Independent):
  Task 1 → Task 2 → Task 3 → Task 4

Phase 2 (PHP License — Sequential):
  Task 5 → Task 6 → Task 7 → Task 8

Phase 3 (Templates — After License):
  Task 9 → Task 10 → Task 11 → Task 12 → Task 13 → Task 14

Phase 4 (Build + Docs — After All):
  Task 15 → Task 16 → Task 17
```

**Parallelizable**: Phase 1 (Go) and Phase 2 (PHP License) can run simultaneously.

## Testing Checklist

After all tasks complete:

- [ ] Go tool: `./licensegen keygen` → creates keypair
- [ ] Go tool: `./licensegen sign --machine=TESTKEY` → outputs license string
- [ ] Go tool: `./licensegen pubkey` → outputs public key
- [ ] Plugin: Settings page shows Machine Key
- [ ] Plugin: Entering valid license → activates
- [ ] Plugin: Entering wrong license → fails
- [ ] Plugin: After activation → all settings visible
- [ ] Plugin: Deactivate → settings locked again
- [ ] Templates: Admin page shows template grid
- [ ] Templates: Export ZIP downloads correctly
- [ ] Templates: ZIP imports into Elementor successfully
- [ ] Build: `.\build.ps1` → reads .env.build → creates dist ZIP
- [ ] README: Plugin README.md present
- [ ] README: Go tool README.md present
