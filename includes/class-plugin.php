<?php
/**
 * Plugin bootstrap / wiring.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Wires the add-on together once its dependency (Loupe Search) is present.
 */
class Plugin {

	private static ?Plugin $instance = null;

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! is_multisite() ) {
			add_action( 'admin_notices', [ $this, 'notice_requires_multisite' ] );
			return;
		}

		if ( ! $this->dependency_met() ) {
			add_action( 'network_admin_notices', [ $this, 'notice_requires_loupe_search' ] );
			return;
		}

		// Network-wide admin settings.
		if ( is_admin() ) {
			new Settings();
		}

		// Site lifecycle purge/enroll runs network-wide (context-free).
		new Site_Lifecycle();

		// Network settings REST endpoints (not is_admin(), so register unconditionally).
		add_action( 'rest_api_init', function (): void {
			( new Settings_REST() )->register_routes();
		} );

		// Register the example block on every site (it queries the hub cross-site).
		new Block();

		// Mirror this site's own content only when it participates.
		$blog_id = get_current_blog_id();
		if ( Participation::is_participating( $blog_id ) ) {
			$post_types = Settings::get_post_types();
			$index      = new Combined_Index( $post_types, Settings::get_language() );
			new Mirror( $index, $post_types, $blog_id );
		}

		// The search endpoint lives only on the hub site.
		if ( Settings::get_hub_blog_id() === $blog_id ) {
			add_action( 'rest_api_init', function (): void {
				( new REST_Controller() )->register_routes();
			} );
		}
	}

	/**
	 * Loupe Search must be loaded (its indexer + the bundled Loupe library).
	 */
	private function dependency_met(): bool {
		return class_exists( '\\Soderlind\\Plugin\\WPLoupe\\WP_Loupe_Indexer' )
			&& class_exists( '\\Loupe\\Loupe\\LoupeFactory' );
	}

	public function notice_requires_multisite(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Loupe Cross-Site Search requires a WordPress multisite network.', 'loupe-cross-site-search' )
			. '</p></div>';
	}

	public function notice_requires_loupe_search(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Loupe Cross-Site Search requires the Loupe Search plugin to be active.', 'loupe-cross-site-search' )
			. '</p></div>';
	}
}
