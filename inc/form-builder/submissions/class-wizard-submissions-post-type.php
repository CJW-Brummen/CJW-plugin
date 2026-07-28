<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Submissions_Post_Type
{
    public function init(): void
    {
        add_action('init', [ $this, 'register' ]);
    }

    public function register(): void
    {
        register_post_type(
            CJW_Wizard_Submission_Repository::POST_TYPE,
            [
                'labels' => [
                    'name' => __('Inzendingen', 'cjw'),
                    'singular_name' => __('Inzending', 'cjw'),
                ],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => 'edit.php?post_type=cjw_wizard',
                'menu_position' => 25,
                'menu_icon' => 'dashicons-feedback',
                'supports' => [ 'title' ],
                'capability_type' => 'post',
            ]
        );
    }
}
