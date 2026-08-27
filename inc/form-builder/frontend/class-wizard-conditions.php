<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Conditions
{
    /**
     * Returns the visible step keys for the given data set.
     *
     * @param array $config Wizard configuration.
     * @param array $data   Current wizard data.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     *
     * @return array<int, string>
     */
    public static function getVisibleStepKeys(array $config, array $data): array
    {
        $visible = [];

        foreach (($config['steps'] ?? []) as $stepKey => $step) {
            if (self::isVisible($step, $data)) {
                $visible[] = (string) $stepKey;
            }
        }

        return $visible;
    }

    /**
     * Determines whether a step or field should be visible.
     *
     * @param array $node Step or field configuration.
     * @param array $data Current wizard data.
     * @param array<string, mixed> $data
     * @param array<string, mixed> $node
     */
    public static function isVisible(array $node, array $data): bool
    {
        $condition = $node['condition'] ?? null;

        if (! is_array($condition) || empty($condition['field']) || empty($condition['operator'])) {
            return true;
        }

        return self::matches($condition, $data);
    }

    /**
     * Evaluates a single condition against the current data.
     *
     * @param array $condition Condition configuration.
     * @param array $data      Current wizard data.
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $data
     */
    public static function matches(array $condition, array $data): bool
    {
        $fieldPath = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? 'equals');
        $expectedValue = $condition['value'] ?? '';
        $actualValue = self::getValueByPath($data, $fieldPath);

        return match ($operator) {
            'not_equals' => ! self::valuesMatch($actualValue, $expectedValue),
            'contains' => self::valueContains($actualValue, $expectedValue),
            'not_contains' => ! self::valueContains($actualValue, $expectedValue),
            'empty' => self::isEmptyValue($actualValue),
            'not_empty' => ! self::isEmptyValue($actualValue),
            default => self::valuesMatch($actualValue, $expectedValue),
        };
    }

    /**
     * Reads a value from nested data using dot notation.
     *
     * @param array  $data Current wizard data.
     * @param string $path Dot-notation path.
     * @param array<string, mixed> $data
     *
     * @return mixed
     */
    public static function getValueByPath(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $value = $data;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[ $segment ];
        }

        return $value;
    }

    /**
     * Returns whether a value should be considered empty.
     *
     * @param mixed $value Value to inspect.
     */
    public static function isEmptyValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isEmptyValue($item)) {
                    return false;
                }
            }

            return true;
        }

        if (is_bool($value)) {
            return $value === false;
        }

        return $value === null || trim((string) $value) === '';
    }

    /**
     * Compares two values using wizard-friendly normalization.
     *
     * @param mixed $actual   Current value.
     * @param mixed $expected Expected value.
     */
    private static function valuesMatch(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array(self::normalizeScalar($expected), array_map([ self::class, 'normalizeScalar' ], $actual), true);
        }

        return self::normalizeScalar($actual) === self::normalizeScalar($expected);
    }

    /**
     * Checks whether a value contains another value.
     *
     * @param mixed $actual   Current value.
     * @param mixed $expected Expected fragment.
     */
    private static function valueContains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array(self::normalizeScalar($expected), array_map([ self::class, 'normalizeScalar' ], $actual), true);
        }

        return str_contains(self::normalizeScalar($actual), self::normalizeScalar($expected));
    }

    /**
     * Normalizes scalar values for comparisons.
     *
     * @param mixed $value Value to normalize.
     */
    private static function normalizeScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return strtolower(trim((string) $value));
    }
}
