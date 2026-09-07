<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one place this site talks to Mollie.
 *
 * Deliberately small and deliberately dull: two calls, one credential, no SDK.
 * A payment provider is the piece of a volunteer-run site where a mistake
 * costs somebody real money, so everything here is written to be read rather
 * than to be clever.
 *
 * The API key is a live credential and is kept out of the camp settings on
 * purpose. That option is snapshotted on every save and can be downloaded as a
 * JSON file from the Beheer tab -- a key stored there would end up in a
 * settings history, in an export in somebody's Downloads folder, and in every
 * database backup that file was made from. It lives in an option of its own
 * that nothing exports, and a site that would rather keep it out of the
 * database entirely can define CJW_MOLLIE_API_KEY in wp-config.php instead,
 * which wins over anything stored.
 */
final class CJW_Mollie
{
    /**
     * Where the legacy single key lived, before there were two slots.
     *
     * Read once and moved into place; nothing writes it any more.
     */
    public const string OPTION_KEY = 'cjw_mollie_api_key';

    /**
     * Both keys: 'test' and 'live'.
     *
     * Never added to the camp settings array. See the class docblock.
     */
    public const string OPTION_KEYS = 'cjw_mollie_keys';

    /**
     * Which of the two is in use.
     */
    public const string OPTION_MODE = 'cjw_mollie_mode';

    /**
     * Set from wp-config.php to keep the key out of the database.
     *
     * Pins the mode with it: a site that hard-codes its credential is not one
     * that wants a switch on a settings screen.
     */
    public const string CONSTANT = 'CJW_MOLLIE_API_KEY';

    public const string MODE_LIVE = 'live';

    public const string MODE_TEST = 'test';

    private const string API = 'https://api.mollie.com/v2/';

    /**
     * Mollie gives up on a webhook after 15 seconds, so nothing here may take
     * longer than the request it is answering.
     */
    private const int TIMEOUT = 12;

    /**
     * Whether this site can talk to Mollie at all, in the mode it is in.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::key() !== '';
    }

    /**
     * Whether payments may actually be created from this installation.
     *
     * A restored copy carries the live key along with the database, and would
     * cheerfully charge real parents while pointing Mollie's webhook at a
     * staging address that answers nobody. The same signal that keeps a copy
     * from mailing parents keeps it from billing them.
     *
     * @return bool
     */
    public static function isUsable(): bool
    {
        return self::isConfigured() && ! CJW_Mail_Safe_Mode::isActive() && self::isSecure();
    }

    /**
     * Why payments are switched off, for a person, or '' when they are on.
     *
     * @return string
     */
    public static function unusableReason(): string
    {
        if (! self::isConfigured()) {
            return self::mode() === self::MODE_TEST
                ? __('Er is nog geen Mollie-testsleutel ingesteld.', 'cjw')
                : __('Er is nog geen Mollie-sleutel voor de live-modus ingesteld.', 'cjw');
        }

        if (CJW_Mail_Safe_Mode::isActive()) {
            return __('Dit is een kopie van de website. Er worden hier geen betalingen aangemaakt.', 'cjw');
        }

        if (! self::isSecure()) {
            return __('De website draait niet op https, en zonder https mag er niet betaald worden.', 'cjw');
        }

        return '';
    }

    /**
     * Whether the site is served over https.
     *
     * Mollie will not call an http webhook, and a payment link that is not
     * encrypted is not a payment link.
     *
     * @return bool
     */
    public static function isSecure(): bool
    {
        return str_starts_with(strtolower(home_url('/')), 'https://');
    }

    /**
     * Which mode the site is in.
     *
     * Test unless somebody has deliberately switched to live: a camp that
     * forgets to switch loses a rehearsal, and one that forgets the other way
     * loses a season's income.
     *
     * @return string
     */
    public static function mode(): string
    {
        if (self::keyIsPinned()) {
            return str_starts_with(self::pinnedKey(), 'live_') ? self::MODE_LIVE : self::MODE_TEST;
        }

        return get_option(self::OPTION_MODE) === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_TEST;
    }

    /**
     * Both modes, with the label a screen shows.
     *
     * @return array<string, string>
     */
    public static function modes(): array
    {
        return [
            self::MODE_TEST => __('Testmodus', 'cjw'),
            self::MODE_LIVE => __('Live', 'cjw'),
        ];
    }

    /**
     * Switches which key is in use.
     *
     * Refuses a mode with no key behind it: a switch that leaves the site
     * unable to take a payment is worse than no switch, and it would only be
     * discovered by the first parent who tried.
     *
     * @param string $mode One of the MODE_ constants.
     *
     * @return bool Whether the switch happened.
     */
    public static function setMode(string $mode): bool
    {
        if (self::keyIsPinned() || ! array_key_exists($mode, self::modes()) || ! self::hasKey($mode)) {
            return false;
        }

        update_option(self::OPTION_MODE, $mode, false);

        return true;
    }

    /**
     * Whether a key is stored for a mode.
     *
     * @param string $mode One of the MODE_ constants.
     *
     * @return bool
     */
    public static function hasKey(string $mode): bool
    {
        return self::keyFor($mode) !== '';
    }

