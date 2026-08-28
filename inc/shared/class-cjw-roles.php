<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The capability map for running a camp.
 *
 * Before this, everything camp-related sat on two stock capabilities:
 * `edit_pages` for the settings screen, and `edit_posts` for the registration
 * forms, the submissions and the CSV export. `edit_posts` is held by
 * Contributor and Author, so anyone able to draft a post could read every
 * child's name, address and medical note, and could edit the live registration
 * form. There was no role between "can edit a page" and "can do anything".
 *
 * One capability now gates camp data -- `cjw_manage_camp` -- and one role holds
 * the job: Kampbeheerder. Administrators keep it because they hold everything.
 * Editors deliberately do not: managing site content is not the same job as
 * reading children's personal data.
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
     * Stock roles that receive the capability on activation.
     *
     * Administrator only. Editor is excluded on purpose -- see the class
     * docblock. Grant it to anyone else deliberately, by giving them the
     * Kampbeheerder role.
     *
     * @var array<int, string>
     */
    private const array GRANT_TO = [ 'administrator' ];

    /**
     * What a Kampbeheerder can do, beyond the camp capability itself.
     *
     * Enough to run a camp year unaided: read the dashboard, work with pages
     * the camp copy links to, and upload the hero image, photo wall and sponsor
     * logos. Deliberately absent: installing plugins, editing theme files,
     * managing users, and deleting other people's content.
     *
     * @var array<int, string>
     */
    private const array ROLE_CAPABILITIES = [
        'read',
        'upload_files',
        'edit_posts',
        'edit_published_posts',
        'publish_posts',
        'delete_posts',
        'edit_pages',
        'edit_published_pages',
        'publish_pages',
    ];

    /**
     * Creates the role and grants the capability. Safe to run repeatedly.
     */
    public static function install(): void
    {
        $capabilities = [ ...self::ROLE_CAPABILITIES, self::CAPABILITY ];
        $existing = get_role(self::ROLE);

        if ($existing instanceof WP_Role) {
            // Top the role up rather than rebuilding it. remove_role() followed
            // by add_role() would silently discard anything an administrator had
            // added to Kampbeheerder, every time the plugin is reactivated.
            foreach ($capabilities as $capability) {
                if (! $existing->has_cap($capability)) {
                    $existing->add_cap($capability);
                }
            }
        } else {
            add_role(self::ROLE, __('Kampbeheerder', 'cjw'), array_fill_keys($capabilities, true));
        }

        foreach (self::GRANT_TO as $roleName) {
            $role = get_role($roleName);

            if ($role instanceof WP_Role) {
                $role->add_cap(self::CAPABILITY);
            }
        }
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

            if ($user->has_cap('edit_posts')) {
                $affected[] = $user;
            }
        }

        return $affected;
    }
}
