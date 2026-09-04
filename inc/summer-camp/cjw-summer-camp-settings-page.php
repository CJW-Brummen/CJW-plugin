<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/fields/cjw_field.php';
require_once __DIR__ . '/fields/cjw_input_field.php';
require_once __DIR__ . '/fields/cjw_date_field.php';
require_once __DIR__ . '/fields/cjw_checkbox_field.php';
require_once __DIR__ . '/fields/cjw_color_field.php';
require_once __DIR__ . '/fields/cjw_media_field.php';
require_once __DIR__ . '/fields/cjw_late_registration_link_field.php';
require_once __DIR__ . '/fields/cjw_wysiwyg_field.php';
require_once __DIR__ . '/fields/cjw_repeater_field.php';
require_once __DIR__ . '/fields/cjw_page_field.php';

/**
 * Handles the administrative settings page for the Summer Camp.
 *
 * Managed the registration of settings, sections, and fields, as well as the
 * rendering of the tabbed admin interface.
 */
class CJW_Summer_Camp_Settings_Page
{
    /**
     * Admin page hook suffix used by WordPress for this settings screen.
     */
    public const string ADMIN_HOOK = 'toplevel_page_cjw-summer-camp';

    /**
     * The top-level WordPress option key.
     */
    private const string OPTION_KEY = CJW_Summer_Camp_Service::OPTION_KEY;

    /**
     * The page slug used for the options-page.
     */
    private const string PAGE_SLUG = 'cjw-summer-camp';

    /**
     * Settings fields grouped by tab. Used to preserve inactive tab values.
     */
    private const array TAB_FIELDS = [
        'general' => [
            'start_date',
            'end_date',
            'camp_age_range',
            'camp_location',
            'drive_dates',
            'registration_deadline',
            'registration_closed_text',
            'registration_active',
            'retention_months',
            'late_registration_link',
            'late_registration_token',
        ],
        'theme' => [
            'theme_year',
            'theme_name',
            'hero_title',
            'hero_subtitle',
            'hero_image_id',
            'hero_image_alt',
            'hero_primary_cta_label',
            'hero_primary_cta_url',
            'hero_secondary_cta_label',
            'hero_secondary_cta_url',
            'theme_primary_color',
            'theme_secondary_color',
            'theme_accent_color',
            'hero_text_color',
            'hero_overlay_opacity',
        ],
        'pricing' => [
            'early_bird_start',
            'early_bird_end',
            'price_week_early',
            'price_weekend_early',
            'price_week',
            'price_weekend',
        ],
        'content' => [
            'intro_text',
            'privacy_notice_text',
            'birthday_mail_enabled',
        ],
        'website' => [
            'theme_description',
            'home_intro_title',
            'home_intro_text',
            'intro_image_id',
            'card_zomerkamp_title',
            'card_zomerkamp_text',
            'card_zomerkamp_page_id',
            'card_info_title',
            'card_info_text',
            'card_info_page_id',
            'card_signup_title',
            'card_signup_text',
            'photos_title',
            'photos_lead',
            'polaroid_1_image_id',
            'polaroid_1_caption',
            'polaroid_2_image_id',
            'polaroid_2_caption',
            'polaroid_3_image_id',
            'polaroid_3_caption',
            'polaroid_4_image_id',
            'polaroid_4_caption',
            'polaroid_5_image_id',
            'polaroid_5_caption',
            'sponsors_title',
            'sponsors_lead',
            'sponsors_cta_text',
            'cta_title',
            'cta_text',
            'contact_email',
            'social_facebook',
            'social_instagram',
            'footer_about',
            'footer_org',
            'support_contact',
        ],
    ];

    /**
     * Initializes the settings page within the WordPress administrative lifecycle.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);

        // Editors lack manage_options, so options.php must accept edit_pages for this group.
        add_filter('option_page_capability_cjw_group', static fn(): string => CJW_Roles::CAPABILITY);
    }

    /**
     * Registers the top-level admin menu page.
     *
     * @return void
     */
    public function menu(): void
    {
        add_menu_page(
            __('Zomerkamp instellingen', 'cjw'),
            __('Zomerkamp', 'cjw'),
            CJW_Roles::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-palmtree',
            25
        );
    }

