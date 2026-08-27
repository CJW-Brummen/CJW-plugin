<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The sponsor post type, and the one field it needs.
 *
 * Both used to live in the Advanced Custom Fields admin: configured through a
 * UI, stored in the database, and present in no file anybody could review or
 * deploy. A fresh install of this plugin produced a site with no sponsor post
 * type at all, and the front page answered by rendering nothing -- the sponsor
 * wall simply vanished, without a word.
 *
 * The link was never ACF's to hold either. It is stored as ordinary post meta
 * under `sponsor_link`, which is why moving off ACF needs no migration: the
 * same key, read the same way, by a meta box instead of a field group.
 */
final class CJW_Summer_Camp_Sponsor_Post_Type
{
    public const string POST_TYPE = 'sponsor';

    /**
     * Where the sponsor's website is stored. Unchanged from the ACF field, on
     * purpose: the ten sponsors already on the site keep working untouched.
     */
    public const string META_LINK = 'sponsor_link';

    private const string NONCE = 'cjw_sponsor_link_nonce';

    public static function init(): void
    {
        $instance = new self();

        add_action('init', [ $instance, 'register' ]);
        add_action('add_meta_boxes', [ $instance, 'registerBox' ]);
        add_action('save_post_' . self::POST_TYPE, [ $instance, 'save' ], 10, 2);
    }

    public function register(): void
    {
        // Registered late enough that a site still running the ACF-defined
        // version does not end up with two of them: register_post_type()
        // replaces an existing registration of the same name.
        register_post_type(
            self::POST_TYPE,
            [
                'labels' => [
                    'name' => __('Sponsoren', 'cjw'),
                    'singular_name' => __('Sponsor', 'cjw'),
                    'add_new_item' => __('Nieuwe sponsor', 'cjw'),
                    'edit_item' => __('Sponsor bewerken', 'cjw'),
                    'search_items' => __('Sponsoren zoeken', 'cjw'),
                    'not_found' => __('Geen sponsoren gevonden.', 'cjw'),
                    'not_found_in_trash' => __('Geen sponsoren in de prullenbak.', 'cjw'),
                ],
                // Public: a sponsor is not private data, and the archive is
                // harmless. It was public under ACF too, and flipping that
                // would change existing permalinks.
                'public' => true,
                'show_in_menu' => true,
                'menu_position' => 26,
                'menu_icon' => 'dashicons-heart',
                'has_archive' => false,
                'supports' => [ 'title', 'thumbnail', 'page-attributes' ],
                'show_in_rest' => true,
            ]
        );
    }

    /**
     * Adds the link field to the sponsor editor.
     *
     * @param string $post_type Post type being edited.
     */
    public function registerBox(string $post_type = ''): void
    {
        if ($post_type !== self::POST_TYPE) {
            return;
        }

        add_meta_box(
            'cjw_sponsor_link',
            __('Website van de sponsor', 'cjw'),
            [ $this, 'renderBox' ],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * The sponsor's website, as a single url field.
     *
     * @param WP_Post $post Sponsor being edited.
     */
    public function renderBox(WP_Post $post): void
    {
        wp_nonce_field('cjw_sponsor_link', self::NONCE);

        $value = (string) get_post_meta((int) $post->ID, self::META_LINK, true);

        echo '<p><label for="cjw_sponsor_link_field" class="screen-reader-text">';
        echo esc_html__('Website van de sponsor', 'cjw');
        echo '</label>';
        printf(
            '<input type="url" id="cjw_sponsor_link_field" name="%1$s" value="%2$s" class="widefat" placeholder="%3$s"></p>',
            esc_attr(self::META_LINK),
            esc_attr($value),
            esc_attr__('https://voorbeeld.nl', 'cjw')
        );
        echo '<p class="description">';
        esc_html_e('Het logo op de voorpagina linkt hiernaartoe. Laat leeg om er geen link van te maken.', 'cjw');
        echo '</p>';
    }

    /**
     * Stores the sponsor's website.
     *
     * @param int     $post_id Sponsor post id.
     * @param WP_Post $post    Sponsor.
     */
    public function save(int $post_id, WP_Post $post): void
    {
        unset($post);

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = isset($_POST[ self::NONCE ])
            ? sanitize_text_field(wp_unslash((string) $_POST[ self::NONCE ]))
            : '';

        if ($nonce === '' || ! wp_verify_nonce($nonce, 'cjw_sponsor_link')) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $url = isset($_POST[ self::META_LINK ])
            ? esc_url_raw(wp_unslash((string) $_POST[ self::META_LINK ]))
            : '';

        if ($url === '') {
            delete_post_meta($post_id, self::META_LINK);

            return;
        }

        update_post_meta($post_id, self::META_LINK, $url);
    }
}
