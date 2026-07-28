<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds contextual help tabs to the summer-camp admin screens.
 */
final class CJW_Summer_Camp_Help_Tabs
{
    /**
     * Screen IDs of the plugin settings page (top-level menu and legacy options page).
     */
    private const array SETTINGS_SCREEN_IDS = [
        'toplevel_page_cjw-summer-camp',
        'settings_page_cjw-summer-camp',
    ];

    /**
     * Registers the help-tab hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('current_screen', [new self(), 'register']);
    }

    /**
     * Adds the help tabs for the current admin screen.
     *
     * @param mixed $screen Current screen.
     *
     * @return void
     */
    public function register(mixed $screen): void
    {
        if (!$screen instanceof WP_Screen) {
            return;
        }

        if (in_array($screen->id, self::SETTINGS_SCREEN_IDS, true)) {
            $this->addSettingsHelp($screen);

            return;
        }

        if ($screen->id === 'edit-sponsor') {
            $this->addSponsorHelp($screen);

            return;
        }

        if ($screen->id === 'edit-cjw_wizard') {
            $this->addWizardHelp($screen);
        }
    }

    /**
     * Adds help tabs to the plugin settings screen.
     *
     * @param WP_Screen $screen Settings screen.
     *
     * @return void
     */
    private function addSettingsHelp(WP_Screen $screen): void
    {
        $screen->add_help_tab([
            'id' => 'cjw-summer-camp-overview',
            'title' => __('Snel aan de slag', 'cjw'),
            'content' => '<p>' . esc_html__('Op deze pagina beheer je alle instellingen voor het zomerkamp. De instellingen zijn verdeeld over vijf tabbladen.', 'cjw') . '</p>'
                . '<ol>'
                . '<li>' . esc_html__('Vul bij “Algemeen” de kampdata en de inschrijfdeadline in en zet de inschrijvingen open.', 'cjw') . '</li>'
                . '<li>' . esc_html__('Stel bij “Thema” het jaarthema, de hero en de kleuren van de website in.', 'cjw') . '</li>'
                . '<li>' . esc_html__('Controleer bij “Tarieven” de prijzen en de vroegboekkorting.', 'cjw') . '</li>'
                . '<li>' . esc_html__('Schrijf bij “Inhoud” de introductietekst en de verjaardagsmail.', 'cjw') . '</li>'
                . '<li>' . esc_html__('Vul bij “Website” de teksten, foto’s en contactgegevens voor de homepage in.', 'cjw') . '</li>'
                . '</ol>'
                . '<p>' . esc_html__('Sla je wijzigingen op met “Wijzigingen opslaan” voordat je naar een ander tabblad gaat.', 'cjw') . '</p>',
        ]);

        $screen->add_help_tab([
            'id' => 'cjw-summer-camp-general',
            'title' => __('Algemeen', 'cjw'),
            'content' => '<p>' . esc_html__('Op het tabblad “Algemeen” stel je de start- en einddatum van het kamp in, de deadline voor inschrijvingen en of de inschrijvingen open staan.', 'cjw') . '</p>'
                . '<p>' . esc_html__('Je vindt hier ook de tekst die bezoekers zien wanneer de inschrijvingen gesloten zijn en de unieke link waarmee iemand zich alsnog laat kan inschrijven.', 'cjw') . '</p>',
        ]);

        $screen->add_help_tab([
            'id' => 'cjw-summer-camp-theme',
            'title' => __('Thema', 'cjw'),
            'content' => '<p>' . esc_html__('Op het tabblad “Thema” stel je het themajaar en de themanaam in, plus de grote foto bovenaan de homepage (de hero) met titel, ondertitel en knoppen.', 'cjw') . '</p>'
                . '<p>' . esc_html__('Ook kies je hier de kleuren die de website gebruikt, zodat de site elk jaar bij het nieuwe kampthema past.', 'cjw') . '</p>',
        ]);

        $screen->add_help_tab([
            'id' => 'cjw-summer-camp-pricing',
            'title' => __('Tarieven', 'cjw'),
            'content' => '<p>' . esc_html__('Op het tabblad “Tarieven” beheer je de prijzen voor een hele week en een weekend.', 'cjw') . '</p>'
                . '<p>' . esc_html__('Daarnaast stel je hier de periode en de prijzen van de vroegboekkorting in. Binnen die periode rekent de website automatisch de lagere prijzen.', 'cjw') . '</p>',
        ]);

        $screen->add_help_tab([
            'id' => 'cjw-summer-camp-content',
            'title' => __('Inhoud', 'cjw'),
            'content' => '<p>' . esc_html__('Op het tabblad “Inhoud” schrijf je de introductietekst die bij het inschrijfformulier wordt getoond.', 'cjw') . '</p>'
                . '<p>' . esc_html__('Ook zet je hier de automatische verjaardagsmail aan of uit en bepaal je de tekst van die mail.', 'cjw') . '</p>',
        ]);

        $screen->add_help_tab([
            'id' => 'cjw-summer-camp-website',
            'title' => __('Website', 'cjw'),
            'content' => '<p>' . esc_html__('Op het tabblad “Website” vul je de inhoud van de homepage in: de omschrijving van het jaarthema, de introductiefoto, de pagina’s achter de kaarten en de foto’s met bijschriften voor het kampplakboek.', 'cjw') . '</p>'
                . '<p>' . esc_html__('Ook stel je hier het contact-e-mailadres en de links naar Facebook en Instagram in; deze worden onder andere in de footer van de website getoond.', 'cjw') . '</p>',
        ]);

        $this->addSidebar($screen);
    }

