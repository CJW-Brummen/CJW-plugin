<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The half-filled registration behind one visitor's wizard.
 *
 * The token used to be accepted from `?token=` as well as from the form body,
 * which meant the URL of a part-filled registration was enough to resume it --
 * with a child's details already typed in. A shared link, a browser history, a
 * referrer header or a support screenshot handed that to someone else.
 *
 * The token now travels two ways only, neither of them visible in a URL: the
 * hidden field the form posts back, and an HttpOnly cookie so that closing the
 * tab does not lose a half-finished form. Both are set by this class.
 */
final class CJW_Wizard_Session
{
    /**
     * Cookie carrying the session token between visits.
     */
    public const string COOKIE = 'cjw_wizard_session';

    /**
     * How long a half-finished registration survives.
     *
     * The cookie and the transient are given the same lifetime deliberately: a
     * cookie that outlives its transient sends a returning parent to an empty
     * form that claims to be resumed.
     */
    private const int LIFETIME = 6 * HOUR_IN_SECONDS;

    public function __construct(
        private string $token
    ) {
        $this->token = $this->normalizeToken($this->token);
    }

    /**
     * Whether this request is starting a wizard rather than continuing one.
     *
     * Deliberately asks "is there anything stored to resume?" rather than "was a
     * token presented?". Two reasons, and both were bugs:
     *
     * remember() writes the token into $_COOKIE for the rest of the request, so
     * by the time a shortcode runs every visitor has presented one -- which made
     * the new-session cap unreachable.
     *
     * And a token nobody issued is not a session. Answering by presence alone
     * would let anyone send `Cookie: cjw_wizard_session=anything`, be counted as
     * returning, and skip the cap entirely -- varying the value each time to
     * park an unbounded number of transients, which is the exact abuse the cap
     * exists to stop.
     */
    public function isNew(): bool
    {
        return ! is_array(get_transient("cjw_wizard_{$this->token}"));
    }

    /**
     * Builds the session for this request.
     *
     * The posted token wins over the cookie so that a form submitted from an
     * older tab still lands in the session it belongs to.
     *
     * @param string $postedToken Token from the submitted form, if any.
     */
    public static function fromRequest(string $postedToken = ''): self
    {
        return new self($postedToken !== '' ? $postedToken : self::cookieToken());
    }

    /**
     * The token the browser presented, if any.
     */
    public static function cookieToken(): string
    {
        if (! isset($_COOKIE[ self::COOKIE ])) {
            return '';
        }

        return sanitize_key(wp_unslash((string) $_COOKIE[ self::COOKIE ]));
    }

    /**
     * The state stored for this session, or an empty one.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $state = get_transient("cjw_wizard_{$this->token}");

        return is_array($state) ? $state : [
            'step' => 0,
            'data' => [],
        ];
    }

    /**
     * Stores the state for this session.
     *
     * @param array<string, mixed> $state
     */
    public function save(array $state): void
    {
        set_transient("cjw_wizard_{$this->token}", $state, self::LIFETIME);
    }

    public function reset(): void
    {
        delete_transient("cjw_wizard_{$this->token}");
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Hands the token to the browser as an HttpOnly cookie.
     *
     * Must run before any output. If headers are already sent -- a shortcode
     * rendering mid-page, for instance -- this does nothing and the session
     * still works for as long as the form keeps posting its hidden field; only
     * resuming after closing the tab is lost.
     */
    public function remember(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $this->token, $this->cookieOptions(time() + self::LIFETIME));

        // So the rest of this same request sees the session it just started.
        $_COOKIE[ self::COOKIE ] = $this->token;
    }

    /**
     * Drops the cookie, for when a visitor starts over.
     */
    public function forget(): void
    {
        if (! headers_sent()) {
            setcookie(self::COOKIE, '', $this->cookieOptions(time() - HOUR_IN_SECONDS));
        }

        unset($_COOKIE[ self::COOKIE ]);
    }

    /**
     * Cookie flags: unreadable to JavaScript, HTTPS-only where the site is, and
     * not sent on cross-site requests.
     *
     * @param int $expires Unix timestamp the cookie should expire at.
     *
     * @return array<string, mixed>
     */
    private function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private function normalizeToken(string $token): string
    {
        $token = sanitize_key($token);

        if ($token !== '') {
            return $token;
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (Exception) {
            return sanitize_key(wp_generate_password(32, false));
        }
    }
}
