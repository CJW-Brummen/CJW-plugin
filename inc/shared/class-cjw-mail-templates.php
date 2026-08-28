<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The mails this site sends, and the copy a kampbeheerder may write for them.
 *
 * Before this, mail copy was scattered and mostly unreachable: the parent
 * confirmation had two fields per form holding a bare block of text with no way
 * to include a name or a date, the organiser's notification was hard-coded in
 * PHP, and the birthday mail was two settings promising a feature that did not
 * exist. Changing what a parent reads meant asking a developer.
 *
 * Templates live inside the camp settings option rather than beside it. That is
 * deliberate: it means the settings history, the JSON export and the new-camp-
 * year archive all cover mail copy without knowing anything about mail. Copy
 * somebody spent an afternoon on should be recoverable by the same undo as
 * everything else on that screen.
 */
final class CJW_Mail_Templates
{
    /**
     * Where the templates live inside the camp settings array.
     */
    public const string SETTINGS_KEY = 'mail_templates';

    /**
     * The registration confirmation, to the parent who just registered.
     */
    public const string CONFIRMATION = 'parent_confirmation';

    /**
     * A greeting on a registered child's birthday.
     */
    public const string BIRTHDAY = 'birthday';

    /**
     * "A registration arrived", to the organisation.
     */
    public const string NOTIFICATION = 'admin_notification';

    /**
     * Tokens every template may use.
     *
     * Named in the same style as the tokens the form builder already resolves,
     * so there is one vocabulary across the site rather than two. The Dutch
     * explanation lives in the admin screen, next to a button that inserts it.
     *
     * @return array<string, string>
     */
    public static function commonTokens(): array
    {
        return [
            '{camp_theme_name}' => __('Het jaarthema, bijvoorbeeld “Piraten”', 'cjw'),
            '{camp_theme_year}' => __('Het kampjaar, bijvoorbeeld “2027”', 'cjw'),
            '{camp_date_range}' => __('De kampdata als één regel, bijvoorbeeld “11 t/m 18 juli 2027”', 'cjw'),
            '{camp_start_date}' => __('De eerste dag van het kamp', 'cjw'),
            '{camp_end_date}' => __('De laatste dag van het kamp', 'cjw'),
            '{camp_location}' => __('De kamplocatie', 'cjw'),
            '{camp_deadline}' => __('De datum waarop de inschrijvingen sluiten', 'cjw'),
            '{camp_contact_email}' => __('Het contact-e-mailadres van de organisatie', 'cjw'),
            '{site_name}' => __('De naam van de website', 'cjw'),
            '{site_url}' => __('Het adres van de website', 'cjw'),
        ];
    }

    /**
     * Every template, with its copy and what it may say.
     *
     * @return array<string, array{label: string, description: string, tokens: array<string, string>, subject: string, body: string}>
     */
    public static function all(): array
    {
        return [
            self::CONFIRMATION => [
                'label' => __('Bevestiging aan de ouder', 'cjw'),
                'description' => __('Gaat naar de ouder zodra een inschrijving binnenkomt. Dit is de enige mail die een ouder van de website krijgt, dus het is ook de plek om te vertellen wat er daarna gebeurt.', 'cjw'),
                'tokens' => [
                    '{child_names}' => __('De naam of namen van de ingeschreven kinderen', 'cjw'),
                    '{camp_choices}' => __('Voor welk kamp of welke kampen is ingeschreven', 'cjw'),
                    '{amount_due}' => __('Het te betalen bedrag, inclusief een eventuele donatie', 'cjw'),
                    '{registration_number}' => __('Het inschrijfnummer, handig om bij vragen te noemen', 'cjw'),
                ],
                'subject' => __('Bevestiging van je inschrijving', 'cjw'),
                'body' => self::defaultConfirmationBody(),
            ],
            self::BIRTHDAY => [
                'label' => __('Verjaardagsmail', 'cjw'),
                'description' => __('Gaat op de verjaardag van een ingeschreven kind naar het e-mailadres waarmee is ingeschreven. Alleen voor kinderen die dit kampjaar zijn ingeschreven, en alleen zolang de gegevens nog bewaard worden.', 'cjw'),
                'tokens' => [
                    '{child_name}' => __('De naam van het jarige kind', 'cjw'),
                    '{child_age}' => __('De leeftijd die het kind vandaag wordt', 'cjw'),
                ],
                'subject' => __('Hiep hiep hoera, {child_name}!', 'cjw'),
                'body' => self::defaultBirthdayBody(),
            ],
            self::NOTIFICATION => [
                'label' => __('Bericht aan de organisatie', 'cjw'),
                'description' => __('Gaat naar de organisatie zodra iemand het formulier heeft ingevuld. Bevat met opzet geen gegevens van kinderen: die horen in de beveiligde omgeving te blijven en niet in mailboxen, doorgestuurde berichten en back-ups terecht te komen. Er zijn daarom geen tokens voor namen of adressen beschikbaar.', 'cjw'),
                'tokens' => [
                    '{form_title}' => __('De naam van het formulier dat is ingevuld', 'cjw'),
                    '{registration_number}' => __('Het inschrijfnummer', 'cjw'),
                    '{submissions_url}' => __('De link naar het overzicht met inschrijvingen', 'cjw'),
                ],
                'subject' => __('Nieuwe inschrijving voor {form_title}', 'cjw'),
                'body' => self::defaultNotificationBody(),
            ],
        ];
    }