    /**
     * A key as shown on screen: enough to recognise, not enough to use.
     *
     * @param string|null $mode Which slot, or null for the one in use.
     *
     * @return string
     */
    public static function maskedKey(?string $mode = null): string
    {
        $key = $mode === null ? self::key() : self::keyFor($mode);

        if ($key === '') {
            return '';
        }

        $prefix = str_starts_with($key, 'live_') ? 'live_' : 'test_';

        return $prefix . '••••' . substr($key, -4);
    }

    /**
     * Whether the key comes from wp-config.php rather than the database.
     *
     * @return bool
     */
    public static function keyIsPinned(): bool
    {
        return self::pinnedKey() !== '';
    }

    /**
     * Stores a key in the slot its own prefix names.
     *
     * The prefix decides, not a dropdown: a key that says live_ is the live
     * key, and there is no way to file it as anything else by mistake.
     *
     * @param string $key The key as typed.
     *
     * @return string The mode it was filed under, or '' when it was refused.
     */
    public static function storeKey(string $key): string
    {
        $key = self::sanitizeKey($key);

        if (! self::looksLikeKey($key)) {
            return '';
        }

        $mode = str_starts_with($key, 'live_') ? self::MODE_LIVE : self::MODE_TEST;
        $keys = self::keys();
        $keys[ $mode ] = $key;

        update_option(self::OPTION_KEYS, $keys, false);

        return $mode;
    }

    /**
     * Forgets one of the two keys.
     *
     * Switches back to the other mode when the one in use is the one being
     * removed, so the site is never left pointing at an empty slot.
     *
     * @param string $mode One of the MODE_ constants.
     *
     * @return void
     */
    public static function clearKey(string $mode): void
    {
        if (! array_key_exists($mode, self::modes())) {
            return;
        }

        $keys = self::keys();
        unset($keys[ $mode ]);

        update_option(self::OPTION_KEYS, $keys, false);

        $other = $mode === self::MODE_LIVE ? self::MODE_TEST : self::MODE_LIVE;

        if (self::mode() === $mode) {
            update_option(self::OPTION_MODE, self::hasKey($other) ? $other : self::MODE_TEST, false);
        }
    }

    /**
     * Whether a string has the shape of a Mollie API key.
     *
     * @param string $key Candidate.
     *
     * @return bool
     */
    public static function looksLikeKey(string $key): bool
    {
        return preg_match('/^(live|test)_[A-Za-z0-9]{25,}$/', $key) === 1;
    }

    /**
     * Whether a URL is somewhere this site may send a parent to pay.
     *
     * The address comes out of an authenticated API response over TLS, so it
     * is not really in doubt -- but it is handed to wp_redirect(), and a
     * redirect built from stored data is exactly the kind of thing that turns
     * into an open redirect two refactors later. Checked rather than trusted.
     *
     * @param string $url Candidate checkout address.
     *
     * @return bool
     */
    public static function isCheckoutUrl(string $url): bool
    {
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return false;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        return $host === 'mollie.com' || str_ends_with($host, '.mollie.com');
    }

    /**
     * Creates a payment and returns what Mollie answered.
     *
     * @param int    $cents       Amount to charge.
     * @param string $description Shown on the parent's bank statement.
     * @param string $redirectUrl Where the parent comes back to.
     * @param string $webhookUrl  Where Mollie reports the outcome.
     * @param array  $metadata    Small payload handed back to us later.
     * @param string $idempotency Key that makes a retry safe.
     * @param array<string, mixed> $metadata
     *
     * @return array{ok: bool, payment?: array<string, mixed>, error?: string}
     */
    public static function createPayment(
        int $cents,
        string $description,
        string $redirectUrl,
        string $webhookUrl,
        array $metadata,
        string $idempotency
    ): array {
        if ($cents < 1) {
            return [
                'ok' => false,
                'error' => __('Er valt niets te betalen.', 'cjw'),
            ];
        }

        return self::request('POST', 'payments', [
            'amount' => [
                'currency' => 'EUR',
                // Mollie wants the exact amount as a string, two decimals.
                'value' => number_format($cents / 100, 2, '.', ''),
            ],
            'description' => mb_substr($description, 0, 255),
            'redirectUrl' => $redirectUrl,
            'webhookUrl' => $webhookUrl,
            'method' => 'ideal',
            'locale' => 'nl_NL',
            'metadata' => $metadata,
        ], $idempotency);
    }

    /**
     * Reads a payment back from Mollie.
     *
     * This is the only thing that may be believed about a payment. The webhook
     * carries an id and nothing else precisely so that nobody can post their
     * way to a paid registration.
     *
     * @param string $paymentId Mollie payment id.
     *
     * @return array{ok: bool, payment?: array<string, mixed>, error?: string, status?: int}
     */
    public static function getPayment(string $paymentId, string $mode = ''): array
    {
        if (! self::looksLikePaymentId($paymentId)) {
            return [
                'ok' => false,
                'error' => __('Dat is geen geldig betaalkenmerk.', 'cjw'),
            ];
        }

        return self::request('GET', 'payments/' . rawurlencode($paymentId), null, '', $mode);
    }

