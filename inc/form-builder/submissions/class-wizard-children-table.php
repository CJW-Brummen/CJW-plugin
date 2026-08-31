<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * One row per child, rather than one row per registration.
 *
 * A family registering three children is a single submission, so the
 * submissions screen answers "who filled this in" and cannot answer "who is
 * coming". That second question is the one a camp actually works from -- group
 * lists, capacity, allergies, birth dates -- and it needs the repeater rows
 * pulled apart.
 *
 * Nothing is stored twice: the rows are assembled from each submission's payload
 * against the config snapshot saved with it, so a child inherits their
 * registration's status and disappears the moment retention anonymises it.
 */
final class CJW_Wizard_Children_Table extends WP_List_Table
{
    private CJW_Wizard_Submission_Repository $repository;

    /**
     * Assembled child rows for the current page.
     *
     * @var array<int, array<string, string|int>>
     */
    private array $rows = [];

    public function __construct(CJW_Wizard_Submission_Repository $repository)
    {
        parent::__construct([
            'singular' => 'kind',
            'plural' => 'kinderen',
            'ajax' => false,
        ]);

        $this->repository = $repository;
    }

    /**
     * The columns a group leader actually reads.
     *
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'name' => __('Kind', 'cjw'),
            'birth_date' => __('Geboortedatum', 'cjw'),
            'camp' => __('Kamp', 'cjw'),
            'photo_consent' => __('Foto’s', 'cjw'),
            'status' => __('Status', 'cjw'),
            'contact' => __('Contact', 'cjw'),
            'submitted' => __('Ontvangen', 'cjw'),
        ];
    }

    /**
     * Everything here is sortable; the table is small enough to sort in PHP.
     *
     * @return array<string, array<int, string|bool>>
     */
    protected function get_sortable_columns(): array
    {
        return [
            'name' => [ 'name', false ],
            'birth_date' => [ 'birth_date', false ],
            'camp' => [ 'camp', false ],
            'photo_consent' => [ 'photo_consent', false ],
            'status' => [ 'status', false ],
            'submitted' => [ 'submitted', true ],
        ];
    }

    public function no_items(): void
    {
        esc_html_e('Nog geen kinderen ingeschreven. Zodra een formulier velden met de betekenis “voornaam van het kind” heeft, verschijnen ze hier.', 'cjw');
    }

