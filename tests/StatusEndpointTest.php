<?php

declare(strict_types=1);

/**
 * The answer an outside monitor gets.
 *
 * Two things matter and neither needs a web server: what the payload says, and
 * how little of it a caller without the token is told. Whether WordPress
 * actually routes it is the integration suite's question.
 */
final class StatusEndpointTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [];
        $GLOBALS['cjw_test_transients'] = [];
        CJW_Cron_Heartbeat::reset();
        CJW_Status_Endpoint::flush();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_options'], $GLOBALS['cjw_test_transients'], $GLOBALS['cjw_test_summer_camp']);
        CJW_Cron_Heartbeat::reset();
        CJW_Status_Endpoint::flush();

        parent::tearDown();
    }

    private function withToken(string $token): void
    {
        $GLOBALS['cjw_test_summer_camp'] = new class ($token) {
            public function __construct(private string $token) {}

            public function getStatusToken(): string
            {
                return $this->token;
            }
        };
    }

    public function testAHealthySiteAnswersOkAndNothingElse(): void
    {
        $payload = CJW_Status_Endpoint::payload();

        $this->assertSame([ 'status', 'time' ], array_keys($payload));
        $this->assertSame('ok', $payload['status']);
        $this->assertNotSame('', $payload['time']);
    }

    /**
     * The whole point of the route: a fault nobody can be mailed about is
     * visible from outside. A crash is the one worth waking somebody for.
     */
    public function testACrashIsCritical(): void
    {
        CJW_Fatal_Error_Watch::report('Call to a member function on null', '/var/www/html/wp-content/plugins/cjw/x.php', 42);

        $this->assertSame('critical', CJW_Status_Endpoint::payload()['status']);
    }

    public function testAStaleCronIsOnlyAWarning(): void
    {
        CJW_Cron_Heartbeat::watch('cjw_test_job', 'Testtaak', DAY_IN_SECONDS);
        $GLOBALS['cjw_test_options'][ CJW_Cron_Heartbeat::OPTION_KEY ] = [
            'cjw_test_job' => time() - (5 * DAY_IN_SECONDS),
        ];

        $payload = CJW_Status_Endpoint::payload(true);

        $this->assertSame('warning', $payload['status']);
        $this->assertFalse($payload['checks']['cron']['ok']);
        $this->assertSame('warning', $payload['checks']['cron']['level']);
        $this->assertSame('Testtaak', $payload['checks']['cron']['stale'][0]['job']);
        $this->assertSame(5, $payload['checks']['cron']['stale'][0]['days']);
    }

    public function testARaisedAlertIsAWarningAndIsNamed(): void
    {
        CJW_Alerts::raise('mail_kaput', 'Bevestigingsmail niet verstuurd', '<p>x</p>');

        $payload = CJW_Status_Endpoint::payload(true);

        $this->assertSame('warning', $payload['status']);
        $this->assertSame([ 'Bevestigingsmail niet verstuurd' ], $payload['checks']['alerts']['raised']);
        $this->assertContains('Bevestigingsmail niet verstuurd', CJW_Status_Endpoint::problems());
    }

    /**
     * A stranger learns that something is wrong, and never what.
     */
    public function testWithoutTheTokenTheDetailIsWithheld(): void
    {
        CJW_Fatal_Error_Watch::report('Boom', '/var/www/html/wp-content/plugins/cjw/x.php', 1);

        $payload = CJW_Status_Endpoint::payload();

        $this->assertSame('critical', $payload['status']);
        $this->assertSame([ 'status', 'time' ], array_keys($payload));
        $this->assertArrayNotHasKey('checks', $payload);
        $this->assertStringNotContainsString('Boom', (string) wp_json_encode($payload));
    }

    public function testWithTheTokenTheDetailNamesTheFault(): void
    {
        CJW_Fatal_Error_Watch::report('Boom', '/var/www/html/wp-content/plugins/cjw/x.php', 7);

        $payload = CJW_Status_Endpoint::payload(true);

        $this->assertFalse($payload['checks']['fatal']['ok']);
        $this->assertSame('Boom', $payload['checks']['fatal']['message']);
        $this->assertSame(7, $payload['checks']['fatal']['line']);
    }

    /**
     * A site with a late cron and a crash is not "a bit unwell": the verdict
     * is the worst thing any check has to say.
     */
    public function testTheWorstCheckDecidesTheVerdict(): void
    {
        CJW_Cron_Heartbeat::watch('cjw_test_job', 'Testtaak', DAY_IN_SECONDS);
        $GLOBALS['cjw_test_options'][ CJW_Cron_Heartbeat::OPTION_KEY ] = [
            'cjw_test_job' => time() - (5 * DAY_IN_SECONDS),
        ];

        $this->assertSame('warning', CJW_Status_Endpoint::payload()['status']);

        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'a.php', 1);
        CJW_Status_Endpoint::flush();

        $this->assertSame('critical', CJW_Status_Endpoint::payload()['status']);
    }

    /**
     * A public route polled every minute should not redo its work every time.
     */
    public function testTheAnswerIsCachedBrieflyAndCanBeAskedAfresh(): void
    {
        $this->assertSame('ok', CJW_Status_Endpoint::payload()['status']);

        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'a.php', 1);

        $this->assertSame('ok', CJW_Status_Endpoint::payload()['status'], 'still the cached answer');
        $this->assertSame('critical', CJW_Status_Endpoint::payload(false, true)['status'], 'asked afresh');
    }

    public function testAProblemIsPhrasedForAPersonRatherThanAsAKey(): void
    {
        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'a.php', 1);

        $this->assertSame([ 'De website is vastgelopen.' ], CJW_Status_Endpoint::problems());
    }

    /**
     * Recording a crash also raises an alert, and the crash check already says
     * so with the file and the line. Reported twice, it reads as two faults.
     */
    public function testACrashIsReportedOnceRatherThanAlsoAsItsOwnAlert(): void
    {
        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'a.php', 1);

        $payload = CJW_Status_Endpoint::payload(true);

        $this->assertCount(1, CJW_Status_Endpoint::problems());
        $this->assertTrue($payload['checks']['alerts']['ok']);
        $this->assertFalse($payload['checks']['fatal']['ok']);
    }

    public function testASilentCronIsReportedOnceRatherThanAlsoAsItsOwnAlert(): void
    {
        CJW_Cron_Heartbeat::watch('cjw_test_job', 'Testtaak', DAY_IN_SECONDS);
        $GLOBALS['cjw_test_options'][ CJW_Cron_Heartbeat::OPTION_KEY ] = [
            'cjw_test_job' => time() - (5 * DAY_IN_SECONDS),
        ];
        CJW_Cron_Heartbeat::reportStale();
        CJW_Status_Endpoint::flush();

        $payload = CJW_Status_Endpoint::payload(true);

        $this->assertCount(1, CJW_Status_Endpoint::problems());
        $this->assertTrue($payload['checks']['alerts']['ok']);
        $this->assertFalse($payload['checks']['cron']['ok']);
    }

    public function testAHealthySiteHasNothingToReport(): void
    {
        $this->assertSame([], CJW_Status_Endpoint::problems());
    }

    public function testOnlyTheStoredTokenUnlocksTheDetail(): void
    {
        $this->withToken('abc123');

        $this->assertTrue(CJW_Status_Endpoint::tokenMatches('abc123'));
        $this->assertFalse(CJW_Status_Endpoint::tokenMatches('abc124'));
        $this->assertFalse(CJW_Status_Endpoint::tokenMatches(''));
    }

    /**
     * A site with no token generated yet must not accept an empty guess as a
     * match, which is what comparing two empty strings would do.
     */
    public function testWithoutAStoredTokenNothingMatches(): void
    {
        $this->withToken('');

        $this->assertFalse(CJW_Status_Endpoint::tokenMatches(''));
        $this->assertFalse(CJW_Status_Endpoint::tokenMatches('anything'));
    }
}
