<?php
/**
 * Combined-index gateway.
 *
 * Owns the Loupe instances for the network-global combined index. Deliberately
 * does NOT reuse Loupe Search's factory/engine (see ADR 0003): that factory
 * caches instances by post type regardless of path and derives its config from
 * the current site's options.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Combined_Index {

	private const FILENAME = 'loupe.db';

	/** Searchable text fields (fixed core schema). */
	private const SEARCHABLE = [ 'post_title', 'post_content', 'post_excerpt' ];

	/** Filterable/facetable fields. */
	private const FILTERABLE = [ 'post_type', 'blog_id', 'blog_name', 'post_date' ];

	/** Sortable fields. */
	private const SORTABLE = [ 'post_date', 'post_title' ];

	/** @var string[] */
	private array $post_types;

	private string $language;

	/** @var array<string,\Loupe\Loupe\Loupe> */
	private array $loupe = [];

	/**
	 * @param string[] $post_types
	 */
	public function __construct( array $post_types, string $language ) {
		$this->post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		$this->language   = preg_match( '/^[a-z]{2}$/', $language ) ? $language : 'en';
	}

	/**
	 * @return string[]
	 */
	public function get_post_types(): array {
		return $this->post_types;
	}

	/**
	 * Network-global base path for the combined index. Shared across all sites
	 * because WP_CONTENT_DIR is network-wide.
	 */
	public static function base_path(): string {
		$default = WP_CONTENT_DIR . '/loupe-cross-site-db';
		$path    = apply_filters( 'loupe_cross_site_db_path', $default );
		$path    = is_string( $path ) && '' !== trim( $path ) ? rtrim( trim( $path ), '/' ) : $default;
		return $path;
	}

	private function db_path( string $post_type ): string {
		$path = self::base_path() . '/' . $post_type;
		if ( ! is_dir( $path ) ) {
			wp_mkdir_p( $path );
		}
		return $path;
	}

	private function config(): Configuration {
		return Configuration::create()
			->withPrimaryKey( 'id' )
			->withSearchableAttributes( self::SEARCHABLE )
			->withFilterableAttributes( self::FILTERABLE )
			->withSortableAttributes( self::SORTABLE )
			->withLanguages( [ $this->language ] )
			->withTypoTolerance( TypoTolerance::create() );
	}

	private function loupe( string $post_type ): \Loupe\Loupe\Loupe {
		if ( ! isset( $this->loupe[ $post_type ] ) ) {
			$factory                     = new LoupeFactory();
			$this->loupe[ $post_type ]   = $factory->create( $this->db_path( $post_type ), $this->config() );
		}
		return $this->loupe[ $post_type ];
	}

	public function is_ready( string $post_type ): bool {
		return file_exists( self::base_path() . '/' . $post_type . '/' . self::FILENAME );
	}

	/**
	 * Upsert a prepared document.
	 *
	 * @param array<string,mixed> $document
	 */
	public function add_document( string $post_type, array $document ): void {
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		$this->loupe( $post_type )->addDocument( $document );
	}

	public function delete_document( string $post_type, string $id ): void {
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		try {
			$this->loupe( $post_type )->deleteDocument( $id );
		} catch ( \Throwable $e ) {
			// Nothing to delete / already gone.
			unset( $e );
		}
	}

	/**
	 * Remove every document belonging to a site across all covered post types.
	 */
	public function purge_site( int $blog_id ): int {
		$removed = 0;
		foreach ( $this->post_types as $post_type ) {
			if ( ! $this->is_ready( $post_type ) ) {
				continue;
			}
			$loupe = $this->loupe( $post_type );
			do {
				$ids = $this->ids_for_blog( $post_type, $blog_id, 500 );
				if ( empty( $ids ) ) {
					break;
				}
				try {
					$loupe->deleteDocuments( $ids );
					$removed += count( $ids );
				} catch ( \Throwable $e ) {
					unset( $e );
					break;
				}
			} while ( count( $ids ) === 500 );
		}
		return $removed;
	}

	/**
	 * Document IDs stored for a given blog in a post-type index.
	 *
	 * @return string[]
	 */
	public function ids_for_blog( string $post_type, int $blog_id, int $limit = 1000 ): array {
		if ( ! $this->is_ready( $post_type ) ) {
			return [];
		}
		try {
			$params = SearchParameters::create()
				->withQuery( '' )
				->withAttributesToRetrieve( [ 'id' ] )
				->withFilter( sprintf( 'blog_id = %d', $blog_id ) )
				->withLimit( max( 1, $limit ) );
			$result = $this->loupe( $post_type )->search( $params )->toArray();
		} catch ( \Throwable $e ) {
			unset( $e );
			return [];
		}
		$ids = [];
		foreach ( $result['hits'] ?? [] as $hit ) {
			if ( isset( $hit['id'] ) ) {
				$ids[] = (string) $hit['id'];
			}
		}
		return $ids;
	}

	/**
	 * Run a merged search across the requested post-type indexes.
	 *
	 * @param array{
	 *   post_types?: string[],
	 *   filter?: string,
	 *   sort?: string[],
	 *   facets?: string[],
	 *   limit?: int,
	 *   attributesToHighlight?: string[],
	 *   highlightStartTag?: string,
	 *   highlightEndTag?: string,
	 *   attributesToCrop?: string[],
	 *   cropLength?: int,
	 *   cropMarker?: string,
	 * } $options
	 * @return array{hits:array<int,array<string,mixed>>,totalHits:int,processingTimeMs:int,facetDistribution:array<string,array<string,int>>}
	 */
	public function search( string $query, array $options = [] ): array {
		$requested = ! empty( $options['post_types'] ) ? array_values( array_intersect( $this->post_types, (array) $options['post_types'] ) ) : $this->post_types;
		$filter    = isset( $options['filter'] ) ? (string) $options['filter'] : '';
		$sort      = isset( $options['sort'] ) && is_array( $options['sort'] ) ? array_values( $options['sort'] ) : [];
		$facets    = isset( $options['facets'] ) && is_array( $options['facets'] ) ? array_values( $options['facets'] ) : [];
		$limit     = isset( $options['limit'] ) ? max( 1, (int) $options['limit'] ) : 100;
		$highlight = isset( $options['attributesToHighlight'] ) && is_array( $options['attributesToHighlight'] ) ? array_values( array_unique( $options['attributesToHighlight'] ) ) : [];
		$crop      = isset( $options['attributesToCrop'] ) && is_array( $options['attributesToCrop'] ) ? array_values( array_unique( $options['attributesToCrop'] ) ) : [];

		$hits      = [];
		$total     = 0;
		$took      = 0;
		$facet_out = [];

		$retrieve = [ 'id', 'blog_id', 'blog_name', 'url', 'post_title', 'post_excerpt', 'post_date', 'post_type' ];
		if ( ! empty( $highlight ) || ! empty( $crop ) ) {
			$retrieve = array_values( array_unique( array_merge( $retrieve, $highlight, $crop ) ) );
		}

		foreach ( $requested as $post_type ) {
			if ( ! $this->is_ready( $post_type ) ) {
				continue;
			}
			try {
				$params = SearchParameters::create()
					->withQuery( $query )
					->withAttributesToRetrieve( $retrieve )
					->withShowRankingScore( true )
					->withLimit( $limit );

				if ( '' !== $filter ) {
					$params = $params->withFilter( $filter );
				}
				if ( ! empty( $sort ) ) {
					$params = $params->withSort( $sort );
				}
				if ( ! empty( $facets ) ) {
					$params = $params->withFacets( $facets );
				}
				if ( ! empty( $highlight ) ) {
					$params = $params->withAttributesToHighlight(
						$highlight,
						isset( $options['highlightStartTag'] ) ? (string) $options['highlightStartTag'] : '<em>',
						isset( $options['highlightEndTag'] ) ? (string) $options['highlightEndTag'] : '</em>'
					);
				}
				if ( ! empty( $crop ) ) {
					$params = $params->withAttributesToCrop(
						$crop,
						isset( $options['cropLength'] ) ? (int) $options['cropLength'] : 50,
						isset( $options['cropMarker'] ) ? (string) $options['cropMarker'] : '…'
					);
				}

				$arr   = $this->loupe( $post_type )->search( $params )->toArray();
				$total += (int) ( $arr['totalHits'] ?? 0 );
				$took  += (int) ( $arr['processingTimeMs'] ?? 0 );

				foreach ( (array) ( $arr['facetDistribution'] ?? [] ) as $field => $dist ) {
					foreach ( (array) $dist as $value => $count ) {
						$facet_out[ $field ][ (string) $value ] = ( $facet_out[ $field ][ (string) $value ] ?? 0 ) + (int) $count;
					}
				}

				foreach ( (array) ( $arr['hits'] ?? [] ) as $hit ) {
					if ( ! is_array( $hit ) ) {
						continue;
					}
					if ( isset( $hit['_rankingScore'] ) && ! isset( $hit['_score'] ) ) {
						$hit['_score'] = $hit['_rankingScore'];
					}
					$hit['post_type'] = $hit['post_type'] ?? $post_type;
					$hits[]           = $hit;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return [
			'hits'              => $hits,
			'totalHits'         => $total,
			'processingTimeMs'  => $took,
			'facetDistribution' => $facet_out,
		];
	}
}
