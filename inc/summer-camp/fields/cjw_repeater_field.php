<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A repeater field for collecting multiple pairs of dates and labels.
 *
 * Provides functionality to add or remove rows dynamically in the admin.
 */
class CJW_Repeater_Field extends CJW_Field
{
    /**
     * Initializes a new repeater field instance.
     *
     * @param string $key The field key.
     * @param string $label The field label.
     * @param string $optionKey The top-level option key.
     * @param string|null $placeholderDate The placeholder for the date input.
     * @param string|null $placeholderLabel The placeholder for the label input.
     * @param string $description Optional help text rendered below the control.
     */
    public function __construct(
        string $key,
        string $label,
        string $optionKey,
        private ?string $placeholderDate = null,
        private ?string $placeholderLabel = null,
        string $description = ''
    ) {
        parent::__construct($key, $label, $optionKey, $description);
        $this->placeholderDate ??= __('Datum', 'cjw');
        $this->placeholderLabel ??= __('Label', 'cjw');
    }

    /**
     * Renders the repeater field HTML.
     *
     * @return void
     */
    public function render(): void
    {
        $values = $this->getValue() ?? [];

        $this->getName()
            |> (fn($x) => printf('<div class="cjw-repeater" data-name="%s">', esc_attr($x)));

        if (empty($values)) {
            // Show empty row template if no values
            $this->render_row(
                0,
                [
                    'date' => '',
                    'label' => '',
                ]
            );
        } else {
            // Render existing rows
            foreach ($values as $index => $value) {
                $this->render_row($index, $value);
            }
        }

        echo '<button type="button" class="button cjw-add">' . esc_html__('Voeg toe', 'cjw') . '</button>';
        echo '</div>';

        $this->renderDescription();
    }

    /**
     * Renders a single row of the repeater.
     *
     * @param int   $index The row index.
     * @param array $value The current values for this row.
     *
     * @return void
     */
    private function render_row(int $index, array $value): void
    {
        echo '<div class="cjw-row">';
        printf(
            '<input type="text" name="%s[%d][date]" class="regular-text cjw-datepicker" placeholder="%s" value="%s" />',
            esc_attr($this->getName()),
            absint($index),
            esc_attr($this->placeholderDate),
            esc_attr($value['date'] ?? '')
        );
        printf(
            '<input type="text" name="%s[%d][label]" class="regular-text" placeholder="%s" value="%s" />',
            esc_attr($this->getName()),
            absint($index),
            esc_attr($this->placeholderLabel),
            esc_attr($value['label'] ?? '')
        );
        echo '<button type="button" class="button cjw-remove">' . esc_html__('Verwijder', 'cjw') . '</button>';
        echo '</div>';
    }
}
