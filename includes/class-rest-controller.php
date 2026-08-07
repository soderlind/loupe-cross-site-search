<?php
/**
 * Hub-only REST controller. Mirrors Loupe Search's request/response schema and
 * adds cross-site attribution plus a blog_id filter/facet.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class REST_Controller {

	private const NAMESPACE = 'loupe-cross-site/v1';

	/** Fields that may be used in filter / sort / facet operations. */
	private const ALLOWED_FIELDS = [ 'post_type', 'blog_id', 'blog_name', 'post_date' ];

	private const MAX_SCANNED = 1000;

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/search', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'search_post' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search_get' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	private function index(): Combined_Index {
		return new Combined_Index( Settings::get_post_types(), Settings::get_language() );
	}

	/**
	 * POST /search — full schema.
	 */
	public function search_post( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return $this->error( 'lcss_invalid_payload', __( 'Request body must be valid JSON.', 'loupe-cross-site-search' ) );
		}

		$q = isset( $body['q'] ) ? trim( (string) $body['q'] ) : '';
		if ( '' === $q ) {
			return $this->error( 'lcss_missing_query', __( 'Missing or empty query parameter "q".', 'loupe-cross-site-search' ) );
		}

		$size   = isset( $body['page']['size'] ) ? (int) $body['page']['size'] : 10;
		$number = isset( $body['page']['number'] ) ? (int) $body['page']['number'] : 1;
		if ( $size < 1 || $size > 100 ) {
			return $this->error( 'lcss_invalid_page_size', __( 'page.size must be between 1 and 100.', 'loupe-cross-site-search' ) );
		}
		$number = max( 1, $number );
		$offset = ( $number - 1 ) * $size;
		if ( $offset + $size > self::MAX_SCANNED ) {
			return $this->error( 'lcss_pagination_limit', __( 'Requested page is too deep.', 'loupe-cross-site-search' ) );
		}

		$post_types = $this->parse_post_types( $body['postTypes'] ?? 'all' );
		if ( $post_types instanceof \WP_Error ) {
			return $post_types;
		}

		try {
			$filter = isset( $body['filter'] ) ? $this->build_filter( $body['filter'] ) : '';
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'lcss_invalid_filter', $e->getMessage() );
		}

		$sort   = $this->parse_sort( $body['sort'] ?? [] );
		$facets = $this->parse_facets( $body['facets'] ?? [] );

		$options = [
			'post_types' => $post_types,
			'filter'     => $filter,
			'sort'       => $sort['loupe'],
			'facets'     => $facets,
			'limit'      => $offset + $size,
		];

		foreach ( [ 'attributesToHighlight', 'attributesToCrop' ] as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				$options[ $key ] = array_map( 'strval', $body[ $key ] );
			}
		}
		foreach ( [ 'highlightStartTag', 'highlightEndTag', 'cropMarker' ] as $key ) {
			if ( isset( $body[ $key ] ) ) {
				$options[ $key ] = (string) $body[ $key ];
			}
		}
		if ( isset( $body['cropLength'] ) ) {
			$options['cropLength'] = (int) $body['cropLength'];
		}

		$result = $this->index()->search( $q, $options );
		return $this->format_response( $result, $number, $size, $offset, $sort['primary'] );
	}

	/**
	 * GET /search — legacy, query + pagination only.
	 */
	public function search_get( \WP_REST_Request $request ) {
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $q ) {
			return $this->error( 'lcss_missing_query', __( 'Missing or empty query parameter "q".', 'loupe-cross-site-search' ) );
		}
		$size      = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 10 ) ) );
		$number    = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$offset    = ( $number - 1 ) * $size;
		$post_type = (string) ( $request->get_param( 'post_type' ) ?: 'all' );

		if ( $offset + $size > self::MAX_SCANNED ) {
			return $this->error( 'lcss_pagination_limit', __( 'Requested page is too deep.', 'loupe-cross-site-search' ) );
		}

		$post_types = $this->parse_post_types( 'all' === $post_type ? 'all' : [ $post_type ] );
		if ( $post_types instanceof \WP_Error ) {
			return $post_types;
		}

		$result = $this->index()->search( $q, [
			'post_types' => $post_types,
			'limit'      => $offset + $size,
		] );
		return $this->format_response( $result, $number, $size, $offset, null );
	}

	/**
	 * @param mixed $requested
	 * @return string[]|\WP_Error
	 */
	private function parse_post_types( $requested ) {
		$covered = Settings::get_post_types();
		if ( 'all' === $requested || null === $requested ) {
			return $covered;
		}
		if ( ! is_array( $requested ) ) {
			return $this->error( 'lcss_invalid_post_types', __( 'postTypes must be "all" or an array of slugs.', 'loupe-cross-site-search' ) );
		}
		$requested = array_map( 'sanitize_key', $requested );
		$valid     = array_values( array_intersect( $covered, $requested ) );
		if ( empty( $valid ) ) {
			return $this->error( 'lcss_invalid_post_type', __( 'None of the requested post types are covered by cross-site search.', 'loupe-cross-site-search' ) );
		}
		return $valid;
	}

	/**
	 * Translate the JSON filter AST into a Loupe filter string over allowlisted fields.
	 *
	 * @param mixed $node
	 */
	private function build_filter( $node ): string {
		if ( ! is_array( $node ) || empty( $node['type'] ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Malformed filter node.', 'loupe-cross-site-search' ) );
		}

		switch ( $node['type'] ) {
			case 'and':
			case 'or':
				$items = isset( $node['items'] ) && is_array( $node['items'] ) ? $node['items'] : [];
				$parts = array_map( [ $this, 'build_filter' ], $items );
				$parts = array_filter( $parts, static fn( $p ) => '' !== $p );
				if ( empty( $parts ) ) {
					return '';
				}
				$glue = 'and' === $node['type'] ? ' AND ' : ' OR ';
				return '(' . implode( $glue, $parts ) . ')';

			case 'not':
				$inner = isset( $node['item'] ) ? $this->build_filter( $node['item'] ) : '';
				return '' === $inner ? '' : 'NOT (' . $inner . ')';

			case 'pred':
				return $this->build_predicate( $node );
		}

		throw new \InvalidArgumentException( esc_html__( 'Unknown filter node type.', 'loupe-cross-site-search' ) );
	}

	/**
	 * @param array<string,mixed> $node
	 */
	private function build_predicate( array $node ): string {
		$field = isset( $node['field'] ) ? (string) $node['field'] : '';
		if ( ! in_array( $field, self::ALLOWED_FIELDS, true ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: field name */
					esc_html__( 'Field "%s" is not filterable.', 'loupe-cross-site-search' ),
					esc_html( $field )
				)
			);
		}
		$op    = isset( $node['op'] ) ? (string) $node['op'] : 'eq';
		$value = $node['value'] ?? null;

		switch ( $op ) {
			case 'eq':
				return sprintf( '%s = %s', $field, $this->literal( $value ) );
			case 'ne':
				return sprintf( '%s != %s', $field, $this->literal( $value ) );
			case 'lt':
				return sprintf( '%s < %s', $field, $this->literal( $value ) );
			case 'lte':
				return sprintf( '%s <= %s', $field, $this->literal( $value ) );
			case 'gt':
				return sprintf( '%s > %s', $field, $this->literal( $value ) );
			case 'gte':
				return sprintf( '%s >= %s', $field, $this->literal( $value ) );
			case 'in':
			case 'nin':
				if ( ! is_array( $value ) || empty( $value ) ) {
					throw new \InvalidArgumentException( esc_html__( 'in/nin require a non-empty array.', 'loupe-cross-site-search' ) );
				}
				$list = implode( ', ', array_map( [ $this, 'literal' ], $value ) );
				return sprintf( '%s %sIN (%s)', $field, 'nin' === $op ? 'NOT ' : '', $list );
			case 'between':
				$min = is_array( $value ) ? ( $value['min'] ?? $value[0] ?? null ) : null;
				$max = is_array( $value ) ? ( $value['max'] ?? $value[1] ?? null ) : null;
				if ( null === $min || null === $max ) {
					throw new \InvalidArgumentException( esc_html__( 'between requires min and max.', 'loupe-cross-site-search' ) );
				}
				return sprintf( '(%1$s >= %2$s AND %1$s <= %3$s)', $field, $this->literal( $min ), $this->literal( $max ) );
		}

		throw new \InvalidArgumentException(
			sprintf(
				/* translators: %s: operator */
				esc_html__( 'Unsupported operator "%s".', 'loupe-cross-site-search' ),
				esc_html( $op )
			)
		);
	}

	/**
	 * @param mixed $value
	 */
	private function literal( $value ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}

	/**
	 * @param mixed $sort
	 * @return array{loupe:string[],primary:?array{by:string,order:string}}
	 */
	private function parse_sort( $sort ): array {
		$loupe   = [];
		$primary = null;
		if ( ! is_array( $sort ) ) {
			return [ 'loupe' => $loupe, 'primary' => $primary ];
		}
		foreach ( $sort as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['by'] ) ) {
				continue;
			}
			$by    = (string) $entry['by'];
			$order = ( isset( $entry['order'] ) && 'asc' === strtolower( (string) $entry['order'] ) ) ? 'asc' : 'desc';
			if ( null === $primary ) {
				$primary = [ 'by' => $by, 'order' => $order ];
			}
			if ( '_score' === $by ) {
				continue; // Relevance is Loupe's default ordering.
			}
			if ( in_array( $by, [ 'post_date', 'post_title' ], true ) ) {
				$loupe[] = $by . ':' . $order;
			}
		}
		return [ 'loupe' => $loupe, 'primary' => $primary ];
	}

	/**
	 * @param mixed $facets
	 * @return string[]
	 */
	private function parse_facets( $facets ): array {
		$fields = [];
		if ( ! is_array( $facets ) ) {
			return $fields;
		}
		foreach ( $facets as $facet ) {
			if ( is_array( $facet ) && isset( $facet['field'] ) && in_array( $facet['field'], self::ALLOWED_FIELDS, true ) ) {
				$fields[] = (string) $facet['field'];
			}
		}
		return array_values( array_unique( $fields ) );
	}

	/**
	 * @param array{hits:array<int,array<string,mixed>>,totalHits:int,processingTimeMs:int,facetDistribution:array<string,array<string,int>>} $result
	 * @param array{by:string,order:string}|null $primary_sort
	 */
	private function format_response( array $result, int $number, int $size, int $offset, ?array $primary_sort ): \WP_REST_Response {
		$hits = $result['hits'];
		$this->sort_hits( $hits, $primary_sort );
		$page_hits = array_slice( $hits, $offset, $size );

		$formatted = [];
		foreach ( $page_hits as $hit ) {
			$blog_id = (int) ( $hit['blog_id'] ?? 0 );
			$post_id = 0;
			if ( isset( $hit['id'] ) && str_contains( (string) $hit['id'], '_' ) ) {
				$post_id = (int) substr( (string) $hit['id'], strpos( (string) $hit['id'], '_' ) + 1 );
			}
			$post_type = (string) ( $hit['post_type'] ?? '' );
			$pt_object = get_post_type_object( $post_type );

			$item = [
				'id'              => $post_id,
				'blog_id'         => $blog_id,
				'blog_name'       => (string) ( $hit['blog_name'] ?? '' ),
				'post_type'       => $post_type,
				'post_type_label' => $pt_object ? $pt_object->labels->singular_name : $post_type,
				'title'           => (string) ( $hit['post_title'] ?? '' ),
				'excerpt'         => (string) ( $hit['post_excerpt'] ?? '' ),
				'date'            => (string) ( $hit['post_date'] ?? '' ),
				'url'             => (string) ( $hit['url'] ?? '' ),
				'_score'          => isset( $hit['_score'] ) ? (float) $hit['_score'] : null,
			];
			if ( isset( $hit['_formatted'] ) && is_array( $hit['_formatted'] ) ) {
				$item['_formatted'] = $hit['_formatted'];
			}
			$formatted[] = $item;
		}

		$total       = (int) $result['totalHits'];
		$total_pages = $size > 0 ? (int) ceil( $total / $size ) : 0;

		$response = [
			'hits'       => $formatted,
			'facets'     => $this->format_facets( $result['facetDistribution'] ),
			'pagination' => [
				'total'        => $total,
				'per_page'     => $size,
				'current_page' => $number,
				'total_pages'  => $total_pages,
			],
			'tookMs'     => (int) $result['processingTimeMs'],
		];

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * @param array<int,array<string,mixed>> $hits
	 * @param array{by:string,order:string}|null $primary_sort
	 */
	private function sort_hits( array &$hits, ?array $primary_sort ): void {
		$by    = $primary_sort['by'] ?? '_score';
		$order = $primary_sort['order'] ?? 'desc';
		$dir   = 'asc' === $order ? 1 : -1;

		usort( $hits, static function ( $a, $b ) use ( $by, $dir ) {
			$field = '_score' === $by ? '_score' : $by;
			$av    = $a[ $field ] ?? null;
			$bv    = $b[ $field ] ?? null;
			if ( is_numeric( $av ) && is_numeric( $bv ) ) {
				return ( $av <=> $bv ) * $dir;
			}
			return strcmp( (string) $av, (string) $bv ) * $dir;
		} );
	}

	/**
	 * @param array<string,array<string,int>> $distribution
	 * @return array<string,array{type:string,buckets:array<int,array{value:string,count:int}>}>
	 */
	private function format_facets( array $distribution ): array {
		$out = [];
		foreach ( $distribution as $field => $values ) {
			$buckets = [];
			foreach ( $values as $value => $count ) {
				$buckets[] = [ 'value' => $this->normalize_facet_value( (string) $value ), 'count' => (int) $count ];
			}
			$out[ $field ] = [ 'type' => 'terms', 'buckets' => $buckets ];
		}
		return $out;
	}

	/**
	 * Loupe returns numeric facet keys as floats ("4.0"); present whole numbers
	 * as integers so e.g. blog_id facets read "4" rather than "4.0".
	 */
	private function normalize_facet_value( string $value ): string {
		if ( is_numeric( $value ) && (float) $value === floor( (float) $value ) && abs( (float) $value ) < PHP_INT_MAX ) {
			return (string) (int) (float) $value;
		}
		return $value;
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => 400 ] );
	}
}
