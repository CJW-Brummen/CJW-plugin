<?php

declare(strict_types=1);

/**
 * Berichten and Reacties, removed rather than hidden.
 *
 * CJW has no blog and takes no comments. Taking the two menus off the sidebar
 * is the easy half; these tests are about the half that is usually left undone.
 * A removed menu leaves its screen answering to anybody who types the URL, and
 * a hidden moderation screen over an open comment form is worse than either --
 * spam accumulating where nobody looks.
 */
final class UnusedFeaturesTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        $this->actAsAdministrator();
    }

    public function tear_down(): void
    {
        unset($_GET['post_type'], $_GET['taxonomy'], $_GET['page'], $GLOBALS['pagenow']);

        parent::tear_down();
    }

    /**
     * Whether the screen guard refuses a screen.
     *
     * Asked as a question rather than with expectException, so a test can say
     * "this one is refused and that one is not" in the same shape, and so the
     * screens that must keep working assert something instead of trailing an
     * assertTrue(true).
     *
     * @param string $screen wp-admin file being requested.
     * @param array<string, string> $query Query parameters that name the screen.
     * @return bool
     */
    private function refuses(string $screen, array $query = []): bool
    {
        $GLOBALS['pagenow'] = $screen;

        foreach ($query as $key => $value) {
            $_GET[ $key ] = $value;
        }

        try {
            (new CJW_Admin_Menu())->blockRemovedScreens();
        } catch (WPDieException) {
            return true;
        }

        return false;
    }

    /* The screens are refused, not merely unlinked
    --------------------------------------------- */

    public function testTheAllPostsScreenIsRefused(): void
    {
        $this->assertTrue($this->refuses('edit.php'));
    }

    public function testTheNewPostScreenIsRefused(): void
    {
        $this->assertTrue($this->refuses('post-new.php'));
    }

    public function testTheCommentsScreenIsRefused(): void
    {
        $this->assertTrue($this->refuses('edit-comments.php'));
    }

    public function testASingleCommentScreenIsRefused(): void
    {
        $this->assertTrue($this->refuses('comment.php'));
    }

    public function testTheDiscussionSettingsAreRefused(): void
    {
        $this->assertTrue($this->refuses('options-discussion.php'));
    }

    public function testTheCategoriesScreenIsRefused(): void
    {
        $this->assertTrue($this->refuses('edit-tags.php', [ 'taxonomy' => 'category' ]));
    }

    public function testTheTagsScreenIsRefused(): void
    {
        $this->assertTrue($this->refuses('edit-tags.php', [ 'taxonomy' => 'post_tag' ]));
    }

    /* ...but only those screens
    --------------------------------------------- */

    public function testThePagesScreenStillWorks(): void
    {
        // edit.php is shared: refusing it wholesale would take Pagina's with
        // it, along with every custom post type in the plugin.
        $this->assertFalse($this->refuses('edit.php', [ 'post_type' => 'page' ]));
    }

    public function testTheRentalInventoryScreenStillWorks(): void
    {
        $this->assertFalse(
            $this->refuses('edit.php', [ 'post_type' => CJW_Summer_Camp_Rental_Post_Type::POST_TYPE ])
        );
    }

    public function testAddingANewPageStillWorks(): void
    {
        $this->assertFalse($this->refuses('post-new.php', [ 'post_type' => 'page' ]));
    }

    public function testAnUnrelatedTaxonomyScreenStillWorks(): void
    {
        $this->assertFalse($this->refuses('edit-tags.php', [ 'taxonomy' => 'nav_menu' ]));
    }

    public function testTheDashboardIsUntouched(): void
    {
        $this->assertFalse($this->refuses('index.php'));
    }

    /* Comments are closed, not just unmoderatable
    --------------------------------------------- */

    public function testCommentsAreClosedOnAPageThatSaysTheyAreOpen(): void
    {
        $id = self::factory()->post->create([
            'post_type' => 'page',
            'comment_status' => 'open',
        ]);

        $this->assertFalse(
            comments_open($id),
            'The page says comments are open and the moderation screen is gone. That is how a site collects spam nobody can see.'
        );
    }

    public function testPingsAreClosedToo(): void
    {
        $id = self::factory()->post->create([
            'post_type' => 'page',
            'ping_status' => 'open',
        ]);

        $this->assertFalse(pings_open($id));
    }

    public function testExistingCommentsAreNotRendered(): void
    {
        $id = self::factory()->post->create([ 'post_type' => 'page' ]);
        self::factory()->comment->create([ 'comment_post_ID' => $id ]);

        $this->assertSame(
            [],
            (array) apply_filters('comments_array', [ 'something' ], $id),
            'A comment already in the database must not reach a template.'
        );
    }

    /* The way back
    --------------------------------------------- */

    public function testTheFilterPutsTheScreensBack(): void
    {
        add_filter('cjw_trim_unused_features', '__return_false');

        try {
            $this->assertFalse($this->refuses('edit.php'));
            $this->assertFalse($this->refuses('edit-comments.php'));
        } finally {
            remove_filter('cjw_trim_unused_features', '__return_false');
        }
    }

    public function testTheFilterDoesNotReopenTheToolsGuard(): void
    {
        // Gereedschap is hidden for a different reason -- it is site
        // administration, not an unused feature -- so a site that turns the
        // blog back on must not get Tools back for its volunteers with it.
        $this->actAsLeidingUser();

        add_filter('cjw_trim_unused_features', '__return_false');

        try {
            $this->assertTrue($this->refuses('tools.php'));
        } finally {
            remove_filter('cjw_trim_unused_features', '__return_false');
        }
    }

    private function actAsLeidingUser(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => CJW_Roles::ROLE_LEIDING ]));
    }
}
