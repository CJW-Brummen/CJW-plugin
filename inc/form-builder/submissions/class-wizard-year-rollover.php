<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Scrubs last season's registrations when a new camp year is started.
 *
 * The summer-camp module owns the button; it announces what it did and knows
 * nothing about registrations. This listens, because the personal data belongs
 * to the form builder and so does the decision about what removing it means.
 * Neither module has to know how the other stores anything.
 *
 * Retention already deletes this data on a timer. This is the same rule reached
 * by the other route: the moment an organiser declares the season over is the
 * moment last year's registrations stopped being needed, whatever the retention
 * window happens to say -- and on a camp that skips a year, the timer would
 * otherwise keep children's details for a season longer than anyone intended.
 */
final class CJW_Wizard_Year_Rollover
{
    /**
     * Fired by the summer-camp module after the year has rolled.
     */
    public const string HOOK = 'cjw_summer_camp_year_started';

    /**
     * Continuation hook, for a season too large to finish in one request.
     */
    public const string CONTINUE_HOOK = 'cjw_wizard_year_rollover_continue';

    /**
     * Filter the summer-camp module offers so other modules can say, before
     * anything happens, what starting a new year will do to their data.
     */
    public const string EFFECTS_FILTER = 'cjw_summer_camp_year_start_effects';

    /**
     * Rows per pass.
     *
     * Matches the retention sweep. Whatever is left over is picked up by a
     * follow-up event rather than held open in the request an organiser is
     * waiting on.
     */
    private const int BATCH = 100;

    private CJW_Wizard_Submission_Store $repository;

    public function __construct(CJW_Wizard_Submission_Store $repository)
    {
        $this->repository = $repository;
    }

    public function init(): void
    {
        add_action(self::HOOK, [ $this, 'on_year_started' ], 10, 2);
        add_action(self::CONTINUE_HOOK, [ $this, 'run_scheduled' ], 10, 1);
        add_filter(self::EFFECTS_FILTER, [ $this, 'describe_effect' ], 10, 2);
    }

    /**
     * Tells the organiser, before they commit, how many registrations this will
     * scrub.
     *
     * @param array<int, string> $effects   Lines gathered so far.
     * @param string             $outgoing  The year being closed.
     *
     * @return array<int, string>
     */
    public function describe_effect(array $effects, string $outgoing): array
    {
        $count = $this->repository->count_for_camp_year_upto($outgoing);

        if ($count < 1) {
            return $effects;
        }

        $effects[] = sprintf(
            /* translators: 1: number of registrations, 2: camp year. */
            _n(
                '%1$d inschrijving van %2$s en eerder wordt geanonimiseerd: namen, adressen, e-mailadressen en opmerkingen worden gewist. Het aantal inschrijvingen per kamp blijft bewaard.',
                '%1$d inschrijvingen van %2$s en eerder worden geanonimiseerd: namen, adressen, e-mailadressen en opmerkingen worden gewist. Het aantal inschrijvingen per kamp blijft bewaard.',
                $count,
                'cjw'
            ),
            $count,
            $outgoing
        );

        return $effects;
    }

    /**
     * Handles the announcement.
     *
     * @param string $outgoing The year being closed.
     * @param string $incoming The year now being prepared.
     */
    public function on_year_started(string $outgoing, string $incoming): void
    {
        unset($incoming);

        $this->anonymise_year($outgoing);
    }

    /**
     * Cron entry point.
     *
     * Separate from anonymise_year() because an action callback must not return
     * a value, while the count is worth having for tests and for running the
     * pass by hand.
     *
     * @param string $year Camp year to scrub.
     */
    public function run_scheduled(string $year): void
    {
        $this->anonymise_year($year);
    }

    /**
     * Anonymises one batch, and queues the next if there is one.
     *
     * @param string $year Camp year to scrub, together with everything before it.
     *
     * @return int Rows anonymised this pass.
     */
    public function anonymise_year(string $year): int
    {
        if (! preg_match('/^\d{4}$/', $year)) {
            return 0;
        }

        $anonymised = 0;

        foreach ($this->repository->get_for_camp_year_upto($year, self::BATCH) as $submission) {
            if ($this->repository->anonymise((int) $submission->ID)) {
                ++$anonymised;
            }
        }

        // Only queue a follow-up when this pass actually achieved something.
        // A batch that anonymised nothing while rows remain means anonymise()
        // is refusing them, and rescheduling would spin on that for ever.
        if ($anonymised > 0 && $this->repository->count_for_camp_year_upto($year) > 0) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK, [ $year ]);
        }

        return $anonymised;
    }
}