    /**
     * Whether a string has the shape of a Mollie payment id.
     *
     * @param string $paymentId Candidate.
     *
     * @return bool
     */
    public static function looksLikePaymentId(string $paymentId): bool
    {
        return preg_match('/^tr_[A-Za-z0-9]{5,60}$/', $paymentId) === 1;
    }

    /**
     * One call to Mollie.
     *
     * @param string     $method      HTTP method.
     * @param string     $path        Path under the API root.
     * @param array|null $body        JSON body, for POST.
     * @param string     $idempotency Idempotency key, for POST.
     * @param string     $mode        Which key to use, or '' for the one in use.
     * @param array<string, mixed>|null $body
     *
     * @return array{ok: bool, payment?: array<string, mixed>, error?: string, status?: int}
     */
    private static function request(
        string $method,
        string $path,
        ?array $body = null,
        string $idempotency = '',
        string $mode = ''
    ): array {
        $key = $mode === '' ? self::key() : self::keyFor($mode);

        if ($key === '') {
            return [
                'ok' => false,
                'error' => __('Er is geen Mollie-sleutel ingesteld.', 'cjw'),
            ];
        }

        $headers = [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($idempotency !== '') {
            // Makes a repeated create safe: a timeout that is retried must not
            // charge a parent twice.
            $headers['Idempotency-Key'] = $idempotency;
        }

        $args = [
            'method' => $method,
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $args['body'] = (string) wp_json_encode($body);
        }

        $response = wp_remote_request(self::API . $path, $args);

        if (is_wp_error($response)) {
            // The message can carry the request URL but never the key, which
            // only ever travels in a header.
            return [
                'ok' => false,
                'error' => $response->get_error_message(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status > 299) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => self::errorFrom($decoded, $status),
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'payment' => $decoded,
        ];
    }

    /**
     * What went wrong, in whatever detail Mollie gave.
     *
     * @param array $decoded Response body.
     * @param int   $status  HTTP status.
     * @param array<string, mixed> $decoded
     *
     * @return string
     */
    private static function errorFrom(array $decoded, int $status): string
    {
        $detail = trim((string) ($decoded['detail'] ?? ''));

        if ($detail !== '') {
            return $detail;
        }

        return sprintf(
            /* translators: %d: HTTP status code. */
            __('Mollie antwoordde met foutcode %d.', 'cjw'),
            $status
        );
    }

    /**
     * The key in use: wp-config.php wins over the database.
     *
     * Never rendered, never logged, never exported.
     *
     * @return string
     */
    private static function key(): string
    {
        return self::keyFor(self::mode());
    }

    /**
     * The key stored for one mode, or ''.
     *
     * @param string $mode One of the MODE_ constants.
     *
     * @return string
     */
    private static function keyFor(string $mode): string
    {
        $pinned = self::pinnedKey();

        if ($pinned !== '') {
            // A pinned key answers for its own mode and for nothing else, so a
            // webhook from the other mode is refused rather than looked up
            // with a credential that cannot see it.
            $pinnedMode = str_starts_with($pinned, 'live_') ? self::MODE_LIVE : self::MODE_TEST;

            return $mode === $pinnedMode ? $pinned : '';
        }

        return self::keys()[ $mode ] ?? '';
    }

    /**
     * The key hard-coded in wp-config.php, or ''.
     *
     * @return string
     */
    private static function pinnedKey(): string
    {
        if (! defined(self::CONSTANT)) {
            return '';
        }

        $pinned = self::sanitizeKey((string) constant(self::CONSTANT));

        return self::looksLikeKey($pinned) ? $pinned : '';
    }

    /**
     * Both stored keys, keyed by mode.
     *
     * @return array<string, string>
     */
    private static function keys(): array
    {
        $stored = get_option(self::OPTION_KEYS, null);

        if (! is_array($stored)) {
            return self::migrateLegacyKey();
        }

        $keys = [];

        foreach (self::modes() as $mode => $label) {
            $key = self::sanitizeKey((string) ($stored[ $mode ] ?? ''));

            if (self::looksLikeKey($key)) {
                $keys[ $mode ] = $key;
            }
        }

        return $keys;
    }

    /**
     * Moves a key stored before there were two slots into the right one.
     *
     * @return array<string, string>
     */
    private static function migrateLegacyKey(): array
    {
        $legacy = self::sanitizeKey((string) get_option(self::OPTION_KEY, ''));

        if (! self::looksLikeKey($legacy)) {
            return [];
        }

        $mode = str_starts_with($legacy, 'live_') ? self::MODE_LIVE : self::MODE_TEST;
        $keys = [ $mode => $legacy ];

        update_option(self::OPTION_KEYS, $keys, false);
        update_option(self::OPTION_MODE, $mode, false);
        delete_option(self::OPTION_KEY);

        return $keys;
    }

    /**
     * A key with anything that is not part of one taken out.
     *
     * @param string $key Raw value.
     *
     * @return string
     */
    private static function sanitizeKey(string $key): string
    {
        return trim(preg_replace('/[^A-Za-z0-9_]/', '', $key) ?? '');
    }
}
