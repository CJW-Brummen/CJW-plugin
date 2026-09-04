<?php

declare(strict_types=1);

/**
 * The sweep that erases children's data runs on WP-Cron, which fires only when
 * somebody visits the site. These tests pin the one thing that matters: going
 * quiet has to become visible.
 */
final class CronHeartbeatTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [];
        $GLOBALS['cjw_test_users'] = [];
        CJW_Cron_Heartbeat::reset();
    }

    protected function tearDown(): void
    {
        CJW_Cron_Heartbeat::reset();
        unset($GLOBALS['cjw_test_options'], $GLOBALS['cjw_test_users']);

        parent::tearDown();
    }

    public function testAJobThatJustRanIsNotStale(): void
    {
        CJW_Cron_Heartbeat::watch('cjw_test_job', 'Testtaak', DAY_IN_SECONDS);
        CJW_Cron_Heartbeat::record('cjw_test_job');

        $this->assertSame([], CJW_Cron_Heartbeat::stale());
    }

    public function testAJobThatHasBeenQuietTooLongIsStale(): void
    {
        CJW_Cron_Heartbeat::watch('cjw_test_job', 'Testtaak', DAY_IN_SECONDS);

        $GLOBALS['cjw_test_options'][CJW_Cron_Heartbeat::OPTION_KEY] = [
            'cjw_test_job' => time() - (3 * DAY_IN_SECONDS),
        ];

        $stale = CJW_Cron_Heartbeat::stale();

        $this->assertArrayHasKey('cjw_test_job', $stale);
        $this->assertSame('Testtaak', $stale['cjw_test_job']['label']);
    }

    /**
     * A fresh install has never run anything. It must not shout about a sweep
     * that is not due yet.
     */
    public function testAJobThatHasNeverRunIsJudgedFromWhenItWasFirstWatched(): void
    {
        CJW_Cron_Heartbeat::watch('cjw_test_job', 'Testtaak', DAY_IN_SECONDS);

        $this->assertSame([], CJW_Cron_Heartbeat::stale());
    }

    public function testRunningAgainLiftsTheAlert(): void
    {
        CJW_Cron_Heartbeat::watch('cjw_test_job', 'Testtaak', DAY_IN_SECONDS);

        $GLOBALS['cjw_test_options'][CJW_Cron_Heartbeat::OPTION_KEY] = [
            'cjw_test_job' => time() - (3 * DAY_IN_SECONDS),
        ];

        CJW_Cron_Heartbeat::reportStale();
        $this->assertTrue(CJW_Alerts::isRaised('cron_silent:cjw_test_job'));

        CJW_Cron_Heartbeat::record('cjw_test_job');

        $this->assertFalse(CJW_Alerts::isRaised('cron_silent:cjw_test_job'));
        $this->assertSame([], CJW_Cron_Heartbeat::stale());
    }

    /**
     * Twenty-one identical mails about the same dead cron is how an alert
     * channel teaches people to ignore it.
     */
    public function testTheSameSilenceIsReportedOnlyOnce(): void
    {
        CJW_Cron_Heartbeat::watch('cjw_test_job', 'Testtaak', DAY_IN_SECONDS);

        $GLOBALS['cjw_test_options'][CJW_Cron_Heartbeat::OPTION_KEY] = [
            'cjw_test_job' => time() - (3 * DAY_IN_SECONDS),
        ];

        $this->assertSame(1, CJW_Cron_Heartbeat::reportStale(), 'the first silence is reported');
        $this->assertSame(0, CJW_Cron_Heartbeat::reportStale(), 'the same silence is not reported again');
        $this->assertTrue(CJW_Alerts::isRaised('cron_silent:cjw_test_job'));
    }
}
