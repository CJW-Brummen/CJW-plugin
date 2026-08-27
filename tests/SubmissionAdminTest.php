<?php

declare(strict_types=1);

/**
 * The submissions list screen.
 *
 * These all guard the same decision: a registration's title is now a number,
 * so nothing an organiser reads may be taken from it.
 */
final class SubmissionAdminTest extends CJW_TestCase
{
    private CJW_Wizard_Submission_Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_post_meta'] = [];
        $GLOBALS['cjw_test_no_edit_link'] = false;
        $GLOBALS['cjw_test_get_posts_return'] = [];

        delete_transient('cjw_wizard_form_count');

        $this->admin = new CJW_Wizard_Submission_Admin(new CJW_Wizard_Submission_Repository());
    }

    public function testTheListNoLongerShowsTheBuiltInTitleColumn(): void
    {
        $columns = $this->admin->filter_columns([
            'cb' => '<input type="checkbox" />',
            'title' => 'Titel',
            'date' => 'Datum',
        ]);

        // The title is "Inschrijving #226". Showing it as a column would give an
        // organiser a screen of numbers.
        $this->assertArrayNotHasKey('title', $columns);
        $this->assertSame('Naam', $columns['cjw_children']);
    }

    public function testTheNameColumnIsTheOneRowActionsHangUnder(): void
    {
        $this->assertSame(
            'cjw_children',
            $this->admin->primary_column('title', 'edit-' . CJW_Wizard_Submission_Repository::POST_TYPE)
        );

        // Every other list table in wp-admin is left alone.
        $this->assertSame('title', $this->admin->primary_column('title', 'edit-page'));
    }

    public function testTheNameColumnReadsTheChildrenNotTheTitle(): void
    {
        update_post_meta(
            226,
            CJW_Wizard_Submission_Repository::META_CHILD_NAME,
            'Daan Jansen, Fleur Jansen, Sem Jansen'
        );

        $this->assertStringContainsString('Daan Jansen, Fleur Jansen, Sem Jansen', $this->cell(226));
        $this->assertStringContainsString('post=226', $this->cell(226));
    }

    public function testTheNameColumnAlsoCarriesTheRegistrationNumber(): void
    {
        update_post_meta(226, CJW_Wizard_Submission_Repository::META_CHILD_NAME, 'Julia Bakker');

        // What the confirmation mail quotes, so it has to be findable here.
        $this->assertStringContainsString('#226', $this->cell(226));
    }

    public function testTheNumberShowsEvenWhenThereIsNoNameToShow(): void
    {
        $this->assertStringContainsString('#300', $this->cell(300));
    }

    public function testTheNameColumnFallsBackToADashWhenTheFormMarksNoNameField(): void
    {
        $this->assertStringContainsString('—', $this->cell(300));
    }

    public function testAnAnonymisedRowStillSaysWhatItIs(): void
    {
        // Retention deletes the child name, so the generated title -- "Gewiste
        // inschrijving (2026)" -- is all that is left to show.
        update_post_meta(301, CJW_Wizard_Submission_Repository::META_ANONYMISED, '2027-01-01');

        $this->assertStringContainsString('Inschrijfformulier', $this->cell(301));
    }

    public function testTheNameIsStillReadableWithoutAnEditLink(): void
    {
        $GLOBALS['cjw_test_no_edit_link'] = true;

        update_post_meta(226, CJW_Wizard_Submission_Repository::META_CHILD_NAME, 'Julia Bakker');

        $cell = $this->cell(226);

        $this->assertStringContainsString('Julia Bakker', $cell);
        $this->assertStringNotContainsString('<a', $cell);
    }

    /**
     * Every spelling WordPress has given the Quick Edit action.
     *
     * 7.1 uses "inline hide-if-no-js"; older releases use "inline hide-if-js".
     * Asserting one literal is how the removal came to do nothing while the
     * suite stayed green.
     *
     * @return array<string, array<int, string>>
     */
    public static function quickEditKeys(): array
    {
        return [
            'WordPress 7.1' => [ 'inline hide-if-no-js' ],
            'older releases' => [ 'inline hide-if-js' ],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('quickEditKeys')]
    public function testQuickEditIsGoneWhateverWordPressCallsIt(string $key): void
    {
        $actions = $this->admin->row_actions(
            [ 'edit' => '<a>Bewerken</a>', $key => '<button>Snel bewerken</button>', 'trash' => '<a>Prullenbak</a>' ],
            new WP_Post(226, CJW_Wizard_Submission_Repository::POST_TYPE)
        );

        $this->assertArrayNotHasKey($key, $actions);
        $this->assertStringNotContainsString('Snel bewerken', implode(' ', $actions));
    }

    public function testQuickEditIsGoneAndEditReadsAsViewing(): void
    {
        $actions = $this->admin->row_actions(
            $this->coreActions(),
            new WP_Post(226, CJW_Wizard_Submission_Repository::POST_TYPE)
        );

        $this->assertStringNotContainsString('Snel bewerken', implode(' ', $actions));
        $this->assertStringContainsString('Bekijken', $actions['edit']);
        $this->assertStringNotContainsString('Bewerken', $actions['edit']);

        // Deleting a registration is still a thing an organiser may do.
        $this->assertArrayHasKey('trash', $actions);
    }

    public function testTheMineTabIsGone(): void
    {
        $views = $this->admin->filter_views([
            'all' => '<a>Alle</a>',
            'mine' => '<a>Mijn</a>',
            'cjw_new' => '<a>Nieuw</a>',
        ]);

        // "Mine" would mean "rows I pressed Opslaan on", which is not a way to
        // filter a season's registrations.
        $this->assertArrayNotHasKey('mine', $views);
        $this->assertSame([ 'all', 'cjw_new' ], array_keys($views));
    }

    public function testARegistrationIsNeverOwnedByWhoeverSavedIt(): void
    {
        $data = $this->admin->disown(
            [ 'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE, 'post_author' => 1 ],
            []
        );

        // A parent filled it in. An organiser pressing Opslaan does not make it
        // theirs -- and that stamp is what brings the "Mijn" tab back.
        $this->assertSame(0, $data['post_author']);
    }

    public function testEveryOtherPostTypeKeepsItsAuthor(): void
    {
        $data = $this->admin->disown([ 'post_type' => 'post', 'post_author' => 1 ], []);

        $this->assertSame(1, $data['post_author']);
    }

    public function testTheOntvangenColumnIsNotWordPressesLastModified(): void
    {
        // WP's own date column shows "Laatst gewijzigd" for anything not
        // published -- which is every registration we have.
        $columns = $this->admin->filter_columns([ 'cb' => '', 'title' => 'Titel', 'date' => 'Datum' ]);

        $this->assertArrayNotHasKey('date', $columns);
        $this->assertSame('Ontvangen', $columns['cjw_received']);
    }

    public function testOtherPostTypesKeepTheirRowActions(): void
    {
        $actions = $this->admin->row_actions($this->coreActions(), new WP_Post(1, 'post'));

        $this->assertSame($this->coreActions(), $actions);
    }

    /**
     * What WordPress offers before the filter runs.
     *
     * @return array<string, string>
     */
    private function coreActions(): array
    {
        return [
            'edit' => '<a href="post.php?post=226&action=edit">Bewerken</a>',
            'inline hide-if-js' => '<button type="button" class="editinline">Snel bewerken</button>',
            'trash' => '<a href="#" class="submitdelete">Prullenbak</a>',
        ];
    }

    private function cell(int $submissionId): string
    {
        ob_start();
        $this->admin->render_column('cjw_children', $submissionId);

        return (string) ob_get_clean();
    }

    public function testTheDetailBoxIsTitledAfterTheRegistration(): void
    {
        $GLOBALS['cjw_test_meta_boxes'] = [];

        $post = new WP_Post(226, CJW_Wizard_Submission_Repository::POST_TYPE);
        $post->post_title = 'Inschrijving #226';

        $this->admin->register_meta_boxes(CJW_Wizard_Submission_Repository::POST_TYPE, $post);

        // The number is stored but the screen heading is a static label and the
        // title field is gone, so this box is the only place it can show.
        $this->assertSame('Inschrijving #226', $GLOBALS['cjw_test_meta_boxes']['cjw_wizard_entry_data']);
    }

    public function testTheDetailBoxStillHasATitleWithoutAPost(): void
    {
        $GLOBALS['cjw_test_meta_boxes'] = [];

        // add_meta_boxes fires on post-new.php too, with nothing to name.
        $this->admin->register_meta_boxes(CJW_Wizard_Submission_Repository::POST_TYPE, null);

        $this->assertSame('Inzendingsgegevens', $GLOBALS['cjw_test_meta_boxes']['cjw_wizard_entry_data']);
    }
}
