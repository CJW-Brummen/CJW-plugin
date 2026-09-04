<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Deletes the personal data in registrations once the camp no longer needs it.
 *
 * Registrations hold identifying details about children, and the AVG expects
 * them to be kept for a defined period rather than indefinitely. Nothing here
 * decides that period: the organiser sets it on the camp settings screen, in
 * months after the camp ends, and zero switches the sweep off entirely.
 *
 * Expired rows are anonymised, not deleted, so the camp keeps its own count of
 * how many registrations a year brought without keeping any child's details.
 */
final class CJW_Wizard_Submission_Retention
{
    /**
     * Daily cron hook.
     */
    public const string HOOK = 'cjw_wizard_retention_sweep';

    /**
     * Rows anonymised per run.
     *
     * A first sweep on a site with years of history should not try to rewrite
     * everything inside one cron request; whatever is left is picked up
     * tomorrow.
     */
    private const int BATCH = 100;

    private CJW_Wizard_Submission_Store $repository;

    public function __construct(CJW_Wizard_Submission_Store $repository)
    {
        $this->repository = $repository;
    }

    public function init(): void
    {
        add_action(self::HOOK, [ $this, 'run_scheduled' ]);

        // The only job on this site that ever erases a child's details. If it
        // stops, nothing looks wrong and the data simply stays.
        CJW_Cron_Heartbeat::watch(
            self::HOOK,
            __('Bewaartermijn opschonen', 'cjw'),
            2 * DAY_IN_SECONDS
        );

        // Self-healing: if the schedule was lost -- a botched deactivation, a
        // restored database, a plugin folder swapped out -- put it back rather
        // than silently never running again.
        if (! wp_next_scheduled(self::HOOK)) {
            self::schedule();
        }
    }

    /**
     * Registers the daily sweep.
     */
    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    /**
     * Cancels the daily sweep, for deactivation.
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
     * Separate from sweep() because an action callback must not return a value,
     * while the count is worth having for tests and for running the sweep by
     * hand.
     */
    public function run_scheduled(): void
    {
        $this->sweep();

        CJW_Cron_Heartbeat::record(self::HOOK);
    }

    /**
     * Anonymises everything past its retention date.
     *
     * @return int Rows anonymised this run.
     */
    public function sweep(): int
    {
        $cutoff = $this->cutoff();

        if (! $cutoff instanceof DateTimeImmutable) {
            return 0;
        }

        $anonymised = 0;

        foreach ($this->repository->get_expired($cutoff, self::BATCH) as $submission) {
            if ($this->repository->anonymise((int) $submission->ID)) {
                ++$anonymised;
            }
        }

        return $anonymised;
    }

    /**
     * The date a submission must predate to be expired, or null when retention
     * is switched off or no camp end date is set.
     *
     * Measured from the end of the camp rather than from the submission, because
     * that is the point the data stops being operationally useful: registrations
     * for one season all expire together, whether they arrived in January or the
     * week before.
     */
    public function cutoff(): ?DateTimeImmutable
    {
        if (! function_exists('cjw_summer_camp')) {
            return null;
        }

        $camp = cjw_summer_camp();
        $months = $camp->getRetentionMonths();

        if ($months < 1) {
            return null;
        }

        $end = $camp->getEndDate();

        if (! $end instanceof DateTimeImmutable) {
            return null;
        }

        // Measured from the camp end date, or from today if that date is in the
        // past. Without the second half, a stale end_date would freeze the
        // cut-off: nobody updates the setting for two years, and the sweep
        // quietly stops expiring anything while appearing to run fine. Taking
        // whichever is later keeps retention advancing with the calendar.
        $now = new DateTimeImmutable('now', wp_timezone());
        $from = $end > $now ? $end : $now;

        // Submissions before this date belong to a camp that ended at least
        // `$months` ago, so their retention window has closed.
        return $from->modify("-{$months} months");
    }
}
