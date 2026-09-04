<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Notices when a scheduled job has stopped running.
 *
 * WP-Cron only fires when somebody visits the site. On a camp site in
 * November that can be nobody for days, and a host that sets DISABLE_WP_CRON
 * without wiring a system cron stops it for good. Every job here fails
 * silently in that case, and one of them -- the retention sweep -- is the only
 * thing that ever erases a child's name, address and medical notes. Nothing
 * looks wrong while the site quietly keeps that data years past the period the
 * organisation told parents about, which is a plain AVG breach that no screen
 * in wp-admin would have shown.
 *
 * So each job records that it ran, and going quiet becomes something the site
 * can see: a failing Site Health check, which the weekly digest already
 * repeats, plus a mail the first time it happens. Deliberately not a section of
 * its own in that digest -- the mail's silence is worth protecting, and a check
 * that only speaks when it is failing spends none of it.
 *
 * Modules register their own hooks rather than this class knowing them, so
 * nothing in shared/ has to reach into a module to find out what it schedules.
 */
final class CJW_Cron_Heartbeat
{
    /**
     * Where the last successful run of each hook is recorded.
     */
    public const string OPTION_KEY = 'cjw_cron_heartbeat';

    /**
     * Alert key prefix, one per watched hook.
     */
    /**
     * Alert keys start with this, one per watched hook.
     *
     * Public because the status endpoint leaves these alerts out of its own
     * list: it reports stale jobs itself, with how long they have been quiet.
     */
    public const string ALERT_PREFIX = 'cron_silent:';

    /**
     * Watched hooks: hook => ['label' => string, 'max_age' => int].
     *
     * @var array<string, array{label: string, max_age: int}>
     */
    private static array $watched = [];

    /**
     * Registers a scheduled job to keep an eye on.
     *
     * @param string $hook   The cron hook.
     * @param string $label  What to call it in Dutch, for people not reading code.
     * @param int    $maxAge How long it may stay quiet before that is a fault.
     *                       Give a daily job more than a day: a cron that fires
     *                       a few hours late is normal and must not cry wolf.
     */
    public static function watch(string $hook, string $label, int $maxAge): void
    {
        self::$watched[ $hook ] = [
            'label' => $label,
            'max_age' => max(HOUR_IN_SECONDS, $maxAge),
        ];
    }

    /**
     * Every watched hook.
     *
     * @return array<string, array{label: string, max_age: int}>
     */
    public static function watched(): array
    {
        return self::$watched;
    }

    /**
     * Forgets every registration. For tests.
     */
    public static function reset(): void
    {
        self::$watched = [];
    }

    /**
     * Records that a job has just run, and lifts its alert.
     *
     * Called from inside the job, after the work rather than before it: the
     * question this answers is "did it complete", not "was it started".
     */
    public static function record(string $hook): void
    {
        $beats = self::all();
        $beats[ $hook ] = time();
        update_option(self::OPTION_KEY, $beats);

        CJW_Alerts::clear(self::ALERT_PREFIX . $hook);
    }

    /**
     * When a hook last completed, or zero when it never has here.
     */
    public static function lastRun(string $hook): int
    {
        return (int) (self::all()[ $hook ] ?? 0);
    }

    /**
     * The watched hooks that have gone quiet for too long.
     *
     * A hook that has never run is only stale once it has had its window to
     * run in, measured from when this class first saw it. Otherwise every
     * fresh install would report a fault before its first sweep was due.
     *
     * @return array<string, array{label: string, max_age: int, last: int, silent_for: int}>
     */
    public static function stale(): array
    {
        $now = time();
        $stale = [];

        foreach (self::$watched as $hook => $watched) {
            $last = self::lastRun($hook);

            if ($last === 0) {
                $last = self::firstSeen($hook);
            }

            $silentFor = $now - $last;

            if ($silentFor <= $watched['max_age']) {
                continue;
            }

            $stale[ $hook ] = [
                'label' => $watched['label'],
                'max_age' => $watched['max_age'],
                'last' => self::lastRun($hook),
                'silent_for' => $silentFor,
            ];
        }

        return $stale;
    }

    /**
     * Hook entry point.
     *
     * Separate from reportStale() for the same reason the retention sweep
     * splits run_scheduled() from sweep(): an action callback must not return
     * a value, while the count is worth having for tests.
     */
    public static function reportStaleScheduled(): void
    {
        self::reportStale();
    }

    /**
     * Reports any newly silent job, once.
     *
     * @return int How many alerts this call raised.
     */
    public static function reportStale(): int
    {
        $raised = 0;

        foreach (self::stale() as $hook => $details) {
            $days = max(1, (int) floor($details['silent_for'] / DAY_IN_SECONDS));

            $body = '<p>' . sprintf(
                /* translators: 1: job name, 2: number of days. */
                esc_html__(
                    'De geplande taak "%1$s" heeft al %2$d dagen niet gedraaid. Zolang dat zo blijft, gebeurt het werk van die taak niet.',
                    'cjw'
                ),
                esc_html($details['label']),
                $days
            ) . '</p>';

            $body .= '<p>' . esc_html__(
                'Dit komt bijna altijd doordat WP-Cron niet meer afgaat: die draait alleen als iemand de site bezoekt, en sommige hostingpakketten zetten hem uit. Vraag de host om een echte cronjob in te stellen.',
                'cjw'
            ) . '</p>';

            if (CJW_Alerts::raise(self::ALERT_PREFIX . $hook, self::subject($details['label']), $body)) {
                ++$raised;
            }
        }

        return $raised;
    }

    /**
     * The subject line of a silence alert.
     */
    private static function subject(string $label): string
    {
        return sprintf(
            /* translators: %s: job name. */
            __('Geplande taak staat stil: %s', 'cjw'),
            $label
        );
    }

    /**
     * When this hook was first watched, so a job that has never run is judged
     * from the moment the site started expecting it rather than from 1970.
     */
    private static function firstSeen(string $hook): int
    {
        $beats = self::all();
        $key = 'first_seen:' . $hook;

        if (isset($beats[ $key ])) {
            return (int) $beats[ $key ];
        }

        $beats[ $key ] = time();
        update_option(self::OPTION_KEY, $beats);

        return (int) $beats[ $key ];
    }

    /**
     * Everything recorded, as hook => timestamp.
     *
     * @return array<string, int>
     */
    private static function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (! is_array($stored)) {
            return [];
        }

        $clean = [];

        foreach ($stored as $hook => $timestamp) {
            if (is_string($hook) && $hook !== '') {
                $clean[ $hook ] = (int) $timestamp;
            }
        }

        return $clean;
    }
}
