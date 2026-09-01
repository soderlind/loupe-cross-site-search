<?php
/**
 * WordPress Abilities API integration for Loupe Cross-Site Search.
 *
 * Registers two public read-only abilities so AI agents and automation tools can
 * discover and run cross-site search via the standard Abilities API (WP 6.9+):
 *
 *   - loupe-cross-site/search   — full-text search across the combined index.
 *   - loupe-cross-site/get-post — fetch one publicly viewable post by site + id.
 *
 * Both abilities are unauthenticated by design, mirroring the public `/search`
 * REST route. The two-tier authorization model still applies:
 *   Tier 1 (permission_callback): intentionally open — public read.
 *   Tier 2 (execute_callback): `get-post` re-checks visibility against the target
 *     object on its own site (published, public post type, not password
 *     protected) and returns a WP_Error 400/404 otherwise, so no caller can read
 *     a non-public post by guessing a blog_id/post_id pair (IDOR).
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Abilities {

	private const CATEGORY = 'loupe-cross-site';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_abilities' ] );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Loupe Cross-Site Search', 'loupe-cross-site-search' ),
				'description' => __( 'Cross-site search abilities provided by the Loupe Cross-Site Search plugin.', 'loupe-cross-site-search' ),
			]
		);
	}

	public static function register_abilities(): void {
		wp_register_ability( 'loupe-cross-site/search', self::get_search_ability_args() );
		wp_register_ability( 'loupe-cross-site/get-post', self::get_get_post_ability_args() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_search_ability_args(): array {
		return [
			'label'               => __( 'Cross-Site Search', 'loupe-cross-site-search' ),
			'description'         => __( 'Search published content across all participating sites in the network using the combined Loupe index. Supports phrase matching with quotes, exclusion with -, and OR searches.', 'loupe-cross-site-search' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'       => 'object',
				'required'   => [ 'query' ],
				'properties' => [
					'query'      => [
						'type'        => 'string',
						'description' => __( 'The search query. Supports phrases ("hello world"), exclusions (-term), and OR searches.', 'loupe-cross-site-search' ),
					],
					'post_types' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => __( 'Post types to search. Defaults to all covered post types.', 'loupe-cross-site-search' ),
					],
					'blog_id'    => [
						'type'        => 'integer',
						'description' => __( 'Restrict results to a single site by its blog ID. Defaults to all participating sites.', 'loupe-cross-site-search' ),
						'minimum'     => 1,
					],
					'per_page'   => [
						'type'        => 'integer',
						'description' => __( 'Number of results to return. Default: 10. Max: 100.', 'loupe-cross-site-search' ),
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
					],
					'page'       => [
						'type'        => 'integer',
						'description' => __( 'Page of results to return. Default: 1.', 'loupe-cross-site-search' ),
						'default'     => 1,
						'minimum'     => 1,
					],
				],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'hits'        => [
						'type'        => 'array',
						'description' => __( 'Array of matching posts across sites.', 'loupe-cross-site-search' ),
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'id'        => [ 'type' => 'integer', 'description' => __( 'Post ID on its source site.', 'loupe-cross-site-search' ) ],
								'blog_id'   => [ 'type' => 'integer', 'description' => __( 'Source site (blog) ID.', 'loupe-cross-site-search' ) ],
								'blog_name' => [ 'type' => 'string', 'description' => __( 'Source site name.', 'loupe-cross-site-search' ) ],
								'title'     => [ 'type' => 'string', 'description' => __( 'Post title.', 'loupe-cross-site-search' ) ],
								'url'       => [ 'type' => 'string', 'description' => __( 'Post permalink.', 'loupe-cross-site-search' ) ],
								'excerpt'   => [ 'type' => 'string', 'description' => __( 'Post excerpt.', 'loupe-cross-site-search' ) ],
								'post_type' => [ 'type' => 'string', 'description' => __( 'Post type slug.', 'loupe-cross-site-search' ) ],
								'post_date' => [ 'type' => 'string', 'description' => __( 'Publication date.', 'loupe-cross-site-search' ) ],
							],
						],
					],
					'total_hits'  => [ 'type' => 'integer', 'description' => __( 'Total number of matching posts.', 'loupe-cross-site-search' ) ],
					'page'        => [ 'type' => 'integer', 'description' => __( 'Current page number.', 'loupe-cross-site-search' ) ],
					'total_pages' => [ 'type' => 'integer', 'description' => __( 'Total number of pages.', 'loupe-cross-site-search' ) ],
				],
			],
			'execute_callback'    => [ __CLASS__, 'execute_search' ],
			'permission_callback' => '__return_true',
			'meta'                => [ 'show_in_rest' => true ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_get_post_ability_args(): array {
		return [
			'label'               => __( 'Get Cross-Site Post', 'loupe-cross-site-search' ),
			'description'         => __( 'Retrieve a single published, publicly viewable post from a participating site by its site (blog) ID and post ID.', 'loupe-cross-site-search' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'       => 'object',
				'required'   => [ 'blog_id', 'id' ],
				'properties' => [
					'blog_id' => [
						'type'        => 'integer',
						'description' => __( 'The source site (blog) ID.', 'loupe-cross-site-search' ),
						'minimum'     => 1,
					],
					'id'      => [
						'type'        => 'integer',
						'description' => __( 'The post ID on that site.', 'loupe-cross-site-search' ),
						'minimum'     => 1,
					],
				],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'id'        => [ 'type' => 'integer', 'description' => __( 'Post ID.', 'loupe-cross-site-search' ) ],
					'blog_id'   => [ 'type' => 'integer', 'description' => __( 'Source site (blog) ID.', 'loupe-cross-site-search' ) ],
					'blog_name' => [ 'type' => 'string', 'description' => __( 'Source site name.', 'loupe-cross-site-search' ) ],
					'title'     => [ 'type' => 'string', 'description' => __( 'Post title.', 'loupe-cross-site-search' ) ],
					'content'   => [ 'type' => 'string', 'description' => __( 'Post content (HTML stripped).', 'loupe-cross-site-search' ) ],
					'excerpt'   => [ 'type' => 'string', 'description' => __( 'Post excerpt.', 'loupe-cross-site-search' ) ],
					'url'       => [ 'type' => 'string', 'description' => __( 'Post permalink.', 'loupe-cross-site-search' ) ],
					'post_type' => [ 'type' => 'string', 'description' => __( 'Post type slug.', 'loupe-cross-site-search' ) ],
					'post_date' => [ 'type' => 'string', 'description' => __( 'Publication date (ISO 8601).', 'loupe-cross-site-search' ) ],
					'author'    => [ 'type' => 'string', 'description' => __( 'Display name of the post author.', 'loupe-cross-site-search' ) ],
				],
			],
			'execute_callback'    => [ __CLASS__, 'execute_get_post' ],
			'permission_callback' => '__return_true',
			'meta'                => [ 'show_in_rest' => true ],
		];
	}

	/**
	 * Execute the loupe-cross-site/search ability.
	 *
	 * Results come straight from the combined index, which the mirror keeps free
	 * of non-public content (published, public post type, not password protected).
	 *
	 * @param array<string,mixed> $input Validated input from the Abilities API.
	 * @return array<string,mixed>
	 */
	public static function execute_search( array $input ): array {
		$query    = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );

		$covered   = Settings::get_post_types();
		$requested = ! empty( $input['post_types'] )
			? array_values( array_intersect( $covered, array_map( 'sanitize_key', (array) $input['post_types'] ) ) )
			: $covered;
		if ( empty( $requested ) ) {
			$requested = $covered;
		}

		$offset  = ( $page - 1 ) * $per_page;
		$options = [
			'post_types' => $requested,
			'limit'      => $offset + $per_page,
		];
		if ( isset( $input['blog_id'] ) && (int) $input['blog_id'] > 0 ) {
			$options['filter'] = sprintf( 'blog_id = %d', (int) $input['blog_id'] );
		}

		$result = ( new Combined_Index( $covered, Settings::get_language() ) )->search( $query, $options );
		$paged  = array_slice( $result['hits'], $offset, $per_page );

		$hits = [];
		foreach ( $paged as $hit ) {
			$hits[] = [
				'id'        => self::post_id_from_composite( (string) ( $hit['id'] ?? '' ) ),
				'blog_id'   => (int) ( $hit['blog_id'] ?? 0 ),
				'blog_name' => (string) ( $hit['blog_name'] ?? '' ),
				'title'     => (string) ( $hit['post_title'] ?? '' ),
				'url'       => (string) ( $hit['url'] ?? '' ),
				'excerpt'   => (string) ( $hit['post_excerpt'] ?? '' ),
				'post_type' => (string) ( $hit['post_type'] ?? '' ),
				'post_date' => (string) ( $hit['post_date'] ?? '' ),
			];
		}

		$total_hits  = (int) $result['totalHits'];
		$total_pages = $per_page > 0 ? (int) ceil( $total_hits / $per_page ) : 0;

		return [
			'hits'        => $hits,
			'total_hits'  => $total_hits,
			'page'        => $page,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * Execute the loupe-cross-site/get-post ability.
	 *
	 * Tier-2 per-object authorization: the visibility check runs on the target
	 * post's own site so ownership, post-type, and password rules are honored.
	 *
	 * @param array<string,mixed> $input Validated input from the Abilities API.
	 * @return array<string,mixed>|\WP_Error Post data or error.
	 */
	public static function execute_get_post( array $input ) {
		$blog_id = (int) ( $input['blog_id'] ?? 0 );
		$post_id = (int) ( $input['id'] ?? 0 );
		if ( $blog_id <= 0 || $post_id <= 0 ) {
			return new \WP_Error( 'invalid_id', __( 'A valid blog ID and post ID are required.', 'loupe-cross-site-search' ), [ 'status' => 400 ] );
		}
		if ( ! get_site( $blog_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found or not publicly accessible.', 'loupe-cross-site-search' ), [ 'status' => 404 ] );
		}

		switch_to_blog( $blog_id );
		try {
			// Tier-2 gate: reuse Loupe Search's single visibility source of truth,
			// evaluated in the target site's context.
			if ( ! \Soderlind\Plugin\WPLoupe\WP_Loupe_Utils::is_publicly_viewable_post( $post_id ) ) {
				return new \WP_Error( 'post_not_found', __( 'Post not found or not publicly accessible.', 'loupe-cross-site-search' ), [ 'status' => 404 ] );
			}

			$post = get_post( $post_id );

			return [
				'id'        => $post_id,
				'blog_id'   => $blog_id,
				'blog_name' => get_bloginfo( 'name' ),
				'title'     => get_the_title( $post ),
				'content'   => wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) ),
				'excerpt'   => wp_strip_all_tags( get_the_excerpt( $post ) ),
				'url'       => get_permalink( $post ),
				'post_type' => $post->post_type,
				'post_date' => get_the_date( 'c', $post ),
				'author'    => get_the_author_meta( 'display_name', (int) $post->post_author ),
			];
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Extract the post ID from a "blogid_postid" composite document id.
	 */
	private static function post_id_from_composite( string $composite ): int {
		$pos = strpos( $composite, '_' );
		return false === $pos ? 0 : (int) substr( $composite, $pos + 1 );
	}
}
