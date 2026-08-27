<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tells an organiser that a registration arrived.
 *
 * Takes no repository. It used to, back when the mail listed every submitted
 * field; phase 3 reduced the body to "one arrived, here is where to read it",
 * which left the dependency injected and never read.
 */
final class CJW_Wizard_Submission_Notifier
{
    /**
     * Tells the organiser a registration arrived, if the form asks for it.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     */
    public function maybe_notify_admin(int $wizard_id, array $config, array $data): void
    {
        // $data is intentionally not used: see build_email_body().
        unset($data);

        $settings = $config['settings'] ?? [];

        if (empty($settings['send_admin_notification'])) {
            return;
        }

        $to = sanitize_email((string) ($settings['notification_email'] ?? ''));
        if ($to === '') {
            $to = (string) get_option('admin_email');
        }

        if (! is_email($to)) {
            return;
        }

        $subject = sprintf(
            /* translators: %s: form title. */
            __('Nieuwe inzending voor %s', 'cjw'),
            get_the_title($wizard_id)
        );

        wp_mail($to, $subject, $this->build_email_body($wizard_id));
    }

    /**
     * Builds the notification body.
     *
     * Deliberately carries no submitted data. This mail used to list every
     * answered field as plaintext -- names, birth dates, addresses and any
     * medical or allergy note a camp form asks for -- to whatever inbox was
     * configured. That copies children's personal data out of the one place it
     * is governed and into mailboxes, phone notifications, forwarded threads and
     * mail backups, none of which the retention sweep or an erasure request can
     * reach. It also travelled over whatever unauthenticated mail the host
     * happens to use.
     *
     * The notification now says only that a registration arrived, and where to
     * read it.
     *
     * @param int $wizard_id Form post id.
     */
    private function build_email_body(int $wizard_id): string
    {
        return implode(PHP_EOL, [
            sprintf(
                /* translators: %s: form title. */
                __('Er is een nieuwe inschrijving binnengekomen voor: %s', 'cjw'),
                get_the_title($wizard_id)
            ),
            '',
            __('De gegevens staan in de beveiligde omgeving en worden bewust niet meegestuurd in deze e-mail.', 'cjw'),
            '',
            sprintf(
                /* translators: %s: admin submissions list URL. */
                __('Bekijk de inschrijving: %s', 'cjw'),
                admin_url('edit.php?post_type=' . CJW_Wizard_Submission_Repository::POST_TYPE)
            ),
        ]);
    }
}
