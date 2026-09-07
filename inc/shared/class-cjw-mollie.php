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
     * Where the key is stored when it is not defined in wp-config.php.
     *
     * Never added to the camp settings array. See the class docblock.
     */
    public const string OPTION_KEY = 'cjw_mollie_api_key';

    /**
     * Set from wp-config.php to keep the key out of the database.
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
     * Whether this site can talk to Mollie at all.
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
            return __('Er is nog geen Mollie-sleutel ingesteld.', 'cjw');
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
     * Live or test, decided by the key itself rather than by a checkbox
     * somebody can leave in the wrong position.
     *
     * @return string
     */
    public static function mode(): string
    {
        return str_starts_with(self::key(), 'live_') ? self::MODE_LIVE : self::MODE_TEST;
    }

    /**
     * The key, as shown on screen: enough to recognise, not enough to use.
     *
     * @return string
     */
    public static function maskedKey(): string
    {
        $key = self::key();

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
        return defined(self::CONSTANT) && self::sanitizeKey((string) constant(self::CONSTANT)) !== '';
    }

    /**
     * Stores a key, or clears it when given an empty string.
     *
     * @param string $key The key as typed.
     *
     * @return bool Whether it looked like a Mollie key at all.
     */
    public static function storeKey(string $key): bool
    {
        $key = self::sanitizeKey($key);

        if ($key === '') {
            delete_option(self::OPTION_KEY);

            return true;
        }

        if (! self::looksLikeKey($key)) {
            return false;
        }

        update_option(self::OPTION_KEY, $key, false);

        return true;
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
    public static function getPayment(string $paymentId): array
    {
        if (! self::looksLikePaymentId($paymentId)) {
            return [
                'ok' => false,
                'error' => __('Dat is geen geldig betaalkenmerk.', 'cjw'),
            ];
        }

        return self::request('GET', 'payments/' . rawurlencode($paymentId));
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
     * @param array<string, mixed>|null $body
     *
     * @return array{ok: bool, payment?: array<string, mixed>, error?: string, status?: int}
     */
    private static function request(string $method, string $path, ?array $body = null, string $idempotency = ''): array
    {
        $key = self::key();

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
        if (defined(self::CONSTANT)) {
            $pinned = self::sanitizeKey((string) constant(self::CONSTANT));

            if ($pinned !== '') {
                return $pinned;
            }
        }

        return self::sanitizeKey((string) get_option(self::OPTION_KEY, ''));
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
