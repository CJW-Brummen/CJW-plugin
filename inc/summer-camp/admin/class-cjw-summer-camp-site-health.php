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
     *
     * @return array
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

        return $tests;
    }

    /**
     * Checks that both camp dates are set and the end date is not before the start date.
     *
     * @return array
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
     * @return array
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
     * @return array
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
     * @return array
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
     * @return array
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
     * Builds a Site Health test result in the required shape.
     *
     * @param string $test        Test identifier.
     * @param string $label       Result label.
     * @param string $status      Result status: good, recommended or critical.
     * @param string $description Result description (HTML).
     * @param string $actions     Optional actions markup (HTML).
     *
     * @return array
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
