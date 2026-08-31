<?php

declare(strict_types=1);

/**
 * Photo consent as something you can query, and duplicate registrations as
 * something a kampbeheerder can see.
 *
 * Both answers were already in the payload, which is a JSON blob: readable one
 * registration at a time and queryable never. "Which children may not be
 * photographed" is asked with a camera in hand before a photo goes on the site,
 * and "have we got this child twice" is asked while counting places. Neither
 * survives being answered by opening two hundred rows.
 *
 * Asked of WordPress rather than of a stub, because all of it is meta queries.
 */
final class ConsentAndDuplicatesTest extends CJW_IntegrationTestCase
{
    private CJW_Wizard_Submission_Repository $repository;

    private int $wizardId = 0;

    public function set_up(): void
    {
        parent::set_up();

        $this->repository = new CJW_Wizard_Submission_Repository();
        $this->wizardId = self::factory()->post->create([ 'post_type' => 'cjw_wizard' ]);
    }

    /**
     * A form asking for children, with a date of birth and photo consent.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'steps' => [
                [
                    'title' => 'Kinderen',
                    'fields' => [
                        [
                            'key' => 'kinderen',
                            'type' => 'repeater',
                            'label' => 'Kinderen',
                            'fields' => [
                                [ 'key' => 'voornaam', 'type' => 'text', 'label' => 'Voornaam', 'role' => 'child_name' ],
                                [ 'key' => 'achternaam', 'type' => 'text', 'label' => 'Achternaam', 'role' => 'child_last_name' ],
                                [ 'key' => 'geboren', 'type' => 'date', 'label' => 'Geboortedatum', 'role' => 'birth_date' ],
                                [ 'key' => 'fotos', 'type' => 'checkbox', 'label' => 'Foto’s', 'role' => 'photo_consent' ],
                            ],
                        ],
                    ],
                ],
                [
                    'title' => 'Ouder',
                    'fields' => [
                        [ 'key' => 'email', 'type' => 'email', 'label' => 'E-mailadres' ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, string>> $children Child rows.
     * @return array<string, mixed>
     */
    private function payload(array $children): array
    {
        return [
            'kinderen' => $children,
            'email' => 'ouder@example.test',
        ];
    }

    /**
     * @param array<int, array<string, string>> $children Child rows.
     */
    private function save(array $children): int
    {
        return $this->repository->save($this->wizardId, $this->config(), $this->payload($children));
    }

    /* Photo consent
    --------------------------------------------- */

