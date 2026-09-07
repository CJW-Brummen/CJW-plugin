<?php

declare(strict_types=1);

/**
 * The capability map, asked of WordPress rather than of a stub.
 *
 * Phase 2 shipped a map that denied every camp screen to every user including
 * administrators, and both `composer check` and a green test suite missed it.
 * The cause was invisible to a stub: mapping the meta capabilities
 * (edit_post, read_post, delete_post) onto cjw_manage_camp registered that
 * capability in WordPress's $post_type_meta_caps, so a bare
 * current_user_can('cjw_manage_camp') was re-mapped to edit_post with no post
 * id and resolved to do_not_allow. Only map_meta_cap() can produce that, and
 * only the real one.
 */
final class CapabilityTest extends CJW_IntegrationTestCase
{
    public function testAnAdministratorCanReachTheCampScreens(): void
    {
        $this->actAsAdministrator();

        // The bare check, which is the one that broke.
        $this->assertTrue(current_user_can(CJW_Roles::CAPABILITY));
    }

    public function testTheCampCapabilityIsNotAMetaCapability(): void
    {
        global $post_type_meta_caps;

        // The mechanism itself. If cjw_manage_camp ever lands in this map
        // again, every bare current_user_can() for it silently starts
        // returning false.
        $this->assertArrayNotHasKey(CJW_Roles::CAPABILITY, (array) $post_type_meta_caps);
    }

    public function testAnAdministratorCanOpenAndTrashARegistration(): void
    {
        $this->actAsAdministrator();

        $id = self::factory()->post->create([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::NEW,
        ]);

        $this->assertTrue(current_user_can('edit_post', $id));
        $this->assertTrue(current_user_can('delete_post', $id));
        $this->assertTrue(current_user_can('read_post', $id));
    }

    public function testAKampbeheerderCanRunTheCamp(): void
    {
        CJW_Roles::install();

        $id = self::factory()->user->create([ 'role' => CJW_Roles::ROLE ]);
        wp_set_current_user($id);

        $this->assertTrue(current_user_can(CJW_Roles::CAPABILITY));
        $this->assertTrue(current_user_can('upload_files'));

        // Deliberately withheld: running a camp is not administering a site.
        $this->assertFalse(current_user_can('install_plugins'));
        $this->assertFalse(current_user_can('edit_theme_options'));
        $this->assertFalse(current_user_can('manage_options'));
        $this->assertFalse(current_user_can('update_core'));

        // list_users used to be withheld here too. A Kampbeheerder now holds
        // it, so a new volunteer does not have to wait for an administrator --
        // but only over Leiding accounts. RolesAndAccessTest holds that line.
        $this->assertTrue(current_user_can('list_users'));
    }

    public function testSomebodyWhoCanDraftAPostCannotReadARegistration(): void
    {
        // The reason the capability exists. This used to be a Contributor,
        // whose edit_posts was the only thing between a volunteer blogger and
        // every child's address and medical notes. Contributor is gone and
        // Leiding took its place, so Leiding is what the line is drawn against
        // now -- and Leiding can do considerably more than a Contributor could.
        $id = self::factory()->user->create([ 'role' => CJW_Roles::ROLE_LEIDING ]);
        wp_set_current_user($id);

        // Leiding can rewrite any page on the site, which is a good deal more
        // than the Contributor this used to describe could do.
        $this->assertTrue(current_user_can('edit_others_pages'));
        $this->assertFalse(current_user_can(CJW_Roles::CAPABILITY));
    }

    public function testTheStockContentRolesNoLongerGrantAnything(): void
    {
        // This test used to create an Editor and assert it could not reach camp
        // data. Written that way it now passes for the wrong reason: the role
        // does not exist, so the user has no capabilities at all and the
        // assertion is vacuous. What is worth pinning is that the old names
        // really are inert -- somebody restoring an old user export cannot
        // resurrect a role that once held edit_posts.
        foreach (CJW_Roles::REPLACED_ROLES as $stock) {
            $id = self::factory()->user->create([ 'role' => $stock ]);
            wp_set_current_user($id);

            $this->assertFalse(
                current_user_can('edit_posts'),
                "The '{$stock}' role still grants edit_posts."
            );
            $this->assertFalse(current_user_can(CJW_Roles::CAPABILITY));
        }
    }

    public function testASubscriberCannotOpenARegistration(): void
    {
        $this->actAsSubscriber();

        $id = self::factory()->post->create([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::NEW,
        ]);

        $this->assertFalse(current_user_can('edit_post', $id));
        $this->assertFalse(current_user_can('read_post', $id));
    }
}
