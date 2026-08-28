<?php

/**
 * One-time setup on plugin activation.
 *
 * @package CJW
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Seeds the settings option and fills in any keys added since the last version.
 *
 * Replaces the identical activation routines that CJW Common and CJW Zomerkamp
 * instellingen each registered before those plugins were merged. The routine is
 * idempotent: it may run on every activation without losing stored settings.
 */
final class CJW_Activator
{
    /**
     * Creates the settings option, or tops up an existing one with new defaults.
     *
     * @return void
     */
    public static function activate(): void
    {
        CJW_Roles::install();
        CJW_Wizard_Submission_Retention::schedule();

        // Only when somebody switched it on: activation is not consent to
        // start mailing people.
        if (CJW_Summer_Camp_Digest::isEnabled()) {
            CJW_Summer_Camp_Digest::schedule();
        }

        $existing = get_option(CJW_Summer_Camp_Service::OPTION_KEY, null);

        if (!is_array($existing)) {
            add_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::defaults(true));

            return;
        }

        $defaults = CJW_Summer_Camp_Service::defaults();

        if (empty($existing['late_registration_token'])) {
            $defaults['late_registration_token'] = CJW_Summer_Camp_Service::generateLateRegistrationToken();
        }

        update_option(
            CJW_Summer_Camp_Service::OPTION_KEY,
            wp_parse_args($existing, $defaults)
        );
    }
}
