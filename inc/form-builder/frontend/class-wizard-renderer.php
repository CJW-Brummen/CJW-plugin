<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Renderer
{
    /**
     * Holds the configuration being rendered.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
        /**
         * Whether the viewer may edit this form.
         *
         * Decided by the caller, which is the only place that knows both the
         * form id and the current user. All it changes is whether the
         * "form unavailable" notice carries its technical reason: a parent
         * gets the short version, the person who can fix it gets the cause.
         */
        private bool $mayEdit = false
    ) {
        $this->config = cjw_localize_value($config);
    }

    /**
     * Renders the current step of the wizard.
     *
     * @param array                $state  Current wizard state.
     * @param array<string,string> $errors Validation errors.
     * @param string               $token  Current session token.
     * @param array<string, mixed> $state
     */
    public function render(array $state, array $errors = [], string $token = '', string $trapFields = ''): void
    {
        $stepKeys = array_keys($this->config['steps'] ?? []);
        $stepIndex = max(0, (int) ($state['step'] ?? 0));
        $stepKey = $stepKeys[ $stepIndex ] ?? null;
        $stateData = is_array($state['data'] ?? null) ? $state['data'] : [];
        $visibleStepKeys = CJW_Wizard_Conditions::getVisibleStepKeys($this->config, $stateData);

        echo '<div class="cjw-wizard-container">';

        // A form with no steps is not a finished form. Both used to arrive
        // here as $stepKey === null and take the success branch, which is how
        // a mistyped shortcode id came to tell a parent their registration had
        // succeeded. "Nothing to render" and "past the last step" are now
        // answered separately, and only the second one is good news.
        if ($stepKeys === []) {
            echo wp_kses_post(CJW_Wizard_Unavailable::notice(
                $this->mayEdit ? CJW_Wizard_Unavailable::detailNoSteps() : ''
            ));
            echo '</div>';
            return;
        }

        if ($visibleStepKeys === []) {
            echo wp_kses_post(CJW_Wizard_Unavailable::notice(
                $this->mayEdit ? CJW_Wizard_Unavailable::detailNoVisibleSteps() : ''
            ));
            echo '</div>';
            return;
        }

        if ($stepKey === null) {
            $this->renderComplete($token, $state['completion'] ?? []);
            echo '</div>';
            return;
        }

        $step = $this->config['steps'][ $stepKey ] ?? [];

        if (! CJW_Wizard_Conditions::isVisible($step, $stateData)) {
            // The state points at a step this data hides. Mid-form the engine
            // has already skipped it, so this is reached on the opening render
            // of a form whose first step is conditional -- and answering that
            // with the success screen was the second way this wizard congratulated
            // somebody who had filled in nothing. Move forward to the first step
            // that is actually visible; only when none is left is the form done.
            $stepKey = $this->firstVisibleStepFrom($stepIndex, $stepKeys, $visibleStepKeys);

            if ($stepKey === null) {
                $this->renderComplete($token, $state['completion'] ?? []);
                echo '</div>';
                return;
            }

            $step = $this->config['steps'][ $stepKey ] ?? [];
        }

        $visibleStepPosition = array_search($stepKey, $visibleStepKeys, true);
        $stepNumber = $visibleStepPosition === false ? 1 : $visibleStepPosition + 1;
        $stepCount = max(1, count($visibleStepKeys));
        $settings = $this->getSettings();
        $hasPrevious = $stepNumber > 1 && ! empty($settings['show_previous_button']);
        $isSummary = (($step['type'] ?? 'default') === 'summary');

        $this->renderProgressBar($stepNumber, $stepCount);

        echo '<div class="cjw-wizard-step" id="cjw-step-' . esc_attr($stepKey) . '">';
        echo '<h2>' . esc_html((string) ($step['label'] ?? $stepKey)) . '</h2>';
        echo '<form method="post" action="' . esc_url($this->getFormActionUrl($token)) . '" class="cjw-wizard-form">';
        echo '<input type="hidden" name="cjw_wizard_token" value="' . esc_attr($token) . '">';
        wp_nonce_field('cjw_wizard_submit_' . $token, 'cjw_wizard_nonce');

        // Anti-abuse hidden fields, pre-escaped by CJW_Wizard_Guard::trapFields().
        // Passed in rather than built here so the renderer stays unit-testable
        // without the guard and the WordPress functions it depends on.
        if ($trapFields !== '') {
            echo wp_kses(
                $trapFields,
                [
                    'div' => [
                        'class' => [],
                        'aria-hidden' => [],
                        'style' => [],
                    ],
                    'label' => [ 'for' => [] ],
                    'input' => [
                        'type' => [],
                        'id' => [],
                        'name' => [],
                        'value' => [],
                        'tabindex' => [],
                        'autocomplete' => [],
                    ],
                ]
            );
        }

        if ($isSummary) {
            $this->renderSummary($stateData);
        } else {
            foreach (($step['fields'] ?? []) as $field) {
                $this->renderComponent($field, $stateData, $errors);
            }
        }

        echo '<div class="cjw-wizard-actions">';

        if ($hasPrevious) {
            echo '<button type="submit" name="cjw_wizard_action" value="previous" class="button">' . esc_html((string) $settings['previous_label']) . '</button>';
        }

        echo '<button type="submit" name="cjw_wizard_action" value="next" class="button button-primary">'
            . esc_html((string) ($isSummary ? $settings['complete_label'] : $settings['submit_label']))
            . '</button>';
        echo '</div>'; // actions
        echo '</form>';
        echo '</div>'; // step
        echo '</div>'; // container
    }

    /**
     * Renders the completion state.
     */
    private function renderComplete(string $token): void
    {
        $settings = $this->getSettings();
        $submission = (int) ($completion['submission'] ?? 0);
        $email = (string) ($completion['email'] ?? '');
        $mail = (string) ($completion['mail'] ?? '');

        echo '<div class="cjw-wizard-complete">';
        echo '<div class="success-icon dashicons dashicons-yes-alt"></div>';
        echo '<h2>' . esc_html__('Gelukt!', 'cjw') . '</h2>';
        echo '<p>' . nl2br(esc_html((string) $settings['success_message'])) . '</p>';

        if ($submission > 0) {
            echo '<p class="cjw-wizard-complete__reference">' . sprintf(
                /* translators: %s: registration number. */
                esc_html__('Je inschrijfnummer is %s. Noem dit nummer als je ons over deze inschrijving mailt of belt.', 'cjw'),
                '<strong>' . esc_html((string) $submission) . '</strong>'
            ) . '</p>';
        }

        echo wp_kses_post($this->confirmationLine($mail, $email));

        echo '<div class="cjw-wizard-actions" style="justify-content: center; border:none; padding:0;">';
        echo '<a href="' . esc_url($this->getResetUrl($token)) . '" class="button">' . esc_html__('Begin Opnieuw', 'cjw') . '</a>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * What the success screen says about the confirmation mail.
     *
     * Silent when the form does not send one: promising nothing and saying
     * nothing is consistent, and "we hebben geen mail gestuurd" would only
     * worry somebody who was never expecting one.
     */
    private function confirmationLine(string $result, string $email): string
    {
        if ($result === CJW_Wizard_Submission_Confirmation::SENT && $email !== '') {
            return '<p class="cjw-wizard-complete__mail">' . sprintf(
                /* translators: %s: the parent's own e-mail address. */
                esc_html__('We hebben een bevestiging gestuurd naar %s. Niets ontvangen? Kijk ook even in je spam.', 'cjw'),
                '<strong>' . esc_html($email) . '</strong>'
            ) . '</p>';
        }

        if ($result === CJW_Wizard_Submission_Confirmation::FAILED) {
            return '<p class="cjw-wizard-complete__mail cjw-wizard-complete__mail--failed">'
                . esc_html__(
                    'Je inschrijving is opgeslagen, maar het versturen van de bevestigingsmail lukte niet. Je hoeft niets opnieuw te doen: we hebben hier zelf bericht van gekregen en nemen contact met je op.',
                    'cjw'
                ) . '</p>';
        }

        return '';
    }

    /**
     * Renders the progress indicator.
     */
    private function renderProgressBar(int $stepNumber, int $stepCount): void
    {
        $percent = (($stepNumber - 1) / $stepCount) * 100;

        echo '<div class="cjw-wizard-progress">';
        echo '<div class="cjw-wizard-progress-bar" style="width: ' . esc_attr((string) $percent) . '%;"></div>';
        echo '</div>';
        echo '<div class="cjw-wizard-step-label">'
            . sprintf(
                /* translators: 1: current step number, 2: total visible step count. */
                esc_html__('Stap %1$d van %2$d', 'cjw'),
                absint($stepNumber),
                absint($stepCount)
            )
            . '</div>';
    }

    /**
     * Renders the summary view.
     *
     * @param array $data Current wizard data.
     * @param array<string, mixed> $data
     */
    private function renderSummary(array $data): void
    {
        echo '<div class="cjw-wizard-summary">';
        $this->renderSummaryList($data);
        echo '</div>';
    }

    /**
     * Renders a single component if it is currently visible.
     *
     * @param array                $field  Field configuration.
     * @param array                $data   Current wizard data.
     * @param array<string,string> $errors Validation errors.
     * @param string               $namePrefix Field name prefix.
     * @param array<string, mixed> $data
     * @param array<string, mixed> $field
     */
    private function renderComponent(array $field, array $data, array $errors, string $namePrefix = 'cjw_field_'): void
    {
        $type = (string) ($field['type'] ?? 'text');
        $key = (string) ($field['key'] ?? '');
        $value = $key !== '' ? ($data[ $key ] ?? null) : null;
        $error = $key !== '' ? ($errors[ $key ] ?? null) : null;
        $isVisible = CJW_Wizard_Conditions::isVisible($field, $data);
        $conditionAttributes = $this->buildConditionAttributes($field);

        if ($type === 'repeater') {
            $this->renderRepeater($field, $value, $data, $errors);
            return;
        }

        if ($type === 'heading') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buildConditionAttributes() escapes every attribute value.
            echo '<div class="cjw-field cjw-field-heading" ' . ($isVisible ? '' : 'style="display:none;"') . ' ' . $conditionAttributes . '>';
            echo '<h3>' . esc_html((string) ($field['label'] ?? '')) . '</h3>';
            echo '</div>';
            return;
        }

        if ($type === 'html') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buildConditionAttributes() escapes every attribute value.
            echo '<div class="cjw-field cjw-field-html" ' . ($isVisible ? '' : 'style="display:none;"') . ' ' . $conditionAttributes . '>';
            echo '<div class="cjw-html-content">' . wp_kses_post((string) ($field['label'] ?? '')) . '</div>';
            echo '</div>';
            return;
        }

        $this->renderField($field, $value, $error, $namePrefix, $data, $errors);
    }

    /**
     * Renders a single input field.
     *
     * @param array       $field      Field configuration.
     * @param mixed       $value      Current value.
     * @param string|null $error      Validation error.
     * @param string      $namePrefix Field name prefix.
     * @param array       $data       Current wizard data.
     * @param array       $errors     Validation errors.
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     * @param array<string, mixed> $field
     */
    private function renderField(array $field, mixed $value, ?string $error, string $namePrefix, array $data, array $errors = []): void
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? $key);
        $type = (string) ($field['type'] ?? 'text');
        $required = ! empty($field['required']);
        $inputName = $this->buildInputName($namePrefix, $key);
        $inputId = $this->buildInputId($inputName);
        $isVisible = CJW_Wizard_Conditions::isVisible($field, $data);
        $conditionAttributes = $this->buildConditionAttributes($field);

        $classes = [ 'cjw-field', 'cjw-field-' . $type ];
        if ($error) {
            $classes[] = 'cjw-has-error';
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buildConditionAttributes() escapes every attribute value.
        echo '<div class="' . esc_attr(implode(' ', $classes)) . '" ' . ($isVisible ? '' : 'style="display:none;"') . ' ' . $conditionAttributes . '>';

        if ($type !== 'checkbox') {
            echo '<label for="' . esc_attr($inputId) . '">'
                . esc_html($label)
                . ($required ? '<span class="cjw-field-required-mark">*</span>' : '')
                . '</label>';
        }

        if ($error !== null && $error !== '') {
            echo '<span class="cjw-error">' . esc_html($error) . '</span>';
        }

        $requiredAttr = $required ? 'required' : '';
        $fieldValue = is_array($value) ? '' : (string) $value;

        switch ($type) {
            case 'radio':
                echo '<div class="cjw-radio-group">';
                $selectedValue = $this->resolveRadioValue($field, $value);
                foreach (($field['options'] ?? []) as $index => $option) {
                    $normalizedOption = $this->normalizeRadioOption($option);
                    $optionValue = $normalizedOption['value'];
                    $optionLabel = $normalizedOption['label'];
                    $optionId = $this->buildInputId($inputName . '_' . (string) $index);
                    echo '<div class="cjw-radio-option">';
                    echo '<label for="' . esc_attr($optionId) . '">';
                    echo '<input type="radio" id="' . esc_attr($optionId) . '" name="' . esc_attr($inputName) . '" value="' . esc_attr($optionValue) . '" ' . checked($selectedValue, $optionValue, false) . ' ' . esc_attr($requiredAttr) . '>';
                    echo ' <span>' . esc_html($optionLabel) . '</span></label>';

                    if ($normalizedOption['fields'] !== []) {
                        $isOptionSelected = $selectedValue === $optionValue;
                        echo '<div class="cjw-radio-option-children indented-checkboxes" '
                            . ($isOptionSelected ? '' : 'style="display:none;"')
                            . ' data-condition-field="' . esc_attr($key) . '"'
                            . ' data-condition-operator="equals"'
                            . ' data-condition-value="' . esc_attr($optionValue) . '">';

                        foreach ($normalizedOption['fields'] as $childField) {
                            $this->renderComponent($childField, $data, $errors, $namePrefix);
                        }

                        echo '</div>';
                    }

                    echo '</div>';
                }
                echo '</div>';
                break;

            case 'textarea':
                echo '<textarea name="' . esc_attr($inputName) . '" id="' . esc_attr($inputId) . '" rows="4" ' . esc_attr($requiredAttr) . '>'
                    . esc_textarea(is_array($value) ? '' : (string) $value)
                    . '</textarea>';
                break;

            case 'checkbox':
                echo '<label>';
                echo '<input type="checkbox" name="' . esc_attr($inputName) . '" id="' . esc_attr($inputId) . '" value="1" ' . checked($value, '1', false) . ' ' . esc_attr($requiredAttr) . '>';
                echo ' ' . esc_html($label) . ($required ? ' *' : '') . '</label>';
                break;

            case 'checkbox_group':
                $selectedValues = is_array($value) ? array_map('strval', $value) : [];
                echo '<div class="cjw-checkbox-group indented-checkboxes">';
                foreach (($field['options'] ?? []) as $index => $option) {
                    $normalizedOption = $this->normalizeCheckboxOption($option);
                    $optionValue = $normalizedOption['value'];
                    $optionLabel = $normalizedOption['label'];
                    $optionId = $this->buildInputId($inputName . '_' . (string) $index);

                    echo '<label for="' . esc_attr($optionId) . '">';
                    echo '<input type="checkbox" id="' . esc_attr($optionId) . '" name="' . esc_attr($inputName) . '[]" value="' . esc_attr($optionValue) . '" ' . checked(in_array($optionValue, $selectedValues, true), true, false) . '>';
                    echo ' <span>' . esc_html($optionLabel) . '</span></label>';
                }
                echo '</div>';
                break;

            case 'email':
                echo '<input type="email" name="' . esc_attr($inputName) . '" id="' . esc_attr($inputId) . '" value="' . esc_attr($fieldValue) . '" ' . esc_attr($requiredAttr) . '>';
                break;

            case 'date':
                echo '<input type="date" name="' . esc_attr($inputName) . '" id="' . esc_attr($inputId) . '" value="' . esc_attr($fieldValue) . '" ' . esc_attr($requiredAttr) . '>';
                break;

            default:
                echo '<input type="text" name="' . esc_attr($inputName) . '" id="' . esc_attr($inputId) . '" value="' . esc_attr($fieldValue) . '" ' . esc_attr($requiredAttr) . '>';
                break;
        }

        echo '</div>';
    }

    /**
     * Renders a repeater field with nested sub-fields.
     *
     * @param array                $field  Repeater configuration.
     * @param mixed                $values Current repeater values.
     * @param array                $data   Current wizard data.
     * @param array<string,string> $errors Validation errors.
     * @param array<string, mixed> $data
     * @param array<string, mixed> $field
     */
    private function renderRepeater(array $field, mixed $values, array $data, array $errors): void
    {
        $key = (string) ($field['key'] ?? '');
        $rows = is_array($values) && $values !== [] ? $values : [ [] ];
        $maxEntries = (int) ($field['max_entries'] ?? 0);
        $isVisible = CJW_Wizard_Conditions::isVisible($field, $data);
        $conditionAttributes = $this->buildConditionAttributes($field);
        $groupError = $errors[ $key ] ?? null;

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buildConditionAttributes() escapes every attribute value.
        echo '<div class="cjw-repeater-container" id="repeater-' . esc_attr($key) . '" data-max-entries="' . esc_attr((string) $maxEntries) . '" ' . ($isVisible ? '' : 'style="display:none;"') . ' ' . $conditionAttributes . '>';
        echo '<h4>' . esc_html((string) ($field['label'] ?? $key)) . '</h4>';

        if ($groupError !== null && $groupError !== '') {
            echo '<span class="cjw-error">' . esc_html($groupError) . '</span>';
        }

        echo '<div class="cjw-repeater-items">';

        foreach ($rows as $index => $rowValues) {
            $rowValues = is_array($rowValues) ? $rowValues : [];
            echo '<div class="cjw-repeater-row">';
            echo '<span class="cjw-remove-repeater-row">&times;</span>';

            foreach (($field['fields'] ?? []) as $subField) {
                $subKey = (string) ($subField['key'] ?? '');
                $rowDataContext = [ ...$data, ...$rowValues ];
                $this->renderField(
                    $subField,
                    $rowValues[ $subKey ] ?? null,
                    $errors[ "{$key}.{$index}.{$subKey}" ] ?? null,
                    "cjw_field_{$key}[{$index}][",
                    $rowDataContext
                );
            }

            echo '</div>';
        }

        echo '</div>';
        echo '<button type="button" class="button cjw-add-repeater-row" data-repeater="' . esc_attr($key) . '">' . esc_html__('Voeg nog een toe', 'cjw') . '</button>';
        echo '</div>';
    }

    /**
     * Renders nested summary lists.
     *
     * @param array $data Current wizard data.
     * @param array<string, mixed> $data
     */
    private function renderSummaryList(array $data): void
    {
        $labelMap = $this->flattenFieldLabels($this->config['steps'] ?? []);

        echo '<ul>';

        foreach ($data as $key => $value) {
            $label = $labelMap[ $key ] ?? $this->humanizeKey((string) $key);

            if (is_array($value)) {
                echo '<li><strong>' . esc_html($label) . ':</strong>';
                $this->renderSummaryList($value);
                echo '</li>';
                continue;
            }

            echo '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html((string) $value) . '</li>';
        }

        echo '</ul>';
    }

    /**
     * Flattens the entire form config into a lookup of key => label.
     *
     * @param array<int, mixed> $steps
     *
     * @return array<string, mixed>
     */
    private function flattenFieldLabels(array $steps): array
    {
        $map = [];
        foreach ($steps as $step) {
            foreach (($step['fields'] ?? []) as $field) {
                $this->mapFieldLabel($field, $map);
            }
        }
        return $map;
    }

    /**
     * Records one field's label, and those of anything nested inside it.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $map
     */
    private function mapFieldLabel(array $field, array &$map): void
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? '');

        if ($key !== '' && $label !== '') {
            $map[ $key ] = $label;
        }

        if (isset($field['fields']) && is_array($field['fields'])) {
            foreach ($field['fields'] as $subField) {
                $this->mapFieldLabel($subField, $map);
            }
        }

        if (isset($field['options']) && is_array($field['options'])) {
            foreach ($field['options'] as $option) {
                if (! is_array($option) || ! isset($option['fields']) || ! is_array($option['fields'])) {
                    continue;
                }

                foreach ($option['fields'] as $subField) {
                    $this->mapFieldLabel($subField, $map);
                }
            }
        }
    }

    /**
     * The stored value for a radio field, whichever option shape the form uses.
     *
     * @param array<string, mixed> $field
     */
    private function resolveRadioValue(array $field, mixed $value): string
    {
        $currentValue = is_array($value) ? '' : (string) ($value ?? '');

        if ($currentValue !== '') {
            return $currentValue;
        }

        foreach (($field['options'] ?? []) as $option) {
            $normalizedOption = $this->normalizeRadioOption($option);

            if (! empty($normalizedOption['default'])) {
                return $normalizedOption['value'];
            }
        }

        return '';
    }

    /**
     * Normalizes one radio option to a value and a label.
     *
     * @return array<string, mixed>
     */
    private function normalizeRadioOption(mixed $option): array
    {
        if (is_array($option)) {
            $label = (string) ($option['label'] ?? ($option['value'] ?? ''));
            $value = (string) ($option['value'] ?? $label);
            $fields = is_array($option['fields'] ?? null) ? $option['fields'] : [];

            return [
                'label' => $label,
                'value' => $value,
                'default' => ! empty($option['default']),
                'fields' => $fields,
            ];
        }

        $value = (string) $option;

        return [
            'label' => $value,
            'value' => $value,
            'default' => false,
            'fields' => [],
        ];
    }

    /**
     * Normalizes one checkbox option to a value and a label.
     *
     * @return array<string, mixed>
     */
    private function normalizeCheckboxOption(mixed $option): array
    {
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
    }

    private function humanizeKey(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Builds the form action URL.
     */
    private function getFormActionUrl(string $token): string
    {
        // The token is carried by the hidden field and the session cookie, never
        // by the URL: a link to a half-filled registration must not resume it.
        unset($token);

        return remove_query_arg([ 'token', 'reset_wizard', '_cjw_reset_nonce' ]);
    }

    /**
     * Builds a reset URL protected by a nonce.
     */
    private function getResetUrl(string $token): string
    {
        $url = add_query_arg('reset_wizard', '1', remove_query_arg([ 'token', '_cjw_reset_nonce' ]));

        return wp_nonce_url($url, 'cjw_wizard_reset_' . $token, '_cjw_reset_nonce');
    }

    /**
     * Builds an input name from the current prefix.
     */
    private function buildInputName(string $namePrefix, string $key): string
    {
        $inputName = "{$namePrefix}{$key}";

        if (str_contains($namePrefix, '[')) {
            $inputName .= ']';
        }

        return $inputName;
    }

    /**
     * Builds a DOM-safe input ID.
     */
    private function buildInputId(string $inputName): string
    {
        return sanitize_html_class(str_replace([ '][', '[', ']' ], [ '_', '_', '' ], $inputName));
    }

    /**
     * Serializes a field condition into data attributes for front-end toggling.
     *
     * @param array<string, mixed> $node
     */
    private function buildConditionAttributes(array $node): string
    {
        $condition = $node['condition'] ?? null;

        if (! is_array($condition) || empty($condition['field']) || empty($condition['operator'])) {
            return '';
        }

        return sprintf(
            'data-condition-field="%s" data-condition-operator="%s" data-condition-value="%s"',
            esc_attr((string) $condition['field']),
            esc_attr((string) $condition['operator']),
            esc_attr((string) ($condition['value'] ?? ''))
        );
    }

    /**
     * This form's settings, over the defaults.
     *
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        return cjw_localize_value(wp_parse_args(
            $this->config['settings'] ?? [],
            CJW_Wizard_Defaults::settings()
        ));
    }
}
