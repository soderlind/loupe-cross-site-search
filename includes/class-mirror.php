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

	/** Loupe Search's Excluded-post flag; a change must re-evaluate the post. */
	private const EXCLUDE_META_KEY = '_loupe_search_exclude';

	private Combined_Index $index;

	/** @var string[] */
	private array $post_types;

	private int $blog_id;

	/** @var array<int,bool> Posts being deleted; their meta removal must not re-add them. */
	private array $deleting = [];

	/**
	 * @param string[] $post_types
	 */
	public function __construct( Combined_Index $index, array $post_types, int $blog_id ) {
		$this->index      = $index;
		$this->post_types = $post_types;
		$this->blog_id    = $blog_id;

		add_action( 'transition_post_status', [ $this, 'on_transition' ], 20, 3 );
		// Upsert after meta and terms are saved, so per-post vetoes see the final state.
		add_action( 'wp_after_insert_post', [ $this, 'on_after_insert' ], 20, 2 );
		add_action( 'added_post_meta', [ $this, 'on_meta_change' ], 20, 3 );
		add_action( 'updated_post_meta', [ $this, 'on_meta_change' ], 20, 3 );
		add_action( 'deleted_post_meta', [ $this, 'on_meta_change' ], 20, 3 );
		add_action( 'before_delete_post', [ $this, 'mark_deleting' ], 1 );
		add_action( 'deleted_post', [ $this, 'on_deleted' ], 20, 2 );
	}

	private function covers( string $post_type ): bool {
		return in_array( $post_type, $this->post_types, true );
	}

	/**
	 * Remove when a published post leaves the published state.
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! $this->covers( $post->post_type ) ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' === $old_status ) {
			$this->remove( $post->post_type, (int) $post->ID );
		}
	}

	/**
	 * Add or refresh a published post once the save is complete.
	 *
	 * The passed object can be stale when a save_post callback ran a nested
	 * wp_update_post(), so the post is reloaded.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object (ignored; may be stale).
	 */
	public function on_after_insert( int $post_id, $post ): void {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && $this->covers( $post->post_type ) ) {
			$this->upsert( $post );
		}
	}

	/**
	 * Re-evaluate a published post when its Excluded-post flag changes.
	 *
	 * @param mixed  $meta_id   Meta ID(s).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		$post_id = (int) $object_id;
		if ( self::EXCLUDE_META_KEY !== $meta_key || isset( $this->deleting[ $post_id ] ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && $this->covers( $post->post_type ) ) {
			$this->upsert( $post );
		}
	}

	/**
	 * wp_delete_post() removes meta before the post row; don't re-add it meanwhile.
	 *
	 * @param int $post_id Post ID.
	 */
	public function mark_deleting( $post_id ): void {
		$this->deleting[ (int) $post_id ] = true;
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
