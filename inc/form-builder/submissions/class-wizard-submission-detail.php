<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One registration, laid out the way somebody actually reads it.
 *
 * The first version flattened everything into a single two-column table, so a
 * family with three children produced about thirty undifferentiated rows: each
 * child's camp, birth date, diet and medical notes scattered among the parent's
 * address and the transport checkboxes, with every unanswered question taking
 * up a line of its own.
 *
 * This groups by the form's own shape -- a block per child, then the parent,
 * then whatever else the form asks -- puts the four things worth knowing at a
 * glance on top, and hides the empty answers behind a toggle so an ordinary
 * registration is short and an unusual one stands out.
 */
final class CJW_Wizard_Submission_Detail
{
    private CJW_Wizard_Submission_Repository $repository;

    public function __construct(CJW_Wizard_Submission_Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Renders the whole detail view for one submission.
     *
     * @param int $submissionId Submission post id.
     */
    public function render(int $submissionId): void
    {
        if ($this->repository->is_anonymised($submissionId)) {
            echo '<p>' . esc_html__(
                'De persoonsgegevens van deze inschrijving zijn gewist volgens de bewaartermijn. De inschrijving zelf telt nog mee voor de jaarcijfers.',
                'cjw'
            ) . '</p>';

            return;
        }

        $config = $this->config($submissionId);
        $payload = $this->repository->get_payload($submissionId);

        if ($config === [] || $payload === []) {
            echo '<p>' . esc_html__('Geen opgeslagen gegevens gevonden.', 'cjw') . '</p>';

            return;
        }

        $this->renderSummary($submissionId, $config, $payload);

        echo '<div class="cjw-detail">';

        $childKey = $this->childRepeaterKey($config);

        if ($childKey !== '') {
            $this->renderChildren($config, $payload, $childKey);
        }

        $this->renderRemainingSteps($config, $payload, $childKey);

        echo '</div>';
    }

    /**
     * The four things worth seeing before scrolling.
     *
     * @param array $config  Config snapshot.
     * @param array $payload Submitted answers.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $payload
     */
    private function renderSummary(int $submissionId, array $config, array $payload): void
    {
        $children = CJW_Wizard_Field_Roles::childRows($config, $payload);
        $amount = $this->amount($submissionId, $children, $payload);
        $email = $this->repository->get_email($submissionId);
        $phone = $this->firstValueByLabel($config, $payload, [ 'telefoon' ]);

        echo '<div class="cjw-summary">';

        $this->summaryCell(
            __('Kind(eren)', 'cjw'),
            implode('<br>', array_map(static fn(array $c): string => esc_html($c['name']), $children))
        );

        $this->summaryCell(
            __('Kamp', 'cjw'),
            implode('<br>', array_map(
                static fn(array $c): string => esc_html($c['camp'] !== '' ? $c['camp'] : '—'),
                $children
            ))
        );

        $this->summaryCell(__('Bedrag', 'cjw'), $amount);

        $contact = [];

        if ($email !== '') {
            $contact[] = $this->copyable($email);
        }

        if ($phone !== '') {
            $contact[] = $this->copyable($phone);
        }

        $this->summaryCell(__('Contact', 'cjw'), $contact === [] ? '—' : implode('<br>', $contact));

        $consent = CJW_Wizard_Privacy_Consent::recorded($submissionId);

        $this->summaryCell(
            __('Privacy', 'cjw'),
            $consent === null
                ? esc_html__('Niet vastgelegd', 'cjw')
                : sprintf(
                    // The wording that was on screen sits in the title, so the
                    // question "what did this parent agree to" is answerable
                    // even after the text has since been rewritten.
                    '<span title="%s">%s</span>',
                    esc_attr($consent['label'] . "\n\n" . $consent['text']),
                    esc_html(sprintf(
                        /* translators: %s: date and time. */
                        __('Akkoord op %s', 'cjw'),
                        wp_date(__('j F Y \o\m H:i', 'cjw'), $consent['at'])
                    ))
                )
        );

        echo '</div>';
    }

    /**
     * One cell of the summary bar.
     *
     * @param string $label Cell heading.
     * @param string $value Pre-escaped cell content.
     */
    private function summaryCell(string $label, string $value): void
    {
        printf(
            '<div class="cjw-summary-cell"><span class="cjw-summary-label">%1$s</span><div class="cjw-summary-value">%2$s</div></div>',
            esc_html($label),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers escape; copyable() builds its own escaped markup.
            $value !== '' ? $value : '—'
        );
    }

    /**
     * A value with a button that copies it.
     */
    private function copyable(string $value): string
    {
        return sprintf(
            '<span class="cjw-copy-row"><span class="cjw-copy-value">%1$s</span>'
            . '<button type="button" class="button-link cjw-copy" data-cjw-copy="%2$s" aria-label="%3$s">%4$s</button></span>',
            esc_html($value),
            esc_attr($value),
            esc_attr(sprintf(
                /* translators: %s: the value that will be copied. */
                __('Kopieer %s', 'cjw'),
                $value
            )),
            esc_html__('kopieer', 'cjw')
        );
    }

    /**
     * The total the parent owes, rendered for this screen.
     *
     * Read from the frozen amount rather than recomputed: the arithmetic needs
     * the payload, and once the retention sweep has erased that, recomputing
     * answers zero. Registrations taken before the amount was frozen have
     * nothing stored, so those fall back to the sum -- while their payload is
     * still there to sum.
     *
     * @param int   $submissionId Registration post id.
     * @param array $children     Child records.
     * @param array $payload      Submitted answers.
     * @param array<int, mixed>    $children
     * @param array<string, mixed> $payload
     */
    private function amount(int $submissionId, array $children, array $payload): string
    {
        $frozen = CJW_Wizard_Payments::amount($submissionId);
        $paid = CJW_Wizard_Payments::paid($submissionId);

        if ($frozen['snapshot'] > 0 || $frozen['override'] !== null) {
            return $this->frozenAmount($frozen, $paid);
        }

        $amount = CJW_Wizard_Amount::of($children, $payload);
        $parts = [];

        if ($amount['camp'] > 0) {
            $parts[] = sprintf(
                /* translators: %s: camp fee in euros. */
                esc_html__('€%s kampgeld', 'cjw'),
                esc_html(number_format_i18n($amount['camp']))
            );
        }

        if ($amount['donation'] > 0) {
            $parts[] = sprintf(
                /* translators: %s: donation in euros. */
                esc_html__('€%s donatie', 'cjw'),
                esc_html(number_format_i18n($amount['donation']))
            );
        }

        if ($parts === []) {
            return '—';
        }

        $sum = '<strong>€' . esc_html(number_format_i18n($amount['total'])) . '</strong>';

        if (count($parts) > 1) {
            $sum .= '<br><span class="cjw-muted">' . implode(' + ', $parts) . '</span>';
        }

        if ($amount['unpriced'] > 0) {
            $sum .= '<br><span class="cjw-muted">' . esc_html(sprintf(
                /* translators: %d: number of children whose camp carries no price. */
                _n('%d kamp zonder prijs in de naam', '%d kampen zonder prijs in de naam', $amount['unpriced'], 'cjw'),
                $amount['unpriced']
            )) . '</span>';
        }

        return $sum;
    }

    /**
     * The frozen amount, with what is still open under it.
     *
     * @param array{camp: int, donation: int, snapshot: int, due: int, override: ?int, unpriced: int} $amount
     * @param int                                                                                    $paid Received, in cents.
     */
    private function frozenAmount(array $amount, int $paid): string
    {
        $sum = '<strong>' . esc_html(CJW_Wizard_Payments::formatAmount($amount['due'])) . '</strong>';
        $outstanding = $amount['due'] - $paid;

        if ($amount['override'] !== null && $amount['override'] !== $amount['snapshot']) {
            $sum .= '<br><span class="cjw-muted">' . esc_html(sprintf(
                /* translators: %s: the amount the form worked out. */
                __('aangepast, formulier: %s', 'cjw'),
                CJW_Wizard_Payments::formatAmount($amount['snapshot'])
            )) . '</span>';
        } elseif ($amount['camp'] > 0 && $amount['donation'] > 0) {
            $sum .= '<br><span class="cjw-muted">' . esc_html(sprintf(
                /* translators: 1: camp fee, 2: donation. */
                __('%1$s kampgeld + %2$s donatie', 'cjw'),
                CJW_Wizard_Payments::formatAmount($amount['camp']),
                CJW_Wizard_Payments::formatAmount($amount['donation'])
            )) . '</span>';
        }

        if ($amount['due'] > 0 && $outstanding <= 0) {
            $sum .= '<br><span style="color:#007017;">' . esc_html__('voldaan', 'cjw') . '</span>';
        } elseif ($paid !== 0) {
            $sum .= '<br><span style="color:#b32d2e;">' . esc_html(sprintf(
                /* translators: %s: outstanding amount. */
                __('%s openstaand', 'cjw'),
                CJW_Wizard_Payments::formatAmount($outstanding)
            )) . '</span>';
        }

        if ($amount['unpriced'] > 0) {
            $sum .= '<br><span class="cjw-muted">' . esc_html(sprintf(
                /* translators: %d: number of children whose camp carries no price. */
                _n('%d kamp zonder prijs in de naam', '%d kampen zonder prijs in de naam', $amount['unpriced'], 'cjw'),
                $amount['unpriced']
            )) . '</span>';
        }

        return $sum;
    }

    /**
     * The key of the repeater that holds the children, if the form has one.
     *
     * @param array $config Config snapshot.
     * @param array<string, mixed> $config
     */
    private function childRepeaterKey(array $config): string
    {
        $path = CJW_Wizard_Field_Roles::pathFor($config, CJW_Wizard_Field_Roles::CHILD_NAME);

        if ($path === '' || ! str_contains($path, '.*.')) {
            return '';
        }

        return explode('.*.', $path)[0];
    }

    /**
     * A block per child.
     *
     * @param array  $config   Config snapshot.
     * @param array  $payload  Submitted answers.
     * @param string $childKey Repeater key holding the children.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $payload
     */
    private function renderChildren(array $config, array $payload, string $childKey): void
    {
        $field = $this->findField($config, $childKey);
        $rows = is_array($payload[ $childKey ] ?? null) ? $payload[ $childKey ] : [];
        $names = CJW_Wizard_Field_Roles::childRows($config, $payload);
        $index = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $answers = $this->answers(is_array($field['fields'] ?? null) ? $field['fields'] : [], $row);

            if ($answers === []) {
                continue;
            }

            $name = $names[ $index ]['name'] ?? '';
            ++$index;

            $this->section(
                $name !== '' ? $name : sprintf(
                    /* translators: %d: position of the child in the registration. */
                    __('Kind %d', 'cjw'),
                    $index
                ),
                $answers
            );
        }
    }

