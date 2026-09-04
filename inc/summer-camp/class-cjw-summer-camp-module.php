<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates loading and WordPress hooks for the summer camp module.
 */
final class CJW_Summer_Camp_Module
{
    /**
     * Boots the module once.
     *
     * @return void
     */
    public static function boot(): void
    {
        static $instance = null;

        if ($instance instanceof self) {
            return;
        }

        $instance = new self();
        $instance->init();
    }

    /**
     * Loads plugin dependencies.
     */
    private function __construct()
    {
        $this->loadDependencies();
    }

    /**
     * Registers WordPress hooks.
     *
     * @return void
     */
    private function init(): void
    {
        add_action('init', [$this, 'initAdmin']);

        // Before anything can read stale settings back: the shared service
        // caches the option, and a save during the request has to invalidate it.
        CJW_Summer_Camp_Service::watchSettings();

        new CJW_Summer_Camp_Admin_Assets()->init();
        new CJW_Summer_Camp_Frontend_Theme_Styles()->init();

        // Must run unconditionally at boot: the sponsor admin attaches a
        // register_post_type_args filter that has to be in place before the
        // sponsor post type registers (also on the front end and REST).
        CJW_Summer_Camp_Sponsor_Post_Type::init();
        CJW_Summer_Camp_Sponsor_Admin::init();
        CJW_Summer_Camp_Rental_Post_Type::init();
        CJW_Summer_Camp_Rental_Admin::init();
        CJW_Summer_Camp_Dashboard_Widget::init();
        // Not admin-only: the status endpoint answers a monitor on the front
        // end, and these are half of what it reports.
        CJW_Summer_Camp_Status_Checks::init();
        CJW_Summer_Camp_Mail::init();
        CJW_Summer_Camp_Settings_History::init();
        CJW_Summer_Camp_Year::init();
        CJW_Summer_Camp_Digest::init();
        CJW_Summer_Camp_Manual::init();
        CJW_Summer_Camp_Mails_Page::init();
        CJW_Summer_Camp_Site_Health::init();
        CJW_Summer_Camp_First_Run::init();
        CJW_Summer_Camp_Help_Tabs::init();
    }

    /**
     * Initializes admin-only components.
     *
     * @return void
     */
    public function initAdmin(): void
    {
        if (!is_admin()) {
            return;
        }

        new CJW_Summer_Camp_Settings_Page()->init();
    }

    /**
     * Loads the class files used by the module.
     *
     * @return void
     */
    private function loadDependencies(): void
    {
        require_once CJW_DIR . 'inc/summer-camp/cjw-summer-camp-settings-page.php';
        require_once CJW_DIR . 'inc/summer-camp/class-cjw-summer-camp-admin-assets.php';
        require_once CJW_DIR . 'inc/summer-camp/class-cjw-summer-camp-frontend-theme-styles.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-dashboard-widget.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-mail.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-settings-history.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-year.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-digest.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-manual.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-mails-page.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-site-health.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-photo-consent-notice.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-first-run.php';
        require_once CJW_DIR . 'inc/summer-camp/class-cjw-summer-camp-status-checks.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-help-tabs.php';
        require_once CJW_DIR . 'inc/summer-camp/class-cjw-summer-camp-sponsor-post-type.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-sponsor-admin.php';
        require_once CJW_DIR . 'inc/summer-camp/class-cjw-summer-camp-rental-post-type.php';
        require_once CJW_DIR . 'inc/summer-camp/admin/class-cjw-summer-camp-rental-admin.php';
    }
}
