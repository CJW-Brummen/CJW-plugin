<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tells the organisers when something has broken quietly.
 *
 * The weekly digest is deliberately silent when there is nothing to report,
 * which is right for news and wrong for faults: a fault that appears on a
 * Tuesday should not wait until Monday, and a fault that stops the cron stops
 * the digest along with it.
 *
 * Raised once, not once a day. A site whose retention sweep has been dead for
 * three weeks does not need twenty-one identical mails; it needs one that is
 * still true. An alert stays raised until whatever raised it recovers and calls
 * clear(), which is also what lets the next occurrence be heard.
 */
final class CJW_Alerts
{
    /**
     * Where raised alerts are remembered, as key => first-seen timestamp.
     */
    public const string OPTION_KEY = 'cjw_alerts';

    /**
     * Reports a fault, unless this one has already been reported.
     *
     * The mail is best effort: a site with no camp managers, or one whose mail
     * is itself the thing that has broken, still records the fault so the Site
     * Health check reports it. That check is the durable surface; the mail is
     * only what saves somebody having to go and look.
     *
     * @param string $key     Stable identifier for this fault.
     * @param string $subject Mail subject.
     * @param string $body    Mail body as HTML.
     *
     * @return bool Whether this call raised the fault, as opposed to finding
     *              it already raised.
     */
    public static function raise(string $key, string $subject, string $body): bool
    {
        if (self::isRaised($key)) {
            return false;
        }

        $stored = self::stored();
        $stored[ $key ] = [
            'at' => time(),
            // Kept so the status endpoint and the Beheer tab can name the fault
            // in the words the organisers were mailed, rather than by its key.
            'subject' => $subject,
        ];
        update_option(self::OPTION_KEY, $stored);

        foreach (CJW_Roles::campManagers() as $manager) {
            $email = (string) $manager->user_email;

            if (is_email($email)) {
                CJW_Mailer::sendMessage($email, $subject, $body);
            }
        }

        return true;
    }

    /**
     * Forgets a fault, so its next occurrence is reported again.
     */
    public static function clear(string $key): void
    {
        $stored = self::stored();

        if (! array_key_exists($key, $stored)) {
            return;
        }

        unset($stored[ $key ]);
        update_option(self::OPTION_KEY, $stored);
    }

    /**
     * Whether this fault is currently reported and unresolved.
     */
    public static function isRaised(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * When this fault was first reported, or zero when it is not raised.
     */
    public static function raisedAt(string $key): int
    {
        return (int) (self::all()[ $key ] ?? 0);
    }

    /**
     * Every raised alert, as key => first-seen timestamp.
     *
     * @return array<string, int>
     */
    public static function all(): array
    {
        $clean = [];

        foreach (self::stored() as $key => $value) {
            $clean[ $key ] = is_array($value) ? (int) ($value['at'] ?? 0) : (int) $value;
        }

        return $clean;
    }

    /**
     * Every raised alert, as key => the subject it was reported under.
     *
     * Alerts raised before subjects were stored answer with their key, which
     * is at least something to search the code for.
     *
     * @return array<string, string>
     */
    public static function subjects(): array
    {
        $subjects = [];

        foreach (self::stored() as $key => $value) {
            $subject = is_array($value) ? trim((string) ($value['subject'] ?? '')) : '';
            $subjects[ $key ] = $subject !== '' ? $subject : $key;
        }

        return $subjects;
    }

    /**
     * The option as it is stored, in whichever shape it was written.
     *
     * @return array<string, mixed>
     */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (! is_array($stored)) {
            return [];
        }

        $clean = [];

        foreach ($stored as $key => $value) {
            if (is_string($key) && $key !== '') {
                $clean[ $key ] = $value;
            }
        }

        return $clean;
    }
}
