<?php

declare(strict_types=1);

/**
 * The figure the detail screen shows an organiser and the figure the
 * confirmation quotes a parent are now the same calculation. These pin it,
 * because a disagreement between those two is discovered by a family who paid
 * the wrong amount.
 */
final class AmountTest extends CJW_TestCase
{
    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function labelProvider(): array
    {
        return [
            'label with a price' => [ 'Weekkamp (10 t/m 17 jaar) - €101', 101 ],
            'no space after the sign' => [ 'Weekendkamp - €44', 44 ],
            'space after the sign' => [ 'Weekkamp - € 99', 99 ],
            'decimal comma' => [ 'Weekkamp - €99,50', 100 ],
            'decimal point' => [ 'Weekkamp - €99.50', 100 ],
            'no price at all' => [ 'Weekkamp', null ],
            'a number but no euro sign' => [ 'Weekkamp 101', null ],
            'empty' => [ '', null ],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('labelProvider')]
    public function testThePriceIsReadOutOfTheLabelTheParentChose(string $label, ?int $expected): void
    {
        $this->assertSame($expected, CJW_Wizard_Amount::priceFromLabel($label));
    }

    public function testEachChildAddsTheirOwnCamp(): void
    {
        $amount = CJW_Wizard_Amount::of([
            [ 'camp' => 'Weekkamp - €101' ],
            [ 'camp' => 'Weekendkamp - €44' ],
        ], []);

        $this->assertSame(145, $amount['camp']);
        $this->assertSame(145, $amount['total']);
        $this->assertSame(0, $amount['unpriced']);
    }

    public function testADonationIsAddedOnTop(): void
    {
        $amount = CJW_Wizard_Amount::of([ [ 'camp' => 'Weekkamp - €101' ] ], [ 'donatie' => '€ 10' ]);

        $this->assertSame(10, $amount['donation']);
        $this->assertSame(111, $amount['total']);
    }

    /**
     * A camp whose label carries no price is counted rather than treated as
     * free, so the screen can say the total is incomplete instead of wrong.
     */
    public function testACampWithoutAPriceIsCountedNotIgnored(): void
    {
        $amount = CJW_Wizard_Amount::of([
            [ 'camp' => 'Weekkamp - €101' ],
            [ 'camp' => 'Nieuw kamp' ],
        ], []);

        $this->assertSame(101, $amount['camp']);
        $this->assertSame(1, $amount['unpriced']);
    }

    public function testTheTextSaysNothingWhenThereIsNothingToQuote(): void
    {
        $this->assertSame('', CJW_Wizard_Amount::asText([ [ 'camp' => 'Weekkamp' ] ], []));
    }

    public function testTheTextBreaksOutADonation(): void
    {
        $text = CJW_Wizard_Amount::asText([ [ 'camp' => 'Weekkamp - €101' ] ], [ 'donatie' => '10' ]);

        $this->assertStringContainsString('111', $text);
        $this->assertStringContainsString('101', $text);
        $this->assertStringContainsString('10', $text);
    }

    public function testTheTextStaysShortWhenThereIsNoDonation(): void
    {
        $this->assertSame(
            '€101',
            CJW_Wizard_Amount::asText([ [ 'camp' => 'Weekkamp - €101' ] ], [])
        );
    }
}
