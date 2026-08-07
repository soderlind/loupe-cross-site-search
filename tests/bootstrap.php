<?php
/**
 * PHPUnit / Pest bootstrap. Loads the plugin classes under test against WordPress
 * function mocks provided by Brain Monkey. No real WordPress is loaded.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content' );
}
if ( ! defined( 'LCSS_PATH' ) ) {
	define( 'LCSS_PATH', dirname( __DIR__ ) . '/' );
}

// Minimal WordPress class stubs used via instanceof / type hints.
require_once __DIR__ . '/stubs.php';

// Plugin classes under test. class-cli.php and class-block.php depend on runtime
// context (WP_CLI / init) and are intentionally not loaded here.
$includes = dirname( __DIR__ ) . '/includes/';
require_once $includes . 'class-participation.php';
require_once $includes . 'class-settings.php';
require_once $includes . 'class-combined-index.php';
require_once $includes . 'class-document-builder.php';
require_once $includes . 'class-rest-controller.php';
