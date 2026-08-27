<?php

declare(strict_types=1);

/**
 * The rate limits and the wizard session, against real transients.
 *
 * Both are built on set_transient()/get_transient(), which the fast suite
 * replaces with an array. That array cannot expire, cannot be shared between
 * requests and cannot be evicted by an object cache -- which is most of what a
 * transient is. The session cap in particular was dead code for a while
 * because a cookie written during the request made every later check see a
 * token that was not really there.
 */
final class GuardAndSessionTest extends CJW_IntegrationTestCase
{
    public function testTheCompletionCapEventuallyBlocks(): void
    {
        $guard = new CJW_Wizard_Guard();
        $limit = $guard->completionLimit();

        for ($i = 0; $i < $limit; $i++) {
            $this->assertTrue($guard->mayComplete(), 'completion ' . ($i + 1));
        }

        // Generous on purpose -- well above any real family -- but finite.
        $this->assertFalse($guard->mayComplete());
    }

    public function testTheSessionCapEventuallyBlocks(): void
    {
        $guard = new CJW_Wizard_Guard();
        $limit = $guard->sessionLimit();

        for ($i = 0; $i < $limit; $i++) {
            $this->assertTrue($guard->mayStartSession(), 'session ' . ($i + 1));
        }

        $this->assertFalse($guard->mayStartSession());
    }

    public function testTheTwoCapsAreCountedSeparately(): void
    {
        $guard = new CJW_Wizard_Guard();

        for ($i = 0; $i < $guard->completionLimit(); $i++) {
            $guard->mayComplete();
        }

        $this->assertFalse($guard->mayComplete());

        // Exhausting one must not close the other, or a single family
        // finishing several registrations would lock the form for a school.
        $this->assertTrue($guard->mayStartSession());
    }

    public function testTheVisitorFingerprintIsHashedAndNotAnAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        $fingerprint = (new CJW_Wizard_Guard())->fingerprint();

        // Salted with wp_salt(), which only exists once WordPress is loaded.
        $this->assertSame(32, strlen($fingerprint));
        $this->assertStringNotContainsString('203.0.113', $fingerprint);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fingerprint);
    }

    public function testTwoVisitorsGetSeparateBudgets(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $first = new CJW_Wizard_Guard();

        for ($i = 0; $i < $first->completionLimit(); $i++) {
            $first->mayComplete();
        }

        $this->assertFalse($first->mayComplete());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.2';

        $this->assertTrue((new CJW_Wizard_Guard())->mayComplete());
    }

    public function testAFreshVisitorHasNoSessionToResume(): void
    {
        $this->assertTrue(CJW_Wizard_Session::fromRequest('')->isNew());
    }

    public function testAForgedCookieDoesNotCountAsAnExistingSession(): void
    {
        // The bug this closes: isNew() used to ask "was a token presented?",
        // so anyone could mint a token and walk past the new-session cap.
        $session = CJW_Wizard_Session::fromRequest('a-token-nobody-issued');

        $this->assertTrue($session->isNew());
    }

    public function testStateSurvivesBeingStoredAndReadBack(): void
    {
        $session = CJW_Wizard_Session::fromRequest('');
        $session->save([ 'step' => 2, 'data' => [ 'voornaam' => 'Fleur', 'kinderen' => [ [ 'leeftijd' => '9' ] ] ] ]);

        wp_cache_flush();

        $resumed = CJW_Wizard_Session::fromRequest($session->getToken());

        $this->assertFalse($resumed->isNew());
        $this->assertSame(2, $resumed->get()['step']);
        $this->assertSame('Fleur', $resumed->get()['data']['voornaam']);
        $this->assertSame('9', $resumed->get()['data']['kinderen'][0]['leeftijd']);
    }

    public function testResettingLeavesNothingToResume(): void
    {
        $session = CJW_Wizard_Session::fromRequest('');
        $session->save([ 'step' => 3, 'data' => [ 'x' => 'y' ] ]);
        $session->reset();

        wp_cache_flush();

        $this->assertTrue(CJW_Wizard_Session::fromRequest($session->getToken())->isNew());
    }

    public function testAnInstantSubmissionIsRejected(): void
    {
        $guard = new CJW_Wizard_Guard();

        // The trap fields carry a signed render time. A form completed faster
        // than a person could read it did not have a person behind it.
        preg_match('/name="' . preg_quote(CJW_Wizard_Guard::TIMESTAMP_FIELD, '/') . '" value="([^"]+)"/', $guard->trapFields(), $m);

        $this->assertNotEmpty($m, 'the timestamp field should be in the trap markup');
        $this->assertNotSame('', $guard->rejectionReason([
            CJW_Wizard_Guard::TIMESTAMP_FIELD => $m[1],
            CJW_Wizard_Guard::HONEYPOT_FIELD => '',
        ]));
    }

    public function testAFilledHoneypotIsRejected(): void
    {
        $guard = new CJW_Wizard_Guard();

        $this->assertNotSame('', $guard->rejectionReason([
            CJW_Wizard_Guard::HONEYPOT_FIELD => 'http://spam.example',
        ]));
    }
}
