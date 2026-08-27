<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Abuse protection for the public registration wizard.
 *
 * The wizard is the only unauthenticated write path in the plugin: a completed
 * pass creates a post, writes two meta rows and sends mail, and every partial
 * pass parks a transient for six hours. Nothing stood between a script and all
 * of that.
 *
 * Three layers, deliberately all server-side and invisible to a real parent --
 * no captcha, and no submission content leaves this server:
 *
 * 1. a honeypot field, hidden from people and filled in by naive bots;
 * 2. a signed render timestamp, rejecting anything submitted implausibly fast;
 * 3. a per-visitor hourly cap on new sessions and on completed registrations.
 *
 * The visitor is identified by a salted hash of their IP, never the address
 * itself, so the throttle can recognise a repeat without the options table
 * holding personal data.
 */
final class CJW_Wizard_Guard
{
    /**
     * Hidden field people never see and bots fill in.
     */
    public const string HONEYPOT_FIELD = 'cjw_wizard_website';

    /**
     * Signed timestamp of the moment the step was rendered.
     */
    public const string TIMESTAMP_FIELD = 'cjw_wizard_rendered';

    /**
     * Transient holding the IP-diversity sample behind the Site Health check.
     */
    public const string TRANSIENT_SAMPLE = 'cjw_wizard_traffic_sample';

    /**
     * Seconds a step must have been on screen before a submission is credible.
     *
     * Two seconds clears any human -- reading a heading and clicking a button
     * cannot be faster -- while a script posting straight at the endpoint is
     * almost always at zero. Change with the `cjw_wizard_min_seconds` filter.
     */
    private const int MIN_SECONDS = 2;

    /**
     * Completed registrations allowed per visitor per hour.
     *
     * Set well above any real family: three large families back to back still
     * clear it. Filter: `cjw_wizard_max_completions_per_hour`.
     */
    private const int MAX_COMPLETIONS_PER_HOUR = 10;

    /**
     * New wizard sessions allowed per visitor per hour.
     *
     * Filter: `cjw_wizard_max_sessions_per_hour`.
     */
    private const int MAX_SESSIONS_PER_HOUR = 15;

    /**
     * Requests to observe before the Site Health check trusts the sample.
     */
    private const int SAMPLE_THRESHOLD = 20;

    /**
     * Distinct visitors to remember while sampling.
     */
    private const int SAMPLE_WIDTH = 8;

    /**
     * Builds the hidden fields that make a submission verifiable.
     *
     * Returned rather than echoed so the renderer stays testable without this
     * class and the WordPress functions it needs.
     */
    public function trapFields(): string
    {
        return '<div class="cjw-wizard-trap" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">'
            . '<label for="' . esc_attr(self::HONEYPOT_FIELD) . '">' . esc_html__('Laat dit veld leeg.', 'cjw') . '</label>'
            . '<input type="text" id="' . esc_attr(self::HONEYPOT_FIELD) . '" name="' . esc_attr(self::HONEYPOT_FIELD) . '" value="" tabindex="-1" autocomplete="off">'
            . '</div>'
            . '<input type="hidden" name="' . esc_attr(self::TIMESTAMP_FIELD) . '" value="' . esc_attr($this->signedTimestamp()) . '">';
    }

    /**
     * Reports why a submission looks automated, or an empty string if it does not.
     *
     * @param array $input Raw request payload.
     * @param array<string, mixed> $input
     *
     * @return string One of 'honeypot', 'too_fast', or ''.
     */
    public function rejectionReason(array $input): string
    {
        if (trim((string) ($input[ self::HONEYPOT_FIELD ] ?? '')) !== '') {
            return 'honeypot';
        }

        if (! $this->stampIsOldEnough((string) ($input[ self::TIMESTAMP_FIELD ] ?? ''))) {
            return 'too_fast';
        }

        return '';
    }

    /**
     * Whether this visitor may open another wizard session this hour.
     */
    public function mayStartSession(): bool
    {
        return $this->consume('s', $this->sessionLimit());
    }

    /**
     * Whether this visitor may complete another registration this hour.
     */
    public function mayComplete(): bool
    {
        return $this->consume('c', $this->completionLimit());
    }

