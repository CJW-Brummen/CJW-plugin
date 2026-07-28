<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A color picker field for hex colors.
 */
class CJW_Color_Field extends CJW_Field
{
    /**
     * Initializes a new color field instance.
     *
     * @param string $key          The field key.
     * @param string $label        The field label.
     * @param string $optionKey    The top-level option key.
     * @param string $defaultColor The default hex color.
     * @param string $description  Optional help text rendered below the control.
     */
    public function __construct(
        string $key,
        string $label,
        string $optionKey,
        private readonly string $defaultColor = '#000000',
        string $description = ''
    ) {
        parent::__construct($key, $label, $optionKey, $description);
    }

    /**
     * Renders the color input HTML.
     *
     * @return void
     */
    public function render(): void
    {
        $value = sanitize_hex_color((string) $this->getValue()) ?: $this->defaultColor;

        printf(
            '<input type="color" name="%s" value="%s" class="cjw-color-field" />',
            esc_attr($this->getName()),
            esc_attr($value)
        );

        $this->renderDescription();
    }
}
