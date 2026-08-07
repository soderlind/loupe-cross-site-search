<?php
/**
 * Settings defaults and sanitization.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeCrossSiteSearch\Settings;

it( 'falls back to sensible defaults when nothing is saved', function (): void {
	Functions\when( 'get_main_site_id' )->justReturn( 7 );
	Functions\when( 'get_site_option' )->justReturn( [] );

	$settings = Settings::get();

	expect( $settings['hub_blog_id'] )->toBe( 7 );
	expect( $settings['mode'] )->toBe( 'all' );
	expect( $settings['post_types'] )->toBe( [ 'post', 'page' ] );
	expect( $settings['language'] )->toBe( 'en' );
} );

it( 'sanitizes stored values', function (): void {
	Functions\when( 'get_main_site_id' )->justReturn( 1 );
	Functions\when( 'get_site_option' )->justReturn( [
		'hub_blog_id' => '3',
		'mode'        => 'nonsense',
		'sites'       => [ '2', '5', 'x' ],
		'language'    => 'NB',
		'post_types'  => [ 'post', 'book' ],
	] );

	$settings = Settings::get();

	expect( $settings['hub_blog_id'] )->toBe( 3 );
	expect( $settings['mode'] )->toBe( 'all' ); // invalid mode reset to default.
	expect( $settings['sites'] )->toBe( [ 2, 5, 0 ] );
	expect( $settings['post_types'] )->toBe( [ 'post', 'book' ] );
} );

it( 'normalizes the language to a two-letter code', function (): void {
	Functions\when( 'get_main_site_id' )->justReturn( 1 );
	Functions\when( 'get_site_option' )->justReturn( [ 'language' => 'nb' ] );
	expect( Settings::get_language() )->toBe( 'nb' );

	Functions\when( 'get_site_option' )->justReturn( [ 'language' => 'english' ] );
	expect( Settings::get_language() )->toBe( 'en' ); // invalid -> default.
} );

it( 'never returns an empty post-type set', function (): void {
	Functions\when( 'get_main_site_id' )->justReturn( 1 );
	Functions\when( 'get_site_option' )->justReturn( [ 'post_types' => [] ] );

	expect( Settings::get_post_types() )->toBe( [ 'post', 'page' ] );
} );
