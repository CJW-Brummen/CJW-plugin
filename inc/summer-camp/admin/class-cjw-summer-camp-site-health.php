<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds summer-camp checks to the WordPress Site Health screen.
 */
final class CJW_Summer_Camp_Site_Health
{
    /**
     * Registers the Site Health hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_filter('site_status_tests', [new self(), 'registerTests']);
    }

    /**
     * Adds the summer-camp tests to the Site Health test list.
     *
     * @param array $tests Registered Site Health tests.
     * @param array<string, mixed> $tests
     *
     * @return array<string, mixed>
     */
    public function registerTests(array $tests): array
    {
        if (!function_exists('cjw_summer_camp')) {
            return $tests;
        }

        if (!isset($tests['direct']) || !is_array($tests['direct'])) {
            $tests['direct'] = [];
        }

        $tests['direct']['cjw_summer_camp_camp_dates'] = [
            'label' => __('Zomerkamp: kampdata', 'cjw'),
            'test' => [$this, 'testCampDates'],
        ];

        $tests['direct']['cjw_summer_camp_registration_deadline'] = [
            'label' => __('Zomerkamp: inschrijfdeadline', 'cjw'),
            'test' => [$this, 'testRegistrationDeadline'],
        ];

        $tests['direct']['cjw_summer_camp_contact_email'] = [
            'label' => __('Zomerkamp: contact-e-mailadres', 'cjw'),
            'test' => [$this, 'testContactEmail'],
        ];

        $tests['direct']['cjw_summer_camp_sponsor_logos'] = [
            'label' => __('Zomerkamp: sponsorlogo’s', 'cjw'),
            'test' => [$this, 'testSponsorLogos'],
        ];

        $tests['direct']['cjw_summer_camp_polaroids'] = [
            'label' => __('Zomerkamp: kampplakboek', 'cjw'),
            'test' => [$this, 'testPolaroids'],
        ];

        $tests['direct']['cjw_summer_camp_mail'] = [
            'label' => __('Zomerkamp: e-mail', 'cjw'),
            'test' => [$this, 'testMail'],
        ];

        $tests['direct']['cjw_summer_camp_cron'] = [
            'label' => __('Zomerkamp: geplande taken', 'cjw'),
            'test' => [$this, 'testScheduledJobs'],
        ];

        $tests['direct']['cjw_summer_camp_registration_page'] = [
            'label' => __('Zomerkamp: inschrijfpagina', 'cjw'),
            'test' => [$this, 'testRegistrationPage'],
        ];

        $tests['direct']['cjw_summer_camp_parent_email'] = [
            'label' => __('Zomerkamp: e-mailadres van de ouder', 'cjw'),
            'test' => [$this, 'testParentEmailRole'],
        ];

        $tests['direct']['cjw_summer_camp_mail_safe_mode'] = [
            'label' => __('Zomerkamp: mail-veiligmodus', 'cjw'),
            'test' => [$this, 'testMailSafeMode'],
        ];

        return $tests;
    }

    /**
     * Checks that both camp dates are set and the end date is not before the start date.
     *
     * @return array<string, mixed>
     */
    public function testCampDates(): array
    {
        $test = 'cjw_summer_camp_camp_dates';
        $camp = cjw_summer_camp();
        $start = $camp->getStartDate();
        $end = $camp->getEndDate();

        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return $this->result(
                $test,
                __('De kampdata zijn nog niet ingesteld', 'cjw'),
                'critical',
                '<p>' . esc_html__('Er zijn nog geen start- en einddatum voor het zomerkamp ingesteld. Zonder kampdata kan de website geen data, aftelling of inschrijfperiode tonen.', 'cjw') . '</p>',
                $this->settingsAction()
            );
        }

