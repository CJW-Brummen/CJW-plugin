<?php

/**
 * Constants and shared globals PHPStan needs before analysing the plugin.
 *
 * @package CJW
 */

declare(strict_types=1);

defined('ABSPATH') || define('ABSPATH', dirname(__DIR__, 3) . '/');
defined('WP_PLUGIN_DIR') || define('WP_PLUGIN_DIR', dirname(__DIR__));
defined('CJW_VERSION') || define('CJW_VERSION', '2.0.0');
defined('CJW_FILE') || define('CJW_FILE', __DIR__ . '/cjw.php');
defined('CJW_DIR') || define('CJW_DIR', __DIR__ . '/');
defined('CJW_URL') || define('CJW_URL', 'https://example.test/wp-content/plugins/cjw/');

require_once __DIR__ . '/inc/shared/class-camp-type.php';
require_once __DIR__ . '/inc/shared/localization.php';
require_once __DIR__ . '/inc/shared/class-summer-camp-service.php';
require_once __DIR__ . '/inc/shared/functions.php';
