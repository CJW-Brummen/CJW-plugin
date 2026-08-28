<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tells the parent their registration arrived.
 *
 * Until now the person who just entered their child's details got nothing back:
 * no confirmation, no summary, no reference. Silence after a form is how people
 * conclude it failed, so they register again or telephone.
 *
 * Off by default, per form. That is deliberate rather than cautious: a
 * confirmation that silently fails to arrive is worse than none, because it
 * replaces "I heard nothing, I'll check" with "it said it would confirm, so it
 * must have worked".
 *
 * The copy is no longer per form. It lives on the Mails screen with the rest,
 * where somebody who is not a developer can edit it and see what it will say.
 *
 * The outcome is recorded on the submission either way, so an organiser can see
 * that a parent was written to rather than assume it.
 */
final class CJW_Wizard_Submission_Confirmation
{
    /**
     * Records what happened, so the list screen need not guess.
     */
    public const string META_RESULT = '_cjw_wizard_confirmation';

    /**
     * When that happened, as a Unix timestamp.
     *
     * Separate from the result rather than folded into it: "verstuurd" answers
     * a different question from "verstuurd op 14 juli om 14:03", and only the
     * second one settles an argument with a parent who says they never got it.
     * Rows written before this constant existed carry no time, and the screens
     * fall back to the arrival date rather than inventing one.
     */
    public const string META_RESULT_TIME = '_cjw_wizard_confirmation_time';

    public const string SENT = 'sent';
    public const string NO_ADDRESS = 'no_address';
    public const string FAILED = 'failed';

    /**
     * Sends the confirmation, if this form asks for one.
     *
     * @param int   $submission_id Submission post id.
     * @param int   $wizard_id     Form post id.
     * @param array $config        Form configuration, tokens already resolved.
     * @param array $data          Submitted payload.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     *
     * @return string One of the result constants, or an empty string when the
     *                form does not send confirmations.
     */
    public function maybe_send(int $submission_id, int $wizard_id, array $config, array $data): string
    {
        unset($wizard_id);

        $settings = $config['settings'] ?? [];

        if (empty($settings['send_parent_confirmation'])) {
            return '';
        }

        $to = CJW_Wizard_Submission_Repository::findEmail($config, $data);

        if ($to === '' || ! is_email($to)) {
            // A form that confirms but never asks for an address is a
            // misconfiguration worth being able to see.
            $this->record($submission_id, self::NO_ADDRESS);

            return self::NO_ADDRESS;
        }

        $sent = CJW_Mailer::send(
            CJW_Mail_Templates::CONFIRMATION,
            $to,
            CJW_Mail_Tokens::registration($submission_id, $config, $data)
        );

        $result = $sent ? self::SENT : self::FAILED;

        $this->record($submission_id, $result);

        return $result;
    }

    /**
     * What was recorded for a submission, or an empty string when this form
     * does not send confirmations.
     */
    public static function result(int $submission_id): string
    {
        return (string) get_post_meta($submission_id, self::META_RESULT, true);
    }

    /**
     * One sentence an organiser can act on, already escaped, or an empty string
     * when there is nothing to say.
     *
     * Deliberately never renders "niets verstuurd" for a form that does not
     * confirm: absence of a confirmation is only news when one was promised.
     */
    public static function summary(int $submission_id): string
    {
        $result = self::result($submission_id);

        if ($result === '') {
            return '';
        }

        $stamp = (int) get_post_meta($submission_id, self::META_RESULT_TIME, true);

        // Rows predating META_RESULT_TIME: the mail went out during the same
        // request that created the submission, so its arrival time is accurate
        // to the second for every purpose this line serves.
        if ($stamp < 1) {
            $post = get_post($submission_id);
            $stamp = $post instanceof WP_Post ? (int) get_post_timestamp($post) : 0;
        }

        $when = $stamp > 0 ? wp_date(__('j F Y \o\m H:i', 'cjw'), $stamp) : '';

        return match ($result) {
            self::SENT => $when !== ''
                ? esc_html(sprintf(
                    /* translators: %s: date and time the confirmation was sent. */
                    __('Verstuurd op %s', 'cjw'),
                    $when
                ))
                : esc_html__('Verstuurd', 'cjw'),
            self::NO_ADDRESS => esc_html__('Niet verstuurd: dit formulier vroeg geen e-mailadres', 'cjw'),
            self::FAILED => esc_html__('Versturen mislukt', 'cjw'),
            default => '',
        };
    }

    /**
     * Whether the parent was promised a confirmation and did not get one.
     */
    public static function failed(int $submission_id): bool
    {
        return self::result($submission_id) === self::FAILED;
    }

    /**
     * Stores the outcome and the moment it happened.
     */
    private function record(int $submission_id, string $result): void
    {
        update_post_meta($submission_id, self::META_RESULT, $result);
        update_post_meta($submission_id, self::META_RESULT_TIME, time());
    }
}
