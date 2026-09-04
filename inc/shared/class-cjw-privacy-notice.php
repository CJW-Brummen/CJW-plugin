<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What the registration form tells a parent about the data it is asking for.
 *
 * The form asks for a child's full name, date of birth and medication notes,
 * and said nothing at all about who receives that, what it is used for, or how
 * long it is kept. The site has known the answer to the last one since
 * retention was configured: a cron enforces it every night. It simply never
 * said it out loud.
 *
 * The wording is the organisers' to write -- a board wants its own sentences
 * about a subject like this, and the copy for the mails already works that way
 * -- but the facts inside it are tokens, so the retention period on the page
 * cannot drift away from the retention period the sweep actually applies.
 */
final class CJW_Privacy_Notice
{
    /**
     * Where the text lives inside the camp settings.
     */
    public const string SETTING = 'privacy_notice_text';

    /**
     * Every token the text may use, with what it stands for.
     *
     * @return array<string, string>
     */
    public static function tokens(): array
    {
        return [
            '{organisatie}' => __('De naam van de website', 'cjw'),
            '{contact_email}' => __('Het contact-e-mailadres van de organisatie', 'cjw'),
            '{bewaartermijn}' => __('Hoe lang een inschrijving bewaard blijft, in woorden — bijvoorbeeld “12 maanden na afloop van het kamp”', 'cjw'),
            '{bewaartermijn_datum}' => __('De datum waarop de gegevens van dit kampjaar gewist worden, als de kampdata bekend zijn', 'cjw'),
            '{privacyverklaring_url}' => __('Het adres van de privacyverklaring; laat leeg om de link onderaan automatisch te laten toevoegen', 'cjw'),
        ];
    }

    /**
     * The text as it is shown on the form: tokens resolved, HTML kept.
     *
     * @return string
     */
    public static function text(): string
    {
        $stored = trim(self::stored());
        $resolved = self::resolve($stored !== '' ? $stored : self::defaultText());

        return wp_kses_post($resolved) . self::privacyPolicyLink($stored);
    }

