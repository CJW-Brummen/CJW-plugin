<?php

/**
 * CJW Zomerkampen.
 *
 * @wordpress-plugin
 * Plugin Name:       CJW Zomerkampen
 * Plugin URI:        https://cjw-brummen.nl/
 * Description:       Zomerkampinstellingen, formulierbouwer en gedeelde services voor CJW Brummen.
 * Version:           2.2.0
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
    define('CJW_VERSION', '2.2.0');
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

// Leaving a scheduled event behind after deactivation means WordPress keeps
// firing a hook nothing listens to until someone clears it by hand.
register_deactivation_hook(CJW_FILE, [CJW_Wizard_Submission_Retention::class, 'unschedule']);
register_deactivation_hook(CJW_FILE, [CJW_Summer_Camp_Digest::class, 'unschedule']);
register_deactivation_hook(CJW_FILE, [CJW_Wizard_Birthday_Mailer::class, 'unschedule']);

// Editor, Auteur and Bijdrager are removed on activation because Leiding
// replaces all three. Putting them back here means the plugin hands the site
// back the way it found it, rather than leaving an administrator with three
// roles missing and no way to recreate them from wp-admin.
register_deactivation_hook(CJW_FILE, [CJW_Roles::class, 'restoreStockRoles']);
