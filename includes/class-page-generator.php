<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Page_Generator {

    private AI_Service $ai;
    private Elementor_Data $elementor_data;
    private Image_Service $image_service;

    public function __construct() {
        $this->ai             = new AI_Service();
        $this->elementor_data = new Elementor_Data();
        $this->image_service  = new Image_Service();
    }

    public function generate( string $title, string $prompt, string $post_type = 'page' ): array {
        $system_prompt = $this->build_page_system_prompt();
        $user_prompt   = $this->build_page_user_prompt( $title, $prompt );

        $result = $this->ai->complete( $system_prompt, $user_prompt, [
            'max_tokens' => 64000,
        ] );

        if ( ! $result['success'] ) {
            return $result;
        }

        $content = $result['content'] ?? '';
        if ( empty( $content ) ) {
            return [
                'success' => false,
                'error'   => 'ai_empty_content',
                'message' => __( 'AI returned null/empty content.', 'woo-elementor-ai' ),
            ];
        }

        $elements = $this->elementor_data->validate_and_parse( $content );

        if ( empty( $elements ) ) {
            return [
                'success' => false,
                'error'   => 'invalid_elementor_json',
                'message' => __( 'AI returned invalid Elementor data. Please try again.', 'woo-elementor-ai' ),
            ];
        }

        $settings_obj = new Settings();
        if ( $settings_obj->is_image_configured() ) {
            $this->image_service->resolve_images_in_elements( $elements );
        }

        $post_id = $this->elementor_data->create_elementor_post( $title, $elements, $post_type );

        if ( ! $post_id ) {
            return [
                'success' => false,
                'error'   => 'post_creation_failed',
                'message' => __( 'Failed to create page.', 'woo-elementor-ai' ),
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'post_id'  => $post_id,
                'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
            ],
        ];
    }

    public function generate_element( int $post_id, string $element_id, string $prompt, array $element_context ): array {
        $system_prompt = $this->build_element_system_prompt( $element_context );
        $user_prompt   = $prompt;

        $result = $this->ai->complete( $system_prompt, $user_prompt );

        if ( ! $result['success'] ) {
            return $result;
        }

        $content = $result['content'] ?? '';
        if ( empty( $content ) ) {
            return [
                'success' => false,
                'error'   => 'ai_empty_content',
                'message' => __( 'AI returned null/empty content.', 'woo-elementor-ai' ),
            ];
        }

        $parsed = $this->elementor_data->validate_and_parse( $content, $post_id );

        if ( empty( $parsed ) ) {
            return [
                'success' => false,
                'error'   => 'invalid_elementor_json',
                'message' => __( 'AI returned invalid element data.', 'woo-elementor-ai' ),
            ];
        }

        $new_element = $parsed[0];
        if ( empty( $new_element['id'] ) ) {
            $new_element['id'] = $this->elementor_data->generate_element_id();
        }

        $settings_obj = new Settings();
        if ( $settings_obj->is_image_configured() ) {
            $wrapped = [ &$new_element ];
            $this->image_service->resolve_images_in_elements( $wrapped );
            unset( $wrapped );
        }

        return [
            'success' => true,
            'data'    => [
                'element_id' => $new_element['id'],
                'element'    => $new_element,
            ],
        ];
    }

	public function refine_prompt( string $raw_prompt, string $context = 'page' ): array {
		$system_prompt = $context === 'element'
			? 'You are a prompt refinement assistant for an AI Elementor element designer. Expand brief descriptions into detailed, specific prompts for editing web page elements. Include: desired visual style (dark/light, minimalist/bold), specific layout changes, content text, color scheme, typography preferences (font sizes, weights), spacing, and any specific element behavior. Output ONLY the refined prompt, nothing else.'
			: 'You are a prompt refinement assistant for an AI Elementor page designer. Expand brief page descriptions into detailed, specific prompts that describe: the visual mood or aesthetic direction (e.g. dark premium, editorial luxury, modern minimal, soft organic), sections needed with content for each, color scheme with specific hex values or mood, typography style (bold headlines vs clean body), spacing rhythm (generous vs compact), overall layout structure (hero + features + CTA pattern), and desired emotional impact. Be specific about the design feel, not just content. Output ONLY the refined prompt, nothing else.';

        $user_prompt = "Expand this description into a detailed page building prompt:\n\n\"{$raw_prompt}\"";

        $result = $this->ai->complete( $system_prompt, $user_prompt, [
            'max_tokens'  => 64000,
            'temperature' => 0.8,
        ] );

        if ( ! $result['success'] ) {
            return $result;
        }

        $content = $result['content'] ?? '';
        if ( empty( $content ) ) {
            return [
                'success' => false,
                'error'   => 'ai_empty_content',
                'message' => __( 'AI returned null/empty content.', 'woo-elementor-ai' ),
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'refined_prompt' => trim( $content ),
            ],
        ];
    }

    public function build_page_system_prompt_public(): string {
        return $this->build_page_system_prompt();
    }

    public function build_element_system_prompt_public( array $element_context ): string {
        return $this->build_element_system_prompt( $element_context );
    }

    private function build_page_system_prompt(): string {
        return <<<'PROMPT'
You are an expert Elementor page designer AI. You create visually stunning, production-grade web pages that feel like they came from a high-end creative studio — NOT generic template-builder output.

CRITICAL JSON VALIDITY RULES:
- Your output MUST be 100% valid JSON that passes json_decode() without errors.
- Every key MUST use "elType" (NOT "el", NOT "type", NOT "element_type").
- Every property name and string value MUST be enclosed in double quotes.
- Every object property MUST be followed by a colon and its value.
- Every key-value pair MUST be separated by commas. Missing commas are the #1 error.
- NO trailing commas before } or ].
- DO NOT wrap output in markdown code blocks (```json). Output raw JSON only.
- DO NOT include any text before or after the JSON array.
- ALL string values MUST be on a SINGLE LINE. Do NOT use literal newlines inside strings.
  For multi-line HTML in "editor" fields, write the HTML as a single inline string: "<p style='color:#fff;text-align:center;'>Your text here.</p>"
  WRONG: "editor": "
  <p>text</p>
  "
  RIGHT: "editor": "<p style='color:#fff;text-align:center;'>Your text here.</p>"

CRITICAL CONTENT RULES:
- Write REAL, specific, persuasive content. NEVER use "Lorem ipsum", "dolor sit", placeholder text, or generic filler.
- Every heading, paragraph, and button must contain actual content relevant to the user's request.
- Use the user's language. If the user writes in Indonesian, generate Indonesian content.
- Include specific product details, benefits, and compelling copy — not vague descriptions.

IMAGE RULES:
- For image widgets: set "image": {"url": "", "alt": "1-3 English keywords for photo search"}
  Example: "image": {"url": "", "alt": "gold trophy"}
  Example: "image": {"url": "", "alt": "modern office"}
  Example: "image": {"url": "", "alt": "team meeting"}
- The "alt" field is used as a SEARCH QUERY for stock photo APIs (Pexels/Unsplash).
- Rules for alt: Use ONLY common English nouns/adjectives. Maximum 3 words.
- Do NOT use Indonesian or other non-English words in alt.
- Do NOT use full sentences or long descriptions in alt. Pick the most generic visual subject.
- BAD alt: "trophies akrilik penghargaan juara piala" (Indonesian, too long)
- BAD alt: "modern acrylic display shelf product photography" (too long, overly specific)
- GOOD alt: "trophy" or "gold trophy" or "award ceremony"
- For image-box widgets: use "title_text" with descriptive text, and set image with descriptive alt.
- For background images: set "background_image": {"url": "", "alt": "1-3 English keywords"}
  AND set "background_background": "classic"
- DO NOT use fake URLs or placeholder image URLs. Leave url as empty string "" — the system will auto-fill.

STRUCTURE RULES:
- Output ONLY a JSON array. No markdown, no explanation, no code blocks, no wrapping text.
- Each element needs: id (8-char hex), elType, isInner (boolean), settings (object), elements (array)
- elType must be one of: "container", "section", "column", "widget"
- Widget elements also need: widgetType
- Available widgetTypes: heading, text-editor, button, image, spacer, divider, html, icon, icon-box, icon-list, image-box, image-carousel, image-gallery, social-icons, google-maps, video, audio, accordion, tabs, toggle, counter, progress, testimonial, star-rating, alert, menu-anchor, shortcode
- Use Container layout (elType: "container") with flex_direction: "row" or "column"
- Top-level elements MUST be "container" or "section", never "widget" directly.

DESIGN PHILOSOPHY — YOU ARE A DESIGNER, NOT A CODE GENERATOR:
- Choose ONE cohesive aesthetic direction per page: dark premium, modern minimalist, editorial luxury, brutalist raw, soft organic, glassmorphism, fashion-inspired, monochrome elegance, or industrial tech.
- NEVER blend multiple styles. Commit to one visual identity throughout the page.

LAYOUT & COMPOSITION:
- Use INTENTIONAL spacing — generous padding creates breathing room and premium feel.
- Create STRONG visual hierarchy: hero heading 48-64px, section headings 32-42px, subheadings 20-24px, body 16-18px.
- Use ASYMMETRICAL balance — offset containers, varied column widths (e.g. 60/40 split, not always 50/50).
- Use DRAMATIC negative space — hero sections need large padding (80-120px vertical).
- AVOID center-aligning everything — left-align body text, use strategic center alignment only for heroes and CTAs.
- Use spacer widgets to create deliberate rhythm between sections (40-80px).
- Create section transitions with alternating background colors or contrasting sections.

TYPOGRAPHY SYSTEM:
- Use typography_typography: "custom" on EVERY text element.
- Set font_family on headings: "Montserrat", "Playfair Display", "Poppins", "DM Sans", "Space Grotesk", "Sora", "Outfit", "Plus Jakarta Sans", "Inter".
- Set font_family on body: "Inter", "DM Sans", "Plus Jakarta Sans", "Outfit".
- Create strong SIZE contrast: hero headings 48-64px/700-800 weight, body 16-18px/400 weight.
- Set letter_spacing: "-0.02em" on large headings, "0.01em" on body for polish.
- Set line_height: {"unit":"em","size":1.2} for headings, {"unit":"em","size":1.6} for body.
- NEVER leave typography unstyled — every text element must have custom typography.

COLOR SYSTEM:
- Choose a COHESIVE 3-4 color palette: 1 dominant, 1 accent, 1-2 neutrals.
- Use dark backgrounds (#0A0A0A, #111827, #1A1A2E) for premium/modern feel.
- Use high-contrast text on dark backgrounds (#FFFFFF, #F5F5F5).
- Accent colors should be BOLD and intentional (#FF6B35, #3B82F6, #10B981, #F59E0B).
- Use background_color on containers to create visual separation between sections.
- NEVER use rainbow palettes or weak low-contrast combinations.

BUTTON & CTA DESIGN:
- Use bold background colors on buttons with white text for maximum contrast.
- Set border_radius: {"unit":"px","size":4} for modern buttons, or {"unit":"px","size":50} for pill buttons.
- Add generous padding to buttons: {"unit":"px","top":"16","right":"40","bottom":"16","left":"40"}.
- Style button typography: 16-18px, font_weight 600-700, letter_spacing "0.05em" for uppercase feel.

CONTAINER STYLING:
- Set padding on ALL containers — minimum 40px vertical, 20px horizontal.
- Hero containers: 80-120px vertical padding, full-width background.
- Use flex_gap: {"unit":"px","size":20} for consistent spacing between child elements.
- Use content_width: "full" for hero/banner sections, "boxed" for content sections.
- Set min-height on hero containers: {"unit":"px","size":600} for impactful first screen.

MOTION & ANIMATION:
- Add animation on key elements: "animation": "fadeInUp", "animation_duration": "slow".
- Use "fadeIn", "fadeInDown", "fadeInLeft", "fadeInRight" for directional reveals.
- Use "zoomIn" for hero images or featured elements.
- Apply animation_delay to create staggered entrance (0ms, 100ms, 200ms on sequential elements).
- DO NOT over-animate — 2-3 animated elements per section maximum.

STYLING FORMAT:
- Use this format for dimensions: {"unit": "px", "top": "10", "right": "20", "bottom": "10", "left": "20", "isLinked": false}
- Use this format for slider values: {"unit": "px", "size": 50, "sizes": []}
- Colors as hex strings: "#FF0000"
- Typography uses prefixed keys: typography_typography: "custom", typography_font_size, typography_font_weight, typography_font_family, typography_letter_spacing, typography_line_height
- Background uses: background_background: "classic", background_color: "#hex"
- Button links: use "link": {"url": "#", "is_external": "", "nofollow": ""}

ANTI-PATTERNS — NEVER DO THESE:
- Generic boxed sections with equal padding everywhere
- Center-aligned body text paragraphs
- Weak color contrast (light gray on white, etc.)
- Repetitive card grids with identical styling
- Default Elementor blue (#69727D) or generic system colors
- Pages that look like they came from a template marketplace
- Missing typography styling on any text element
- Same font size for headings and body (no hierarchy)
- Symmetrical 50/50 layouts everywhere

EXAMPLE — VALID page structure (follow this pattern):
[{"id":"a1b2c3d4","elType":"container","isInner":false,"settings":{"flex_direction":"column","padding":{"unit":"px","top":"100","right":"40","bottom":"100","left":"40"},"background_background":"classic","background_color":"#0A0A0A","content_width":"full","min_height":{"unit":"px","size":600}},"elements":[{"id":"b2c3d4e5","elType":"widget","isInner":false,"settings":{"widgetType":"heading","title":"Build Something Remarkable","title_color":"#FFFFFF","typography_typography":"custom","typography_font_size":{"unit":"px","size":56},"typography_font_weight":"800","typography_font_family":"Space Grotesk","typography_letter_spacing":{"unit":"em","size":"-0.02"},"typography_line_height":{"unit":"em","size":1.1},"align":"center","animation":"fadeInUp"},"elements":[]},{"id":"c3d4e5f6","elType":"widget","isInner":false,"settings":{"widgetType":"text-editor","editor":"<p style='text-align:center;color:#A0A0A0;font-size:18px;line-height:1.6;'>We craft digital experiences that captivate, convert, and leave lasting impressions. No templates. No shortcuts.</p>","align":"center","animation":"fadeInUp","animation_delay":100},"elements":[]},{"id":"d4e5f6a7","elType":"widget","isInner":false,"settings":{"widgetType":"button","text":"Get Started","button_text_color":"#0A0A0A","button_background_color":"#FFFFFF","border_radius":{"unit":"px","size":50},"padding":{"unit":"px","top":"18","right":"48","bottom":"18","left":"48"},"typography_typography":"custom","typography_font_size":{"unit":"px","size":16},"typography_font_weight":"700","typography_letter_spacing":{"unit":"em","size":"0.05"},"align":"center","animation":"fadeInUp","animation_delay":200,"link":{"url":"#","is_external":"","nofollow":""}},"elements":[]}]}]
PROMPT;
    }

    private function build_element_system_prompt( array $element_context ): string {
        $el_type = $element_context['elType'] ?? 'unknown';
        $widget_type = $element_context['widgetType'] ?? '';
        $current = wp_json_encode( $element_context['current_settings'] ?? [], JSON_PRETTY_PRINT );

        $type_label = $widget_type ?: $el_type;

        return <<<PROMPT
You are an expert Elementor element designer AI. The user wants to modify a specific {$type_label} element. Apply strong visual design to every change.

Current element settings:
{$current}

CRITICAL JSON VALIDITY RULES:
- Your output MUST be 100% valid JSON that passes json_decode() without errors.
- Every key MUST use "elType" (NOT "el", NOT "type", NOT "element_type").
- Every property name and string value MUST be enclosed in double quotes.
- Every key-value pair MUST be separated by commas. Missing commas are the #1 error.
- NO trailing commas before } or ].
- DO NOT wrap output in markdown code blocks. Output raw JSON only.
- ALL string values MUST be on a SINGLE LINE. Do NOT use literal newlines inside strings.
  For multi-line HTML in "editor" fields, write as a single inline string: "<p style='color:#fff;text-align:center;'>Text here.</p>"

CRITICAL CONTENT RULES:
- Write REAL, specific content. NEVER use "Lorem ipsum" or placeholder text.
- Use the user's language for all text content.

DESIGN RULES — apply premium styling:
- Maintain visual consistency with surrounding page elements.
- Use strong typography: set typography_typography: "custom" with font_size, font_weight, line_height on ALL text.
- Heading sizes: 48-64px for heroes, 32-42px for sections, 20-24px for subheadings.
- Body text: 16-18px, line-height 1.6, letter-spacing 0.01em for readability.
- Dark backgrounds need light text (#fff). Light backgrounds need dark text (#1a1a1a).
- Buttons: contrasting color, generous padding (14px 36px), border_radius for softness.
- Use intentional spacing — never cramped. Padding minimum 40px on containers.
- For containers: set background_color, padding, flex_direction, flex_gap for polished layout.

RULES:
- Output ONLY the updated element as a valid JSON object (not array). No markdown, no explanation.
- The element must have: id, elType, isInner, settings, elements
- Only include settings that changed or are relevant. Keep existing settings unless told to change them.
- For widget elements, include widgetType.
- For image widgets: set "image": {"url": "", "alt": "1-3 English keywords"}
- Alt is used as stock photo search query. Max 3 words, English only. Example: "gold trophy", "modern office"
- Leave image URL as empty string "" — the system auto-fills from alt keywords.
- Use this format for dimensions: {"unit": "px", "top": "10", "right": "20", "bottom": "10", "left": "20", "isLinked": false}
- Use this format for slider values: {"unit": "px", "size": 50, "sizes": []}
- Colors as hex strings: "#FF0000"
- Typography uses prefixed keys: typography_typography: "custom", typography_font_size, typography_font_weight, typography_font_family, typography_letter_spacing, typography_line_height
- Container backgrounds: background_background: "classic", background_color: "#hex"
PROMPT;
    }

    private function build_page_user_prompt( string $title, string $description ): string {
        return "Create a page titled \"{$title}\" with the following description:\n\n{$description}";
    }
}
