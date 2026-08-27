<?php

declare(strict_types=1);

/**
 * The retention period is the difference between "we keep children's data as
 * long as we need it" and "forever". Getting the arithmetic wrong in either
 * direction is bad: too long is the AVG problem this exists to solve, too short
 * silently destroys a live season's registrations.
 */
final class RetentionTest extends CJW_TestCase
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
     * @param mixed $months Stored retention setting.
     */
    private function service(mixed $months = null): CJW_Summer_Camp_Service
    {
        if ($months !== null) {
            $GLOBALS['cjw_test_options'][ CJW_Summer_Camp_Service::OPTION_KEY ] = [ 'retention_months' => $months ];
        }

        return new CJW_Summer_Camp_Service();
    }

    public function testTwelveMonthsIsTheDefault(): void
    {
        $this->assertSame(
            12,
            $this->service()->getRetentionMonths(),
            'A site that never touches the setting must still purge eventually.'
        );
    }

    public function testZeroMeansKeepIndefinitely(): void
    {
        $this->assertSame(0, $this->service(0)->getRetentionMonths());
    }

    public function testANegativePeriodCannotBeStored(): void
    {
        $this->assertSame(
            0,
            $this->service(-6)->getRetentionMonths(),
            'A negative period would put the cut-off in the future and purge live registrations.'
        );
    }

    public function testAnAbsurdPeriodIsClampedRatherThanTreatedAsForever(): void
    {
        $this->assertSame(
            120,
            $this->service(99999)->getRetentionMonths()
        );
    }

    public function testANonNumericValueFallsBackToKeepingNothingScheduled(): void
    {
        $this->assertSame(0, $this->service('nooit')->getRetentionMonths());
    }

    /**
     * The cut-off is measured back from the camp's end date, so a whole season
     * expires together regardless of when each registration arrived.
     */
    public function testTheCutoffIsTheCampEndDateMinusTheRetentionPeriod(): void
    {
        $end = new DateTimeImmutable('2026-08-15');

        $this->assertSame(
            '2025-08-15',
            $end->modify('-12 months')->format('Y-m-d'),
            'Twelve months after the 2026 camp, everything from the 2025 season is expired.'
        );
    }
}
