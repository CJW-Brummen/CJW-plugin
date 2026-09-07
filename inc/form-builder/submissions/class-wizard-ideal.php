<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Paying for a registration with iDEAL.
 *
 * The order of events is the whole design. The registration is stored first
 * and the parent is sent to Mollie second, never the other way round: a bank
 * app that crashes, a phone that dies, a browser closed on the confirmation
 * screen must never cost the camp a registration, and money must never be
 * taken for details nobody wrote down.
 *
 * Nothing a browser says about a payment is believed. The parent comes back to
 * a page with a token in it and Mollie posts a webhook carrying an id, and in
 * both cases the answer to "was this paid" comes from asking Mollie over an
 * authenticated connection. That is why the webhook needs no signature: it
 * carries no claim to forge.
 */
final class CJW_Wizard_Ideal
{
    /**
     * The parent's key to their own registration, in the pay link.
     *
     * Long and random: it is a bearer capability. What it grants is the right
     * to pay, and to see the completion screen for that one registration.
     */
    public const string META_TOKEN = '_cjw_wizard_pay_token';

    /**
     * The payments started at Mollie for this registration.
     */
    public const string META_ATTEMPTS = '_cjw_wizard_mollie_attempts';

    /**
     * Query argument on the link that starts a payment.
     */
    public const string ARG_PAY = 'cjw_betalen';

    /**
     * Query argument on the address Mollie sends the parent back to.
     */
    public const string ARG_RETURN = 'cjw_betaald';

    public const string REST_ROUTE = '/mollie';

    /**
     * Fires when a registration has gone unpaid long enough that the parent
     * should hear from us anyway.
     */
    public const string HOOK_CONFIRMATION = 'cjw_wizard_ideal_confirmation';

    /**
     * How long a parent is left alone before the confirmation goes out
     * without a payment attached to it.
     */
    private const int CONFIRMATION_DELAY = 1800;

    /**
     * How long a started payment is offered again rather than replaced.
     *
     * A parent who clicks twice, or comes back within the quarter of an hour
     * an iDEAL payment stays open, should land on the payment they already
     * have rather than leave a trail of abandoned ones behind them.
     */
    private const int REUSE_WINDOW = 900;

