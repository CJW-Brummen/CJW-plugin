<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A standard checkbox field.
 *
 * Handles boolean-like settings stored in WordPress options.
 */
class CJW_Checkbox_Field extends CJW_Field
{
    /**
     * Renders the checkbox field HTML.
     *
     * @return void
     */
    public function render(): void
    {
        printf(
            '<input type="checkbox" name="%s" value="1" %s />',
            esc_attr($this->getName()),
            checked(1, $this->getValue(), false)
        );

        $this->renderDescription();
    }
}
