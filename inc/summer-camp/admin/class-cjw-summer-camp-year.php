<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns the yearly changeover into one button with a visible consequence.
 *
 * The camp is annual, and the settings screen gives no signal about which of
 * its fields are this-year values and which are structural. Whoever runs the
 * 2027 camp will either miss one or overwrite something they should not have
 * touched, because the checklist that would have told them apart only ever
 * existed in somebody's head.
 *
 * What this deliberately does not do is clear the images and the copy. Those
 * are the fields an organiser can see are stale simply by looking at the site,
 * and wiping them would replace a homepage that is a year out of date with a
 * homepage that is empty -- the worse of the two, and the one that takes an
 * evening to undo.
 *
 * The dates are the opposite case: nothing on the site shows they are stale
 * until a parent is told the camp starts on a day in the past.
 */
final class CJW_Summer_Camp_Year
{
    /**
     * The admin-post action behind the button.
     */
    public const string ACTION = 'cjw_start_camp_year';

    /**
     * Fired once the new year is in place, so other modules can act on it.
     *
     * The summer-camp module knows nothing about registrations, and should not
     * have to. It says what happened; whoever cares listens.
     */
    public const string HOOK_STARTED = 'cjw_summer_camp_year_started';

    /**
     * Collects what other modules will do, before anything is committed.
     */
    public const string FILTER_EFFECTS = 'cjw_summer_camp_year_start_effects';

