<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Submission_Exporter
{
    private CJW_Wizard_Submission_Repository $repository;

    public function __construct(CJW_Wizard_Submission_Repository $repository)
    {
        $this->repository = $repository;
    }

    public function init(): void
    {
        add_action('admin_menu', [ $this, 'register_export_page' ]);
        add_action('admin_post_cjw_wizard_export_csv', [ $this, 'handle_export' ]);
    }

    public function register_export_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=cjw_wizard',
            __('CSV Export', 'cjw'),
            __('CSV Export', 'cjw'),
            CJW_Roles::CAPABILITY,
            'cjw-wizard-export',
            [ $this, 'render_export_page' ]
        );
    }

    public function render_export_page(): void
    {
        if (! CJW_Roles::currentUserCan()) {
            return;
        }

        $wizards = get_posts(
            [
                'post_type' => 'cjw_wizard',
                'post_status' => [ 'publish', 'draft', 'private' ],
                'numberposts' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
            ]
        );

        ?>
		<div class="wrap">
			<h1><?php esc_html_e('CSV Export', 'cjw'); ?></h1>
			<p><?php esc_html_e('Download inschrijvingen van een formulier als CSV.', 'cjw'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('cjw_wizard_export_csv', 'cjw_wizard_export_nonce'); ?>
				<input type="hidden" name="action" value="cjw_wizard_export_csv">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="cjw-wizard-export-id"><?php esc_html_e('Formulier', 'cjw'); ?></label>
						</th>
						<td>
							<select name="wizard_id" id="cjw-wizard-export-id" required>
								<option value=""><?php esc_html_e('Selecteer een formulier', 'cjw'); ?></option>
								<?php foreach ($wizards as $wizard) : ?>
									<option value="<?php echo esc_attr((string) $wizard->ID); ?>">
										<?php echo esc_html($wizard->post_title !== '' ? $wizard->post_title : __('Zonder titel', 'cjw')); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Download CSV', 'cjw')); ?>
			</form>
		</div>
		<?php
    }

    public function handle_export(): void
    {
        if (! CJW_Roles::currentUserCan()) {
            wp_die(esc_html__('Geen toegang.', 'cjw'));
        }

        $nonce = isset($_POST['cjw_wizard_export_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['cjw_wizard_export_nonce'])) : '';
        if (! wp_verify_nonce($nonce, 'cjw_wizard_export_csv')) {
            wp_die(esc_html__('Ongeldig exportverzoek.', 'cjw'));
        }

        $wizard_id = isset($_POST['wizard_id']) ? absint(wp_unslash((string) $_POST['wizard_id'])) : 0;
        if ($wizard_id <= 0) {
            wp_die(esc_html__('Geen formulier geselecteerd.', 'cjw'));
        }

        $config = CJW_Wizard_Config::get($wizard_id);
        if (empty($config['settings']['enable_csv_export'])) {
            wp_die(esc_html__('CSV-export staat niet aan voor dit formulier.', 'cjw'));
        }

        $submissions = $this->repository->get_by_wizard($wizard_id);
        $rows = [];

        // fototoestemming is a column of its own, ahead of the payload. The
        // answer is already in the flattened payload, but under whatever field
        // key the form happens to use and in whatever shape that field produces
        // -- '1' from a checkbox, 'Ja' from a radio. Whoever handles the photos
        // reads this file to find out who is not in the pictures, so the answer
        // gets one predictable column with one of two words in it.
        $headers = [ 'submission_id', 'submitted_at', 'fototoestemming' ];

        foreach ($submissions as $submission) {
            $id = (int) $submission->ID;
            $flat_payload = $this->repository->flatten_data($this->repository->get_payload($id));
            $headers = array_values(array_unique([ ...$headers, ...array_keys($flat_payload) ]));
            $rows[] = [
                'submission_id' => (string) $id,
                'submitted_at' => get_post_time('Y-m-d H:i:s', false, $submission),
                'fototoestemming' => self::consentColumn($id),
                ...$flat_payload,
            ];
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wizard-' . $wizard_id . '-export-' . gmdate('Ymd-His') . '.csv"');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            exit;
        }

        fputcsv($output, array_map([ self::class, 'escape_csv_cell' ], $headers));
        foreach ($rows as $row) {
            $csv_row = [];
            foreach ($headers as $header) {
                $csv_row[] = self::escape_csv_cell((string) ($row[ $header ] ?? ''));
            }
            fputcsv($output, $csv_row);
        }

        exit;
    }

    /**
     * The photo consent column: one word, or one word per child.
     *
     * Siblings can answer differently, so a registration with three children
     * exports "ja/nee/ja" rather than a single answer that would be wrong for
     * one of them.
     *
     * @param int $submission_id Registration.
     * @return string
     */
    private static function consentColumn(int $submission_id): string
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reading every row of one key on one post.
        $answers = get_post_meta($submission_id, CJW_Wizard_Submission_Repository::META_PHOTO_CONSENT, false);

        if (! is_array($answers) || $answers === []) {
            return '';
        }

        return implode('/', array_map('strval', $answers));
    }

    /**
     * Neutralises spreadsheet formula injection in a single cell.
     *
     * Submissions arrive through a public, unauthenticated form, so any value
     * may begin with a character that Excel, LibreOffice Calc and Google Sheets
     * read as the start of a formula. `=cmd|'/c calc'!A1` entered as a child's
     * name executes when an organiser opens the export. Prefixing an apostrophe
     * forces the cell to literal text in all three, and they strip it again on
     * display.
     *
     * Numeric values are returned untouched so that a legitimate negative
     * number does not collect a stray apostrophe: `-5` is a formula to no
     * spreadsheet, and anything genuinely hostile fails is_numeric().
     *
     * @param string $value Raw cell value.
     */
    public static function escape_csv_cell(string $value): string
    {
        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }
}
