<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Config
{
    /**
     * Canonical schema reference:
     * docs/config-schema.md
     */

    /**
     * Get the configuration for a specific wizard.
     *
     * @param int|null $wizard_id The ID of the cjw_wizard post.
     * @return array<string, mixed>
     */
    public static function get(?int $wizard_id = null): array
    {
        if ($wizard_id) {
            $config_json = get_post_meta($wizard_id, '_cjw_wizard_config', true);
            $normalized_config = CJW_Wizard_Config_Normalizer::normalize(
                is_string($config_json) && $config_json !== ''
                    ? json_decode($config_json, true)
                    : null
            );

            $normalized_config['settings'] = cjw_localize_value(wp_parse_args(
                get_post_meta($wizard_id, '_cjw_wizard_settings', true),
                CJW_Wizard_Defaults::settings()
            ));

            return cjw_localize_value($normalized_config);
        }

        return cjw_localize_value([
            ...CJW_Wizard_Config_Normalizer::empty(),
            'settings' => CJW_Wizard_Defaults::settings(),
        ]);
    }
}
