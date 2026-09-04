<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Notices that the site crashed, and says so once.
 *
 * A PHP fatal error takes the page down mid-render. The visitor sees a white
 * page or WordPress's "there has been a critical error" line; the organisers
 * see nothing at all, because everything this plugin reports goes out by mail
 * from code that just stopped running. The weekly digest cannot help either:
 * it only mails when there is news, so a site that has been dead since Tuesday
 * looks exactly like a quiet week.
 *
 * The crash is recorded here, where the status endpoint can serve it to an
 * outside monitor, and raised as an alert -- once per place in the code, so a
 * page that fatals on every request produces one mail rather than hundreds.
 */
final class CJW_Fatal_Error_Watch
{
    /**
     * The last crash, as an array or an empty string when there has been none.
     */
    public const string OPTION_KEY = 'cjw_last_fatal_error';

    public const string ACTION_CLEAR = 'cjw_clear_fatal_error';

    /**
     * Alert keys start with this, so each place in the code reports once.
     *
     * Public because the status endpoint leaves these alerts out of its own
     * list: this class reports the same crash there, with the file and line.
     */
    public const string ALERT_PREFIX = 'fatal_error:';

    /**
     * The error levels that end the request.
     */
    private const int FATAL_LEVELS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    /**
     * Registers the shutdown handler.
     *
     * @return void
     */
    public static function init(): void
    {
        register_shutdown_function([ self::class, 'catchFatal' ]);

        if (is_admin()) {
            add_action('admin_post_' . self::ACTION_CLEAR, [ self::class, 'handleClear' ]);
        }
    }

    /**
     * Runs at the end of every request, including the ones that died.
     *
     * Everything here is guarded: this runs after a fatal error, when the state
     * of the site is by definition not to be trusted, and a second fatal thrown
     * from the reporter would replace a recorded crash with an unrecorded one.
     *
     * @return void
     */
    public static function catchFatal(): void
    {
        $error = error_get_last();

        if ($error === null || ($error['type'] & self::FATAL_LEVELS) === 0) {
            return;
        }

        try {
            self::report($error['message'], $error['file'], $error['line']);
        } catch (Throwable $reportingFailed) {
            // Nothing left to do but not make it worse: the request is already
            // over, and a second fatal thrown from here would replace a
            // recorded crash with an unrecorded one.
            unset($reportingFailed);
        }
    }

    /**
     * Records a crash and tells the organisers, once per place in the code.
     *
     * @param string $message What PHP said.
     * @param string $file    File the crash happened in.
     * @param int    $line    Line it happened on.
     *
     * @return void
     */
    public static function report(string $message, string $file, int $line): void
    {
        $relative = self::relativePath($file);

        update_option(self::OPTION_KEY, [
            'message' => self::shorten($message),
            'file' => $relative,
            'line' => $line,
            'time' => time(),
        ], false);

        CJW_Alerts::raise(
            self::ALERT_PREFIX . md5($relative . ':' . $line),
            __('De website is gecrasht', 'cjw'),
            '<p>' . esc_html__(
                'Er is een fout opgetreden waardoor de website halverwege is gestopt. Bezoekers zagen op dat moment een lege of kapotte pagina.',
                'cjw'
            ) . '</p><p><code>' . esc_html(self::shorten($message)) . '</code><br>'
                . esc_html(sprintf(
                    /* translators: 1: file path, 2: line number. */
                    __('in %1$s op regel %2$d', 'cjw'),
                    $relative,
                    $line
                )) . '</p><p>' . esc_html__(
                    'Stuur dit bericht door naar wie de website onderhoudt. Je krijgt pas weer een melding als de website op een andere plek crasht, of nadat je deze melding op het tabblad Beheer hebt weggehaald.',
                    'cjw'
                ) . '</p>'
        );
    }

    /**
     * The last recorded crash, or null.
     *
     * @return array{message: string, file: string, line: int, time: int}|null
     */
    public static function last(): ?array
    {
        $stored = get_option(self::OPTION_KEY, null);

        if (! is_array($stored) || ! isset($stored['time'])) {
            return null;
        }

        return [
            'message' => (string) ($stored['message'] ?? ''),
            'file' => (string) ($stored['file'] ?? ''),
            'line' => (int) ($stored['line'] ?? 0),
            'time' => (int) $stored['time'],
        ];
    }

    /**
     * Forgets the last crash, so the site reports healthy again.
     *
     * @return void
     */
    public static function clear(): void
    {
        $last = self::last();

        if ($last !== null) {
            CJW_Alerts::clear(self::ALERT_PREFIX . md5($last['file'] . ':' . $last['line']));
        }

        delete_option(self::OPTION_KEY);
    }

    /**
     * Handles the "opgelost" button on the Beheer tab.
     *
     * @return void
     */
    public static function handleClear(): void
    {
        if (! CJW_Roles::currentUserCan() && ! current_user_can('manage_options')) {
            wp_die(esc_html__('Je hebt geen toestemming hiervoor.', 'cjw'));
        }

        check_admin_referer(self::ACTION_CLEAR);
        self::clear();

        wp_safe_redirect(add_query_arg(
            [
                'page' => 'cjw-summer-camp',
                'tab' => 'beheer',
                'cjw_result' => 'fatal_cleared',
            ],
            admin_url('admin.php')
        ));

        exit;
    }

    /**
     * A path inside the installation rather than the server's own directory
     * layout, which the status endpoint would otherwise hand to strangers.
     *
     * @param string $file Absolute path.
     *
     * @return string
     */
    private static function relativePath(string $file): string
    {
        if ($file === '') {
            return '';
        }

        if (defined('ABSPATH') && str_starts_with($file, (string) ABSPATH)) {
            return substr($file, strlen((string) ABSPATH));
        }

        return basename($file);
    }

    /**
     * One line of message, short enough for an option, a mail and a payload.
     *
     * @param string $message PHP's own message, which can run to kilobytes.
     *
     * @return string
     */
    private static function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($message) > 500 ? mb_substr($message, 0, 499) . '…' : $message;
    }
}
