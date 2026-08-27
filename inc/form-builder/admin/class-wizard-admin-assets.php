<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Admin_Assets
{
    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        global $post;

        if (($hook !== 'post-new.php' && $hook !== 'post.php') || !$post || $post->post_type !== 'cjw_wizard') {
            return;
        }

        wp_enqueue_script(
            'cjw-wizard-builder-utils',
            CJW_URL . 'assets/form-builder/admin/js/builder-utils.js',
            [],
            CJW_VERSION,
            true
        );

        wp_enqueue_script(
            'cjw-wizard-builder-state',
            CJW_URL . 'assets/form-builder/admin/js/builder-state.js',
            ['cjw-wizard-builder-utils'],
            CJW_VERSION,
            true
        );

        wp_enqueue_script(
            'cjw-wizard-builder-editors',
            CJW_URL . 'assets/form-builder/admin/js/builder-editors.js',
            ['cjw-wizard-builder-utils'],
            CJW_VERSION,
            true
        );

        wp_enqueue_script(
            'cjw-wizard-builder-dnd',
            CJW_URL . 'assets/form-builder/admin/js/builder-dnd.js',
            ['cjw-wizard-builder-state'],
            CJW_VERSION,
            true
        );

        wp_enqueue_script(
            'cjw-wizard-builder-renderers',
            CJW_URL . 'assets/form-builder/admin/js/builder-renderers.js',
            [
                'cjw-wizard-builder-utils',
                'cjw-wizard-builder-state',
                'cjw-wizard-builder-editors',
                'cjw-wizard-builder-dnd',
            ],
            CJW_VERSION,
            true
        );

        wp_enqueue_script(
            'cjw-wizard-builder-js',
            CJW_URL . 'assets/form-builder/admin/js/builder.js',
            [
                'cjw-wizard-builder-utils',
                'cjw-wizard-builder-state',
                'cjw-wizard-builder-editors',
                'cjw-wizard-builder-dnd',
                'cjw-wizard-builder-renderers',
            ],
            CJW_VERSION,
            true
        );

        wp_enqueue_style(
            'cjw-wizard-builder-css',
            CJW_URL . 'assets/form-builder/admin/css/builder.css',
            [],
            CJW_VERSION
        );

        wp_add_inline_script(
            'cjw-wizard-builder-js',
            <<<'JS'
document.addEventListener("DOMContentLoaded", () => {
	const notificationToggle = document.getElementById("cjw-wizard-setting-send_admin_notification");
	const notificationWrapper = document.getElementById("cjw-wizard-notification-email-wrapper");
	const summerCampToggle = document.getElementById("cjw-wizard-setting-use_summer_camp_settings");
	const summerCampWrapper = document.getElementById("cjw-wizard-summer-camp-intro-wrapper");

	if (notificationToggle && notificationWrapper) {
		const syncNotificationWrapper = () => {
			notificationWrapper.style.display = notificationToggle.checked ? "" : "none";
		};

		notificationToggle.addEventListener("change", syncNotificationWrapper);
		syncNotificationWrapper();
	}

	if (summerCampToggle && summerCampWrapper) {
		const syncSummerCampWrapper = () => {
			summerCampWrapper.style.display = summerCampToggle.checked ? "" : "none";
		};

		summerCampToggle.addEventListener("change", syncSummerCampWrapper);
		syncSummerCampWrapper();
	}

	const confirmationToggle = document.getElementById("cjw-wizard-setting-send_parent_confirmation");
	const confirmationWrapper = document.getElementById("cjw-wizard-parent-confirmation-wrapper");

	if (confirmationToggle && confirmationWrapper) {
		const syncConfirmationWrapper = () => {
			confirmationWrapper.style.display = confirmationToggle.checked ? "" : "none";
		};

		confirmationToggle.addEventListener("change", syncConfirmationWrapper);
		syncConfirmationWrapper();
	}
});
JS
        );
    }
}
