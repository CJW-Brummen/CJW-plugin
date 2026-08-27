<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Config_Normalizer
{
    /**
     * Brings a stored configuration up to the shape the rest of the code expects.
     *
     * @return array<string, mixed>
     */
    public static function normalize(mixed $config): array
    {
        if (! is_array($config)) {
            return self::empty();
        }

        return [
            'steps' => self::normalizeSteps($config['steps'] ?? []),
            'rules' => is_array($config['rules'] ?? null) ? $config['rules'] : [],
        ];
    }

    /**
     * The configuration a form starts life with.
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return [
            'steps' => [],
            'rules' => [],
        ];
    }

    /**
     * Normalizes every step, dropping anything that is not one.
     *
     * @return array<string, mixed>
     */
    private static function normalizeSteps(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }

        $normalized = [];

        if (array_is_list($steps)) {
            foreach ($steps as $index => $step) {
                $stepKey = self::normalizeStepKey($step, 'step_' . ($index + 1));
                $normalized[ $stepKey ] = self::normalizeStep($step, $stepKey);
            }

            return $normalized;
        }

        foreach ($steps as $key => $step) {
            $stepKey = self::normalizeStepKey($step, (string) $key);
            $normalized[ $stepKey ] = self::normalizeStep($step, $stepKey);
        }

        return $normalized;
    }

    /**
     * Normalizes one step and the fields under it.
     *
     * @return array<string, mixed>
     */
    private static function normalizeStep(mixed $step, string $stepKey): array
    {
        if (! is_array($step)) {
            $step = [];
        }

        return [
            ...$step,
            'key' => $stepKey,
            'label' => (string) ($step['label'] ?? $stepKey),
            'type' => self::normalizeStepType($step['type'] ?? 'default'),
            'fields' => self::normalizeFields($step['fields'] ?? []),
            'condition' => self::normalizeCondition($step['condition'] ?? null),
        ];
    }

    /**
     * Normalizes a list of fields, giving each a key it can be addressed by.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalized = [];

        foreach ($fields as $index => $field) {
            $normalized[] = self::normalizeField($field, 'field_' . ($index + 1));
        }

        return $normalized;
    }

    /**
     * Normalizes one field, and anything nested inside it.
     *
     * @return array<string, mixed>
     */
    private static function normalizeField(mixed $field, string $fallbackKey): array
    {
        if (is_string($field)) {
            return [
                'key' => self::slugify($field) !== '' ? self::slugify($field) : $fallbackKey,
                'label' => $field,
                'type' => 'text',
                'required' => true,
                'condition' => [],
            ];
        }

        if (! is_array($field)) {
            $field = [];
        }

        $key = (string) ($field['key'] ?? $fallbackKey);
        $type = self::normalizeFieldType($field['type'] ?? 'text');

        $normalized = [
            ...$field,
            'key' => $key,
            'label' => (string) ($field['label'] ?? $key),
            'type' => $type,
            'required' => ! empty($field['required']),
            'condition' => self::normalizeCondition($field['condition'] ?? null),
        ];

        if ($type === 'repeater') {
            $normalized['fields'] = self::normalizeFields($field['fields'] ?? []);
            $normalized['max_entries'] = max(0, (int) ($field['max_entries'] ?? 0));
        }

        if ($type === 'radio') {
            $normalized['options'] = self::normalizeRadioOptions($field['options'] ?? []);
        }

        if ($type === 'checkbox_group') {
            $normalized['options'] = self::normalizeCheckboxOptions($field['options'] ?? []);
        }

        // What the field means, for the list columns, the roster and capacity.
        // Unknown values are dropped rather than stored, so a hand-edited config
        // cannot invent a role the code will later look for and never find.
        $normalized['role'] = CJW_Wizard_Field_Roles::isValid($field['role'] ?? '')
            ? (string) $field['role']
            : '';

        foreach ([ 'min_length', 'max_length', 'min_date', 'max_date', 'date_after_field', 'date_before_field' ] as $property) {
            if (array_key_exists($property, $field)) {
                $normalized[ $property ] = is_int($field[ $property ]) ? $field[ $property ] : (string) $field[ $property ];
            }
        }

        return $normalized;
    }

    /**
     * Normalizes a show-when condition to field, operator and value.
     *
     * @return array<string, mixed>
     */
    private static function normalizeCondition(mixed $condition): array
    {
        if (! is_array($condition)) {
            return [];
        }

        $field = trim((string) ($condition['field'] ?? ''));
        $operator = trim((string) ($condition['operator'] ?? ''));

        if ($field === '' || $operator === '') {
            return [];
        }

        return [
            'field' => $field,
            'operator' => $operator,
            'value' => is_array($condition['value'] ?? null) ? '' : (string) ($condition['value'] ?? ''),
        ];
    }

    /**
     * Normalizes radio options, which may be plain strings or full option arrays.
     *
     * @return array<int, array<string, mixed>|string>
     */
    private static function normalizeRadioOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values(array_map(
            static function (mixed $option): string|array {
                if (! is_array($option)) {
                    return (string) $option;
                }

                $label = (string) ($option['label'] ?? ($option['value'] ?? ''));
                $value = (string) ($option['value'] ?? $label);

                return [
                    ...$option,
                    'label' => $label,
                    'value' => $value,
                    'default' => ! empty($option['default']),
                    'fields' => self::normalizeFields($option['fields'] ?? []),
                ];
            },
            $options
        ));
    }

    /**
     * Normalizes checkbox options, which may be plain strings or full option arrays.
     *
     * @return array<int, array<string, string>|string>
     */
    private static function normalizeCheckboxOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values(array_map(
            static function (mixed $option): string|array {
                if (! is_array($option)) {
                    return (string) $option;
                }

                $label = (string) ($option['label'] ?? ($option['value'] ?? ''));
                $value = (string) ($option['value'] ?? $label);

                return [
                    'label' => $label,
                    'value' => $value,
                ];
            },
            $options
        ));
    }

    private static function normalizeStepKey(mixed $step, string $fallbackKey): string
    {
        if (is_array($step) && ! empty($step['key'])) {
            return (string) $step['key'];
        }

        return $fallbackKey;
    }

    private static function normalizeStepType(mixed $type): string
    {
        return $type === 'summary' ? 'summary' : 'default';
    }

    private static function normalizeFieldType(mixed $type): string
    {
        $allowed = [ 'text', 'email', 'date', 'checkbox', 'checkbox_group', 'radio', 'textarea', 'heading', 'html', 'repeater' ];

        return in_array($type, $allowed, true) ? $type : 'text';
    }

    private static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/\s+/', '_', $text) ?? '';
        $text = preg_replace('/[^\w-]+/', '', $text) ?? '';
        return preg_replace('/--+/', '_', $text) ?? '';
    }
}
