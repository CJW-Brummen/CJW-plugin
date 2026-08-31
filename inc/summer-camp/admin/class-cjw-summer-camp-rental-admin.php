<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The verhuurmateriaal list screen: the numbers, in the list, in page order.
 *
 * A rental item is almost entirely numbers, and the default list table shows
 * none of them -- seven rows of title and date, where "how many Trippsteins do
 * we have out" is the question the screen exists to answer. The columns below
 * put the four fields that matter next to the name, so the inventory can be
 * read without opening anything.
 */
final class CJW_Summer_Camp_Rental_Admin
{
    /**
     * Registers the list screen hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();
        $type = CJW_Summer_Camp_Rental_Post_Type::POST_TYPE;

        add_filter('manage_' . $type . '_posts_columns', [$instance, 'columns']);
        add_action('manage_' . $type . '_posts_custom_column', [$instance, 'renderColumn'], 10, 2);
        add_filter('manage_edit-' . $type . '_sortable_columns', [$instance, 'sortableColumns']);
        add_action('pre_get_posts', [$instance, 'defaultOrder']);
    }

    /**
     * Adds the inventory columns, before the date.
     *
     * @param array<string, mixed> $columns List table columns.
     * @return array<string, mixed>
     */
    public function columns(array $columns): array
    {
        $updated = [];

        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $updated['cjw_verhuur_soort'] = __('Soort', 'cjw');
                $updated['cjw_verhuur_aantal'] = __('Aantal', 'cjw');
                $updated['cjw_verhuur_voor'] = __('Voor', 'cjw');
                $updated['cjw_verhuur_maat'] = __('Afmetingen', 'cjw');
                $updated['cjw_verhuur_volgorde'] = __('Volgorde', 'cjw');
            }

            $updated[$key] = $label;
        }

        return $updated;
    }

    /**
     * Renders one inventory cell.
     *
     * @param string $column  Column key.
     * @param int    $post_id Item id.
     * @return void
     */
    public function renderColumn(string $column, int $post_id): void
    {
        $type = CJW_Summer_Camp_Rental_Post_Type::class;

        switch ($column) {
            case 'cjw_verhuur_soort':
                $slug = $type::category($post_id);
                echo esc_html($type::categories()[$slug] ?? $slug);
                break;

            case 'cjw_verhuur_aantal':
                $count = $type::number($post_id, $type::META_COUNT);
                echo $count > 0
                    ? esc_html(sprintf('%d×', $count))
                    : '<em>' . esc_html__('op aanvraag', 'cjw') . '</em>';
                break;

            case 'cjw_verhuur_voor':
                echo esc_html($this->capacity($post_id));
                break;

            case 'cjw_verhuur_maat':
                echo esc_html($this->footprint($post_id));
                break;

            case 'cjw_verhuur_volgorde':
                echo esc_html((string) get_post_field('menu_order', $post_id));
                break;
        }
    }

    /**
     * "8 pers." or "8–10 pers.", or an em dash when it does not apply.
     *
     * @param int $post_id Item id.
     * @return string
     */
    private function capacity(int $post_id): string
    {
        $type = CJW_Summer_Camp_Rental_Post_Type::class;

        $from = $type::number($post_id, $type::META_CAPACITY);
        $to = $type::number($post_id, $type::META_CAPACITY_MAX);

        if ($from <= 0) {
            return '—';
        }

        return $to > $from
            ? sprintf('%1$d–%2$d pers.', $from, $to)
            : sprintf('%d pers.', $from);
    }

    /**
     * "300 × 440 cm", or an em dash when the item has no footprint.
     *
     * @param int $post_id Item id.
     * @return string
     */
    private function footprint(int $post_id): string
    {
        $type = CJW_Summer_Camp_Rental_Post_Type::class;

        $length = $type::number($post_id, $type::META_LENGTH);
        $width = $type::number($post_id, $type::META_WIDTH);

        if ($length <= 0 || $width <= 0) {
            return '—';
        }

        return sprintf('%1$d × %2$d cm', $length, $width);
    }

    /**
     * Lets the list be sorted by hand-set order.
     *
     * @param array<string, mixed> $columns Sortable columns.
     * @return array<string, mixed>
     */
    public function sortableColumns(array $columns): array
    {
        $columns['cjw_verhuur_volgorde'] = 'menu_order';

        return $columns;
    }

    /**
     * Lists items in the order the site shows them, not by date.
     *
     * @param WP_Query $query Query being prepared.
     * @return void
     */
    public function defaultOrder(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== CJW_Summer_Camp_Rental_Post_Type::POST_TYPE) {
            return;
        }

        if ((string) $query->get('orderby') !== '') {
            return;
        }

        // The same string form the sponsor list uses: an ordered array here is
        // formatted one way by phpcs and the other by php-cs-fixer, and the two
        // rewrite each other on every run.
        $query->set('orderby', 'menu_order title');
        $query->set('order', 'ASC');
    }
}
