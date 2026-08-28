<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Moves mail copy that was written before there was one place to write it.
 *
 * Two homes are being closed: the parent confirmation's subject and body, which
 * lived in each form's own settings, and birthday_mail_text, which lived on the
 * camp settings screen next to a checkbox promising a feature that did not
 * exist. Both held real sentences somebody typed, and neither is reachable
 * after the fields come out.
 *
 * Runs once, on activation, and records that it ran. Anything already written on
 * the Mails screen wins -- a migration must never overwrite the newer text with
 * the older one, which is the way these usually go wrong.
 */
final class CJW_Mail_Migration
{
    /**
     * Set once the copy has been moved.
     */
    public const string OPTION_DONE = 'cjw_mail_templates_migrated';

    /**
     * Moves whatever is worth keeping into the central templates.
     *
     * @return int Templates filled from old copy.
     */
    public static function run(): int
    {
        if (get_option(self::OPTION_DONE) === 'yes') {
            return 0;
        }

        $templates = CJW_Mail_Templates::stored();
        $moved = 0;

        $confirmation = self::confirmationFromForms();

        if ($confirmation !== [] && self::isBlank($templates, CJW_Mail_Templates::CONFIRMATION)) {
            $templates[ CJW_Mail_Templates::CONFIRMATION ] = $confirmation;
            ++$moved;
        }

        $birthday = self::birthdayFromSettings();

        if ($birthday !== [] && self::isBlank($templates, CJW_Mail_Templates::BIRTHDAY)) {
            $templates[ CJW_Mail_Templates::BIRTHDAY ] = $birthday;
            ++$moved;
        }

        if ($moved > 0) {
            CJW_Mail_Templates::save($templates);
        }

        update_option(self::OPTION_DONE, 'yes', false);

        return $moved;
    }

    /**
     * Whether a template has nothing written in it yet.
     *
     * @param array<string, array{subject: string, body: string}> $templates
     */
    private static function isBlank(array $templates, string $key): bool
    {
        return trim((string) ($templates[ $key ]['subject'] ?? '')) === ''
            && trim((string) ($templates[ $key ]['body'] ?? '')) === '';
    }

    /**
     * The confirmation copy from whichever form has any.
     *
     * Takes the first form that wrote something. More than one form with its own
     * confirmation copy is a case that has never existed on this site -- there
     * is one form -- and silently concatenating two would produce a mail nobody
     * wrote.
     *
     * @return array{subject: string, body: string}|array{}
     */
    private static function confirmationFromForms(): array
    {
        $forms = get_posts([
            'post_type' => 'cjw_wizard',
            'post_status' => 'any',
            'numberposts' => 50,
            'fields' => 'ids',
        ]);

        foreach ($forms as $form_id) {
            $settings = get_post_meta((int) $form_id, '_cjw_wizard_settings', true);

            if (! is_array($settings)) {
                continue;
            }

            $subject = trim((string) ($settings['parent_confirmation_subject'] ?? ''));
            $body = trim((string) ($settings['parent_confirmation_body'] ?? ''));

            if ($subject === '' && $body === '') {
                continue;
            }

            return [
                'subject' => sanitize_text_field($subject),
                // The old field was a plain textarea, so its line breaks are the
                // only structure it has. wpautop turns them into the paragraphs
                // the HTML mail needs, instead of one run-on block.
                'body' => $body === '' ? '' : wp_kses_post(wpautop($body)),
            ];
        }

        return [];
    }

    /**
     * The birthday copy from the camp settings.
     *
     * @return array{subject: string, body: string}|array{}
     */
    private static function birthdayFromSettings(): array
    {
        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);

        if (! is_array($settings)) {
            return [];
        }

        $body = trim((string) ($settings['birthday_mail_text'] ?? ''));

        if ($body === '') {
            return [];
        }

        return [
            // The old field never had a subject; the default is a better
            // starting point than an empty line.
            'subject' => '',
            'body' => wp_kses_post($body),
        ];
    }
}
