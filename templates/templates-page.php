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
					<?php if ( ! empty( $tpl['preview'] ) ) : ?>
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
	if (!confirm('<?php echo esc_js( __( 'Delete this template?', 'woo-elementor-ai' ) ); ?>')) return;
	btn.disabled = true;
	var formData = new FormData();
	formData.append('action', 'woo_elementor_ai_delete_template');
	formData.append('nonce', '<?php echo esc_js( $nonce ); ?>');
	formData.append('template_id', id);

	fetch(ajaxurl, { method: 'POST', body: formData })
	.then(function(r) { return r.json(); })
	.then(function(data) { if (data.success) { location.reload(); } })
	.finally(function() { btn.disabled = false; });
}
</script>
