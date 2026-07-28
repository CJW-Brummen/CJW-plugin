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

        add_action('init', [self::class, 'loadTextdomain']);

        CJW_Summer_Camp_Module::boot();
        CJW_Form_Builder_Module::boot();
    }

    /**
     * Registers the single text domain for the whole plugin.
     *
     * @return void
     */
    public static function loadTextdomain(): void
    {
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
        require_once CJW_DIR . 'inc/shared/localization.php';
        require_once CJW_DIR . 'inc/shared/class-summer-camp-service.php';
        require_once CJW_DIR . 'inc/shared/functions.php';

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
