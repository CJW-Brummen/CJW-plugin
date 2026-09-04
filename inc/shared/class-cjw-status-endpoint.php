<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One address something outside this site can ask "are you still there?".
 *
 * Everything this plugin reports, it reports by mail from inside itself: the
 * weekly digest, the alerts, the cron heartbeat. All of it depends on the site
 * being alive enough to run code and send mail, which is exactly the case that
 * needs reporting. The digest's own docblock says as much -- a quiet week and a
 * dead site produce the same silence -- and leaves it there.
 *
 * So: a route with no session, no cookies and almost no work, for an uptime
 * monitor to poll every few minutes. An anonymous caller learns whether the
 * site is up and whether it considers itself healthy, and nothing else. With
 * the token from the Beheer tab the same route says what is actually wrong,
 * because "degraded" on its own sends somebody hunting through wp-admin.
 */
final class CJW_Status_Endpoint
{
    public const string REST_NAMESPACE = 'cjw/v1';

    public const string ROUTE = '/status';

    /**
     * Query argument carrying the token.
     */
    public const string TOKEN_ARG = 'token';

    /**
     * Query argument asking for an HTTP status code rather than a word.
     */
    public const string STRICT_ARG = 'strict';

    /**
     * Nothing is wrong.
     */
    public const string LEVEL_OK = 'ok';

    /**
     * Something is wrong that costs nobody anything today.
     */
    public const string LEVEL_WARNING = 'warning';

    /**
     * Something is wrong that is costing registrations, data or trust right
     * now. This is the level worth waking somebody for.
     */
    public const string LEVEL_CRITICAL = 'critical';

    /**
     * Modules add their own checks here, so the endpoint never has to reach
     * into one. See CJW_Summer_Camp_Status_Checks.
     */
    public const string FILTER_CHECKS = 'cjw_status_checks';

    /**
     * Where the computed checks are parked between polls.
     */
    public const string TRANSIENT = 'cjw_status_checks';

    /**
     * How long a monitor may be told something that was true a moment ago.
     *
     * The route is public and a monitor polls it every minute or so; without
     * this each poll redoes every check. Short enough that somebody watching
     * the Beheer tab after a fix does not wait for it -- and that panel asks
     * for fresh checks anyway.
     */
    private const int CACHE_SECONDS = 30;

    /**
     * Worst-first, so the overall verdict is the first level that matches.
     *
     * @var array<int, string>
     */
    private const array LEVELS_BY_SEVERITY = [ self::LEVEL_CRITICAL, self::LEVEL_WARNING, self::LEVEL_OK ];

