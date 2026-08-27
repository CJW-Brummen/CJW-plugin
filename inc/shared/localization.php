<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    return;
}

if (! function_exists('cjw_localize_value')) {
    /**
     * Converts localizable values to the best value for the current WordPress locale.
     *
     * Supports locale-keyed arrays, DateTimeInterface instances, and explicit
     * number/currency formatting. Plain strings remain unchanged unless a type is
     * provided, so stored settings are not unexpectedly rewritten.
     *
     * @param mixed       $value  Value to localize.
     * @param array       $args   Optional formatting arguments.
     * @param string|null $locale Locale to prefer. Defaults to WordPress' locale.
     * @param array<string, mixed> $args
     *
     * @return mixed
     */
    function cjw_localize_value(mixed $value, array $args = [], ?string $locale = null): mixed
    {
        $locale = cjw_normalize_locale($locale ?? cjw_current_locale());
        $type = (string) ($args['type'] ?? 'auto');

        if ($value instanceof DateTimeInterface) {
            return cjw_localize_date_value($value, $args, $locale);
        }

        if (is_array($value)) {
            $localized = cjw_select_locale_value($value, $locale, $args);

            if ($localized['found']) {
                return cjw_localize_value($localized['value'], $args, $locale);
            }

            return array_map(function ($item) use ($locale) {
                return cjw_localize_value($item, [], $locale);
            }, $value);
        }

        if ($type === 'date') {
            return cjw_localize_date_value($value, $args, $locale);
        }

        if ($type === 'number') {
            return cjw_localize_number_value($value, $args);
        }

        if ($type === 'currency') {
            return cjw_localize_currency_value($value, $args);
        }

        return $value;
    }
}

if (! function_exists('cjw_current_locale')) {
    /**
     * Returns the locale WordPress chose for this request.
     */
    function cjw_current_locale(): string
    {
        if (function_exists('determine_locale')) {
            return determine_locale();
        }

        if (function_exists('get_locale')) {
            return get_locale();
        }

        return 'en_US';
    }
}

if (! function_exists('cjw_select_locale_value')) {
    /**
     * Selects the best matching entry from a locale-keyed array.
     *
     * @param array  $value  Candidate localized values.
     * @param string $locale Normalized locale.
     * @param array  $args   Optional fallback arguments.
     * @param array<string, mixed> $args
     * @param array<string, mixed> $value
     *
     * @return array{found: bool, value: mixed}
     */
    function cjw_select_locale_value(array $value, string $locale, array $args = []): array
    {
        if (! cjw_is_locale_map($value)) {
            return [
                'found' => false,
                'value' => null,
            ];
        }

        $language = strtok($locale, '_') ?: $locale;
        $fallback_locale = cjw_normalize_locale((string) ($args['fallback_locale'] ?? 'en_US'));
        $fallback_language = strtok($fallback_locale, '_') ?: $fallback_locale;
        $candidates = [
            $locale,
            str_replace('_', '-', $locale),
            strtolower($locale),
            $language,
            $fallback_locale,
            str_replace('_', '-', $fallback_locale),
            strtolower($fallback_locale),
            $fallback_language,
            'default',
            'fallback',
        ]
                |> array_filter(...)
                |> array_unique(...)
                |> array_values(...);

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $value)) {
                return [
                    'found' => true,
                    'value' => $value[$candidate],
                ];
            }
        }

        return [
            'found' => true,
            'value' => reset($value),
        ];
    }
}

if (! function_exists('cjw_is_locale_map')) {
    /**
     * Checks whether an array only contains locale/fallback keys.
     *
     * @param array<string, mixed> $value
     */
    function cjw_is_locale_map(array $value): bool
    {
        if ($value === [] || array_is_list($value)) {
            return false;
        }

        $has_locale_key = false;

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return false;
            }

            if (in_array($key, ['default', 'fallback'], true)) {
                continue;
            }

            if (! preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z]{2})?$/', $key)) {
                return false;
            }

            $has_locale_key = true;
        }

        return $has_locale_key;
    }
}

if (! function_exists('cjw_localize_date_value')) {
    /**
     * Formats a date-like value with WordPress' localized date formatter.
     *
     * @param array<string, mixed> $args
     */
    function cjw_localize_date_value(mixed $value, array $args = [], ?string $locale = null): string
    {
        $date = cjw_parse_date_value($value);

        if (! $date instanceof DateTimeInterface) {
            return '';
        }

        $format = (string) ($args['format'] ?? '');

        if ($format === '') {
            $format = function_exists('get_option') ? (string) get_option('date_format', 'j F Y') : 'j F Y';
        }

        $locale = cjw_normalize_locale($locale ?? cjw_current_locale());
        $switched = false;

        if (
            function_exists('switch_to_locale')
            && function_exists('restore_previous_locale')
            && cjw_normalize_locale(cjw_current_locale()) !== $locale
        ) {
            $switched = switch_to_locale($locale);
        }

        try {
            if (function_exists('wp_date')) {
                return wp_date($format, $date->getTimestamp());
            }

            return gmdate($format, $date->getTimestamp());
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
    }
}

if (! function_exists('cjw_parse_date_value')) {
    /**
     * Converts common date representations to DateTimeImmutable.
     *
     * @throws DateMalformedStringException
     */
    function cjw_parse_date_value(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value))) {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

            return new DateTimeImmutable('@' . (int) $value)->setTimezone($timezone);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'Y/m/d', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);

            if ($date instanceof DateTimeImmutable) {
                return $date->setTime(0, 0);
            }
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}

if (! function_exists('cjw_localize_number_value')) {
    /**
     * Formats a numeric value with WordPress' number formatter.
     *
     * @param array<string, mixed> $args
     */
    function cjw_localize_number_value(mixed $value, array $args = []): string
    {
        if (! is_numeric($value)) {
            return '';
        }

        $decimals = max(0, (int) ($args['decimals'] ?? 0));

        if (function_exists('number_format_i18n')) {
            return number_format_i18n((float) $value, $decimals);
        }

        return number_format((float) $value, $decimals, ',', '.');
    }
}

if (! function_exists('cjw_localize_currency_value')) {
    /**
     * Formats a numeric value as a localized currency string.
     *
     * @param array<string, mixed> $args
     */
    function cjw_localize_currency_value(mixed $value, array $args = []): string
    {
        $number = cjw_localize_number_value($value, $args);

        if ($number === '') {
            return '';
        }

        $symbol = (string) ($args['currency_symbol'] ?? '€');
        $position = (string) ($args['currency_position'] ?? 'before');

        return $position === 'after'
            ? trim($number . ' ' . $symbol)
            : trim($symbol . ' ' . $number);
    }
}

if (! function_exists('cjw_normalize_locale')) {
    /**
     * Normalizes locale separators while preserving the usual WordPress casing.
     */
    function cjw_normalize_locale(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));

        if ($locale === '') {
            return 'en_US';
        }

        $parts = explode('_', $locale);
        $language = strtolower(($parts[0] ?? 'en'));

        if (! isset($parts[1])) {
            return $language;
        }

        return $language . '_' . strtoupper($parts[1]);
    }
}
