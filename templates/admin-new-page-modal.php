<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="woo-ai-modal-overlay" class="woo-ai-modal-overlay" style="display:none;">
    <div class="woo-ai-modal">
        <div class="woo-ai-modal-header">
            <h2 id="woo-ai-modal-title"><?php esc_html_e( 'Generate with AI', 'woo-elementor-ai' ); ?></h2>
            <button type="button" class="woo-ai-modal-close" onclick="wooAiModal.close()">&times;</button>
        </div>
        <div class="woo-ai-modal-body">
            <div class="woo-ai-field-row">
                <div class="woo-ai-field woo-ai-field-half">
                    <label for="woo-ai-copywriting-method"><?php esc_html_e( 'Copywriting Method', 'woo-elementor-ai' ); ?></label>
                    <select id="woo-ai-copywriting-method" class="woo-ai-select">
                        <option value=""><?php esc_html_e( 'None — Free style', 'woo-elementor-ai' ); ?></option>
                        <option value="AIDA" selected>AIDA — <?php esc_html_e( 'Fondasi klasik, serba guna', 'woo-elementor-ai' ); ?></option>
                        <option value="PAS">PAS — <?php esc_html_e( 'Problem, Agitate, Solution', 'woo-elementor-ai' ); ?></option>
                        <option value="FAB">FAB — <?php esc_html_e( 'Features, Advantages, Benefits', 'woo-elementor-ai' ); ?></option>
                        <option value="BAB">BAB — <?php esc_html_e( 'Before, After, Bridge', 'woo-elementor-ai' ); ?></option>
                        <option value="4Ps">4Ps — <?php esc_html_e( 'Promise, Picture, Proof, Push', 'woo-elementor-ai' ); ?></option>
                        <option value="QUEST">QUEST — <?php esc_html_e( 'Long-form sales page', 'woo-elementor-ai' ); ?></option>
                        <option value="ACCA">ACCA — <?php esc_html_e( 'Awareness, Comprehension, Conviction, Action', 'woo-elementor-ai' ); ?></option>
                        <option value="PASTOR">PASTOR — <?php esc_html_e( 'Problem-solving copy', 'woo-elementor-ai' ); ?></option>
                        <option value="SSS">SSS — <?php esc_html_e( 'Star, Story, Solution', 'woo-elementor-ai' ); ?></option>
                        <option value="SLAP">SLAP — <?php esc_html_e( 'Stop, Look, Act, Purchase', 'woo-elementor-ai' ); ?></option>
                    </select>
                </div>
                <div class="woo-ai-field woo-ai-field-half">
                    <label for="woo-ai-language"><?php esc_html_e( 'Language', 'woo-elementor-ai' ); ?></label>
                    <select id="woo-ai-language" class="woo-ai-select">
                        <option value="id" selected><?php esc_html_e( 'Indonesian', 'woo-elementor-ai' ); ?></option>
                        <option value="en"><?php esc_html_e( 'English', 'woo-elementor-ai' ); ?></option>
                        <option value="mixed"><?php esc_html_e( 'Mixed (Bilingual)', 'woo-elementor-ai' ); ?></option>
                    </select>
                </div>
            </div>
            <div class="woo-ai-field">
                <label for="woo-ai-page-title"><?php esc_html_e( 'Title', 'woo-elementor-ai' ); ?></label>
                <input type="text" id="woo-ai-page-title" class="woo-ai-input" placeholder="">
            </div>
            <div class="woo-ai-field">
                <label for="woo-ai-prompt"><?php esc_html_e( 'Describe your page:', 'woo-elementor-ai' ); ?></label>
                <textarea id="woo-ai-prompt" class="woo-ai-textarea" rows="6" placeholder="<?php esc_attr_e( 'e.g., Landing page for a modern cafe with hero, menu, testimonials, and contact section...', 'woo-elementor-ai' ); ?>"></textarea>
                <button type="button" id="woo-ai-refine-btn" class="woo-ai-refine-btn" onclick="wooAiModal.refine()">
                    <span class="dashicons dashicons-star-filled"></span>
                    <?php esc_html_e( 'Refine', 'woo-elementor-ai' ); ?>
                </button>
            </div>
        </div>
        <div class="woo-ai-modal-footer">
            <div id="woo-ai-status" class="woo-ai-status"></div>
            <div class="woo-ai-modal-actions">
                <button type="button" class="button" onclick="wooAiModal.close()"><?php esc_html_e( 'Cancel', 'woo-elementor-ai' ); ?></button>
                <button type="button" id="woo-ai-generate-btn" class="button button-primary" onclick="wooAiModal.generate()">
                    <?php esc_html_e( 'Generate', 'woo-elementor-ai' ); ?>
                </button>
            </div>
        </div>
    </div>
</div>