    /**
     * Registers the route.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('rest_api_init', [ self::class, 'registerRoute' ]);
    }

    /**
     * Declares the route on the REST API.
     *
     * @return void
     */
    public static function registerRoute(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            // Deliberately public: a monitor cannot log in, and the answer
            // without a token says no more than the homepage already does.
            'permission_callback' => '__return_true',
            'callback' => [ self::class, 'handle' ],
            'args' => [
                self::TOKEN_ARG => [
                    'type' => 'string',
                    'required' => false,
                ],
                self::STRICT_ARG => [
                    'type' => 'boolean',
                    'required' => false,
                ],
            ],
        ]);
    }

    /**
     * Answers the request.
     *
     * By default always HTTP 200 while the site can answer at all: reachability
     * is what an uptime monitor measures, and a cron running late is not the
     * site being down. The verdict is a word in the body, so a keyword monitor
     * watching for `"status":"ok"` catches everything else. A site that has
     * actually died answers nothing, which is the case this route exists for.
     *
     * Monitors that can only look at status codes ask for `strict=1` and get a
     * 503 when the verdict is critical.
     *
     * @param mixed $request The REST request.
     *
     * @return WP_REST_Response
     */
    public static function handle(mixed $request): WP_REST_Response
    {
        $token = '';
        $strict = false;

        if (is_object($request) && method_exists($request, 'get_param')) {
            $token = (string) ($request->get_param(self::TOKEN_ARG) ?? '');
            $strict = (bool) $request->get_param(self::STRICT_ARG);
        }

        $payload = self::payload(self::tokenMatches($token));
        $failing = $strict && $payload['status'] === self::LEVEL_CRITICAL;

        return new WP_REST_Response($payload, $failing ? 503 : 200);
    }

    /**
     * What the route answers.
     *
     * @param bool $detailed Whether the caller proved it may see the detail.
     * @param bool $fresh    Skip the cache; for a person looking at a screen.
     *
     * @return array<string, mixed>
     */
    public static function payload(bool $detailed = false, bool $fresh = false): array
    {
        $checks = self::checks($fresh);

        $payload = [
            'status' => self::worstLevel($checks),
            'time' => gmdate('c'),
        ];

        if (! $detailed) {
            return $payload;
        }

        $payload['site'] = (string) get_bloginfo('name');
        $payload['version'] = defined('CJW_VERSION') ? (string) CJW_VERSION : '';
        $payload['checks'] = $checks;

        return $payload;
    }

    /**
     * The overall verdict: the worst thing any check has to say.
     *
     * @param array<string, array<string, mixed>> $checks Checks to weigh.
     *
     * @return string
     */
    public static function worstLevel(array $checks): string
    {
        $levels = array_map(
            static fn(array $check): string => (string) ($check['level'] ?? self::LEVEL_OK),
            $checks
        );

        foreach (self::LEVELS_BY_SEVERITY as $level) {
            if (in_array($level, $levels, true)) {
                return $level;
            }
        }

        return self::LEVEL_OK;
    }

    /**
     * Builds one check in the shape every caller expects.
     *
     * @param string               $level  One of the LEVEL_ constants.
     * @param string               $sayso  One sentence a person can act on, or ''.
     * @param array<string, mixed> $detail Anything else worth serving with a token.
     *
     * @return array<string, mixed>
     */
    public static function check(string $level, string $sayso = '', array $detail = []): array
    {
        return [
            'ok' => $level === self::LEVEL_OK,
            'level' => $level,
            'says' => $sayso,
            ...$detail,
        ];
    }

    /**
     * Everything that can be wrong without anybody hearing about it.
     *
     * Deliberately not the Site Health tests: those are for a person reading a
     * screen, and several of them are opinions about configuration rather than
     * faults. A monitor should report a site that has stopped doing its work,
     * and these are the ways it stops quietly.
     *
     * Three of them are about the plugin's own plumbing and live here. The
     * rest are a module's business and arrive on FILTER_CHECKS, so this class
     * never has to know what a camp or a form is.
     *
     * @param bool $fresh Skip the cache.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function checks(bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = get_transient(self::TRANSIENT);

            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        $checks = [
            'cron' => self::cronCheck(),
            'alerts' => self::alertsCheck(),
            'fatal' => self::fatalCheck(),
        ];

        /**
         * Lets a module report on its own work.
         *
         * @param array<string, array<string, mixed>> $checks Checks so far.
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- FILTER_CHECKS is 'cjw_status_checks'; the constant is what modules hook onto.
        $filtered = apply_filters(self::FILTER_CHECKS, $checks);
        $checks = is_array($filtered) ? $filtered : $checks;

        set_transient(self::TRANSIENT, $checks, self::CACHE_SECONDS);

        return $checks;
    }

    /**
     * Throws the cached verdict away.
     *
     * @return void
     */
    public static function flush(): void
    {
        delete_transient(self::TRANSIENT);
    }

    /**
     * The full address of the route, with the token when there is one.
     *
     * @param bool $withToken Whether to include the token.
     *
     * @return string
     */
    public static function url(bool $withToken = false): string
    {
        $url = rest_url(self::REST_NAMESPACE . self::ROUTE);
        $token = self::token();

        if (! $withToken || $token === '') {
            return $url;
        }

        return add_query_arg(self::TOKEN_ARG, $token, $url);
    }

    /**
     * The token from the camp settings, or an empty string.
     *
     * @return string
     */
    public static function token(): string
    {
        if (! function_exists('cjw_summer_camp')) {
            return '';
        }

        $camp = cjw_summer_camp();

        return method_exists($camp, 'getStatusToken') ? (string) $camp->getStatusToken() : '';
    }

    /**
     * Whether a caller's token is the stored one.
     *
     * A wrong token is answered exactly like no token at all: telling a
     * stranger that their guess was close enough to be worth guessing again is
     * the one thing this route should not do.
     *
     * @param string $candidate Token the caller sent.
     *
     * @return bool
     */
    public static function tokenMatches(string $candidate): bool
    {
        $stored = self::token();

        return $stored !== '' && $candidate !== '' && hash_equals($stored, $candidate);
    }

    /**
     * Renders the panel on the Beheer tab.
     *
     * @return void
     */
    public static function renderPanel(): void
    {
        // Fresh: somebody is looking at this screen, very possibly straight
        // after fixing the thing it complains about.
        $payload = self::payload(true, true);
        $level = (string) $payload['status'];
        $healthy = $level === self::LEVEL_OK;
        $fatal = CJW_Fatal_Error_Watch::last();

        ?>
        <div class="cjw-tool-panel<?php echo $healthy ? '' : ' cjw-tool-panel--warn'; ?>">
            <h2><?php esc_html_e('Statuspagina voor bewaking', 'cjw'); ?></h2>

            <p class="description">
                <?php esc_html_e('De website meldt problemen per mail, en juist een website die helemaal stilstaat kan geen mail meer sturen. Laat daarom een bewakingsdienst van buitenaf dit adres controleren; die merkt het wél als de website niet meer antwoordt.', 'cjw'); ?>
            </p>

            <table class="widefat striped cjw-tool-table">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Nu', 'cjw'); ?></th>
                        <td>
                            <?php if ($healthy) : ?>
                                <?php esc_html_e('In orde.', 'cjw'); ?>
                            <?php else : ?>
                                <strong>
                                    <?php
                                    echo esc_html(
                                        $level === self::LEVEL_CRITICAL
                                            ? __('Er is iets mis dat nu geld of gegevens kost.', 'cjw')
                                            : __('Er is iets mis dat aandacht nodig heeft.', 'cjw')
                                    );
                                    ?>
                                </strong>
                                <ul>
                                    <?php foreach (self::problems(true) as $problem) : ?>
                                        <li><?php echo esc_html($problem); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Adres om te bewaken', 'cjw'); ?></th>
                        <td>
                            <input type="url" readonly class="large-text code" value="<?php echo esc_attr(self::url(true)); ?>" />
                            <p class="description">
                                <?php esc_html_e('Plak dit adres in een gratis bewakingsdienst zoals UptimeRobot. Stel in dat er alarm geslagen wordt zodra “status”:“ok” niet meer in het antwoord staat; dan hoor je het ook als de website nog wel draait maar iets anders is vastgelopen. Kan de dienst alleen naar de foutcode kijken? Zet dan &strict=1 achter het adres: bij een ernstige storing antwoordt de website dan met foutcode 503.', 'cjw'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Laatste crash', 'cjw'); ?></th>
                        <td>
                            <?php if ($fatal === null) : ?>
                                <?php esc_html_e('Geen. De website is niet vastgelopen sinds deze melding voor het laatst is weggehaald.', 'cjw'); ?>
                            <?php else : ?>
                                <strong>
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: %s: date and time. */
                                        __('Op %s.', 'cjw'),
                                        wp_date(__('j F Y \o\m H:i', 'cjw'), $fatal['time'])
                                    ));
                                    ?>
                                </strong>
                                <br><code><?php echo esc_html($fatal['message']); ?></code>
                                <br>
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: 1: file path, 2: line number. */
                                    __('in %1$s op regel %2$d', 'cjw'),
                                    $fatal['file'],
                                    $fatal['line']
                                ));
                                ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if ($fatal !== null) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cjw-tool-form">
                    <?php wp_nonce_field(CJW_Fatal_Error_Watch::ACTION_CLEAR); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(CJW_Fatal_Error_Watch::ACTION_CLEAR); ?>" />
                    <?php submit_button(__('Dit is opgelost, haal de melding weg', 'cjw'), 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * What is wrong, in sentences.
     *
     * @param bool $fresh Skip the cache.
     *
     * @return array<int, string>
     */
    public static function problems(bool $fresh = false): array
    {
        $problems = [];

        foreach (self::checks($fresh) as $check) {
            $says = trim((string) ($check['says'] ?? ''));

            if (($check['level'] ?? self::LEVEL_OK) !== self::LEVEL_OK && $says !== '') {
                $problems[] = $says;
            }
        }

        return $problems;
    }

    /**
     * Whether the scheduled jobs are still running.
     *
     * A warning rather than critical: a job that fires late catches up on its
     * own, and the one that matters most -- the retention sweep -- is reported
     * as critical by Site Health, which is where somebody reads about it.
     *
     * @return array<string, mixed>
     */
    private static function cronCheck(): array
    {
        $stale = CJW_Cron_Heartbeat::stale();

        if ($stale === []) {
            return self::check(self::LEVEL_OK);
        }

        $jobs = array_map(
            static fn(array $details): array => [
                'job' => (string) $details['label'],
                'days' => max(1, (int) floor(((int) $details['silent_for']) / DAY_IN_SECONDS)),
            ],
            $stale
        );

        $says = implode(' ', array_map(
            static fn(array $job): string => sprintf(
                /* translators: 1: job name, 2: number of days. */
                __('%1$s heeft al %2$d dagen niet gedraaid.', 'cjw'),
                $job['job'],
                $job['days']
            ),
            $jobs
        ));

        return self::check(self::LEVEL_WARNING, $says, [ 'stale' => array_values($jobs) ]);
    }

    /**
     * Whether any fault is currently reported and unresolved.
     *
     * These have already been mailed to the camp managers, so the site is not
     * silently broken; it is loudly broken and nobody has fixed it yet.
     *
     * @return array<string, mixed>
     */
    private static function alertsCheck(): array
    {
        $raised = [];

        foreach (CJW_Alerts::subjects() as $key => $subject) {
            // A crashed site and a silent cron each have a check of their own,
            // which says more than the alert's subject line does. Left in here
            // as well, the same fault would be reported twice.
            if (str_starts_with($key, CJW_Fatal_Error_Watch::ALERT_PREFIX)
                || str_starts_with($key, CJW_Cron_Heartbeat::ALERT_PREFIX)) {
                continue;
            }

            $raised[] = $subject;
        }

        if ($raised === []) {
            return self::check(self::LEVEL_OK);
        }

        return self::check(
            self::LEVEL_WARNING,
            implode(' · ', $raised),
            [ 'raised' => $raised ]
        );
    }

    /**
     * Whether the site has crashed since anybody last looked.
     *
     * Critical: a fatal error means visitors were served a broken page, and
     * unlike everything else here it cannot report itself by mail reliably.
     *
     * @return array<string, mixed>
     */
    private static function fatalCheck(): array
    {
        $last = CJW_Fatal_Error_Watch::last();

        if ($last === null) {
            return self::check(self::LEVEL_OK);
        }

        return self::check(
            self::LEVEL_CRITICAL,
            __('De website is vastgelopen.', 'cjw'),
            [
                'at' => gmdate('c', $last['time']),
                'message' => $last['message'],
                'file' => $last['file'],
                'line' => $last['line'],
            ]
        );
    }
}
