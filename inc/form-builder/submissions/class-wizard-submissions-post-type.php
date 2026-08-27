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
                    'name' => __('Inschrijvingen', 'cjw'),
                    'singular_name' => __('Inschrijving', 'cjw'),
                    // The detail screen is a reading screen: it shows what a
                    // parent filled in, which nobody may rewrite. Calling it
                    // "bewerken" invited an organiser to look for an edit that
                    // is not there.
                    'edit_item' => __('Inschrijving bekijken', 'cjw'),
                    'search_items' => __('Inschrijvingen zoeken', 'cjw'),
                    'not_found' => __('Geen inschrijvingen gevonden.', 'cjw'),
                    'not_found_in_trash' => __('Geen inschrijvingen in de prullenbak.', 'cjw'),
                ],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => 'edit.php?post_type=cjw_wizard',
                'menu_position' => 25,
                'menu_icon' => 'dashicons-feedback',
                // No title support: the title is generated from the post id,
                // so an editable title box would only offer a way to break the
                // one thing it is for.
                'supports' => false,
                // A submission is what a parent filled in. There is nothing
                // coherent to create by hand -- an empty row has no payload, no
                // child, no address -- so the "Add New" button is removed rather
                // than left as a trap that produces broken registrations.
                'capabilities' => CJW_Roles::postTypeCapabilities(false),
                'map_meta_cap' => true,
            ]
        );
    }
}
