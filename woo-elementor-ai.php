<?php
/**
 * Plugin Name: Woo Elementor AI
 * Plugin URI:  https://github.com/brainplusplus/woo-elementor-ai
 * Description: AI-powered page generation, editing, and chat for Elementor using OpenAI-compatible APIs with flexible image generation support.
 * Version:     1.1.0
 * Author:      Developer
 * Text Domain: woo-elementor-ai
 * Domain Path: /languages
 * License:     GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined("ABSPATH")) {
    exit();
}

define("WOO_ELEMENTOR_AI_VERSION", "1.1.0");
define("WOO_ELEMENTOR_AI_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("WOO_ELEMENTOR_AI_PLUGIN_URL", plugin_dir_url(__FILE__));
define("WOO_ELEMENTOR_AI_PLUGIN_BASENAME", plugin_basename(__FILE__));

function woo_elementor_ai_is_elementor_active(): bool
{
    return did_action("elementor/loaded") || class_exists("\Elementor\Plugin");
}

function woo_elementor_ai_missing_elementor_notice(): void
{
    ?>
    <div class="notice notice-error">
        <p>
            <strong><?php esc_html_e(
                "Woo Elementor AI",
                "woo-elementor-ai",
            ); ?></strong>
            <?php esc_html_e(
                "requires Elementor to be installed and active.",
                "woo-elementor-ai",
            ); ?>
        </p>
    </div>
    <?php
}

function woo_elementor_ai_init(): void
{
    if (!woo_elementor_ai_is_elementor_active()) {
        add_action(
            "admin_notices",
            "woo_elementor_ai_missing_elementor_notice",
        );
        return;
    }

    require_once WOO_ELEMENTOR_AI_PLUGIN_DIR . "includes/class-plugin.php";
    \WooElementorAI\Plugin::get_instance();
}

add_action("plugins_loaded", "woo_elementor_ai_init");
