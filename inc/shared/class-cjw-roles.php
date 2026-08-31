<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Who may do what.
 *
 * Before this, everything camp-related sat on two stock capabilities:
 * `edit_pages` for the settings screen, and `edit_posts` for the registration
 * forms, the submissions and the CSV export. `edit_posts` is held by
 * Contributor and Author, so anyone able to draft a post could read every
 * child's name, address and medical note, and could edit the live registration
 * form. There was no role between "can edit a page" and "can do anything".
 *
 * There are two jobs at CJW and now two roles for them.
 *
 * Leiding keeps the website: copy, pages, images, sponsors. It is the old
 * Editor role, which is what Editor, Auteur and Bijdrager were all being used
 * as anyway -- three roles for one job, and a person had to know which of the
 * three they had been given to know what they could do. Those three are
 * migrated into Leiding and removed, so there is one answer to "what can this
 * volunteer do".
 *
 * Kampbeheerder runs the camp: everything Leiding can do, plus the camp
 * capability that gates settings, forms, submissions and the export, plus
 * enough user management to sign up a new volunteer without waiting for an
 * administrator.
 *
 * Administrator keeps the site: plugins, themes, tools, options, and any
 * account that is not Leiding. Nothing below grants a route to those, and the
 * two guards at the bottom of this class exist to keep it that way.
 */
final class CJW_Roles
{
    /**
     * Gates camp settings, registration forms, submissions and the CSV export.
     */
    public const string CAPABILITY = 'cjw_manage_camp';

    /**
     * Role slug for whoever runs the camp.
     */
    public const string ROLE = 'cjw_kampbeheerder';

    /**
     * Role slug for whoever keeps the website.
     */
    public const string ROLE_LEIDING = 'cjw_leiding';

    /**
     * Bumped whenever the capability map changes.
     *
     * Roles are built by the activation hook, and an activation hook does not
     * run when a plugin is updated -- only when somebody switches it off and on
     * again. So a site that already had this plugin installed would have
     * received the Leiding role never, and its volunteers would have kept
     * whatever they had. Every request compares this against the stored
     * version instead, which costs one autoloaded option read and is the only
     * way a capability change actually reaches a running site.
     */
    public const string VERSION = '2';

    /**
     * Where the installed capability map version is recorded.
     */
    private const string OPTION_VERSION = 'cjw_roles_version';

    /**
     * The stock roles Leiding replaces.
     *
     * Their users are moved to Leiding and the roles themselves are removed, so
     * nobody picks one from a dropdown by accident. Deactivating the plugin
     * puts them back -- see restoreStockRoles().
     *
     * @var array<int, string>
     */
    public const array REPLACED_ROLES = [ 'editor', 'author', 'contributor' ];

    /**
     * The roles a Kampbeheerder may hand out, edit and remove.
     *
     * Administrator and Kampbeheerder are deliberately absent: a volunteer who
     * can run the camp must not be able to make themselves, or anybody else, an
     * administrator. Subscriber is here because it is what WordPress gives a
     * new account by default and refusing it would be confusing.
     *
     * @var array<int, string>
     */
    public const array MANAGEABLE_ROLES = [ self::ROLE_LEIDING, 'subscriber' ];

    /**
     * Stock roles that receive the camp capability on activation.
     *
     * Administrator only. Grant it to anyone else deliberately, by giving them
     * the Kampbeheerder role.
     *
     * @var array<int, string>
     */
    private const array GRANT_TO = [ 'administrator' ];

    /**
     * What Leiding can do: the website, all of it.
     *
     * This is WordPress's Editor role with two deliberate omissions.
     *
     * `unfiltered_html` is not here. It lets a user save raw <script> into a
     * post, which any later visitor -- including an administrator -- then runs.
     * That is a route from "can edit a page" to "can do anything", which is the
     * exact boundary this file exists to draw. Nothing in the CJW design needs
     * hand-written script in page content.
     *
     * `manage_links` is not here either: the Links manager has been hidden by
     * default since WordPress 3.5 and this site does not use it.
     *
     * The level_* capabilities are numeric leftovers from WordPress 2.0 that a
     * few core queries still read. They are kept because dropping them breaks
     * the author dropdown in ways that are hard to trace back here.
     *
     * @var array<int, string>
     */
    private const array LEIDING_CAPABILITIES = [
        'read',
        'upload_files',
        'manage_categories',

        'edit_pages',
        'edit_others_pages',
        'edit_published_pages',
        'edit_private_pages',
        'publish_pages',
        'read_private_pages',
        'delete_pages',
        'delete_others_pages',
        'delete_published_pages',
        'delete_private_pages',

        'level_0',
        'level_1',
        'level_2',
        'level_3',
        'level_4',
        'level_5',
        'level_6',
        'level_7',
    ];

