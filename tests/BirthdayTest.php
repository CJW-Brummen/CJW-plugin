<?php

declare(strict_types=1);

/**
 * The date arithmetic behind the birthday mail. Whether it reaches an inbox is
 * checked against a real WordPress; whether today is somebody's birthday is
 * decided here, where every awkward date can be tried cheaply.
 */
final class BirthdayTest extends CJW_TestCase
{
    private function on(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('Europe/Amsterdam'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int|null}>
     */
    public static function birthdayProvider(): array
    {
        return [
            'birthday today, Dutch format' => [ '09-04-2015', '2027-04-09', 12 ],
            'birthday today, ISO format' => [ '2015-04-09', '2027-04-09', 12 ],
            'birthday today, slashes' => [ '09/04/2015', '2027-04-09', 12 ],
            'birthday tomorrow' => [ '10-04-2015', '2027-04-09', null ],
            'birthday yesterday' => [ '08-04-2015', '2027-04-09', null ],
            'same day and month, wrong month order' => [ '04-09-2015', '2027-04-09', null ],
            'born today' => [ '09-04-2027', '2027-04-09', null ],
            'born in the future' => [ '09-04-2030', '2027-04-09', null ],
            'no date' => [ '', '2027-04-09', null ],
            'not a date' => [ 'onzin', '2027-04-09', null ],
            'leap day on a leap year' => [ '29-02-2016', '2028-02-29', 12 ],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('birthdayProvider')]
    public function testTheAgeIsOnlyGivenOnTheDayItself(string $born, string $today, ?int $expected): void
    {
        $this->assertSame($expected, CJW_Wizard_Birthday_Mailer::ageToday($born, $this->on($today)));
    }

    /**
     * A date the field could never have produced must not be coerced into one.
     * 31-11 is not a day, and PHP will happily roll it into 1 December unless
     * the parse is strict.
     */
    public function testAnImpossibleDateIsNotRolledForward(): void
    {
        $this->assertNull(CJW_Wizard_Birthday_Mailer::ageToday('31-11-2015', $this->on('2027-12-01')));
    }
}
