<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The screen an organiser actually works in.
 *
 * Before this it was a list of "Formulier - 2026-08-26 14:02" rows: every
 * registration looked identical, so finding one meant opening them in turn.
 * The columns and filters here all lean on field roles -- the form says which
 * answer is the child's name and which is the camp -- and degrade quietly to a
 * dash on a form that marks nothing.
 */
final class CJW_Wizard_Submission_Admin
{
    private CJW_Wizard_Submission_Repository $repository;
    private CJW_Wizard_Submission_Detail $detail;

    public function __construct(CJW_Wizard_Submission_Repository $repository)
    {
        $this->repository = $repository;
        $this->detail = new CJW_Wizard_Submission_Detail($repository);
    }

    public function init(): void
    {
        $postType = CJW_Wizard_Submission_Repository::POST_TYPE;

        add_action('add_meta_boxes', [ $this, 'register_meta_boxes' ], 10, 2);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue' ]);
        add_action('save_post_' . $postType, [ $this, 'save_notes' ], 10, 2);
        add_filter('manage_' . $postType . '_posts_columns', [ $this, 'filter_columns' ]);
        add_action('manage_' . $postType . '_posts_custom_column', [ $this, 'render_column' ], 10, 2);
        add_filter('manage_edit-' . $postType . '_sortable_columns', [ $this, 'sortable_columns' ]);
        add_action('restrict_manage_posts', [ $this, 'render_filters' ]);
        add_action('pre_get_posts', [ $this, 'apply_filters' ]);
        add_filter('list_table_primary_column', [ $this, 'primary_column' ], 10, 2);
        add_filter('post_row_actions', [ $this, 'row_actions' ], 10, 2);
        add_filter('views_edit-' . $postType, [ $this, 'filter_views' ]);
        add_filter('wp_insert_post_data', [ $this, 'disown' ], 10, 2);
    }

    /**
     * Registers the two boxes the detail screen is made of.
     *
     * The data box is titled after the registration itself. The screen heading
     * is a post type label and so cannot carry the number, and the title field
     * is gone -- which left "Inschrijving #226" stored but nowhere on screen,
     * and the number is precisely what an organiser quotes back to a parent.
     *
     * @param string       $post_type Post type being edited.
     * @param WP_Post|null $post      The row, when WordPress passes one.
     */
    public function register_meta_boxes(string $post_type = '', ?WP_Post $post = null): void
    {
        unset($post_type);

        add_meta_box(
            'cjw_wizard_entry_data',
            $post instanceof WP_Post && $post->post_title !== ''
                ? $post->post_title
                : __('Inzendingsgegevens', 'cjw'),
            [ $this, 'render_submission_meta_box' ],
            CJW_Wizard_Submission_Repository::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'cjw_wizard_entry_notes',
            __('Interne notities', 'cjw'),
            [ $this, 'render_notes_meta_box' ],
            CJW_Wizard_Submission_Repository::POST_TYPE,
            'side'
        );
    }

    /**
     * Renders one submission.
     *
     * Delegated: the layout is substantial enough to own a class, and keeping it
     * out of here stops this file becoming the place everything lands.
     *
     * @param WP_Post $post Submission.
     */
    public function render_submission_meta_box(WP_Post $post): void
    {
        $id = (int) $post->ID;
        $wizardId = $this->repository->get_wizard_id($id);

        echo '<p class="cjw-detail-form"><strong>' . esc_html__('Formulier:', 'cjw') . '</strong> ';
        echo esc_html($wizardId > 0 ? get_the_title($wizardId) : __('Onbekend', 'cjw')) . '</p>';

        // A plain checkbox drives the toggle through CSS alone, so an organiser
        // can still reveal the unanswered questions with JavaScript disabled.
        echo '<p class="cjw-toggle-wrap">';
        echo '<input type="checkbox" id="cjw-show-all" class="cjw-show-all">';
        echo '<label for="cjw-show-all">' . esc_html__('Toon ook de vragen die niet zijn ingevuld', 'cjw') . '</label>';
        echo '</p>';

        $this->detail->render($id);
    }

