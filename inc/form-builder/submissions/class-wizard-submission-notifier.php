<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tells an organiser that a registration arrived.
 *
 * Carries no submitted data, and the template it uses is not offered any. This
 * mail used to list every answered field as plaintext -- names, birth dates,
 * addresses and any medical or allergy note a camp form asks for -- to whatever
 * inbox was configured. That copies children's personal data out of the one
 * place it is governed and into mailboxes, phone notifications, forwarded
 * threads and mail backups, none of which the retention sweep or an erasure
 * request can reach.
 *
 * The rule is now enforced rather than remembered: the template's token list has
 * no entry for a name or an address, and CJW_Mailer strips any token a template
 * was not given. Somebody pasting {child_names} into this mail gets an empty
 * space, not a child's name.
 */
final class CJW_Wizard_Submission_Notifier
{
    /**
     * Tells the organiser a registration arrived, if the form asks for it.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     */
    public function maybe_notify_admin(int $wizard_id, array $config, array $data, int $submission_id = 0): void
    {
        // $data is intentionally not used: this mail carries no answers.
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

        CJW_Mailer::send(CJW_Mail_Templates::NOTIFICATION, $to, [
            '{form_title}' => (string) get_the_title($wizard_id),
            '{registration_number}' => $submission_id > 0 ? (string) $submission_id : '',
            '{submissions_url}' => admin_url('edit.php?post_type=' . CJW_Wizard_Submission_Repository::POST_TYPE),
            ...CJW_Mail_Tokens::camp(),
        ]);
    }
}
