<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Post_Type
{
    public function init(): void
    {
        add_action('init', [ $this, 'register' ]);
    }

    public function register(): void
    {
        register_post_type(
            'cjw_wizard',
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
            ]
        );
    }
}
