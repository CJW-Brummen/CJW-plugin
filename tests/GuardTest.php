<?php

declare(strict_types=1);

/**
 * The guard is the only thing standing between a script and the registration
 * wizard, which writes posts and sends mail without any authentication.
 */
final class GuardTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_transients'] = [];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    }

    protected function tearDown(): void
    {
        $GLOBALS['cjw_test_transients'] = [];
        unset($_SERVER['REMOTE_ADDR']);

        parent::tearDown();
    }

    /**
     * Extracts the signed stamp the guard just rendered, so a test can submit
     * it back the way a browser would.
     */
    private function stampFrom(CJW_Wizard_Guard $guard): string
    {
        preg_match(
            '/name="' . preg_quote(CJW_Wizard_Guard::TIMESTAMP_FIELD, '/') . '" value="([^"]+)"/',
            $guard->trapFields(),
            $matches
        );

        return $matches[1] ?? '';
    }

    public function testHoneypotFieldIsRenderedEmptyAndHiddenFromAssistiveTech(): void
    {
        $markup = (new CJW_Wizard_Guard())->trapFields();

        $this->assertStringContainsString('name="' . CJW_Wizard_Guard::HONEYPOT_FIELD . '"', $markup);
        $this->assertStringContainsString('aria-hidden="true"', $markup);
        $this->assertStringContainsString('tabindex="-1"', $markup);
        $this->assertStringContainsString('autocomplete="off"', $markup);
    }

    public function testAFilledHoneypotIsRejected(): void
    {
        $guard = new CJW_Wizard_Guard();

        $this->assertSame('honeypot', $guard->rejectionReason([
            CJW_Wizard_Guard::HONEYPOT_FIELD => 'http://spam.test',
            CJW_Wizard_Guard::TIMESTAMP_FIELD => $this->stampFrom($guard),
        ]));
    }

    public function testASubmissionArrivingInstantlyIsRejected(): void
    {
        $guard = new CJW_Wizard_Guard();

        // The stamp is issued now, so no time has elapsed — which is what a
        // script posting straight at the endpoint looks like.
        $this->assertSame('too_fast', $guard->rejectionReason([
            CJW_Wizard_Guard::TIMESTAMP_FIELD => $this->stampFrom($guard),
        ]));
    }

    public function testAMissingStampIsRejected(): void
    {
        $this->assertSame('too_fast', (new CJW_Wizard_Guard())->rejectionReason([]));
    }

    public function testAForgedStampIsRejected(): void
    {
        $forged = (string) (time() - 3600) . '.' . str_repeat('a', 64);

        $this->assertSame(
            'too_fast',
            (new CJW_Wizard_Guard())->rejectionReason([ CJW_Wizard_Guard::TIMESTAMP_FIELD => $forged ]),
            'A stamp that was not signed by this site must not be trusted.'
        );
    }

    public function testAGenuineStampOldEnoughIsAccepted(): void
    {
        $guard = new CJW_Wizard_Guard();
        $issued = (string) (time() - 30);
        $stamp = $issued . '.' . hash_hmac('sha256', $issued, wp_salt('nonce'));

        $this->assertSame('', $guard->rejectionReason([ CJW_Wizard_Guard::TIMESTAMP_FIELD => $stamp ]));
    }

    public function testCompletionsAreCappedPerHour(): void
    {
        $guard = new CJW_Wizard_Guard();
        $limit = $guard->completionLimit();

        for ($i = 0; $i < $limit; $i++) {
            $this->assertTrue($guard->mayComplete(), "Completion {$i} is within the limit and must be allowed.");
        }

        $this->assertFalse($guard->mayComplete(), 'The completion after the limit must be refused.');
    }

    public function testNewSessionsAreCappedSeparatelyFromCompletions(): void
    {
        $guard = new CJW_Wizard_Guard();

        for ($i = 0; $i < $guard->sessionLimit(); $i++) {
            $guard->mayStartSession();
        }

        $this->assertFalse($guard->mayStartSession(), 'Session starts must be capped.');
        $this->assertTrue($guard->mayComplete(), 'Exhausting session starts must not block completing a registration.');
    }

    public function testADifferentVisitorHasTheirOwnBudget(): void
    {
        $guard = new CJW_Wizard_Guard();

        for ($i = 0; $i < $guard->completionLimit(); $i++) {
            $guard->mayComplete();
        }
        $this->assertFalse($guard->mayComplete());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        $this->assertTrue(
            (new CJW_Wizard_Guard())->mayComplete(),
            'One visitor hitting the cap must not lock out everyone else.'
        );
    }

    public function testTheStoredKeyNeverContainsTheAddress(): void
    {
        $guard = new CJW_Wizard_Guard();
        $guard->mayComplete();

        $stored = wp_json_encode([
            array_keys($GLOBALS['cjw_test_transients']),
            array_values($GLOBALS['cjw_test_transients']),
        ]);

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('198.51.100.7', $stored, 'The visitor IP must never be persisted.');
        $this->assertSame(32, strlen($guard->fingerprint()));
    }

    public function testTrafficSampleNoticesMoreThanOneVisitor(): void
    {
        (new CJW_Wizard_Guard())->noteTraffic();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        (new CJW_Wizard_Guard())->noteTraffic();

        $sample = CJW_Wizard_Guard::trafficSample();

        $this->assertSame(2, $sample['count']);
        $this->assertSame(2, $sample['distinct']);
        $this->assertFalse($sample['conclusive'], 'Two observations are far too few to conclude anything.');
    }

    public function testTrafficSampleBecomesConclusiveAndCanShowASingleVisitor(): void
    {
        for ($i = 0; $i < 25; $i++) {
            (new CJW_Wizard_Guard())->noteTraffic();
        }

        $sample = CJW_Wizard_Guard::trafficSample();

        $this->assertTrue($sample['conclusive']);
        $this->assertSame(1, $sample['distinct'], 'Every request came from one address — the proxy symptom.');
    }
}
