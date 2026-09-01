<?php
/**
 * Abilities: tier-2 per-object authorization for loupe-cross-site/get-post.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeCrossSiteSearch\Abilities;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Utils;

beforeEach( function (): void {
	WP_Loupe_Utils::$viewable = true;

	Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
	Functions\when( 'switch_to_blog' )->justReturn( true );
	Functions\when( 'restore_current_blog' )->justReturn( true );
	Functions\when( 'get_site' )->justReturn( (object) [ 'blog_id' => 2 ] );
} );

it( 'rejects a missing blog_id or post id with a 400 WP_Error', function (): void {
	$error = Abilities::execute_get_post( [ 'blog_id' => 0, 'id' => 0 ] );

	expect( $error )->toBeInstanceOf( WP_Error::class );
	expect( $error->get_error_code() )->toBe( 'invalid_id' );
	expect( $error->data['status'] )->toBe( 400 );
} );

it( 'returns a 404 WP_Error for an unknown site', function (): void {
	Functions\when( 'get_site' )->justReturn( null );

	$error = Abilities::execute_get_post( [ 'blog_id' => 999, 'id' => 45 ] );

	expect( $error )->toBeInstanceOf( WP_Error::class );
	expect( $error->get_error_code() )->toBe( 'post_not_found' );
	expect( $error->data['status'] )->toBe( 404 );
} );

it( 'returns a 404 WP_Error when the target post is not publicly viewable', function (): void {
	WP_Loupe_Utils::$viewable = false;

	$error = Abilities::execute_get_post( [ 'blog_id' => 2, 'id' => 45 ] );

	expect( $error )->toBeInstanceOf( WP_Error::class );
	expect( $error->get_error_code() )->toBe( 'post_not_found' );
	expect( $error->data['status'] )->toBe( 404 );
} );

it( 'returns post data when the target post is publicly viewable', function (): void {
	Functions\when( 'get_post' )->justReturn( new WP_Post( [ 'ID' => 45, 'post_type' => 'post', 'post_author' => 7 ] ) );
	Functions\when( 'get_bloginfo' )->justReturn( 'Site B' );
	Functions\when( 'get_the_title' )->justReturn( 'Hello' );
	Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
	Functions\when( 'get_the_excerpt' )->justReturn( 'Excerpt' );
	Functions\when( 'get_permalink' )->justReturn( 'https://b.test/hello' );
	Functions\when( 'get_the_date' )->justReturn( '2020-01-01T00:00:00+00:00' );
	Functions\when( 'get_the_author_meta' )->justReturn( 'Jane' );

	$data = Abilities::execute_get_post( [ 'blog_id' => 2, 'id' => 45 ] );

	expect( $data )->toBeArray();
	expect( $data['id'] )->toBe( 45 );
	expect( $data['blog_id'] )->toBe( 2 );
	expect( $data['blog_name'] )->toBe( 'Site B' );
	expect( $data['title'] )->toBe( 'Hello' );
	expect( $data['url'] )->toBe( 'https://b.test/hello' );
	expect( $data['author'] )->toBe( 'Jane' );
} );
