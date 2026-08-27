<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Polishes the sponsor post type in the admin: ordering support, list columns and logo warnings.
 */
final class CJW_Summer_Camp_Sponsor_Admin
{
    /**
     * Sponsor post type name.
     */
    private const string POST_TYPE = 'sponsor';

    /**
     * Registers the sponsor admin hooks.
     *
     * Must run on every request (not admin-only): the register_post_type_args
     * filter also affects the post type on the frontend and in REST requests.
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        add_filter('register_post_type_args', [$instance, 'filterPostTypeArgs'], 10, 2);
        add_filter('manage_sponsor_posts_columns', [$instance, 'columns']);
        add_action('manage_sponsor_posts_custom_column', [$instance, 'renderColumn'], 10, 2);
        add_filter('manage_edit-sponsor_sortable_columns', [$instance, 'sortableColumns']);
        add_action('pre_get_posts', [$instance, 'defaultOrder']);
        add_action('admin_notices', [$instance, 'missingLogoNotice']);
    }

    /**
     * Adds page-attributes support so sponsors can be ordered via the menu_order field.
     *
     * @param array  $args      Post type registration arguments.
     * @param string $post_type Post type name.
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function filterPostTypeArgs(array $args, string $post_type): array
    {
        if ($post_type !== self::POST_TYPE) {
            return $args;
        }

        $supports = $args['supports'] ?? ['title', 'editor'];

        if (!is_array($supports)) {
            return $args;
        }

        if (!in_array('page-attributes', $supports, true)) {
            $supports[] = 'page-attributes';
        }

        $args['supports'] = $supports;

        return $args;
    }

    /**
     * Adds the logo and order columns to the sponsor list table.
     *
     * @param array $columns List table columns.
     * @param array<string, mixed> $columns
     *
     * @return array<string, mixed>
     */
    public function columns(array $columns): array
    {
        $updated = [];

        foreach ($columns as $key => $label) {
            if ($key === 'title') {
                $updated['cjw_sponsor_logo'] = __('Logo', 'cjw');
            }

            if ($key === 'date') {
                $updated['cjw_sponsor_order'] = __('Volgorde', 'cjw');
            }

            $updated[$key] = $label;
        }

        if (!isset($updated['cjw_sponsor_logo'])) {
            $updated['cjw_sponsor_logo'] = __('Logo', 'cjw');
        }

        if (!isset($updated['cjw_sponsor_order'])) {
            $updated['cjw_sponsor_order'] = __('Volgorde', 'cjw');
        }

        return $updated;
    }

    /**
     * Renders the custom sponsor list table columns.
     *
     * @param string $column  Column name.
     * @param int    $post_id Sponsor post ID.
     *
     * @return void
     */
    public function renderColumn(string $column, int $post_id): void
    {
        if ($column === 'cjw_sponsor_logo') {
            $this->renderLogoColumn($post_id);

            return;
        }

        if ($column === 'cjw_sponsor_order') {
            $post = get_post($post_id);

            echo esc_html($post instanceof WP_Post ? (string) $post->menu_order : '');
        }
    }

    /**
     * Makes the order column sortable on menu_order.
     *
     * @param array $columns Sortable columns.
     * @param array<string, mixed> $columns
     *
     * @return array<string, mixed>
     */
    public function sortableColumns(array $columns): array
    {
        $columns['cjw_sponsor_order'] = 'menu_order';

        return $columns;
    }

    /**
     * Orders the sponsor list by menu_order when no explicit order is requested.
     *
     * @param WP_Query $query Current query.
     *
     * @return void
     */
    public function defaultOrder(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== self::POST_TYPE) {
            return;
        }

        if ((string) $query->get('orderby') !== '') {
            return;
        }

        $query->set('orderby', 'menu_order title');
        $query->set('order', 'ASC');
    }

    /**
     * Shows a warning on the sponsor list screen when published sponsors lack a logo.
     *
     * @return void
     */
    public function missingLogoNotice(): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();

        if (!$screen instanceof WP_Screen || $screen->id !== 'edit-sponsor') {
            return;
        }

        $offenders = $this->publishedSponsorsWithoutLogo();

        if ($offenders === []) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Let op:', 'cjw'); ?></strong>
                <?php
                echo esc_html(_n(
                    'de volgende gepubliceerde sponsor heeft geen logo (uitgelichte afbeelding) en wordt niet op de website getoond:',
                    'de volgende gepubliceerde sponsoren hebben geen logo (uitgelichte afbeelding) en worden niet op de website getoond:',
                    count($offenders),
                    'cjw'
                ));
        ?>
            </p>
            <ul style="list-style:disc;padding-left:20px;">
                <?php foreach ($offenders as $offender): ?>
                    <li>
                        <?php
                $title = get_the_title($offender);
                    $title = $title !== '' ? $title : __('(geen titel)', 'cjw');
                    $edit_link = get_edit_post_link($offender->ID);

                    if (is_string($edit_link) && $edit_link !== '') {
                        printf('<a href="%s">%s</a>', esc_url($edit_link), esc_html($title));
                    } else {
                        echo esc_html($title);
                    }
                    ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Renders the logo column: the featured image or a placeholder with warning.
     *
     * @param int $post_id Sponsor post ID.
     *
     * @return void
     */
    private function renderLogoColumn(int $post_id): void
    {
        if (has_post_thumbnail($post_id)) {
            echo wp_kses_post(
                get_the_post_thumbnail(
                    $post_id,
                    [60, 60],
                    ['style' => 'width:60px;height:60px;object-fit:contain;background:#fff;border:1px solid #dcdcde;border-radius:4px;']
                )
            );

            return;
        }

        ?>
        <span style="display:inline-block;width:60px;height:60px;border:2px dashed #c3c4c7;border-radius:4px;" aria-hidden="true"></span>
        <br />
        <span style="color:#b32d2e;"><?php esc_html_e('(geen logo — wordt niet getoond)', 'cjw'); ?></span>
        <?php
    }

    /**
     * Returns the published sponsors without a featured image.
     *
     * @return WP_Post[]
     */
    private function publishedSponsorsWithoutLogo(): array
    {
        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only check on a small sponsor set.
            'meta_query' => [
                [
                    'key' => '_thumbnail_id',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);
    }
}