    public function testConsentIsStoredOneRowPerChild(): void
    {
        $id = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '1' ],
            [ 'voornaam' => 'Sem', 'achternaam' => 'Jansen', 'geboren' => '2017-09-11', 'fotos' => '' ],
        ]);

        $this->assertSame(
            [ 'ja', 'nee' ],
            get_post_meta($id, CJW_Wizard_Submission_Repository::META_PHOTO_CONSENT, false),
            'Siblings answer separately, so a joined value would be wrong for one of them.'
        );
    }

    public function testAnUncheckedBoxIsNotConsent(): void
    {
        // The whole point of the normalisation: a checkbox that was not ticked
        // sends nothing at all, and "nothing" must not read as yes.
        $this->assertSame('nee', CJW_Wizard_Submission_Repository::consentValue(''));
        $this->assertSame('nee', CJW_Wizard_Submission_Repository::consentValue('0'));
        $this->assertSame('nee', CJW_Wizard_Submission_Repository::consentValue('weet niet'));
    }

    public function testEveryShapeOfYesIsRecognised(): void
    {
        // The field can be a checkbox, a radio or a select depending on how the
        // form was built, and each produces a different word for the same answer.
        foreach ([ '1', 'on', 'ja', 'Ja', 'JA', 'yes', 'akkoord', 'true' ] as $yes) {
            $this->assertSame('ja', CJW_Wizard_Submission_Repository::consentValue($yes), "'{$yes}' is a yes.");
        }
    }

    public function testTheFilterFindsAFamilyWhereOneChildSaidNo(): void
    {
        $mixed = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '1' ],
            [ 'voornaam' => 'Sem', 'achternaam' => 'Jansen', 'geboren' => '2017-09-11', 'fotos' => '' ],
        ]);
        $this->save([
            [ 'voornaam' => 'Noor', 'achternaam' => 'de Wit', 'geboren' => '2016-01-20', 'fotos' => '1' ],
        ]);

        $found = get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => array_keys(CJW_Wizard_Submission_Status::all()),
            'fields' => 'ids',
            'numberposts' => 10,
            'meta_query' => [
                [
                    'key' => CJW_Wizard_Submission_Repository::META_PHOTO_CONSENT,
                    'value' => 'nee',
                ],
            ],
        ]);

        $this->assertSame([ $mixed ], $found);
    }

    public function testTheDashboardCountsChildrenNotRegistrations(): void
    {
        $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '1' ],
            [ 'voornaam' => 'Sem', 'achternaam' => 'Jansen', 'geboren' => '2017-09-11', 'fotos' => '' ],
        ]);
        $this->save([
            [ 'voornaam' => 'Noor', 'achternaam' => 'de Wit', 'geboren' => '2016-01-20', 'fotos' => '' ],
        ]);

        $this->assertSame(
            [ 'ja' => 1, 'nee' => 2 ],
            CJW_Wizard_Submission_Repository::consentCounts()
        );
    }

    /* Duplicate detection
    --------------------------------------------- */

    public function testTheSameChildTwiceIsFlagged(): void
    {
        $first = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '1' ],
        ]);
        $second = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '1' ],
        ]);

        $this->assertSame(
            $first,
            (int) get_post_meta($second, CJW_Wizard_Submission_Repository::META_DUPLICATE_OF, true)
        );
        $this->assertSame(
            '',
            (string) get_post_meta($first, CJW_Wizard_Submission_Repository::META_DUPLICATE_OF, true),
            'The earlier registration is not the duplicate; the later one is.'
        );
    }

    public function testSpellingAndSpacingDoNotHideADuplicate(): void
    {
        $first = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '1' ],
        ]);
        $second = $this->save([
            [ 'voornaam' => '  fleur ', 'achternaam' => 'JANSEN', 'geboren' => '2015-04-02', 'fotos' => '' ],
        ]);

        $this->assertSame(
            $first,
            (int) get_post_meta($second, CJW_Wizard_Submission_Repository::META_DUPLICATE_OF, true),
            'A duplicate that only matches an exact string is a duplicate check that finds nothing.'
        );
    }

    public function testTwoChildrenWithTheSameNameAreNotADuplicate(): void
    {
        $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '1' ],
        ]);
        $second = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2011-08-30', 'fotos' => '1' ],
        ]);

        $this->assertSame(
            '',
            (string) get_post_meta($second, CJW_Wizard_Submission_Repository::META_DUPLICATE_OF, true),
            'Same name, different birthday: two children, not one registered twice.'
        );
    }

    public function testAChildWithNoBirthDateIsNeverCalledADuplicate(): void
    {
        $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '', 'fotos' => '1' ],
        ]);
        $second = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '', 'fotos' => '1' ],
        ]);

        $this->assertSame(
            '',
            (string) get_post_meta($second, CJW_Wizard_Submission_Repository::META_DUPLICATE_OF, true),
            'A name on its own is not enough to call two children the same person.'
        );
    }

    public function testTheKeyReadsTheDateFormatsAFormCanProduce(): void
    {
        $iso = CJW_Wizard_Submission_Repository::childKey('Fleur Jansen', '2015-04-02');

        $this->assertNotSame('', $iso);
        $this->assertSame($iso, CJW_Wizard_Submission_Repository::childKey('Fleur Jansen', '02-04-2015'));
        $this->assertSame($iso, CJW_Wizard_Submission_Repository::childKey('Fleur Jansen', '02/04/2015'));
    }

    public function testTheKeyIsNotAReadableCopyOfTheChild(): void
    {
        $key = CJW_Wizard_Submission_Repository::childKey('Fleur Jansen', '2015-04-02');

        $this->assertStringNotContainsStringIgnoringCase('fleur', $key);
        $this->assertStringNotContainsString('2015', $key);
    }

    /* What anonymisation takes, and what it leaves
    --------------------------------------------- */

    public function testAnonymisingRemovesTheChildKeyAndTheDuplicateLink(): void
    {
        $first = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '1' ],
        ]);
        $second = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '1' ],
        ]);

        $this->repository->anonymise($second);

        $this->assertSame([], get_post_meta($second, CJW_Wizard_Submission_Repository::META_CHILD_KEY, false));
        $this->assertSame(
            '',
            (string) get_post_meta($second, CJW_Wizard_Submission_Repository::META_DUPLICATE_OF, true),
            'The link is derived from a name and a birthday and points at another family. It goes when they do.'
        );
        $this->assertGreaterThan(0, $first);
    }

    public function testAnonymisingKeepsThePhotoAnswer(): void
    {
        // Like the camp type: it identifies nobody, and it is what keeps "12 of
        // them said no to photos" answerable after the personal data is gone.
        $id = $this->save([
            [ 'voornaam' => 'Fleur', 'achternaam' => 'Jansen', 'geboren' => '2015-04-02', 'fotos' => '' ],
        ]);

        $this->repository->anonymise($id);

        $this->assertSame(
            [ 'nee' ],
            get_post_meta($id, CJW_Wizard_Submission_Repository::META_PHOTO_CONSENT, false)
        );
    }
}