    /**
     * Payments a single token may start in an hour.
     */
    private const int RATE_LIMIT = 12;

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('rest_api_init', [ self::class, 'registerRoute' ]);
        add_action('template_redirect', [ self::class, 'handlePayLink' ], 5);
        add_action(self::HOOK_CONFIRMATION, [ self::class, 'sendDeferredConfirmation' ]);
    }

    /**
     * The webhook Mollie posts to.
     *
     * Public by necessity: Mollie cannot log in. It is safe because the body
     * carries only an id, and the id is worth nothing without our API key.
     *
     * @return void
     */
    public static function registerRoute(): void
    {
        register_rest_route(CJW_Status_Endpoint::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [ self::class, 'handleWebhook' ],
            'args' => [
                'id' => [
                    'type' => 'string',
                    'required' => true,
                ],
            ],
        ]);
    }

    /**
     * Whether this form makes the parent pay before it is finished.
     *
     * @param array $config Form configuration, settings included.
     * @param array<string, mixed> $config
     *
     * @return bool
     */
    public static function isRequiredFor(array $config): bool
    {
        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];

        return ! empty($settings['require_ideal_payment']);
    }

    /**
     * Whether this registration still has to be paid before it is done.
     *
     * @param int $submissionId Registration post id.
     *
     * @return bool
     */
    public static function isOutstanding(int $submissionId): bool
    {
        return CJW_Wizard_Payments::outstanding($submissionId) > 0;
    }

    /**
     * The parent's own link to pay this registration.
     *
     * @param int $submissionId Registration post id.
     *
     * @return string
     */
    public static function payUrl(int $submissionId): string
    {
        $token = self::token($submissionId);

        return $token === '' ? '' : add_query_arg(self::ARG_PAY, $token, home_url('/'));
    }

    /**
     * The token for a registration, made on first use.
     *
     * @param int $submissionId Registration post id.
     *
     * @return string
     */
    public static function token(int $submissionId): string
    {
        if ($submissionId < 1) {
            return '';
        }

        $stored = (string) get_post_meta($submissionId, self::META_TOKEN, true);

        if (preg_match('/^[a-f0-9]{32}$/', $stored) === 1) {
            return $stored;
        }

        try {
            $token = bin2hex(random_bytes(16));
        } catch (Exception) {
            return '';
        }

        update_post_meta($submissionId, self::META_TOKEN, $token);

        return $token;
    }

    /**
     * The registration a token belongs to, or 0.
     *
     * @param string $token Token from a link.
     *
     * @return int
     */
    public static function submissionForToken(string $token): int
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return 0;
        }

        $found = get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            // Not 'any'. WP_Query expands that to every status which is not
            // exclude_from_search, and every status this post type uses is --
            // so 'any' finds none of them and every pay link would read as
            // expired. Cancelled is included on purpose: a parent following an
            // old link deserves to be told, not to be told nothing.
            'post_status' => array_keys(CJW_Wizard_Submission_Status::all()),
            'numberposts' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- An exact lookup on a random token; there is no other way in.
            'meta_query' => [
                [
                    'key' => self::META_TOKEN,
                    'value' => $token,
                ],
            ],
        ]);

        return $found === [] ? 0 : (int) $found[0];
    }

    /**
     * Starts, or re-offers, a payment for a registration.
     *
     * @param int $submissionId Registration post id.
     *
     * @return array{ok: bool, url?: string, error?: string}
     */
    public static function start(int $submissionId): array
    {
        if (! CJW_Mollie::isUsable()) {
            return [
                'ok' => false,
                'error' => CJW_Mollie::unusableReason(),
            ];
        }

        $post = get_post($submissionId);

        if (! $post instanceof WP_Post || $post->post_type !== CJW_Wizard_Submission_Repository::POST_TYPE) {
            return [
                'ok' => false,
                'error' => __('Deze inschrijving bestaat niet.', 'cjw'),
            ];
        }

        if ($post->post_status === CJW_Wizard_Submission_Status::CANCELLED || $post->post_status === 'trash') {
            return [
                'ok' => false,
                'error' => __('Deze inschrijving is geannuleerd.', 'cjw'),
            ];
        }

        $cents = CJW_Wizard_Payments::outstanding($submissionId);

        if ($cents < 1) {
            return [
                'ok' => false,
                'error' => __('Er staat niets meer open.', 'cjw'),
            ];
        }

        $reusable = self::reusableAttempt($submissionId, $cents);

        if ($reusable !== '') {
            return [
                'ok' => true,
                'url' => $reusable,
            ];
        }

        $answer = CJW_Mollie::createPayment(
            $cents,
            self::description($submissionId),
            self::returnUrl($submissionId),
            self::webhookUrl(),
            [
                // Read back on the webhook and checked against what we stored.
                'submission' => $submissionId,
                'site' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            ],
            self::idempotencyKey($submissionId, $cents)
        );

        if (! ($answer['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($answer['error'] ?? __('De betaling kon niet worden gestart.', 'cjw')),
            ];
        }

        $payment = $answer['payment'] ?? [];
        $paymentId = (string) ($payment['id'] ?? '');
        $checkout = (string) ($payment['_links']['checkout']['href'] ?? '');

        if (! CJW_Mollie::looksLikePaymentId($paymentId) || ! CJW_Mollie::isCheckoutUrl($checkout)) {
            return [
                'ok' => false,
                'error' => __('Mollie gaf geen geldige betaallink terug.', 'cjw'),
            ];
        }

        self::rememberAttempt($submissionId, $paymentId, $cents, (string) ($payment['mode'] ?? ''), $checkout);

        return [
            'ok' => true,
            'url' => $checkout,
        ];
    }

    /**
     * Sends a parent who followed a pay link on to iDEAL.
     *
     * @return void
     */
    public static function handlePayLink(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A link out of a mail carries no nonce; the random token is what authenticates it, and starting a payment changes nothing that a repeat would spoil.
        if (! isset($_GET[ self::ARG_PAY ])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $token = sanitize_text_field(wp_unslash((string) $_GET[ self::ARG_PAY ]));
        $submissionId = self::submissionForToken($token);

        if ($submissionId < 1) {
            wp_die(
                esc_html__('Deze betaallink klopt niet of is verlopen. Neem contact op met de organisatie.', 'cjw'),
                esc_html__('Betaallink onbekend', 'cjw'),
                [ 'response' => 404 ]
            );
        }

        if (! self::withinRateLimit($token)) {
            wp_die(
                esc_html__('Er zijn net te veel betaalpogingen gedaan. Probeer het over een paar minuten opnieuw.', 'cjw'),
                esc_html__('Even wachten', 'cjw'),
                [ 'response' => 429 ]
            );
        }

        if (! self::isOutstanding($submissionId)) {
            wp_safe_redirect(self::returnUrl($submissionId));
            exit;
        }

        $started = self::start($submissionId);

        if (! ($started['ok'] ?? false)) {
            wp_die(
                esc_html((string) ($started['error'] ?? '')),
                esc_html__('Betalen lukt nu niet', 'cjw'),
                [ 'response' => 503 ]
            );
        }

        // Deliberately wp_redirect and not wp_safe_redirect: the destination
        // is Mollie's checkout, which is by definition not on this site, so
        // the allowed-hosts list would refuse it. The URL is not trusted for
        // being ours -- start() only ever returns one that CJW_Mollie has
        // confirmed is https and on mollie.com, so this cannot become an open
        // redirect even if a stored attempt were somehow tampered with.
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- See above; exit follows immediately.
        wp_redirect((string) $started['url']);
        exit;
    }

    /**
     * Answers Mollie's webhook.
     *
     * @param mixed $request The REST request.
     *
     * @return WP_REST_Response
     */
    public static function handleWebhook(mixed $request): WP_REST_Response
    {
        $paymentId = '';

        if (is_object($request) && method_exists($request, 'get_param')) {
            $paymentId = (string) ($request->get_param('id') ?? '');
        }

        $result = self::process($paymentId);

        self::recordWebhookSeen();

        // 200 for everything we have decided about, including "not ours".
        // Anything else and Mollie retries for a day, which is right for a
        // failure at this end and wrong for a payment that was never ours.
        return new WP_REST_Response(
            [ 'handled' => $result['handled'] ],
            $result['retry'] ? 503 : 200
        );
    }

    /**
     * Decides what a payment id means, by asking Mollie.
     *
     * @param string $paymentId Mollie payment id.
     *
     * @return array{handled: bool, retry: bool, reason: string}
     */
    public static function process(string $paymentId): array
    {
        if (! CJW_Mollie::looksLikePaymentId($paymentId)) {
            return self::outcome(false, false, 'malformed');
        }

        // A copy of the site must not write the real books, and its webhook
        // should not be reachable in the first place.
        if (CJW_Mail_Safe_Mode::isActive()) {
            return self::outcome(false, false, 'copy');
        }

        $answer = CJW_Mollie::getPayment($paymentId);

        if (! ($answer['ok'] ?? false)) {
            $status = (int) ($answer['status'] ?? 0);

            // Mollie does not know it: nothing to wait for. Anything else --
            // a timeout, a 500, our key briefly rejected -- is worth a retry.
            $unknown = $status === 404 || $status === 410;

            return self::outcome(false, ! $unknown, $unknown ? 'unknown' : 'unreachable');
        }

        $payment = $answer['payment'] ?? [];
        $submissionId = (int) ($payment['metadata']['submission'] ?? 0);

        if ($submissionId < 1 || ! self::isKnownAttempt($submissionId, $paymentId)) {
            // Either not ours at all, or an id that never came from here.
            return self::outcome(false, false, 'foreign');
        }

        if ((string) ($payment['mode'] ?? '') !== CJW_Mollie::mode()) {
            // A test payment must never touch live books, or the other way up.
            return self::outcome(false, false, 'wrong-mode');
        }

        if ((string) ($payment['amount']['currency'] ?? '') !== 'EUR') {
            return self::outcome(false, false, 'wrong-currency');
        }

        $status = (string) ($payment['status'] ?? '');

        self::rememberStatus($submissionId, $paymentId, $status);

        if ($status !== 'paid') {
            return self::outcome(false, false, $status === '' ? 'no-status' : $status);
        }

        return self::outcome(self::recordPaid($submissionId, $paymentId, $payment), false, 'paid');
    }

    /**
     * Writes a completed payment into the ledger, once.
     *
     * @param int    $submissionId Registration post id.
     * @param string $paymentId    Mollie payment id.
     * @param array  $payment      What Mollie answered.
     * @param array<string, mixed> $payment
     *
     * @return bool Whether this call recorded it.
     */
    private static function recordPaid(int $submissionId, string $paymentId, array $payment): bool
    {
        if (CJW_Wizard_Payments::hasReference($submissionId, $paymentId)) {
            return false;
        }

        // The amount Mollie says was paid, not the amount we asked for: those
        // differ when somebody pays an older link after a correction, and the
        // books follow the bank.
        $cents = self::centsFrom((string) ($payment['amount']['value'] ?? ''));

        if ($cents < 1) {
            return false;
        }

        $paidAt = substr((string) ($payment['paidAt'] ?? ''), 0, 10);

        CJW_Wizard_Payments::add(
            $submissionId,
            $cents,
            CJW_Wizard_Payments::METHOD_IDEAL,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt) === 1 ? $paidAt : '',
            '',
            $paymentId
        );

        CJW_Wizard_Payments::applyStatus($submissionId);
        self::sendConfirmation($submissionId);

        return true;
    }

    /**
     * Sends the confirmation that was held back until the payment resolved.
     *
     * @param int $submissionId Registration post id.
     *
     * @return void
     */
    public static function sendConfirmation(int $submissionId): void
    {
        if (CJW_Wizard_Submission_Confirmation::result($submissionId) !== '') {
            return;
        }

        $repository = new CJW_Wizard_Submission_Repository();
        $config = $repository->get_config($submissionId);
        $payload = $repository->get_payload($submissionId);

        if ($config === []) {
            return;
        }

        new CJW_Wizard_Submission_Confirmation()->maybe_send(
            $submissionId,
            $repository->get_wizard_id($submissionId),
            $config,
            $payload
        );
    }

    /**
     * The safety net: a parent who never paid still hears from us.
     *
     * @param int $submissionId Registration post id.
     *
     * @return void
     */
    public static function sendDeferredConfirmation(int $submissionId): void
    {
        self::sendConfirmation((int) $submissionId);
    }

    /**
     * Holds the confirmation back until the payment is settled.
     *
     * @param int $submissionId Registration post id.
     *
     * @return void
     */
    public static function deferConfirmation(int $submissionId): void
    {
        if (wp_next_scheduled(self::HOOK_CONFIRMATION, [ $submissionId ]) !== false) {
            return;
        }

        wp_schedule_single_event(time() + self::CONFIRMATION_DELAY, self::HOOK_CONFIRMATION, [ $submissionId ]);
    }

    /**
     * Where Mollie sends the parent back to.
     *
     * @param int $submissionId Registration post id.
     *
     * @return string
     */
    public static function returnUrl(int $submissionId): string
    {
        $token = self::token($submissionId);
        $base = function_exists('cjw_summer_camp') ? cjw_summer_camp()->getRegistrationPageUrl() : '';

        if ($base === '') {
            $base = home_url('/');
        }

        return $token === '' ? $base : add_query_arg(self::ARG_RETURN, $token, $base);
    }

    /**
     * Where Mollie reports the outcome.
     *
     * @return string
     */
    public static function webhookUrl(): string
    {
        return rest_url(CJW_Status_Endpoint::REST_NAMESPACE . self::REST_ROUTE);
    }

    /**
     * Whether any published form makes the parent pay.
     *
     * @return bool
     */
    public static function anyPublishedFormRequires(): bool
    {
        if (! class_exists('CJW_Wizard_Post_Type') || ! class_exists('CJW_Wizard_Config')) {
            return false;
        }

        $forms = get_posts([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_status' => 'publish',
            'numberposts' => 50,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($forms as $formId) {
            if (self::isRequiredFor(CJW_Wizard_Config::get((int) $formId))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The pay button for the confirmation mail, or nothing.
     *
     * Empty the moment there is nothing left to pay, which is what keeps a
     * parent who has just paid from being asked again by a mail that went out
     * seconds later.
     *
     * @param int $submissionId Registration post id.
     *
     * @return string Trusted HTML.
     */
    public static function mailButton(int $submissionId): string
    {
        if (! CJW_Mollie::isUsable() || ! self::isOutstanding($submissionId)) {
            return '';
        }

        $url = self::payUrl($submissionId);

        if ($url === '') {
            return '';
        }

        $outstanding = CJW_Wizard_Payments::outstanding($submissionId);

        return sprintf(
            '<p style="margin:24px 0 8px;"><a href="%1$s" style="display:inline-block;padding:12px 20px;'
            . 'border-radius:6px;background:#cc0066;color:#ffffff;font-weight:700;text-decoration:none;">%2$s</a></p>'
            . '<p style="margin:0;font-size:14px;color:#5a625d;">%3$s</p>',
            esc_url($url),
            esc_html(sprintf(
                /* translators: %s: amount still to pay. */
                __('Betaal %s met iDEAL', 'cjw'),
                CJW_Wizard_Payments::formatAmount($outstanding)
            )),
            esc_html__('Deze link blijft werken; je kunt ook later betalen.', 'cjw')
        );
    }

    /**
     * The registration a parent has just come back to, or 0.
     *
     * @return int
     */
    public static function returningSubmission(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Mollie sends the parent back to this address; the random token is what identifies the registration, and nothing is changed here.
        if (! isset($_GET[ self::ARG_RETURN ])) {
            return 0;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        return self::submissionForToken(sanitize_text_field(wp_unslash((string) $_GET[ self::ARG_RETURN ])));
    }

    /**
     * The completion screen for a registration, from the registration itself.
     *
     * Used when a parent arrives back from iDEAL, where there is no session to
     * read: the token in the address is the only thing tying the browser to a
     * registration, and everything shown is looked up here rather than taken
     * from the request.
     *
     * @param int $submissionId Registration post id.
     *
     * @return array<string, mixed>
     */
    public static function completionState(int $submissionId): array
    {
        $repository = new CJW_Wizard_Submission_Repository();
        $outstanding = CJW_Wizard_Payments::outstanding($submissionId);

        return [
            'step' => PHP_INT_MAX,
            'data' => [],
            'completion' => [
                'submission' => $submissionId,
                'email' => $repository->get_email($submissionId),
                'mail' => CJW_Wizard_Submission_Confirmation::result($submissionId),
                'outstanding' => $outstanding,
                'pay_url' => $outstanding > 0 ? self::payUrl($submissionId) : '',
            ],
        ];
    }

    /**
     * Every payment started for a registration.
     *
     * @param int $submissionId Registration post id.
     *
     * @return array<string, array{cents: int, mode: string, at: int, status: string, url: string}>
     */
    public static function attempts(int $submissionId): array
    {
        $stored = get_post_meta($submissionId, self::META_ATTEMPTS, true);
        $clean = [];

        foreach (is_array($stored) ? $stored : [] as $id => $row) {
            if (! is_string($id) || ! is_array($row)) {
                continue;
            }

            $clean[ $id ] = [
                'cents' => (int) ($row['cents'] ?? 0),
                'mode' => (string) ($row['mode'] ?? ''),
                'at' => (int) ($row['at'] ?? 0),
                'status' => (string) ($row['status'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
            ];
        }

        return $clean;
    }

    /**
     * Whether this payment was started from here, for this registration.
     *
     * @param int    $submissionId Registration post id.
     * @param string $paymentId    Mollie payment id.
     *
     * @return bool
     */
    private static function isKnownAttempt(int $submissionId, string $paymentId): bool
    {
        return array_key_exists($paymentId, self::attempts($submissionId));
    }

    /**
     * A payment already started that can be offered again.
     *
     * @param int $submissionId Registration post id.
     * @param int $cents        What is owed now.
     *
     * @return string Checkout URL, or ''.
     */
    private static function reusableAttempt(int $submissionId, int $cents): string
    {
        foreach (self::attempts($submissionId) as $row) {
            if (
                CJW_Mollie::isCheckoutUrl($row['url'])
                && $row['cents'] === $cents
                && $row['status'] === ''
                && $row['at'] > time() - self::REUSE_WINDOW
            ) {
                return $row['url'];
            }
        }

        return '';
    }

    /**
     * Remembers a payment we started, so its webhook can be recognised later.
     *
     * @param int    $submissionId Registration post id.
     * @param string $paymentId    Mollie payment id.
     * @param int    $cents        Amount asked for.
     * @param string $mode         live or test.
     * @param string $url          Checkout URL.
     *
     * @return void
     */
    private static function rememberAttempt(int $submissionId, string $paymentId, int $cents, string $mode, string $url): void
    {
        $attempts = self::attempts($submissionId);

        $attempts[ $paymentId ] = [
            'cents' => $cents,
            'mode' => $mode,
            'at' => time(),
            'status' => '',
            'url' => $url,
        ];

        update_post_meta($submissionId, self::META_ATTEMPTS, $attempts);
    }

    /**
     * Notes what Mollie last said about a payment we started.
     *
     * @param int    $submissionId Registration post id.
     * @param string $paymentId    Mollie payment id.
     * @param string $status       Status Mollie reported.
     *
     * @return void
     */
    private static function rememberStatus(int $submissionId, string $paymentId, string $status): void
    {
        $attempts = self::attempts($submissionId);

        if (! isset($attempts[ $paymentId ])) {
            return;
        }

        $attempts[ $paymentId ]['status'] = $status;

        // A payment that will never complete stops being one to re-offer.
        if (in_array($status, [ 'canceled', 'expired', 'failed' ], true)) {
            $attempts[ $paymentId ]['url'] = '';
        }

        update_post_meta($submissionId, self::META_ATTEMPTS, $attempts);
    }

    /**
     * Remembers that Mollie reached us, for the Beheer tab.
     *
     * @return void
     */
    private static function recordWebhookSeen(): void
    {
        update_option('cjw_mollie_last_webhook', time(), false);
    }

    /**
     * When Mollie last called, or 0.
     *
     * @return int
     */
    public static function lastWebhook(): int
    {
        return (int) get_option('cjw_mollie_last_webhook', 0);
    }

    /**
     * Keeps one link from starting an unbounded number of payments.
     *
     * @param string $token Pay token.
     *
     * @return bool
     */
    private static function withinRateLimit(string $token): bool
    {
        $key = 'cjw_pay_' . md5($token);
        $seen = (int) get_transient($key);

        if ($seen >= self::RATE_LIMIT) {
            return false;
        }

        set_transient($key, $seen + 1, HOUR_IN_SECONDS);

        return true;
    }

    /**
     * What the parent sees on their bank statement.
     *
     * Deliberately no child's name: a bank statement is read by whoever holds
     * the account, and printed by whoever holds the paper.
     *
     * @param int $submissionId Registration post id.
     *
     * @return string
     */
    private static function description(int $submissionId): string
    {
        $name = function_exists('cjw_summer_camp') ? get_bloginfo('name') : '';

        return trim(sprintf(
            /* translators: 1: site name, 2: registration number. */
            __('%1$s inschrijving %2$d', 'cjw'),
            $name !== '' ? $name : __('Zomerkamp', 'cjw'),
            $submissionId
        ));
    }

    /**
     * A key that makes a retried create safe.
     *
     * @param int $submissionId Registration post id.
     * @param int $cents        Amount.
     *
     * @return string
     */
    private static function idempotencyKey(int $submissionId, int $cents): string
    {
        return sprintf('cjw-%d-%d-%d', $submissionId, $cents, (int) floor(time() / self::REUSE_WINDOW));
    }

    /**
     * Mollie's decimal string as cents.
     *
     * @param string $value For example "72.50".
     *
     * @return int
     */
    private static function centsFrom(string $value): int
    {
        if (preg_match('/^\d+\.\d{2}$/', $value) !== 1) {
            return 0;
        }

        return (int) round((float) $value * 100);
    }

    /**
     * One answer about one webhook, in the shape every caller expects.
     *
     * @param bool   $handled Whether anything was written.
     * @param bool   $retry   Whether Mollie should try again.
     * @param string $reason  What happened, for tests and for the log.
     *
     * @return array{handled: bool, retry: bool, reason: string}
     */
    private static function outcome(bool $handled, bool $retry, string $reason): array
    {
        return [
            'handled' => $handled,
            'retry' => $retry,
            'reason' => $reason,
        ];
    }
}
