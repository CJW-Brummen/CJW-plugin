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
            'edit_posts',
            'cjw-wizard-export',
            [ $this, 'render_export_page' ]
        );
    }

    public function render_export_page(): void
    {
        if (! current_user_can('edit_posts')) {
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
			<p><?php esc_html_e('Download inzendingen van een formulier als CSV.', 'cjw'); ?></p>
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
        if (! current_user_can('edit_posts')) {
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
        $headers = [ 'submission_id', 'submitted_at' ];

        foreach ($submissions as $submission) {
            $flat_payload = $this->repository->flatten_data($this->repository->get_payload((int) $submission->ID));
            $headers = array_values(array_unique([ ...$headers, ...array_keys($flat_payload) ]));
            $rows[] = [
                'submission_id' => (string) $submission->ID,
                'submitted_at' => get_post_time('Y-m-d H:i:s', false, $submission),
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

        fputcsv($output, $headers);
        foreach ($rows as $row) {
            $csv_row = [];
            foreach ($headers as $header) {
                $csv_row[] = $row[ $header ] ?? '';
            }
            fputcsv($output, $csv_row);
        }

        exit;
    }
}
