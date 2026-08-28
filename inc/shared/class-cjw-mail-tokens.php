<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The values behind the tokens a mail template may use.
 *
 * Split from the registry, which says what a token means, so that resolving one
 * does not require loading an admin screen's worth of copy — and so the camp
 * values are gathered in exactly one place for every mail that wants them.
 */
final class CJW_Mail_Tokens
{
    /**
     * The camp values every template shares.
     *
     * @return array<string, string>
     */
    public static function camp(): array
    {
        $camp = function_exists('cjw_summer_camp') ? cjw_summer_camp() : null;

        if ($camp === null) {
            return [];
        }

        return [
            '{camp_theme_name}' => (string) $camp->getThemeName(),
            '{camp_theme_year}' => (string) $camp->getThemeYear(),
            '{camp_date_range}' => (string) $camp->getDateRangeText(),
            '{camp_start_date}' => self::date($camp->getStartDate()),
            '{camp_end_date}' => self::date($camp->getEndDate()),
            '{camp_location}' => (string) $camp->getCampLocation(),
            '{camp_deadline}' => self::date($camp->getRegistrationDeadline()),
            '{camp_contact_email}' => (string) $camp->getContactEmail(),
            '{site_name}' => (string) get_bloginfo('name'),
            '{site_url}' => home_url('/'),
        ];
    }

    /**
     * Everything a registration can say about itself, for the confirmation.
     *
     * @param array<string, mixed> $config  Config snapshot saved with the row.
     * @param array<string, mixed> $payload Submitted answers.
     *
     * @return array<string, string>
     */
    public static function registration(int $submission_id, array $config, array $payload): array
    {
        $children = CJW_Wizard_Field_Roles::childRows($config, $payload);

        $names = [];
        $camps = [];

        foreach ($children as $child) {
            $name = trim((string) ($child['name'] ?? ''));
            $camp = trim((string) ($child['camp'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }

            if ($camp !== '' && ! in_array($camp, $camps, true)) {
                $camps[] = $camp;
            }
        }

        return [
            '{child_names}' => self::list($names),
            '{camp_choices}' => self::list($camps),
            '{amount_due}' => CJW_Wizard_Amount::asText($children, $payload),
            '{registration_number}' => $submission_id > 0 ? (string) $submission_id : '',
            ...self::camp(),
        ];
    }

    /**
     * "Anna", "Anna en Sem", "Anna, Sem en Nora".
     *
     * @param array<int, string> $items
     */
    public static function list(array $items): string
    {
        $items = array_values(array_filter($items));

        if ($items === []) {
            return '';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' ' . __('en', 'cjw') . ' ' . $last;
    }

    /**
     * A date as a parent should read it.
     */
    private static function date(?DateTimeImmutable $date): string
    {
        if (! $date instanceof DateTimeImmutable) {
            return '';
        }

        return wp_date((string) get_option('date_format'), $date->getTimestamp());
    }
}
