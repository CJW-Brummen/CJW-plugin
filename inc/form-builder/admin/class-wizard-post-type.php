<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Post_Type
{
    /**
     * The post type a [cjw_wizard] shortcode must point at.
     *
     * Spelled once, because the shortcode now refuses an id that resolves to
     * anything else and two copies of a slug are two chances to disagree.
     */
    public const string SLUG = 'cjw_wizard';

    public function init(): void
    {
        add_action('init', [ $this, 'register' ]);
    }

    public function register(): void
    {
        register_post_type(
            self::SLUG,
            [
                'labels' => [
                    'name' => __('Formulieren', 'cjw'),
                    'singular_name' => __('Formulier', 'cjw'),
                    'add_new' => __('Nieuw Formulier', 'cjw'),
                    'add_new_item' => __('Nieuw Formulier', 'cjw'),
                    'edit_item' => __('Bewerk Formulier', 'cjw'),
                ],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'menu_icon' => 'dashicons-forms',
                'supports' => [ 'title' ],
                'capabilities' => CJW_Roles::postTypeCapabilities(),
                'map_meta_cap' => true,
            ]
        );
    }
}
