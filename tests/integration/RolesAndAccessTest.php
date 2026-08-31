<?php

declare(strict_types=1);

/**
 * Who can do what, asked of WordPress rather than of a stub.
 *
 * This is the file to be suspicious of. A capability map is the one part of a
 * plugin where a mistake is silent in both directions: too tight and a
 * volunteer quietly cannot do their job, too loose and somebody who was given
 * the keys to the copy has the keys to the site. Neither shows up as an error.
 *
 * The user capabilities on Kampbeheerder are the sharp edge. `edit_users` on
 * its own is enough to change an administrator's password and log in as them,
 * so most of what follows is about proving that the two guards in CJW_Roles
 * actually close that, against real users, through real map_meta_cap().
 */
final class RolesAndAccessTest extends CJW_IntegrationTestCase
{
    public function tear_down(): void
    {
        // Roles live in an option and are cached in a global. The option rolls
        // back with the transaction; the global does not, and a test that adds
        // or removes a role would otherwise leak it into the next one.
        unset($GLOBALS['wp_roles']);

        parent::tear_down();
    }

    /* The two roles exist and are the right size
    --------------------------------------------- */

    public function testBothRolesAreCreatedOnActivation(): void
    {
        $this->assertInstanceOf(WP_Role::class, get_role(CJW_Roles::ROLE));
        $this->assertInstanceOf(WP_Role::class, get_role(CJW_Roles::ROLE_LEIDING));
    }

    public function testLeidingCanKeepTheWebsite(): void
    {
        $user = $this->actAsLeiding();

        foreach (
            [
                'read', 'upload_files',
                'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages',
                'delete_pages', 'delete_others_pages', 'delete_published_pages',
            ] as $capability
        ) {
            $this->assertTrue(
                user_can($user, $capability),
                "Leiding cannot '{$capability}', which is part of keeping the website."
            );
        }
    }

    public function testLeidingHoldsNoPostCapabilities(): void
    {
        // There are no posts on this site and never will be. A role carrying
        // capabilities for content that cannot exist is how a menu nobody wants
        // comes back later.
        $user = $this->actAsLeiding();

        foreach (
            [
                'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts',
                'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts',
                'delete_published_posts', 'delete_private_posts', 'moderate_comments',
            ] as $capability
        ) {
            $this->assertFalse(
                user_can($user, $capability),
                "Leiding holds '{$capability}', for content this site does not have."
            );
        }
    }

    public function testLeidingCannotReachCampData(): void
    {
        $user = $this->actAsLeiding();

        $this->assertFalse(
            user_can($user, CJW_Roles::CAPABILITY),
            'Leiding must not reach registrations: children\'s names, addresses and medical notes.'
        );
        $this->assertFalse(current_user_can(CJW_Roles::CAPABILITY));
    }

    public function testLeidingCannotAdministerTheSite(): void
    {
        $user = $this->actAsLeiding();

        foreach (
            [
                'manage_options', 'activate_plugins', 'install_plugins', 'update_plugins',
                'switch_themes', 'edit_theme_options', 'edit_themes', 'edit_files',
                'list_users', 'create_users', 'edit_users', 'delete_users', 'promote_users',
                'import', 'export',
            ] as $capability
        ) {
            $this->assertFalse(
                user_can($user, $capability),
                "Leiding holds '{$capability}', which is site administration."
            );
        }
    }

    public function testLeidingCannotSaveRawScriptIntoAPage(): void
    {
        // unfiltered_html is the route from "can edit a page" to "can do
        // anything": a <script> saved into a post runs in the next
        // administrator's browser.
        $this->assertFalse(user_can($this->actAsLeiding(), 'unfiltered_html'));
    }

    public function testAKampbeheerderCanDoEverythingLeidingCan(): void
    {
        $leiding = get_role(CJW_Roles::ROLE_LEIDING);
        $manager = $this->actAsKampbeheerder();

        foreach (array_keys(array_filter($leiding->capabilities)) as $capability) {
            $this->assertTrue(
                user_can($manager, $capability),
                "A Kampbeheerder cannot '{$capability}', which Leiding can."
            );
        }
    }

