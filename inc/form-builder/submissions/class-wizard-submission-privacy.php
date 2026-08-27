<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Answers AVG access and erasure requests through WordPress's own privacy tools.
 *
 * A parent has the right to see what is held about them and to have it removed.
 * Without this, both requests have to be served by hand -- someone opening
 * submissions one by one looking for a name -- which is slow enough that it does
 * not reliably happen at all.
 *
 * Registering here puts registrations into the flows an administrator already
 * has under Tools: Personal Data Export and Erase Personal Data.
 */
final class CJW_Wizard_Submission_Privacy
{
    /**
     * Submissions handled per request page, so a long history cannot time out.
     */
    private const int PER_PAGE = 25;

    private CJW_Wizard_Submission_Store $repository;

    public function __construct(CJW_Wizard_Submission_Store $repository)
    {
        $this->repository = $repository;
    }

    public function init(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ]);
        add_filter('wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ]);
    }

    /**
     * Adds registrations to the Personal Data Export tool.
     *
     * @param array $exporters Registered exporters.
     * @param array<string, mixed> $exporters
     *
     * @return array<string, mixed>
     */
    public function register_exporter(array $exporters): array
    {
        $exporters['cjw-wizard-submissions'] = [
            'exporter_friendly_name' => __('Kampinschrijvingen', 'cjw'),
            'callback' => [ $this, 'export' ],
        ];

        return $exporters;
    }

    /**
     * Adds registrations to the Erase Personal Data tool.
     *
     * @param array $erasers Registered erasers.
     * @param array<string, mixed> $erasers
     *
     * @return array<string, mixed>
     */
    public function register_eraser(array $erasers): array
    {
        $erasers['cjw-wizard-submissions'] = [
            'eraser_friendly_name' => __('Kampinschrijvingen', 'cjw'),
            'callback' => [ $this, 'erase' ],
        ];

        return $erasers;
    }

    /**
     * Returns everything held about one address.
     *
     * Field labels come from the config snapshot saved with the submission, so a
     * parent reads the questions as they were actually asked rather than the
     * internal keys, even if the form has been edited since.
     *
     * @param string $email Address being requested.
     * @param int    $page  1-based page number.
     *
     * @return array<string, mixed>
     */
    public function export(string $email, int $page = 1): array
    {
        $submissions = $this->page($email, $page);
        $items = [];

        foreach ($submissions as $submission) {
            $id = (int) $submission->ID;
            $payload = $this->repository->flatten_data($this->repository->get_payload($id));
            $labels = $this->labels($id);

            $data = [
                [
                    'name' => __('Ingediend op', 'cjw'),
                    'value' => get_post_time('Y-m-d H:i', false, $submission),
                ],
            ];

            foreach ($payload as $key => $value) {
                $data[] = [
                    'name' => $labels[ $key ] ?? $key,
                    'value' => $value,
                ];
            }

            $items[] = [
                'group_id' => 'cjw-wizard-submissions',
                'group_label' => __('Kampinschrijvingen', 'cjw'),
                'item_id' => 'cjw-wizard-submission-' . $id,
                'data' => $data,
            ];
        }

        return [
            'data' => $items,
            'done' => count($submissions) < self::PER_PAGE,
        ];
    }

    /**
     * Removes the personal data held for one address.
     *
     * Anonymises rather than deletes: the row survives without anything that
     * identifies anybody, so the camp keeps its own count of how many children
     * came in a given year. Nothing that could re-identify the parent remains.
     *
     * @param string $email Address being erased.
     * @param int    $page  1-based page number.
     *
     * @return array<string, mixed>
     */
    public function erase(string $email, int $page = 1): array
    {
        // Deliberately ignores $page and always takes the head of a fresh query.
        //
        // WordPress drives an eraser as a page-incrementing AJAX loop: page 1,
        // then 2, then 3, until the callback reports done. That assumes the
        // result set is stable, and this one is not -- anonymise() deletes the
        // very meta key get_by_email() matches on, so every row erased on one
        // page drops out of the set the next page offsets into. Offsetting by
        // $page skipped PER_PAGE rows for every page after the first, then
        // reported the request complete with personal data still on disk.
        //
        // The shrinking set is the pagination: keep taking the first PER_PAGE
        // rows until none are left.
        unset($page);

        $all = $this->repository->get_by_email($email);
        $submissions = array_slice($all, 0, self::PER_PAGE);
        $removed = 0;
        $retained = 0;

        foreach ($submissions as $submission) {
            if ($this->repository->anonymise((int) $submission->ID)) {
                ++$removed;
                continue;
            }

            ++$retained;
        }

        $messages = [];

        if ($removed > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of registrations anonymised. */
                _n(
                    'Bij %d kampinschrijving zijn de persoonsgegevens gewist. De inschrijving zelf blijft geteld voor de jaarstatistiek, zonder gegevens die naar een persoon te herleiden zijn.',
                    'Bij %d kampinschrijvingen zijn de persoonsgegevens gewist. De inschrijvingen zelf blijven geteld voor de jaarstatistiek, zonder gegevens die naar een persoon te herleiden zijn.',
                    $removed,
                    'cjw'
                ),
                $removed
            );
        }

        return [
            'items_removed' => $removed,
            'items_retained' => $retained > 0,
            'messages' => $messages,
            // Stop when the set is exhausted, and also when a pass removed
            // nothing: without that second condition a row that cannot be
            // anonymised would keep matching the query and spin the AJAX loop
            // forever.
            'done' => count($all) <= self::PER_PAGE || $removed === 0,
        ];
    }

    /**
     * One page of submissions for an address.
     *
     * Safe for export(), which only reads: the set does not move under it.
     * erase() must not use this -- see the comment there.
     *
     * @return array<int, WP_Post>
     */
    private function page(string $email, int $page): array
    {
        $all = $this->repository->get_by_email($email);

        return array_slice($all, (max(1, $page) - 1) * self::PER_PAGE, self::PER_PAGE);
    }

    /**
     * Field key => the label the form used, from the saved config snapshot.
     *
     * @return array<string, string>
     */
    private function labels(int $submissionId): array
    {
        $snapshot = get_post_meta($submissionId, CJW_Wizard_Submission_Repository::META_SNAPSHOT, true);
        $config = is_string($snapshot) ? json_decode($snapshot, true) : null;

        if (! is_array($config)) {
            return [];
        }

        $labels = [];

        foreach (is_array($config['steps'] ?? null) ? $config['steps'] : [] as $step) {
            if (is_array($step) && is_array($step['fields'] ?? null)) {
                $labels += $this->labelsInFields($step['fields']);
            }
        }

        return $labels;
    }

    /**
     * Collects labels from one level of fields and everything nested under it.
     *
     * @param array $fields Field configuration.
     * @param array<int, mixed> $fields
     *
     * @return array<string, string>
     */
    private function labelsInFields(array $fields): array
    {
        $labels = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');

            if ($key !== '' && ($field['label'] ?? '') !== '') {
                $labels[ $key ] = (string) $field['label'];
            }

            if (is_array($field['fields'] ?? null)) {
                $labels += $this->labelsInFields($field['fields']);
            }

            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                if (is_array($option) && is_array($option['fields'] ?? null)) {
                    $labels += $this->labelsInFields($option['fields']);
                }
            }
        }

        return $labels;
    }
}
