<?php
/**
 * Network settings for cross-site search.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Stores and renders the network-level configuration: the hub site, which sites
 * participate, the combined-index language, and which post types are covered.
 */
class Settings {

	public const OPTION = 'loupe_cross_site_settings';

	public function __construct() {
		add_action( 'network_admin_menu', [ $this, 'add_menu' ] );
	}

	/**
	 * Merged settings with defaults.
	 *
	 * @return array{hub_blog_id:int,mode:string,sites:int[],language:string,post_types:string[]}
	 */
	public static function get(): array {
		$defaults = [
			'hub_blog_id' => get_main_site_id(),
			'mode'        => 'all',
			'sites'       => [],
			'language'    => 'en',
			'post_types'  => [ 'post', 'page' ],
		];
		$saved = get_site_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		$merged                = array_merge( $defaults, $saved );
		$merged['hub_blog_id'] = (int) $merged['hub_blog_id'];
		$merged['sites']       = array_values( array_map( 'intval', (array) $merged['sites'] ) );
		$merged['post_types']  = array_values( array_filter( array_map( 'sanitize_key', (array) $merged['post_types'] ) ) );
		$merged['mode']        = in_array( $merged['mode'], [ 'all', 'allowlist', 'blocklist' ], true ) ? $merged['mode'] : 'all';
		return $merged;
	}

	public static function get_hub_blog_id(): int {
		return self::get()['hub_blog_id'];
	}

	public static function get_language(): string {
		$lang = self::get()['language'];
		return preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : 'en';
	}

	/**
	 * @return string[]
	 */
	public static function get_post_types(): array {
		$types = self::get()['post_types'];
		return empty( $types ) ? [ 'post', 'page' ] : $types;
	}

	public static function get_mode(): string {
		return self::get()['mode'];
	}

	/**
	 * @return int[]
	 */
	public static function get_configured_sites(): array {
		return self::get()['sites'];
	}

	public function add_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'Cross-Site Search', 'loupe-cross-site-search' ),
			__( 'Cross-Site Search', 'loupe-cross-site-search' ),
			'manage_network_options',
			'loupe-cross-site-search',
			[ $this, 'render' ]
		);
	}

	/**
	 * Sanitize a raw settings payload into the stored shape.
	 *
	 * @param array<string,mixed> $input
	 * @return array{hub_blog_id:int,mode:string,sites:int[],language:string,post_types:string[]}
	 */
	public static function sanitize( array $input ): array {
		$mode = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : 'all';
		return [
			'hub_blog_id' => isset( $input['hub_blog_id'] ) ? (int) $input['hub_blog_id'] : get_main_site_id(),
			'mode'        => in_array( $mode, [ 'all', 'allowlist', 'blocklist' ], true ) ? $mode : 'all',
			'sites'       => isset( $input['sites'] ) ? array_values( array_unique( array_map( 'intval', (array) $input['sites'] ) ) ) : [],
			'language'    => isset( $input['language'] ) ? strtolower( substr( (string) preg_replace( '/[^a-z]/i', '', (string) $input['language'] ), 0, 2 ) ) : 'en',
			'post_types'  => isset( $input['post_types'] ) ? array_values( array_filter( array_map( 'sanitize_key', (array) $input['post_types'] ) ) ) : [ 'post', 'page' ],
		];
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$this->enqueue_assets();
		echo '<div class="wrap lcss-settings"><div id="lcss-settings-root"></div>'
			. '<noscript><p>' . esc_html__( 'The Cross-Site Search settings screen requires JavaScript.', 'loupe-cross-site-search' ) . '</p></noscript></div>';
	}

	private function enqueue_assets(): void {
		$base  = LCSS_PATH . 'admin/settings';
		$asset = file_exists( $base . '.asset.php' )
			? require $base . '.asset.php'
			: [ 'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ], 'version' => LCSS_VERSION ];

		wp_enqueue_script( 'lcss-settings', LCSS_URL . 'admin/settings.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'lcss-settings', LCSS_URL . 'admin/settings.css', [ 'wp-components' ], $asset['version'] );
		wp_set_script_translations( 'lcss-settings', 'loupe-cross-site-search' );

		wp_add_inline_script(
			'lcss-settings',
			'window.lcssSettings = ' . wp_json_encode( [
				'root'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			] ) . ';',
			'before'
		);
	}
}
