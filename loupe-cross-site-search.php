<?php
/**
 * Loupe Cross-Site Search
 *
 * @package   Soderlind\Plugin\LoupeCrossSiteSearch
 * @author    Per Soderlind
 * @license   GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Loupe Cross-Site Search
 * Plugin URI:        https://github.com/soderlind/loupe-cross-site-search
 * Description:       Cross-site search for WordPress multisite. Maintains one combined Loupe index across participating sites and exposes a search endpoint on a designated hub site. Add-on to Loupe Search.
 * Version:           0.2.0
 * Author:            Per Soderlind
 * Author URI:        https://soderlind.no
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       loupe-cross-site-search
 * Network:           true
 * Requires at least: 6.9
 * Requires PHP:      8.3
 * Requires Plugins:  loupe-search
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'LCSS_FILE', __FILE__ );
define( 'LCSS_VERSION', '0.2.0' );
define( 'LCSS_PATH', plugin_dir_path( __FILE__ ) );
define( 'LCSS_URL', plugin_dir_url( __FILE__ ) );

require_once LCSS_PATH . 'includes/class-participation.php';
require_once LCSS_PATH . 'includes/class-settings.php';
require_once LCSS_PATH . 'includes/class-combined-index.php';
require_once LCSS_PATH . 'includes/class-document-builder.php';
require_once LCSS_PATH . 'includes/class-mirror.php';
require_once LCSS_PATH . 'includes/class-site-lifecycle.php';
require_once LCSS_PATH . 'includes/class-rest-controller.php';
require_once LCSS_PATH . 'includes/class-block.php';
require_once LCSS_PATH . 'includes/class-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LCSS_PATH . 'includes/class-cli.php';
}

/**
 * Boot the add-on after Loupe Search (which loads on plugins_loaded at the default priority).
 */
function init(): void {
	Plugin::get_instance();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init', 20 );