        if ($end < $start) {
            return $this->result(
                $test,
                __('De einddatum van het kamp ligt vóór de startdatum', 'cjw'),
                'critical',
                '<p>' . esc_html__('De einddatum van het zomerkamp ligt vóór de startdatum. Controleer en corrigeer de kampdata.', 'cjw') . '</p>',
                $this->settingsAction()
            );
        }

        return $this->result(
            $test,
            __('De kampdata zijn ingesteld', 'cjw'),
            'good',
            '<p>' . esc_html(sprintf(
                /* translators: %s: camp date range. */
                __('De kampdata zijn ingesteld: %s.', 'cjw'),
                $camp->getDateRangeText()
            )) . '</p>'
        );
    }

    /**
     * Checks that the registration deadline is set and does not fall after the camp start date.
     *
     * @return array<string, mixed>
     */
    public function testRegistrationDeadline(): array
    {
        $test = 'cjw_summer_camp_registration_deadline';
        $camp = cjw_summer_camp();
        $deadline = $camp->getRegistrationDeadline();
        $start = $camp->getStartDate();

        if (!$deadline instanceof DateTimeImmutable) {
            return $this->result(
                $test,
                __('De inschrijfdeadline is nog niet ingesteld', 'cjw'),
                'recommended',
                '<p>' . esc_html__('Er is nog geen deadline voor de inschrijvingen ingesteld. Zonder deadline blijven de inschrijvingen open zolang ze op “open” staan.', 'cjw') . '</p>',
                $this->settingsAction()
            );
        }

        if ($start instanceof DateTimeImmutable && $deadline > $start) {
            return $this->result(
                $test,
                __('De inschrijfdeadline ligt na de start van het kamp', 'cjw'),
                'recommended',
                '<p>' . esc_html__('De deadline voor inschrijvingen ligt na de startdatum van het zomerkamp. Kies een deadline die op of vóór de startdatum ligt.', 'cjw') . '</p>',
                $this->settingsAction()
            );
        }

        return $this->result(
            $test,
            __('De inschrijfdeadline is ingesteld', 'cjw'),
            'good',
            '<p>' . esc_html__('De deadline voor inschrijvingen is ingesteld en ligt niet na de startdatum van het kamp.', 'cjw') . '</p>'
        );
    }

    /**
     * Checks that a valid public contact e-mail address is configured.
     *
     * @return array<string, mixed>
     */
    public function testContactEmail(): array
    {
        $test = 'cjw_summer_camp_contact_email';
        $email = cjw_summer_camp()->getContactEmail();

        if ($email === '' || !is_email($email)) {
            return $this->result(
                $test,
                __('Het contact-e-mailadres ontbreekt of is ongeldig', 'cjw'),
                'recommended',
                '<p>' . esc_html__('Er is geen geldig contact-e-mailadres ingesteld. Bezoekers kunnen daardoor geen contact opnemen via de website.', 'cjw') . '</p>',
                $this->settingsAction('website')
            );
        }

        return $this->result(
            $test,
            __('Het contact-e-mailadres is ingesteld', 'cjw'),
            'good',
            '<p>' . esc_html(sprintf(
                /* translators: %s: configured contact e-mail address. */
                __('Het contact-e-mailadres is ingesteld: %s.', 'cjw'),
                $email
            )) . '</p>'
        );
    }

    /**
     * Checks that every published sponsor has a featured image (logo).
     *
     * @return array<string, mixed>
     */
    public function testSponsorLogos(): array
    {
        $test = 'cjw_summer_camp_sponsor_logos';

        if (!post_type_exists('sponsor')) {
            return $this->result(
                $test,
                __('Het sponsor-berichttype is niet actief', 'cjw'),
                'good',
                '<p>' . esc_html__('Het sponsor-berichttype is niet actief, dus er zijn geen sponsorlogo’s om te controleren.', 'cjw') . '</p>'
            );
        }

        $offenders = $this->publishedSponsorsWithoutLogo();

        if ($offenders === []) {
            return $this->result(
                $test,
                __('Alle gepubliceerde sponsoren hebben een logo', 'cjw'),
                'good',
                '<p>' . esc_html__('Alle gepubliceerde sponsoren hebben een logo (uitgelichte afbeelding) en worden op de website getoond.', 'cjw') . '</p>'
            );
        }

        $titles = array_map(
            static function (WP_Post $sponsor): string {
                $title = get_the_title($sponsor);

                return $title !== '' ? $title : __('(geen titel)', 'cjw');
            },
            $offenders
        );

        return $this->result(
            $test,
            __('Niet alle gepubliceerde sponsoren hebben een logo', 'cjw'),
            'recommended',
            '<p>' . esc_html__('De volgende gepubliceerde sponsoren hebben geen logo (uitgelichte afbeelding) en worden daarom niet op de website getoond:', 'cjw') . '</p>'
                . '<p><strong>' . esc_html(implode(', ', $titles)) . '</strong></p>',
            sprintf(
                '<p><a href="%s">%s</a></p>',
                esc_url(admin_url('edit.php?post_type=sponsor')),
                esc_html__('Beheer de sponsoren', 'cjw')
            )
        );
    }

    /**
     * Checks that at least one photo is chosen for the kampplakboek photo wall.
     *
     * @return array<string, mixed>
     */
    public function testPolaroids(): array
    {
        $test = 'cjw_summer_camp_polaroids';

        if (cjw_summer_camp()->getPolaroids() === []) {
            return $this->result(
                $test,
                __('Er zijn nog geen foto’s voor het kampplakboek gekozen', 'cjw'),
                'recommended',
                '<p>' . esc_html__('Er is nog geen enkele foto voor het kampplakboek op de homepage gekozen. Kies minimaal één foto om de fotomuur te vullen.', 'cjw') . '</p>',
                $this->settingsAction('website')
            );
        }

        return $this->result(
            $test,
            __('Het kampplakboek bevat foto’s', 'cjw'),
            'good',
            '<p>' . esc_html__('Er is minimaal één foto voor het kampplakboek op de homepage gekozen.', 'cjw') . '</p>'
        );
    }

    /**
     * Checks that somebody has established mail actually leaves this site.
     *
     * Deliberately reports on evidence rather than on configuration. A mail
     * plugin being installed proves nothing; a test message that went out last
     * month proves something. The failing case is graded critical because the
     * cost lands on a parent who registers, hears nothing, and concludes it did
     * not work.
     *
     * @return array<string, mixed>
     */
    public function testMail(): array
    {
        $test = 'cjw_summer_camp_mail';
        $last = CJW_Summer_Camp_Mail::lastTest();
        $configured = CJW_Summer_Camp_Mail::transportIsConfigured();

        if ($last !== null && !$last['ok']) {
            return $this->result(
                $test,
                __('De laatste testmail is niet aangekomen', 'cjw'),
                'critical',
                '<p>' . esc_html__('De website kon de laatste testmail niet versturen. Zolang dat zo is, krijgen ouders geen bevestiging van hun inschrijving en krijgt de organisatie geen bericht bij een nieuwe inschrijving.', 'cjw') . '</p>'
                . ($last['error'] !== '' ? '<p><code>' . esc_html($last['error']) . '</code></p>' : ''),
                $this->mailAction()
            );
        }

        if ($last === null) {
            return $this->result(
                $test,
                __('Er is nog nooit een testmail verstuurd', 'cjw'),
                'recommended',
                '<p>' . esc_html__('Niemand weet of deze website mail kan versturen. Dat blijkt pas als een ouder zich inschrijft en geen bevestiging krijgt. Stuur eenmalig een testmail om het zeker te weten.', 'cjw') . '</p>',
                $this->mailAction()
            );
        }

        if (!$configured) {
            return $this->result(
                $test,
                __('Mail wordt verstuurd door de webserver zelf', 'cjw'),
                'recommended',
                '<p>' . esc_html__('De testmail is gelukt, maar er is geen mailplugin actief. De website verstuurt mail dan rechtstreeks via de webserver, en grote mailproviders zoals Gmail en Outlook weigeren die mail vaak of zetten hem in de map ongewenste mail. Dat gaat vooral mis bij ouders, niet bij de organisatie.', 'cjw') . '</p>',
                $this->mailAction()
            );
        }

        if (CJW_Summer_Camp_Mail::lastTestIsStale()) {
            return $this->result(
                $test,
                __('De laatste testmail is lang geleden', 'cjw'),
                'recommended',
                '<p>' . esc_html(sprintf(
                    /* translators: %s: date of the last successful test. */
                    __('De laatste geslaagde testmail is van %s. Er is sindsdien een kampseizoen voorbij; test opnieuw voordat de inschrijvingen opengaan.', 'cjw'),
                    wp_date((string) get_option('date_format'), $last['time'])
                )) . '</p>',
                $this->mailAction()
            );
        }

        return $this->result(
            $test,
            __('De website kan mail versturen', 'cjw'),
            'good',
            '<p>' . esc_html(sprintf(
                /* translators: %s: date of the last successful test. */
                __('De laatste testmail is gelukt op %s, en er is een mailplugin actief die het versturen verzorgt.', 'cjw'),
                wp_date((string) get_option('date_format'), $last['time'])
            )) . '</p>'
        );
    }

    /**
     * Links to the Beheer tab, where the test button lives.
     *
     * @return string
     */
    private function mailAction(): string
    {
        return sprintf(
            '<p><a href="%s">%s</a></p>',
            esc_url(add_query_arg('tab', 'beheer', admin_url('admin.php?page=cjw-summer-camp'))),
            esc_html__('Stuur een testmail', 'cjw')
        );
    }

    /**
     * Checks that the site's scheduled jobs are still running.
     *
     * WP-Cron fires only when somebody visits, so an out-of-season camp site
     * can go days without one, and a host that disables it stops them for
     * good. The retention sweep is why this is critical rather than merely
     * recommended: it is the only thing that ever erases a child's details,
     * and its silence looks exactly like everything being fine.
     *
     * @return array<string, mixed>
     */
    public function testScheduledJobs(): array
    {
        $stale = CJW_Cron_Heartbeat::stale();

        if ($stale === []) {
            return $this->result(
                'cjw_summer_camp_cron',
                __('De geplande taken draaien', 'cjw'),
                'good',
                '<p>' . esc_html__(
                    'Het opschonen van oude inschrijvingen, de verjaardagsmail en het weekbericht zijn recent gedraaid.',
                    'cjw'
                ) . '</p>'
            );
        }

        $lines = [];

        foreach ($stale as $details) {
            $days = max(1, (int) floor($details['silent_for'] / DAY_IN_SECONDS));

            $lines[] = '<li>' . sprintf(
                /* translators: 1: job name, 2: number of days. */
                esc_html__('%1$s: al %2$d dagen niet gedraaid', 'cjw'),
                esc_html($details['label']),
                $days
            ) . '</li>';
        }

        return $this->result(
            'cjw_summer_camp_cron',
            __('Er staan geplande taken stil', 'cjw'),
            'critical',
            '<p>' . esc_html__('Deze geplande taken hebben te lang niet gedraaid:', 'cjw')
                . '</p><ul>' . implode('', $lines) . '</ul><p>'
                . esc_html__(
                    'Zolang het opschonen stilstaat, blijven gegevens van kinderen langer bewaard dan aan ouders is verteld. Vraag de host om WP-Cron te controleren of een echte cronjob in te stellen.',
                    'cjw'
                ) . '</p>'
        );
    }

    /**
     * Checks that the "Schrijf je in" buttons have somewhere to go.
     *
     * Every button on the site links to the page with the registration form;
     * when no such page can be found the theme hides them rather than link to
     * nowhere. A homepage without a signup button while registration is open
     * is the worst version of that, so that case is critical.
     *
     * @return array<string, mixed>
     */
    public function testRegistrationPage(): array
    {
        $test = 'cjw_summer_camp_registration_page';
        $camp = cjw_summer_camp();
        $url = $camp->getRegistrationPageUrl();

        if ($url !== '') {
            return $this->result(
                $test,
                __('De inschrijfknoppen linken naar het inschrijfformulier', 'cjw'),
                'good',
                '<p>' . sprintf(
                    /* translators: %s: page address. */
                    esc_html__('Elke “Schrijf je in”-knop op de website linkt naar %s.', 'cjw'),
                    '<code>' . esc_html($url) . '</code>'
                ) . '</p>'
            );
        }

        return $this->result(
            $test,
            __('De inschrijfknoppen wijzen nergens heen', 'cjw'),
            $camp->isRegistrationOpen() ? 'critical' : 'recommended',
            '<p>' . esc_html__(
                'Er is geen gepubliceerde pagina gevonden met het inschrijfformulier (de [cjw_wizard]-shortcode), en bij “Pagina met inschrijfformulier” is geen adres ingevuld. De website verbergt daarom elke “Schrijf je in”-knop.',
                'cjw'
            ) . '</p><p>' . esc_html__(
                'Publiceer de pagina met het formulier, of vul het adres in bij de zomerkamp-instellingen.',
                'cjw'
            ) . '</p>',
            $this->settingsAction('general')
        );
    }

    /**
     * Checks that every published form says which field is the parent's address.
     *
     * Without the role the plugin guesses: the first declared e-mail field, or
     * failing that the first value that looks like an address. On a form with
     * an emergency contact that guess decides who gets the confirmation and,
     * worse, whose data an AVG request returns.
     *
     * @return array<string, mixed>
     */
    public function testParentEmailRole(): array
    {
        $test = 'cjw_summer_camp_parent_email';

        if (!class_exists('CJW_Wizard_Field_Roles') || !class_exists('CJW_Wizard_Config')) {
            return $this->result(
                $test,
                __('De formulierbouwer is niet geladen', 'cjw'),
                'good',
                '<p>' . esc_html__('Er zijn geen formulieren om te controleren.', 'cjw') . '</p>'
            );
        }

        $unmarked = $this->formsWithoutParentEmail();

        if ($unmarked === []) {
            return $this->result(
                $test,
                __('Elk formulier weet welk veld het e-mailadres van de ouder is', 'cjw'),
                'good',
                '<p>' . esc_html__('Bevestigingen en AVG-verzoeken gaan naar het veld dat daarvoor is gemarkeerd.', 'cjw') . '</p>'
            );
        }

        $lines = [];

        foreach ($unmarked as $form) {
            $lines[] = sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url((string) get_edit_post_link($form->ID, 'raw')),
                esc_html($form->post_title !== '' ? $form->post_title : (string) $form->ID)
            );
        }

        return $this->result(
            $test,
            __('Een formulier weet niet welk veld het e-mailadres van de ouder is', 'cjw'),
            'recommended',
            '<p>' . esc_html__(
                'Bij deze formulieren is geen veld gemarkeerd met de betekenis “E-mailadres van de ouder”. De website raadt dan welk veld het is, en bij een formulier met een noodcontact kan die gok verkeerd uitvallen: de bevestiging en een AVG-verzoek gaan dan naar het verkeerde adres.',
                'cjw'
            ) . '</p><ul>' . implode('', $lines) . '</ul><p>' . esc_html__(
                'Open het formulier, kies bij het e-mailveld van de ouder de betekenis “E-mailadres van de ouder” en sla op.',
                'cjw'
            ) . '</p>'
        );
    }

    /**
     * Checks that a copy of this site would recognise itself.
     *
     * Until somebody confirms the live host, a restored backup is only caught
     * when the hosting party marks the environment -- and most do not.
     *
     * @return array<string, mixed>
     */
    public function testMailSafeMode(): array
    {
        $test = 'cjw_summer_camp_mail_safe_mode';

        if (CJW_Mail_Safe_Mode::isActive()) {
            return $this->result(
                $test,
                __('Mail-veiligmodus staat aan: dit is een kopie', 'cjw'),
                'good',
                '<p>' . esc_html(CJW_Mail_Safe_Mode::reason()) . ' '
                    . esc_html__('Mail gaat naar de beheerder in plaats van naar ouders.', 'cjw') . '</p>'
            );
        }

        if (CJW_Mail_Safe_Mode::isConfirmed()) {
            return $this->result(
                $test,
                __('Een kopie van deze website zou geen mail naar ouders sturen', 'cjw'),
                'good',
                '<p>' . sprintf(
                    /* translators: %s: host name. */
                    esc_html__('%s is bevestigd als de live website; een kopie op een ander adres herkent zichzelf.', 'cjw'),
                    '<code>' . esc_html(CJW_Mail_Safe_Mode::productionHost()) . '</code>'
                ) . '</p>'
            );
        }

        return $this->result(
            $test,
            __('Een kopie van deze website zou nog mail naar ouders sturen', 'cjw'),
            'recommended',
            '<p>' . esc_html__(
                'De live website is nog niet bevestigd. Een teruggezette kopie -- om een update te oefenen, bijvoorbeeld -- bevat echte inschrijvingen en zou verjaardagsmails en bevestigingen naar echte ouders sturen.',
                'cjw'
            ) . '</p><p>' . esc_html__('Klik op het tabblad Beheer op “Dit is de live website”.', 'cjw') . '</p>',
            $this->settingsAction('beheer')
        );
    }

    /**
     * Published forms with no field carrying the parent e-mail role.
     *
     * @return WP_Post[]
     */
    private function formsWithoutParentEmail(): array
    {
        $forms = get_posts([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        return array_values(array_filter($forms, static function ($form): bool {
            return $form instanceof WP_Post
                && CJW_Wizard_Field_Roles::pathFor(CJW_Wizard_Config::get((int) $form->ID), CJW_Wizard_Field_Roles::PARENT_EMAIL) === '';
        }));
    }

    /**
     * Builds a Site Health test result in the required shape.
     *
     * @param string $test        Test identifier.
     * @param string $label       Result label.
     * @param string $status      Result status: good, recommended or critical.
     * @param string $description Result description (HTML).
     * @param string $actions     Optional actions markup (HTML).
     *
     * @return array<string, mixed>
     */
    private function result(string $test, string $label, string $status, string $description, string $actions = ''): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => [
                'label' => __('CJW Zomerkamp', 'cjw'),
                'color' => 'blue',
            ],
            'description' => $description,
            'actions' => $actions,
            'test' => $test,
        ];
    }

    /**
     * Builds the default action markup linking to the settings page.
     *
     * @param string $tab Optional settings tab.
     *
     * @return string
     */
    private function settingsAction(string $tab = ''): string
    {
        $url = admin_url('admin.php?page=cjw-summer-camp');

        if ($tab !== '') {
            $url = add_query_arg('tab', $tab, $url);
        }

        return sprintf(
            '<p><a href="%s">%s</a></p>',
            esc_url($url),
            esc_html__('Open de zomerkamp-instellingen', 'cjw')
        );
    }

    /**
     * Returns the published sponsors without a featured image.
     *
     * @return WP_Post[]
     */
    private function publishedSponsorsWithoutLogo(): array
    {
        return get_posts([
            'post_type' => 'sponsor',
            'post_status' => 'publish',
            'numberposts' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only check on a small sponsor set.
            'meta_query' => [
                [
                    'key' => '_thumbnail_id',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);
    }
}
