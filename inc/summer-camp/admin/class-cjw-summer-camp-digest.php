<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sends the dashboard widget to the people who never log in to read it.
 *
 * The widget already computes camp status, an attention list and quick links,
 * and renders them on a screen the organisers open a few times a year. Nothing
 * was wrong with the checks; the delivery was wrong.
 *
 * Deliberately quiet when there is nothing to say. A weekly mail that reads
 * "alles in orde" for eight months teaches its recipient to filter it, and then
 * it is not read on the week it finally matters. Whether the site is up at all
 * is a different question, answered by an uptime monitor rather than by mail
 * the site sends itself -- a dead site sends nothing, and silence from
 * something that mails weekly is indistinguishable from silence because all is
 * well.
 */
final class CJW_Summer_Camp_Digest
{
    /**
     * Weekly cron hook.
     */
    public const string HOOK = 'cjw_summer_camp_weekly_digest';

    /**
     * The admin-post action behind the preview button.
     */
    public const string ACTION_PREVIEW = 'cjw_preview_digest';

    /**
     * The admin-post action behind the on/off switch.
     */
    public const string ACTION_TOGGLE = 'cjw_toggle_digest';

    /**
     * Settings for the digest, kept out of the main option so the weekly mail
     * cannot be switched on or off by restoring an older settings snapshot.
     */
    public const string OPTION_KEY = 'cjw_summer_camp_digest';

    /**
     * How close the deadline has to be before it is worth mentioning on its own.
     */
    private const int DEADLINE_NOTICE_WEEKS = 6;

    /**
     * Lets other modules add their own section to the mail.
     */
    public const string FILTER_SECTIONS = 'cjw_summer_camp_digest_sections';

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        add_action(self::HOOK, [$instance, 'runScheduled']);

        // Watched even when the mail itself is switched off: the schedule is
        // what proves cron is alive, and a silent digest and a dead cron look
        // identical from the outside. On init, because the label is translated
        // and the text domain is not loaded before then.
        add_action('init', static function (): void {
            CJW_Cron_Heartbeat::watch(
                self::HOOK,
                __('Weekbericht', 'cjw'),
                9 * DAY_IN_SECONDS
            );
        });
        add_action('admin_post_' . self::ACTION_PREVIEW, [$instance, 'handlePreview']);
        add_action('admin_post_' . self::ACTION_TOGGLE, [$instance, 'handleToggle']);

