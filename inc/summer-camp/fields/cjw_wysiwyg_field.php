<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A WYSIWYG editor field.
 *
 * Uses the built-in WordPress `wp_editor()` function.
 */
class CJW_Wysiwyg_Field extends CJW_Field
{
    /**
     * Renders the WYSIWYG editor HTML.
     *
     * @return void
     */
    public function render(): void
    {
        wp_editor(
            $this->getValue() ?? '',
            $this->key,
            [
                'textarea_name' => $this->getName(),
                'textarea_rows' => 5,
                'media_buttons' => false,
            ]
        );

        $this->renderDescription();
    }
}
