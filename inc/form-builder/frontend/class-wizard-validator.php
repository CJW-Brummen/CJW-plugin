<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Validator
{
    /**
     * Holds the configuration being validated against.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config
    ) {
        $this->config = cjw_localize_value($config);
    }

    /**
     * Validates the currently active step.
     *
     * @param string $stepKey       Current step key.
     * @param array  $submittedData Submitted fields for the current request.
     * @param array  $allData       Full wizard state data after merging the request.
     * @param array<string, mixed> $allData
     * @param array<string, mixed> $submittedData
     *
     * @return array<string, string>
     */
    public function validateStep(string $stepKey, array $submittedData, array $allData): array
    {
        $errors = [];
        $step = $this->config['steps'][ $stepKey ] ?? [];

        foreach (($step['fields'] ?? []) as $component) {
            $this->validateComponent($component, $submittedData, $allData, $errors);
        }

        return $errors;
    }

    /**
     * Validates a single field or nested repeater field.
     *
     * @param array                $component     Field configuration.
     * @param array                $submittedData Current request data for this level.
     * @param array                $allData       Full wizard state data.
     * @param array                $errors       Collected errors.
     * @param string               $prefix        Error key prefix for nested fields.
     * @param array<string, mixed> $allData
     * @param array<string, mixed> $component
     * @param array<string, string> $errors
     * @param array<string, mixed> $submittedData
     */
    private function validateComponent(array $component, array $submittedData, array $allData, array &$errors, string $prefix = ''): void
    {
        if (! CJW_Wizard_Conditions::isVisible($component, $allData)) {
            return;
        }

        $type = (string) ($component['type'] ?? 'text');
        $key = (string) ($component['key'] ?? '');
        $required = ! empty($component['required']);
        $value = $submittedData[ $key ] ?? null;

        if ($type === 'repeater') {
            $this->validateRepeater($component, $value, $allData, $errors, $prefix);
            return;
        }

        if (in_array($type, [ 'heading', 'html' ], true) || $key === '') {
            return;
        }

        if ($type === 'checkbox_group') {
            $this->validateCheckboxGroup($component, $value, $errors, "{$prefix}{$key}");
            return;
        }

        if ($required && CJW_Wizard_Conditions::isEmptyValue($value)) {
            $errors[ "{$prefix}{$key}" ] = __('Dit veld is verplicht.', 'cjw');
            return;
        }

        if (CJW_Wizard_Conditions::isEmptyValue($value)) {
            return;
        }

        $stringValue = is_array($value) ? '' : trim((string) $value);

        switch ($type) {
            case 'email':
                if (! is_email($stringValue)) {
                    $errors[ "{$prefix}{$key}" ] = __('Voer een geldig e-mailadres in.', 'cjw');
                }
                break;

            case 'date':
                $this->validateDateField($component, $stringValue, $allData, $errors, "{$prefix}{$key}");
                break;

            case 'radio':
                $options = $this->normalizeRadioOptions($component['options'] ?? []);
                $optionValues = array_map(
                    static fn(array $option): string => $option['value'],
                    $options
                );
                if (! in_array($stringValue, $optionValues, true)) {
                    $errors[ "{$prefix}{$key}" ] = __('Kies een geldige optie.', 'cjw');
                    break;
                }

                foreach ($options as $option) {
                    if ($option['value'] !== $stringValue) {
                        continue;
                    }

                    foreach ($option['fields'] as $subField) {
                        $this->validateComponent($subField, $submittedData, $allData, $errors, $prefix);
                    }
                    break;
                }
                break;

            case 'text':
            case 'textarea':
                $this->validateLength($component, $stringValue, $errors, "{$prefix}{$key}");
                break;
        }
    }

    /**
     * Validates a repeater field including row count and nested required fields.
     *
     * @param array                $component Field configuration.
     * @param mixed                $value     Submitted repeater rows.
     * @param array                $allData   Full wizard state data.
     * @param array                $errors    Collected errors.
     * @param string               $prefix    Error prefix for nesting.
     * @param array<string, mixed> $allData
     * @param array<string, mixed> $component
     * @param array<string, string> $errors
     */
    private function validateRepeater(array $component, mixed $value, array $allData, array &$errors, string $prefix): void
    {
        $key = (string) ($component['key'] ?? '');
        $required = ! empty($component['required']);
        $rows = is_array($value) ? $value : [];
        $activeRows = [];

        foreach ($rows as $row) {
            if (is_array($row) && ! $this->isRowEmpty($row)) {
                $activeRows[] = $row;
            }
        }

        if ($required && $activeRows === []) {
            $errors[ "{$prefix}{$key}" ] = __('Voeg minimaal één item toe.', 'cjw');
            return;
        }

        $maxEntries = (int) ($component['max_entries'] ?? 0);
        if ($maxEntries > 0 && count($activeRows) > $maxEntries) {
            $errors[ "{$prefix}{$key}" ] = sprintf(
                /* translators: %d is the maximum number of rows. */
                __('Maximaal %d items toegestaan.', 'cjw'),
                $maxEntries
            );
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row) || $this->isRowEmpty($row)) {
                continue;
            }

            $rowDataContext = [ ...$allData, ...$row ];

            foreach (($component['fields'] ?? []) as $subField) {
                $this->validateComponent(
                    $subField,
                    $row,
                    $rowDataContext,
                    $errors,
                    "{$prefix}{$key}.{$index}."
                );
            }
        }
    }

    /**
     * Validates min/max lengths for text-like fields.
     *
     * @param array                $component Field configuration.
     * @param string               $value     Submitted value.
     * @param array                $errors    Collected errors.
     * @param string               $errorKey  Error key.
     * @param array<string, mixed> $component
     * @param array<string, string> $errors
     */
    private function validateLength(array $component, string $value, array &$errors, string $errorKey): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        $minLength = (int) ($component['min_length'] ?? 0);
        $maxLength = (int) ($component['max_length'] ?? 0);

        if ($minLength > 0 && $length < $minLength) {
            $errors[ $errorKey ] = sprintf(
                /* translators: %d is the minimum number of characters. */
                __('Gebruik minimaal %d tekens.', 'cjw'),
                $minLength
            );
            return;
        }

        if ($maxLength > 0 && $length > $maxLength) {
            $errors[ $errorKey ] = sprintf(
                /* translators: %d is the maximum number of characters. */
                __('Gebruik maximaal %d tekens.', 'cjw'),
                $maxLength
            );
        }
    }

    /**
     * Validates date format and optional date range constraints.
     *
     * @param array                $component Field configuration.
     * @param string               $value     Submitted value.
     * @param array                $allData   Full wizard state data.
     * @param array                $errors    Collected errors.
     * @param string               $errorKey  Error key.
     * @param array<string, mixed> $allData
     * @param array<string, mixed> $component
     * @param array<string, string> $errors
     */
    private function validateDateField(array $component, string $value, array $allData, array &$errors, string $errorKey): void
    {
        $date = $this->parseDate($value);

        if ($date === null) {
            $errors[ $errorKey ] = __('Voer een geldige datum in.', 'cjw');
            return;
        }

        $minDate = $this->parseDate((string) ($component['min_date'] ?? ''));
        if ($minDate !== null && $date < $minDate) {
            $errors[ $errorKey ] = sprintf(
                /* translators: %s is a date. */
                __('Kies een datum op of na %s.', 'cjw'),
                cjw_localize_value($minDate, [
                    'type' => 'date',
                    'format' => 'd-m-Y',
                ])
            );
            return;
        }

        $maxDate = $this->parseDate((string) ($component['max_date'] ?? ''));
        if ($maxDate !== null && $date > $maxDate) {
            $errors[ $errorKey ] = sprintf(
                /* translators: %s is a date. */
                __('Kies een datum op of voor %s.', 'cjw'),
                cjw_localize_value($maxDate, [
                    'type' => 'date',
                    'format' => 'd-m-Y',
                ])
            );
            return;
        }

        $afterField = (string) ($component['date_after_field'] ?? '');
        if ($afterField !== '') {
            $afterValue = CJW_Wizard_Conditions::getValueByPath($allData, $afterField);
            $afterDate = $this->parseDate(is_array($afterValue) ? '' : (string) $afterValue);

            if ($afterDate !== null && $date < $afterDate) {
                $errors[ $errorKey ] = __('Deze datum mag niet voor de gekoppelde startdatum liggen.', 'cjw');
                return;
            }
        }

        $beforeField = (string) ($component['date_before_field'] ?? '');
        if ($beforeField !== '') {
            $beforeValue = CJW_Wizard_Conditions::getValueByPath($allData, $beforeField);
            $beforeDate = $this->parseDate(is_array($beforeValue) ? '' : (string) $beforeValue);

            if ($beforeDate !== null && $date > $beforeDate) {
                $errors[ $errorKey ] = __('Deze datum mag niet na de gekoppelde einddatum liggen.', 'cjw');
            }
        }
    }

    /**
     * Strictly parses an HTML date value.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->setTime(0, 0);
        }

        return null;
    }

    /**
     * Returns whether a repeater row is fully empty.
     *
     * @param array<string, mixed> $row
     */
    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (! CJW_Wizard_Conditions::isEmptyValue($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks a checkbox group against its options and its required flag.
     *
     * @param array<string, mixed> $component
     * @param array<string, string> $errors
     */
    private function validateCheckboxGroup(array $component, mixed $value, array &$errors, string $errorKey): void
    {
        $selectedValues = is_array($value)
            ? array_values(array_filter(array_map('strval', $value), static fn(string $entry): bool => trim($entry) !== ''))
            : [];

        if (! empty($component['required']) && $selectedValues === []) {
            $errors[ $errorKey ] = __('Kies minimaal één checkbox.', 'cjw');
            return;
        }

        if ($selectedValues === []) {
            return;
        }

        $optionValues = array_map(
            static fn(array $option): string => $option['value'],
            $this->normalizeCheckboxOptions($component['options'] ?? [])
        );

        foreach ($selectedValues as $selectedValue) {
            if (! in_array($selectedValue, $optionValues, true)) {
                $errors[ $errorKey ] = __('Kies een geldige optie.', 'cjw');
                return;
            }
        }
    }

    /**
     * Normalizes radio options so a submitted value can be checked against them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRadioOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values(array_map(
            static function (mixed $option): array {
                if (is_array($option)) {
                    $label = (string) ($option['label'] ?? ($option['value'] ?? ''));
                    $value = (string) ($option['value'] ?? $label);

                    return [
                        'label' => $label,
                        'value' => $value,
                        'fields' => is_array($option['fields'] ?? null) ? $option['fields'] : [],
                    ];
                }

                $value = (string) $option;

                return [
                    'label' => $value,
                    'value' => $value,
                    'fields' => [],
                ];
            },
            $options
        ));
    }

    /**
     * Normalizes checkbox options so submitted values can be checked against them.
     *
     * @return array<int, array<string, string>>
     */
    private function normalizeCheckboxOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values(array_map(
            static function (mixed $option): array {
                if (is_array($option)) {
                    $label = (string) ($option['label'] ?? ($option['value'] ?? ''));
                    $value = (string) ($option['value'] ?? $label);

                    return [
                        'label' => $label,
                        'value' => $value,
                    ];
                }

                $value = (string) $option;

                return [
                    'label' => $value,
                    'value' => $value,
                ];
            },
            $options
        ));
    }
}
