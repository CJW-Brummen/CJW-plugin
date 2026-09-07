<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Settings
{
    public const string META_KEY = '_cjw_wizard_settings';

    /**
     * The settings saved on one form, merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public function get(int $post_id): array
    {
        return wp_parse_args(
            get_post_meta($post_id, self::META_KEY, true),
            CJW_Wizard_Defaults::settings()
        );
    }

    /**
     * Cleans submitted settings before they are stored.
     *
     * @return array<string, mixed>
     */
    public function sanitize(mixed $settings): array
    {
        if (! is_array($settings)) {
            $settings = [];
        }

        $defaults = CJW_Wizard_Defaults::settings();

        return [
            'submit_label' => $this->sanitize_required_text($settings['submit_label'] ?? null, $defaults['submit_label']),
            'complete_label' => $this->sanitize_required_text($settings['complete_label'] ?? null, $defaults['complete_label']),
            'previous_label' => $this->sanitize_required_text($settings['previous_label'] ?? null, $defaults['previous_label']),
            'show_previous_button' => ! empty($settings['show_previous_button']),
            'success_message' => $this->sanitize_required_textarea($settings['success_message'] ?? null, $defaults['success_message']),
            'use_summer_camp_settings' => ! empty($settings['use_summer_camp_settings']),
            'require_privacy_consent' => ! empty($settings['require_privacy_consent']),
            'require_ideal_payment' => ! empty($settings['require_ideal_payment']),
            'show_summer_camp_intro' => ! empty($settings['show_summer_camp_intro']),
            'send_admin_notification' => ! empty($settings['send_admin_notification']),
            'notification_email' => $this->sanitize_notification_email($settings['notification_email'] ?? null),
            'enable_csv_export' => ! empty($settings['enable_csv_export']),
            'send_parent_confirmation' => ! empty($settings['send_parent_confirmation']),
        ];
    }

    private function sanitize_required_text(mixed $value, string $default): string
    {
        $sanitized = sanitize_text_field(is_scalar($value) ? (string) $value : '');

        return $sanitized !== '' ? $sanitized : $default;
    }

    private function sanitize_required_textarea(mixed $value, string $default): string
    {
        $sanitized = sanitize_textarea_field(is_scalar($value) ? (string) $value : '');

        return $sanitized !== '' ? $sanitized : $default;
    }

    private function sanitize_notification_email(mixed $value): string
    {
        $email = sanitize_email(is_scalar($value) ? (string) $value : '');

        if ($email === '' || ! is_email($email)) {
            return '';
        }

        return $email;
    }
}
