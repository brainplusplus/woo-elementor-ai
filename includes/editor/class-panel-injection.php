<?php
namespace WooElementorAI\Editor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Panel_Injection {

    public function __construct() {
        add_action( 'elementor/element/common/_section_style/after_section_end', [ $this, 'add_ai_controls' ], 10, 2 );
    }

    public function add_ai_controls( $element, $args ): void {
        $element->start_controls_section(
            'woo_ai_assistant_section',
            [
                'label' => __( 'AI Assistant', 'woo-elementor-ai' ),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $element->add_control(
            'woo_ai_prompt',
            [
                'label'       => __( 'Describe changes', 'woo-elementor-ai' ),
                'type'        => \Elementor\Controls_Manager::TEXTAREA,
                'rows'        => 3,
                'placeholder' => __( 'e.g., Make this heading bold and blue', 'woo-elementor-ai' ),
            ]
        );

        $element->add_control(
            'woo_ai_refine',
            [
                'type'  => \Elementor\Controls_Manager::BUTTON,
                'text'  => __( 'Refine Prompt', 'woo-elementor-ai' ),
                'event' => 'woo:ai:refine',
            ]
        );

        $element->add_control(
            'woo_ai_generate',
            [
                'type'        => \Elementor\Controls_Manager::BUTTON,
                'text'        => __( 'Generate', 'woo-elementor-ai' ),
                'button_type' => 'success',
                'event'       => 'woo:ai:generate',
            ]
        );

        $element->end_controls_section();
    }
}
