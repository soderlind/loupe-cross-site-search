<?php
/**
 * Shared per-site reindex routine used by both the WP-CLI worker and the
 * Action Scheduler background job.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Reindexer {

	/**
	 * Rebuild one site's slice of the combined index. The caller must already be
	 * in the target site's context (a `--url` bootstrap or `switch_to_blog()`).
	 *
	 * @return int Number of documents indexed.
	 */
	public static function run( int $blog_id, Combined_Index $index ): int {
		$index->purge_site( $blog_id );

		$total = 0;
		foreach ( $index->get_post_types() as $post_type ) {
			$paged = 1;
			do {
				$ids = get_posts( [
					'post_type'        => $post_type,
					'post_status'      => 'publish',
					'posts_per_page'   => 200,
					'paged'            => $paged,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => false,
				] );
				foreach ( $ids as $post_id ) {
					$post = get_post( $post_id );
					if ( ! $post instanceof \WP_Post ) {
						continue;
					}
					$document = Document_Builder::build( $post, $blog_id );
					if ( null !== $document ) {
						$index->add_document( $post_type, $document );
						$total++;
					}
				}
				$paged++;
			} while ( count( $ids ) === 200 );
		}

		return $total;
	}
}
