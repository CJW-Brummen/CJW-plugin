<?php

declare(strict_types=1);

/**
 * A crash that reports itself.
 *
 * Everything else this plugin reports goes out by mail from code that has just
 * stopped running, which is precisely the case that needs reporting. So the
 * crash is recorded where an outside monitor can read it, and mailed once per
 * place in the code rather than once per request.
 */
final class FatalErrorWatchTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [];
        $GLOBALS['cjw_test_mails'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_options'], $GLOBALS['cjw_test_mails']);

        parent::tearDown();
    }

    public function testNothingIsRecordedUntilSomethingCrashes(): void
    {
        $this->assertNull(CJW_Fatal_Error_Watch::last());
    }

    public function testACrashIsRecordedWithWhereItHappened(): void
    {
        CJW_Fatal_Error_Watch::report('Uncaught TypeError: bad', ABSPATH . 'wp-content/plugins/cjw/inc/x.php', 12);

        $last = CJW_Fatal_Error_Watch::last();

        $this->assertIsArray($last);
        $this->assertSame('Uncaught TypeError: bad', $last['message']);
        $this->assertSame('wp-content/plugins/cjw/inc/x.php', $last['file'], 'a path inside the installation, not the server layout');
        $this->assertSame(12, $last['line']);
        $this->assertGreaterThan(0, $last['time']);
    }

    /**
     * A page that crashes on every request must produce one alert, not one per
     * visitor.
     */
    public function testTheSamePlaceReportsOnce(): void
    {
        $file = ABSPATH . 'wp-content/plugins/cjw/inc/x.php';

        CJW_Fatal_Error_Watch::report('Boom', $file, 3);
        $raised = CJW_Alerts::all();

        CJW_Fatal_Error_Watch::report('Boom', $file, 3);

        $this->assertSame($raised, CJW_Alerts::all(), 'no second alert for the same place');
        $this->assertCount(1, CJW_Alerts::all());
    }

    public function testADifferentPlaceReportsAgain(): void
    {
        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'a.php', 3);
        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'b.php', 3);

        $this->assertCount(2, CJW_Alerts::all());
    }

    public function testClearingForgetsTheCrashAndItsAlert(): void
    {
        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'a.php', 3);

        CJW_Fatal_Error_Watch::clear();

        $this->assertNull(CJW_Fatal_Error_Watch::last());
        $this->assertSame([], CJW_Alerts::all(), 'so the next crash in the same place is reported again');
    }

    public function testTheAlertSaysWhatCrashedAndWhere(): void
    {
        CJW_Fatal_Error_Watch::report('Uncaught Error: kaput', ABSPATH . 'wp-content/plugins/cjw/boom.php', 99);

        $subjects = CJW_Alerts::subjects();

        $this->assertSame([ 'De website is gecrasht' ], array_values($subjects));
    }

    /**
     * PHP messages can run to kilobytes, and this one ends up in an option, a
     * mail and a JSON payload.
     */
    public function testAVeryLongMessageIsShortened(): void
    {
        CJW_Fatal_Error_Watch::report(str_repeat('x', 900), ABSPATH . 'a.php', 1);

        $this->assertSame(500, mb_strlen((string) CJW_Fatal_Error_Watch::last()['message']));
    }

    public function testWhitespaceInTheMessageIsCollapsed(): void
    {
        CJW_Fatal_Error_Watch::report("Boom\n  in    stack", ABSPATH . 'a.php', 1);

        $this->assertSame('Boom in stack', CJW_Fatal_Error_Watch::last()['message']);
    }
}
