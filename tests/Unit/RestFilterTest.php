<?php
/**
 * REST controller: filter-AST translation, sort/facet parsing, post-type parsing.
 * Private methods are exercised via reflection.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeCrossSiteSearch\REST_Controller;

/**
 * @param mixed[] $args
 * @return mixed
 */
function call_private( object $object, string $method, array $args ) {
	$ref = new ReflectionMethod( $object, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( $object, $args );
}

function pred( string $field, string $op, $value ): array {
	return [ 'type' => 'pred', 'field' => $field, 'op' => $op, 'value' => $value ];
}

it( 'translates an equality predicate', function (): void {
	$rest = new REST_Controller();
	expect( call_private( $rest, 'build_filter', [ pred( 'post_type', 'eq', 'post' ) ] ) )
		->toBe( "post_type = 'post'" );
} );

it( 'translates IN over blog_id', function (): void {
	$rest = new REST_Controller();
	expect( call_private( $rest, 'build_filter', [ pred( 'blog_id', 'in', [ 2, 3 ] ) ] ) )
		->toBe( 'blog_id IN (2, 3)' );
} );

it( 'combines predicates with AND / OR / NOT', function (): void {
	$rest = new REST_Controller();

	$and = [
		'type'  => 'and',
		'items' => [ pred( 'post_type', 'eq', 'post' ), pred( 'blog_id', 'eq', 2 ) ],
	];
	expect( call_private( $rest, 'build_filter', [ $and ] ) )
		->toBe( "(post_type = 'post' AND blog_id = 2)" );

	$not = [ 'type' => 'not', 'item' => pred( 'blog_id', 'eq', 2 ) ];
	expect( call_private( $rest, 'build_filter', [ $not ] ) )
		->toBe( 'NOT (blog_id = 2)' );
} );

it( 'translates a between predicate on post_date', function (): void {
	$rest = new REST_Controller();
	$node = pred( 'post_date', 'between', [ '2020-01-01', '2020-12-31' ] );
	expect( call_private( $rest, 'build_filter', [ $node ] ) )
		->toBe( "(post_date >= '2020-01-01' AND post_date <= '2020-12-31')" );
} );

it( 'rejects predicates on non-allowlisted fields', function (): void {
	$rest = new REST_Controller();
	expect( fn() => call_private( $rest, 'build_filter', [ pred( 'post_content', 'eq', 'x' ) ] ) )
		->toThrow( InvalidArgumentException::class );
} );

it( 'escapes single quotes in string literals', function (): void {
	$rest = new REST_Controller();
	expect( call_private( $rest, 'literal', [ "O'Brien" ] ) )->toBe( "'O''Brien'" );
	expect( call_private( $rest, 'literal', [ 42 ] ) )->toBe( '42' );
} );

it( 'parses sort entries into Loupe strings and a primary key', function (): void {
	$rest = new REST_Controller();
	$out  = call_private( $rest, 'parse_sort', [ [
		[ 'by' => '_score', 'order' => 'desc' ],
		[ 'by' => 'post_date', 'order' => 'asc' ],
	] ] );

	expect( $out['loupe'] )->toBe( [ 'post_date:asc' ] ); // _score is Loupe's default order.
	expect( $out['primary'] )->toBe( [ 'by' => '_score', 'order' => 'desc' ] );
} );

it( 'keeps only allowlisted facet fields', function (): void {
	$rest   = new REST_Controller();
	$facets = call_private( $rest, 'parse_facets', [ [
		[ 'type' => 'terms', 'field' => 'blog_id' ],
		[ 'type' => 'terms', 'field' => 'post_content' ],
		[ 'type' => 'terms', 'field' => 'post_type' ],
	] ] );

	expect( $facets )->toBe( [ 'blog_id', 'post_type' ] );
} );

it( 'normalizes whole-number facet values to integers', function (): void {
	$rest = new REST_Controller();
	$out  = call_private( $rest, 'format_facets', [ [
		'blog_id'   => [ '4.0' => 2, '18.0' => 1 ],
		'post_type' => [ 'post' => 5 ],
	] ] );

	expect( $out['blog_id']['buckets'] )->toBe( [
		[ 'value' => '4', 'count' => 2 ],
		[ 'value' => '18', 'count' => 1 ],
	] );
	expect( $out['post_type']['buckets'] )->toBe( [
		[ 'value' => 'post', 'count' => 5 ],
	] );
} );

it( 'resolves post types, rejecting unknown ones', function (): void {
	Functions\when( 'get_main_site_id' )->justReturn( 1 );
	Functions\when( 'get_site_option' )->justReturn( [ 'post_types' => [ 'post', 'page' ] ] );

	$rest = new REST_Controller();

	expect( call_private( $rest, 'parse_post_types', [ 'all' ] ) )->toBe( [ 'post', 'page' ] );
	expect( call_private( $rest, 'parse_post_types', [ [ 'page' ] ] ) )->toBe( [ 'page' ] );
	expect( call_private( $rest, 'parse_post_types', [ [ 'book' ] ] ) )->toBeInstanceOf( WP_Error::class );
} );

it( 'keeps only inline highlight tags in _formatted, including nested arrays', function (): void {
	// Minimal wp_kses: drop disallowed tags (and script bodies), keep allowed ones bare.
	Functions\when( 'wp_kses' )->alias(
		static function ( string $html, array $allowed ): string {
			$html = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
			return (string) preg_replace_callback(
				'#<(/?)([a-z0-9]+)\b[^>]*>#i',
				static fn( $m ) => isset( $allowed[ strtolower( $m[2] ) ] ) ? '<' . $m[1] . strtolower( $m[2] ) . '>' : '',
				$html
			);
		}
	);
	Functions\when( 'get_post_type_object' )->justReturn( null );

	$result = [
		'hits'              => [
			[
				'id'         => '2_45',
				'blog_id'    => 2,
				'post_type'  => 'post',
				'_formatted' => [
					'post_title'   => '<mark>Hello</mark> <script>alert(1)</script><img src=x onerror=alert(2)>',
					'post_content' => [ '<mark>hi</mark> <a href="javascript:alert(3)">there</a>' ],
				],
			],
		],
		'totalHits'         => 1,
		'processingTimeMs'  => 1,
		'facetDistribution' => [],
	];

	$response  = call_private( new REST_Controller(), 'format_response', [ $result, 1, 10, 0, null ] );
	$formatted = $response->data['hits'][0]['_formatted'];

	expect( $formatted['post_title'] )->toBe( '<mark>Hello</mark> ' );
	expect( $formatted['post_content'][0] )->toBe( '<mark>hi</mark> there' );
} );
