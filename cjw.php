<?php

/**
 * CJW Zomerkampen.
 *
 * @wordpress-plugin
 * Plugin Name:       CJW Zomerkampen
 * Plugin URI:        https://cjw-brummen.nl/
 * Description:       Zomerkampinstellingen, formulierbouwer en gedeelde services voor CJW Brummen.
 * Version:           2.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.5
 * Author:            Imre Boersma
 * Author URI:        https://imreboersma.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cjw
 * Domain Path:       /languages
 *
 * @package CJW
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CJW_VERSION')) {
    define('CJW_VERSION', '2.0.0');
}

if (!defined('CJW_FILE')) {
    define('CJW_FILE', __FILE__);
}

if (!defined('CJW_DIR')) {
    define('CJW_DIR', plugin_dir_path(__FILE__));
}

if (!defined('CJW_URL')) {
    define('CJW_URL', plugin_dir_url(__FILE__));
}

require_once CJW_DIR . 'inc/class-cjw-plugin.php';

CJW_Plugin::boot();

register_activation_hook(CJW_FILE, [CJW_Activator::class, 'activate']);