    /**
     * One template's definition.
     *
     * @return array{label: string, description: string, tokens: array<string, string>, subject: string, body: string}|null
     */
    public static function definition(string $key): ?array
    {
        return self::all()[ $key ] ?? null;
    }

    /**
     * Every token a template may use, its own plus the shared ones.
     *
     * @return array<string, string>
     */
    public static function tokensFor(string $key): array
    {
        $definition = self::definition($key);

        if ($definition === null) {
            return [];
        }

        return [ ...$definition['tokens'], ...self::commonTokens() ];
    }

    /**
     * The subject in use: what was written, or the default.
     */
    public static function subject(string $key): string
    {
        return self::value($key, 'subject');
    }

    /**
     * The body in use: what was written, or the default.
     */
    public static function body(string $key): string
    {
        return self::value($key, 'body');
    }

    /**
     * Whether a template still holds its shipped copy.
     */
    public static function isDefault(string $key): bool
    {
        $stored = self::stored()[ $key ] ?? [];

        return trim((string) ($stored['subject'] ?? '')) === ''
            && trim((string) ($stored['body'] ?? '')) === '';
    }

    /**
     * The written copy, as stored. Absent keys mean "use the default".
     *
     * @return array<string, array{subject: string, body: string}>
     */
    public static function stored(): array
    {
        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);
        $templates = is_array($settings) ? ($settings[ self::SETTINGS_KEY ] ?? []) : [];

        return self::sanitize($templates);
    }

    /**
     * Writes the templates back, leaving the rest of the settings alone.
     *
     * Goes through update_option like any other settings change, so the history
     * snapshot happens on its own.
     *
     * @param array<string, array{subject?: string, body?: string}> $templates
     */
    public static function save(array $templates): void
    {
        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        $settings[ self::SETTINGS_KEY ] = self::sanitize($templates);

        update_option(CJW_Summer_Camp_Service::OPTION_KEY, $settings);
    }

    /**
     * Keeps only known templates, with a plain subject and post-safe HTML body.
     *
     * @param mixed $input Raw templates.
     *
     * @return array<string, array{subject: string, body: string}>
     */
    public static function sanitize(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $clean = [];

        foreach (array_keys(self::all()) as $key) {
            $template = $input[ $key ] ?? null;

            if (! is_array($template)) {
                continue;
            }

            $clean[ $key ] = [
                'subject' => sanitize_text_field((string) ($template['subject'] ?? '')),
                'body' => wp_kses_post((string) ($template['body'] ?? '')),
            ];
        }

        return $clean;
    }

    /**
     * A stored field, falling back to the shipped default when it is blank.
     *
     * Blank rather than absent, because clearing a field in the editor should
     * restore the default rather than send an empty mail.
     */
    private static function value(string $key, string $field): string
    {
        $definition = self::definition($key);

        if ($definition === null) {
            return '';
        }

        $written = trim((string) (self::stored()[ $key ][ $field ] ?? ''));

        return $written !== '' ? $written : $definition[ $field ];
    }

    /**
     * The shipped confirmation copy.
     *
     * @return string
     */
    private static function defaultConfirmationBody(): string
    {
        return implode("\n\n", [
            '<p>' . __('Hoi,', 'cjw') . '</p>',
            '<p>' . __('Bedankt voor je inschrijving. We hebben hem goed ontvangen.', 'cjw') . '</p>',
            '<p>'
                . __('Ingeschreven: <strong>{child_names}</strong><br>', 'cjw')
                . __('Kamp: {camp_choices}<br>', 'cjw')
                . __('Te betalen: {amount_due}<br>', 'cjw')
                . __('Inschrijfnummer: {registration_number}', 'cjw')
                . '</p>',
            '<p>' . __('Het kamp is van {camp_date_range}. We nemen contact met je op als er nog iets nodig is.', 'cjw') . '</p>',
            '<p>' . __('Vragen? Mail ons gerust op {camp_contact_email}.', 'cjw') . '</p>',
        ]);
    }

    /**
     * The shipped birthday copy.
     *
     * @return string
     */
    private static function defaultBirthdayBody(): string
    {
        return implode("\n\n", [
            '<p>' . __('Hoera, {child_name} is vandaag {child_age} jaar geworden!', 'cjw') . '</p>',
            '<p>' . __('Wij wensen je een hele fijne verjaardag, en we zien je graag op het zomerkamp.', 'cjw') . '</p>',
            '<p>' . __('Groetjes van de hele kampleiding', 'cjw') . '</p>',
        ]);
    }

    /**
     * The shipped notification copy.
     *
     * @return string
     */
    private static function defaultNotificationBody(): string
    {
        return implode("\n\n", [
            '<p>' . __('Er is een nieuwe inschrijving binnengekomen voor {form_title}.', 'cjw') . '</p>',
            '<p>' . __('Inschrijfnummer: {registration_number}', 'cjw') . '</p>',
            '<p>' . __('De gegevens staan in de beveiligde omgeving en worden bewust niet meegestuurd in deze e-mail.', 'cjw') . '</p>',
            '<p><a href="{submissions_url}">' . __('Bekijk de inschrijving', 'cjw') . '</a></p>',
        ]);
    }
}
