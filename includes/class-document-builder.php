<?php
/**
 * Document builder — turns a post into a combined-index document.
 *
 * Reuses Loupe Search's own document builder for field fidelity, then projects
 * the result onto the fixed core schema and adds cross-site attribution
 * (composite id, blog_id, blog_name, url). Runs in the post's own site context.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

use Soderlind\Plugin\WPLoupe\WP_Loupe_Indexer;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Document_Builder {

	private static ?WP_Loupe_Indexer $indexer = null;

	/** Blog the cached indexer (and its schema cache) was built for. */
	private static int $indexer_blog = 0;

	/**
	 * Composite primary key for a post in the combined index.
	 */
	public static function document_id( int $blog_id, int $post_id ): string {
		return $blog_id . '_' . $post_id;
	}

	/**
	 * Build a combined-index document, or null if the post is not indexable.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function build( \WP_Post $post, int $blog_id ): ?array {
		if ( 'publish' !== $post->post_status ) {
			return null;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return null;
		}
		// Respect the same password gate Loupe Search uses.
		$index_protected = apply_filters( 'loupe_search_index_protected', empty( $post->post_password ) );
		if ( ! $index_protected ) {
			return null;
		}

		$prepared = self::prepared( $post, $blog_id );

		$document = [
			'id'           => self::document_id( $blog_id, (int) $post->ID ),
			'blog_id'      => $blog_id,
			'blog_name'    => get_bloginfo( 'name' ),
			'url'          => get_permalink( $post ),
			'post_type'    => $post->post_type,
			'post_title'   => $prepared['post_title'] ?? $post->post_title,
			'post_content' => $prepared['post_content'] ?? wp_strip_all_tags( (string) $post->post_content ),
			'post_excerpt' => $prepared['post_excerpt'] ?? (string) $post->post_excerpt,
			'post_date'    => $prepared['post_date'] ?? (string) $post->post_date,
		];

		/**
		 * Filter the combined-index document before it is written.
		 *
		 * @param array<string,mixed> $document The projected document.
		 * @param \WP_Post            $post     The source post.
		 * @param int                 $blog_id  The source site ID.
		 */
		return apply_filters( 'loupe_cross_site_document', $document, $post, $blog_id );
	}

	/**
	 * The full document Loupe Search would build for this post (best effort).
	 *
	 * @return array<string,mixed>
	 */
	private static function prepared( \WP_Post $post, int $blog_id ): array {
		try {
			// Rebuild for a new blog: the indexer's schema manager caches by post
			// type only, which would leak one site's schema into another when
			// several sites are reindexed in one request (switch_to_blog batch).
			if ( null === self::$indexer || self::$indexer_blog !== $blog_id ) {
				// register_hooks:false — we only want prepare_document(), not a second set of save hooks.
				self::$indexer      = new WP_Loupe_Indexer( Settings::get_post_types(), false );
				self::$indexer_blog = $blog_id;
			}
			$doc = self::$indexer->prepare_document( $post );
			return is_array( $doc ) ? $doc : [];
		} catch ( \Throwable $e ) {
			return [];
		}
	}
}
