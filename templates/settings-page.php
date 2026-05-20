<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$settings = $this->get_settings();
$license       = $this->get_license();
$machine_key   = $license->get_machine_key();
$is_licensed   = $license->is_licensed();
$masked_key    = $license->get_masked_license();
$license_nonce = wp_create_nonce( 'woo_elementor_ai_license_nonce' );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Woo Elementor AI — Settings', 'woo-elementor-ai' ); ?></h1>

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
	</div>

	<script>
	function wooAiCopyMachineKey() {
		var field = document.getElementById('woo_ai_machine_key');
		field.select();
		document.execCommand('copy');
		alert('<?php echo esc_js( __( 'Machine key copied!', 'woo-elementor-ai' ) ); ?>');
	}

	function wooAiActivateLicense() {
		var btn = document.getElementById('woo-ai-activate-license');
		var result = document.getElementById('woo-ai-license-result');
		var key = document.getElementById('woo_ai_license_key').value.trim();
		if (!key) { result.innerHTML = '<span style="color:red;"><?php echo esc_js( __( 'Enter a license key', 'woo-elementor-ai' ) ); ?></span>'; return; }
		btn.disabled = true;
		result.textContent = '<?php echo esc_js( __( 'Verifying...', 'woo-elementor-ai' ) ); ?>';

		var formData = new FormData();
		formData.append('action', 'woo_elementor_ai_activate_license');
		formData.append('nonce', '<?php echo esc_js( $license_nonce ); ?>');
		formData.append('license_key', key);

		fetch(ajaxurl, { method: 'POST', body: formData })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success) { location.reload(); }
			else { result.innerHTML = '<span style="color:red;">' + (data.data.message || '<?php echo esc_js( __( 'Activation failed', 'woo-elementor-ai' ) ); ?>') + '</span>'; }
		})
		.catch(function() { result.innerHTML = '<span style="color:red;"><?php echo esc_js( __( 'Network error', 'woo-elementor-ai' ) ); ?></span>'; })
		.finally(function() { btn.disabled = false; });
	}

	function wooAiDeactivateLicense() {
		if (!confirm('<?php echo esc_js( __( 'Deactivate license? Plugin features will be locked.', 'woo-elementor-ai' ) ); ?>')) return;
		var result = document.getElementById('woo-ai-license-result');

		var formData = new FormData();
		formData.append('action', 'woo_elementor_ai_deactivate_license');
		formData.append('nonce', '<?php echo esc_js( $license_nonce ); ?>');

		fetch(ajaxurl, { method: 'POST', body: formData })
		.then(function(r) { return r.json(); })
		.then(function(data) { if (data.success) { location.reload(); } })
		.catch(function() { result.innerHTML = '<span style="color:red;"><?php echo esc_js( __( 'Network error', 'woo-elementor-ai' ) ); ?></span>'; });
	}
	</script>

	<?php return; ?>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'woo_elementor_ai_settings_group' ); ?>

		<h2><?php esc_html_e( 'AI Chat Configuration', 'woo-elementor-ai' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="base_url"><?php esc_html_e( 'Base URL', 'woo-elementor-ai' ); ?></label></th>
				<td>
					<input type="url" id="base_url"
						   name="woo_elementor_ai_settings[base_url]"
						   value="<?php echo esc_attr( $settings['base_url'] ); ?>"
						   class="regular-text" placeholder="https://api.openai.com/v1">
					<p class="description"><?php esc_html_e( 'Include version path (e.g. /v1 or /api/paas/v4). Endpoint /chat/completions is appended automatically.', 'woo-elementor-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="api_key"><?php esc_html_e( 'API Key', 'woo-elementor-ai' ); ?></label></th>
				<td>
					<div style="display:flex;align-items:center;gap:8px;">
						<input type="password" id="api_key"
							   name="woo_elementor_ai_settings[api_key]"
							   value="<?php echo esc_attr( $settings['api_key'] ); ?>"
							   class="regular-text">
						<button type="button" class="button" onclick="wooAiToggleKey('api_key')">
							<span class="dashicons dashicons-visibility"></span>
						</button>
						<button type="button" class="button" id="woo-ai-test-chat" onclick="wooAiTestChat()">
							<?php esc_html_e( 'Test Connection', 'woo-elementor-ai' ); ?>
						</button>
					</div>
					<span id="woo-ai-test-chat-result" style="margin-left:8px;"></span>
				</td>
			</tr>
			<tr>
				<th><label for="model"><?php esc_html_e( 'Model', 'woo-elementor-ai' ); ?></label></th>
				<td>
					<input type="text" id="model"
						   name="woo_elementor_ai_settings[model]"
						   value="<?php echo esc_attr( $settings['model'] ); ?>"
						   class="regular-text" list="chat-models">
					<datalist id="chat-models">
						<option value="gpt-4o">
						<option value="gpt-4o-mini">
						<option value="gpt-4-turbo">
						<option value="gpt-3.5-turbo">
						<option value="claude-3-opus-20240229">
						<option value="claude-3-sonnet-20240229">
					</datalist>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'AI Processing Mode', 'woo-elementor-ai' ); ?></label></th>
				<td>
					<fieldset>
						<label style="display:block;margin-bottom:8px;">
							<input type="radio" name="woo_elementor_ai_settings[ai_processing_mode]" value="curl"
								<?php checked( $settings['ai_processing_mode'], 'curl' ); ?>>
							<strong>cURL (PHP Native)</strong>
							<p style="margin:2px 0 0 22px;color:#666;">PHP curl extension. Default, works everywhere.</p>
						</label>
						<label style="display:block;margin-bottom:8px;">
							<input type="radio" name="woo_elementor_ai_settings[ai_processing_mode]" value="exec_curl"
								<?php checked( $settings['ai_processing_mode'], 'exec_curl' ); ?>>
							<strong>Exec cURL (Shell Process)</strong>
							<p style="margin:2px 0 0 22px;color:#666;">Bypasses PHP-FPM timeouts via proc_open. Best for CloudPanel/Nginx.</p>
						</label>
						<label style="display:block;margin-bottom:8px;">
							<input type="radio" name="woo_elementor_ai_settings[ai_processing_mode]" value="frontend"
								<?php checked( $settings['ai_processing_mode'], 'frontend' ); ?>>
							<strong>Frontend (Client-Side)</strong>
							<p style="margin:2px 0 0 22px;color:#666;">Browser fetches AI API directly. Zero PHP blocking. API key visible in browser (admin only).</p>
						</label>
					</fieldset>
				</td>
			</tr>
		</table>

		<hr>

		<h2><?php esc_html_e( 'Image Generation', 'woo-elementor-ai' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="image_source"><?php esc_html_e( 'Image Source', 'woo-elementor-ai' ); ?></label></th>
				<td>
					<select id="image_source" name="woo_elementor_ai_settings[image_source]"
							onchange="wooAiToggleImageSections(this.value)">
						<option value="none" <?php selected( $settings['image_source'], 'none' ); ?>>
							<?php esc_html_e( 'None', 'woo-elementor-ai' ); ?>
						</option>
						<option value="unsplash" <?php selected( $settings['image_source'], 'unsplash' ); ?>>
							<?php esc_html_e( 'Unsplash', 'woo-elementor-ai' ); ?>
						</option>
						<option value="pexels" <?php selected( $settings['image_source'], 'pexels' ); ?>>
							<?php esc_html_e( 'Pexels', 'woo-elementor-ai' ); ?>
						</option>
						<option value="openai_compatible" <?php selected( $settings['image_source'], 'openai_compatible' ); ?>>
							<?php esc_html_e( 'OpenAI Compatible', 'woo-elementor-ai' ); ?>
						</option>
					</select>
				</td>
			</tr>
		</table>

		<div id="woo-ai-image-unsplash" class="woo-ai-image-section" style="display:none;">
			<table class="form-table">
				<tr>
					<th><label for="unsplash_api_key"><?php esc_html_e( 'Unsplash API Key', 'woo-elementor-ai' ); ?></label></th>
					<td>
						<input type="password" id="unsplash_api_key"
							   name="woo_elementor_ai_settings[unsplash_api_key]"
							   value="<?php echo esc_attr( $settings['unsplash_api_key'] ); ?>"
							   class="regular-text">
						<p class="description">
							<?php
							printf(
								esc_html__( 'Get your free API key at %s', 'woo-elementor-ai' ),
								'<a href="https://unsplash.com/developers" target="_blank">unsplash.com/developers</a>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div id="woo-ai-image-pexels" class="woo-ai-image-section" style="display:none;">
			<table class="form-table">
				<tr>
					<th><label for="pexels_api_key"><?php esc_html_e( 'Pexels API Key', 'woo-elementor-ai' ); ?></label></th>
					<td>
						<input type="password" id="pexels_api_key"
							   name="woo_elementor_ai_settings[pexels_api_key]"
							   value="<?php echo esc_attr( $settings['pexels_api_key'] ); ?>"
							   class="regular-text">
						<p class="description">
							<?php
							printf(
								esc_html__( 'Get your free API key at %s', 'woo-elementor-ai' ),
								'<a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div id="woo-ai-image-openai_compatible" class="woo-ai-image-section" style="display:none;">
			<table class="form-table">
				<tr>
					<th><label for="image_base_url"><?php esc_html_e( 'Base URL', 'woo-elementor-ai' ); ?></label></th>
					<td>
						<input type="url" id="image_base_url"
							   name="woo_elementor_ai_settings[image_base_url]"
							   value="<?php echo esc_attr( $settings['image_base_url'] ); ?>"
							   class="regular-text" placeholder="https://openrouter.ai/api/v1">
					</td>
				</tr>
				<tr>
					<th><label for="image_api_key"><?php esc_html_e( 'API Key', 'woo-elementor-ai' ); ?></label></th>
					<td>
						<div style="display:flex;align-items:center;gap:8px;">
							<input type="password" id="image_api_key"
								   name="woo_elementor_ai_settings[image_api_key]"
								   value="<?php echo esc_attr( $settings['image_api_key'] ); ?>"
								   class="regular-text">
							<button type="button" class="button" onclick="wooAiToggleKey('image_api_key')">
								<span class="dashicons dashicons-visibility"></span>
							</button>
						</div>
					</td>
				</tr>
				<tr>
					<th><label for="image_model"><?php esc_html_e( 'Model', 'woo-elementor-ai' ); ?></label></th>
					<td>
						<input type="text" id="image_model"
							   name="woo_elementor_ai_settings[image_model]"
							   value="<?php echo esc_attr( $settings['image_model'] ); ?>"
							   class="regular-text" list="image-models">
						<datalist id="image-models">
							<option value="dall-e-3">
							<option value="dall-e-2">
							<option value="stable-diffusion-xl">
						</datalist>
					</td>
				</tr>
				<tr>
					<th><label for="image_endpoint"><?php esc_html_e( 'Image Endpoint', 'woo-elementor-ai' ); ?></label></th>
					<td>
						<input type="text" id="image_endpoint"
							   name="woo_elementor_ai_settings[image_endpoint]"
							   value="<?php echo esc_attr( $settings['image_endpoint'] ); ?>"
							   class="regular-text" placeholder="/v1/images/generations">
						<p class="description"><?php esc_html_e( 'Appended to Base URL. Default: /v1/images/generations', 'woo-elementor-ai' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<hr>

		<h2><?php esc_html_e( 'Generation Defaults', 'woo-elementor-ai' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="max_tokens"><?php esc_html_e( 'Max Tokens', 'woo-elementor-ai' ); ?></label></th>
				<td>
					<input type="number" id="max_tokens"
						   name="woo_elementor_ai_settings[max_tokens]"
						   value="<?php echo esc_attr( $settings['max_tokens'] ); ?>"
						   min="256" max="128000" step="256" class="small-text">
				</td>
			</tr>
			<tr>
				<th><label for="temperature"><?php esc_html_e( 'Temperature', 'woo-elementor-ai' ); ?></label></th>
				<td>
					<input type="range" id="temperature"
						   name="woo_elementor_ai_settings[temperature]"
						   value="<?php echo esc_attr( $settings['temperature'] ); ?>"
						   min="0" max="2" step="0.1"
						   oninput="document.getElementById('temp-display').textContent=this.value">
					<span id="temp-display"><?php echo esc_html( $settings['temperature'] ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="chat_max_context"><?php esc_html_e( 'Chat Max Context (tokens)', 'woo-elementor-ai' ); ?></label></th>
				<td>
					<input type="number" id="chat_max_context"
						   name="woo_elementor_ai_settings[chat_max_context]"
						   value="<?php echo esc_attr( $settings['chat_max_context'] ); ?>"
						   min="1000" max="128000" step="1000" class="small-text">
				</td>
			</tr>
		</table>

		<hr>

		<h2><?php esc_html_e( 'Logging', 'woo-elementor-ai' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="log_purge_limit"><?php esc_html_e( 'Log Retention (rows)', 'woo-elementor-ai' ); ?></label></th>
				<td>
					<input type="number" id="log_purge_limit"
						   name="woo_elementor_ai_settings[log_purge_limit]"
						   value="<?php echo esc_attr( $settings['log_purge_limit'] ); ?>"
						   min="100" max="100000" step="100" class="small-text">
					<p class="description"><?php esc_html_e( 'Auto-purge oldest logs when exceeding this limit. Minimum 100.', 'woo-elementor-ai' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>

<script>
function wooAiToggleKey(fieldId) {
	var field = document.getElementById(fieldId);
	field.type = field.type === 'password' ? 'text' : 'password';
}

function wooAiToggleImageSections(source) {
	var sections = document.querySelectorAll('.woo-ai-image-section');
	sections.forEach(function(el) { el.style.display = 'none'; });
	var target = document.getElementById('woo-ai-image-' + source);
	if (target) target.style.display = 'block';
}

(function() {
	var source = document.getElementById('image_source');
	if (source) wooAiToggleImageSections(source.value);
})();

function wooAiTestChat() {
	var btn = document.getElementById('woo-ai-test-chat');
	var result = document.getElementById('woo-ai-test-chat-result');
	btn.disabled = true;
	result.textContent = '<?php echo esc_js( __( 'Testing...', 'woo-elementor-ai' ) ); ?>';

	fetch('<?php echo esc_url( rest_url( 'woo-elementor-ai/v1/settings/test' ) ); ?>', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
		}
	})
	.then(function(r) { return r.json(); })
	.then(function(data) {
		if (data.success) {
			result.innerHTML = '<span style="color:green;">&#10003; <?php echo esc_js( __( 'Connected!', 'woo-elementor-ai' ) ); ?></span>';
		} else {
			result.innerHTML = '<span style="color:red;">&#10007; ' + (data.data.message || '<?php echo esc_js( __( 'Failed', 'woo-elementor-ai' ) ); ?>') + '</span>';
		}
	})
	.catch(function() {
		result.innerHTML = '<span style="color:red;">&#10007; <?php echo esc_js( __( 'Network error', 'woo-elementor-ai' ) ); ?></span>';
	})
	.finally(function() { btn.disabled = false; });
}
</script>
