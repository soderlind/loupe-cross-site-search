<?php
/**
 * Site lifecycle — purge a site's documents when it leaves the network or goes
 * non-public. Runs network-wide and context-free; the combined index lives at a
 * network-global path, so no switch_to_blog is needed.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Site_Lifecycle {

	public function __construct() {
		add_action( 'wp_delete_site', [ $this, 'on_delete_site' ], 10, 1 );
		add_action( 'archive_blog', [ $this, 'purge_blog' ], 10, 1 );
		add_action( 'make_spam_blog', [ $this, 'purge_blog' ], 10, 1 );
		add_action( 'make_delete_blog', [ $this, 'purge_blog' ], 10, 1 );
		add_action( 'update_blog_public', [ $this, 'on_public_change' ], 10, 2 );
	}

	/**
	 * @param \WP_Site $old_site The site being deleted.
	 */
	public function on_delete_site( $old_site ): void {
		if ( $old_site instanceof \WP_Site ) {
			$this->purge_blog( (int) $old_site->blog_id );
		}
	}

	/**
	 * @param int|string $blog_id
	 * @param int|string $value New public flag.
	 */
	public function on_public_change( $blog_id, $value ): void {
		// Only relevant when participation is driven by the public flag.
		if ( 'allowlist' === Settings::get_mode() ) {
			return;
		}
		if ( (int) $value === 0 ) {
			$this->purge_blog( (int) $blog_id );
		}
	}

	/**
	 * @param int|string $blog_id
	 */
	public function purge_blog( $blog_id ): void {
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 ) {
			return;
		}
		try {
			$index = new Combined_Index( Settings::get_post_types(), Settings::get_language() );
			$index->purge_site( $blog_id );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[loupe-cross-site] purge failed for blog %d: %s', $blog_id, $e->getMessage() ) );
			}
		}
	}
}
