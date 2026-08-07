<?php
/**
 * Mirror — keeps a participating site's content in the combined index.
 *
 * Runs in the site's own request context (no switch_to_blog), so options and
 * registered filters are live. A mirror failure is caught and logged and must
 * never break the editor save (see round 3, Q18).
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Mirror {

	private Combined_Index $index;

	/** @var string[] */
	private array $post_types;

	private int $blog_id;

	/**
	 * @param string[] $post_types
	 */
	public function __construct( Combined_Index $index, array $post_types, int $blog_id ) {
		$this->index      = $index;
		$this->post_types = $post_types;
		$this->blog_id    = $blog_id;

		add_action( 'transition_post_status', [ $this, 'on_transition' ], 20, 3 );
		add_action( 'deleted_post', [ $this, 'on_deleted' ], 20, 2 );
	}

	private function covers( string $post_type ): bool {
		return in_array( $post_type, $this->post_types, true );
	}

	/**
	 * Add on publish, remove when a published post leaves the published state.
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! $this->covers( $post->post_type ) ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			$this->upsert( $post );
		} elseif ( 'publish' === $old_status ) {
			$this->remove( $post->post_type, (int) $post->ID );
		}
	}

	/**
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object (passed since WP 5.5).
	 */
	public function on_deleted( int $post_id, $post ): void {
		$post_type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( ! is_string( $post_type ) || ! $this->covers( $post_type ) ) {
			return;
		}
		$this->remove( $post_type, $post_id );
	}

	private function upsert( \WP_Post $post ): void {
		try {
			$document = Document_Builder::build( $post, $this->blog_id );
			if ( null === $document ) {
				// Not indexable (e.g. password protected) — ensure any stale copy is gone.
				$this->remove( $post->post_type, (int) $post->ID );
				return;
			}
			$this->index->add_document( $post->post_type, $document );
		} catch ( \Throwable $e ) {
			$this->log( 'upsert', $post->ID, $e );
		}
	}

	private function remove( string $post_type, int $post_id ): void {
		try {
			$this->index->delete_document( $post_type, Document_Builder::document_id( $this->blog_id, $post_id ) );
		} catch ( \Throwable $e ) {
			$this->log( 'remove', $post_id, $e );
		}
	}

	private function log( string $op, int $post_id, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[loupe-cross-site] %s failed for blog %d post %d: %s', $op, $this->blog_id, $post_id, $e->getMessage() ) );
		}
	}
}
