<?php

declare(strict_types=1);

final class YearRolloverTest extends CJW_TestCase
{
    private FakeSubmissionRepository $repository;
    private CJW_Wizard_Year_Rollover $rollover;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_scheduled'] = [];

        $this->repository = new FakeSubmissionRepository();
        $this->rollover = new CJW_Wizard_Year_Rollover($this->repository);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_scheduled']);

        parent::tearDown();
    }

    public function testLastSeasonIsAnonymisedWhenTheNewYearStarts(): void
    {
        $this->repository->seed(3, 'ouder@example.test', '2026');

        $this->rollover->on_year_started('2026', '2027');

        $this->assertSame(0, $this->repository->remaining());
    }

    public function testTheNewSeasonIsLeftAlone(): void
    {
        $this->repository->seed(2, 'ouder@example.test', '2026');
        $this->repository->seed(4, 'nieuwe@example.test', '2027');

        $this->rollover->anonymise_year('2026');

        $this->assertSame(4, $this->repository->remaining());
    }

    public function testEarlierSeasonsAreSweptUpToo(): void
    {
        $this->repository->seed(2, 'oud@example.test', '2024');
        $this->repository->seed(2, 'ouder@example.test', '2026');

        $this->rollover->anonymise_year('2026');

        $this->assertSame(0, $this->repository->remaining());
    }

    public function testARowThatIsAlreadyAnonymisedIsNotCountedTwice(): void
    {
        $this->repository->seed(2, 'ouder@example.test', '2026');
        $this->repository->anonymise(0);

        $this->assertSame(1, $this->rollover->anonymise_year('2026'));
    }

    public function testNothingHappensForAYearThatIsNotAYear(): void
    {
        $this->repository->seed(3, 'ouder@example.test', '2026');

        $this->assertSame(0, $this->rollover->anonymise_year('vorig jaar'));
        $this->assertSame(3, $this->repository->remaining());
    }

    public function testMoreThanOneBatchQueuesAFollowUp(): void
    {
        $this->repository->seed(150, 'ouder@example.test', '2026');

        $anonymised = $this->rollover->anonymise_year('2026');

        $this->assertSame(100, $anonymised);
        $this->assertSame(50, $this->repository->remaining());
        $this->assertCount(1, $GLOBALS['cjw_test_scheduled']);
        $this->assertSame(CJW_Wizard_Year_Rollover::CONTINUE_HOOK, $GLOBALS['cjw_test_scheduled'][0]['hook']);
        $this->assertSame(['2026'], $GLOBALS['cjw_test_scheduled'][0]['args']);
    }

    public function testASingleBatchQueuesNothing(): void
    {
        $this->repository->seed(4, 'ouder@example.test', '2026');

        $this->rollover->anonymise_year('2026');

        $this->assertSame([], $GLOBALS['cjw_test_scheduled']);
    }

    /**
     * A pass that anonymises nothing while rows remain means anonymise() is
     * refusing them. Rescheduling on that would spin once a minute for ever,
     * which is a worse failure than stopping: it is invisible.
     */
    public function testAPassThatAchievesNothingDoesNotRescheduleItself(): void
    {
        $this->repository->seed(3, 'ouder@example.test', '2026');
        $this->repository->makeStubborn(0);
        $this->repository->makeStubborn(1);
        $this->repository->makeStubborn(2);

        $this->assertSame(0, $this->rollover->anonymise_year('2026'));
        $this->assertSame([], $GLOBALS['cjw_test_scheduled']);
    }

    public function testTheOrganiserIsToldHowManyRegistrationsThisTouches(): void
    {
        $this->repository->seed(7, 'ouder@example.test', '2026');

        $effects = $this->rollover->describe_effect([], '2026');

        $this->assertCount(1, $effects);
        $this->assertStringContainsString('7 inschrijvingen', $effects[0]);
        $this->assertStringContainsString('2026', $effects[0]);
        $this->assertStringContainsString('geanonimiseerd', $effects[0]);
    }

    public function testNothingIsSaidWhenThereIsNothingToScrub(): void
    {
        $this->assertSame([], $this->rollover->describe_effect([], '2026'));
    }

    public function testTheDescriptionIsAddedToWhatOtherModulesAlreadySaid(): void
    {
        $this->repository->seed(1, 'ouder@example.test', '2026');

        $effects = $this->rollover->describe_effect(['De kampdata worden leeggemaakt.'], '2026');

        $this->assertCount(2, $effects);
        $this->assertSame('De kampdata worden leeggemaakt.', $effects[0]);
    }
}
