<?php
/**
 * Participation rules — which sites are included in cross-site search.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Decides, without switching sites, whether a given blog participates.
 */
class Participation {

	/**
	 * Whether a site participates in cross-site search.
	 */
	public static function is_participating( int $blog_id ): bool {
		if ( $blog_id <= 0 ) {
			return false;
		}

		$site = get_site( $blog_id );
		if ( ! $site instanceof \WP_Site ) {
			return false;
		}
		// Never index archived, deleted, or spam sites.
		if ( (int) $site->archived || (int) $site->deleted || (int) $site->spam ) {
			return false;
		}

		$mode      = Settings::get_mode();
		$is_public = (int) $site->public === 1;
		$listed    = in_array( $blog_id, Settings::get_configured_sites(), true );

		$participating = match ( $mode ) {
			'allowlist' => $listed,
			'blocklist' => $is_public && ! $listed,
			default     => $is_public,
		};

		/**
		 * Filter whether a site participates in cross-site search.
		 *
		 * @param bool $participating Whether the site participates.
		 * @param int  $blog_id       Site ID.
		 */
		return (bool) apply_filters( 'loupe_cross_site_is_participating', $participating, $blog_id );
	}

	/**
	 * All participating site IDs, resolved from site metadata only (no switch_to_blog).
	 *
	 * @return int[]
	 */
	public static function get_participating_site_ids(): array {
		$ids = [];
		foreach ( get_sites( [ 'number' => 0, 'orderby' => 'id' ] ) as $site ) {
			$blog_id = (int) $site->blog_id;
			if ( self::is_participating( $blog_id ) ) {
				$ids[] = $blog_id;
			}
		}
		return $ids;
	}
}
