<?php

/**
 * Top level plugin bootstrap.
 *
 * @package CJW
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loads the shared services and boots every feature module.
 *
 * The plugin is organised as modules under inc/: `shared` holds the domain
 * services both features rely on, `summer-camp` owns the camp settings and
 * `form-builder` owns the registration wizard. Modules may use `shared`; they
 * never reach into each other except through the documented integration in
 * inc/form-builder/integrations/.
 */
final class CJW_Plugin
{
    /**
     * Boots the plugin once.
     *
     * @return void
     */
    public static function boot(): void
    {
        static $booted = false;

        if ($booted) {
            return;
        }

        $booted = true;

        self::loadShared();
        self::loadActivator();

        // On every request, not just in the admin: the capabilities they guard
        // are checked by the REST API and by WP-CLI too, and a guard that only
        // runs in wp-admin is a guard with a way around it.
        CJW_Roles::registerGuards();

        // Every button on the site asks where the form lives; the answer is
        // cached and has to be dropped when a page changes, wherever that
        // happens -- the editor, the REST API, WP-CLI.
        CJW_Registration_Page::init();

        // On every request as well: the birthday job and the confirmations
        // send from cron and from the public form, not from wp-admin.
        CJW_Mail_Safe_Mode::init();

        // Both belong outside wp-admin: the crash to report is the one on a
        // page a parent was looking at, and the monitor polling the status
        // route has no session at all.
        CJW_Fatal_Error_Watch::init();
        CJW_Status_Endpoint::init();

        // Not only on activation: an activation hook does not run when a
        // plugin is updated, so a capability change would never reach a site
        // that already had the plugin installed. See CJW_Roles::VERSION.
        add_action('init', [CJW_Roles::class, 'installIfOutdated'], 0);

        add_action('init', [self::class, 'loadTextdomain']);

        if (is_admin()) {
            (new CJW_Roles_Notice())->init();
            CJW_Admin_Menu::init();

            // Late enough that every module has registered the jobs it wants
            // watched, and on a screen a human is actually looking at. A dead
            // cron cannot report itself, so somebody logging in is the one
            // moment the site is certain to be running code.
            add_action('admin_init', [CJW_Cron_Heartbeat::class, 'reportStaleScheduled']);
        }

        CJW_Summer_Camp_Module::boot();
        CJW_Form_Builder_Module::boot();
    }

    /**
     * Registers the single text domain for the whole plugin.
     *
     * Plugin Check discourages this call because WordPress.org-hosted plugins
     * get their translations loaded automatically. This plugin is not on
     * WordPress.org and ships its own `languages/` directory, and
     * WP_Textdomain_Registry only learns about a bundled language directory
     * through load_plugin_textdomain(). Without this call, just-in-time loading
     * would look in wp-content/languages/plugins/ only and never find the
     * translations shipped inside the plugin.
     *
     * @return void
     */
    public static function loadTextdomain(): void
    {
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Self-hosted plugin with bundled translations; see the docblock above.
        load_plugin_textdomain('cjw', false, dirname(plugin_basename(CJW_FILE)) . '/languages');
    }

    /**
     * Loads the shared domain services used by every module.
     *
     * @return void
     */
    private static function loadShared(): void
    {
        require_once CJW_DIR . 'inc/shared/class-camp-type.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-roles.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-roles-notice.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-admin-menu.php';
        require_once CJW_DIR . 'inc/shared/localization.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-privacy-notice.php';
        require_once CJW_DIR . 'inc/shared/class-summer-camp-service.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-registration-page.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-mail-safe-mode.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-fatal-error-watch.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-status-endpoint.php';
        require_once CJW_DIR . 'inc/shared/functions.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-mail-templates.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-mail-tokens.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-mailer.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-alerts.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-cron-heartbeat.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-mail-migration.php';

        require_once CJW_DIR . 'inc/summer-camp/class-cjw-summer-camp-module.php';
        require_once CJW_DIR . 'inc/form-builder/class-cjw-form-builder-module.php';
    }

    /**
     * Loads the activation routine.
     *
     * Required at boot as well as on activation, because WordPress loads the
     * plugin file before firing the activation hook.
     *
     * @return void
     */
    private static function loadActivator(): void
    {
        require_once CJW_DIR . 'inc/class-cjw-activator.php';
    }
}
