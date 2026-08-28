<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What a registration comes to.
 *
 * Lived inside the submissions detail screen until the confirmation mail needed
 * to quote the same figure. Two implementations of "what does this family owe"
 * is one more than a camp can afford to have -- they would drift, and the one
 * that drifts is the one the parent reads.
 *
 * The camp label the parent chose carries its own price, and that wins over the
 * Tarieven setting: the label is the number they actually saw and agreed to,
 * and the setting can be edited afterwards.
 */
final class CJW_Wizard_Amount
{
    /**
     * Adds up one registration.
     *
     * @param array<int, array<string, string>> $children Child records.
     * @param array<string, mixed>              $payload  Submitted answers.
     *
     * @return array{camp: int, donation: int, total: int, unpriced: int}
     */
    public static function of(array $children, array $payload): array
    {
        $camp = 0;
        $unpriced = 0;

        foreach ($children as $child) {
            $price = self::priceFromLabel((string) ($child['camp'] ?? ''));

            if ($price === null) {
                ++$unpriced;
                continue;
            }

            $camp += $price;
        }

        $donation = (int) preg_replace('/[^0-9]/', '', (string) ($payload['donatie'] ?? ''));

        return [
            'camp' => $camp,
            'donation' => $donation,
            'total' => $camp + $donation,
            'unpriced' => $unpriced,
        ];
    }

    /**
     * The total as a parent should read it, for a mail.
     *
     * Returns an empty string when there is no figure to quote -- a camp label
     * without a price in it, and no donation. Better to say nothing than to
     * tell a family they owe nothing.
     *
     * @param array<int, array<string, string>> $children
     * @param array<string, mixed>              $payload
     */
    public static function asText(array $children, array $payload): string
    {
        $amount = self::of($children, $payload);

        if ($amount['total'] < 1) {
            return '';
        }

        $text = '€' . number_format_i18n($amount['total']);

        if ($amount['donation'] > 0 && $amount['camp'] > 0) {
            $text .= sprintf(
                /* translators: 1: camp fee, 2: donation, both already formatted. */
                __(' (€%1$s kampgeld en €%2$s donatie)', 'cjw'),
                number_format_i18n($amount['camp']),
                number_format_i18n($amount['donation'])
            );
        }

        return $text;
    }

    /**
     * Reads a price out of a camp label such as "Weekkamp (10 t/m 17 jaar) - €101".
     */
    public static function priceFromLabel(string $label): ?int
    {
        if (preg_match('/€\s*([0-9]+(?:[.,][0-9]{2})?)/u', $label, $match) !== 1) {
            return null;
        }

        return (int) round((float) str_replace(',', '.', $match[1]));
    }
}