    /**
     * What a Kampbeheerder can do on top of Leiding.
     *
     * The user capabilities are the reason this role can sign up a new
     * volunteer. On their own they would also let a Kampbeheerder edit an
     * administrator's account and take the site over, so they never travel
     * alone: editableRoles() limits which roles can be handed out, and
     * protectPrivilegedUsers() refuses to edit or delete anybody who is not
     * Leiding. Both are registered in registerGuards().
     *
     * Deliberately absent, in every case because it is the site rather than the
     * camp: installing plugins, editing themes and theme files, the Tools menu,
     * and the options screens.
     *
     * @var array<int, string>
     */
    private const array CAMP_MANAGER_CAPABILITIES = [
        self::CAPABILITY,
        'list_users',
        'create_users',
        'edit_users',
        'delete_users',
        'promote_users',
    ];

    /**
     * Creates both roles, migrates the stock ones and grants the camp
     * capability. Safe to run repeatedly.
     *
     * @return void
     */
    public static function install(): void
    {
        self::ensureRole(
            self::ROLE_LEIDING,
            __('Leiding', 'cjw'),
            self::LEIDING_CAPABILITIES
        );

        self::ensureRole(
            self::ROLE,
            __('Kampbeheerder', 'cjw'),
            [ ...self::LEIDING_CAPABILITIES, ...self::CAMP_MANAGER_CAPABILITIES ]
        );

        // Before the roles are removed, or the people in them become users with
        // a role that no longer exists: able to log in, able to do nothing.
        self::migrateReplacedRoles();
        self::removeReplacedRoles();

        foreach (self::GRANT_TO as $roleName) {
            $role = get_role($roleName);

            if ($role instanceof WP_Role) {
                $role->add_cap(self::CAPABILITY);
            }
        }

        update_option(self::OPTION_VERSION, self::VERSION, true);
    }

    /**
     * Builds the roles when the stored map is older than this one.
     *
     * Runs on every request and does nothing on all but the first: the version
     * is an autoloaded option, so the check is a single array lookup against
     * memory once WordPress has loaded its options.
     *
     * @return void
     */
    public static function installIfOutdated(): void
    {
        if (get_option(self::OPTION_VERSION) === self::VERSION) {
            return;
        }

        self::install();
    }

    /**
     * Creates a role, or tops up an existing one.
     *
     * Topped up rather than rebuilt: remove_role() followed by add_role() would
     * silently discard anything an administrator had added to the role, every
     * time the plugin is reactivated.
     *
     * @param string $slug Role slug.
     * @param string $label Display name.
     * @param array<int, string> $capabilities Capabilities the role must hold.
     * @return void
     */
    private static function ensureRole(string $slug, string $label, array $capabilities): void
    {
        $existing = get_role($slug);

        if (! $existing instanceof WP_Role) {
            add_role($slug, $label, array_fill_keys($capabilities, true));

            return;
        }

        foreach ($capabilities as $capability) {
            if (! $existing->has_cap($capability)) {
                $existing->add_cap($capability);
            }
        }
    }

    /**
     * Moves everyone in Editor, Auteur or Bijdrager to Leiding.
     *
     * A user keeps every other role they hold: somebody who is both an
     * administrator and an editor stays an administrator.
     *
     * @return int How many users were moved.
     */
    public static function migrateReplacedRoles(): int
    {
        $moved = 0;

        foreach (self::REPLACED_ROLES as $stock) {
            // Queried by role rather than by capability: the role's definition
            // may already be gone, but the user's own stored role list is what
            // this reads and that survives remove_role().
            $query = [
                'role' => $stock,
                'fields' => 'ID',
            ];

            foreach (get_users($query) as $userId) {
                $user = get_userdata((int) $userId);

                if (! $user instanceof WP_User) {
                    continue;
                }

                $user->remove_role($stock);

                if (! in_array(self::ROLE_LEIDING, $user->roles, true)) {
                    $user->add_role(self::ROLE_LEIDING);
                }

                ++$moved;
            }
        }

        return $moved;
    }

