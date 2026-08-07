<?php
/**
 * Combined-index path resolution.
 */

declare(strict_types=1);

use Soderlind\Plugin\LoupeCrossSiteSearch\Combined_Index;

it( 'resolves the network-global base path', function (): void {
	expect( Combined_Index::base_path() )->toBe( WP_CONTENT_DIR . '/loupe-cross-site-db' );
} );
