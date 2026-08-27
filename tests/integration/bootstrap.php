<?php

/**
 * Boots real WordPress, with this plugin loaded, for the integration suite.
 *
 * The fast suite in tests/bootstrap.php runs against a hand-written stand-in
 * for WordPress. That stand-in is the right tool for pure domain logic -- the
 * normalizer, the conditions engine, the validator -- and the wrong tool for
 * anything that asks WordPress a question, because it can only ever answer the
 * way its author expected. It has now hidden four real bugs: a post type name
 * one character too long, a capability map that denied every screen to every
 * user, a Quick Edit action removed under a key WordPress does not use, and a
 * checked() shim that returned where WordPress echoes.
 *
 * Everything here runs against the real thing instead.
 */

declare(strict_types=1);

$cjw_tests_dir = __DIR__;
$cjw_root = dirname(__DIR__, 2);

require_once $cjw_root . '/vendor/autoload.php';

// The WP suite refuses to start without the polyfills, and looks for them in
// WordPress's own vendor directory unless told otherwise.
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $cjw_root . '/vendor/yoast/phpunit-polyfills');

// A constant, not an environment variable: the suite reads it with defined().
define('WP_TESTS_CONFIG_FILE_PATH', $cjw_tests_dir . '/wp-tests-config.php');

$cjw_wp_suite = $cjw_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $cjw_wp_suite . '/includes/functions.php';

// Loaded as a must-use plugin would be: before the test suite installs
// WordPress, so activation-time hooks and register_post_type() run for real.
tests_add_filter('muplugins_loaded', static function () use ($cjw_root): void {
    require $cjw_root . '/cjw.php';
});

// Activation, not just loading. The capability that gates every camp screen
// is granted by the activation hook, so a plugin that is merely present on
// disk is not the thing these tests mean to describe.
tests_add_filter('init', static function (): void {
    if (! get_role(CJW_Roles::ROLE) instanceof WP_Role) {
        CJW_Activator::activate();
    }
}, 0);

require $cjw_wp_suite . '/includes/bootstrap.php';

require_once __DIR__ . '/support/IntegrationTestCase.php';
