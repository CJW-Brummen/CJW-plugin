<?php

declare(strict_types=1);

/**
 * The session decides two things that turned out to be easy to get wrong: which
 * half-finished registration a request belongs to, and whether the request is
 * starting a new one.
 *
 * The second is not cosmetic. remember() writes the token into $_COOKIE for the
 * remainder of the request, so any caller asking afterwards "did this visitor
 * present a token?" gets yes -- including a first-time visitor. The new-session
 * rate limit was written against that question and was therefore unreachable:
 * it never once ran.
 */
final class SessionTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_COOKIE = [];
        $GLOBALS['cjw_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $GLOBALS['cjw_test_transients'] = [];

        parent::tearDown();
    }

    public function testAVisitorWithNothingToPresentStartsANewSession(): void
    {
        $session = CJW_Wizard_Session::fromRequest('');

        $this->assertTrue($session->isNew());
        $this->assertNotSame('', $session->getToken(), 'A new session still needs a token.');
    }

    public function testAVisitorPresentingACookieResumesThatSession(): void
    {
        (new CJW_Wizard_Session(str_repeat('a', 32)))->save([ 'step' => 2, 'data' => [] ]);
        $_COOKIE[ CJW_Wizard_Session::COOKIE ] = str_repeat('a', 32);

        $session = CJW_Wizard_Session::fromRequest('');

        $this->assertSame(str_repeat('a', 32), $session->getToken());
        $this->assertSame(2, $session->get()['step'], 'The half-finished registration must come back.');
        $this->assertFalse($session->isNew(), 'A returning visitor must not consume the new-session budget.');
    }

    public function testAPostedTokenResumesThatSessionToo(): void
    {
        (new CJW_Wizard_Session(str_repeat('b', 32)))->save([ 'step' => 3, 'data' => [] ]);

        $session = CJW_Wizard_Session::fromRequest(str_repeat('b', 32));

        $this->assertSame(str_repeat('b', 32), $session->getToken());
        $this->assertSame(3, $session->get()['step']);
        $this->assertFalse($session->isNew());
    }

    /**
     * A form submitted from a tab opened before the cookie changed must land in
     * the session it belongs to, not the one the browser is currently holding.
     */
    public function testThePostedTokenWinsOverTheCookie(): void
    {
        $_COOKIE[ CJW_Wizard_Session::COOKIE ] = str_repeat('c', 32);

        $this->assertSame(
            str_repeat('d', 32),
            CJW_Wizard_Session::fromRequest(str_repeat('d', 32))->getToken()
        );
    }

    public function testAGarbageCookieIsTreatedAsNoSessionAtAll(): void
    {
        $_COOKIE[ CJW_Wizard_Session::COOKIE ] = '../../etc/passwd';

        $session = CJW_Wizard_Session::fromRequest('');

        $this->assertTrue($session->isNew(), 'An unusable token must start a fresh session, not resume a mangled one.');
        $this->assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $session->getToken());
    }

    /**
     * A token nobody issued is not a session. If it counted as one, sending a
     * made-up cookie would skip the new-session cap -- and varying it each time
     * would park an unbounded number of transients, which is what the cap exists
     * to prevent.
     */
    public function testAnInventedCookieDoesNotCountAsResumingASession(): void
    {
        $_COOKIE[ CJW_Wizard_Session::COOKIE ] = str_repeat('f', 32);

        $this->assertTrue(
            CJW_Wizard_Session::fromRequest('')->isNew(),
            'A token with no stored state behind it must consume the new-session budget.'
        );
    }

    public function testASessionWithStoredStateCountsAsResuming(): void
    {
        $started = CJW_Wizard_Session::fromRequest('');
        $started->save([ 'step' => 1, 'data' => [] ]);

        $_COOKIE[ CJW_Wizard_Session::COOKIE ] = $started->getToken();

        $this->assertFalse(
            CJW_Wizard_Session::fromRequest('')->isNew(),
            'A genuine returning visitor must not be charged for a new session.'
        );
    }

    public function testTokensAreNotGuessable(): void
    {
        $first = CJW_Wizard_Session::fromRequest('')->getToken();
        $second = CJW_Wizard_Session::fromRequest('')->getToken();

        $this->assertNotSame($first, $second);
        $this->assertSame(32, strlen($first), 'A 32-character hex token is 128 bits of randomness.');
    }

    public function testStateRoundTripsThroughTheSession(): void
    {
        $session = CJW_Wizard_Session::fromRequest('');

        $this->assertSame([ 'step' => 0, 'data' => [] ], $session->get(), 'A new session starts at the first step.');

        $session->save([ 'step' => 2, 'data' => [ 'naam' => 'Anna' ] ]);

        $this->assertSame([ 'step' => 2, 'data' => [ 'naam' => 'Anna' ] ], $session->get());
    }

    public function testResettingClearsTheStoredRegistration(): void
    {
        $session = CJW_Wizard_Session::fromRequest('');
        $session->save([ 'step' => 3, 'data' => [ 'naam' => 'Anna' ] ]);

        $session->reset();

        $this->assertSame([ 'step' => 0, 'data' => [] ], $session->get());
    }

    /**
     * Two sessions must never see each other's data — this is the whole reason
     * the token stopped travelling in the query string.
     */
    public function testOneSessionCannotSeeAnothersData(): void
    {
        $mine = CJW_Wizard_Session::fromRequest('');
        $mine->save([ 'step' => 1, 'data' => [ 'naam' => 'Anna' ] ]);

        $theirs = CJW_Wizard_Session::fromRequest('');

        $this->assertSame([ 'step' => 0, 'data' => [] ], $theirs->get());
    }
}
