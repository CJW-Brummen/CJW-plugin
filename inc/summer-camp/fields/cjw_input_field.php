<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A standard text-based input field.
 *
 * Supports various HTML5 input types like text, url, number, etc.
 */
class CJW_Input_Field extends CJW_Field
{
    /**
     * Initializes a new input field instance.
     *
     * @param string $key The field key.
     * @param string $label The field label.
     * @param string $optionKey The top-level option key.
     * @param string $type The HTML input type (e.g., 'text', 'number', 'url').
     * @param bool $disabled Whether the field should be read-only.
     * @param string $description Optional help text rendered below the control.
     */
    public function __construct(
        string                  $key,
        string                  $label,
        string                  $optionKey,
        private readonly string $type = 'text',
        private readonly bool   $disabled = false,
        string                  $description = ''
    ) {
        parent::__construct($key, $label, $optionKey, $description);
    }

    /**
     * Renders the input field HTML.
     *
     * @return void
     */
    public function render(): void
    {
        printf(
            '<input type="%s" name="%s" value="%s" class="regular-text" %s />',
            esc_attr($this->type),
            esc_attr($this->getName()),
            esc_attr((string) $this->getValue()),
            $this->disabled ? 'disabled' : ''
        );

        $this->renderDescription();
    }
}
