<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Wishes a registered child a happy birthday.
 *
 * The two settings behind this have been on the Inhoud tab for a long time,
 * both labelled "nog niet actief". An admin screen that collects input it then
 * ignores teaches people not to trust the rest of the screen, so this either had
 * to be built or the fields had to come out.
 *
 * It reads birth dates that are already stored for another reason, and it is
 * bounded by the same window that governs them: a registration is greeted for
 * exactly as long as the retention setting says its data may be kept, and not a
 * day longer. Set retention to four months and a child is wished a happy
 * birthday if it falls in those four months; after that the row is on its way
 * to being anonymised and nothing is sent.
 *
 * That is the whole privacy position: no new data is kept, nothing is kept for
 * longer, and the greeting cannot outlive the reason the birth date is there.
 *
 * Sent to the address that registered, never to a child. The form asks for a
 * parent's address, and that is the only address there is.
 */
final class CJW_Wizard_Birthday_Mailer
{
    /**
     * Daily cron hook.
     */
    public const string HOOK = 'cjw_wizard_birthday_mail';

    /**
     * Records which birthdays have been greeted, so a second run on the same
     * day sends nothing.
     *
     * Keyed per submission and holding one entry per child per year, because
     * cron can fire more than once a day and a duplicate birthday mail is a
     * small embarrassment that arrives at scale.
     */
    public const string META_GREETED = '_cjw_wizard_birthday_greeted';

    /**
     * Registrations examined per run.
     *
     * A camp year is a few hundred rows, so this is a ceiling rather than a
     * pagination scheme.
     */
    private const int BATCH = 500;

    private CJW_Wizard_Submission_Repository $repository;

    public function __construct(CJW_Wizard_Submission_Repository $repository)
    {
        $this->repository = $repository;
    }

    public function init(): void
    {
        add_action(self::HOOK, [ $this, 'run_scheduled' ]);

        // Self-healing, like the retention sweep: a botched deactivation or a
        // restored database should not end the schedule for ever.
        if (! wp_next_scheduled(self::HOOK)) {
            self::schedule();
        }
    }