        // Self-healing, for the same reasons the retention sweep is: a botched
        // deactivation or a restored database should not silently end the
        // schedule for ever.
        if (self::isEnabled() && !wp_next_scheduled(self::HOOK)) {
            self::schedule();
        }
    }

    /**
     * Registers the weekly send.
     *
     * @return void
     */
    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK)) {
            return;
        }

        wp_schedule_event(self::nextMondayMorning(), 'weekly', self::HOOK);
    }

    /**
     * Cancels the weekly send.
     *
     * @return void
     */
    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    /**
     * Monday morning, in the site's own timezone.
     *
     * Monday because the week's registrations have accumulated over the weekend,
     * which is when parents actually fill the form in.
     *
     * @return int
     */
    private static function nextMondayMorning(): int
    {
        $monday = new DateTimeImmutable('now', wp_timezone())
            ->modify('next monday')
            ->setTime(8, 0);

        return $monday->getTimestamp();
    }

    /**
     * Cron entry point.
     *
     * @return void
     */
    public function runScheduled(): void
    {
        $this->maybeSend();

        CJW_Cron_Heartbeat::record(self::HOOK);
    }

    /**
     * Sends the digest if it is switched on and has something to report.
     *
     * @return bool Whether a mail was sent.
     */
    public function maybeSend(): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $sections = self::sections();

        if ($sections === []) {
            return false;
        }

        return $this->send($sections) > 0;
    }

    /**
     * Sends the digest to everyone who runs the camp.
     *
     * One message each rather than one message with several recipients:
     * volunteers' addresses are not the site's to introduce to each other, and
     * a To line full of them is how that happens by accident.
     *
     * @param array<int, array{title: string, lines: array<int, string>}> $sections
     *
     * @return int How many messages were handed off without error.
     */
    public function send(array $sections): int
    {
        $subject = self::subject();
        $body = self::body($sections);
        $sent = 0;

        foreach (self::recipients() as $to) {
            if (wp_mail($to, $subject, $body)) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * Everything worth telling an organiser this week.
     *
     * An empty array means nothing happened and no mail is warranted.
     *
     * @return array<int, array{title: string, lines: array<int, string>}>
     */
    public static function sections(): array
    {
        $sections = [];

        $attention = self::failingChecks();

        if ($attention !== []) {
            $sections[] = [
                'title' => __('Dit heeft aandacht nodig', 'cjw'),
                'lines' => $attention,
            ];
        }

        $deadline = self::deadlineLines();

        if ($deadline !== []) {
            $sections[] = [
                'title' => __('Inschrijvingen', 'cjw'),
                'lines' => $deadline,
            ];
        }

        /**
         * Lets other modules add a section.
         *
         * The summer-camp module cannot count registrations; that is the form
         * builder's data and its business.
         *
         * @param array<int, array{title: string, lines: array<int, string>}> $sections
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- FILTER_SECTIONS is 'cjw_summer_camp_digest_sections'; the sniff cannot resolve a constant, and spelling the name twice is how the two copies drift apart.
        $sections = apply_filters(self::FILTER_SECTIONS, $sections);

        return is_array($sections) ? $sections : [];
    }

    /**
     * The camp's own Site Health checks that are not passing.
     *
     * Reuses the checks rather than restating them, so a check added there is
     * in the mail the same week.
     *
     * @return array<int, string>
     */
    private static function failingChecks(): array
    {
        $health = new CJW_Summer_Camp_Site_Health();
        $tests = $health->registerTests([]);
        $failing = [];

        foreach ($tests['direct'] ?? [] as $test) {
            $callback = $test['test'] ?? null;

            if (!is_callable($callback)) {
                continue;
            }

            $result = $callback();

            if (!is_array($result) || ($result['status'] ?? 'good') === 'good') {
                continue;
            }

            $failing[] = (string) ($result['label'] ?? '');
        }

        return array_values(array_filter($failing));
    }

    /**
     * The deadline, when it is close enough to matter.
     *
     * @return array<int, string>
     */
    private static function deadlineLines(): array
    {
        $camp = cjw_summer_camp();
        $deadline = $camp->getRegistrationDeadline();

        if (!$deadline instanceof DateTimeImmutable) {
            return [];
        }

        $today = new DateTimeImmutable('now', wp_timezone())->setTime(0, 0);

        if ($today > $deadline) {
            return [];
        }

        $days = (int) $today->diff($deadline)->days;

        if ($days > self::DEADLINE_NOTICE_WEEKS * 7) {
            return [];
        }

        return [
            sprintf(
                /* translators: 1: number of days, 2: deadline date. */
                _n(
                    'Nog %1$d dag tot de inschrijfdeadline van %2$s.',
                    'Nog %1$d dagen tot de inschrijfdeadline van %2$s.',
                    $days,
                    'cjw'
                ),
                $days,
                wp_date((string) get_option('date_format'), $deadline->getTimestamp())
            ),
            $camp->isRegistrationOpen()
                ? __('De inschrijvingen staan open.', 'cjw')
                : __('Let op: de inschrijvingen staan dicht.', 'cjw'),
        ];
    }

    /**
     * The subject line.
     *
     * @return string
     */
    private static function subject(): string
    {
        return sprintf(
            /* translators: %s: site name. */
            __('Weekbericht %s', 'cjw'),
            get_bloginfo('name')
        );
    }

    /**
     * The message body.
     *
     * Plain text and no personal data, for the same reason the organiser's
     * notification carries none: this goes to a mailbox, and mailboxes are
     * forwarded, backed up and read on trains.
     *
     * @param array<int, array{title: string, lines: array<int, string>}> $sections
     *
     * @return string
     */
    private static function body(array $sections): string
    {
        $lines = [];

        foreach ($sections as $section) {
            $lines[] = (string) ($section['title'] ?? '');
            $lines[] = str_repeat('-', mb_strlen((string) ($section['title'] ?? '')));

            foreach ($section['lines'] ?? [] as $line) {
                $lines[] = '- ' . $line;
            }

            $lines[] = '';
        }

        $lines[] = __('Bekijk het zelf:', 'cjw');
        $lines[] = admin_url('admin.php?page=cjw-summer-camp');
        $lines[] = '';
        $lines[] = __('Dit bericht wordt alleen verstuurd als er iets te melden is. Je kunt het uitzetten op de Beheer-tab van de zomerkampinstellingen.', 'cjw');

        return implode(PHP_EOL, $lines);
    }

    /**
     * Whether the weekly mail is switched on.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        $settings = get_option(self::OPTION_KEY, []);

        return is_array($settings) && !empty($settings['enabled']);
    }

    /**
     * Who the digest goes to: everyone holding the camp capability.
     *
     * Nobody has to remember to add the new volunteer to a list, and nobody is
     * mailed who could not already read all of this by logging in. The cost is
     * that it cannot be declined individually, which is why it is off until
     * somebody switches it on.
     *
     * @return array<int, string>
     */
    public static function recipients(): array
    {
        $addresses = [];

        foreach (CJW_Roles::campManagers() as $user) {
            $email = (string) $user->user_email;

            if (is_email($email)) {
                $addresses[] = $email;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Switches the weekly mail on or off.
     *
     * @return void
     */
    public function handleToggle(): void
    {
        $this->assertPermitted();
        check_admin_referer(self::ACTION_TOGGLE);

        $enabled = !empty($_POST['cjw_digest_enabled']);

        update_option(self::OPTION_KEY, [ 'enabled' => $enabled ], false);

        if ($enabled) {
            self::schedule();
        } else {
            self::unschedule();
        }

        $this->returnToBeheer($enabled ? 'digest_on' : 'digest_off');
    }

    /**
     * Sends this week's digest now, so somebody can see what it looks like.
     *
     * @return void
     */
    public function handlePreview(): void
    {
        $this->assertPermitted();
        check_admin_referer(self::ACTION_PREVIEW);

        $sections = self::sections();

        if ($sections === []) {
            $sections = [
                [
                    'title' => __('Niets te melden', 'cjw'),
                    'lines' => [ __('Er is deze week niets bijzonders. Op een gewone week wordt dit bericht dan ook niet verstuurd.', 'cjw') ],
                ],
            ];
        }

        $this->returnToBeheer($this->send($sections) > 0 ? 'digest_sent' : 'digest_failed');
    }

    /**
     * Renders the panel on the Beheer tab.
     *
     * @return void
     */
    public static function renderPanel(): void
    {
        $enabled = self::isEnabled();
        $next = wp_next_scheduled(self::HOOK);
        $recipients = self::recipients();

        ?>
        <div class="cjw-tool-panel">
            <h2><?php esc_html_e('Weekbericht', 'cjw'); ?></h2>

            <p class="description">
                <?php esc_html_e('Een mail op maandagochtend met wat er die week aandacht nodig heeft: nieuwe inschrijvingen, instellingen die nog ontbreken, en de deadline als die dichtbij komt. Is er niets te melden, dan komt er ook geen mail.', 'cjw'); ?>
            </p>

            <?php if ($enabled && $next !== false) : ?>
                <p>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: date and time of the next scheduled send. */
                        __('Het volgende weekbericht staat gepland voor %s.', 'cjw'),
                        wp_date(__('j F Y \o\m H:i', 'cjw'), $next)
                    ));
                    ?>
                </p>
            <?php endif; ?>

            <p>
                <?php esc_html_e('Het weekbericht gaat naar iedereen die het kamp beheert:', 'cjw'); ?>
                <?php if ($recipients === []) : ?>
                    <strong><?php esc_html_e('op dit moment niemand met een geldig e-mailadres.', 'cjw'); ?></strong>
                <?php else : ?>
                    <?php echo esc_html(implode(', ', $recipients)); ?>
                <?php endif; ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cjw-tool-form">
                <?php wp_nonce_field(self::ACTION_TOGGLE); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_TOGGLE); ?>" />

                <label for="cjw-digest-enabled" style="font-weight:400;">
                    <input
                        type="checkbox"
                        id="cjw-digest-enabled"
                        name="cjw_digest_enabled"
                        value="1"
                        <?php checked($enabled); ?>
                    />
                    <?php esc_html_e('Stuur het weekbericht', 'cjw'); ?>
                </label>

                <?php submit_button(__('Opslaan', 'cjw'), 'secondary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cjw-tool-form">
                <?php wp_nonce_field(self::ACTION_PREVIEW); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_PREVIEW); ?>" />
                <?php submit_button(__('Stuur nu een voorbeeld', 'cjw'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Stops anyone without the camp capability.
     *
     * @return void
     */
    private function assertPermitted(): void
    {
        if (!CJW_Roles::currentUserCan()) {
            wp_die(esc_html__('Je hebt geen toestemming om deze actie uit te voeren.', 'cjw'));
        }
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
