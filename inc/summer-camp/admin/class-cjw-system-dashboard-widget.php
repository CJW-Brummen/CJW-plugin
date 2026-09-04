<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The technical half of the dashboard, for whoever maintains the site.
 *
 * Deliberately a second widget rather than more rows in the "Zomerkamp" one.
 * That widget is a kampbeheerder's: dates, prices, what still needs filling
 * in. This one is about whether the machine is running -- crashes, scheduled
 * jobs, whether the site is a copy -- and about the numbers somebody wants at
 * a glance rather than by opening three screens. Mixed together, each half
 * would bury the other, and a volunteer would be shown PHP versions they have
 * no use for.
 *
 * Administrators only, and not because the numbers are secret: everything here
 * is either about the installation or is a count a kampbeheerder can already
 * see on their own screens.
 */
final class CJW_System_Dashboard_Widget
{
    /**
     * Dashboard widget identifier.
     */
    private const string WIDGET_ID = 'cjw_system_dashboard_widget';

    /**
     * Registers the dashboard hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('wp_dashboard_setup', [new self(), 'register']);
    }

    /**
     * Adds the widget, for administrators.
     *
     * @return void
     */
    public function register(): void
    {
        if (!current_user_can('manage_options') || !function_exists('cjw_summer_camp')) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('CJW · Systeem', 'cjw'),
            [$this, 'render']
        );
    }

    /**
     * Renders the widget.
     *
     * @return void
     */
    public function render(): void
    {
        $this->renderStatus();
        $this->renderRegistrations();
        $this->renderCron();
        $this->renderEnvironment();
        $this->renderLinks();
    }

    /**
     * The verdict, and what is behind it.
     *
     * @return void
     */
    private function renderStatus(): void
    {
        // Fresh rather than the cached poll answer: somebody is looking, quite
        // possibly right after fixing the thing this would otherwise still
        // report for another half minute.
        $level = (string) CJW_Status_Endpoint::payload(false, true)['status'];
        $problems = CJW_Status_Endpoint::problems();

        [$label, $background, $colour] = match ($level) {
            CJW_Status_Endpoint::LEVEL_CRITICAL => [__('Storing', 'cjw'), '#fcf0f1', '#b32d2e'],
            CJW_Status_Endpoint::LEVEL_WARNING => [__('Let op', 'cjw'), '#fcf9e8', '#8a6116'],
            default => [__('In orde', 'cjw'), '#edfaef', '#007017'],
        };

        printf(
            '<p style="margin:0 0 8px;"><span style="display:inline-block;padding:1px 8px;border-radius:9999px;font-weight:600;background:%s;color:%s;">%s</span></p>',
            esc_attr($background),
            esc_attr($colour),
            esc_html($label)
        );

        if ($problems === []) {
            return;
        }

        echo '<ul style="list-style:disc;margin:0 0 12px 20px;">';

        foreach ($problems as $problem) {
            printf('<li style="color:%s;">%s</li>', esc_attr($colour), esc_html($problem));
        }

        echo '</ul>';
    }

    /**
     * The numbers, for the camp year that is running.
     *
     * @return void
     */
    private function renderRegistrations(): void
    {
        if (!class_exists('CJW_Wizard_Submission_Repository')) {
            return;
        }

        $year = CJW_Wizard_Submission_Repository::currentCampYear();
        $repository = new CJW_Wizard_Submission_Repository();
        $counts = $repository->count_by_camp_type($year);
        $total = (int) ($counts['total'] ?? 0);
        $week = $repository->count_since(new DateTimeImmutable('-7 days', wp_timezone()));
        $consent = CJW_Wizard_Submission_Repository::consentCounts();

        $rows = [
            sprintf(
                /* translators: 1: number of registrations, 2: camp year. */
                _n('%1$d inschrijving voor %2$s', '%1$d inschrijvingen voor %2$s', $total, 'cjw'),
                $total,
                $year
            ),
            sprintf(
                /* translators: %d: number of registrations in the last seven days. */
                _n('%d in de afgelopen 7 dagen', '%d in de afgelopen 7 dagen', $week, 'cjw'),
                $week
            ),
        ];

        foreach ($counts as $camp => $count) {
            if ($camp !== 'total') {
                $rows[] = sprintf('%s: %d', (string) $camp, (int) $count);
            }
        }

        if ($consent['nee'] > 0) {
            $rows[] = sprintf(
                /* translators: %d: number of children without photo consent. */
                _n('%d kind zonder fototoestemming', '%d kinderen zonder fototoestemming', $consent['nee'], 'cjw'),
                $consent['nee']
            );
        }

        $this->section(__('Inschrijvingen', 'cjw'), [implode(' · ', $rows)]);
    }

    /**
     * Every watched job, when it last ran and when it is due again.
     *
     * The endpoint only reports a job once it is late. This is the same
     * information before that point, which is where somebody notices that WP
     * Cron stopped firing at all.
     *
     * @return void
     */
    private function renderCron(): void
    {
        $lines = [];

        foreach (CJW_Cron_Heartbeat::watched() as $hook => $watched) {
            $last = CJW_Cron_Heartbeat::lastRun($hook);
            $next = wp_next_scheduled($hook);

            $lines[] = sprintf(
                '<strong>%s</strong> — %s · %s',
                esc_html((string) $watched['label']),
                esc_html(
                    $last > 0
                        ? sprintf(
                            /* translators: %s: human readable time difference, e.g. "6 hours". */
                            __('%s geleden', 'cjw'),
                            human_time_diff($last)
                        )
                        : __('nog nooit gedraaid', 'cjw')
                ),
                esc_html(
                    is_int($next)
                        ? sprintf(
                            /* translators: %s: human readable time difference. */
                            __('volgende over %s', 'cjw'),
                            human_time_diff(time(), $next)
                        )
                        : __('niet ingepland', 'cjw')
                )
            );
        }

        if ($lines === []) {
            return;
        }

        $this->section(__('Geplande taken', 'cjw'), $lines, false);
    }

    /**
     * Which installation this is, and what it is running.
     *
     * @return void
     */
    private function renderEnvironment(): void
    {
        $lines = [];

        $lines[] = CJW_Mail_Safe_Mode::isActive()
            ? '<strong style="color:#8a6116;">' . esc_html__('Kopie — mail gaat naar de beheerder', 'cjw') . '</strong>'
            : esc_html__('Live website', 'cjw');

        $lines[] = esc_html(sprintf(
            /* translators: 1: plugin version, 2: WordPress version, 3: PHP version. */
            __('CJW %1$s · WordPress %2$s · PHP %3$s', 'cjw'),
            defined('CJW_VERSION') ? (string) CJW_VERSION : '?',
            (string) get_bloginfo('version'),
            PHP_VERSION
        ));

        $fatal = CJW_Fatal_Error_Watch::last();

        if ($fatal !== null) {
            $lines[] = '<span style="color:#b32d2e;">' . esc_html(sprintf(
                /* translators: 1: how long ago, 2: file, 3: line. */
                __('Vastgelopen %1$s geleden in %2$s op regel %3$d', 'cjw'),
                human_time_diff($fatal['time']),
                $fatal['file'],
                $fatal['line']
            )) . '</span>';
        }

        $this->section(__('Omgeving', 'cjw'), $lines, false);
    }

    /**
     * Where to go from here.
     *
     * @return void
     */
    private function renderLinks(): void
    {
        $links = [
            [
                add_query_arg(
                    [
                        'page' => 'cjw-summer-camp',
                        'tab' => 'beheer',
                    ],
                    admin_url('admin.php')
                ),
                __('Beheer', 'cjw'),
            ],
            [admin_url('site-health.php'), __('Websitediagnose', 'cjw')],
            [CJW_Status_Endpoint::url(true), __('Statuspagina', 'cjw')],
        ];

        echo '<p style="border-top:1px solid #dcdcde;padding-top:8px;margin-top:8px;">';

        foreach ($links as $index => $link) {
            if ($index > 0) {
                echo ' | ';
            }

            printf('<a href="%s">%s</a>', esc_url($link[0]), esc_html($link[1]));
        }

        echo '</p>';
    }

    /**
     * One headed block of lines.
     *
     * @param string             $title  Heading.
     * @param array<int, string> $lines  Lines to print.
     * @param bool               $escape Whether the lines still need escaping.
     *
     * @return void
     */
    private function section(string $title, array $lines, bool $escape = true): void
    {
        printf('<h3 style="margin:12px 0 4px;">%s</h3>', esc_html($title));

        foreach ($lines as $line) {
            printf(
                '<p style="margin:0 0 4px;">%s</p>',
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers escape their own markup; see $escape.
                $escape ? esc_html($line) : $line
            );
        }
    }
}
