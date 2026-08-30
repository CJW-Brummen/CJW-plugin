<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The admin menu, trimmed to the job.
 *
 * Almost all of this is done by the capability map in CJW_Roles: WordPress
 * hides a menu whose capability the current user lacks, so Leiding never sees
 * Zomerkamp, Inschrijvingen or Formulieren, and neither Leiding nor
 * Kampbeheerder sees Plugins, Weergave or Instellingen. There is nothing to
 * hide by hand there, and hiding it by hand would be worse than useless --
 * remove_menu_page() takes the entry off the menu and leaves the screen behind
 * it reachable by URL.
 *
 * Two entries do not follow from a capability, and those are what this class is
 * for.
 */
final class CJW_Admin_Menu
{
    /**
     * Registers the menu hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        add_action('admin_menu', [ $instance, 'trim' ], 999);
        add_action('admin_init', [ $instance, 'blockToolsPage' ]);
    }

    /**
     * Removes the entries a correct capability map still leaves behind.
     *
     * Gereedschap (tools.php) is the one that matters. WordPress gates the
     * Tools menu on `edit_posts`, not on anything administrative, so every
     * Leiding member and every Kampbeheerder would find "Beschikbaar
     * gereedschap" sitting in their sidebar -- a page about categories-to-tags
     * conversion and press-this, which is site administration by any reading,
     * and which is exactly the kind of thing a volunteer clicks once and worries
     * about afterwards.
     *
     * @return void
     */
    public function trim(): void
    {
        if (current_user_can('manage_options')) {
            return;
        }

        remove_menu_page('tools.php');
    }

    /**
     * Refuses tools.php itself, not just its menu entry.
     *
     * The menu entry is only half of it. remove_menu_page() is cosmetic: the
     * screen stays reachable by typing the URL, because WordPress still holds
     * `edit_posts` against it. A menu tidied on the surface while the page
     * underneath answers to anybody is the kind of access control that reads as
     * done and is not, so the page itself is refused here too.
     *
     * @return void
     */
    public function blockToolsPage(): void
    {
        global $pagenow;

        if ('tools.php' !== $pagenow) {
            return;
        }

        // The Tools screen hosts other plugins' subpages through ?page=, and
        // those carry their own capability checks. Only the bare screen is
        // refused.
        if (isset($_GET['page'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which screen was asked for; no state changes.
            return;
        }

        if (current_user_can('manage_options')) {
            return;
        }

        wp_die(
            esc_html__('Je hebt geen toegang tot deze pagina.', 'cjw'),
            esc_html__('Geen toegang', 'cjw'),
            [ 'response' => 403 ]
        );
    }
}
