<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The admin, trimmed to what this site actually does.
 *
 * Two different trims live here.
 *
 * One is about roles, and almost all of it is done by the capability map in
 * CJW_Roles: WordPress hides a menu whose capability the current user lacks, so
 * Leiding never sees Zomerkamp, Inschrijvingen or Formulieren, and neither
 * Leiding nor Kampbeheerder sees Plugins, Weergave or Instellingen. There is
 * nothing to hide by hand there. Gereedschap is the exception, because
 * WordPress gates it on `edit_posts` rather than on anything administrative.
 *
 * The other is about the site: CJW has no blog and takes no comments, and is
 * not going to. Berichten and Reacties are two top-level menus, a "+ Nieuw"
 * entry and a bubble in the toolbar, all leading to screens that will always be
 * empty -- and every one of them is something a volunteer can click on and then
 * worry about.
 *
 * Both trims refuse the screens as well as removing the entries.
 * remove_menu_page() is cosmetic: it takes the item off the sidebar and leaves
 * the page behind it answering to anybody who types the URL. A menu tidied on
 * the surface while the screen underneath still works is the kind of tidying
 * that reads as done and is not.
 *
 * Comments are closed at the same time, and that is the half that matters most.
 * Hiding the moderation screen while the comment form still renders and
 * wp_insert_comment() still accepts posts would leave spam collecting somewhere
 * nobody looks. The site already had comments closed by default with none open;
 * this makes it true regardless of what a later import or a stray checkbox does.
 *
 * The blog and comment trim sits behind the `cjw_trim_unused_features` filter,
 * so a site that ever does want a blog can have one back without editing a
 * plugin.
 */
final class CJW_Admin_Menu
{
    /**
     * The screens that go with Berichten and Reacties.
     *
     * Note that edit.php and post-new.php are shared with pages and with every
     * custom post type, so they are handled separately: only `post` is refused.
     *
     * @var array<int, string>
     */
    private const array BLOCKED_SCREENS = [
        'edit-comments.php',
        'comment.php',
        'options-discussion.php',
    ];

    /**
     * Taxonomies that exist only to organise posts.
     *
     * Their screens are submenus of Berichten and disappear with it, but
     * edit-tags.php stays reachable by URL, which is the same half-measure the
     * class docblock argues against.
     *
     * @var array<int, string>
     */
    private const array BLOCKED_TAXONOMIES = [ 'category', 'post_tag' ];

    /**
     * Registers the hooks.
     *
     * Not gated on is_admin(): closing comments has to happen on the front end
     * too, which is where the form would otherwise render.
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        add_action('admin_menu', [ $instance, 'trim' ], 999);
        add_filter('register_post_type_args', [ $instance, 'hidePostsFromTheMenu' ], 10, 2);
        add_action('admin_init', [ $instance, 'blockRemovedScreens' ]);
        add_action('admin_bar_menu', [ $instance, 'trimToolbar' ], 999);

        if (! self::trimming()) {
            return;
        }

        // Priority 20, after anything a theme or another plugin sets, and
        // deliberately absolute: there is no post on this site that should
        // ever accept one.
        add_filter('comments_open', '__return_false', 20);
        add_filter('pings_open', '__return_false', 20);
        add_filter('comments_array', [ $instance, 'noComments' ], 20);
    }

    /**
     * Whether the blog and comment trim applies.
     *
     * @return bool
     */
    private static function trimming(): bool
    {
        return (bool) apply_filters('cjw_trim_unused_features', true);
    }

    /**
     * Removes the menu entries.
     *
     * @return void
     */
    public function trim(): void
    {
        // Gereedschap: gated by WordPress on edit_posts, so every Leiding
        // member and every Kampbeheerder would find "Beschikbaar gereedschap"
        // in their sidebar -- a screen about categories-to-tags conversion,
        // which is site administration by any reading.
        if (! current_user_can('manage_options')) {
            remove_menu_page('tools.php');
        }

        if (! self::trimming()) {
            return;
        }

        remove_menu_page('edit-comments.php');
    }

