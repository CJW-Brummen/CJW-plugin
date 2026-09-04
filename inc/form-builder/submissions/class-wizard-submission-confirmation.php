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

    /**
     * Alert key for "the confirmation could not be sent".
     */
    private const string ALERT_FAILED = 'confirmation_mail_failed';

    /**
     * Alert key prefix for "this form promises a confirmation it cannot send",
     * completed with the form id.
     */
    private const string ALERT_NO_ADDRESS = 'confirmation_no_address:';

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
        $settings = $config['settings'] ?? [];

        if (empty($settings['send_parent_confirmation'])) {
            return '';
        }

        $to = CJW_Wizard_Submission_Repository::findEmail($config, $data);

        if ($to === '' || ! is_email($to)) {
            // A form that confirms but never asks for an address is a
            // misconfiguration worth being able to see.
            $this->record($submission_id, self::NO_ADDRESS);
            $this->reportNoAddress($wizard_id);

            return self::NO_ADDRESS;
        }

        $sent = CJW_Mailer::send(
            CJW_Mail_Templates::CONFIRMATION,
            $to,
            CJW_Mail_Tokens::registration($submission_id, $config, $data)
        );

        $result = $sent ? self::SENT : self::FAILED;

        $this->record($submission_id, $result);

        if ($sent) {
            // Mail is working again, so the next breakage is worth hearing about.
            CJW_Alerts::clear(self::ALERT_FAILED);
        } else {
            $this->reportFailure($submission_id);
        }

        return $result;
    }

    /**
     * Tells the organisers that a parent was promised a confirmation and did
     * not get one.
     *
     * A parent reads a missing confirmation as a failed registration, so they
     * register again or telephone -- and until now the only trace was a red
     * line on an admin list that gets opened a few times a year. Raised once
     * and cleared by the next successful send, because a mail server that is
     * down for a day should produce one alert, not ninety.
     */
    private function reportFailure(int $submission_id): void
    {
        $reason = CJW_Mailer::lastError();

        $body = '<p>' . sprintf(
            /* translators: %d: submission number. */
            esc_html__(
                'De bevestigingsmail voor inschrijving #%d is niet verstuurd. De ouder heeft dus niets ontvangen, terwijl het formulier wel zei dat er een bevestiging aankomt.',
                'cjw'
            ),
            $submission_id
        ) . '</p>';

        if ($reason !== '') {
            $body .= '<p>' . sprintf(
                /* translators: %s: error message from the mail server. */
                esc_html__('De mailserver meldde: %s', 'cjw'),
                esc_html($reason)
            ) . '</p>';
        }

        $body .= '<p>' . esc_html__(
            'Neem contact op met deze ouder, en controleer daarna of er meer inschrijvingen zonder bevestiging zijn blijven staan. Je krijgt pas weer een melding als het versturen eerst weer is gelukt.',
            'cjw'
        ) . '</p>';

        CJW_Alerts::raise(
            self::ALERT_FAILED,
            __('Bevestigingsmail niet verstuurd', 'cjw'),
            $body
        );
    }

    /**
     * Tells the organisers that a form promises a confirmation it can never
     * send, because it never asks for an address.
     *
     * Keyed per form: two forms can be wrong in this way independently, and
     * fixing one should not silence the other.
     */
    private function reportNoAddress(int $wizard_id): void
    {
        $body = '<p>' . esc_html__(
            'Dit formulier stuurt een bevestiging naar de ouder, maar vraagt nergens om een e-mailadres. Er is dus niemand om de bevestiging naartoe te sturen.',
            'cjw'
        ) . '</p><p>' . esc_html__(
            'Voeg een e-mailveld toe aan het formulier en geef het de betekenis “E-mailadres van de ouder”, of zet de bevestiging uit in de instellingen van dit formulier.',
            'cjw'
        ) . '</p>';

        CJW_Alerts::raise(
            self::ALERT_NO_ADDRESS . $wizard_id,
            __('Formulier belooft een bevestiging maar vraagt geen e-mailadres', 'cjw'),
            $body
        );
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
