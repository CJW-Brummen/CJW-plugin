<?php

declare(strict_types=1);

/**
 * The submissions list, rendered by the real WP_Posts_List_Table.
 *
 * Quick Edit was "removed" for a fortnight without being removed: the action
 * was unset under the key `inline hide-if-js`, and WordPress 7.1 emits
 * `inline hide-if-no-js`. The unit test asserted the same wrong key, so it
 * agreed. Nothing but the real list table can settle what the key is.
 */
final class AdminListTableTest extends CJW_IntegrationTestCase
{
    private function screen(): WP_Posts_List_Table
    {
        require_once ABSPATH . 'wp-admin/includes/admin.php';

        $type = CJW_Wizard_Submission_Repository::POST_TYPE;

        $_GET['post_type'] = $type;
        $_REQUEST['post_type'] = $type;
        $GLOBALS['typenow'] = $type;
        $GLOBALS['post_type'] = $type;
        $GLOBALS['hook_suffix'] = 'edit.php';

        set_current_screen('edit-' . $type);

        $query = new WP_Query();
        $GLOBALS['wp_the_query'] = $query;
        $GLOBALS['wp_query'] = $query;

        $table = _get_list_table('WP_Posts_List_Table', [ 'screen' => 'edit-' . $type ]);
        $table->prepare_items();

        return $table;
    }

    private function rowsHtml(): string
    {
        ob_start();
        $this->screen()->display_rows();

        return (string) ob_get_clean();
    }

    public function testQuickEditIsGoneFromTheRenderedRow(): void
    {
        $this->actAsAdministrator();
        self::factory()->post->create([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::NEW,
        ]);

        $html = $this->rowsHtml();

        $this->assertStringContainsString('row-actions', $html);
        $this->assertStringNotContainsString('editinline', $html);
        $this->assertStringNotContainsString('Snel', $html);
    }

    public function testTheEditActionReadsAsViewing(): void
    {
        $this->actAsAdministrator();
        self::factory()->post->create([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::NEW,
        ]);

        $this->assertStringContainsString('Bekijken', $this->rowsHtml());
    }

    public function testTheNameColumnShowsTheChildrenAndTheNumber(): void
    {
        $this->actAsAdministrator();

        $id = self::factory()->post->create([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::NEW,
            'post_title' => 'Inschrijving #0',
        ]);
        update_post_meta($id, CJW_Wizard_Submission_Repository::META_CHILD_NAME, 'Fleur Jansen');

        $html = $this->rowsHtml();

        $this->assertStringContainsString('Fleur Jansen', $html);
        $this->assertStringContainsString('#' . $id, $html);
    }

    public function testThereIsNoMijnTab(): void
    {
        $this->actAsAdministrator();
        self::factory()->post->create([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::NEW,
        ]);

        $this->assertArrayNotHasKey('mine', $this->screen()->get_views());
    }

    public function testEveryStatusGetsItsOwnTabExceptCancelledInAlle(): void
    {
        $this->actAsAdministrator();

        foreach (array_keys(CJW_Wizard_Submission_Status::all()) as $status) {
            self::factory()->post->create([
                'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
                'post_status' => $status,
            ]);
        }

        $views = $this->screen()->get_views();

        foreach (array_keys(CJW_Wizard_Submission_Status::all()) as $status) {
            $this->assertArrayHasKey($status, $views, $status);
        }

        // Three of the four, cancelled excluded.
        $this->assertStringContainsString('(3)', $views['all']);
    }

    public function testARegistrationIsNotOwnedByWhoeverSavedIt(): void
    {
        $this->actAsAdministrator();

        $id = wp_insert_post([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::NEW,
            'post_title' => 'Inschrijving',
        ]);

        // WordPress stamps the current user onto post_author on every save.
        // That stamp is untrue here, and it is what raises the "Mijn" tab.
        $this->assertSame('0', get_post_field('post_author', $id));
    }

    public function testTheDetailScreenIsNotAnEditingScreen(): void
    {
        $this->actAsAdministrator();
        require_once ABSPATH . 'wp-admin/includes/admin.php';

        $type = CJW_Wizard_Submission_Repository::POST_TYPE;

        $this->assertFalse(post_type_supports($type, 'title'));
        $this->assertSame('Inzending bekijken', get_post_type_object($type)->labels->edit_item);
    }
}