    /**
     * Registers the setting, sections, and fields for the settings page.
     *
     * @return void
     */
    public function register(): void
    {
        register_setting(
            'cjw_group',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'validateAndSanitize'],
            ]
        );

        add_settings_section(
            'general',
            __('Algemeen', 'cjw'),
            $this->sectionIntro(__('Stel hier de kampdata, de locatie en de inschrijvingen voor het zomerkamp in.', 'cjw')),
            self::PAGE_SLUG . '-general'
        );
        add_settings_section(
            'theme',
            __('Thema', 'cjw'),
            $this->sectionIntro(
                __('Bepaal hier het jaarthema en de uitstraling van de grote banner (hero) bovenaan de homepage.', 'cjw'),
                [CJW_Photo_Consent_Notice::class, 'render']
            ),
            self::PAGE_SLUG . '-theme'
        );
        add_settings_section(
            'pricing',
            __('Tarieven', 'cjw'),
            $this->sectionIntro(__('Beheer hier de kampprijzen en de periode van de vroegboekkorting.', 'cjw')),
            self::PAGE_SLUG . '-pricing'
        );
        add_settings_section(
            'content',
            __('Inhoud', 'cjw'),
            $this->sectionIntro(__('Teksten die worden gebruikt bij het inschrijfformulier en in e-mails naar ouders.', 'cjw')),
            self::PAGE_SLUG . '-content'
        );

        // The website tab is split into themed sections; they all render on the same tab page.
        $website_page = self::PAGE_SLUG . '-website';

        add_settings_section(
            'website_jaarthema',
            __('Jaarthema', 'cjw'),
            $this->sectionIntro(__('Vertel bezoekers waar het kampthema van dit jaar over gaat; deze omschrijving verschijnt in het themablok op de homepage.', 'cjw')),
            $website_page
        );
        add_settings_section(
            'website_intro',
            __('Introductie homepage', 'cjw'),
            $this->sectionIntro(
                __('Het welkomstblok dat bezoekers direct onder de grote banner op de homepage zien.', 'cjw'),
                [CJW_Photo_Consent_Notice::class, 'render']
            ),
            $website_page
        );
        add_settings_section(
            'website_cards',
            __('Kaarten homepage', 'cjw'),
            $this->sectionIntro(__('De drie klikbare kaarten op de homepage die bezoekers naar de belangrijkste onderdelen van de website leiden.', 'cjw')),
            $website_page
        );
        add_settings_section(
            'website_photos',
            __('Kampplakboek', 'cjw'),
            $this->sectionIntro(
                __('Het fotoblok in plakboekstijl op de homepage, met maximaal vijf polaroidfoto’s van eerdere kampen.', 'cjw'),
                [CJW_Photo_Consent_Notice::class, 'render']
            ),
            $website_page
        );
        add_settings_section(
            'website_sponsors',
            __('Sponsors', 'cjw'),
            $this->sectionIntro(__('Het blok op de homepage waarin je sponsors bedankt en nieuwe sponsors werft.', 'cjw')),
            $website_page
        );
        add_settings_section(
            'website_cta',
            __('Oproep-banner', 'cjw'),
            $this->sectionIntro(__('De opvallende gekleurde banner onderaan de homepage die bezoekers aanspoort om in te schrijven.', 'cjw')),
            $website_page
        );
        add_settings_section(
            'website_contact',
            __('Contact & social', 'cjw'),
            $this->sectionIntro(__('Contactgegevens, social media en de teksten in de voettekst (footer) onderaan elke pagina.', 'cjw')),
            $website_page
        );

        $this->fields();
    }

    /**
     * The help text under the privacy field, with the tokens spelled out.
     *
     * @return string
     */
    private function privacyNoticeDescription(): string
    {
        $tokens = [];

        foreach (CJW_Privacy_Notice::tokens() as $token => $meaning) {
            $tokens[] = $token . ' — ' . $meaning;
        }

        return __('Deze tekst staat op de laatste stap van het inschrijfformulier, boven het vinkje waarmee de ouder akkoord gaat. Laat leeg om de standaardtekst te gebruiken.', 'cjw')
            . ' ' . __('Woorden tussen accolades vult de website zelf in, zodat de tekst klopt ook als je later iets aan de instellingen verandert:', 'cjw')
            . ' ' . implode('; ', $tokens) . '.';
    }

    /**
     * Creates a section callback that prints one guiding sentence.
     *
     * @param string        $text  The intro sentence.
     * @param callable|null $after Optional producer of extra HTML under the
     *                             sentence, called only when the section is
     *                             actually rendered so it may hit the database.
     *
     * @return Closure
     */
    private function sectionIntro(string $text, ?callable $after = null): Closure
    {
        return static function () use ($text, $after): void {
            echo '<p>' . esc_html($text) . '</p>';

            if ($after !== null) {
                echo wp_kses_post((string) $after());
            }
        };
    }

    /**
     * Defines and adds individual fields to the settings sections.
     *
     * @return void
     */
    private function fields(): void
    {
        // Section: General
        $this->add(
            'start_date',
            __('Start zomerkamp', 'cjw'),
            new CJW_Date_Field('start_date', '', self::OPTION_KEY, __('Eerste dag van het zomerkamp. Formaat: dd-mm-jjjj, bijvoorbeeld 11-07-2026. Verschijnt onder andere bovenaan de homepage en op de inschrijfpagina.', 'cjw')),
            'general'
        );
        $this->add(
            'end_date',
            __('Eind zomerkamp', 'cjw'),
            new CJW_Date_Field('end_date', '', self::OPTION_KEY, __('Laatste dag van het zomerkamp. Formaat: dd-mm-jjjj. Moet na de startdatum liggen.', 'cjw')),
            'general'
        );
        $this->add(
            'camp_age_range',
            __('Leeftijden', 'cjw'),
            new CJW_Input_Field('camp_age_range', '', self::OPTION_KEY, description: __('Voor welke leeftijden het kamp bedoeld is, bijvoorbeeld “6 t/m 17 jaar”. Verschijnt bij de kampgegevens op de website.', 'cjw')),
            'general'
        );
        $this->add(
            'camp_location',
            __('Locatie', 'cjw'),
            new CJW_Input_Field('camp_location', '', self::OPTION_KEY, description: __('Naam en plaats van de kamplocatie, bijvoorbeeld “Landgoed Brockhausen, Stokkum”. Verschijnt bij de kampgegevens op de website.', 'cjw')),
            'general'
        );
        $this->add(
            'drive_dates',
            __('Rijdata', 'cjw'),
            new CJW_Repeater_Field('drive_dates', '', self::OPTION_KEY, description: __('Data waarop kinderen gebracht en opgehaald worden. Vul per rij een datum (dd-mm-jjjj) en een korte omschrijving in, bijvoorbeeld “Heenreis week”. Klik op “Voeg toe” voor een extra rij en op “Verwijder” om een rij te wissen.', 'cjw')),
            'general'
        );
        $this->add(
            'registration_deadline',
            __('Deadline inschrijvingen', 'cjw'),
            new CJW_Date_Field('registration_deadline', '', self::OPTION_KEY, __('Laatste dag waarop ouders kunnen inschrijven. Formaat: dd-mm-jjjj. Na deze datum sluit het inschrijfformulier automatisch.', 'cjw')),
            'general'
        );
        $this->add(
            'registration_closed_text',
            __('Inschrijvingen gesloten tekst', 'cjw'),
            new CJW_Wysiwyg_Field('registration_closed_text', '', self::OPTION_KEY, __('Deze tekst zien bezoekers op de inschrijfpagina zodra de inschrijvingen gesloten zijn. Leg bijvoorbeeld uit wanneer de volgende inschrijfronde start of hoe ouders contact kunnen opnemen.', 'cjw')),
            'general'
        );
        $this->add(
            'registration_active',
            __('Inschrijvingen open', 'cjw'),
            new CJW_Checkbox_Field('registration_active', '', self::OPTION_KEY, __('Vink aan om de inschrijvingen te openen. Haal het vinkje weg om de inschrijvingen direct te sluiten, ook vóór de deadline.', 'cjw')),
            'general'
        );
        $this->add(
            'retention_months',
            __('Inschrijvingen bewaren (maanden)', 'cjw'),
            new CJW_Input_Field(
                'retention_months',
                '',
                self::OPTION_KEY,
                'number',
                description: __('Aantal maanden ná de einddatum van het kamp dat inschrijvingen bewaard blijven. Daarna worden de persoonsgegevens automatisch gewist; het aantal inschrijvingen per jaar blijft bewaard. Inschrijvingen bevatten gegevens van kinderen, dus bewaar ze niet langer dan nodig. Standaard 12 maanden, net tot vóór het volgende kamp. Vul 0 in om nooit automatisch te wissen.', 'cjw')
            ),
            'general'
        );
        $this->add(
            'late_registration_link',
            __('Pagina met inschrijfformulier', 'cjw'),
            new CJW_Input_Field('late_registration_link', '', self::OPTION_KEY, 'url', description: __('Volledig webadres van de pagina met het inschrijfformulier, beginnend met https://. Elke “Schrijf je in”-knop op de website linkt hierheen, en de unieke late-inschrijflink hieronder wordt ervan gemaakt. Laat leeg om de website zelf de gepubliceerde pagina met het formulier te laten vinden.', 'cjw')),
            'general'
        );
        $this->add(
            'late_registration_token',
            __('Unieke late-inschrijving link', 'cjw'),
            new CJW_Late_Registration_Link_Field('late_registration_token', '', self::OPTION_KEY, description: __('Vink “Maak een nieuwe unieke link bij opslaan” aan als de huidige link niet meer gebruikt mag worden; de oude link werkt daarna niet meer.', 'cjw')),
            'general'
        );

        // Section: Theme
        $this->add(
            'theme_year',
            __('Themajaar', 'cjw'),
            new CJW_Input_Field('theme_year', '', self::OPTION_KEY, 'number', description: __('Het jaar van dit kamp, bijvoorbeeld 2026. Wordt op meerdere plekken op de website getoond, onder andere in de inschrijfkaart op de homepage.', 'cjw')),
            'theme'
        );
        $this->add(
            'theme_name',
            __('Themanaam', 'cjw'),
            new CJW_Input_Field('theme_name', '', self::OPTION_KEY, description: __('De naam van het kampthema van dit jaar, bijvoorbeeld “Jungle Avontuur”. Verschijnt onder andere in het themablok op de homepage.', 'cjw')),
            'theme'
        );
        $this->add(
            'hero_title',
            __('Hero titel', 'cjw'),
            new CJW_Input_Field('hero_title', '', self::OPTION_KEY, description: __('Grote titel in de banner (hero) bovenaan de homepage. Verschijnt groot over de hero-afbeelding heen.', 'cjw')),
            'theme'
        );
        $this->add(
            'hero_subtitle',
            __('Hero subtitel', 'cjw'),
            new CJW_Input_Field('hero_subtitle', '', self::OPTION_KEY, description: __('Korte ondertitel onder de hero-titel, bijvoorbeeld een slogan van één zin. Laat leeg om geen ondertitel te tonen.', 'cjw')),
            'theme'
        );
        $this->add(
            'hero_image_id',
            __('Hero afbeelding', 'cjw'),
            new CJW_Media_Field(
                'hero_image_id',
                '',
                self::OPTION_KEY,
                __('Grote sfeerfoto achter de tekst bovenaan de homepage. Kies een liggende foto van goede kwaliteit (minimaal 1600 pixels breed werkt het best).', 'cjw'),
                recommendedWidth: 2000,
                recommendedHeight: 1125,
                maxBytes: 800 * KB_IN_BYTES
            ),
            'theme'
        );
        $this->add(
            'hero_image_alt',
            __('Alt-tekst hero afbeelding', 'cjw'),
            new CJW_Input_Field('hero_image_alt', '', self::OPTION_KEY, description: __('Korte omschrijving van de hero-afbeelding voor blinde en slechtziende bezoekers en voor zoekmachines, bijvoorbeeld “Kinderen spelen bij het kampvuur”.', 'cjw')),
            'theme'
        );
        $this->add(
            'hero_primary_cta_label',
            __('Primaire knop tekst', 'cjw'),
            new CJW_Input_Field('hero_primary_cta_label', '', self::OPTION_KEY, description: __('Tekst op de opvallende (eerste) knop in de banner, bijvoorbeeld “Schrijf je in”. Laat leeg om deze knop te verbergen.', 'cjw')),
            'theme'
        );
        $this->add(
            'hero_primary_cta_url',
            __('Primaire knop link', 'cjw'),
            new CJW_Input_Field('hero_primary_cta_url', '', self::OPTION_KEY, 'url', description: __('Webadres waar de eerste knop naartoe linkt. Laat leeg om naar de pagina met het inschrijfformulier te linken; vul alleen iets in als de knop ergens anders heen moet, beginnend met https://.', 'cjw')),
            'theme'
        );
        $this->add(
            'hero_secondary_cta_label',
            __('Secundaire knop tekst', 'cjw'),
            new CJW_Input_Field('hero_secondary_cta_label', '', self::OPTION_KEY, description: __('Tekst op de tweede (minder opvallende) knop in de banner. Laat leeg om deze knop te verbergen.', 'cjw')),
            'theme'
        );
        $this->add(
            'hero_secondary_cta_url',
            __('Secundaire knop link', 'cjw'),
            new CJW_Input_Field('hero_secondary_cta_url', '', self::OPTION_KEY, 'url', description: __('Webadres waar de tweede knop naartoe linkt. Begin met https://.', 'cjw')),
            'theme'
        );
        $this->add(
            'theme_primary_color',
            __('Primaire kleur', 'cjw'),
            new CJW_Color_Field('theme_primary_color', '', self::OPTION_KEY, '#588C7E', __('Hoofdkleur van het thema; wordt gebruikt voor knoppen en accenten op de hele website. Klik op het kleurvak om een andere kleur te kiezen.', 'cjw')),
            'theme'
        );
        $this->add(
            'theme_secondary_color',
            __('Secundaire kleur', 'cjw'),
            new CJW_Color_Field('theme_secondary_color', '', self::OPTION_KEY, '#F0AE73', __('Tweede themakleur; wordt gebruikt voor achtergronden en decoraties.', 'cjw')),
            'theme'
        );
        $this->add(
            'theme_accent_color',
            __('Accentkleur', 'cjw'),
            new CJW_Color_Field('theme_accent_color', '', self::OPTION_KEY, '#ED1C24', __('Opvallende accentkleur voor kleine details, zoals markeringen en linkjes.', 'cjw')),
            'theme'
        );
        $this->add(
            'hero_text_color',
            __('Hero tekstkleur', 'cjw'),
            new CJW_Color_Field('hero_text_color', '', self::OPTION_KEY, '#FFFFFF', __('Kleur van de tekst in de banner. Kies wit bij een donkere foto en een donkere kleur bij een lichte foto, zodat de tekst goed leesbaar blijft.', 'cjw')),
            'theme'
        );
        $this->add(
            'hero_overlay_opacity',
            __('Hero afbeelding overlay (%)', 'cjw'),
            new CJW_Input_Field('hero_overlay_opacity', '', self::OPTION_KEY, 'number', description: __('Donkere waas over de hero-afbeelding zodat de tekst beter leesbaar wordt. Van 0 tot 90%: 0 = geen waas, 90 = bijna zwart.', 'cjw')),
            'theme'
        );

        // Section: Pricing
        $this->add(
            'early_bird_start',
            __('Vroegboekkorting startdatum', 'cjw'),
            new CJW_Date_Field('early_bird_start', '', self::OPTION_KEY, __('Eerste dag waarop de vroegboekkorting geldt. Formaat: dd-mm-jjjj.', 'cjw')),
            'pricing'
        );
        $this->add(
            'early_bird_end',
            __('Vroegboekkorting einddatum', 'cjw'),
            new CJW_Date_Field('early_bird_end', '', self::OPTION_KEY, __('Laatste dag van de vroegboekkorting. Formaat: dd-mm-jjjj. Moet na de startdatum liggen; daarna gelden automatisch de normale prijzen.', 'cjw')),
            'pricing'
        );
        $this->add(
            'price_week_early',
            __('Prijs week vroegboekkorting', 'cjw'),
            new CJW_Input_Field('price_week_early', '', self::OPTION_KEY, 'number', description: __('Prijs voor een hele week mét vroegboekkorting. In euro’s, zonder €-teken, bijvoorbeeld 95.', 'cjw')),
            'pricing'
        );
        $this->add(
            'price_weekend_early',
            __('Prijs weekend vroegboekkorting', 'cjw'),
            new CJW_Input_Field('price_weekend_early', '', self::OPTION_KEY, 'number', description: __('Prijs voor het weekend mét vroegboekkorting. In euro’s, zonder €-teken.', 'cjw')),
            'pricing'
        );
        $this->add(
            'price_week',
            __('Prijs week', 'cjw'),
            new CJW_Input_Field('price_week', '', self::OPTION_KEY, 'number', description: __('Normale prijs voor een hele week. In euro’s, zonder €-teken.', 'cjw')),
            'pricing'
        );
        $this->add(
            'price_weekend',
            __('Prijs weekend', 'cjw'),
            new CJW_Input_Field('price_weekend', '', self::OPTION_KEY, 'number', description: __('Normale prijs voor het weekend. In euro’s, zonder €-teken.', 'cjw')),
            'pricing'
        );

        // Section: Content
        $this->add(
            'intro_text',
            __('Introductietekst', 'cjw'),
            new CJW_Wysiwyg_Field('intro_text', '', self::OPTION_KEY, __('Welkomsttekst die bovenaan het inschrijfformulier wordt getoond. Vertel kort wat ouders kunnen verwachten. Laat leeg om geen introductietekst te tonen.', 'cjw')),
            'content'
        );
        $this->add(
            'privacy_notice_text',
            __('Privacytekst op het formulier', 'cjw'),
            new CJW_Wysiwyg_Field(
                'privacy_notice_text',
                '',
                self::OPTION_KEY,
                $this->privacyNoticeDescription()
            ),
            'content'
        );
        $this->add(
            'birthday_mail_enabled',
            __('Verjaardagsmail actief', 'cjw'),
            new CJW_Checkbox_Field('birthday_mail_enabled', '', self::OPTION_KEY, __('Stuurt op de verjaardag van een ingeschreven kind een felicitatie naar het e-mailadres waarmee is ingeschreven. Alleen voor kinderen die dit kampjaar meegaan, en alleen zolang de inschrijving nog bewaard wordt. De tekst schrijf je bij Zomerkamp → Mails.', 'cjw')),
            'content'
        );

        // Tab: Website (consumed by the cjw-theme front page and footer)
        // Section: Jaarthema
        $this->add(
            'theme_description',
            __('Omschrijving jaarthema', 'cjw'),
            new CJW_Wysiwyg_Field('theme_description', '', self::OPTION_KEY, __('Uitgebreide omschrijving van het jaarthema. Verschijnt in het themablok op de homepage, onder de banner. Laat leeg om het themablok zonder omschrijving te tonen.', 'cjw')),
            'website',
            'website_jaarthema'
        );

        // Section: Introductie homepage
        $this->add(
            'home_intro_title',
            __('Titel introductie', 'cjw'),
            new CJW_Input_Field('home_intro_title', '', self::OPTION_KEY, description: __('Titel van het welkomstblok op de homepage. Tekst na een | krijgt de handgetekende onderstreping, bijvoorbeeld “Welkom bij |CJW Zomerkampen”.', 'cjw')),
            'website',
            'website_intro'
        );
        $this->add(
            'home_intro_text',
            __('Tekst introductie', 'cjw'),
            new CJW_Wysiwyg_Field('home_intro_text', '', self::OPTION_KEY, __('Welkomsttekst van de homepage. Vertel in een paar zinnen wat de CJW-zomerkampen zijn en voor wie ze bedoeld zijn.', 'cjw')),
            'website',
            'website_intro'
        );
        $this->add(
            'intro_image_id',
            __('Introductiefoto homepage', 'cjw'),
            new CJW_Media_Field(
                'intro_image_id',
                '',
                self::OPTION_KEY,
                __('Sfeerfoto naast de welkomsttekst op de homepage. Laat leeg om geen foto te tonen.', 'cjw'),
                // The theme serves this one at WordPress's "large" size.
                recommendedWidth: 1200,
                recommendedHeight: 900,
                maxBytes: 400 * KB_IN_BYTES
            ),
            'website',
            'website_intro'
        );

        // Section: Kaarten homepage
        $this->add(
            'card_zomerkamp_title',
            __('Titel kaart “Zomerkamp”', 'cjw'),
            new CJW_Input_Field('card_zomerkamp_title', '', self::OPTION_KEY, description: __('Titel van de kaart over het zomerkamp. Tekst na een | krijgt de handgetekende onderstreping, bijvoorbeeld “Het |zomerkamp”.', 'cjw')),
            'website',
            'website_cards'
        );
        $this->add(
            'card_zomerkamp_text',
            __('Tekst kaart “Zomerkamp”', 'cjw'),
            new CJW_Input_Field('card_zomerkamp_text', '', self::OPTION_KEY, description: __('Korte tekst (één à twee zinnen) op de kaart over het zomerkamp.', 'cjw')),
            'website',
            'website_cards'
        );
        $this->add(
            'card_zomerkamp_page_id',
            __('Pagina kaart “Zomerkamp”', 'cjw'),
            new CJW_Page_Field('card_zomerkamp_page_id', '', self::OPTION_KEY, __('De pagina waar bezoekers naartoe gaan als ze op de kaart “Zomerkamp” klikken.', 'cjw')),
            'website',
            'website_cards'
        );
        $this->add(
            'card_info_title',
            __('Titel kaart “Praktische info”', 'cjw'),
            new CJW_Input_Field('card_info_title', '', self::OPTION_KEY, description: __('Titel van de kaart met praktische informatie. Tekst na een | krijgt de handgetekende onderstreping, bijvoorbeeld “Praktische |info”.', 'cjw')),
            'website',
            'website_cards'
        );
        $this->add(
            'card_info_text',
            __('Tekst kaart “Praktische info”', 'cjw'),
            new CJW_Input_Field('card_info_text', '', self::OPTION_KEY, description: __('Korte tekst (één à twee zinnen) op de kaart met praktische informatie.', 'cjw')),
            'website',
            'website_cards'
        );
        $this->add(
            'card_info_page_id',
            __('Pagina kaart “Praktische info”', 'cjw'),
            new CJW_Page_Field('card_info_page_id', '', self::OPTION_KEY, __('De pagina waar bezoekers naartoe gaan als ze op de kaart “Praktische info” klikken.', 'cjw')),
            'website',
            'website_cards'
        );
        $this->add(
            'card_signup_title',
            __('Titel inschrijfkaart', 'cjw'),
            new CJW_Input_Field('card_signup_title', '', self::OPTION_KEY, description: __('Titel van de inschrijfkaart. Tekst na een | krijgt de handgetekende onderstreping, bijvoorbeeld “Schrijf je |in”.', 'cjw')),
            'website',
            'website_cards'
        );
        $this->add(
            'card_signup_text',
            __('Tekst inschrijfkaart', 'cjw'),
            new CJW_Input_Field('card_signup_text', '', self::OPTION_KEY, description: __('Korte tekst op de inschrijfkaart. Typ <code>{jaar}</code> om automatisch het kampjaar in te vullen, bijvoorbeeld “Schrijf je nu in voor zomerkamp {jaar}”.', 'cjw')),
            'website',
            'website_cards'
        );

        // Section: Kampplakboek
        $this->add(
            'photos_title',
            __('Titel kampplakboek', 'cjw'),
            new CJW_Input_Field('photos_title', '', self::OPTION_KEY, description: __('Titel boven het fotoblok. Tekst na een | krijgt de handgetekende onderstreping, bijvoorbeeld “Ons |kampplakboek”.', 'cjw')),
            'website',
            'website_photos'
        );
        $this->add(
            'photos_lead',
            __('Introzin kampplakboek', 'cjw'),
            new CJW_Input_Field('photos_lead', '', self::OPTION_KEY, description: __('Korte introzin onder de titel van het fotoblok.', 'cjw')),
            'website',
            'website_photos'
        );

        for ($slot = 1; $slot <= CJW_Summer_Camp_Service::POLAROID_SLOTS; $slot++) {
            $this->add(
                "polaroid_{$slot}_image_id",
                /* translators: %d: polaroid slot number. */
                sprintf(__('Foto %d kampplakboek', 'cjw'), $slot),
                new CJW_Media_Field(
                    "polaroid_{$slot}_image_id",
                    '',
                    self::OPTION_KEY,
                    /* translators: %d: polaroid slot number. */
                    sprintf(__('Foto %d in het plakboek. Liggende foto’s werken het best; de foto wordt automatisch bijgesneden. Laat leeg om deze plek in het plakboek leeg te laten.', 'cjw'), $slot),
                    // Matches the theme's registered cjw-polaroid crop exactly,
                    // which is the size actually served on the front page.
                    recommendedWidth: 900,
                    recommendedHeight: 675,
                    maxBytes: 300 * KB_IN_BYTES
                ),
                'website',
                'website_photos'
            );
            $this->add(
                "polaroid_{$slot}_caption",
                /* translators: %d: polaroid slot number. */
                sprintf(__('Bijschrift foto %d', 'cjw'), $slot),
                new CJW_Input_Field(
                    "polaroid_{$slot}_caption",
                    '',
                    self::OPTION_KEY,
                    /* translators: %d: polaroid slot number. */
                    description: sprintf(__('Kort bijschrift onder foto %d, zoals op een echte polaroid, bijvoorbeeld “Kampvuur 2025”. Laat leeg om geen bijschrift te tonen.', 'cjw'), $slot)
                ),
                'website',
                'website_photos'
            );
        }

        // Section: Sponsors
        $this->add(
            'sponsors_title',
            __('Titel sponsorblok', 'cjw'),
            new CJW_Input_Field('sponsors_title', '', self::OPTION_KEY, description: __('Titel boven het sponsorblok. Tekst na een | krijgt de handgetekende onderstreping, bijvoorbeeld “Onze |sponsors”.', 'cjw')),
            'website',
            'website_sponsors'
        );
        $this->add(
            'sponsors_lead',
            __('Introzin sponsorblok', 'cjw'),
            new CJW_Input_Field('sponsors_lead', '', self::OPTION_KEY, description: __('Korte introzin onder de titel van het sponsorblok, bijvoorbeeld een bedankje aan alle sponsors.', 'cjw')),
            'website',
            'website_sponsors'
        );
        $this->add(
            'sponsors_cta_text',
            __('Knoptekst sponsorwerving', 'cjw'),
            new CJW_Input_Field('sponsors_cta_text', '', self::OPTION_KEY, description: __('Tekst op de knop of link waarmee nieuwe sponsors zich kunnen melden, bijvoorbeeld “Word ook sponsor”.', 'cjw')),
            'website',
            'website_sponsors'
        );

        // Section: Oproep-banner
        $this->add(
            'cta_title',
            __('Titel oproep-banner', 'cjw'),
            new CJW_Input_Field('cta_title', '', self::OPTION_KEY, description: __('Titel in de oproep-banner. Tekst na een | krijgt de handgetekende onderstreping, bijvoorbeeld “Ga je |mee?”.', 'cjw')),
            'website',
            'website_cta'
        );
        $this->add(
            'cta_text',
            __('Tekst oproep-banner', 'cjw'),
            new CJW_Input_Field('cta_text', '', self::OPTION_KEY, description: __('Korte aansporende tekst in de oproep-banner, bijvoorbeeld “Wacht niet te lang: vol = vol!”.', 'cjw')),
            'website',
            'website_cta'
        );

        // Section: Contact & social
        $this->add(
            'contact_email',
            __('Contact-e-mailadres', 'cjw'),
            new CJW_Input_Field('contact_email', '', self::OPTION_KEY, 'email', description: __('E-mailadres waarop ouders de kampleiding kunnen bereiken. Verschijnt in de voettekst van de website.', 'cjw')),
            'website',
            'website_contact'
        );
        $this->add(
            'social_facebook',
            __('Facebook-URL', 'cjw'),
            new CJW_Input_Field('social_facebook', '', self::OPTION_KEY, 'url', description: __('Volledig webadres van de Facebook-pagina, bijvoorbeeld https://www.facebook.com/cjwbrummen. Laat leeg om het Facebook-icoon te verbergen.', 'cjw')),
            'website',
            'website_contact'
        );
        $this->add(
            'social_instagram',
            __('Instagram-URL', 'cjw'),
            new CJW_Input_Field('social_instagram', '', self::OPTION_KEY, 'url', description: __('Volledig webadres van het Instagram-account, bijvoorbeeld https://www.instagram.com/cjwbrummen. Laat leeg om het Instagram-icoon te verbergen.', 'cjw')),
            'website',
            'website_contact'
        );
        $this->add(
            'footer_about',
            __('Tekst over CJW (footer)', 'cjw'),
            new CJW_Input_Field('footer_about', '', self::OPTION_KEY, description: __('Korte tekst over CJW in de voettekst van elke pagina (twee à drie zinnen).', 'cjw')),
            'website',
            'website_contact'
        );
        $this->add(
            'footer_org',
            __('Organisatienaam (footer)', 'cjw'),
            new CJW_Input_Field('footer_org', '', self::OPTION_KEY, description: __('Naam van de organisatie zoals die onderaan in de voettekst staat, bijvoorbeeld “CJW Brummen”.', 'cjw')),
            'website',
            'website_contact'
        );
        $this->add(
            'support_contact',
            __('Wie onderhoudt de website', 'cjw'),
            new CJW_Input_Field('support_contact', '', self::OPTION_KEY, description: __('Naam, telefoonnummer en e-mailadres van wie je belt als de website zelf stuk is. Deze gegevens staan nergens op de openbare site; ze staan in de Handleiding, zodat de volgende vrijwilliger weet bij wie hij moet zijn.', 'cjw')),
            'website',
            'website_contact'
        );
    }

    /**
     * Helper to register a field to a section.
     *
     * @param string      $key     The field key.
     * @param string      $label   The field label.
     * @param CJW_Field   $field   The field instance.
     * @param string      $tab     The tab the field belongs to.
     * @param string|null $section The section ID within the tab; defaults to the tab ID.
     *
     * @return void
     */
    private function add(string $key, string $label, CJW_Field $field, string $tab, ?string $section = null): void
    {
        add_settings_field(
            $key,
            esc_html($label),
            [$field, 'render'],
            self::PAGE_SLUG . '-' . $tab,
            $section ?? $tab
        );
    }

    /**
     * Validates and sanitizes the settings input.
     *
     * Injects additional validation errors if necessary.
     *
     * @param mixed $input The raw input value.
     *
     * @return array<string, mixed> The sanitized array.
     */
    public function validateAndSanitize(mixed $input): array
    {
        if (!is_array($input)) {
            $input = [];
        }

        $input = $this->mergeInactiveTabSettings($input);

        if (!empty($input['early_bird_start']) && !empty($input['early_bird_end'])) {
            if (strtotime((string) $input['early_bird_end']) < strtotime((string) $input['early_bird_start'])) {
                add_settings_error(
                    self::OPTION_KEY,
                    'early_bird',
                    __('Vroegboekkorting einddatum moet na de startdatum liggen.', 'cjw')
                );
            }
        }

        return CJW_Summer_Camp_Service::sanitize($input);
    }

    /**
     * Merges previously saved values for fields hidden by inactive tabs.
     *
     * @param array $input The submitted settings for the active tab.
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function mergeInactiveTabSettings(array $input): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- settings API verifies the options form nonce.
        $active_tab = isset($_POST['cjw_summer_camp_active_tab'])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- settings API verifies the options from nonce.
            ? sanitize_key(wp_unslash((string) $_POST['cjw_summer_camp_active_tab']))
            : '';

        if (!array_key_exists($active_tab, self::TAB_FIELDS)) {
            return $input;
        }

        $existing = get_option(self::OPTION_KEY, []);

        if (!is_array($existing)) {
            return $input;
        }

        $active_fields = array_flip(self::TAB_FIELDS[$active_tab]);

        foreach ($existing as $key => $value) {
            if (!array_key_exists((string) $key, $active_fields) && !array_key_exists((string) $key, $input)) {
                $input[(string) $key] = $value;
            }
        }

        return $input;
    }

    /**
     * Renders the administrative settings page with tabbed navigation.
     *
     * @return void
     */
    public function render(): void
    {
        if (!CJW_Roles::currentUserCan()) {
            wp_die(esc_html__('Je hebt geen toestemming om deze instellingen te bekijken.', 'cjw'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is not needed for tab changes
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'general';
        $tabs = [
            'general' => __('Algemeen', 'cjw'),
            'theme' => __('Thema', 'cjw'),
            'pricing' => __('Tarieven', 'cjw'),
            'content' => __('Inhoud', 'cjw'),
            'website' => __('Website', 'cjw'),
            'beheer' => __('Beheer', 'cjw'),
        ];

        if (!array_key_exists($active_tab, $tabs)) {
            $active_tab = 'general';
        }

        ?>

		<div class="wrap">
            <h1><?php esc_html_e('Zomerkamp Instellingen', 'cjw'); ?></h1>
			<?php $this->renderStatusPanel(); ?>

            <h2 class="nav-tab-wrapper">
                <?php
                foreach ($tabs as $tab => $label):
                    $url = add_query_arg(['tab' => $tab]);
                    $active = $active_tab === $tab ? 'nav-tab-active' : '';
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="nav-tab <?php echo esc_attr($active); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php settings_errors(self::OPTION_KEY); ?>

            <?php if ($active_tab === 'beheer') : ?>
                <?php $this->renderTools(); ?>
            <?php else : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('cjw_group'); ?>
                    <input type="hidden" name="cjw_summer_camp_active_tab" value="<?php echo esc_attr($active_tab); ?>" />

                    <?php do_settings_sections(self::PAGE_SLUG . '-' . $active_tab); ?>

                    <?php submit_button(); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders the Beheer tab: the actions that are not settings.
     *
     * Everything here changes something without being a field you type into --
     * sending a test mail, putting an older version back, starting a new camp
     * year. Mixed in among the settings they would either be missed or pressed
     * by accident, and each one is owned by the class that implements it rather
     * than reimplemented here.
     *
     * @return void
     */
    private function renderTools(): void
    {
        $this->renderToolNotice();

        CJW_Summer_Camp_First_Run::renderPanel();
        CJW_Status_Endpoint::renderPanel();
        CJW_Summer_Camp_Mail::renderPanel();
        CJW_Mail_Safe_Mode::renderPanel();
        CJW_Summer_Camp_Digest::renderPanel();
        CJW_Summer_Camp_Settings_History::renderPanel();
        CJW_Summer_Camp_Year::renderPanel();
    }

    /**
     * Reports the outcome of whatever action sent the browser back here.
     *
     * @return void
     */
    private function renderToolNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a flag set by our own redirect after that action was verified; nothing is changed here.
        $result = isset($_GET['cjw_result']) ? sanitize_key(wp_unslash((string) $_GET['cjw_result'])) : '';

        if ($result === '') {
            return;
        }

        $notices = [
            'restored' => ['success', __('De eerdere versie van de instellingen is teruggezet.', 'cjw')],
            'restore_failed' => ['error', __('Die versie kon niet worden teruggezet. Ververs de pagina en probeer het opnieuw.', 'cjw')],
            'imported' => ['success', __('De instellingen uit het bestand zijn ingelezen.', 'cjw')],
            'import_failed' => ['error', __('Dat bestand kon niet worden ingelezen. Kies een bestand dat eerder met “Download de instellingen” is gemaakt.', 'cjw')],
            'digest_on' => ['success', __('Het weekbericht staat aan. Is er niets te melden, dan komt er die week geen mail.', 'cjw')],
            'digest_off' => ['success', __('Het weekbericht staat uit.', 'cjw')],
            'digest_sent' => ['success', __('Het voorbeeld is verstuurd naar iedereen die het kamp beheert.', 'cjw')],
            'digest_failed' => ['error', __('Het voorbeeld kon niet worden verstuurd. Controleer hierboven of de website wel mail kan versturen.', 'cjw')],
            'year_started' => ['success', __('Het nieuwe kampjaar is gestart. Vul de nieuwe kampdata in en zet de inschrijvingen open zodra je zover bent.', 'cjw')],
            'year_not_confirmed' => ['error', __('Er is niets veranderd: het ingetypte jaartal klopte niet.', 'cjw')],
            'setup_done' => ['success', __('Wat ontbrak is aangemaakt. Loop de nieuwe pagina’s na en vul ze met eigen tekst; de privacyverklaring staat als concept klaar.', 'cjw')],
            'setup_nothing' => ['success', __('Er ontbrak niets; er is niets aangemaakt.', 'cjw')],
            'host_confirmed' => ['success', __('Dit webadres is vastgelegd als de live website. Elke kopie op een ander adres stuurt vanaf nu geen mail meer naar ouders.', 'cjw')],
            'host_forgotten' => ['success', __('De live website is vergeten. Bevestig het nieuwe adres zodra de website daar draait.', 'cjw')],
            'fatal_cleared' => ['success', __('De crashmelding is weggehaald. De statuspagina meldt weer “ok”, en een volgende crash wordt opnieuw gemeld.', 'cjw')],
        ];

        if (!array_key_exists($result, $notices)) {
            return;
        }

        [$type, $message] = $notices[$result];

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    /**
     * Renders a compact operational summary above the settings form.
     *
     * @return void
     */
    private function renderStatusPanel(): void
    {
        $camp = cjw_summer_camp();
        $deadline = $camp->getRegistrationDeadline();
        $week_price = $camp->getPrice(CampType::WEEK);
        $weekend_price = $camp->getPrice(CampType::WEEKEND);
        $late_registration_link = $camp->getLateRegistrationLink();
        $registration_page = $camp->getRegistrationPageUrl();
        $registration_open = $camp->isRegistrationOpen();
        $registration_status_class = $registration_open ? 'is-open' : 'is-closed';
        $registration_status_label = $registration_open ? __('Open', 'cjw') : __('Gesloten', 'cjw');
        $early_bird_status = $camp->isEarlyBird() ? __('Actief', 'cjw') : __('Niet actief', 'cjw');
        $status_items = [
            __('Zomerkamp', 'cjw') => $camp->getDateRangeText(),
            __('Deadline', 'cjw') => $this->formatDate($deadline),
            __('Inschrijfpagina', 'cjw') => $registration_page !== ''
                ? $registration_page
                : __('Geen pagina met het formulier gevonden', 'cjw'),
            __('Vroegboekkorting', 'cjw') => $early_bird_status,
            __('Prijs week', 'cjw') => cjw_localize_value($week_price, [
                'type' => 'currency',
                'currency_symbol' => 'EUR',
            ]),
            __('Prijs weekend', 'cjw') => cjw_localize_value($weekend_price, [
                'type' => 'currency',
                'currency_symbol' => 'EUR',
            ]),
        ];

        ?>
        <div class="cjw-status-panel" aria-label="<?php esc_attr_e('Zomerkamp status', 'cjw'); ?>">
            <div class="cjw-status-panel__header">
                <h2><?php esc_html_e('Status', 'cjw'); ?></h2>
                <span class="cjw-status-badge <?php echo esc_attr($registration_status_class); ?>">
                    <?php echo esc_html($registration_status_label); ?>
                </span>
            </div>

            <dl class="cjw-status-grid">
                <?php foreach ($status_items as $label => $value): ?>
                    <div class="cjw-status-item">
                        <dt><?php echo esc_html((string) $label); ?></dt>
                        <dd><?php echo esc_html($value !== '' ? $value : __('Niet ingesteld', 'cjw')); ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>

            <?php if ($late_registration_link !== ''): ?>
                <div class="cjw-status-link">
                    <label for="late-registration-link"><?php esc_html_e('Late inschrijving', 'cjw'); ?></label>
                    <input type="url" readonly id="late-registration-link" value="<?php echo esc_attr($late_registration_link); ?>" />
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Formats an admin-facing date.
     *
     * @param DateTimeImmutable|null $date Date to format.
     *
     * @return string
     */
    private function formatDate(?DateTimeImmutable $date): string
    {
        if (!$date instanceof DateTimeImmutable) {
            return '';
        }

        return cjw_localize_value($date, [
            'type' => 'date',
            'format' => (string) get_option('date_format'),
        ]);
    }
}
