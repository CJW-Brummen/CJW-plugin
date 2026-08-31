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

        // Not only on activation: an activation hook does not run when a
        // plugin is updated, so a capability change would never reach a site
        // that already had the plugin installed. See CJW_Roles::VERSION.
        add_action('init', [CJW_Roles::class, 'installIfOutdated'], 0);

        add_action('init', [self::class, 'loadTextdomain']);

        // Outside the is_admin() check below: it closes comments, and the
        // comment form renders on the front end.
        CJW_Admin_Menu::init();

        if (is_admin()) {
            (new CJW_Roles_Notice())->init();
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
        require_once CJW_DIR . 'inc/shared/class-summer-camp-service.php';
        require_once CJW_DIR . 'inc/shared/functions.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-mail-templates.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-mail-tokens.php';
        require_once CJW_DIR . 'inc/shared/class-cjw-mailer.php';
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
