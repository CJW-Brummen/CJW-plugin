<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Engine
{
    /**
     * Length ceiling for a field the builder gave no `max_length`.
     *
     * Generous enough for the longest thing a camp form asks for — an allergy
     * or medication note — while making it pointless to stuff the database
     * through the public form. Filter: `cjw_wizard_default_max_length`.
     */
    private const int DEFAULT_MAX_LENGTH = 1000;

    /**
     * Row ceiling for a repeater the builder gave no `max_entries`.
     *
     * Filter: `cjw_wizard_default_max_entries`.
     */
    private const int DEFAULT_MAX_ENTRIES = 50;

    private CJW_Wizard_Validator $validator;

    /**
     * Holds the configuration the engine steps through.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config
    ) {
        $this->config = cjw_localize_value($config);
        $this->validator = new CJW_Wizard_Validator($this->config);
    }

    /**
     * Processes a wizard submission and moves forward or backward.
     *
     * @param array $state Existing wizard state.
     * @param array $input Raw request payload.
     * @param array<string, mixed> $input
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function next(array $state, array $input): array
    {
        $state = $this->normalizeState($state);
        $stepKeys = array_keys($this->config['steps'] ?? []);
        $currentIndex = $this->normalizeCurrentIndex($state['step'], $stepKeys);
        $currentStep = $stepKeys[ $currentIndex ] ?? null;

        if ($currentStep === null) {
            return [
                'state' => $state,
                'errors' => [],
            ];
        }

        $stepFields = $this->config['steps'][ $currentStep ]['fields'] ?? [];
        $filteredInput = $this->filterInputRecursive($input);
        $filteredInput = $this->restrictToConfiguredFields($stepFields, $filteredInput);
        $filteredInput = $this->applyMissingFieldDefaults($stepFields, $filteredInput);
        $action = $this->normalizeAction($input['cjw_wizard_action'] ?? 'next');
        $state['data'] = [ ...$state['data'], ...$filteredInput ];

        if ($action === 'previous') {
            $state['step'] = $this->findPreviousVisibleStepIndex($currentIndex, $state['data'], $stepKeys);

            return [
                'state' => $state,
                'errors' => [],
            ];
        }

        $errors = $this->validator->validateStep($currentStep, $filteredInput, $state['data']);

        if ($errors !== []) {
            return [
                'state' => $state,
                'errors' => $errors,
            ];
        }

        $state['step'] = $this->findNextVisibleStepIndex($currentIndex, $state['data'], $stepKeys);

        return [
            'state' => $state,
            'errors' => [],
        ];
    }

    /**
     * Filters wizard field input and strips the internal prefix.
     *
     * @param array $input Raw request input.
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function filterInputRecursive(array $input, bool $isTopLevel = true): array
    {
        $filtered = [];

        foreach ($input as $key => $value) {
            $keyStr = (string) $key;

            if ($isTopLevel && str_starts_with($keyStr, 'cjw_field_')) {
                $cleanKey = str_replace('cjw_field_', '', $keyStr);
                $filtered[ $cleanKey ] = is_array($value) ? $this->filterInputRecursive($value, false) : $value;
                continue;
            }

            if (! $isTopLevel) {
                $filtered[ $key ] = is_array($value) ? $this->filterInputRecursive($value, false) : $value;
            }
        }

        return $filtered;
    }

    /**
     * Drops anything the current step does not actually ask for, and bounds
     * what it does.
     *
     * Without this, every POST key prefixed `cjw_field_` was merged into session
     * state and persisted to `_cjw_wizard_payload` — arbitrary keys, arbitrary
     * volume, from an unauthenticated form. The step's own configuration is the
     * only list of keys that should ever be stored.
     *
     * @param array $fields        Field configuration for the current step.
     * @param array $submittedData Filtered request data for this step.
     * @param array<int, mixed> $fields
     * @param array<string, mixed> $submittedData
     *
     * @return array<string, mixed>
     */
    private function restrictToConfiguredFields(array $fields, array $submittedData): array
    {
        $allowed = $this->allowedFieldMap($fields);
        $restricted = [];

        foreach ($submittedData as $key => $value) {
            $field = $allowed[ (string) $key ] ?? null;

            if ($field === null) {
                continue;
            }

            $restricted[ $key ] = $this->boundValue($field, $value);
        }

        return $restricted;
    }

    /**
     * Flattens a step's fields into key => field, following the nesting the
     * engine itself stores through: repeater sub-fields and the fields that
     * hang off a radio option.
     *
     * @param array $fields Field configuration.
     * @param array<int, mixed> $fields
     *
     * @return array<string, array>
     *
     * @return array<string, mixed>
     */
    private function allowedFieldMap(array $fields): array
    {
        $map = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');

            if ($key !== '') {
                $map[ $key ] = $field;
            }

            if (is_array($field['fields'] ?? null)) {
                $map += $this->allowedFieldMap($field['fields']);
            }

            foreach ($this->normalizeRadioOptions($field['options'] ?? []) as $option) {
                $map += $this->allowedFieldMap($option['fields']);
            }
        }

        return $map;
    }

    /**
     * Caps a submitted value at the field's own configured length, or at a
     * blanket ceiling where the builder set none.
     *
     * @param array $field Field configuration.
     * @param mixed $value Submitted value.
     * @param array<string, mixed> $field
     */
    private function boundValue(array $field, mixed $value): mixed
    {
        if (is_array($value)) {
            // A checkbox group's value is a flat list of chosen option values,
            // not repeater rows. Sending it through boundRows() applied the
            // repeater row cap to it, so a group with more than 50 options
            // silently lost the tail of a parent's answers.
            if (($field['type'] ?? '') === 'checkbox_group') {
                return $this->boundChoices($field, $value);
            }

            return $this->boundRows($field, $value);
        }

        if (! is_scalar($value)) {
            return '';
        }

        $string = (string) $value;
        $configured = (int) ($field['max_length'] ?? 0);
        $limit = $configured > 0
            ? $configured
            : max(1, (int) apply_filters('cjw_wizard_default_max_length', self::DEFAULT_MAX_LENGTH));

        return function_exists('mb_substr') ? mb_substr($string, 0, $limit) : substr($string, 0, $limit);
    }

    /**
     * Bounds the chosen values of a checkbox group.
     *
     * A visitor can only legitimately return values the form offered, so the
     * count is capped at the number of configured options and each value is
     * length-bounded like any other answer.
     *
     * @param array $field   Field configuration.
     * @param array $choices Submitted values.
     * @param array<int, mixed> $choices
     * @param array<string, mixed> $field
     *
     * @return array<int, mixed>
     */
    private function boundChoices(array $field, array $choices): array
    {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $limit = max(1, count($options));
        $bounded = [];

        foreach (array_slice(array_values($choices), 0, $limit) as $choice) {
            if (is_array($choice)) {
                continue;
            }

            $bounded[] = $this->boundValue([ 'max_length' => $field['max_length'] ?? 0 ], $choice);
        }

        return $bounded;
    }

    /**
     * Caps repeater rows at the field's `max_entries`, or at a blanket ceiling,
     * and bounds every value inside them.
     *
     * @param array $field Field configuration.
     * @param array $rows  Submitted rows.
     * @param array<string, mixed> $field
     * @param array<int, mixed> $rows
     *
     * @return array<int, mixed>
     */
    private function boundRows(array $field, array $rows): array
    {
        $configured = (int) ($field['max_entries'] ?? 0);
        $limit = $configured > 0
            ? $configured
            : max(1, (int) apply_filters('cjw_wizard_default_max_entries', self::DEFAULT_MAX_ENTRIES));

        $rows = array_slice($rows, 0, $limit, true);
        $subFields = $this->allowedFieldMap(is_array($field['fields'] ?? null) ? $field['fields'] : []);

        if ($subFields === []) {
            return $rows;
        }

        $bounded = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $boundedRow = [];

            foreach ($row as $key => $value) {
                $subField = $subFields[ (string) $key ] ?? null;

                if ($subField === null) {
                    continue;
                }

                $boundedRow[ $key ] = $this->boundValue($subField, $value);
            }

            $bounded[ $index ] = $boundedRow;
        }

        return $bounded;
    }

    /**
     * Adds empty values for inputs that browsers omit from POST, such as unchecked
     * checkboxes and fields below a non-selected radio option.
     *
     * @param array<int, mixed> $fields
     * @param array<string, mixed> $submittedData
     *
     * @return array<string, mixed>
     */
    private function applyMissingFieldDefaults(array $fields, array $submittedData): array
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            $key = (string) ($field['key'] ?? '');

            if ($key !== '' && $type === 'checkbox' && ! array_key_exists($key, $submittedData)) {
                $submittedData[ $key ] = '';
            }

            if ($key !== '' && $type === 'checkbox_group' && ! array_key_exists($key, $submittedData)) {
                $submittedData[ $key ] = [];
            }

            if ($type !== 'radio' || $key === '') {
                continue;
            }

            $selectedValue = is_array($submittedData[ $key ] ?? null)
                ? ''
                : (string) ($submittedData[ $key ] ?? '');

            foreach ($this->normalizeRadioOptions($field['options'] ?? []) as $option) {
                if ($option['value'] === $selectedValue) {
                    $submittedData = $this->applyMissingFieldDefaults($option['fields'], $submittedData);
                    continue;
                }

                $submittedData = $this->clearFieldValues($option['fields'], $submittedData);
            }
        }

        return $submittedData;
    }

    /**
     * Empties the answers to fields a condition has just hidden.
     *
     * @param array<int, mixed> $fields
     * @param array<string, mixed> $submittedData
     *
     * @return array<string, mixed>
     */
    private function clearFieldValues(array $fields, array $submittedData): array
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            if ($key !== '') {
                $submittedData[ $key ] = ($field['type'] ?? '') === 'checkbox_group' ? [] : '';
            }

            if (($field['type'] ?? '') === 'radio') {
                foreach ($this->normalizeRadioOptions($field['options'] ?? []) as $option) {
                    $submittedData = $this->clearFieldValues($option['fields'], $submittedData);
                }
            }

            if (is_array($field['fields'] ?? null)) {
                $submittedData = $this->clearFieldValues($field['fields'], $submittedData);
            }
        }

        return $submittedData;
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
     * Normalizes wizard state into a predictable structure.
     *
     * @param array $state Existing state.
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function normalizeState(array $state): array
    {
        return [
            'step' => max(0, (int) ($state['step'] ?? 0)),
            'data' => is_array($state['data'] ?? null) ? $state['data'] : [],
        ];
    }

    /**
     * Ensures the stored step index still points to an existing step.
     *
     * @param int                $index    Stored step index.
     * @param array<int, string> $stepKeys Configured step keys.
     */
    private function normalizeCurrentIndex(int $index, array $stepKeys): int
    {
        if ($stepKeys === []) {
            return 0;
        }

        return max(0, min($index, count($stepKeys) - 1));
    }

    /**
     * Finds the next visible step index based on current state data.
     *
     * @param int                $currentIndex Current absolute step index.
     * @param array              $data         Current wizard data.
     * @param array<int, string> $stepKeys     Configured step keys.
     * @param array<string, mixed> $data
     */
    private function findNextVisibleStepIndex(int $currentIndex, array $data, array $stepKeys): int
    {
        for ($index = $currentIndex + 1, $count = count($stepKeys); $index < $count; $index++) {
            $stepKey = $stepKeys[ $index ];
            $step = $this->config['steps'][ $stepKey ] ?? [];

            if (CJW_Wizard_Conditions::isVisible($step, $data)) {
                return $index;
            }
        }

        return count($stepKeys);
    }

    /**
     * Finds the previous visible step index based on current state data.
     *
     * @param int                $currentIndex Current absolute step index.
     * @param array              $data         Current wizard data.
     * @param array<int, string> $stepKeys     Configured step keys.
     * @param array<string, mixed> $data
     */
    private function findPreviousVisibleStepIndex(int $currentIndex, array $data, array $stepKeys): int
    {
        for ($index = $currentIndex - 1; $index >= 0; $index--) {
            $stepKey = $stepKeys[ $index ];
            $step = $this->config['steps'][ $stepKey ] ?? [];

            if (CJW_Wizard_Conditions::isVisible($step, $data)) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * Normalizes the requested wizard action.
     *
     * @param mixed $action Raw action value.
     */
    private function normalizeAction(mixed $action): string
    {
        return $action === 'previous' ? 'previous' : 'next';
    }
}