    /**
     * Removes Editor, Auteur and Bijdrager from the role list.
     *
     * @return void
     */
    private static function removeReplacedRoles(): void
    {
        foreach (self::REPLACED_ROLES as $stock) {
            if (get_role($stock) instanceof WP_Role) {
                remove_role($stock);
            }
        }
    }

    /**
     * Puts the stock roles back, on deactivation.
     *
     * Without this, deactivating the plugin would leave a site with no Editor,
     * Auteur or Bijdrager and no way to recreate them from wp-admin -- the
     * plugin would have taken something away that it never announced it was
     * borrowing. populate_roles() is WordPress's own installer routine: it adds
     * whichever default roles are missing and tops up their capabilities,
     * leaving anything else alone.
     *
     * The CJW roles are deliberately left in place. Removing them would strip
     * every volunteer of their capabilities the moment a plugin is toggled.
     *
     * @return void
     */
    public static function restoreStockRoles(): void
    {
        require_once ABSPATH . 'wp-admin/includes/schema.php';

        populate_roles();
    }

    /**
     * Registers the two filters that keep user management from becoming a
     * route to administrator.
     *
     * @return void
     */
    public static function registerGuards(): void
    {
        add_filter('editable_roles', [ self::class, 'editableRoles' ]);
        add_filter('map_meta_cap', [ self::class, 'protectPrivilegedUsers' ], 10, 4);
    }

    /**
     * Limits which roles a Kampbeheerder may hand out.
     *
     * This is the gate on the dropdown, and on the server: edit_user() and
     * wp_insert_user() both validate a submitted role against
     * get_editable_roles(), so a hand-made POST cannot get past it either.
     *
     * @param array<string, array<string, mixed>> $roles Roles the current user may assign.
     * @return array<string, array<string, mixed>>
     */
    public static function editableRoles($roles)
    {
        if (! is_array($roles)) {
            return $roles;
        }

        // Administrators are not ours to restrict.
        if (current_user_can('manage_options')) {
            return $roles;
        }

        if (! current_user_can(self::CAPABILITY)) {
            return $roles;
        }

        return array_intersect_key($roles, array_flip(self::MANAGEABLE_ROLES));
    }

    /**
     * Refuses a Kampbeheerder any user but a Leiding one.
     *
     * `edit_users` on its own is the whole site: it is enough to change an
     * administrator's password and log in as them. This maps the three meta
     * capabilities that act on a specific user, and denies them whenever the
     * person being acted on holds a role a Kampbeheerder may not hand out.
     *
     * Their own account is exempt, or a Kampbeheerder could not change their
     * own password.
     *
     * @param array<int, string> $caps Primitive capabilities required.
     * @param string $cap Meta capability being checked.
     * @param int $userId User attempting the action.
     * @param array<int, mixed> $args Arguments; $args[0] is the target user id.
     * @return array<int, string>
     */
    public static function protectPrivilegedUsers($caps, $cap, $userId, $args)
    {
        if (! in_array($cap, [ 'edit_user', 'delete_user', 'promote_user' ], true)) {
            return $caps;
        }

        if (! isset($args[0])) {
            return $caps;
        }

        $targetId = (int) $args[0];

        // Own profile. Without this a Kampbeheerder could not change their own
        // password, because their own role is not one they may hand out.
        if ($targetId === (int) $userId) {
            return $caps;
        }

        $actor = get_userdata((int) $userId);

        if (! $actor instanceof WP_User) {
            return $caps;
        }

        // Administrators are unaffected, and anybody without the camp
        // capability is already governed by WordPress's own rules.
        if ($actor->has_cap('manage_options') || ! $actor->has_cap(self::CAPABILITY)) {
            return $caps;
        }

        $target = get_userdata($targetId);

        if (! $target instanceof WP_User) {
            return [ 'do_not_allow' ];
        }

        foreach ($target->roles as $role) {
            if (! in_array($role, self::MANAGEABLE_ROLES, true)) {
                return [ 'do_not_allow' ];
            }
        }

        return $caps;
    }

    /**
     * Whether the current user may see camp data.
     */
    public static function currentUserCan(): bool
    {
        return current_user_can(self::CAPABILITY);
    }

