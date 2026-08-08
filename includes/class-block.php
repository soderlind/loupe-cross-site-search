<?php
/**
 * Example cross-site search block (dynamic). Renders a container that the view
 * script hydrates; the container carries the hub search endpoint URL.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Block {

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		$dir = LCSS_PATH . 'build/cross-site-search';
		if ( ! file_exists( $dir . '/block.json' ) ) {
			return;
		}
		$block_type = register_block_type( $dir, [ 'render_callback' => [ $this, 'render' ] ] );

		// Load JS translations from the bundled languages/ directory.
		if ( $block_type instanceof \WP_Block_Type ) {
			foreach ( (array) $block_type->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'loupe-cross-site-search', LCSS_PATH . 'languages' );
			}
		}
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public function render( array $attributes ): string {
		$hub      = Settings::get_hub_blog_id();
		$config   = [
			'endpoint'       => get_rest_url( $hub, 'loupe-cross-site/v1/search' ),
			'heading'        => isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '',
			'placeholder'    => isset( $attributes['placeholder'] ) ? (string) $attributes['placeholder'] : __( 'Search…', 'loupe-cross-site-search' ),
			'perPage'        => isset( $attributes['perPage'] ) ? max( 1, min( 100, (int) $attributes['perPage'] ) ) : 10,
			'showSiteFilter' => ! isset( $attributes['showSiteFilter'] ) || (bool) $attributes['showSiteFilter'],
			'showTypeFilter' => ! isset( $attributes['showTypeFilter'] ) || (bool) $attributes['showTypeFilter'],
			'showSort'       => ! isset( $attributes['showSort'] ) || (bool) $attributes['showSort'],
			'defaultSort'    => isset( $attributes['defaultSort'] ) ? (string) $attributes['defaultSort'] : 'relevance',
			'showExcerpt'    => ! isset( $attributes['showExcerpt'] ) || (bool) $attributes['showExcerpt'],
			'showDate'       => ! isset( $attributes['showDate'] ) || (bool) $attributes['showDate'],
			'highlight'      => ! isset( $attributes['highlight'] ) || (bool) $attributes['highlight'],
			'i18n'           => [
				'sites'      => __( 'Sites', 'loupe-cross-site-search' ),
				'types'      => __( 'Types', 'loupe-cross-site-search' ),
				'sortBy'     => __( 'Sort by', 'loupe-cross-site-search' ),
				'relevance'  => __( 'Relevance', 'loupe-cross-site-search' ),
				'newest'     => __( 'Newest', 'loupe-cross-site-search' ),
				'oldest'     => __( 'Oldest', 'loupe-cross-site-search' ),
				'title'      => __( 'Title', 'loupe-cross-site-search' ),
				'searching'  => __( 'Searching…', 'loupe-cross-site-search' ),
				/* translators: %s: search query */
				'noResults'  => __( 'No results for “%s”.', 'loupe-cross-site-search' ),
				/* translators: 1: result count, 2: search query, 3: time in milliseconds */
				'results'    => __( '%1$d results for “%2$s” in %3$d ms', 'loupe-cross-site-search' ),
				/* translators: 1: search query, 2: time in milliseconds */
				'oneResult'  => __( '1 result for “%1$s” in %2$d ms', 'loupe-cross-site-search' ),
				'failed'     => __( 'Search failed. Please try again.', 'loupe-cross-site-search' ),
				'clear'      => __( 'Clear search', 'loupe-cross-site-search' ),
				'clearAll'   => __( 'Clear filters', 'loupe-cross-site-search' ),
				'prev'       => __( 'Previous', 'loupe-cross-site-search' ),
				'next'       => __( 'Next', 'loupe-cross-site-search' ),
			],
		];

		$wrapper = get_block_wrapper_attributes( [ 'class' => 'lcss-search' ] );

		return sprintf(
			'<div %1$s data-config="%2$s"></div>',
			$wrapper,
			esc_attr( wp_json_encode( $config ) )
		);
	}
}