    /**
     * Every step that is not the children repeater.
     *
     * @param array  $config   Config snapshot.
     * @param array  $payload  Submitted answers.
     * @param string $childKey Repeater key to skip.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $payload
     */
    private function renderRemainingSteps(array $config, array $payload, string $childKey): void
    {
        foreach (is_array($config['steps'] ?? null) ? $config['steps'] : [] as $step) {
            if (! is_array($step) || ($step['type'] ?? '') === 'summary') {
                continue;
            }

            $fields = array_values(array_filter(
                is_array($step['fields'] ?? null) ? $step['fields'] : [],
                static fn($f): bool => is_array($f) && ($f['key'] ?? '') !== $childKey
            ));

            $answers = $this->answers($fields, $payload);

            if ($answers !== []) {
                $this->section((string) ($step['label'] ?? ''), $answers);
            }
        }
    }

    /**
     * Flattens a level of fields into label/value pairs, in the order asked.
     *
     * @param array $fields Field configuration.
     * @param array $data   Answers at this level.
     * @param array<string, mixed> $data
     * @param array<int, mixed> $fields
     *
     * @return array<int, array{label: string, value: string, empty: bool}>
     */
    private function answers(array $fields, array $data): array
    {
        $answers = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            $key = (string) ($field['key'] ?? '');

            // Headings and explanatory blocks are form furniture, not answers.
            if (in_array($type, [ 'heading', 'html' ], true) || $key === '') {
                continue;
            }

            if ($type === 'repeater') {
                $answers[] = [
                    'label' => (string) ($field['label'] ?? $key),
                    'value' => $this->repeaterValue($field, $data[ $key ] ?? null),
                    'empty' => $this->repeaterValue($field, $data[ $key ] ?? null) === '',
                ];

                continue;
            }

            $value = $this->readable($data[ $key ] ?? null, $type);

            $answers[] = [
                'label' => (string) ($field['label'] ?? $key),
                'value' => $value,
                'empty' => $value === '',
            ];

            // Fields that hang off the chosen radio option.
            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                if (is_array($option) && is_array($option['fields'] ?? null)) {
                    $answers = [ ...$answers, ...$this->answers($option['fields'], $data) ];
                }
            }
        }

        return $answers;
    }

    /**
     * A nested repeater rendered as a short list.
     *
     * @param array $field Repeater configuration.
     * @param mixed $value Submitted rows.
     * @param array<string, mixed> $field
     */
    private function repeaterValue(array $field, mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        $lines = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $parts = [];

            foreach (is_array($field['fields'] ?? null) ? $field['fields'] : [] as $sub) {
                if (! is_array($sub)) {
                    continue;
                }

                $subValue = $this->readable($row[ (string) ($sub['key'] ?? '') ] ?? null, (string) ($sub['type'] ?? 'text'));

                if ($subValue !== '') {
                    $parts[] = $subValue;
                }
            }

            if ($parts !== []) {
                $lines[] = implode(' — ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Turns a stored answer into something worth reading.
     *
     * @param mixed  $value Raw answer.
     * @param string $type  Field type.
     */
    private function readable(mixed $value, string $type): string
    {
        if (is_array($value)) {
            $flat = array_filter(array_map(
                static fn($v): string => is_scalar($v) ? trim((string) $v) : '',
                $value
            ));

            return implode(', ', $flat);
        }

        if (! is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);

        if ($type === 'checkbox') {
            return $value === '' ? '' : __('Ja', 'cjw');
        }

        return $value;
    }

    /**
     * One headed block of label/value rows.
     *
     * @param string $title   Block heading.
     * @param array  $answers Label/value pairs.
     * @param array<int, array<string, bool|string>> $answers
     */
    private function section(string $title, array $answers): void
    {
        printf('<h3 class="cjw-section-title">%s</h3>', esc_html($title));
        echo '<table class="widefat striped cjw-section"><tbody>';

        foreach ($answers as $answer) {
            printf(
                '<tr class="%1$s"><th scope="row">%2$s</th><td>%3$s</td></tr>',
                $answer['empty'] ? 'cjw-empty' : '',
                esc_html($answer['label']),
                $answer['empty']
                    ? '<span class="cjw-muted">—</span>'
                    : nl2br(esc_html($answer['value']))
            );
        }

        echo '</tbody></table>';
    }

    /**
     * First answer whose field label contains one of the given words.
     *
     * @param array              $config Config snapshot.
     * @param array              $data   Answers.
     * @param array<int, string> $words  Words to look for, lowercase.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     */
    private function firstValueByLabel(array $config, array $data, array $words): string
    {
        foreach (is_array($config['steps'] ?? null) ? $config['steps'] : [] as $step) {
            foreach (is_array($step['fields'] ?? null) ? $step['fields'] : [] as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $key = (string) ($field['key'] ?? '');
                $label = strtolower((string) ($field['label'] ?? ''));

                foreach ($words as $word) {
                    if ($key !== '' && str_contains($label, $word) && ($data[ $key ] ?? '') !== '') {
                        return trim((string) $data[ $key ]);
                    }
                }
            }
        }

        return '';
    }

    /**
     * Finds a top-level field by key anywhere in the form.
     *
     * @param array  $config Config snapshot.
     * @param string $key    Field key.
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function findField(array $config, string $key): array
    {
        foreach (is_array($config['steps'] ?? null) ? $config['steps'] : [] as $step) {
            foreach (is_array($step['fields'] ?? null) ? $step['fields'] : [] as $field) {
                if (is_array($field) && ($field['key'] ?? '') === $key) {
                    return $field;
                }
            }
        }

        return [];
    }

    /**
     * The config as it was when this registration was taken.
     *
     * @param int $submissionId Submission post id.
     *
     * @return array<string, mixed>
     */
    private function config(int $submissionId): array
    {
        return $this->repository->get_config($submissionId);
    }
}
