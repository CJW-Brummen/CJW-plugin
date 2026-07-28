<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Submission_Admin
{
    private CJW_Wizard_Submission_Repository $repository;

    public function __construct(CJW_Wizard_Submission_Repository $repository)
    {
        $this->repository = $repository;
    }

    public function init(): void
    {
        add_action('add_meta_boxes', [ $this, 'register_meta_boxes' ]);
        add_filter('manage_' . CJW_Wizard_Submission_Repository::POST_TYPE . '_posts_columns', [ $this, 'filter_columns' ]);
        add_action('manage_' . CJW_Wizard_Submission_Repository::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2);
    }

    public function register_meta_boxes(): void
    {
        add_meta_box(
            'cjw_wizard_submission_data',
            __('Inzendingsgegevens', 'cjw'),
            [ $this, 'render_submission_meta_box' ],
            CJW_Wizard_Submission_Repository::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render_submission_meta_box(\WP_Post $post): void
    {
        $payload = $this->repository->get_payload((int) $post->ID);
        $wizard_id = $this->repository->get_wizard_id((int) $post->ID);

        echo '<p><strong>' . esc_html__('Formulier:', 'cjw') . '</strong> ' . esc_html($wizard_id > 0 ? get_the_title($wizard_id) : __('Onbekend', 'cjw')) . '</p>';
        echo '<table class="widefat striped" style="margin-top: 12px;">';
        echo '<thead><tr><th>' . esc_html__('Veld', 'cjw') . '</th><th>' . esc_html__('Waarde', 'cjw') . '</th></tr></thead><tbody>';

        foreach ($this->repository->flatten_data($payload) as $key => $value) {
            echo '<tr>';
            echo '<td><code>' . esc_html($key) . '</code></td>';
            echo '<td>' . esc_html($value) . '</td>';
            echo '</tr>';
        }

        if ($payload === []) {
            echo '<tr><td colspan="2">' . esc_html__('Geen opgeslagen gegevens gevonden.', 'cjw') . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    public function filter_columns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '',
            'title' => __('Inzending', 'cjw'),
            'wizard' => __('Formulier', 'cjw'),
            'date' => __('Datum', 'cjw'),
        ];
    }

    public function render_column(string $column, int $post_id): void
    {
        if ($column !== 'wizard') {
            return;
        }

        $wizard_id = $this->repository->get_wizard_id($post_id);
        echo esc_html($wizard_id > 0 ? get_the_title($wizard_id) : __('Onbekend', 'cjw'));
    }
}