    public function prepare_items(): void
    {
        $this->rows = $this->collect();

        $this->applySearch();
        $this->applyFilters();
        $this->applySort();

        $perPage = 50;
        $current = max(1, (int) $this->get_pagenum());
        $total = count($this->rows);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]);

        $this->items = array_slice($this->rows, ($current - 1) * $perPage, $perPage);
        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
    }

    /**
     * Expands every submission into its children.
     *
     * @return array<int, array<string, string|int>>
     */
    private function collect(): array
    {
        $submissions = get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::inAllList(),
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $rows = [];

        foreach ($submissions as $submission) {
            $id = (int) $submission->ID;

            // An anonymised registration has no child left to list.
            if ($this->repository->is_anonymised($id)) {
                continue;
            }

            $snapshot = get_post_meta($id, CJW_Wizard_Submission_Repository::META_SNAPSHOT, true);
            $config = is_string($snapshot) ? json_decode($snapshot, true) : null;

            if (! is_array($config)) {
                continue;
            }

            foreach (CJW_Wizard_Field_Roles::childRows($config, $this->repository->get_payload($id)) as $child) {
                $rows[] = [
                    'name' => $child['name'],
                    'birth_date' => $child['birth_date'],
                    'camp' => $child['camp'],
                    // Normalised here rather than shown raw: the field can be a
                    // checkbox, a radio or a select depending on how the form
                    // was built, so the payload holds '1', 'on' or 'Ja' for the
                    // same answer and a column of those is unsortable.
                    'photo_consent' => CJW_Wizard_Submission_Repository::consentValue($child['photo_consent']),
                    'status' => (string) get_post_status($id),
                    'contact' => $this->repository->get_email($id),
                    'submitted' => get_post_time('Y-m-d H:i', false, $submission),
                    'submission_id' => $id,
                ];
            }
        }

        return $rows;
    }

    private function applySearch(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list-table search box, not a state change.
        $term = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['s'])) : '';

        if ($term === '') {
            return;
        }

        $this->rows = array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => stripos((string) $row['name'], $term) !== false
                || stripos((string) $row['contact'], $term) !== false
        ));
    }

    private function applyFilters(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $camp = isset($_REQUEST['cjw_camp']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['cjw_camp'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $status = isset($_REQUEST['cjw_status']) ? sanitize_key(wp_unslash((string) $_REQUEST['cjw_status'])) : '';

        if ($camp !== '') {
            $this->rows = array_values(array_filter($this->rows, static fn(array $row): bool => $row['camp'] === $camp));
        }

        if ($status !== '') {
            $this->rows = array_values(array_filter($this->rows, static fn(array $row): bool => $row['status'] === $status));
        }
    }

    private function applySort(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash((string) $_REQUEST['orderby'])) : 'submitted';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $direction = isset($_REQUEST['order'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
            ? strtolower(sanitize_key(wp_unslash((string) $_REQUEST['order'])))
            : 'desc';

        $order = $direction === 'asc' ? 1 : -1;

        if (! array_key_exists($orderby, $this->get_sortable_columns())) {
            $orderby = 'submitted';
        }

        usort(
            $this->rows,
            static function (array $a, array $b) use ($orderby, $order): int {
                // Natural, case-insensitive: "Anna 10" should not sort above
                // "Anna 9", and a name is not a case-sensitive token.
                return strnatcasecmp((string) $a[ $orderby ], (string) $b[ $orderby ]) * $order;
            }
        );
    }

    /**
     * Any column without its own renderer.
     *
     * @param array  $item        Row.
     * @param string $column_name Column key.
     * @param array<string, mixed> $item
     */
    public function column_default($item, $column_name): string
    {
        return esc_html($this->valueOrDash((string) ($item[ $column_name ] ?? '')));
    }

    /**
     * The child's name, linking to the registration it came from.
     *
     * @param array $item Row.
     * @param array<string, mixed> $item
     */
    public function column_name(array $item): string
    {
        $link = get_edit_post_link((int) $item['submission_id']);
        $name = $this->valueOrDash((string) $item['name']);

        if ($link === null) {
            return esc_html($name);
        }

        return sprintf(
            '<strong><a href="%1$s">%2$s</a></strong>',
            esc_url($link),
            esc_html($name)
        );
    }

    /**
     * Whether this child may be photographed.
     *
     * A refusal is the only answer worth making loud. This screen is read on a
     * field with a camera in hand, and the one thing that must not be missed is
     * the child who is not in the pictures.
     *
     * @param array $item Row.
     * @param array<string, mixed> $item
     */
    public function column_photo_consent(array $item): string
    {
        if ((string) ($item['photo_consent'] ?? '') === 'ja') {
            return '<span style="color:#646970;">' . esc_html__('ja', 'cjw') . '</span>';
        }

        return '<strong style="color:#b32d2e;">' . esc_html__('nee', 'cjw') . '</strong>';
    }

    /**
     * The status of the registration this child belongs to.
     *
     * @param array $item Row.
     * @param array<string, mixed> $item
     */
    public function column_status(array $item): string
    {
        // Pre-escaped by the badge helper.
        return CJW_Wizard_Submission_Status::badge((string) $item['status']);
    }

    /**
     * Birth date with the age beside it.
     *
     * @param array $item Row.
     * @param array<string, mixed> $item
     */
    public function column_birth_date(array $item): string
    {
        $raw = (string) $item['birth_date'];

        if ($raw === '') {
            return esc_html($this->valueOrDash($raw));
        }

        $date = date_create_immutable($raw);

        if ($date === false) {
            return esc_html($raw);
        }

        // The age is what a group leader is actually reading for.
        $age = $date->diff(new DateTimeImmutable('now'))->y;

        return sprintf(
            '%1$s <span style="color:#646970;">(%2$s)</span>',
            esc_html(wp_date('j M Y', $date->getTimestamp())),
            esc_html(sprintf(
                /* translators: %d: age in years. */
                _n('%d jaar', '%d jaar', $age, 'cjw'),
                $age
            ))
        );
    }

    /**
     * The camp and status dropdowns above the table.
     *
     * @param string $which Top or bottom of the table.
     */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads back the filter for the selected state.
        $camp = isset($_REQUEST['cjw_camp']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['cjw_camp'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $status = isset($_REQUEST['cjw_status']) ? sanitize_key(wp_unslash((string) $_REQUEST['cjw_status'])) : '';

        echo '<div class="alignleft actions">';

        $camps = $this->repository->known_camp_types();

        if ($camps !== []) {
            echo '<select name="cjw_camp"><option value="">' . esc_html__('Alle kampen', 'cjw') . '</option>';

            foreach ($camps as $option) {
                printf(
                    '<option value="%1$s" %2$s>%3$s</option>',
                    esc_attr($option),
                    selected($camp, $option, false),
                    esc_html($option)
                );
            }

            echo '</select>';
        }

        echo '<select name="cjw_status"><option value="">' . esc_html__('Alle statussen', 'cjw') . '</option>';

        foreach (CJW_Wizard_Submission_Status::all() as $value => $label) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($value),
                selected($status, $value, false),
                esc_html($label)
            );
        }

        echo '</select>';

        submit_button(__('Filter', 'cjw'), '', 'filter_action', false);

        echo '</div>';
    }

    private function valueOrDash(string $value): string
    {
        return $value !== '' ? $value : '—';
    }
}