    /**
     * Adds the help tab to the sponsor list screen.
     *
     * @param WP_Screen $screen Sponsor list screen.
     *
     * @return void
     */
    private function addSponsorHelp(WP_Screen $screen): void
    {
        $screen->add_help_tab([
            'id' => 'cjw-summer-camp-sponsors',
            'title' => __('Sponsoren beheren', 'cjw'),
            'content' => '<p>' . esc_html__('Zo voeg je een sponsor toe aan de website:', 'cjw') . '</p>'
                . '<ol>'
                . '<li>' . esc_html__('Klik op “Nieuwe sponsor” en gebruik de bedrijfsnaam als titel.', 'cjw') . '</li>'
                . '<li>' . esc_html__('Stel het logo in als uitgelichte afbeelding. Dit is verplicht: zonder logo wordt de sponsor NIET op de website getoond.', 'cjw') . '</li>'
                . '<li>' . esc_html__('Vul de website van de sponsor in via het veld “Sponsor link”.', 'cjw') . '</li>'
                . '<li>' . esc_html__('Bepaal de volgorde op de website via het volgordeveld in het vak “Attributen” (een lager nummer komt eerder).', 'cjw') . '</li>'
                . '</ol>',
        ]);

        $this->addSidebar($screen);
    }

    /**
     * Adds the help tab to the wizard forms list screen.
     *
     * @param WP_Screen $screen Wizard list screen.
     *
     * @return void
     */
    private function addWizardHelp(WP_Screen $screen): void
    {
        $screen->add_help_tab([
            'id' => 'cjw-summer-camp-forms',
            'title' => __('Formulieren en inzendingen', 'cjw'),
            'content' => '<p>' . esc_html__('Hier beheer je de inschrijfformulieren van het zomerkamp. Publiceer een formulier om het op de website te kunnen gebruiken.', 'cjw') . '</p>'
                . '<p>' . esc_html__('Binnengekomen inschrijvingen vind je onder “Inzendingen” in dit menu. Daar bekijk je elke inzending en exporteer je alle inzendingen als CSV-bestand.', 'cjw') . '</p>',
        ]);

        $this->addSidebar($screen);
    }

    /**
     * Sets the shared help sidebar with a link to the settings page.
     *
     * @param WP_Screen $screen Current screen.
     *
     * @return void
     */
    private function addSidebar(WP_Screen $screen): void
    {
        $screen->set_help_sidebar(
            '<p><strong>' . esc_html__('Meer hulp', 'cjw') . '</strong></p>'
                . '<p><a href="' . esc_url(admin_url('admin.php?page=cjw-summer-camp')) . '">' . esc_html__('Zomerkamp-instellingen', 'cjw') . '</a></p>'
        );
    }
}
