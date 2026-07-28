<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Engine
{
    private CJW_Wizard_Validator $validator;

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

        $filteredInput = $this->filterInputRecursive($input);
        $filteredInput = $this->applyMissingFieldDefaults(
            $this->config['steps'][ $currentStep ]['fields'] ?? [],
            $filteredInput
        );
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
     * Adds empty values for inputs that browsers omit from POST, such as unchecked
     * checkboxes and fields below a non-selected radio option.
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
