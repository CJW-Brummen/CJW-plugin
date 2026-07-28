<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Submission_Notifier
{
    private CJW_Wizard_Submission_Repository $repository;

    public function __construct(CJW_Wizard_Submission_Repository $repository)
    {
        $this->repository = $repository;
    }

    public function maybe_notify_admin(int $wizard_id, array $config, array $data): void
    {
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

        wp_mail($to, $subject, $this->build_email_body($wizard_id, $data));
    }

    private function build_email_body(int $wizard_id, array $data): string
    {
        $lines = [
            sprintf(
                /* translators: %s: form title. */
                __('Nieuwe inzending voor: %s', 'cjw'),
                get_the_title($wizard_id)
            ),
            '',
            __('Ingevulde gegevens:', 'cjw'),
        ];

        $flat_data = $this->repository->flatten_data($data);

        if ($flat_data === []) {
            $lines[] = __('Geen velden ontvangen.', 'cjw');
        } else {
            foreach ($flat_data as $key => $value) {
                $lines[] = sprintf('%s: %s', $key, $value);
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            /* translators: %s: admin submissions list URL. */
            __('Bekijk alle inzendingen: %s', 'cjw'),
            admin_url('edit.php?post_type=' . CJW_Wizard_Submission_Repository::POST_TYPE)
        );

        return implode(PHP_EOL, $lines);
    }
}
