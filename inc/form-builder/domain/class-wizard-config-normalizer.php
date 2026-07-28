<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Config_Normalizer
{
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

    public static function empty(): array
    {
        return [
            'steps' => [],
            'rules' => [],
        ];
    }

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

        foreach ([ 'min_length', 'max_length', 'min_date', 'max_date', 'date_after_field', 'date_before_field' ] as $property) {
            if (array_key_exists($property, $field)) {
                $normalized[ $property ] = is_int($field[ $property ]) ? $field[ $property ] : (string) $field[ $property ];
            }
        }

        return $normalized;
    }

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
