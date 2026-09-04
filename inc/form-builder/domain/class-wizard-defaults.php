<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Defaults
{
    /**
     * The settings a form falls back to when it has none of its own.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return [
            'submit_label' => __('Volgende', 'cjw'),
            'complete_label' => __('Afronden', 'cjw'),
            'previous_label' => __('Vorige', 'cjw'),
            'show_previous_button' => true,
            'success_message' => __('Bedankt! Je formulier is succesvol afgerond.', 'cjw'),
            'use_summer_camp_settings' => false,
            // On by default: the form asks for a child's medical notes, and a
            // form that says nothing about that is the state this replaces.
            'require_privacy_consent' => true,
            'show_summer_camp_intro' => true,
            'send_admin_notification' => false,
            'notification_email' => '',
            'enable_csv_export' => false,
            // Off by default on purpose: see CJW_Wizard_Submission_Confirmation.
            'send_parent_confirmation' => false,
        ];
    }
}
