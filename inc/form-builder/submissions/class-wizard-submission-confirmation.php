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
 * Off by default, per form. That is deliberate rather than cautious: mail
 * deliverability is not solved yet -- there is no authenticated SMTP and no way
 * to test sending from the admin -- and a confirmation that silently fails to
 * arrive is worse than none, because it replaces "I heard nothing, I'll check"
 * with "it said it would confirm, so it must have worked".
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
            update_post_meta($submission_id, self::META_RESULT, self::NO_ADDRESS);

            return self::NO_ADDRESS;
        }

        $subject = trim((string) ($settings['parent_confirmation_subject'] ?? ''));

        if ($subject === '') {
            $subject = sprintf(
                /* translators: %s: form title. */
                __('Bevestiging van je inschrijving voor %s', 'cjw'),
                get_the_title($wizard_id)
            );
        }

        $sent = wp_mail($to, $subject, $this->body($settings, $wizard_id));
        $result = $sent ? self::SENT : self::FAILED;

        update_post_meta($submission_id, self::META_RESULT, $result);

        return $result;
    }

    /**
     * The message body.
     *
     * Camp-specific detail comes from the form's own copy, which the summer-camp
     * integration has already had a pass at -- so `{camp_start_date}` and
     * `{camp_price_week}` are real values by the time they reach here.
     *
     * Unlike the organiser's notification, this mail legitimately carries the
     * reader's own registration: it goes to the address they gave, about the
     * child they entered. What it must not do is quote answers back
     * indiscriminately, so it carries the camp's copy and nothing lifted from
     * the payload.
     *
     * @param array $settings  Form settings.
     * @param int   $wizard_id Form post id.
     * @param array<string, mixed> $settings
     */
    private function body(array $settings, int $wizard_id): string
    {
        $copy = trim((string) ($settings['parent_confirmation_body'] ?? ''));

        if ($copy === '') {
            $copy = __('Bedankt voor je inschrijving. We hebben hem goed ontvangen en nemen contact met je op als er iets nodig is.', 'cjw');
        }

        $lines = [
            $copy,
            '',
            sprintf(
                /* translators: %s: form title. */
                __('Inschrijving: %s', 'cjw'),
                get_the_title($wizard_id)
            ),
        ];

        $contact = function_exists('cjw_summer_camp') ? (string) cjw_summer_camp()->getContactEmail() : '';

        if (is_email($contact)) {
            $lines[] = '';
            $lines[] = sprintf(
                /* translators: %s: contact e-mail address. */
                __('Vragen? Mail ons op %s.', 'cjw'),
                $contact
            );
        }

        return implode(PHP_EOL, $lines);
    }
}