    /**
     * Styles and the clipboard helper, on the submission screen only.
     *
     * @param string $hook Current admin page.
     */
    public function enqueue(string $hook): void
    {
        if (! in_array($hook, [ 'post.php', 'post-new.php' ], true)) {
            return;
        }

        $screen = get_current_screen();

        if (! $screen instanceof WP_Screen || $screen->post_type !== CJW_Wizard_Submission_Repository::POST_TYPE) {
            return;
        }

        wp_register_style('cjw-submission-detail', false, [], CJW_VERSION);
        wp_enqueue_style('cjw-submission-detail');
        wp_add_inline_style('cjw-submission-detail', $this->styles());

        wp_register_script('cjw-submission-detail', false, [], CJW_VERSION, true);
        wp_enqueue_script('cjw-submission-detail');
        wp_add_inline_script('cjw-submission-detail', $this->script());
    }

    private function styles(): string
    {
        return <<<'CSS'
.cjw-detail-form { margin: 0 0 8px; }
.cjw-toggle-wrap { margin: 0 0 16px; color: #646970; }
.cjw-summary {
    display: flex; flex-wrap: wrap; gap: 1px; margin: 0 0 20px;
    background: #dcdcde; border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden;
}
.cjw-summary-cell { background: #fff; padding: 10px 14px; flex: 1 1 160px; min-width: 0; }
.cjw-summary-label {
    display: block; font-size: 11px; text-transform: uppercase;
    letter-spacing: .04em; color: #646970; margin-bottom: 4px;
}
.cjw-summary-value { font-size: 13px; line-height: 1.5; word-break: break-word; }
.cjw-section-title { margin: 22px 0 6px; font-size: 14px; }
.cjw-section th { width: 34%; font-weight: 600; }
.cjw-muted { color: #8c8f94; }
.cjw-copy-row { display: inline-flex; align-items: center; gap: 6px; }
.cjw-copy { text-decoration: none; font-size: 11px; }
.cjw-show-all:not(:checked) ~ * .cjw-empty,
.cjw-toggle-wrap:has(.cjw-show-all:not(:checked)) ~ .cjw-detail .cjw-empty { display: none; }
CSS;
    }

    private function script(): string
    {
        return <<<'JS'
document.addEventListener('click', (event) => {
	const button = event.target.closest('.cjw-copy');
	if (!button) return;
	event.preventDefault();
	const value = button.dataset.cjwCopy || '';
	const done = () => {
		const original = button.textContent;
		button.textContent = 'gekopieerd';
		setTimeout(() => { button.textContent = original; }, 1200);
	};
	if (navigator.clipboard && window.isSecureContext) {
		navigator.clipboard.writeText(value).then(done).catch(() => {});
		return;
	}
	const field = document.createElement('textarea');
	field.value = value;
	field.style.position = 'fixed';
	field.style.opacity = '0';
	document.body.appendChild(field);
	field.select();
	try { document.execCommand('copy'); done(); } catch (error) { /* clipboard unavailable */ }
	document.body.removeChild(field);
});
JS;
    }

    /**
     * The internal-notes box.
     *
     * @param WP_Post $post Submission.
     */
    public function render_notes_meta_box(WP_Post $post): void
    {
        wp_nonce_field('cjw_wizard_notes', 'cjw_wizard_notes_nonce');

        echo '<p class="description">' . esc_html__('Alleen zichtbaar voor beheerders. Wordt nooit naar de ouder gestuurd.', 'cjw') . '</p>';
        echo '<textarea name="cjw_wizard_notes" rows="6" style="width:100%;">';
        echo esc_textarea($this->repository->get_notes((int) $post->ID));
        echo '</textarea>';
    }

    /**
     * Stores the internal notes.
     *
     * @param int     $post_id Submission post id.
     * @param WP_Post $post    Submission.
     */
    public function save_notes(int $post_id, WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = isset($_POST['cjw_wizard_notes_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['cjw_wizard_notes_nonce']))
            : '';

        if ($nonce === '' || ! wp_verify_nonce($nonce, 'cjw_wizard_notes')) {
            return;
        }

        if (! current_user_can(CJW_Roles::CAPABILITY)) {
            return;
        }

        $notes = isset($_POST['cjw_wizard_notes'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['cjw_wizard_notes']))
            : '';

        unset($post);

        if ($notes === '') {
            delete_post_meta($post_id, CJW_Wizard_Submission_Repository::META_NOTES);

            return;
        }

        update_post_meta($post_id, CJW_Wizard_Submission_Repository::META_NOTES, $notes);
    }

    /**
     * Replaces the default columns with ones that tell registrations apart.
     *
     * @param array $columns Existing columns.
     * @param array<string, mixed> $columns
     *
     * @return array<string, mixed>
     */
    public function filter_columns(array $columns): array
    {
        $built = [
            'cb' => $columns['cb'] ?? '',
            // Not the built-in title column: the title is now "Inschrijving
            // #226", which tells an organiser nothing about who is coming. The
            // names are read from the payload instead, so the column stays
            // useful while the stored title carries no personal data.
            'cjw_children' => __('Naam', 'cjw'),
            'cjw_camp' => __('Kamp', 'cjw'),
            'cjw_foto' => __('Foto’s', 'cjw'),
            'cjw_status' => __('Status', 'cjw'),
        ];

        // Only worth a column when there is more than one form to tell apart.
        if ($this->hasMultipleForms()) {
            $built['cjw_form'] = __('Formulier', 'cjw');
        }

        // Not the built-in date column: for any status other than published it
        // shows "Laatst gewijzigd", so a column headed "Ontvangen" was
        // reporting the last time somebody pressed Opslaan.
        $built['cjw_received'] = __('Ontvangen', 'cjw');

        return $built;
    }

    /**
     * Lets the child and camp columns be sorted.
     *
     * @param array $columns Sortable columns.
     * @param array<string, mixed> $columns
     *
     * @return array<string, mixed>
     */
    public function sortable_columns(array $columns): array
    {
        $columns['cjw_children'] = CJW_Wizard_Submission_Repository::META_CHILD_NAME;
        $columns['cjw_camp'] = CJW_Wizard_Submission_Repository::META_CAMP_TYPE;
        $columns['cjw_received'] = 'date';

        return $columns;
    }

    /**
     * Hangs the row actions under the names rather than the (removed) title.
     *
     * @param string $default Column WordPress would have used.
     * @param string $screen  Screen id.
     */
    public function primary_column(string $default, string $screen): string
    {
        return $screen === 'edit-' . CJW_Wizard_Submission_Repository::POST_TYPE
            ? 'cjw_children'
            : $default;
    }

    /**
     * Drops the "Mijn" tab.
     *
     * A registration is filled in by a parent, not written by anyone with a
     * login, so "mine" can only ever mean "rows I happened to press Opslaan
     * on" -- which is not a thing to filter a season's registrations by.
     *
     * @param array $views Status links above the list.
     * @param array<string, mixed> $views
     *
     * @return array<string, string>
     */
    public function filter_views(array $views): array
    {
        unset($views['mine']);

        return $views;
    }

    /**
     * Keeps registrations unowned.
     *
     * WordPress stamps the editing user onto post_author on every save, so an
     * organiser changing a status would quietly become the "author" of a
     * family's registration -- which is untrue, and is what makes the "Mijn"
     * tab appear in the first place.
     *
     * @param array $data    Row about to be written.
     * @param array $postarr Full post array.
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     *
     * @return array<string, mixed>
     */
    public function disown(array $data, array $postarr): array
    {
        unset($postarr);

        if (($data['post_type'] ?? '') === CJW_Wizard_Submission_Repository::POST_TYPE) {
            $data['post_author'] = 0;
        }

        return $data;
    }

    /**
     * Trims the row actions to the ones that exist.
     *
     * "Bewerken" promised an edit screen; the page it opens only reads back
     * what a parent submitted. "Snel bewerken" was worse -- it offered inline
     * fields for a title nobody types and a slug nobody reads.
     *
     * @param array   $actions Row actions.
     * @param WP_Post $post    The row.
     * @param array<string, mixed> $actions
     *
     * @return array<string, string>
     */
    public function row_actions(array $actions, WP_Post $post): array
    {
        if ($post->post_type !== CJW_Wizard_Submission_Repository::POST_TYPE) {
            return $actions;
        }

        // Matched by prefix, not by the whole key. WordPress spells it
        // "inline hide-if-js" in some versions and "inline hide-if-no-js" in
        // others -- unsetting one literal string quietly did nothing on 7.1,
        // and looked fine because the test agreed with the guess.
        foreach (array_keys($actions) as $key) {
            if (str_starts_with((string) $key, 'inline')) {
                unset($actions[ $key ]);
            }
        }

        $link = get_edit_post_link((int) $post->ID);

        if (isset($actions['edit']) && $link !== null) {
            $actions['edit'] = sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url($link),
                esc_html__('Bekijken', 'cjw')
            );
        }

        return $actions;
    }

    /**
     * Fills one cell of the list table.
     *
     * @param string $column  Column key.
     * @param int    $post_id Submission post id.
     */
    public function render_column(string $column, int $post_id): void
    {
        switch ($column) {
            case 'cjw_children':
                // Pre-escaped by the helper, which builds a link.
                echo $this->childrenCell($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes the link it builds.
                echo $this->duplicateNote($post_id);
                break;

            case 'cjw_status':
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes the colour and the label it builds.
                echo CJW_Wizard_Submission_Status::badge((string) get_post_status($post_id));

                // Only failures are shown here. A confirmation that went out is
                // the expected case and belongs on the submission itself; a
                // parent who was promised one and never got it is the row an
                // organiser has to find without opening two hundred others.
                if (CJW_Wizard_Submission_Confirmation::failed($post_id)) {
                    printf(
                        '<br><span style="color:#b32d2e;white-space:nowrap;">%s</span>',
                        esc_html__('bevestiging mislukt', 'cjw')
                    );
                }
                break;

            case 'cjw_camp':
                echo esc_html($this->valueOrDash($this->repository->get_camp_type($post_id)));
                break;

            case 'cjw_foto':
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes the label it builds.
                echo $this->consentCell($post_id);
                break;

            case 'cjw_received':
                printf(
                    '%1$s<br><span style="color:#646970;">%2$s</span>',
                    esc_html(get_the_date((string) get_option('date_format'), $post_id)),
                    esc_html(get_the_time((string) get_option('time_format'), $post_id))
                );
                break;

            case 'cjw_form':
                $wizardId = $this->repository->get_wizard_id($post_id);
                echo esc_html($wizardId > 0 ? get_the_title($wizardId) : __('Onbekend', 'cjw'));
                break;
        }
    }

    /**
     * Adds "by form" and "by camp" dropdowns above the list.
     *
     * @param string $post_type Post type being listed.
     */
    public function render_filters(string $post_type): void
    {
        if ($post_type !== CJW_Wizard_Submission_Repository::POST_TYPE) {
            return;
        }

        if ($this->hasMultipleForms()) {
            $this->renderWizardFilter();
        }

        $this->renderCampFilter();
        $this->renderConsentFilter();
    }

    /**
     * The "no photo consent" filter.
     *
     * One value, not a yes/no pair. Nobody goes looking for the children who
     * said yes; the question is always which ones did not, asked before a photo
     * goes anywhere.
     *
     * @return void
     */
    private function renderConsentFilter(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads back the filter for the selected state.
        $current = isset($_GET['cjw_consent_filter']) ? sanitize_text_field(wp_unslash((string) $_GET['cjw_consent_filter'])) : '';

        echo '<select name="cjw_consent_filter">';
        echo '<option value="">' . esc_html__('Alle fototoestemming', 'cjw') . '</option>';
        printf(
            '<option value="nee" %1$s>%2$s</option>',
            selected($current, 'nee', false),
            esc_html__('Zonder toestemming', 'cjw')
        );
        printf(
            '<option value="ja" %1$s>%2$s</option>',
            selected($current, 'ja', false),
            esc_html__('Alleen met toestemming', 'cjw')
        );
        echo '</select>';
    }

    /**
     * Applies those dropdowns, and the sortable columns, to the query.
     *
     * @param WP_Query $query Current query.
     */
    public function apply_filters(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== CJW_Wizard_Submission_Repository::POST_TYPE) {
            return;
        }

        // The "Alle" tab asks for no status, and WP_Query then falls back to
        // statuses registered as `public => true`. None of ours are -- they are
        // admin-only -- so the tab came up empty while every per-status tab
        // worked. Name them explicitly.
        if ((string) $query->get('post_status') === '') {
            $query->set('post_status', CJW_Wizard_Submission_Status::inAllList());
        }

        $this->applySearch($query);

        $meta = [];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list-table filter, not a state change.
        $wizardId = isset($_GET['cjw_wizard_filter']) ? absint(wp_unslash($_GET['cjw_wizard_filter'])) : 0;

        if ($wizardId > 0) {
            $meta[] = [
                'key' => CJW_Wizard_Submission_Repository::META_WIZARD_ID,
                'value' => $wizardId,
            ];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $camp = isset($_GET['cjw_camp_filter']) ? sanitize_text_field(wp_unslash((string) $_GET['cjw_camp_filter'])) : '';

        if ($camp !== '') {
            $meta[] = [
                'key' => CJW_Wizard_Submission_Repository::META_CAMP_TYPE,
                'value' => $camp,
            ];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $consent = isset($_GET['cjw_consent_filter']) ? sanitize_text_field(wp_unslash((string) $_GET['cjw_consent_filter'])) : '';

        if (in_array($consent, [ 'ja', 'nee' ], true)) {
            // Matches a registration where any one child answered this way,
            // because consent is stored a row per child. "Zonder toestemming"
            // has to find a family where one of three said no.
            $meta[] = [
                'key' => CJW_Wizard_Submission_Repository::META_PHOTO_CONSENT,
                'value' => $consent,
            ];
        }

        if ($meta !== []) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- An admin list filter over one camp's submissions; the alternative is no filter at all.
            $query->set('meta_query', $meta);
        }

        $orderby = (string) $query->get('orderby');

        if (in_array($orderby, [ CJW_Wizard_Submission_Repository::META_CHILD_NAME, CJW_Wizard_Submission_Repository::META_CAMP_TYPE ], true)) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting a list table by a column the organiser clicked.
            $query->set('meta_key', $orderby);
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Makes the search box find children again.
     *
     * The names used to be the post title, so WordPress's own search covered
     * them. They now live in meta, which `s` does not reach -- and a meta_query
     * cannot simply be bolted on, because WP_Query would AND it with the title
     * search instead of widening it. So the term is resolved to a set of ids
     * first and handed back as `post__in`.
     *
     * @param WP_Query $query Current query.
     */
    private function applySearch(WP_Query $query): void
    {
        $term = trim((string) $query->get('s'));

        if ($term === '') {
            return;
        }

        $query->set('s', '');

        $statuses = (array) $query->get('post_status');

        $byMeta = get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => $statuses,
            'numberposts' => -1,
            'fields' => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- An admin search over one season's registrations; the alternative is a search box that finds nothing.
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => CJW_Wizard_Submission_Repository::META_CHILD_NAME,
                    'value' => $term,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => CJW_Wizard_Submission_Repository::META_EMAIL,
                    'value' => $term,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => CJW_Wizard_Submission_Repository::META_CAMP_TYPE,
                    'value' => $term,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        // Keeps "Inschrijving #226", and a bare "226", finding the row.
        $byTitle = get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => $statuses,
            'numberposts' => -1,
            'fields' => 'ids',
            's' => $term,
        ]);

        $ids = array_values(array_unique([ ...array_map('intval', $byMeta), ...array_map('intval', $byTitle) ]));

        // 0 matches nothing, which is the point: an empty post__in would be
        // ignored and the search would return the whole list.
        $query->set('post__in', $ids !== [] ? $ids : [ 0 ]);
    }

    private function renderWizardFilter(): void
    {
        $wizards = get_posts([
            'post_type' => 'cjw_wizard',
            'post_status' => [ 'publish', 'draft', 'private' ],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        if ($wizards === []) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads back the filter for the selected state.
        $current = isset($_GET['cjw_wizard_filter']) ? absint(wp_unslash($_GET['cjw_wizard_filter'])) : 0;

        echo '<select name="cjw_wizard_filter">';
        echo '<option value="">' . esc_html__('Alle formulieren', 'cjw') . '</option>';

        foreach ($wizards as $wizard) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr((string) $wizard->ID),
                selected($current, (int) $wizard->ID, false),
                esc_html($wizard->post_title !== '' ? $wizard->post_title : __('Zonder titel', 'cjw'))
            );
        }

        echo '</select>';
    }

    private function renderCampFilter(): void
    {
        $camps = $this->repository->known_camp_types();

        if ($camps === []) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads back the filter for the selected state.
        $current = isset($_GET['cjw_camp_filter']) ? sanitize_text_field(wp_unslash((string) $_GET['cjw_camp_filter'])) : '';

        echo '<select name="cjw_camp_filter">';
        echo '<option value="">' . esc_html__('Alle kampen', 'cjw') . '</option>';

        foreach ($camps as $camp) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($camp),
                selected($current, $camp, false),
                esc_html($camp)
            );
        }

        echo '</select>';
    }

    /**
     * A note under the name when this registration repeats an earlier one.
     *
     * Says "mogelijk", because it is a guess: a name and a date of birth match,
     * which is usually the same child registered twice and occasionally two
     * children who share both. The parent was told nothing and nothing was
     * refused, so this line is the only place it surfaces.
     *
     * @param int $submissionId Registration.
     * @return string Escaped HTML, or an empty string.
     */
    private function duplicateNote(int $submissionId): string
    {
        $earlier = (int) get_post_meta($submissionId, CJW_Wizard_Submission_Repository::META_DUPLICATE_OF, true);

        if ($earlier < 1 || get_post_status($earlier) === false) {
            return '';
        }

        $link = get_edit_post_link($earlier);

        $label = sprintf(
            /* translators: %d: the earlier registration's number. */
            __('mogelijk dubbel met #%d', 'cjw'),
            $earlier
        );

        if (! is_string($link) || $link === '') {
            return '<br><span style="color:#b32d2e;">' . esc_html($label) . '</span>';
        }

        return '<br><a href="' . esc_url($link) . '" style="color:#b32d2e;">' . esc_html($label) . '</a>';
    }

    /**
     * The photo consent cell: what the children on this registration answered.
     *
     * @param int $submissionId Registration.
     * @return string Escaped HTML.
     */
    private function consentCell(int $submissionId): string
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reading every row of one key on one post.
        $answers = get_post_meta($submissionId, CJW_Wizard_Submission_Repository::META_PHOTO_CONSENT, false);
        $answers = is_array($answers) ? array_map('strval', $answers) : [];

        if ($answers === []) {
            return esc_html($this->valueOrDash(''));
        }

        $refused = count(array_filter($answers, static fn(string $answer): bool => $answer === 'nee'));

        if ($refused === 0) {
            return '<span style="color:#646970;">' . esc_html__('ja', 'cjw') . '</span>';
        }

        // Coloured, and it says how many. A family where one of three children
        // may not be photographed is the case this column exists for, and "nee"
        // on its own would read as all of them.
        $label = count($answers) === $refused
            ? __('nee', 'cjw')
            : sprintf(
                /* translators: 1: children without consent, 2: children on the registration. */
                __('%1$d van %2$d niet', 'cjw'),
                $refused,
                count($answers)
            );

        return '<strong style="color:#b32d2e;">' . esc_html($label) . '</strong>';
    }

    /**
     * Whether the site has more than one form, and so anything to tell apart.
     */
    private function hasMultipleForms(): bool
    {
        $forms = get_transient('cjw_wizard_form_count');

        if (! is_numeric($forms)) {
            $forms = count(get_posts([
                'post_type' => 'cjw_wizard',
                'post_status' => [ 'publish', 'draft', 'private' ],
                'numberposts' => 5,
                'fields' => 'ids',
            ]));

            set_transient('cjw_wizard_form_count', $forms, HOUR_IN_SECONDS);
        }

        return (int) $forms > 1;
    }

    /**
     * The children of one registration, linked to their detail page.
     *
     * Falls back to the title, which after anonymisation is the only thing
     * left to show, and to a dash on a form that marks no name field.
     */
    private function childrenCell(int $submissionId): string
    {
        $names = $this->repository->get_child_name($submissionId);

        if ($names === '') {
            $names = $this->repository->is_anonymised($submissionId)
                ? get_the_title($submissionId)
                : '—';
        }

        // The number the parent is quoted in the confirmation mail, kept beside
        // the names rather than in a column of its own: it is a reference to
        // look up by, not something to scan a list for. Styled inline because
        // the detail screen's stylesheet is not loaded here.
        $number = sprintf(
            ' <span style="color:#646970;">#%d</span>',
            $submissionId
        );

        $link = get_edit_post_link($submissionId);

        if ($link === null) {
            return '<strong>' . esc_html($names) . '</strong>' . $number;
        }

        return sprintf(
            '<strong><a class="row-title" href="%1$s">%2$s</a></strong>%3$s',
            esc_url($link),
            esc_html($names),
            $number
        );
    }

    private function valueOrDash(string $value): string
    {
        return $value !== '' ? $value : '—';
    }
}