    /**
     * Takes Berichten out of the menu where it is built, rather than after.
     *
     * Calling remove_menu_page('edit.php') is not enough, and the way it fails
     * is worth recording. It removes the top-level entry and leaves the submenus, and
     * wp-admin/includes/menu.php then promotes the first submenu the user can
     * still reach into the vacated parent slot. Berichten came back for exactly
     * the two roles that hold none of its capabilities -- pointing at
     * edit-tags.php?taxonomy=category, because Leiding holds manage_categories,
     * and still labelled "Berichten". Unsetting the submenu array alongside it
     * did not help either: the promotion runs on a menu that has already been
     * rebuilt by then.
     *
     * Declaring the post type out of the menu removes the parent and its
     * children together, before there is anything to promote.
     *
     * @param array<string, mixed> $args Post type arguments.
     * @param string $post_type Post type being registered.
     * @return array<string, mixed>
     */
    public function hidePostsFromTheMenu($args, $post_type): array
    {
        if ('post' !== $post_type || ! self::trimming()) {
            return (array) $args;
        }

        $args = (array) $args;
        $args['show_in_menu'] = false;

        return $args;
    }

    /**
     * Removes the toolbar entries that survive the menu removal.
     *
     * The comment bubble and "+ Nieuw > Bericht" are built from the toolbar,
     * not from the admin menu, so neither notices that Berichten has gone.
     *
     * @param WP_Admin_Bar $bar The toolbar being assembled.
     * @return void
     */
    public function trimToolbar($bar): void
    {
        if (! self::trimming() || ! $bar instanceof WP_Admin_Bar) {
            return;
        }

        $bar->remove_node('comments');
        $bar->remove_node('new-post');
    }

    /**
     * Answers with no comments, whatever the database holds.
     *
     * @param array<int, mixed> $comments Comments WordPress loaded for the post.
     * @return array<int, mixed>
     */
    public function noComments($comments): array
    {
        unset($comments);

        return [];
    }

    /**
     * Refuses the screens themselves, not just their menu entries.
     *
     * @return void
     */
    public function blockRemovedScreens(): void
    {
        global $pagenow;

        $screen = (string) $pagenow;

        // The Tools screen hosts other plugins' subpages through ?page=, and
        // those carry their own capability checks. Only the bare screen is
        // refused.
        if ('tools.php' === $screen && ! current_user_can('manage_options') && '' === $this->query('page')) {
            $this->refuse();
        }

        if (! self::trimming()) {
            return;
        }

        if (in_array($screen, self::BLOCKED_SCREENS, true)) {
            $this->refuse();
        }

        if (in_array($screen, [ 'edit.php', 'post-new.php' ], true) && 'post' === $this->postType()) {
            $this->refuse();
        }

        if ('edit-tags.php' === $screen && in_array($this->query('taxonomy'), self::BLOCKED_TAXONOMIES, true)) {
            $this->refuse();
        }
    }

    /**
     * The post type the current screen is for.
     *
     * Defaults to `post` when absent, exactly as edit.php and post-new.php do.
     *
     * @return string
     */
    private function postType(): string
    {
        $type = $this->query('post_type');

        return '' === $type ? 'post' : $type;
    }

    /**
     * One sanitized query parameter naming a screen.
     *
     * @param string $key Parameter name.
     * @return string Empty string when absent.
     */
    private function query(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which screen was asked for; nothing is written.
        if (! isset($_GET[ $key ])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which screen was asked for; nothing is written.
        return sanitize_key(wp_unslash((string) $_GET[ $key ]));
    }

    /**
     * Ends the request with a 403.
     *
     * @return void
     */
    private function refuse(): void
    {
        wp_die(
            esc_html__('Deze site gebruikt dit onderdeel niet.', 'cjw'),
            esc_html__('Niet beschikbaar', 'cjw'),
            [ 'response' => 403 ]
        );
    }
}
