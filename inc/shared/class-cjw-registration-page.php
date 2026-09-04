<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one page on the site that holds the registration form.
 *
 * Every "Schrijf je in" button in the theme used to default to `#inschrijven`,
 * an anchor that exists in no template, so the primary call to action on the
 * homepage scrolled nowhere. Meanwhile the plugin already knew where the form
 * lives: the settings screen asks for the page's address in order to build the
 * late-registration link from it. That address is now canonical for every
 * button as well, and when it is left blank the page is found rather than
 * guessed -- the published page whose content carries the [cjw_wizard]
 * shortcode.
 *
 * Found once and cached: the buttons render on every page of the site, and a
 * LIKE over post_content per request is a poor trade for a value that changes
 * a few times a year. Saving, trashing, restoring or deleting a page drops the
 * cache, so moving the form to another page is picked up on the next request.
 */
final class CJW_Registration_Page
{
    /**
     * Where the found page id is cached.
     */
    public const string TRANSIENT = 'cjw_registration_page';

    /**
     * The shortcode that marks the page.
     */
    private const string SHORTCODE = 'cjw_wizard';

    /**
     * Drops the cache whenever a page changes, so a moved form is found again.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('save_post_page', [ self::class, 'flush' ], 10, 0);
        add_action('deleted_post', [ self::class, 'flush' ], 10, 0);
        add_action('trashed_post', [ self::class, 'flush' ], 10, 0);
        add_action('untrashed_post', [ self::class, 'flush' ], 10, 0);
    }

    /**
     * The address of the page holding the form, or an empty string when no
     * published page does.
     *
     * @return string
     */
    public static function url(): string
    {
        $id = self::id();

        if ($id < 1) {
            return '';
        }

        $url = get_permalink($id);

        return is_string($url) ? $url : '';
    }

    /**
     * The id of the page holding the form, or 0. Cached for a day.
     *
     * A miss is cached as well: a site without the page yet would otherwise
     * run the search on every request until somebody creates it, and creating
     * it is a page save, which flushes.
     *
     * @return int
     */
    public static function id(): int
    {
        $cached = get_transient(self::TRANSIENT);

        if (is_array($cached) && isset($cached['id'])) {
            return (int) $cached['id'];
        }

        $id = self::detect();

        set_transient(self::TRANSIENT, [ 'id' => $id ], DAY_IN_SECONDS);

        return $id;
    }

    /**
     * Searches the published pages for the one carrying the shortcode.
     *
     * The oldest page wins when several match, on the grounds that the form
     * page is created once and a later copy is more likely a draft someone
     * published by mistake than a deliberate move.
     *
     * @return int Page id, or 0 when there is none.
     */
    public static function detect(): int
    {
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            // Narrows the candidates in SQL; has_shortcode() below is the real
            // test, because a search also matches a title or a mention in prose.
            's' => '[' . self::SHORTCODE,
            'numberposts' => 20,
            'orderby' => 'date',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        foreach ($pages as $page) {
            if ($page instanceof WP_Post && has_shortcode((string) $page->post_content, self::SHORTCODE)) {
                return (int) $page->ID;
            }
        }

        return 0;
    }

    /**
     * Forgets the found page, so the next request looks again.
     *
     * @return void
     */
    public static function flush(): void
    {
        delete_transient(self::TRANSIENT);
    }
}