    /**
     * Maps a post type's primitive capabilities onto the camp capability.
     *
     * Passed to register_post_type() so the forms and submissions screens are
     * gated by `cjw_manage_camp` rather than by `edit_posts`, which Contributor
     * and Author hold.
     *
     * Only the primitive (plural) capabilities appear here. The meta
     * capabilities -- `edit_post`, `read_post`, `delete_post` -- are deliberately
     * left alone, and this is not a detail:
     *
     * register_post_type() feeds a post type's meta capabilities to
     * _post_type_meta_capabilities(), which records each custom name in the
     * global $post_type_meta_caps. Naming `cjw_manage_camp` there would tell
     * WordPress that it IS a meta capability, so a plain
     * current_user_can('cjw_manage_camp') would be re-mapped to `edit_post`
     * with no post to check -- and map_meta_cap() answers that with
     * `do_not_allow`. Every gate would fail closed, for administrators too.
     *
     * With `map_meta_cap => true` and only the primitives listed, core maps the
     * meta capabilities through these primitives on its own, which is what we
     * want anyway.
     *
     * @param bool $allowCreate Whether new posts of this type may be created by
     *                          hand in wp-admin. False maps `create_posts` to
     *                          `do_not_allow`, which is how WordPress is told to
     *                          drop the "Add New" button and refuse
     *                          post-new.php. It does not affect wp_insert_post(),
     *                          which checks no capabilities -- so the public form
     *                          keeps writing submissions exactly as before.
     *
     * @return array<string, string>
     */
    public static function postTypeCapabilities(bool $allowCreate = true): array
    {
        $primitives = [
            'edit_posts',
            'edit_others_posts',
            'edit_private_posts',
            'edit_published_posts',
            'publish_posts',
            'read_private_posts',
            'delete_posts',
            'delete_private_posts',
            'delete_published_posts',
            'delete_others_posts',
            'create_posts',
        ];

        $capabilities = array_fill_keys($primitives, self::CAPABILITY);

        if (! $allowCreate) {
            $capabilities['create_posts'] = 'do_not_allow';
        }

        return $capabilities;
    }

    /**
     * Everyone who may see camp data.
     *
     * Anyone holding the capability, which is Kampbeheerders and
     * administrators. Deliberately asked as "who holds the capability" rather
     * than "who has the role": an administrator who granted it to a second role
     * meant that, and a list that ignored them would quietly leave somebody out.
     *
     * @return array<int, WP_User>
     */
    public static function campManagers(): array
    {
        $managers = [];

        // 'fields' => 'ID' (string) returns a flat list of ids. The array form
        // returns stdClass rows and poisons the user meta cache for every later
        // query in the request.
        foreach (get_users([ 'fields' => 'ID' ]) as $userId) {
            $user = get_userdata((int) $userId);

            if ($user instanceof WP_User && $user->has_cap(self::CAPABILITY)) {
                $managers[] = $user;
            }
        }

        return $managers;
    }

    /**
     * Users who could reach camp data under the old `edit_posts` gate but
     * cannot under the new one.
     *
     * Leiding is skipped. That role was created after the gate moved and has
     * never had camp access by design, so listing every Leiding member as
     * "lost access" would turn a one-off migration notice into a permanent
     * complaint about the way the site is meant to work.
     *
     * Nothing is changed for them automatically: promoting someone to read
     * children's data is a decision for a person, not for an activation hook.
     * CJW_Roles_Notice reports the list once so it is not discovered by
     * somebody quietly losing access.
     *
     * @return array<int, WP_User>
     */
    public static function usersLosingAccess(): array
    {
        $affected = [];

        // 'fields' => 'ID' (string) returns a flat list of ids. The array form
        // returns stdClass rows and poisons the user meta cache for every later
        // query in the request.
        foreach (get_users([ 'fields' => 'ID' ]) as $userId) {
            $user = get_userdata((int) $userId);

            if (! $user instanceof WP_User) {
                continue;
            }

            if ($user->has_cap(self::CAPABILITY)) {
                continue;
            }

            if (in_array(self::ROLE_LEIDING, $user->roles, true)) {
                continue;
            }

            if ($user->has_cap('edit_posts')) {
                $affected[] = $user;
            }
        }

        return $affected;
    }
}
