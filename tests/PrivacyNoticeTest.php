<?php

declare(strict_types=1);

/**
 * What the form tells a parent about the data it asks for.
 *
 * The wording is the organisers'; the facts inside it are not. A retention
 * period typed into a sentence goes stale the moment somebody changes the
 * setting, and the sentence is the one a parent would hold the camp to -- so
 * the period, the address and the erasure date are tokens the site fills in.
 */
final class PrivacyNoticeTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [ 'admin_email' => 'beheer@example.test' ];
        $GLOBALS['cjw_test_summer_camp'] = new class {
            public string $text = '';

            public int $months = 12;

            public ?DateTimeImmutable $end = null;

            public function getPrivacyNoticeText(): string
            {
                return $this->text;
            }

            public function getRetentionMonths(): int
            {
                return $this->months;
            }

            public function getEndDate(): ?DateTimeImmutable
            {
                return $this->end;
            }

            public function getContactEmail(): string
            {
                return 'kamp@example.test';
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_options'], $GLOBALS['cjw_test_summer_camp']);

        parent::tearDown();
    }

    private function camp(): object
    {
        return $GLOBALS['cjw_test_summer_camp'];
    }

    public function testTheBuiltInTextIsUsedUntilSomebodyWritesTheirOwn(): void
    {
        $this->assertTrue(CJW_Privacy_Notice::isDefault());
        $this->assertStringContainsString('geboortedatum', CJW_Privacy_Notice::text());

        $this->camp()->text = '<p>Onze eigen tekst.</p>';

        $this->assertFalse(CJW_Privacy_Notice::isDefault());
        $this->assertStringContainsString('Onze eigen tekst.', CJW_Privacy_Notice::text());
        $this->assertStringNotContainsString('geboortedatum', CJW_Privacy_Notice::text());
    }

    public function testEveryTokenIsFilledIn(): void
    {
        $this->camp()->end = new DateTimeImmutable('2027-07-18');
        $this->camp()->text = '<p>{organisatie} · {contact_email} · {bewaartermijn} · {bewaartermijn_datum}</p>';

        $text = CJW_Privacy_Notice::text();

        $this->assertStringContainsString('CJW Brummen', $text, 'the site name');
        $this->assertStringContainsString('kamp@example.test', $text);
        $this->assertStringContainsString('12 maanden na afloop van het kamp', $text);
        $this->assertStringNotContainsString('{', $text, 'no token may survive into what a parent reads');
    }

    /**
     * The sweep erases what is older than the camp end plus the retention
     * period, so the date on the form is that same arithmetic read forwards.
     */
    public function testTheErasureDateIsTheCampEndPlusTheRetentionPeriod(): void
    {
        $this->camp()->end = new DateTimeImmutable('2027-07-18');
        $this->camp()->months = 12;

        $date = CJW_Privacy_Notice::erasureDate();

        // The month name follows the site's date format and locale; the year
        // is the part that proves the arithmetic.
        $this->assertStringContainsString('18', $date);
        $this->assertStringContainsString('2028', $date);
        $this->assertStringContainsString($date, CJW_Privacy_Notice::retentionPhrase());
    }

    public function testWithoutCampDatesThePeriodIsStillNamed(): void
    {
        $this->camp()->end = null;

        $this->assertSame('', CJW_Privacy_Notice::erasureDate());
        $this->assertSame('12 maanden na afloop van het kamp', CJW_Privacy_Notice::retentionPhrase());
    }

    public function testOneMonthIsSaidInTheSingular(): void
    {
        $this->camp()->months = 1;

        $this->assertStringContainsString('1 maand na afloop', CJW_Privacy_Notice::retentionPhrase());
    }

    /**
     * Retention switched off is a promise the form must not make.
     */
    public function testRetentionSwitchedOffPromisesNoDeletion(): void
    {
        $this->camp()->months = 0;

        $this->assertSame('totdat je ons vraagt ze te wissen', CJW_Privacy_Notice::retentionPhrase());
        $this->assertSame('', CJW_Privacy_Notice::erasureDate());
    }

    public function testTheRecordedVersionIsPlainTextWithoutMarkup(): void
    {
        $this->camp()->text = "<p>Eerste alinea.</p>\n\n<p>Tweede <strong>alinea</strong>.</p>";

        $plain = CJW_Privacy_Notice::plainText();

        $this->assertStringNotContainsString('<', $plain);
        $this->assertSame("Eerste alinea.\nTweede alinea.", $plain);
    }

    public function testTheLabelBesideTheTickIsNotEmpty(): void
    {
        $this->assertNotSame('', CJW_Privacy_Notice::consentLabel());
    }
}