    public function testAKampbeheerderRunsTheCampButNotTheSite(): void
    {
        $manager = $this->actAsKampbeheerder();

        $this->assertTrue(user_can($manager, CJW_Roles::CAPABILITY));
        $this->assertTrue(user_can($manager, 'list_users'));
        $this->assertTrue(user_can($manager, 'create_users'));

        foreach (
            [
                'manage_options', 'activate_plugins', 'install_plugins', 'update_plugins',
                'switch_themes', 'edit_theme_options', 'edit_themes', 'edit_files',
                'import', 'export', 'update_core',
            ] as $capability
        ) {
            $this->assertFalse(
                user_can($manager, $capability),
                "A Kampbeheerder holds '{$capability}', which is site administration."
            );
        }
    }

    /* Taking a capability back
    --------------------------------------------- */

    public function testACapabilityGrantedByAnEarlierVersionIsTakenBack(): void
    {
        // The install used to be add-only: it could top a role up but never
        // trim it, so removing a capability from the map reached a fresh site
        // and no existing one. Removing the post capabilities from Leiding was
        // exactly that change, and it did nothing on the site it was written on.
        $role = get_role(CJW_Roles::ROLE_LEIDING);
        $role->add_cap('edit_posts');

        // The record is what tells a capability this plugin granted from one
        // somebody added by hand. Written as though an earlier version had
        // granted edit_posts, which is what actually happened.
        $record = get_option('cjw_roles_installed');
        $record[ CJW_Roles::ROLE_LEIDING ][] = 'edit_posts';
        update_option('cjw_roles_installed', $record);

        $this->assertTrue(user_can($this->actAsLeiding(), 'edit_posts'));

        CJW_Roles::install();
        unset($GLOBALS['wp_roles']);

        $this->assertFalse(
            user_can($this->actAsLeiding(), 'edit_posts'),
            'A capability this plugin no longer grants is still on the role.'
        );
    }

    public function testACapabilityAnAdministratorAddedByHandSurvives(): void
    {
        // The reason the install records what it granted rather than simply
        // rebuilding the role: an administrator who widened Leiding on purpose
        // should not have it undone by the next plugin update.
        CJW_Roles::install();

        $role = get_role(CJW_Roles::ROLE_LEIDING);
        $role->add_cap('export');

        CJW_Roles::install();
        unset($GLOBALS['wp_roles']);

        $this->assertTrue(
            user_can($this->actAsLeiding(), 'export'),
            'A capability added by hand was thrown away by a reinstall.'
        );
    }

    /* The stock roles are gone, and their people are not
    --------------------------------------------- */

    public function testEditorAuteurAndBijdragerAreRemoved(): void
    {
        foreach (CJW_Roles::REPLACED_ROLES as $stock) {
            $this->assertNull(
                get_role($stock),
                "The '{$stock}' role is still in the dropdown; Leiding was supposed to replace it."
            );
        }
    }

    public function testSomebodyInAReplacedRoleBecomesLeiding(): void
    {
        add_role('editor', 'Editor', [ 'read' => true, 'edit_posts' => true ]);
        $id = self::factory()->user->create([ 'role' => 'editor' ]);

        CJW_Roles::migrateReplacedRoles();

        $user = get_userdata($id);

        $this->assertContains(CJW_Roles::ROLE_LEIDING, $user->roles);
        $this->assertNotContains('editor', $user->roles, 'They kept a role that is about to stop existing.');
    }

    public function testAnAdministratorWhoIsAlsoAnEditorStaysAnAdministrator(): void
    {
        add_role('editor', 'Editor', [ 'read' => true, 'edit_posts' => true ]);
        $id = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($id)->add_role('editor');

        CJW_Roles::migrateReplacedRoles();

        $this->assertContains('administrator', get_userdata($id)->roles);
    }

    public function testTheStockRolesComeBackWhenThePluginIsDeactivated(): void
    {
        CJW_Roles::restoreStockRoles();

        foreach (CJW_Roles::REPLACED_ROLES as $stock) {
            $this->assertInstanceOf(
                WP_Role::class,
                get_role($stock),
                "Deactivating left the site without '{$stock}' and no way to recreate it."
            );
        }
    }

    /* Handing out roles
    --------------------------------------------- */

    public function testAKampbeheerderMayOnlyHandOutLeiding(): void
    {
        $this->actAsKampbeheerder();

        $editable = array_keys(get_editable_roles());

        sort($editable);
        $expected = CJW_Roles::MANAGEABLE_ROLES;
        sort($expected);

        $this->assertSame($expected, $editable);
        $this->assertNotContains('administrator', $editable);
        $this->assertNotContains(CJW_Roles::ROLE, $editable, 'They could make a second Kampbeheerder.');
    }

