<?php

declare(strict_types=1);

final class SubmissionRepositoryTest extends CJW_TestCase
{
    public function testFlattenDataUsesDotNotationForNestedArrays(): void
    {
        $repository = new CJW_Wizard_Submission_Repository();

        $flat = $repository->flatten_data(
            [
                'participant' => [
                    'name' => 'Sam',
                ],
                'children' => [
                    [ 'name' => 'Anna' ],
                    [ 'name' => 'Milan' ],
                ],
            ]
        );

        $this->assertSame(
            [
                'participant.name' => 'Sam',
                'children.0.name' => 'Anna',
                'children.1.name' => 'Milan',
            ],
            $flat
        );
    }

    public function testTheTitleIsTheSubmissionNumberAndNotTheChild(): void
    {
        [ $id, $post ] = $this->saveOne();

        // Anna is what the registration is about, but a title and the slug WordPress
        // derives from it are both personal data the moment they carry her name --
        // in the row, in the URL, and in anything that ever copied either.
        $this->assertSame('Inschrijving #' . $id, $post['post_title']);
        $this->assertSame('inschrijving-' . $id, $post['post_name']);
        $this->assertStringNotContainsStringIgnoringCase('anna', $post['post_title'] . ' ' . $post['post_name']);
    }

    public function testTheChildIsStillRecordedWhereTheListScreenReadsIt(): void
    {
        [ $id ] = $this->saveOne();

        $this->assertSame(
            'Anna de Vries',
            get_post_meta($id, CJW_Wizard_Submission_Repository::META_CHILD_NAME, true)
        );
    }

    public function testARegistrationWithNoNamedChildIsStillTitledCoherently(): void
    {
        // A form that marks no name field used to fall back to
        // "Inschrijfformulier - 2026-08-27 14:02". There is no fallback left to
        // get wrong: the number does not depend on the payload at all.
        [ $id, $post ] = $this->saveOne([ 'steps' => [] ], []);

        $this->assertSame('Inschrijving #' . $id, $post['post_title']);
    }

    /**
     * Saves one registration and returns its id and the stored row.
     *
     * @param array|null $config Form configuration, or null for a form that
     *                           marks a child's name.
     * @param array|null $data   Payload to match it.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function saveOne(?array $config = null, ?array $data = null): array
    {
        $GLOBALS['cjw_test_posts'] = [];
        $GLOBALS['cjw_test_post_meta'] = [];
        $GLOBALS['cjw_test_post_meta_rows'] = [];

        $config ??= [
            'steps' => [
                [
                    'fields' => [
                        [ 'key' => 'voornaam', 'label' => 'Voornaam', 'role' => 'child_name' ],
                        [ 'key' => 'tussenvoegsel', 'label' => 'Tussenvoegsel', 'role' => 'child_infix' ],
                        [ 'key' => 'achternaam', 'label' => 'Achternaam', 'role' => 'child_last_name' ],
                    ],
                ],
            ],
        ];

        $data ??= [ 'voornaam' => 'Anna', 'tussenvoegsel' => 'de', 'achternaam' => 'Vries' ];

        $id = (new CJW_Wizard_Submission_Repository())->save(24, $config, $data);

        return [ $id, $GLOBALS['cjw_test_posts'][ $id ] ];
    }
}
