<div id="woo-ai-chat-panel" class="woo-ai-chat-panel" style="display:none;">
    <div class="woo-ai-chat-header">
        <span class="woo-ai-chat-title">AI Chat</span>
        <div class="woo-ai-chat-header-actions">
            <select id="woo-ai-chat-method" class="woo-ai-chat-context" title="Copywriting Method">
                <option value="">Free style</option>
                <option value="AIDA" selected>AIDA</option>
                <option value="PAS">PAS</option>
                <option value="FAB">FAB</option>
                <option value="BAB">BAB</option>
                <option value="4Ps">4Ps</option>
                <option value="QUEST">QUEST</option>
                <option value="PASTOR">PASTOR</option>
                <option value="SSS">SSS</option>
            </select>
            <select id="woo-ai-chat-lang" class="woo-ai-chat-context" title="Language">
                <option value="id" selected>ID</option>
                <option value="en">EN</option>
                <option value="mixed">Mix</option>
            </select>
            <select id="woo-ai-chat-context" class="woo-ai-chat-context">
                <option value="page"><?php esc_html_e( 'Page', 'woo-elementor-ai' ); ?></option>
                <option value="section"><?php esc_html_e( 'Section', 'woo-elementor-ai' ); ?></option>
                <option value="element"><?php esc_html_e( 'Widget', 'woo-elementor-ai' ); ?></option>
            </select>
            <button type="button" class="woo-ai-chat-btn" onclick="WooAiChat.clearChat()" title="<?php esc_attr_e( 'Clear chat', 'woo-elementor-ai' ); ?>">
                <span class="eicon-trash"></span>
            </button>
            <button type="button" class="woo-ai-chat-btn" onclick="WooAiChat.toggle()" title="<?php esc_attr_e( 'Close', 'woo-elementor-ai' ); ?>">
                <span class="eicon-close"></span>
            </button>
        </div>
    </div>
    <div class="woo-ai-chat-quick-actions">
        <button type="button" class="woo-ai-quick-btn" data-action="Add a hero section with heading, description and CTA button">+ Hero</button>
        <button type="button" class="woo-ai-quick-btn" data-action="Add a testimonials section with 3 testimonial cards">+ Testimonials</button>
        <button type="button" class="woo-ai-quick-btn" data-action="Change the color scheme to dark theme">Dark Theme</button>
        <button type="button" class="woo-ai-quick-btn" data-action="Improve the responsive layout for mobile devices">Fix Mobile</button>
    </div>
    <div id="woo-ai-chat-messages" class="woo-ai-chat-messages"></div>
    <div class="woo-ai-chat-input-area">
        <textarea id="woo-ai-chat-input" class="woo-ai-chat-input" placeholder="<?php esc_attr_e( 'Type your message...', 'woo-elementor-ai' ); ?>" rows="2"></textarea>
        <button type="button" class="woo-ai-chat-send" onclick="WooAiChat.send()" title="<?php esc_attr_e( 'Send', 'woo-elementor-ai' ); ?>">
            <span class="eicon-arrow-right"></span>
        </button>
    </div>
</div>
