<?php
/**
 * Participation rules.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeCrossSiteSearch\Participation;

/**
 * @param array<string,mixed> $settings
 */
function mock_settings( array $settings ): void {
	Functions\when( 'get_main_site_id' )->justReturn( 1 );
	Functions\when( 'get_site_option' )->justReturn( $settings );
}

function mock_site( array $props ): void {
	Functions\when( 'get_site' )->alias( static fn( $id ) => new WP_Site( array_merge( [ 'blog_id' => $id ], $props ) ) );
}

it( 'includes public sites in "all" mode', function (): void {
	mock_settings( [ 'mode' => 'all', 'sites' => [] ] );
	mock_site( [ 'public' => '1' ] );

	expect( Participation::is_participating( 5 ) )->toBeTrue();
} );

it( 'excludes non-public sites in "all" mode', function (): void {
	mock_settings( [ 'mode' => 'all', 'sites' => [] ] );
	mock_site( [ 'public' => '0' ] );

	expect( Participation::is_participating( 5 ) )->toBeFalse();
} );

it( 'excludes archived, deleted, or spam sites regardless of mode', function (): void {
	mock_settings( [ 'mode' => 'all', 'sites' => [] ] );
	mock_site( [ 'public' => '1', 'archived' => '1' ] );

	expect( Participation::is_participating( 5 ) )->toBeFalse();
} );

it( 'honours an allowlist', function (): void {
	mock_settings( [ 'mode' => 'allowlist', 'sites' => [ 5 ] ] );
	mock_site( [ 'public' => '0' ] ); // allowlist ignores the public flag.

	expect( Participation::is_participating( 5 ) )->toBeTrue();
	expect( Participation::is_participating( 6 ) )->toBeFalse();
} );

it( 'honours a blocklist', function (): void {
	mock_settings( [ 'mode' => 'blocklist', 'sites' => [ 6 ] ] );
	mock_site( [ 'public' => '1' ] );

	expect( Participation::is_participating( 5 ) )->toBeTrue();
	expect( Participation::is_participating( 6 ) )->toBeFalse();
} );

it( 'rejects invalid ids and missing sites', function (): void {
	mock_settings( [ 'mode' => 'all', 'sites' => [] ] );
	Functions\when( 'get_site' )->justReturn( null );

	expect( Participation::is_participating( 0 ) )->toBeFalse();
	expect( Participation::is_participating( 9 ) )->toBeFalse();
} );
