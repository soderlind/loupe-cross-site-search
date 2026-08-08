<?php
/**
 * WP-CLI commands: reindex and verify the combined index.
 *
 * Backfill runs per-site in each site's own context via separate CLI bootstraps
 * (`wp --url=…`), never switch_to_blog (see ADR 0002).
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) || ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * Manage the Loupe cross-site combined index.
 */
class CLI {

	/**
	 * Rebuild the combined index for participating sites.
	 *
	 * ## OPTIONS
	 *
	 * [--sites=<ids>]
	 * : Comma-separated site IDs. Defaults to all participating sites.
	 *
	 * [--post-types=<types>]
	 * : Comma-separated post types. Defaults to the configured set.
	 *
	 * ## EXAMPLES
	 *
	 *     wp loupe-cross-site reindex
	 *     wp loupe-cross-site reindex --sites=2,5 --post-types=post
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function reindex( $args, $assoc_args ): void {
		$site_ids = $this->resolve_site_ids( $assoc_args );
		if ( empty( $site_ids ) ) {
			\WP_CLI::warning( 'No participating sites to reindex.' );
			return;
		}

		$pt_arg = isset( $assoc_args['post-types'] ) ? ' --post-types=' . escapeshellarg( (string) $assoc_args['post-types'] ) : '';

		foreach ( $site_ids as $blog_id ) {
			$url = get_home_url( $blog_id );
			\WP_CLI::log( sprintf( 'Reindexing site %d (%s)…', $blog_id, $url ) );
			\WP_CLI::runcommand(
				'loupe-cross-site reindex-site --url=' . escapeshellarg( $url ) . $pt_arg,
				[ 'launch' => true, 'exit_error' => false ]
			);
		}
		\WP_CLI::success( 'Reindex complete.' );
	}

	/**
	 * Worker: reindex the current site (run via --url). Runs in the site's context.
	 *
	 * ## OPTIONS
	 *
	 * [--post-types=<types>]
	 * : Comma-separated post types. Defaults to the configured set.
	 *
	 * [--force]
	 * : Index even if the site is not currently participating.
	 *
	 * @subcommand reindex-site
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function reindex_site( $args, $assoc_args ): void {
		$blog_id = get_current_blog_id();
		if ( empty( $assoc_args['force'] ) && ! Participation::is_participating( $blog_id ) ) {
			\WP_CLI::warning( sprintf( 'Site %d is not participating; skipping (use --force to override).', $blog_id ) );
			return;
		}

		$post_types = $this->post_types_arg( $assoc_args );
		$index      = new Combined_Index( $post_types, Settings::get_language() );

		$total = Reindexer::run( $blog_id, $index );

		\WP_CLI::success( sprintf( 'Indexed %d documents for site %d.', $total, $blog_id ) );
	}

	/**
	 * Verify the combined index against site content and optionally repair drift.
	 *
	 * ## OPTIONS
	 *
	 * [--site=<id>]
	 * : A single site ID. Defaults to all participating sites.
	 *
	 * [--repair]
	 * : Add missing documents and remove stale ones.
	 *
	 * ## EXAMPLES
	 *
	 *     wp loupe-cross-site verify
	 *     wp loupe-cross-site verify --site=5 --repair
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function verify( $args, $assoc_args ): void {
		$site_ids = isset( $assoc_args['site'] )
			? [ (int) $assoc_args['site'] ]
			: Participation::get_participating_site_ids();

		$repair = ! empty( $assoc_args['repair'] );

		foreach ( $site_ids as $blog_id ) {
			$url = get_home_url( $blog_id );
			\WP_CLI::runcommand(
				'loupe-cross-site verify-site --url=' . escapeshellarg( $url ) . ( $repair ? ' --repair' : '' ),
				[ 'launch' => true, 'exit_error' => false ]
			);
		}
	}

	/**
	 * Worker: verify the current site (run via --url).
	 *
	 * ## OPTIONS
	 *
	 * [--repair]
	 * : Add missing documents and remove stale ones.
	 *
	 * @subcommand verify-site
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function verify_site( $args, $assoc_args ): void {
		$blog_id    = get_current_blog_id();
		$repair     = ! empty( $assoc_args['repair'] );
		$post_types = $this->post_types_arg( $assoc_args );
		$index      = new Combined_Index( $post_types, Settings::get_language() );

		$missing_total = 0;
		$stale_total   = 0;

		foreach ( $post_types as $post_type ) {
			$published = array_map( 'intval', get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] ) );

			$indexed_post_ids = [];
			foreach ( $index->ids_for_blog( $post_type, $blog_id, 1000 ) as $doc_id ) {
				if ( str_contains( $doc_id, '_' ) ) {
					$indexed_post_ids[] = (int) substr( $doc_id, strpos( $doc_id, '_' ) + 1 );
				}
			}

			$missing = array_diff( $published, $indexed_post_ids );
			$stale   = array_diff( $indexed_post_ids, $published );

			$missing_total += count( $missing );
			$stale_total   += count( $stale );

			if ( $repair ) {
				foreach ( $missing as $post_id ) {
					$post = get_post( $post_id );
					if ( $post instanceof \WP_Post ) {
						$document = Document_Builder::build( $post, $blog_id );
						if ( null !== $document ) {
							$index->add_document( $post_type, $document );
						}
					}
				}
				foreach ( $stale as $post_id ) {
					$index->delete_document( $post_type, Document_Builder::document_id( $blog_id, (int) $post_id ) );
				}
			}
		}

		$msg = sprintf( 'Site %d: %d missing, %d stale.', $blog_id, $missing_total, $stale_total );
		if ( $repair ) {
			\WP_CLI::success( $msg . ' Repaired.' );
		} elseif ( $missing_total || $stale_total ) {
			\WP_CLI::warning( $msg . ' Run with --repair to fix.' );
		} else {
			\WP_CLI::success( $msg );
		}
	}

	/**
	 * Remove a site's documents from the combined index.
	 *
	 * ## OPTIONS
	 *
	 * --site=<id>
	 * : The site ID to purge.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function purge( $args, $assoc_args ): void {
		$blog_id = isset( $assoc_args['site'] ) ? (int) $assoc_args['site'] : 0;
		if ( $blog_id <= 0 ) {
			\WP_CLI::error( 'Provide --site=<id>.' );
		}
		$index   = new Combined_Index( Settings::get_post_types(), Settings::get_language() );
		$removed = $index->purge_site( $blog_id );
		\WP_CLI::success( sprintf( 'Removed %d documents for site %d.', $removed, $blog_id ) );
	}

	/**
	 * @param array $assoc_args
	 * @return int[]
	 */
	private function resolve_site_ids( array $assoc_args ): array {
		if ( isset( $assoc_args['sites'] ) ) {
			return array_values( array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['sites'] ) ) ) );
		}
		return Participation::get_participating_site_ids();
	}

	/**
	 * @param array $assoc_args
	 * @return string[]
	 */
	private function post_types_arg( array $assoc_args ): array {
		if ( isset( $assoc_args['post-types'] ) ) {
			$types = array_map( 'sanitize_key', explode( ',', (string) $assoc_args['post-types'] ) );
			$types = array_values( array_intersect( Settings::get_post_types(), $types ) );
			if ( ! empty( $types ) ) {
				return $types;
			}
		}
		return Settings::get_post_types();
	}
}

\WP_CLI::add_command( 'loupe-cross-site', CLI::class );