    /**
     * The settings a new camp year invalidates.
     *
     * Every one of these is a date or a date range. They are the fields that go
     * from correct to actively misleading the moment a season ends, and the
     * ones nobody can spot as wrong by looking at the homepage.
     *
     * @var array<int, string>
     */
    public const array DATE_FIELDS = [
        'start_date',
        'end_date',
        'registration_deadline',
        'early_bird_start',
        'early_bird_end',
        'drive_dates',
    ];

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION, [new self(), 'handleStart']);
    }

    /**
     * Handles the button.
     *
     * @return void
     */
    public function handleStart(): void
    {
        if (!CJW_Roles::currentUserCan()) {
            wp_die(esc_html__('Je hebt geen toestemming om een nieuw kampjaar te starten.', 'cjw'));
        }

        check_admin_referer(self::ACTION);

        $confirmed = isset($_POST['cjw_confirm_year'])
            ? sanitize_text_field(wp_unslash((string) $_POST['cjw_confirm_year']))
            : '';

        $outgoing = self::currentYear();
        $incoming = self::nextYear();

        // Typing the year is the confirmation. A checkbox gets ticked without
        // reading; the one destructive action on this screen is worth the two
        // seconds it costs to type four digits.
        if ($confirmed !== $incoming) {
            $this->returnToBeheer('year_not_confirmed');
        }

        $this->start($outgoing, $incoming);

        $this->returnToBeheer('year_started');
    }

    /**
     * Rolls the settings over and announces it.
     *
     * @param string $outgoing The year being closed.
     * @param string $incoming The year being prepared.
     *
     * @return void
     */
    public function start(string $outgoing, string $incoming): void
    {
        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        // Names the snapshot that the update_option below will take anyway,
        // rather than taking a second one here: the history list should say
        // what this save was, a year later when somebody is looking for it.
        CJW_Summer_Camp_Settings_History::nameNextSnapshot(sprintf(
            /* translators: %s: the camp year that was closed. */
            __('Archief van kampjaar %s', 'cjw'),
            $outgoing
        ));

        foreach (self::DATE_FIELDS as $field) {
            $settings[$field] = is_array($settings[$field] ?? null) ? [] : '';
        }

        $settings['theme_year'] = $incoming;

        // Not optional, and not a separate decision. isRegistrationOpen() treats
        // an empty deadline as "no deadline", so clearing the dates while
        // registration is still active would throw the form open indefinitely
        // for a camp with no date -- the precise opposite of what starting a new
        // year means.
        $settings['registration_active'] = false;

        update_option(CJW_Summer_Camp_Service::OPTION_KEY, $settings);

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- HOOK_STARTED is 'cjw_summer_camp_year_started'; the sniff cannot resolve a constant, and spelling the name twice is how the two copies drift apart.
        do_action(self::HOOK_STARTED, $outgoing, $incoming);
    }

    /**
     * The camp year now ending.
     *
     * Read from the theme year, which is what the organiser actually maintains,
     * and falls back to the start date and then to the calendar.
     *
     * @return string
     */
    public static function currentYear(): string
    {
        $camp = cjw_summer_camp();
        $year = trim($camp->getThemeYear());

        if (preg_match('/^\d{4}$/', $year)) {
            return $year;
        }

        $start = $camp->getStartDate();

        if ($start instanceof DateTimeImmutable) {
            return $start->format('Y');
        }

        return wp_date('Y');
    }

    /**
     * The year being prepared.
     *
     * @return string
     */
    public static function nextYear(): string
    {
        return (string) ((int) self::currentYear() + 1);
    }

    /**
     * Everything starting a new year will change, in plain Dutch.
     *
     * Built before anything happens and shown on the button, because a
     * destructive action nobody can see the extent of is one people either
     * avoid for years or press by accident.
     *
     * @return array<int, string>
     */
    public static function effects(): array
    {
        $outgoing = self::currentYear();

        $effects = [
            sprintf(
                /* translators: 1: outgoing camp year, 2: incoming camp year. */
                __('De huidige instellingen worden bewaard als “Archief van kampjaar %1$s”, en het jaarthema springt naar %2$s.', 'cjw'),
                $outgoing,
                self::nextYear()
            ),
            __('De kampdata, de inschrijfdeadline, de rijdata en de periode van de vroegboekkorting worden leeggemaakt.', 'cjw'),
            __('De inschrijvingen gaan dicht, totdat je ze zelf weer openzet.', 'cjw'),
            __('Foto’s, teksten, prijzen, kleuren en de contactgegevens blijven staan.', 'cjw'),
        ];

        /**
         * Lets other modules say what a new camp year does to their data.
         *
         * @param array<int, string> $effects  Lines gathered so far.
         * @param string             $outgoing The year being closed.
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- FILTER_EFFECTS is 'cjw_summer_camp_year_start_effects'; see the note on HOOK_STARTED.
        $effects = apply_filters(self::FILTER_EFFECTS, $effects, $outgoing);

        return is_array($effects) ? $effects : [];
    }

    /**
     * Renders the panel on the Beheer tab.
     *
     * @return void
     */
    public static function renderPanel(): void
    {
        $incoming = self::nextYear();

        ?>
        <div class="cjw-tool-panel cjw-tool-panel--warn">
            <h2><?php esc_html_e('Nieuw kampjaar starten', 'cjw'); ?></h2>

            <p class="description">
                <?php esc_html_e('Doe dit één keer per jaar, als het kamp achter de rug is en je aan het volgende jaar begint. Dit is wat er dan gebeurt:', 'cjw'); ?>
            </p>

            <ul class="cjw-tool-list">
                <?php foreach (self::effects() as $effect) : ?>
                    <li><?php echo esc_html($effect); ?></li>
                <?php endforeach; ?>
            </ul>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cjw-tool-form">
                <?php wp_nonce_field(self::ACTION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />

                <label for="cjw-confirm-year">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: the new camp year. */
                        __('Typ %s om te bevestigen', 'cjw'),
                        $incoming
                    ));
                    ?>
                </label>
                <input
                    type="text"
                    id="cjw-confirm-year"
                    name="cjw_confirm_year"
                    class="small-text"
                    inputmode="numeric"
                    autocomplete="off"
                    required
                />
                <?php submit_button(__('Start kampjaar', 'cjw') . ' ' . $incoming, 'primary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Returns to the Beheer tab carrying a result flag.
     *
     * @param string $result Result key the panel renders a notice for.
     *
     * @return never
     */
    private function returnToBeheer(string $result): void
    {
        wp_safe_redirect(add_query_arg(
            [
                'page' => 'cjw-summer-camp',
                'tab' => 'beheer',
                'cjw_result' => $result,
            ],
            admin_url('admin.php')
        ));

        exit;
    }
}
