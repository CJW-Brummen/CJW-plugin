<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The manual, in Dutch, for the people who will actually run this.
 *
 * The docs/ directory covers the config schema, the cutover and the development
 * setup -- in English, for someone who reads PHP. The people who run the camp read Dutch
 * and will never open the repository. The plugin's instincts were already right
 * here: the help tabs, the dashboard widget and the Site Health checks are
 * exactly this pattern. They only stopped short of being a manual.
 *
 * Written for the person who inherits this, not for the person who built it.
 * Everything it can read from the site, it reads: the dates, the deadline and
 * who to call are the site's own answers rather than a document's memory of
 * them, because a manual that goes stale is worse than no manual -- it is
 * confidently wrong.
 */
final class CJW_Summer_Camp_Manual
{
    /**
     * Page slug for the manual screen.
     */
    public const string PAGE_SLUG = 'cjw-handleiding';

    /**
     * Registers the menu entry.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [new self(), 'menu'], 20);
    }

    /**
     * Adds the manual under the Zomerkamp menu.
     *
     * @return void
     */
    public function menu(): void
    {
        add_submenu_page(
            'cjw-summer-camp',
            __('Handleiding', 'cjw'),
            __('Handleiding', 'cjw'),
            CJW_Roles::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * The chapters, in reading order.
     *
     * @return array<string, string>
     */
    private function chapters(): array
    {
        return [
            'jaar' => __('Het kampjaar in stappen', 'cjw'),
            'schermen' => __('Wat staat waar', 'cjw'),
            'inschrijvingen' => __('Inschrijvingen bekijken', 'cjw'),
            'misgaat' => __('Als er iets misgaat', 'cjw'),
            'techniek' => __('Eenmalig instellen', 'cjw'),
            'updates' => __('Updates', 'cjw'),
        ];
    }

    /**
     * Renders the manual.
     *
     * @return void
     */
    public function render(): void
    {
        if (!CJW_Roles::currentUserCan()) {
            wp_die(esc_html__('Je hebt geen toestemming om deze pagina te bekijken.', 'cjw'));
        }

        ?>
        <div class="wrap cjw-manual">
            <h1><?php esc_html_e('Handleiding zomerkampwebsite', 'cjw'); ?></h1>

            <p class="cjw-manual__lead">
                <?php esc_html_e('Alles wat je moet weten om deze website een jaar lang te draaien. Je hebt er geen programmeur voor nodig en je hoeft niets te installeren.', 'cjw'); ?>
            </p>

            <nav class="cjw-manual__toc" aria-label="<?php esc_attr_e('Inhoud van de handleiding', 'cjw'); ?>">
                <ol>
                    <?php foreach ($this->chapters() as $anchor => $title) : ?>
                        <li><a href="#cjw-<?php echo esc_attr($anchor); ?>"><?php echo esc_html($title); ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </nav>

            <?php
            $this->renderYear();
            $this->renderScreens();
            $this->renderSubmissions();
            $this->renderTroubleshooting();
            $this->renderRunbook();
            $this->renderUpdates();
            ?>
        </div>
        <?php
    }

    /**
     * Opens a chapter.
     *
     * @param string $anchor Anchor id, without the prefix.
     * @param string $title  Chapter heading.
     *
     * @return void
     */
    private function openChapter(string $anchor, string $title): void
    {
        printf(
            '<section class="cjw-manual__chapter" id="cjw-%1$s"><h2>%2$s</h2>',
            esc_attr($anchor),
            esc_html($title)
        );
    }

    /**
     * A link to one of the camp's own screens.
     *
     * @param string $label Link text.
     * @param string $path  Admin path.
     *
     * @return string
     */
    private function link(string $label, string $path): string
    {
        return sprintf('<a href="%s">%s</a>', esc_url(admin_url($path)), esc_html($label));
    }

    /**
     * Prints a list of steps.
     *
     * @param array<int, string> $steps Steps, already escaped where they carry markup.
     *
     * @return void
     */
    private function steps(array $steps): void
    {
        echo '<ol class="cjw-manual__steps">';

        foreach ($steps as $step) {
            echo '<li>' . wp_kses($step, [
                'a' => [ 'href' => true ],
                'strong' => [],
                'em' => [],
                'code' => [],
            ]) . '</li>';
        }

        echo '</ol>';
    }

    /**
     * The yearly cycle, in the order it happens.
     *
     * @return void
     */
    private function renderYear(): void
    {
        $this->openChapter('jaar', __('Het kampjaar in stappen', 'cjw'));

        ?>
        <p><?php esc_html_e('De website volgt elk jaar dezelfde ronde. Werk hem van boven naar beneden af en je slaat niets over.', 'cjw'); ?></p>

        <h3><?php esc_html_e('1. Meteen na het kamp', 'cjw'); ?></h3>
        <?php
        $this->steps([
            sprintf(
                /* translators: %s: link to the Beheer tab. */
                __('Ga naar %s en klik op <strong>Start kampjaar</strong>. De oude instellingen worden bewaard, het jaartal springt een jaar vooruit, de data worden leeggemaakt en de inschrijvingen gaan dicht.', 'cjw'),
                $this->link(__('Zomerkamp → Beheer', 'cjw'), 'admin.php?page=cjw-summer-camp&tab=beheer')
            ),
            __('De inschrijvingen van het afgelopen kamp worden daarbij geanonimiseerd: namen, adressen en opmerkingen verdwijnen, de aantallen blijven. Dat hoort zo — die gegevens gaan over kinderen en horen niet jarenlang te blijven staan.', 'cjw'),
            __('Zet de foto’s van dit kamp in het kampplakboek, zolang iedereen nog weet welke leuk waren.', 'cjw'),
        ]);
        ?>

        <h3><?php esc_html_e('2. In de winter: het nieuwe thema', 'cjw'); ?></h3>
        <?php
        $this->steps([
            sprintf(
                /* translators: %s: link to the Thema tab. */
                __('Vul op %s de themanaam en de teksten van de grote banner in.', 'cjw'),
                $this->link(__('Zomerkamp → Thema', 'cjw'), 'admin.php?page=cjw-summer-camp&tab=theme')
            ),
            __('Kies een nieuwe sfeerfoto. Krijg je een waarschuwing dat de foto te groot is, maak hem dan eerst kleiner — anders wordt de site traag op een telefoon.', 'cjw'),
            __('De kleuren op het Thema-tabblad kleuren de hele website mee. Verander ze rustig, maar kijk daarna even op de homepage.', 'cjw'),
        ]);
        ?>

        <h3><?php esc_html_e('3. In het voorjaar: data en prijzen', 'cjw'); ?></h3>
        <?php
        $this->steps([
            sprintf(
                /* translators: %s: link to the Algemeen tab. */
                __('Vul op %s de begin- en einddatum van het kamp in, de rijdata, en de datum waarop de inschrijvingen sluiten.', 'cjw'),
                $this->link(__('Zomerkamp → Algemeen', 'cjw'), 'admin.php?page=cjw-summer-camp&tab=general')
            ),
            sprintf(
                /* translators: %s: link to the Tarieven tab. */
                __('Zet op %s de prijzen voor dit jaar, en de periode waarin de vroegboekkorting geldt.', 'cjw'),
                $this->link(__('Zomerkamp → Tarieven', 'cjw'), 'admin.php?page=cjw-summer-camp&tab=pricing')
            ),
            sprintf(
                /* translators: %s: link to the forms screen. */
                __('Loop het inschrijfformulier na op %s: kloppen de vragen, de kampkeuzes en de bedragen nog?', 'cjw'),
                $this->link(__('Formulieren', 'cjw'), 'edit.php?post_type=cjw_wizard')
            ),
            __('Stuur een testmail (Beheer-tabblad) en schrijf jezelf één keer proefin. Krijg je een bevestiging in je inbox? Dan werkt het.', 'cjw'),
        ]);
        ?>

        <h3><?php esc_html_e('4. Inschrijvingen openzetten', 'cjw'); ?></h3>
        <?php
        $this->steps([
            __('Zet op het tabblad Algemeen het vinkje <strong>Inschrijvingen geopend</strong> aan. Vanaf dat moment kan iedereen zich inschrijven, tot de deadline.', 'cjw'),
            __('Controleer of de homepage klopt: de juiste data, de juiste prijzen, de nieuwe foto’s.', 'cjw'),
        ]);
        ?>

        <h3><?php esc_html_e('5. Tijdens de inschrijfperiode', 'cjw'); ?></h3>
        <?php
        $this->steps([
            __('Zet het weekbericht aan op het Beheer-tabblad. Je krijgt dan op maandagochtend een mail met wat er die week is binnengekomen en wat er nog mist. Is er niets te melden, dan komt er ook geen mail.', 'cjw'),
            __('Zet inschrijvingen op <em>Bevestigd</em> zodra je ze hebt nagekeken, en op <em>Betaald</em> als het geld binnen is. Zo kun je in één oogopslag zien wie er nog moet betalen.', 'cjw'),
            __('Komt iemand te laat? Op het Beheer-tabblad staat een privélink waarmee die persoon zich alsnog kan inschrijven, ook als de inschrijvingen dicht zijn. Deel die link alleen persoonlijk.', 'cjw'),
        ]);
        ?>

        <h3><?php esc_html_e('6. Vlak voor het kamp', 'cjw'); ?></h3>
        <?php
        $this->steps([
            sprintf(
                /* translators: %s: link to the children overview. */
                __('Open %s voor het overzicht per kind: wie gaat er mee, naar welk kamp, met welke bijzonderheden.', 'cjw'),
                $this->link(__('Inschrijvingen → Kinderen', 'cjw'), 'edit.php?post_type=cjw_wizard_entry&page=cjw-children')
            ),
            __('Download de lijst als bestand als je hem op papier wilt meenemen. Ga zorgvuldig om met die lijst: er staan gegevens van kinderen op.', 'cjw'),
        ]);

        echo '</section>';
    }

    /**
     * What each screen controls.
     *
     * @return void
     */
    private function renderScreens(): void
    {
        $this->openChapter('schermen', __('Wat staat waar', 'cjw'));

        $rows = [
            [
                __('Zomerkamp → Algemeen', 'cjw'),
                __('Kampdata, locatie, leeftijden, rijdata, deadline, of de inschrijvingen open staan, en hoe lang inschrijvingen bewaard blijven.', 'cjw'),
                __('Bovenaan de homepage en op de inschrijfpagina.', 'cjw'),
            ],
            [
                __('Zomerkamp → Thema', 'cjw'),
                __('Het jaarthema, de grote foto bovenaan de homepage met de teksten en knoppen erop, en de kleuren van de site.', 'cjw'),
                __('De banner bovenaan de homepage, en de kleuren van de hele website.', 'cjw'),
            ],
            [
                __('Zomerkamp → Tarieven', 'cjw'),
                __('De prijs voor een week en een weekend, en een apart prijzenpaar voor de vroegboekkorting met de periode waarin die geldt.', 'cjw'),
                __('Op de inschrijfpagina en overal waar een bedrag genoemd wordt.', 'cjw'),
            ],
            [
                __('Zomerkamp → Inhoud', 'cjw'),
                __('Teksten die in het inschrijfformulier en in mails aan ouders worden gebruikt.', 'cjw'),
                __('In het formulier en in de bevestigingsmail.', 'cjw'),
            ],
            [
                __('Zomerkamp → Website', 'cjw'),
                __('Alle teksten en foto’s van de homepage: het welkomstblok, de drie kaarten, het kampplakboek, het sponsorblok, de oproepbanner, de contactgegevens en de voettekst.', 'cjw'),
                __('De homepage, van boven tot onder, en de voettekst op elke pagina.', 'cjw'),
            ],
            [
                __('Zomerkamp → Beheer', 'cjw'),
                __('Geen instellingen maar knoppen: een testmail versturen, het weekbericht aanzetten, een eerdere versie van de instellingen terugzetten, en een nieuw kampjaar starten.', 'cjw'),
                __('Nergens — dit tabblad verandert de website niet uit zichzelf.', 'cjw'),
            ],
            [
                __('Sponsoren', 'cjw'),
                __('Eén sponsor per item, met het logo als uitgelichte afbeelding en een link naar hun website.', 'cjw'),
                __('Het sponsorblok op de homepage.', 'cjw'),
            ],
            [
                __('Formulieren', 'cjw'),
                __('Het inschrijfformulier: de stappen, de vragen en de voorwaarden.', 'cjw'),
                __('De inschrijfpagina.', 'cjw'),
            ],
            [
                __('Inschrijvingen', 'cjw'),
                __('Alles wat er is binnengekomen, met een status per inschrijving.', 'cjw'),
                __('Nergens — deze gegevens zijn alleen voor de organisatie.', 'cjw'),
            ],
        ];

        ?>
        <table class="widefat striped cjw-manual__table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Scherm', 'cjw'); ?></th>
                    <th scope="col"><?php esc_html_e('Wat je er instelt', 'cjw'); ?></th>
                    <th scope="col"><?php esc_html_e('Waar je het terugziet', 'cjw'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($row[0]); ?></th>
                        <td><?php echo esc_html($row[1]); ?></td>
                        <td><?php echo esc_html($row[2]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        echo '</section>';
    }

    /**
     * Reading the registrations.
     *
     * @return void
     */
    private function renderSubmissions(): void
    {
        $this->openChapter('inschrijvingen', __('Inschrijvingen bekijken', 'cjw'));

        ?>
        <p>
            <?php
            echo wp_kses(
                sprintf(
                    /* translators: %s: link to the submissions list. */
                    __('Alles wat binnenkomt staat onder %s. Je kunt daar niets zelf toevoegen: een inschrijving ontstaat alleen doordat iemand het formulier invult, zodat de lijst altijd klopt met wat er echt is ingevuld.', 'cjw'),
                    $this->link(__('Inschrijvingen', 'cjw'), 'edit.php?post_type=cjw_wizard_entry')
                ),
                [ 'a' => [ 'href' => true ] ]
            );
            ?>
        </p>

        <h3><?php esc_html_e('De statussen', 'cjw'); ?></h3>
        <ul class="cjw-manual__list">
            <li><strong><?php esc_html_e('Nieuw', 'cjw'); ?></strong> — <?php esc_html_e('net binnengekomen, nog niet bekeken.', 'cjw'); ?></li>
            <li><strong><?php esc_html_e('Bevestigd', 'cjw'); ?></strong> — <?php esc_html_e('nagekeken en akkoord; het kind heeft een plek.', 'cjw'); ?></li>
            <li><strong><?php esc_html_e('Betaald', 'cjw'); ?></strong> — <?php esc_html_e('het geld is binnen.', 'cjw'); ?></li>
            <li><strong><?php esc_html_e('Geannuleerd', 'cjw'); ?></strong> — <?php esc_html_e('gaat niet door. Deze staan in een apart tabblad en tellen niet mee.', 'cjw'); ?></li>
        </ul>

        <h3><?php esc_html_e('Handige dingen om te weten', 'cjw'); ?></h3>
        <ul class="cjw-manual__list">
            <li><?php esc_html_e('Bovenaan een inschrijving zie je of de ouder een bevestigingsmail heeft gekregen, en wanneer. Staat daar “Versturen mislukt”, dan heeft die ouder niets ontvangen — bel of mail ze zelf even.', 'cjw'); ?></li>
            <li><?php esc_html_e('Met de zoekbalk zoek je op naam van het kind, op e-mailadres van de ouder en op kamp.', 'cjw'); ?></li>
            <li><?php esc_html_e('Het overzicht “Kinderen” zet alle kinderen onder elkaar in plaats van per inschrijving. Handig als één ouder twee kinderen heeft opgegeven.', 'cjw'); ?></li>
            <li><?php esc_html_e('Inschrijvingen worden vanzelf geanonimiseerd als ze oud genoeg zijn. Hoe lang ze bewaard blijven stel je in op het tabblad Algemeen, in maanden na afloop van het kamp.', 'cjw'); ?></li>
        </ul>
        <?php

        echo '</section>';
    }

    /**
     * When something breaks.
     *
     * @return void
     */
    private function renderTroubleshooting(): void
    {
        $this->openChapter('misgaat', __('Als er iets misgaat', 'cjw'));

        $camp = cjw_summer_camp();
        $support = $camp->getSupportContact();

        ?>
        <h3><?php esc_html_e('Iemand zegt dat het inschrijfformulier niet werkt', 'cjw'); ?></h3>
        <?php
        $this->steps([
            __('Probeer het zelf, op je telefoon en op een computer. Vul het formulier één keer helemaal in.', 'cjw'),
            __('Staan de inschrijvingen wel open, en is de deadline nog niet voorbij? Kijk op het tabblad Algemeen.', 'cjw'),
            sprintf(
                /* translators: %s: link to Site Health. */
                __('Kijk bij %s of er een rood punt bij “Zomerkamp” staat. Daar staat meestal precies wat er mist.', 'cjw'),
                $this->link(__('Gereedschap → Websitediagnose', 'cjw'), 'site-health.php')
            ),
            __('Krijgt iemand de melding dat er te veel inschrijvingen vanaf zijn internetverbinding zijn gedaan? Dan is de beveiliging tegen misbruik aangeslagen. Laat die persoon het over een uur nog eens proberen, of schrijf hem handmatig in.', 'cjw'),
        ]);
        ?>

        <h3><?php esc_html_e('Ouders krijgen geen bevestigingsmail', 'cjw'); ?></h3>
        <?php
        $this->steps([
            sprintf(
                /* translators: %s: link to the Beheer tab. */
                __('Stuur een testmail vanaf %s naar je eigen adres.', 'cjw'),
                $this->link(__('Zomerkamp → Beheer', 'cjw'), 'admin.php?page=cjw-summer-camp&tab=beheer')
            ),
            __('Komt hij niet aan, kijk dan ook in je map ongewenste mail. Daar terechtkomen telt als een probleem, ook al is de mail aangekomen.', 'cjw'),
            __('Lukt het versturen helemaal niet, dan staat de reden erbij. Dat is bijna altijd iets aan de mailinstellingen — zie het hoofdstuk hieronder.', 'cjw'),
        ]);
        ?>

        <h3><?php esc_html_e('Ik heb per ongeluk instellingen overschreven', 'cjw'); ?></h3>
        <p>
            <?php
            echo wp_kses(
                sprintf(
                    /* translators: %s: link to the Beheer tab. */
                    __('Geen paniek. Op %s staat een lijst met eerdere versies van de instellingen. Klik op <strong>Zet terug</strong> bij de versie van vóór je fout. Ook dat terugzetten kun je weer ongedaan maken.', 'cjw'),
                    $this->link(__('Zomerkamp → Beheer', 'cjw'), 'admin.php?page=cjw-summer-camp&tab=beheer')
                ),
                [
                    'a' => [ 'href' => true ],
                    'strong' => [],
                ]
            );
            ?>
        </p>

        <h3><?php esc_html_e('De website is helemaal niet bereikbaar', 'cjw'); ?></h3>
        <p><?php esc_html_e('Dan kun je hier niets doen — deze pagina is dan ook weg. Bel degene die de website onderhoudt, en daarna zo nodig het hostingbedrijf.', 'cjw'); ?></p>

        <h3><?php esc_html_e('Wie je belt', 'cjw'); ?></h3>
        <?php if ($support !== '') : ?>
            <p class="cjw-manual__contact"><?php echo esc_html($support); ?></p>
        <?php else : ?>
            <p class="cjw-manual__warning">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: link to the Website tab. */
                        __('Hier staat nog niemand. Vul op %s bij <strong>Wie onderhoudt de website</strong> een naam, telefoonnummer en e-mailadres in — anders eindigt deze handleiding nergens.', 'cjw'),
                        $this->link(__('Zomerkamp → Website', 'cjw'), 'admin.php?page=cjw-summer-camp&tab=website')
                    ),
                    [
                        'a' => [ 'href' => true ],
                        'strong' => [],
                    ]
                );
                ?>
            </p>
        <?php endif; ?>
        <?php

        echo '</section>';
    }

    /**
     * The things that are set up once and then forgotten.
     *
     * Deliberately in the volunteer's manual rather than in a developer
     * document. None of it can be done from this screen, and all of it is the
     * camp's own responsibility -- an account somewhere, a subscription, half an
     * hour once. A document only a programmer reads is how these three end up
     * never being done.
     *
     * @return void
     */
    private function renderRunbook(): void
    {
        $this->openChapter('techniek', __('Eenmalig instellen', 'cjw'));

        ?>
        <p><?php esc_html_e('Drie dingen die je één keer regelt en daarna jaren met rust laat. Ze kunnen niet vanaf deze pagina — er is iemand voor nodig die bij de hosting en bij de domeinnaam kan. Doe ze samen met degene die de website onderhoudt, en vink ze hieronder af zodra ze klaar zijn.', 'cjw'); ?></p>

        <h3><?php esc_html_e('1. Zorgen dat mail aankomt', 'cjw'); ?></h3>
        <p><?php esc_html_e('Zonder dit verstuurt de website mail rechtstreeks vanaf de webserver. Gmail en Outlook vertrouwen die mail niet en gooien hem vaak weg of zetten hem bij ongewenste mail. Ouders merken dat als eerste: die krijgen geen bevestiging en denken dat de inschrijving is mislukt.', 'cjw'); ?></p>
        <?php
        $this->steps([
            __('Vraag bij het hostingbedrijf om de gegevens van een mailpostvak op het eigen domein: serveradres, poort, gebruikersnaam en wachtwoord.', 'cjw'),
            __('Installeer een mailplugin die het versturen overneemt — bijvoorbeeld FluentSMTP of WP Mail SMTP, allebei gratis — en vul die gegevens daar in.', 'cjw'),
            __('Laat bij de domeinnaam drie regels instellen die aan de rest van de wereld vertellen dat deze server namens jullie mag mailen: SPF, DKIM en DMARC. Het hostingbedrijf weet wat daarmee bedoeld wordt en doet dit meestal voor je.', 'cjw'),
            __('Stuur daarna een testmail vanaf het Beheer-tabblad, naar een Gmail-adres én naar een Outlook-adres. Kijk in beide gevallen ook in de map ongewenste mail.', 'cjw'),
        ]);
        ?>

        <h3><?php esc_html_e('2. Reservekopieën, en één keer uitproberen', 'cjw'); ?></h3>
        <p><?php esc_html_e('Op deze website staan gegevens van kinderen. Erop vertrouwen dat het hostingbedrijf wel een back-up zal hebben, is geen plan: bijna niemand heeft ooit geprobeerd of zo’n back-up echt terug te zetten is.', 'cjw'); ?></p>
        <?php
        $this->steps([
            __('Zorg dat er elke dag een kopie wordt gemaakt van zowel de database als de geüploade bestanden, en dat die kopie ergens anders wordt bewaard dan op dezelfde server.', 'cjw'),
            __('Bewaar de kopieën van de laatste dertig dagen. Sommige problemen merk je pas weken later.', 'cjw'),
            __('Zet één keer daadwerkelijk een kopie terug op een testomgeving. Klok hoe lang het duurt en schrijf op wie het gedaan heeft. Dit is de stap die iedereen overslaat, en het is de enige stap die bewijst dat de rest werkt.', 'cjw'),
            __('Zet in de agenda dat je dat elk jaar opnieuw doet, na het kamp.', 'cjw'),
        ]);
        ?>

        <h3><?php esc_html_e('3. Een wachter die waarschuwt als de site eruit ligt', 'cjw'); ?></h3>
        <p><?php esc_html_e('Het weekbericht kan niet vertellen dat de website plat ligt: een website die plat ligt verstuurt niets. Daar heb je een dienst van buitenaf voor nodig die elke paar minuten kijkt of de site nog antwoordt.', 'cjw'); ?></p>
        <?php
        $this->steps([
            __('Maak een account bij een gratis uptime-dienst, bijvoorbeeld UptimeRobot of Better Stack.', 'cjw'),
            sprintf(
                /* translators: %s: registration page URL. */
                __('Laat hem niet de homepage in de gaten houden maar de <strong>inschrijfpagina</strong>: %s. Dat is de pagina waarvan het stukgaan geld kost, en het is de pagina die het eerst stukgaat.', 'cjw'),
                esc_html($this->registrationUrl())
            ),
            __('Laat de waarschuwing naar twee mensen gaan, niet naar één. Vakanties vallen samen met het kampseizoen.', 'cjw'),
        ]);

        echo '</section>';
    }

    /**
     * The update policy.
     *
     * @return void
     */
    private function renderUpdates(): void
    {
        $this->openChapter('updates', __('Updates', 'cjw'));

        ?>
        <p><?php esc_html_e('Zonder afspraak gebeurt er één van twee dingen: er wordt drie jaar niets bijgewerkt, of er gaat vanzelf iets bij dat het inschrijfformulier sloopt in de week voor het kamp. Allebei komen ze op hetzelfde neer — niemand had iets gekozen. Dit is de afspraak:', 'cjw'); ?></p>

        <ul class="cjw-manual__list">
            <li><strong><?php esc_html_e('Kleine updates van WordPress: automatisch.', 'cjw'); ?></strong> <?php esc_html_e('Dat zijn de beveiligingsupdates. Die wil je meteen hebben.', 'cjw'); ?></li>
            <li><strong><?php esc_html_e('Plugins van anderen: automatisch.', 'cjw'); ?></strong> <?php esc_html_e('Ze worden zelden geïnstalleerd en zelden gebruikt; achterlopen is hier het grotere risico.', 'cjw'); ?></li>
            <li><strong><?php esc_html_e('De twee eigen onderdelen (het thema en de zomerkampplugin): niet automatisch.', 'cjw'); ?></strong> <?php esc_html_e('Die worden bijgewerkt door wie de website onderhoudt, uit de eigen broncode.', 'cjw'); ?></li>
            <li>
                <strong><?php esc_html_e('Grote updates van WordPress: met opzet, in september of oktober.', 'cjw'); ?></strong>
                <?php esc_html_e('Dus na het kamp en ruim voor de nieuwe inschrijvingen. Eerst uitproberen op een kopie, dan pas op de echte site, en daarna één keer het inschrijfformulier helemaal invullen om te zien of het nog werkt.', 'cjw'); ?>
            </li>
        </ul>

        <p><?php esc_html_e('Nooit een grote update in de weken rond de inschrijfdeadline of het kamp zelf. Als er dan iets breekt, breekt het precies wanneer niemand tijd heeft.', 'cjw'); ?></p>
        <?php

        echo '</section>';
    }

    /**
     * The address the uptime check should watch.
     *
     * Read from the site rather than written down, so it stays right when the
     * page moves.
     *
     * @return string
     */
    private function registrationUrl(): string
    {
        $camp = cjw_summer_camp();
        $page_id = $camp->getCardZomerkampPageId();

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => 1,
            's' => '[cjw_wizard',
        ]);

        if ($pages !== []) {
            return (string) get_permalink($pages[0]);
        }

        return $page_id > 0 ? (string) get_permalink($page_id) : home_url('/');
    }
}
