<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Submission_Repository
{
    /**
     * The submissions post type.
     *
     * WordPress caps post type names at 20 characters. The original name,
     * `cjw_wizard_submission`, was 21, so register_post_type() always returned
     * post_type_length_invalid and the submissions screen never existed --
     * submissions were saved to wp_posts and were simply unreachable in
     * wp-admin. Any name set here must stay within the limit;
     * PostTypeNameTest asserts it.
     */
    public const string POST_TYPE = 'cjw_wizard_entry';

    public const string META_WIZARD_ID = '_cjw_wizard_id';
    public const string META_PAYLOAD = '_cjw_wizard_payload';
    public const string META_SNAPSHOT = '_cjw_wizard_snapshot';

    public function save(int $wizard_id, array $config, array $data): int
    {
        $wizard_title = get_the_title($wizard_id);
        $submission_title = sprintf(
            /* translators: 1: form title, 2: submission date and time. */
            __('%1$s - %2$s', 'cjw'),
            $wizard_title !== '' ? $wizard_title : __('Formulier', 'cjw'),
            wp_date('Y-m-d H:i:s')
        );

        $submission_id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $submission_title,
        ], true);

        if (is_wp_error($submission_id)) {
            return 0;
        }

        update_post_meta($submission_id, self::META_WIZARD_ID, $wizard_id);
        update_post_meta($submission_id, self::META_PAYLOAD, wp_json_encode($data, JSON_UNESCAPED_UNICODE));
        update_post_meta($submission_id, self::META_SNAPSHOT, wp_json_encode($config, JSON_UNESCAPED_UNICODE));

        return (int) $submission_id;
    }

    public function get_payload(int $submission_id): array
    {
        $payload = get_post_meta($submission_id, self::META_PAYLOAD, true);

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    public function get_wizard_id(int $submission_id): int
    {
        return (int) get_post_meta($submission_id, self::META_WIZARD_ID, true);
    }

    public function get_by_wizard(int $wizard_id): array
    {
        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Submissions are stored as a custom post type and scoped by the owning form id.
            'meta_key' => self::META_WIZARD_ID,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- This admin/export query intentionally filters submissions for one form.
            'meta_value' => $wizard_id,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    public function flatten_data(array $data, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($data as $key => $value) {
            $column = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flattened += $this->flatten_data($value, $column);
                continue;
            }

            $flattened[ $column ] = (string) $value;
        }

        return $flattened;
    }
}
