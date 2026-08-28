<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A WordPress media-library image picker field.
 *
 * Carries a size warning, because nothing else in either repository checks one.
 * A photo straight off a phone uploads fine, renders fine and looks fine to
 * whoever chose it -- while every visitor on a train pays for it, and while the
 * font preloading and the deleted stylesheet rules argue over kilobytes.
 *
 * The warning names the recommended size in the message rather than in a
 * document nobody opens, and it warns rather than refuses: an organiser
 * uploading the only photo they have of last year's camp, an hour before the
 * site goes live, should not be stopped by a rule about kilobytes.
 */
class CJW_Media_Field extends CJW_Field
{
    /**
     * File size above which the warning appears, when the caller names none.
     */
    public const int DEFAULT_MAX_BYTES = 600 * KB_IN_BYTES;

    /**
     * How much wider than recommended an image may be before it is called out.
     *
     * A photo somewhat larger than needed is fine and often deliberate -- it
     * gives WordPress room to generate the sizes it serves. Half again as wide
     * is where "large enough" turns into "straight off a camera".
     */
    private const float WIDTH_TOLERANCE = 1.5;

    /**
     * Initializes a new media field instance.
     *
     * @param string $key                The unique identifier for the field within the option array.
     * @param string $label              The human-readable label for the field.
     * @param string $optionKey          The top-level WordPress option key where values are stored.
     * @param string $description        Optional help text rendered below the control.
     * @param int    $recommendedWidth   Width this image is actually displayed at, in pixels. Zero to say nothing.
     * @param int    $recommendedHeight  Matching height, in pixels.
     * @param int    $maxBytes           File size above which to warn.
     */
    public function __construct(
        string $key,
        string $label,
        string $optionKey,
        string $description = '',
        private int $recommendedWidth = 0,
        private int $recommendedHeight = 0,
        private int $maxBytes = self::DEFAULT_MAX_BYTES
    ) {
        parent::__construct($key, $label, $optionKey, $description);
    }

    /**
     * Renders the media picker field HTML.
     *
     * @return void
     */
    public function render(): void
    {
        $attachment_id = absint($this->getValue());
        $warning = $this->warning($attachment_id);
        $image = $attachment_id > 0
            ? wp_get_attachment_image($attachment_id, 'medium', false, ['class' => 'cjw-media-field__image'])
            : '';
        $modal_title = $this->label !== ''
            /* translators: %s: field label. */
            ? sprintf(__('Kies afbeelding: %s', 'cjw'), $this->label)
            : __('Kies afbeelding', 'cjw');

        printf(
            '<div class="cjw-media-field" data-cjw-media-field data-title="%s" data-button="%s" data-max-bytes="%s" data-recommended-width="%s" data-recommended-height="%s">',
            esc_attr($modal_title),
            esc_attr__('Gebruik deze afbeelding', 'cjw'),
            esc_attr((string) $this->maxBytes),
            esc_attr((string) $this->recommendedWidth),
            esc_attr((string) $this->recommendedHeight)
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

        printf(
            '<p class="cjw-media-field__warning%s" data-cjw-media-warning>%s</p>',
            $warning === '' ? ' is-hidden' : '',
            esc_html($warning)
        );

        echo '<div class="cjw-media-field__actions">';
        echo '<button type="button" class="button" data-cjw-media-select>' . esc_html__('Kies afbeelding', 'cjw') . '</button>';
        echo '<button type="button" class="button" data-cjw-media-clear>' . esc_html__('Verwijder afbeelding', 'cjw') . '</button>';
        echo '</div>';
        echo '</div>';

        $this->renderDescription();
    }

    /**
     * What is wrong with the chosen image, if anything.
     *
     * @param int $attachment_id Chosen attachment.
     *
     * @return string An empty string when there is nothing to say.
     */
    public function warning(int $attachment_id): string
    {
        if ($attachment_id < 1) {
            return '';
        }

        $path = get_attached_file($attachment_id);
        $bytes = is_string($path) && file_exists($path) ? (int) filesize($path) : 0;

        $metadata = wp_get_attachment_metadata($attachment_id);
        $width = is_array($metadata) ? (int) ($metadata['width'] ?? 0) : 0;

        $too_heavy = $bytes > $this->maxBytes;
        $too_wide = $this->recommendedWidth > 0
            && $width > (int) round($this->recommendedWidth * self::WIDTH_TOLERANCE);

        if (!$too_heavy && !$too_wide) {
            return '';
        }

        $problem = $too_heavy
            ? sprintf(
                /* translators: %s: file size, already formatted. */
                __('Deze afbeelding is %s groot.', 'cjw'),
                size_format($bytes, 1)
            )
            : sprintf(
                /* translators: %d: image width in pixels. */
                __('Deze afbeelding is %d pixels breed.', 'cjw'),
                $width
            );

        return $problem . ' ' . __('Daardoor laadt de pagina traag, vooral op een telefoon met mobiel internet.', 'cjw')
            . ' ' . $this->recommendation();
    }

    /**
     * The size to aim for, said plainly.
     *
     * @return string
     */
    private function recommendation(): string
    {
        $size = size_format($this->maxBytes, 0);

        if ($this->recommendedWidth < 1 || $this->recommendedHeight < 1) {
            return sprintf(
                /* translators: %s: file size, already formatted. */
                __('Maak de afbeelding kleiner: houd ongeveer %s aan.', 'cjw'),
                $size
            );
        }

        return sprintf(
            /* translators: 1: width in pixels, 2: height in pixels, 3: file size, already formatted. */
            __('Maak de afbeelding kleiner: ongeveer %1$d bij %2$d pixels en hooguit %3$s.', 'cjw'),
            $this->recommendedWidth,
            $this->recommendedHeight,
            $size
        );
    }
}
