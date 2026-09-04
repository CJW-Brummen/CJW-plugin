<?php

declare(strict_types=1);

/**
 * The ledger's arithmetic, and the way it reads what somebody typed.
 *
 * Money is the one thing on this site a volunteer will check by hand against a
 * bank statement, so the sums and the parsing are worth pinning down without a
 * database in the way.
 */
final class PaymentsTest extends CJW_TestCase
{
    private const int ID = 4321;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_post_meta'] = [];
        $GLOBALS['cjw_test_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_post_meta'], $GLOBALS['cjw_test_options']);

        parent::tearDown();
    }

    /**
     * @param array<string, int> $amount
     */
    private function withAmount(array $amount): void
    {
        update_post_meta(self::ID, CJW_Wizard_Payments::META_AMOUNT, $amount);
    }

    public function testAnAmountIsFrozenInCentsFromTheChildrenAndThePayload(): void
    {
        CJW_Wizard_Payments::freeze(self::ID, [
            [ 'name' => 'Fleur', 'camp' => 'Weekkamp (€ 120)' ],
        ], [ 'donatie' => '10' ]);

        $amount = CJW_Wizard_Payments::amount(self::ID);

        $this->assertSame(12000, $amount['camp']);
        $this->assertSame(1000, $amount['donation']);
        $this->assertSame(13000, $amount['snapshot']);
        $this->assertSame(13000, $amount['due']);
        $this->assertNull($amount['override']);
    }

    public function testNothingIsOwedWhenNothingWasFrozen(): void
    {
        $this->assertSame(0, CJW_Wizard_Payments::amount(self::ID)['due']);
        $this->assertFalse(CJW_Wizard_Payments::isSettled(self::ID));
    }

    public function testPaymentsAddUpAndLeaveTheRest(): void
    {
        $this->withAmount([ 'total' => 24000 ]);

        CJW_Wizard_Payments::add(self::ID, 10000, CJW_Wizard_Payments::METHOD_TRANSFER, '2027-05-01');
        CJW_Wizard_Payments::add(self::ID, 5000, CJW_Wizard_Payments::METHOD_CASH, '2027-07-10', 'aan de poort');

        $this->assertSame(15000, CJW_Wizard_Payments::paid(self::ID));
        $this->assertSame(9000, CJW_Wizard_Payments::outstanding(self::ID));
        $this->assertFalse(CJW_Wizard_Payments::isSettled(self::ID));
    }

    public function testAnInstalmentThatSettlesTheBalanceSettlesIt(): void
    {
        $this->withAmount([ 'total' => 24000 ]);

        CJW_Wizard_Payments::add(self::ID, 12000, CJW_Wizard_Payments::METHOD_TRANSFER);
        CJW_Wizard_Payments::add(self::ID, 12000, CJW_Wizard_Payments::METHOD_TRANSFER);

        $this->assertSame(0, CJW_Wizard_Payments::outstanding(self::ID));
        $this->assertTrue(CJW_Wizard_Payments::isSettled(self::ID));
    }

    public function testOverpaymentIsSettledRatherThanUnpaid(): void
    {
        $this->withAmount([ 'total' => 10000 ]);
        CJW_Wizard_Payments::add(self::ID, 12000, CJW_Wizard_Payments::METHOD_TRANSFER);

        $this->assertSame(-2000, CJW_Wizard_Payments::outstanding(self::ID));
        $this->assertTrue(CJW_Wizard_Payments::isSettled(self::ID));
    }

    /**
     * Money that went back out is a thing that happens, and a ledger that can
     * only add up cannot say so.
     */
    public function testARefundIsRecordedAsANegativeRow(): void
    {
        $this->withAmount([ 'total' => 10000 ]);
        CJW_Wizard_Payments::add(self::ID, 10000, CJW_Wizard_Payments::METHOD_TRANSFER);
        CJW_Wizard_Payments::add(self::ID, -2500, CJW_Wizard_Payments::METHOD_TRANSFER, '', 'te veel betaald, teruggestort');

        $this->assertSame(7500, CJW_Wizard_Payments::paid(self::ID));
        $this->assertSame(2500, CJW_Wizard_Payments::outstanding(self::ID));
    }

    public function testAZeroPaymentIsRefused(): void
    {
        $this->assertFalse(CJW_Wizard_Payments::add(self::ID, 0, CJW_Wizard_Payments::METHOD_CASH));
        $this->assertSame([], CJW_Wizard_Payments::payments(self::ID));
    }

    public function testOneTypedTwiceCanBeTakenBackOut(): void
    {
        $this->withAmount([ 'total' => 10000 ]);
        CJW_Wizard_Payments::add(self::ID, 5000, CJW_Wizard_Payments::METHOD_CASH);
        CJW_Wizard_Payments::add(self::ID, 5000, CJW_Wizard_Payments::METHOD_CASH);

        $rows = CJW_Wizard_Payments::payments(self::ID);

        $this->assertTrue(CJW_Wizard_Payments::remove(self::ID, $rows[0]['id']));
        $this->assertSame(5000, CJW_Wizard_Payments::paid(self::ID));
        $this->assertFalse(CJW_Wizard_Payments::remove(self::ID, 'nietbestaand'));
    }

    public function testPaymentsComeBackOldestFirst(): void
    {
        CJW_Wizard_Payments::add(self::ID, 100, CJW_Wizard_Payments::METHOD_CASH, '2027-08-01');
        CJW_Wizard_Payments::add(self::ID, 200, CJW_Wizard_Payments::METHOD_CASH, '2027-03-01');

        $dates = array_column(CJW_Wizard_Payments::payments(self::ID), 'date');

        $this->assertSame([ '2027-03-01', '2027-08-01' ], $dates);
    }

    public function testAnUnknownMethodIsRecordedAsSomethingElse(): void
    {
        CJW_Wizard_Payments::add(self::ID, 100, 'bitcoin');

        $this->assertSame(CJW_Wizard_Payments::METHOD_OTHER, CJW_Wizard_Payments::payments(self::ID)[0]['method']);
    }

    /**
     * A reduced rate under the minimabeleid is the case this exists for: the
     * form worked out one number and the camp is asking for another.
     */
    public function testACorrectedAmountWinsAndKeepsTheOriginalBeside(): void
    {
        $this->withAmount([ 'total' => 24000, 'camp' => 24000, 'donation' => 0 ]);

        CJW_Wizard_Payments::setDue(self::ID, 5000);

        $amount = CJW_Wizard_Payments::amount(self::ID);

        $this->assertSame(5000, $amount['due']);
        $this->assertSame(24000, $amount['snapshot'], 'the form\'s own figure is not overwritten');
        $this->assertSame(5000, $amount['override']);
    }

    public function testDroppingTheCorrectionGoesBackToTheFormsFigure(): void
    {
        $this->withAmount([ 'total' => 24000 ]);
        CJW_Wizard_Payments::setDue(self::ID, 5000);

        CJW_Wizard_Payments::setDue(self::ID, null);

        $this->assertSame(24000, CJW_Wizard_Payments::amount(self::ID)['due']);
        $this->assertNull(CJW_Wizard_Payments::amount(self::ID)['override']);
    }

    public function testSettlingTheBalanceImpliesPaid(): void
    {
        $this->withAmount([ 'total' => 10000 ]);
        CJW_Wizard_Payments::add(self::ID, 10000, CJW_Wizard_Payments::METHOD_TRANSFER);

        $this->assertSame(
            CJW_Wizard_Submission_Status::PAID,
            CJW_Wizard_Payments::statusFor(self::ID, CJW_Wizard_Submission_Status::CONFIRMED)
        );
    }

    public function testTakingAPaymentBackOutTakesTheStatusWithIt(): void
    {
        $this->withAmount([ 'total' => 10000 ]);

        $this->assertSame(
            CJW_Wizard_Submission_Status::CONFIRMED,
            CJW_Wizard_Payments::statusFor(self::ID, CJW_Wizard_Submission_Status::PAID)
        );
    }

    /**
     * Somebody decided to cancel. A payment arriving after that is a refund to
     * arrange, not an undo.
     */
    public function testACancelledRegistrationIsLeftAlone(): void
    {
        $this->withAmount([ 'total' => 10000 ]);
        CJW_Wizard_Payments::add(self::ID, 10000, CJW_Wizard_Payments::METHOD_TRANSFER);

        $this->assertSame(
            CJW_Wizard_Submission_Status::CANCELLED,
            CJW_Wizard_Payments::statusFor(self::ID, CJW_Wizard_Submission_Status::CANCELLED)
        );
    }

    /**
     * A form with no prices in it must not mark every arrival as paid.
     */
    public function testAFormWithNothingToPayDoesNotMoveTheStatus(): void
    {
        $this->assertSame(
            CJW_Wizard_Submission_Status::NEW,
            CJW_Wizard_Payments::statusFor(self::ID, CJW_Wizard_Submission_Status::NEW)
        );
    }

    /**
     * @return array<string, array{0: string, 1: ?int}>
     */
    public static function amounts(): array
    {
        return [
            'whole euros' => [ '72', 7200 ],
            'dutch decimal' => [ '72,50', 7250 ],
            'english decimal' => [ '72.50', 7250 ],
            'with a currency sign' => [ '€ 72,50', 7250 ],
            'with a thousands dot' => [ '1.234,50', 123450 ],
            'with a thousands comma' => [ '1,234.50', 123450 ],
            'thousands only' => [ '1.234', 123400 ],
            'spaces everywhere' => [ '  120 ', 12000 ],
            'a refund' => [ '-25', -2500 ],
            'nothing at all' => [ '', null ],
            'not a number' => [ 'contant', null ],
        ];
    }

    /**
     * @param string   $raw      What somebody typed.
     * @param int|null $expected Cents.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('amounts')]
    public function testAmountsAreReadTheWayPeopleTypeThem(string $raw, ?int $expected): void
    {
        $this->assertSame($expected, CJW_Wizard_Payments::parseAmount($raw));
    }

    public function testAmountsAreShownTheWayDutchWritesThem(): void
    {
        $this->assertSame('€ 72,50', CJW_Wizard_Payments::formatAmount(7250));
        $this->assertSame('€ 120,00', CJW_Wizard_Payments::formatAmount(12000));
        $this->assertSame('-€ 25,00', CJW_Wizard_Payments::formatAmount(-2500));
    }

    /**
     * The note is the one part of a payment somebody wrote by hand, so it is
     * the one part that can name a person.
     */
    public function testAnonymisingKeepsTheMoneyAndDropsTheNote(): void
    {
        $this->withAmount([ 'total' => 10000 ]);
        CJW_Wizard_Payments::add(self::ID, 10000, CJW_Wizard_Payments::METHOD_CASH, '2027-07-10', 'contant van de buurvrouw');

        CJW_Wizard_Payments::anonymise(self::ID);

        $rows = CJW_Wizard_Payments::payments(self::ID);

        $this->assertSame(10000, $rows[0]['cents']);
        $this->assertSame('2027-07-10', $rows[0]['date']);
        $this->assertSame(CJW_Wizard_Payments::METHOD_CASH, $rows[0]['method']);
        $this->assertSame('', $rows[0]['note']);
        $this->assertSame(10000, CJW_Wizard_Payments::paid(self::ID), 'the books still add up');
    }
}
