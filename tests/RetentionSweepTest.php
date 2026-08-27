<?php

declare(strict_types=1);

/**
 * The sweep decides when children's data stops existing. Wrong in one direction
 * it keeps personal data indefinitely, which is the AVG problem it was built to
 * solve; wrong in the other it destroys a live season's registrations.
 */
final class RetentionSweepTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['cjw_test_options'] = [];

        parent::tearDown();
    }

    /**
     * @param array $settings Camp settings to store.
     */
    private function withSettings(array $settings): void
    {
        $GLOBALS['cjw_test_options'][ CJW_Summer_Camp_Service::OPTION_KEY ] = $settings;
    }

    private function sweeper(?FakeSubmissionRepository $repository = null): CJW_Wizard_Submission_Retention
    {
        return new CJW_Wizard_Submission_Retention($repository ?? new FakeSubmissionRepository());
    }

    public function testRetentionOffMeansNothingIsEverExpired(): void
    {
        $this->withSettings([ 'retention_months' => 0, 'end_date' => '2020-08-15' ]);

        $this->assertNull($this->sweeper()->cutoff(), 'Zero months must switch the sweep off, not expire everything.');
    }

    public function testNoCampEndDateMeansNothingIsExpired(): void
    {
        $this->withSettings([ 'retention_months' => 12, 'end_date' => '' ]);

        $this->assertNull(
            $this->sweeper()->cutoff(),
            'Without an end date there is no reference point, and guessing one could wipe a live season.'
        );
    }

    public function testAMalformedEndDateIsTreatedAsNoDate(): void
    {
        $this->withSettings([ 'retention_months' => 12, 'end_date' => 'volgende zomer' ]);

        $this->assertNull($this->sweeper()->cutoff());
    }

    public function testTheCutoffIsTheCampEndMinusTheRetentionPeriodWhileTheCampIsStillAhead(): void
    {
        $end = (new DateTimeImmutable('now'))->modify('+2 months');
        $this->withSettings([ 'retention_months' => 12, 'end_date' => $end->format('Y-m-d') ]);

        $cutoff = $this->sweeper()->cutoff();

        $this->assertInstanceOf(DateTimeImmutable::class, $cutoff);
        $this->assertSame(
            $end->modify('-12 months')->format('Y-m-d'),
            $cutoff->format('Y-m-d')
        );
    }

    /**
     * The judgement call worth pinning: measuring only from the stored end date
     * has a silent failure mode. Nobody updates the setting for two years, the
     * cut-off freezes at that old date, and the sweep stops expiring anything
     * while still running daily and reporting success.
     */
    public function testAStaleCampEndDateDoesNotFreezeTheCutoff(): void
    {
        $this->withSettings([ 'retention_months' => 12, 'end_date' => '2019-08-15' ]);

        $cutoff = $this->sweeper()->cutoff();
        $expected = (new DateTimeImmutable('now'))->modify('-12 months');

        $this->assertInstanceOf(DateTimeImmutable::class, $cutoff);
        $this->assertSame(
            $expected->format('Y-m'),
            $cutoff->format('Y-m'),
            'With the end date long past, retention must advance with the calendar instead of standing still.'
        );
    }

    public function testTheCutoffIsNeverInTheFuture(): void
    {
        $this->withSettings([ 'retention_months' => 1, 'end_date' => '2019-08-15' ]);

        $this->assertLessThan(
            (new DateTimeImmutable('now'))->getTimestamp(),
            $this->sweeper()->cutoff()->getTimestamp(),
            'A cut-off in the future would expire registrations that have not been taken yet.'
        );
    }

    public function testSweepingWithRetentionOffTouchesNothing(): void
    {
        $repository = new FakeSubmissionRepository();
        $repository->seed(10, 'ouder@example.test');

        $this->withSettings([ 'retention_months' => 0, 'end_date' => '2019-08-15' ]);

        $this->assertSame(0, $this->sweeper($repository)->sweep());
        $this->assertSame(10, $repository->remaining(), 'Switching retention off must preserve every registration.');
    }

    public function testSweepingAnonymisesExpiredSubmissions(): void
    {
        $repository = new FakeSubmissionRepository();
        $repository->seed(10, 'ouder@example.test');

        $this->withSettings([ 'retention_months' => 12, 'end_date' => '2019-08-15' ]);

        $this->assertSame(10, $this->sweeper($repository)->sweep());
        $this->assertSame(0, $repository->remaining());
    }

    public function testSweepingIsIdempotent(): void
    {
        $repository = new FakeSubmissionRepository();
        $repository->seed(5, 'ouder@example.test');

        $this->withSettings([ 'retention_months' => 12, 'end_date' => '2019-08-15' ]);
        $sweeper = $this->sweeper($repository);

        $this->assertSame(5, $sweeper->sweep());
        $this->assertSame(0, $sweeper->sweep(), 'A second sweep must find nothing left to do.');
    }

    public function testASweepIsBatchedSoALongHistoryCannotStallCron(): void
    {
        $repository = new FakeSubmissionRepository();
        $repository->seed(250, 'ouder@example.test');

        $this->withSettings([ 'retention_months' => 12, 'end_date' => '2019-08-15' ]);
        $sweeper = $this->sweeper($repository);

        $this->assertSame(100, $sweeper->sweep(), 'One run must cap its work.');
        $this->assertSame(150, $repository->remaining());

        $sweeper->sweep();
        $sweeper->sweep();

        $this->assertSame(0, $repository->remaining(), 'Later runs must pick up the remainder.');
    }
}
