<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Adds the week's registrations to the summer-camp weekly mail.
 *
 * The digest is the summer-camp module's, and that module cannot count
 * registrations: the data belongs to the form builder, and so does the decision
 * about what may be said about it in an email. What travels is a number.
 */
final class CJW_Wizard_Submission_Digest
{
    /**
     * The filter the summer-camp module offers.
     */
    public const string FILTER_SECTIONS = 'cjw_summer_camp_digest_sections';

    private CJW_Wizard_Submission_Store $repository;

    public function __construct(CJW_Wizard_Submission_Store $repository)
    {
        $this->repository = $repository;
    }

    public function init(): void
    {
        add_filter(self::FILTER_SECTIONS, [ $this, 'add_section' ]);
    }

    /**
     * Adds a registrations section, when there were any.
     *
     * A week with no registrations adds nothing rather than a line reading
     * "0 inschrijvingen". Out of season that is every week, and it is exactly
     * the content that turns a weekly mail into something nobody opens.
     *
     * @param array<int, array{title: string, lines: array<int, string>}> $sections
     *
     * @return array<int, array{title: string, lines: array<int, string>}>
     */
    public function add_section(array $sections): array
    {
        $since = new DateTimeImmutable('now', wp_timezone())->modify('-7 days');
        $count = $this->repository->count_since($since);

        if ($count < 1) {
            return $sections;
        }

        $lines = [
            sprintf(
                /* translators: %d: number of registrations. */
                _n(
                    'Er is deze week %d inschrijving binnengekomen.',
                    'Er zijn deze week %d inschrijvingen binnengekomen.',
                    $count,
                    'cjw'
                ),
                $count
            ),
            // No names, no addresses, nothing from the payload: this is a
            // mailbox, and the details stay in the one place governed by the
            // retention sweep and an erasure request.
            admin_url('edit.php?post_type=' . CJW_Wizard_Submission_Repository::POST_TYPE),
        ];

        foreach ($this->running_total() as $line) {
            $lines[] = $line;
        }

        array_unshift($sections, [
            'title' => __('Nieuwe inschrijvingen', 'cjw'),
            'lines' => $lines,
        ]);

        return $sections;
    }

    /**
     * Where this camp year stands in total, broken down per camp.
     *
     * The week's number alone answers "is anything happening"; this answers
     * "are we going to fill the camp", which is the question that decides
     * whether anybody does anything about it.
     *
     * @return array<int, string>
     */
    private function running_total(): array
    {
        $year = $this->camp_year();
        $counts = $this->repository->count_by_camp_type($year);

        if ($counts === []) {
            return [];
        }

        $total = (int) ($counts['total'] ?? 0);
        unset($counts['total']);

        $lines = [
            sprintf(
                /* translators: 1: number of registrations, 2: camp year. */
                _n(
                    'In totaal %1$d inschrijving voor kamp %2$s.',
                    'In totaal %1$d inschrijvingen voor kamp %2$s.',
                    $total,
                    'cjw'
                ),
                $total,
                $year
            ),
        ];

        // Only worth breaking down when there is more than one camp to break it
        // into; otherwise it repeats the line above in different words.
        if (count($counts) > 1) {
            $parts = [];

            foreach ($counts as $camp => $count) {
                // Count first. The camp names carry their own age range and
                // price ("Weekkamp (10 t/m 17 jaar) - EUR 101"), so a number
                // appended to one runs straight into it.
                $parts[] = sprintf(
                    /* translators: 1: number of children signed up, 2: camp name. */
                    __('%1$d × %2$s', 'cjw'),
                    $count,
                    $camp
                );
            }

            // Said outright, because these numbers can add up to more than the
            // total above and a reader who spots that without being told
            // concludes the site cannot count. One registration covering two
            // children in different camps is counted under both.
            $lines[] = sprintf(
                /* translators: %s: per-camp counts, already joined. */
                __('Aangemeld per kamp: %s. Eén inschrijving kan twee kinderen voor verschillende kampen bevatten, dus deze aantallen kunnen samen hoger uitkomen dan het totaal.', 'cjw'),
                implode(', ', $parts)
            );
        }

        return $lines;
    }

    /**
     * The camp year the running total is about.
     */
    private function camp_year(): string
    {
        if (function_exists('cjw_summer_camp')) {
            $start = cjw_summer_camp()->getStartDate();

            if ($start instanceof DateTimeImmutable) {
                return $start->format('Y');
            }
        }

        return wp_date('Y');
    }
}