    /**
     * Registers the daily run, early enough to be a morning greeting.
     */
    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK)) {
            return;
        }

        $tomorrow = new DateTimeImmutable('tomorrow', wp_timezone())->setTime(8, 0);

        wp_schedule_event($tomorrow->getTimestamp(), 'daily', self::HOOK);
    }

    /**
     * Cancels the daily run, for deactivation.
     */
    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    /**
     * Cron entry point.
     *
     * Separate from send_todays_greetings() because an action callback must not
     * return a value, while the count is worth having for tests and for running
     * the job by hand.
     */
    public function run_scheduled(): void
    {
        $this->send_todays_greetings();
    }

    /**
     * Greets every child whose birthday is today.
     *
     * @return int Messages sent.
     */
    public function send_todays_greetings(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $today = new DateTimeImmutable('now', wp_timezone());
        $sent = 0;

        foreach ($this->registrationsInRetention() as $submission) {
            $sent += $this->greet((int) $submission->ID, $today);
        }

        return $sent;
    }

    /**
     * Greets the children of one registration who have a birthday today.
     *
     * @return int Messages sent for this registration.
     */
    private function greet(int $submission_id, DateTimeImmutable $today): int
    {
        $to = $this->repository->get_email($submission_id);

        if ($to === '' || ! is_email($to)) {
            return 0;
        }

        $config = $this->repository->get_config($submission_id);
        $payload = $this->repository->get_payload($submission_id);

        if ($config === [] || $payload === []) {
            return 0;
        }

        $greeted = $this->alreadyGreeted($submission_id);
        $sent = 0;

        foreach (CJW_Wizard_Field_Roles::childRows($config, $payload) as $index => $child) {
            $name = trim((string) ($child['name'] ?? ''));
            $age = self::ageToday((string) ($child['birth_date'] ?? ''), $today);

            if ($name === '' || $age === null) {
                continue;
            }

            $marker = $today->format('Y') . ':' . $index;

            if (in_array($marker, $greeted, true)) {
                continue;
            }

            $delivered = CJW_Mailer::send(CJW_Mail_Templates::BIRTHDAY, $to, [
                '{child_name}' => $name,
                '{child_age}' => (string) $age,
                ...CJW_Mail_Tokens::camp(),
            ]);

            // Recorded whether or not it went out. A failing mail server would
            // otherwise mean the same family is retried every hour of the day,
            // and the birthday is over by the time anyone notices.
            $greeted[] = $marker;

            if ($delivered) {
                ++$sent;
            }
        }

        update_post_meta($submission_id, self::META_GREETED, $greeted);

        return $sent;
    }

    /**
     * The age a child turns today, or null when today is not their birthday.
     *
     * Accepts the formats the date field writes: dd-mm-yyyy and yyyy-mm-dd.
     */
    public static function ageToday(string $birth_date, DateTimeImmutable $today): ?int
    {
        $born = self::parse($birth_date);

        if (! $born instanceof DateTimeImmutable) {
            return null;
        }

        if ($born->format('m-d') !== $today->format('m-d')) {
            return null;
        }

        // Not diff()->y, which is an absolute difference: a child "born" in
        // 2030 would come out as three years old today rather than as the data
        // entry mistake it is.
        if ($born->format('Y-m-d') >= $today->format('Y-m-d')) {
            return null;
        }

        return (int) $born->diff($today)->y;
    }

    /**
     * Reads a stored birth date.
     *
     * Strict about what it accepts. createFromFormat() is happy to roll an
     * impossible date forward -- 31-11-2015 becomes 1 December -- which would
     * greet a family on a day that is not anybody's birthday. Formatting the
     * result back and comparing is the only way to notice.
     */
    private static function parse(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach ([ 'd-m-Y', 'Y-m-d', 'd/m/Y' ] as $format) {
            $date = DateTimeImmutable::createFromFormat($format . '|', $value, wp_timezone());

            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Whether the organiser has switched the birthday mail on.
     */
    private function enabled(): bool
    {
        if (! function_exists('cjw_summer_camp')) {
            return false;
        }

        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);

        return is_array($settings) && ! empty($settings['birthday_mail_enabled']);
    }

    /**
     * Markers already recorded for a submission.
     *
     * @return array<int, string>
     */
    private function alreadyGreeted(int $submission_id): array
    {
        $stored = get_post_meta($submission_id, self::META_GREETED, true);

        return is_array($stored) ? array_values(array_filter(array_map('strval', $stored))) : [];
    }

    /**
     * Registrations whose retention window is still open.
     *
     * The window is the retention setting, measured the way the sweep measures
     * it -- from the end of the camp, so a season's registrations expire
     * together. A row the sweep is about to anonymise is a row whose birth date
     * is no longer ours to use, so it is not greeted in the meantime.
     *
     * With retention switched off there is no window to honour, and greeting
     * indefinitely is the thing this bound exists to prevent. It falls back to
     * the current camp year: bounded, and the narrower of the two answers.
     *
     * @return array<int, WP_Post>
     */
    private function registrationsInRetention(): array
    {
        $cutoff = $this->retentionCutoff();

        if (! $cutoff instanceof DateTimeImmutable) {
            return $this->repository->get_for_camp_year(
                CJW_Wizard_Submission_Repository::currentCampYear(),
                self::BATCH
            );
        }

        $query = [
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            // Not 'any', which drops this post type's custom statuses, and not
            // the trash either: a deleted registration is not greeted.
            'post_status' => array_keys(CJW_Wizard_Submission_Status::all()),
            'numberposts' => self::BATCH,
            'no_found_rows' => true,
            'date_query' => [
                [
                    'after' => $cutoff->format('Y-m-d H:i:s'),
                    'inclusive' => true,
                ],
            ],
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One key, over the registrations still inside the retention window.
            'meta_query' => [
                [
                    'key' => CJW_Wizard_Submission_Repository::META_ANONYMISED,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        return get_posts($query);
    }

    /**
     * The date a registration must postdate for its data to still be kept.
     *
     * @return DateTimeImmutable|null Null when retention is switched off.
     */
    private function retentionCutoff(): ?DateTimeImmutable
    {
        if (! class_exists('CJW_Wizard_Submission_Retention')) {
            return null;
        }

        return new CJW_Wizard_Submission_Retention($this->repository)->cutoff();
    }
}
