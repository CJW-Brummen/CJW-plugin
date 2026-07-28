<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A WordPress media-library image picker field.
 */
class CJW_Media_Field extends CJW_Field
{
    /**
     * Renders the media picker field HTML.
     *
     * @return void
     */
    public function render(): void
    {
        $attachment_id = absint($this->getValue());
        $image = $attachment_id > 0
            ? wp_get_attachment_image($attachment_id, 'medium', false, ['class' => 'cjw-media-field__image'])
            : '';
        $modal_title = $this->label !== ''
            /* translators: %s: field label. */
            ? sprintf(__('Kies afbeelding: %s', 'cjw'), $this->label)
            : __('Kies afbeelding', 'cjw');

        printf(
            '<div class="cjw-media-field" data-cjw-media-field data-title="%s" data-button="%s">',
            esc_attr($modal_title),
            esc_attr__('Gebruik deze afbeelding', 'cjw')
        );

        printf(
            '<input type="hidden" name="%s" value="%s" data-cjw-media-input />',
            esc_attr($this->getName()),
            esc_attr((string) $attachment_id)
        );

        echo '<div class="cjw-media-field__preview" data-cjw-media-preview>';

        if ($image !== '') {
            echo wp_kses_post($image);
        } else {
            echo '<span>' . esc_html__('Geen afbeelding gekozen', 'cjw') . '</span>';
        }

        echo '</div>';
        echo '<div class="cjw-media-field__actions">';
        echo '<button type="button" class="button" data-cjw-media-select>' . esc_html__('Kies afbeelding', 'cjw') . '</button>';
        echo '<button type="button" class="button" data-cjw-media-clear>' . esc_html__('Verwijder afbeelding', 'cjw') . '</button>';
        echo '</div>';
        echo '</div>';

        $this->renderDescription();
    }
}
