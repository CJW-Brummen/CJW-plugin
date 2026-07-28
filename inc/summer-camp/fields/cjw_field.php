<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for all CJW fields.
 *
 * Provides core functionality for handling option keys, field names, and values.
 */
abstract class CJW_Field
{
    /**
     * Initializes a new field instance.
     *
     * @param string $key         The unique identifier for the field within the option array.
     * @param string $label       The human-readable label for the field.
     * @param string $optionKey   The top-level WordPress option key where values are stored.
     * @param string $description Optional help text rendered below the control.
     */
    public function __construct(
        protected string $key,
        protected string $label,
        protected string $optionKey,
        protected string $description = ''
    ) {}

    /**
     * Renders the optional help text below the field control.
     *
     * Allows a small set of inline HTML tags (a, code, strong, em).
     *
     * @return void
     */
    protected function renderDescription(): void
    {
        if ($this->description === '') {
            return;
        }

        echo '<p class="description">' . wp_kses(
            $this->description,
            [
                'a' => [
                    'href' => true,
                    'target' => true,
                    'rel' => true,
                ],
                'code' => [],
                'strong' => [],
                'em' => [],
            ]
        ) . '</p>';
    }

    /**
     * Retrieves the current value for the field from the database.
     *
     * @return mixed The field value or null if not set.
     */
    protected function getValue(): mixed
    {
        $options = get_option($this->optionKey, []);
        return $options[$this->key] ?? null;
    }

    /**
     * Constructs the name attribute for the HTML input element.
     *
     * @return string The formatted input name (e.g., 'option_key[field_key]').
     */
    protected function getName(): string
    {
        return "$this->optionKey[$this->key]";
    }

    /**
     * Renders the field's HTML in the WordPress admin.
     *
     * @return void
     */
    abstract public function render(): void;
}
