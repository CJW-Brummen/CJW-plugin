<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sets up the pages a fresh site needs, once, on request.
 *
 * Activation installs roles and schedules jobs and creates nothing a visitor
 * can see: no front page, no page for the registration form, no privacy page,
 * no menu. Each omission surfaced as its own symptom -- a signup button with
 * nowhere to go, a consent checkbox with nothing to link to, a theme falling
 * back to its hard-coded menu -- and each was diagnosed separately.
 *
 * This does not run on activation. Activation happens on a restored copy and
 * on a re-activation of the live site as readily as on a fresh install, and
 * neither of those should grow pages. A notice says what is missing; one
 * button creates exactly that. Nothing that exists is touched: a front page
 * that is already set stays set, a menu that is already assigned is left as
 * it is, and a step that is complete is skipped even when the button is
 * pressed twice.
 */
final class CJW_Summer_Camp_First_Run
{
    public const string ACTION = 'cjw_first_run';

    public const string ACTION_DISMISS = 'cjw_first_run_dismiss';

    public const string OPTION_DISMISSED = 'cjw_first_run_notice_dismissed';

    /**
     * The theme's menu location, by name.
     */
    private const string MENU_LOCATION = 'menu-1';

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION, [new self(), 'handleRun']);
        add_action('admin_post_' . self::ACTION_DISMISS, [new self(), 'handleDismiss']);
        add_action('admin_notices', [new self(), 'renderNotice']);
    }

    /**
     * Each part of the setup, and whether it is in place.
     *
     * @return array<string, array{label: string, done: bool, detail: string}>
     */
    public static function status(): array
    {
        $camp = cjw_summer_camp();
        $front = (int) get_option('page_on_front', 0);
        $privacy = (int) get_option('wp_page_for_privacy_policy', 0);
        $registration = $camp->getRegistrationPageUrl();
        $zomerkamp = $camp->getCardZomerkampPageId();
        $info = $camp->getCardInfoPageId();
        $menuLocationExists = array_key_exists(self::MENU_LOCATION, get_registered_nav_menus());

        return [
            'form' => [
                'label' => __('Inschrijfformulier', 'cjw'),
                'done' => self::firstForm() > 0,
                'detail' => __('Een formulier in de formulierbouwer, als concept.', 'cjw'),
            ],
            'registration_page' => [
                'label' => __('Pagina “Inschrijven”', 'cjw'),
                'done' => $registration !== '',
                'detail' => $registration !== '' ? $registration : __('De pagina met het inschrijfformulier, waar elke “Schrijf je in”-knop heen linkt.', 'cjw'),
            ],
            'front_page' => [
                'label' => __('Homepage', 'cjw'),
                'done' => get_option('show_on_front') === 'page' && $front > 0 && get_post_status($front) === 'publish',
                'detail' => __('Een vaste pagina met de zes blokken van de homepage, ingesteld als voorpagina.', 'cjw'),
            ],
            'card_pages' => [
                'label' => __('Pagina’s “Zomerkamp” en “Praktische info”', 'cjw'),
                'done' => self::pageIsLive($zomerkamp) && self::pageIsLive($info),
                'detail' => __('De twee pagina’s achter de kaarten op de homepage.', 'cjw'),
            ],
            'privacy_page' => [
                'label' => __('Privacyverklaring', 'cjw'),
                'done' => $privacy > 0 && get_post($privacy) instanceof WP_Post,
                'detail' => __('De pagina waar het inschrijfformulier naar verwijst. Wordt als concept aangemaakt; publiceer hem zelf na het nalezen.', 'cjw'),
            ],
            'menu' => [
                'label' => __('Hoofdmenu', 'cjw'),
                'done' => !$menuLocationExists || has_nav_menu(self::MENU_LOCATION),
                'detail' => $menuLocationExists
                    ? __('Een menu met de pagina’s hierboven, gekoppeld aan de menuplek van het thema.', 'cjw')
                    : __('Het actieve thema heeft geen menuplek; er is niets in te richten.', 'cjw'),
            ],
        ];
    }

    /**
     * Whether everything is in place.
     *
     * @return bool
     */
    public static function isComplete(): bool
    {
        foreach (self::status() as $item) {
            if (!$item['done']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The parts that are missing, by key.
     *
     * @return array<int, string>
     */
    public static function missing(): array
    {
        return array_keys(array_filter(self::status(), static fn(array $item): bool => !$item['done']));
    }

    /**
     * Creates whatever is missing, and nothing else.
     *
     * The order matters: the form before the page that embeds it, the pages
     * before the menu that links to them.
     *
     * @return array<int, string> Keys of the parts that were created.
     */
    public function run(): array
    {
        $created = [];
        $status = self::status();

        if (!$status['form']['done']) {
            $this->createForm();
            $created[] = 'form';
        }

        if (!$status['registration_page']['done']) {
            $this->createRegistrationPage();
            $created[] = 'registration_page';
        }

        if (!$status['card_pages']['done']) {
            $this->createCardPages();
            $created[] = 'card_pages';
        }

        if (!$status['front_page']['done']) {
            $this->createFrontPage();
            $created[] = 'front_page';
        }

        if (!$status['privacy_page']['done']) {
            $this->createPrivacyPage();
            $created[] = 'privacy_page';
        }

        if (!$status['menu']['done']) {
            $this->createMenu();
            $created[] = 'menu';
        }

        return $created;
    }

    /**
     * Handles the button.
     *
     * @return void
     */
    public function handleRun(): void
    {
        $this->authorise(self::ACTION);

        $created = $this->run();

        $this->returnToBeheer($created === [] ? 'setup_nothing' : 'setup_done');
    }

    /**
     * Hides the notice for a site that is deliberately left as it is.
     *
     * @return void
     */
    public function handleDismiss(): void
    {
        $this->authorise(self::ACTION_DISMISS);

        update_option(self::OPTION_DISMISSED, '1', false);

        $referer = wp_get_referer();

        wp_safe_redirect($referer !== false ? $referer : admin_url());
        exit;
    }

    /**
     * The notice on every admin screen while parts are missing.
     *
     * @return void
     */
    public function renderNotice(): void
    {
        if (!self::mayRun() || get_option(self::OPTION_DISMISSED)) {
            return;
        }

        // Not on the Beheer tab itself, where the panel says the same thing.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which tab is open; nothing is changed here.
        if (isset($_GET['page'], $_GET['tab']) && $_GET['page'] === 'cjw-summer-camp' && $_GET['tab'] === 'beheer') {
            return;
        }

        $missing = self::missing();

        if ($missing === []) {
            return;
        }

        $status = self::status();
        $labels = array_map(static fn(string $key): string => $status[$key]['label'], $missing);

        ?>
        <div class="notice notice-info">
            <p>
                <strong><?php esc_html_e('De website is nog niet helemaal ingericht.', 'cjw'); ?></strong>
                <?php
                echo esc_html(sprintf(
                    /* translators: %s: comma-separated list of missing parts. */
                    __('Dit ontbreekt nog: %s.', 'cjw'),
                    implode(', ', $labels)
                ));
                ?>
                <a href="<?php echo esc_url(self::beheerUrl()); ?>"><?php esc_html_e('Richt de website in met één klik', 'cjw'); ?></a>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::ACTION_DISMISS); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_DISMISS); ?>">
                <?php submit_button(__('Zo laten, verberg deze melding', 'cjw'), 'link', 'submit', false); ?>
            </form>
            <p></p>
        </div>
        <?php
    }

    /**
     * The panel on the Beheer tab.
     *
     * @return void
     */
    public static function renderPanel(): void
    {
        $status = self::status();
        $complete = self::isComplete();

        ?>
        <div class="cjw-tool-panel<?php echo $complete ? '' : ' cjw-tool-panel--warn'; ?>">
            <h2><?php esc_html_e('Website inrichten', 'cjw'); ?></h2>

            <p class="description">
                <?php esc_html_e('De pagina’s en het menu die de website nodig heeft om te werken. Wat er al is, blijft ongemoeid; alleen wat ontbreekt wordt aangemaakt.', 'cjw'); ?>
            </p>

            <table class="widefat striped cjw-tool-table">
                <tbody>
                    <?php foreach ($status as $item) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($item['label']); ?></th>
                            <td>
                                <?php if ($item['done']) : ?>
                                    <span aria-hidden="true">✓</span> <?php esc_html_e('Aanwezig.', 'cjw'); ?>
                                <?php else : ?>
                                    <strong><?php esc_html_e('Ontbreekt.', 'cjw'); ?></strong>
                                <?php endif; ?>
                                <?php echo esc_html($item['detail']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!$complete && self::mayRun()) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cjw-tool-form">
                    <?php wp_nonce_field(self::ACTION); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                    <?php submit_button(__('Maak aan wat ontbreekt', 'cjw'), 'primary', 'submit', false); ?>
                </form>
            <?php elseif (!$complete) : ?>
                <p class="description"><?php esc_html_e('Alleen een beheerder die pagina’s mag publiceren kan dit aanmaken.', 'cjw'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Whether the current user may set the site up.
     *
     * Creating and publishing pages, and pointing the site's front page at one
     * of them, is an editor's job; the camp capability alone is not enough.
     *
     * @return bool
     */
    public static function mayRun(): bool
    {
        return current_user_can('publish_pages') && (CJW_Roles::currentUserCan() || current_user_can('manage_options'));
    }

    private function createForm(): int
    {
        return (int) wp_insert_post([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_status' => 'draft',
            'post_title' => __('Inschrijfformulier', 'cjw'),
        ]);
    }

    private function createRegistrationPage(): int
    {
        $form = self::firstForm();
        $shortcode = $form > 0 ? sprintf('[cjw_wizard id="%d"]', $form) : '[cjw_wizard]';

        $id = $this->createPage(
            __('Inschrijven', 'cjw'),
            'inschrijven',
            "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->"
        );

        if (class_exists('CJW_Registration_Page')) {
            CJW_Registration_Page::flush();
        }

        return $id;
    }

    private function createCardPages(): void
    {
        $camp = cjw_summer_camp();
        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        if (!self::pageIsLive($camp->getCardZomerkampPageId())) {
            $settings['card_zomerkamp_page_id'] = $this->createPage(
                __('Zomerkamp', 'cjw'),
                'zomerkamp',
                self::starterContent(
                    __('Hoe ziet een kampweek eruit?', 'cjw'),
                    __('Vertel hier over de leeftijdsgroepen, het programma, het slapen in tenten en het leven op het kampterrein. Deze pagina zit achter de kaart “Zomerkamp” op de homepage.', 'cjw')
                )
            );
        }

        if (!self::pageIsLive($camp->getCardInfoPageId())) {
            $settings['card_info_page_id'] = $this->createPage(
                __('Praktische info', 'cjw'),
                'praktische-info',
                self::starterContent(
                    __('Alles wat ouders willen weten', 'cjw'),
                    __('Paklijst, data, kosten, vervoer, medicijnen en de antwoorden op de vragen die elk jaar terugkomen. Deze pagina zit achter de kaart “Praktische info” op de homepage.', 'cjw')
                )
            );
        }

        update_option(CJW_Summer_Camp_Service::OPTION_KEY, $settings);
        CJW_Summer_Camp_Service::flush();
    }

    private function createFrontPage(): void
    {
        $blocks = ['hero', 'intro', 'jaarthema', 'cards', 'photos', 'sponsors'];
        $content = implode("\n", array_map(static fn(string $block): string => "<!-- wp:cjw/{$block} /-->", $blocks));

        $id = $this->createPage(__('Home', 'cjw'), 'home', $content);

        update_option('show_on_front', 'page');
        update_option('page_on_front', $id);
    }

    private function createPrivacyPage(): void
    {
        $content = '';

        if (!class_exists('WP_Privacy_Policy_Content') && defined('ABSPATH') && is_readable(ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
        }

        if (class_exists('WP_Privacy_Policy_Content')) {
            $content = (string) WP_Privacy_Policy_Content::get_default_content(false, true);
        }

        if ($content === '') {
            $content = self::starterContent(
                __('Privacyverklaring', 'cjw'),
                __('Beschrijf hier welke gegevens het inschrijfformulier vraagt, waarom, wie ze kan zien en hoe lang ze bewaard blijven.', 'cjw')
            );
        }

        $id = $this->createPage(__('Privacyverklaring', 'cjw'), 'privacyverklaring', $content, 'draft');

        update_option('wp_page_for_privacy_policy', $id);
    }

    private function createMenu(): void
    {
        $existing = wp_get_nav_menu_object(__('Hoofdmenu', 'cjw'));
        $menuId = $existing instanceof WP_Term ? (int) $existing->term_id : 0;

        if ($menuId === 0) {
            $created = wp_create_nav_menu(__('Hoofdmenu', 'cjw'));
            $menuId = is_wp_error($created) ? 0 : (int) $created;
        }

        if ($menuId === 0) {
            return;
        }

        // Only fill a menu that is empty; an existing "Hoofdmenu" somebody
        // built by hand is theirs.
        if (wp_get_nav_menu_items($menuId) === [] || wp_get_nav_menu_items($menuId) === false) {
            $camp = cjw_summer_camp();
            $front = (int) get_option('page_on_front', 0);

            $this->addMenuLink($menuId, __('Home', 'cjw'), home_url('/'), $front);
            $this->addMenuLink($menuId, __('Zomerkamp', 'cjw'), '', $camp->getCardZomerkampPageId());
            $this->addMenuLink($menuId, __('Praktische info', 'cjw'), '', $camp->getCardInfoPageId());

            if (class_exists('CJW_Registration_Page')) {
                $this->addMenuLink($menuId, __('Inschrijven', 'cjw'), '', CJW_Registration_Page::id());
            }

            $this->addMenuLink($menuId, __('Sponsoren', 'cjw'), home_url('/#sponsoren'));
            $this->addMenuLink($menuId, __('Contact', 'cjw'), home_url('/#contact'));
        }

        $locations = get_theme_mod('nav_menu_locations', []);
        $locations = is_array($locations) ? $locations : [];
        $locations[self::MENU_LOCATION] = $menuId;

        set_theme_mod('nav_menu_locations', $locations);
    }

    /**
     * Adds one menu item: a page when an id is given, otherwise a plain link.
     *
     * @param int    $menuId Menu term id.
     * @param string $title  Item label.
     * @param string $url    Custom link, when not a page.
     * @param int    $pageId Page to link to, or 0.
     *
     * @return void
     */
    private function addMenuLink(int $menuId, string $title, string $url = '', int $pageId = 0): void
    {
        if ($pageId > 0 && get_post($pageId) instanceof WP_Post) {
            wp_update_nav_menu_item($menuId, 0, [
                'menu-item-title' => $title,
                'menu-item-type' => 'post_type',
                'menu-item-object' => 'page',
                'menu-item-object-id' => $pageId,
                'menu-item-status' => 'publish',
            ]);

            return;
        }

        if ($url === '') {
            return;
        }

        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => $title,
            'menu-item-type' => 'custom',
            'menu-item-url' => $url,
            'menu-item-status' => 'publish',
        ]);
    }

    /**
     * Creates one page.
     *
     * @param string $title   Page title.
     * @param string $slug    Wanted slug; WordPress makes it unique.
     * @param string $content Block markup.
     * @param string $status  Post status.
     *
     * @return int Page id, or 0 on failure.
     */
    private function createPage(string $title, string $slug, string $content, string $status = 'publish'): int
    {
        $id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => $status,
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $content,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ]);

        return is_wp_error($id) ? 0 : (int) $id;
    }

    /**
     * A heading and a paragraph telling the editor what belongs here.
     *
     * @param string $heading   Section heading.
     * @param string $paragraph Instruction to the editor.
     *
     * @return string Block markup.
     */
    private static function starterContent(string $heading, string $paragraph): string
    {
        return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html($heading) . "</h2>\n<!-- /wp:heading -->\n\n"
            . "<!-- wp:paragraph -->\n<p>" . esc_html($paragraph) . "</p>\n<!-- /wp:paragraph -->";
    }

    /**
     * The first form in the builder, published if there is one, or 0.
     *
     * @return int
     */
    private static function firstForm(): int
    {
        if (!class_exists('CJW_Wizard_Post_Type')) {
            return 0;
        }

        foreach (['publish', ['draft', 'pending', 'private', 'future']] as $status) {
            $forms = get_posts([
                'post_type' => CJW_Wizard_Post_Type::SLUG,
                'post_status' => $status,
                'numberposts' => 1,
                'orderby' => 'date',
                'order' => 'ASC',
                'fields' => 'ids',
            ]);

            if ($forms !== []) {
                return (int) $forms[0];
            }
        }

        return 0;
    }

    private static function pageIsLive(int $id): bool
    {
        return $id > 0 && get_post_status($id) === 'publish';
    }

    private static function beheerUrl(): string
    {
        return add_query_arg(
            [
                'page' => 'cjw-summer-camp',
                'tab' => 'beheer',
            ],
            admin_url('admin.php')
        );
    }

    /**
     * Stops anyone who may not set the site up, and checks the nonce.
     *
     * @param string $action The admin-post action being handled.
     *
     * @return void
     */
    private function authorise(string $action): void
    {
        if (!self::mayRun()) {
            wp_die(esc_html__('Je hebt geen toestemming om de website in te richten.', 'cjw'));
        }

        check_admin_referer($action);
    }

    /**
     * Returns to the Beheer tab carrying a result flag.
     *
     * @param string $result Result flag the Beheer tab renders a notice for.
     *
     * @return never
     */
    private function returnToBeheer(string $result): void
    {
        wp_safe_redirect(add_query_arg(['cjw_result' => $result], self::beheerUrl()));

        exit;
    }
}
