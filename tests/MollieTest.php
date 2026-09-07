<?php

declare(strict_types=1);

/**
 * The credential and the conditions under which this site may charge anybody.
 *
 * No HTTP here: what matters first is that the key is recognised, never handed
 * back, and that a site which has no business taking money refuses to.
 */
final class MollieTest extends CJW_TestCase
{
    private const string LIVE = 'live_abcdefghij1234567890ABCDE';

    private const string TEST = 'test_abcdefghij1234567890ABCDE';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [];
        $GLOBALS['cjw_test_environment_type'] = 'production';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_options'], $GLOBALS['cjw_test_environment_type']);

        parent::tearDown();
    }

    public function testASiteWithoutAKeyTakesNoMoney(): void
    {
        $this->assertFalse(CJW_Mollie::isConfigured());
        $this->assertFalse(CJW_Mollie::isUsable());
        $this->assertStringContainsString('testsleutel', CJW_Mollie::unusableReason());
    }

    /**
     * A fresh site is in test mode, which is the harmless half of the switch.
     */
    public function testTestModeIsWhereASiteStarts(): void
    {
        $this->assertSame(CJW_Mollie::MODE_TEST, CJW_Mollie::mode());
    }

    /**
     * The prefix files the key; there is no dropdown to get wrong.
     */
    public function testAKeyIsFiledUnderTheModeItsPrefixNames(): void
    {
        $this->assertSame(CJW_Mollie::MODE_LIVE, CJW_Mollie::storeKey(self::LIVE));
        $this->assertSame(CJW_Mollie::MODE_TEST, CJW_Mollie::storeKey(self::TEST));

        $this->assertTrue(CJW_Mollie::hasKey(CJW_Mollie::MODE_LIVE));
        $this->assertTrue(CJW_Mollie::hasKey(CJW_Mollie::MODE_TEST));
    }

    /**
     * Pasting the live key is not the same as going live. That is the point of
     * the switch: both keys sit there all year and the mode is a decision.
     */
    public function testStoringTheLiveKeyDoesNotGoLiveByItself(): void
    {
        CJW_Mollie::storeKey(self::LIVE);

        $this->assertSame(CJW_Mollie::MODE_TEST, CJW_Mollie::mode());
        $this->assertFalse(CJW_Mollie::isConfigured(), 'there is no test key yet');
    }

    public function testSwitchingChangesWhichKeyIsUsed(): void
    {
        CJW_Mollie::storeKey(self::TEST);
        CJW_Mollie::storeKey(self::LIVE);

        $this->assertTrue(CJW_Mollie::setMode(CJW_Mollie::MODE_LIVE));
        $this->assertSame(CJW_Mollie::MODE_LIVE, CJW_Mollie::mode());
        $this->assertStringStartsWith('live_', CJW_Mollie::maskedKey());

        $this->assertTrue(CJW_Mollie::setMode(CJW_Mollie::MODE_TEST));
        $this->assertStringStartsWith('test_', CJW_Mollie::maskedKey());
    }

    /**
     * A switch that leaves the site unable to take a payment would only be
     * discovered by the first parent who tried.
     */
    public function testSwitchingToAModeWithNoKeyIsRefused(): void
    {
        CJW_Mollie::storeKey(self::TEST);

        $this->assertFalse(CJW_Mollie::setMode(CJW_Mollie::MODE_LIVE));
        $this->assertSame(CJW_Mollie::MODE_TEST, CJW_Mollie::mode());
    }

    public function testAnUnknownModeIsRefused(): void
    {
        CJW_Mollie::storeKey(self::TEST);

        $this->assertFalse(CJW_Mollie::setMode('sandbox'));
    }

    /**
     * Both keys live side by side, which is what makes the yearly rehearsal a
     * switch rather than a copy-paste from the Mollie dashboard.
     */
    public function testBothKeysAreKeptAndShownApart(): void
    {
        CJW_Mollie::storeKey(self::TEST);
        CJW_Mollie::storeKey(self::LIVE);

        $this->assertStringStartsWith('test_', CJW_Mollie::maskedKey(CJW_Mollie::MODE_TEST));
        $this->assertStringStartsWith('live_', CJW_Mollie::maskedKey(CJW_Mollie::MODE_LIVE));
    }

    public function testRemovingTheKeyInUseFallsBackToTheOtherMode(): void
    {
        CJW_Mollie::storeKey(self::TEST);
        CJW_Mollie::storeKey(self::LIVE);
        CJW_Mollie::setMode(CJW_Mollie::MODE_LIVE);

        CJW_Mollie::clearKey(CJW_Mollie::MODE_LIVE);

        $this->assertFalse(CJW_Mollie::hasKey(CJW_Mollie::MODE_LIVE));
        $this->assertSame(CJW_Mollie::MODE_TEST, CJW_Mollie::mode(), 'never left pointing at an empty slot');
        $this->assertTrue(CJW_Mollie::isConfigured());
    }

    /**
     * A key stored before there were two slots keeps working.
     */
    public function testAKeyFromBeforeTheSwitchIsMovedIntoPlace(): void
    {
        $GLOBALS['cjw_test_options'][ CJW_Mollie::OPTION_KEY ] = self::LIVE;

        $this->assertTrue(CJW_Mollie::hasKey(CJW_Mollie::MODE_LIVE));
        $this->assertSame(CJW_Mollie::MODE_LIVE, CJW_Mollie::mode(), 'and stays in the mode it was in');
        $this->assertTrue(CJW_Mollie::isConfigured());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rubbish(): array
    {
        return [
            'a sentence' => [ 'mijn sleutel' ],
            'the right prefix but too short' => [ 'live_abc' ],
            'no prefix' => [ 'abcdefghij1234567890ABCDE' ],
            'the dashboard URL instead of the key' => [ 'https://my.mollie.com/dashboard' ],
        ];
    }

    /**
     * @param string $candidate What somebody pasted.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('rubbish')]
    public function testSomethingThatIsNotAKeyIsRefused(string $candidate): void
    {
        $this->assertFalse(CJW_Mollie::looksLikeKey($candidate));
        $this->assertSame('', CJW_Mollie::storeKey($candidate), 'it belongs in neither slot');
        $this->assertFalse(CJW_Mollie::isConfigured(), 'and nothing is stored');
    }

    /**
     * The panel has to say which key is in use without being a way to read it.
     */
    public function testTheKeyIsOnlyEverShownMasked(): void
    {
        CJW_Mollie::storeKey(self::LIVE);

        $masked = CJW_Mollie::maskedKey(CJW_Mollie::MODE_LIVE);

        $this->assertSame('live_••••BCDE', $masked, 'the prefix and the last four, nothing else');
        $this->assertStringNotContainsString('abcdefghij', $masked);
        $this->assertStringNotContainsString(self::LIVE, $masked);
    }

    public function testAKeyCanBeTakenOutAgain(): void
    {
        CJW_Mollie::storeKey(self::TEST);

        CJW_Mollie::clearKey(CJW_Mollie::MODE_TEST);

        $this->assertFalse(CJW_Mollie::isConfigured());
        $this->assertSame('', CJW_Mollie::maskedKey());
    }

    /**
     * A restored copy carries the live key in its database. Charging real
     * parents from a staging address, with a webhook pointing at nobody, is
     * the one thing it must not do.
     */
    public function testACopyOfTheSiteRefusesToChargeAnybody(): void
    {
        CJW_Mollie::storeKey(self::LIVE);
        CJW_Mollie::setMode(CJW_Mollie::MODE_LIVE);
        $GLOBALS['cjw_test_environment_type'] = 'staging';

        $this->assertTrue(CJW_Mollie::isConfigured());
        $this->assertFalse(CJW_Mollie::isUsable());
        $this->assertStringContainsString('kopie', CJW_Mollie::unusableReason());
    }

    public function testAConfiguredProductionSiteMayCharge(): void
    {
        CJW_Mollie::storeKey(self::LIVE);
        CJW_Mollie::setMode(CJW_Mollie::MODE_LIVE);

        $this->assertTrue(CJW_Mollie::isUsable());
        $this->assertSame('', CJW_Mollie::unusableReason());
    }

    public function testATestKeyIsPerfectlyUsableAndSaysSo(): void
    {
        CJW_Mollie::storeKey(self::TEST);

        $this->assertTrue(CJW_Mollie::isUsable());
        $this->assertSame(CJW_Mollie::MODE_TEST, CJW_Mollie::mode());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function paymentIds(): array
    {
        return [
            'a real one' => [ 'tr_5B8cwPMGnU6qLbRvo7qEZo', true ],
            'empty' => [ '', false ],
            'wrong prefix' => [ 'pay_5B8cwPMGnU6qLbRvo7qEZo', false ],
            'too short' => [ 'tr_abc', false ],
            'path traversal' => [ 'tr_../../payments', false ],
            'with a query string' => [ 'tr_5B8cwPMGnU6?foo=1', false ],
        ];
    }

    /**
     * The id goes into a URL, so it is checked before it gets there.
     *
     * @param string $candidate Id from a webhook body.
     * @param bool   $valid     Whether it should be accepted.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('paymentIds')]
    public function testOnlySomethingShapedLikeAPaymentIdIsAccepted(string $candidate, bool $valid): void
    {
        $this->assertSame($valid, CJW_Mollie::looksLikePaymentId($candidate));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function checkoutUrls(): array
    {
        return [
            'mollie checkout' => [ 'https://www.mollie.com/checkout/select-issuer/ideal/abc', true ],
            'mollie without www' => [ 'https://mollie.com/checkout/abc', true ],
            'http instead of https' => [ 'http://www.mollie.com/checkout/abc', false ],
            'somewhere else entirely' => [ 'https://evil.test/checkout/abc', false ],
            'mollie in the path only' => [ 'https://evil.test/www.mollie.com/checkout', false ],
            'mollie as a prefix of another host' => [ 'https://mollie.com.evil.test/checkout', false ],
            'empty' => [ '', false ],
            'a javascript url' => [ 'javascript:alert(1)', false ],
        ];
    }

    /**
     * The checkout address is handed to wp_redirect(), so it is checked rather
     * than trusted for having come from an API response.
     *
     * @param string $url   Candidate.
     * @param bool   $valid Whether a parent may be sent there.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('checkoutUrls')]
    public function testOnlyMollieIsAPlaceToSendAParentToPay(string $url, bool $valid): void
    {
        $this->assertSame($valid, CJW_Mollie::isCheckoutUrl($url));
    }

    public function testWithoutAKeyNothingIsEvenAttempted(): void
    {
        $answer = CJW_Mollie::getPayment('tr_5B8cwPMGnU6qLbRvo7qEZo');

        $this->assertFalse($answer['ok']);
        $this->assertStringContainsString('geen Mollie-sleutel', (string) $answer['error']);
    }

    public function testAPaymentForNothingIsRefusedBeforeItLeavesTheSite(): void
    {
        CJW_Mollie::storeKey(self::LIVE);

        $answer = CJW_Mollie::createPayment(0, 'Inschrijving', 'https://example.test/r', 'https://example.test/w', [], 'k');

        $this->assertFalse($answer['ok']);
    }
}
