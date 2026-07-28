<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A specialized field for date inputs.
 *
 * Automatically attaches the 'cjw-datepicker' class for Flatpickr integration.
 */
class CJW_Date_Field extends CJW_Field
{
    /**
     * Renders the date input field HTML.
     *
     * @return void
     */
    public function render(): void
    {
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text cjw-datepicker" />',
            esc_attr($this->getName()),
            esc_attr((string) $this->getValue())
        );

        $this->renderDescription();
    }
}
