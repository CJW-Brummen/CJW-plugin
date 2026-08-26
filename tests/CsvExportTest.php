<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The CSV export carries values straight from a public, unauthenticated form
 * into a file an organiser opens in Excel, LibreOffice Calc or Google Sheets.
 * Every leading character those applications treat as the start of a formula
 * has to be neutralised on the way out.
 */
final class CsvExportTest extends CJW_TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function formulaPrefixProvider(): array
    {
        return [
            'equals sign' => [ "=cmd|'/c calc'!A1" ],
            'plus sign' => [ '+HYPERLINK("http://evil.test")' ],
            'minus sign' => [ '-2+3+cmd|\'/c calc\'!A1' ],
            'at sign' => [ '@SUM(1+1)*cmd|\'/c calc\'!A1' ],
            'tab' => [ "\t=1+1" ],
            'carriage return' => [ "\r=1+1" ],
        ];
    }

    #[DataProvider('formulaPrefixProvider')]
    public function testFormulaPrefixesAreNeutralised(string $payload): void
    {
        $escaped = CJW_Wizard_Submission_Exporter::escape_csv_cell($payload);

        $this->assertSame(
            "'" . $payload,
            $escaped,
            'A value opening with a formula character must be forced to literal text.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function harmlessValueProvider(): array
    {
        return [
            'a name' => [ 'Anna de Vries' ],
            'an email' => [ 'ouder@example.test' ],
            'a date' => [ '2026-07-13' ],
            'a sentence starting with a word' => [ 'Geen bijzonderheden' ],
            'an empty value' => [ '' ],
        ];
    }

    #[DataProvider('harmlessValueProvider')]
    public function testOrdinaryValuesPassThroughUnchanged(string $value): void
    {
        $this->assertSame($value, CJW_Wizard_Submission_Exporter::escape_csv_cell($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function numericValueProvider(): array
    {
        return [
            'negative integer' => [ '-5' ],
            'negative decimal' => [ '-12.50' ],
            'signed positive' => [ '+42' ],
            'plain integer' => [ '175' ],
        ];
    }

    /**
     * A leading `-` or `+` on a real number is not a formula in any spreadsheet,
     * and quoting it would put a stray apostrophe in front of every negative
     * amount in the export.
     */
    #[DataProvider('numericValueProvider')]
    public function testNumbersKeepTheirSign(string $value): void
    {
        $this->assertSame($value, CJW_Wizard_Submission_Exporter::escape_csv_cell($value));
    }
}
