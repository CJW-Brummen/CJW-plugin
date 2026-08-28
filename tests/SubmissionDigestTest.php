<?php

declare(strict_types=1);

/**
 * What the form builder contributes to the summer-camp weekly mail.
 */
final class SubmissionDigestTest extends CJW_TestCase
{
    private FakeSubmissionRepository $repository;
    private CJW_Wizard_Submission_Digest $digest;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [
            CJW_Summer_Camp_Service::OPTION_KEY => [ 'start_date' => '10-07-2027' ],
        ];

        $this->repository = new FakeSubmissionRepository();
        $this->digest = new CJW_Wizard_Submission_Digest($this->repository);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_options']);

        parent::tearDown();
    }

    /**
     * Out of season this is every week, and a line reading "0 inschrijvingen"
     * every week is what turns a weekly mail into one nobody opens.
     */
    public function testAQuietWeekAddsNothing(): void
    {
        $this->assertSame([], $this->digest->add_section([]));
    }

    public function testTheWeeksRegistrationsAreCounted(): void
    {
        $this->repository->seed(3, 'ouder@example.test', '2027');

        $sections = $this->digest->add_section([]);

        $this->assertCount(1, $sections);
        $this->assertSame('Nieuwe inschrijvingen', $sections[0]['title']);
        $this->assertStringContainsString('3 inschrijvingen', $sections[0]['lines'][0]);
    }

    public function testOneRegistrationIsNotCalledInschrijvingen(): void
    {
        $this->repository->seed(1, 'ouder@example.test', '2027');

        $sections = $this->digest->add_section([]);

        $this->assertStringContainsString('1 inschrijving binnengekomen', $sections[0]['lines'][0]);
    }

    public function testTheRunningTotalForTheYearIsIncluded(): void
    {
        $this->repository->seed(5, 'ouder@example.test', '2027');

        $lines = implode(' ', $this->digest->add_section([])[0]['lines']);

        $this->assertStringContainsString('In totaal 5 inschrijvingen voor kamp 2027', $lines);
    }

    /**
     * A mailbox is forwarded, backed up and read on trains. Whatever else this
     * mail carries, it does not carry a child's name.
     */
    public function testItCarriesACountAndALinkAndNothingElse(): void
    {
        $this->repository->seed(2, 'ouder@example.test', '2027');

        $lines = implode(' ', $this->digest->add_section([])[0]['lines']);

        $this->assertStringNotContainsString('ouder@example.test', $lines);
        $this->assertStringNotContainsString('Anna', $lines);
        $this->assertStringContainsString('edit.php?post_type=', $lines);
    }

    public function testItGoesAboveWhateverTheCampModuleAlreadyReported(): void
    {
        $this->repository->seed(1, 'ouder@example.test', '2027');

        $sections = $this->digest->add_section([
            [ 'title' => 'Dit heeft aandacht nodig', 'lines' => [ 'Geen contactadres.' ] ],
        ]);

        $this->assertCount(2, $sections);
        $this->assertSame('Nieuwe inschrijvingen', $sections[0]['title']);
    }
}