    /**
     * The same text as one block of plain text, for the record kept with a
     * registration.
     *
     * @return string
     */
    public static function plainText(): string
    {
        $text = str_replace([ '</p>', '<br>', '<br />' ], "\n", self::text());
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Whether an organiser has written their own text.
     *
     * @return bool
     */
    public static function isDefault(): bool
    {
        return trim(self::stored()) === '';
    }

    /**
     * The label beside the checkbox.
     *
     * Deliberately not editable. It is the sentence the tick is evidence of,
     * and it has to keep meaning what the record says it means.
     *
     * @return string
     */
    public static function consentLabel(): string
    {
        return __('Ik heb dit gelezen en ga akkoord met het gebruik van deze gegevens.', 'cjw');
    }

    /**
     * The wording used when nobody has written their own.
     *
     * @return string
     */
    public static function defaultText(): string
    {
        return implode("\n\n", [
            '<p>' . __('Voor de inschrijving vragen we gegevens van je kind: de naam, de geboortedatum en wat we moeten weten om goed voor hem of haar te zorgen, zoals allergieën of medicijnen.', 'cjw') . '</p>',
            '<p>' . __('{organisatie} gebruikt die gegevens alleen om het kamp te organiseren. We geven ze niet aan anderen. De leiding ziet alleen wat ze tijdens het kamp nodig heeft.', 'cjw') . '</p>',
            '<p>' . __('We bewaren de inschrijving {bewaartermijn}. Daarna worden de persoonsgegevens automatisch gewist.', 'cjw') . '</p>',
            '<p>' . __('Wil je weten welke gegevens we van je kind hebben, of wil je ze laten aanpassen of wissen? Mail dan naar {contact_email}.', 'cjw') . '</p>',
        ]);
    }

    /**
     * The stored text, before tokens are resolved.
     *
     * @return string
     */
    public static function stored(): string
    {
        if (! function_exists('cjw_summer_camp')) {
            return '';
        }

        $camp = cjw_summer_camp();

        return method_exists($camp, 'getPrivacyNoticeText') ? (string) $camp->getPrivacyNoticeText() : '';
    }

    /**
     * How long a registration is kept, said in words.
     *
     * @return string
     */
    public static function retentionPhrase(): string
    {
        $months = self::retentionMonths();

        if ($months < 1) {
            return __('totdat je ons vraagt ze te wissen', 'cjw');
        }

        $phrase = sprintf(
            /* translators: %d: number of months. */
            _n('%d maand na afloop van het kamp', '%d maanden na afloop van het kamp', $months, 'cjw'),
            $months
        );

        $date = self::erasureDate();

        return $date === '' ? $phrase : sprintf(
            /* translators: 1: period in words, 2: the date the data is erased. */
            __('%1$s, dus tot %2$s', 'cjw'),
            $phrase,
            $date
        );
    }

    /**
     * The date this camp year's registrations are erased, or an empty string
     * when the camp has no end date yet or retention is switched off.
     *
     * @return string
     */
    public static function erasureDate(): string
    {
        $months = self::retentionMonths();

        if ($months < 1 || ! function_exists('cjw_summer_camp')) {
            return '';
        }

        $camp = cjw_summer_camp();
        $end = method_exists($camp, 'getEndDate') ? $camp->getEndDate() : null;

        if (! $end instanceof DateTimeImmutable) {
            return '';
        }

        // The sweep measures from the camp end date, so the promise on the form
        // is the same arithmetic read forwards. See CJW_Wizard_Submission_Retention::cutoff().
        return (string) cjw_localize_value($end->modify("+{$months} months"), [ 'type' => 'date' ]);
    }

    /**
     * The address of the site's privacy page, or an empty string.
     *
     * @return string
     */
    public static function privacyPolicyUrl(): string
    {
        if (! function_exists('get_privacy_policy_url')) {
            return '';
        }

        return (string) get_privacy_policy_url();
    }

    /**
     * Replaces every token with what it stands for.
     *
     * @param string $text Text as written.
     *
     * @return string
     */
    private static function resolve(string $text): string
    {
        return strtr($text, [
            '{organisatie}' => self::organisation(),
            '{contact_email}' => self::contactEmail(),
            '{bewaartermijn}' => self::retentionPhrase(),
            '{bewaartermijn_datum}' => self::erasureDate(),
            '{privacyverklaring_url}' => self::privacyPolicyUrl(),
        ]);
    }

    /**
     * The link to the full privacy statement, appended when there is a page to
     * link to and the text does not already link to it itself.
     *
     * @param string $stored The text as written, before resolving.
     *
     * @return string
     */
    private static function privacyPolicyLink(string $stored): string
    {
        $url = self::privacyPolicyUrl();

        if ($url === '' || str_contains($stored, '{privacyverklaring_url}')) {
            return '';
        }

        return sprintf(
            '<p><a href="%s">%s</a></p>',
            esc_url($url),
            esc_html__('Lees de volledige privacyverklaring', 'cjw')
        );
    }

    private static function organisation(): string
    {
        $name = trim((string) get_bloginfo('name'));

        return $name !== '' ? $name : __('De organisatie', 'cjw');
    }

    private static function contactEmail(): string
    {
        if (function_exists('cjw_summer_camp')) {
            $camp = cjw_summer_camp();
            $email = method_exists($camp, 'getContactEmail') ? (string) $camp->getContactEmail() : '';

            if ($email !== '') {
                return $email;
            }
        }

        return (string) get_option('admin_email', '');
    }

    private static function retentionMonths(): int
    {
        if (! function_exists('cjw_summer_camp')) {
            return 0;
        }

        $camp = cjw_summer_camp();

        return method_exists($camp, 'getRetentionMonths') ? (int) $camp->getRetentionMonths() : 0;
    }
}