    /**
     * Records one observation for the IP-diversity sample.
     *
     * If a proxy or CDN sits in front of the site, REMOTE_ADDR is the proxy and
     * every visitor collapses onto one fingerprint -- which would throttle the
     * whole site as though it were a single person. The sample makes that
     * visible in Site Health instead of silently turning parents away.
     */
    public function noteTraffic(): void
    {
        $sample = get_transient(self::TRANSIENT_SAMPLE);

        if (! is_array($sample)) {
            $sample = [
                'seen' => [],
                'count' => 0,
            ];
        }

        $seen = is_array($sample['seen'] ?? null) ? $sample['seen'] : [];
        $fingerprint = $this->fingerprint();

        if (! in_array($fingerprint, $seen, true) && count($seen) < self::SAMPLE_WIDTH) {
            $seen[] = $fingerprint;
        }

        set_transient(
            self::TRANSIENT_SAMPLE,
            [
                'seen' => $seen,
                'count' => (int) ($sample['count'] ?? 0) + 1,
            ],
            DAY_IN_SECONDS
        );
    }

    /**
     * The observed traffic sample, for the Site Health check.
     *
     * @return array{count: int, distinct: int, threshold: int, conclusive: bool}
     */
    public static function trafficSample(): array
    {
        $sample = get_transient(self::TRANSIENT_SAMPLE);
        $count = is_array($sample) ? (int) ($sample['count'] ?? 0) : 0;
        $distinct = is_array($sample) && is_array($sample['seen'] ?? null) ? count($sample['seen']) : 0;

        return [
            'count' => $count,
            'distinct' => $distinct,
            'threshold' => self::SAMPLE_THRESHOLD,
            'conclusive' => $count >= self::SAMPLE_THRESHOLD,
        ];
    }

    /**
     * The completed-registration cap actually in force.
     */
    public function completionLimit(): int
    {
        return max(1, (int) apply_filters('cjw_wizard_max_completions_per_hour', self::MAX_COMPLETIONS_PER_HOUR));
    }

    /**
     * The new-session cap actually in force.
     */
    public function sessionLimit(): int
    {
        return max(1, (int) apply_filters('cjw_wizard_max_sessions_per_hour', self::MAX_SESSIONS_PER_HOUR));
    }

    /**
     * A stable, non-identifying handle for the current visitor.
     *
     * Salted with wp_salt(), so the stored value cannot be reversed to an
     * address if the options table leaks, and it expires within the hour.
     * Override the source address with `cjw_wizard_client_ip` when a proxy or
     * CDN means REMOTE_ADDR is not the visitor.
     */
    public function fingerprint(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads the connecting address for rate limiting; not form input.
        $remote = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']))
            : '';

        $address = (string) apply_filters('cjw_wizard_client_ip', $remote);

        return substr(hash_hmac('sha256', $address, wp_salt('auth')), 0, 32);
    }

    /**
     * Signs the current time so the elapsed check cannot be forged.
     */
    private function signedTimestamp(): string
    {
        $now = (string) time();

        return $now . '.' . hash_hmac('sha256', $now, wp_salt('nonce'));
    }

    /**
     * Verifies the render stamp and the time elapsed since it was issued.
     */
    private function stampIsOldEnough(string $stamp): bool
    {
        $parts = explode('.', $stamp, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [ $issued, $signature ] = $parts;

        if (! hash_equals(hash_hmac('sha256', $issued, wp_salt('nonce')), $signature)) {
            return false;
        }

        $minimum = max(0, (int) apply_filters('cjw_wizard_min_seconds', self::MIN_SECONDS));

        return (int) $issued > 0 && (time() - (int) $issued) >= $minimum;
    }

    /**
     * Takes one unit from an hourly budget, or reports that it is spent.
     *
     * A fixed window: the reset time is set when the window opens and is not
     * pushed back by later requests, so a family spreading registrations across
     * an evening is not slowly squeezed out by their own earlier ones.
     *
     * @param string $bucket Short bucket id.
     * @param int    $limit  Requests allowed per hour.
     */
    private function consume(string $bucket, int $limit): bool
    {
        $key = 'cjw_wz_' . $bucket . '_' . $this->fingerprint();
        $now = time();
        $window = get_transient($key);

        if (! is_array($window) || (int) ($window['reset'] ?? 0) <= $now) {
            $window = [
                'count' => 0,
                'reset' => $now + HOUR_IN_SECONDS,
            ];
        }

        if ((int) $window['count'] >= $limit) {
            return false;
        }

        $window['count'] = (int) $window['count'] + 1;

        set_transient($key, $window, max(60, (int) $window['reset'] - $now));

        return true;
    }
}
