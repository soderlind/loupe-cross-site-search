<?php
/**
 * Document builder: guards, projection, and cross-site attribution.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeCrossSiteSearch\Document_Builder;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Indexer;

beforeEach( function (): void {
	// Reset the cached indexer between tests.
	$prop = new ReflectionProperty( Document_Builder::class, 'indexer' );
	$prop->setAccessible( true );
	$prop->setValue( null, null );

	Functions\when( 'get_main_site_id' )->justReturn( 1 );
	Functions\when( 'get_site_option' )->justReturn( [ 'post_types' => [ 'post' ] ] );
	Functions\when( 'wp_is_post_revision' )->justReturn( false );
	Functions\when( 'wp_is_post_autosave' )->justReturn( false );
	Functions\when( 'get_bloginfo' )->justReturn( 'Site A' );
	Functions\when( 'get_permalink' )->justReturn( 'https://a.test/hello' );
	Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
} );

it( 'builds a composite document id', function (): void {
	expect( Document_Builder::document_id( 2, 45 ) )->toBe( '2_45' );
} );

it( 'returns null for non-published posts', function (): void {
	$post = new WP_Post( [ 'ID' => 45, 'post_status' => 'draft' ] );
	expect( Document_Builder::build( $post, 2 ) )->toBeNull();
} );

it( 'returns null for revisions and autosaves', function (): void {
	Functions\when( 'wp_is_post_revision' )->justReturn( true );
	$post = new WP_Post( [ 'ID' => 45, 'post_status' => 'publish' ] );
	expect( Document_Builder::build( $post, 2 ) )->toBeNull();
} );

it( 'returns null for password-protected posts', function (): void {
	$post = new WP_Post( [ 'ID' => 45, 'post_status' => 'publish', 'post_password' => 'secret' ] );
	expect( Document_Builder::build( $post, 2 ) )->toBeNull();
} );

it( 'projects the prepared document and adds attribution', function (): void {
	WP_Loupe_Indexer::$next_document = [
		'post_title'   => 'Prepared Title',
		'post_content' => 'Prepared content',
		'post_excerpt' => 'Prepared excerpt',
		'post_date'    => '2020-01-01 00:00:00',
	];

	$post = new WP_Post( [ 'ID' => 45, 'post_type' => 'post', 'post_status' => 'publish' ] );
	$doc  = Document_Builder::build( $post, 2 );

	expect( $doc )->toBeArray();
	expect( $doc['id'] )->toBe( '2_45' );
	expect( $doc['blog_id'] )->toBe( 2 );
	expect( $doc['blog_name'] )->toBe( 'Site A' );
	expect( $doc['url'] )->toBe( 'https://a.test/hello' );
	expect( $doc['post_type'] )->toBe( 'post' );
	expect( $doc['post_title'] )->toBe( 'Prepared Title' );
	expect( $doc['post_content'] )->toBe( 'Prepared content' );
	expect( $doc['post_excerpt'] )->toBe( 'Prepared excerpt' );
} );

it( 'falls back to raw post fields when preparation yields nothing', function (): void {
	WP_Loupe_Indexer::$next_document = [];

	$post = new WP_Post( [
		'ID'           => 9,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Raw Title',
		'post_content' => '<p>Raw body</p>',
		'post_excerpt' => 'Raw excerpt',
		'post_date'    => '2021-02-02 00:00:00',
	] );

	$doc = Document_Builder::build( $post, 3 );

	expect( $doc['id'] )->toBe( '3_9' );
	expect( $doc['post_title'] )->toBe( 'Raw Title' );
	expect( $doc['post_content'] )->toBe( 'Raw body' ); // tags stripped.
	expect( $doc['post_date'] )->toBe( '2021-02-02 00:00:00' );
} );
