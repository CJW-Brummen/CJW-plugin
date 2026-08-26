<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and enqueues admin assets for the settings page.
 */
final class CJW_Summer_Camp_Admin_Assets
{
    /**
     * Registers admin enqueue hook.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Enqueues assets only on this plugin's settings page.
     *
     * @param string $hook Current admin page hook.
     *
     * @return void
     */
    public function enqueue(string $hook): void
    {
        if ($hook !== CJW_Summer_Camp_Settings_Page::ADMIN_HOOK) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_script(
            'flatpickr',
            CJW_URL . 'assets/summer-camp/lib/flatpickr.min.js',
            [],
            '4.6.13',
            true
        );

        wp_enqueue_style(
            'flatpickr',
            CJW_URL . 'assets/summer-camp/lib/flatpickr.min.css',
            [],
            '4.6.13'
        );

        wp_enqueue_script(
            'cjw-summer-camp-admin',
            CJW_URL . 'assets/summer-camp/js/admin.js',
            ['flatpickr'],
            CJW_VERSION,
            true
        );

        wp_enqueue_style(
            'cjw-summer-camp-admin',
            CJW_URL . 'assets/summer-camp/css/admin.css',
            [],
            CJW_VERSION
        );
    }
}
