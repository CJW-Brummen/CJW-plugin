<?php

declare(strict_types=1);

/**
 * Faults are reported once and stay reported until they recover. The
 * alternative -- one mail per occurrence -- is how a channel gets filtered.
 */
final class AlertsTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [];
        $GLOBALS['cjw_test_users'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_options'], $GLOBALS['cjw_test_users']);

        parent::tearDown();
    }

    public function testAFaultIsRememberedOnceRaised(): void
    {
        $this->assertFalse(CJW_Alerts::isRaised('boiler'));

        CJW_Alerts::raise('boiler', 'Kapot', '<p>Stuk.</p>');

        $this->assertTrue(CJW_Alerts::isRaised('boiler'));
        $this->assertGreaterThan(0, CJW_Alerts::raisedAt('boiler'));
    }

    public function testRaisingTheSameFaultTwiceReportsNothingTheSecondTime(): void
    {
        CJW_Alerts::raise('boiler', 'Kapot', '<p>Stuk.</p>');

        $this->assertFalse(CJW_Alerts::raise('boiler', 'Kapot', '<p>Stuk.</p>'));
    }

    public function testClearingLetsTheNextOccurrenceBeHeard(): void
    {
        CJW_Alerts::raise('boiler', 'Kapot', '<p>Stuk.</p>');
        CJW_Alerts::clear('boiler');

        $this->assertFalse(CJW_Alerts::isRaised('boiler'));

        CJW_Alerts::raise('boiler', 'Kapot', '<p>Alweer stuk.</p>');

        $this->assertTrue(CJW_Alerts::isRaised('boiler'));
    }

    public function testFaultsAreIndependentOfEachOther(): void
    {
        CJW_Alerts::raise('boiler', 'Kapot', '<p>Stuk.</p>');

        $this->assertFalse(CJW_Alerts::isRaised('kraan'));
    }
}
