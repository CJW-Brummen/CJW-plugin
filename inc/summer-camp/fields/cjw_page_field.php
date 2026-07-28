<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A dropdown field for selecting a WordPress page.
 */
class CJW_Page_Field extends CJW_Field
{
    /**
     * Renders the page dropdown HTML.
     *
     * @return void
     */
    public function render(): void
    {
        $dropdown = wp_dropdown_pages([
            'name' => esc_attr($this->getName()),
            'selected' => absint($this->getValue()),
            'show_option_none' => esc_html__('— Geen pagina —', 'cjw'),
            'option_none_value' => '0',
            'echo' => false,
        ]);

        echo $dropdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() returns safe markup.

        $this->renderDescription();
    }
}