    public function testAnAdministratorMayStillHandOutEveryRole(): void
    {
        $this->actAsAdministrator();

        $this->assertContains('administrator', array_keys(get_editable_roles()));
        $this->assertContains(CJW_Roles::ROLE, array_keys(get_editable_roles()));
    }

    /* Acting on a specific user
    --------------------------------------------- */

    public function testAKampbeheerderCannotEditAnAdministrator(): void
    {
        $administrator = self::factory()->user->create([ 'role' => 'administrator' ]);
        $this->actAsKampbeheerder();

        $this->assertFalse(
            current_user_can('edit_user', $administrator),
            'edit_user on an administrator is a password change away from owning the site.'
        );
        $this->assertFalse(current_user_can('delete_user', $administrator));
        $this->assertFalse(current_user_can('promote_user', $administrator));
    }

    public function testAKampbeheerderCannotEditAnotherKampbeheerder(): void
    {
        $other = self::factory()->user->create([ 'role' => CJW_Roles::ROLE ]);
        $this->actAsKampbeheerder();

        $this->assertFalse(current_user_can('edit_user', $other));
    }

    public function testAKampbeheerderCanManageLeiding(): void
    {
        $leiding = self::factory()->user->create([ 'role' => CJW_Roles::ROLE_LEIDING ]);
        $this->actAsKampbeheerder();

        $this->assertTrue(current_user_can('edit_user', $leiding));
        $this->assertTrue(current_user_can('delete_user', $leiding));
        $this->assertTrue(current_user_can('promote_user', $leiding));
    }

    public function testAKampbeheerderCanStillChangeTheirOwnPassword(): void
    {
        $id = $this->actAsKampbeheerder();

        $this->assertTrue(
            current_user_can('edit_user', $id),
            'Their own role is not one they may hand out, which must not lock them out of their own profile.'
        );
    }

    public function testAnAdministratorIsUnaffectedByTheGuard(): void
    {
        $other = self::factory()->user->create([ 'role' => 'administrator' ]);
        $this->actAsAdministrator();

        $this->assertTrue(current_user_can('edit_user', $other));
        $this->assertTrue(current_user_can('delete_user', $other));
    }

    public function testLeidingCannotManageUsersAtAll(): void
    {
        $other = self::factory()->user->create([ 'role' => CJW_Roles::ROLE_LEIDING ]);
        $this->actAsLeiding();

        $this->assertFalse(current_user_can('edit_user', $other));
        $this->assertFalse(current_user_can('list_users'));
    }

    /* The inventory is camp operations
    --------------------------------------------- */

    public function testLeidingCannotEditTheRentalInventory(): void
    {
        $this->actAsLeiding();

        $type = get_post_type_object(CJW_Summer_Camp_Rental_Post_Type::POST_TYPE);

        $this->assertInstanceOf(WP_Post_Type::class, $type);
        $this->assertFalse(
            current_user_can($type->cap->edit_posts),
            'How many tents are out on loan is camp operations, not website copy.'
        );
    }

    public function testAKampbeheerderCanEditTheRentalInventory(): void
    {
        $this->actAsKampbeheerder();

        $type = get_post_type_object(CJW_Summer_Camp_Rental_Post_Type::POST_TYPE);

        $this->assertTrue(current_user_can($type->cap->edit_posts));
    }

    public function testLeidingCanStillManageSponsors(): void
    {
        // Sponsor logos are pictures on the front page: website content, and
        // deliberately left where Leiding can reach them. This is the assertion
        // that catches it if the sponsor post type ever goes back to the
        // default post capabilities, which Leiding no longer holds.
        $this->actAsLeiding();

        $type = get_post_type_object(CJW_Summer_Camp_Sponsor_Post_Type::POST_TYPE);

        $this->assertInstanceOf(WP_Post_Type::class, $type);
        $this->assertTrue(current_user_can($type->cap->edit_posts));
    }

    /* Helpers
    --------------------------------------------- */

    private function actAsLeiding(): int
    {
        $id = self::factory()->user->create([ 'role' => CJW_Roles::ROLE_LEIDING ]);
        wp_set_current_user($id);

        return $id;
    }

    private function actAsKampbeheerder(): int
    {
        $id = self::factory()->user->create([ 'role' => CJW_Roles::ROLE ]);
        wp_set_current_user($id);

        return $id;
    }
}
