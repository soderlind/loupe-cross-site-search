<?php
/**
 * Mirror: upserts after the save completes and re-evaluates posts whose
 * Excluded-post flag changes.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeCrossSiteSearch\Combined_Index;
use Soderlind\Plugin\LoupeCrossSiteSearch\Document_Builder;
use Soderlind\Plugin\LoupeCrossSiteSearch\Mirror;

beforeEach( function (): void {
	$prop = new ReflectionProperty( Document_Builder::class, 'indexer' );
	$prop->setAccessible( true );
	$prop->setValue( null, null );

	Functions\when( 'get_main_site_id' )->justReturn( 1 );
	Functions\when( 'get_site_option' )->justReturn( [ 'post_types' => [ 'post' ] ] );
	Functions\when( 'wp_is_post_revision' )->justReturn( false );
	Functions\when( 'wp_is_post_autosave' )->justReturn( false );
	Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'public' => true ] );
	Functions\when( 'get_bloginfo' )->justReturn( 'Site A' );
	Functions\when( 'get_permalink' )->justReturn( 'https://a.test/hello' );
	Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );

	$this->index  = Mockery::mock( Combined_Index::class );
	$this->mirror = new Mirror( $this->index, [ 'post' ], 2 );
	$this->post   = new WP_Post( [ 'ID' => 45, 'post_type' => 'post', 'post_status' => 'publish' ] );
	$posts        = [ 45 => $this->post ];
	$this->posts  = &$posts;
	Functions\when( 'get_post' )->alias( static function ( $id ) use ( &$posts ) {
		return $posts[ (int) $id ] ?? null;
	} );
} );

afterEach( function (): void {
	$this->addToAssertionCount( $this->index->mockery_getExpectationCount() );
} );

function veto_everything(): void {
	Functions\when( 'apply_filters' )->alias(
		static fn( $tag, $value ) => 'loupe_search_is_indexable' === $tag ? false : $value
	);
}

it( 'upserts a published post once the save is complete', function (): void {
	$this->index->shouldReceive( 'add_document' )->once()->with( 'post', Mockery::on( fn( $d ) => '2_45' === $d['id'] ) );
	$this->mirror->on_after_insert( 45, $this->post );
} );

it( 'removes a vetoed post instead of mirroring it', function (): void {
	veto_everything();
	$this->index->shouldReceive( 'delete_document' )->once()->with( 'post', '2_45' );
	$this->index->shouldNotReceive( 'add_document' );
	$this->mirror->on_after_insert( 45, $this->post );
} );

it( 'ignores drafts and uncovered post types after save', function (): void {
	$this->posts[45] = new WP_Post( [ 'ID' => 45, 'post_type' => 'post', 'post_status' => 'draft' ] );
	$this->posts[46] = new WP_Post( [ 'ID' => 46, 'post_type' => 'page', 'post_status' => 'publish' ] );
	$this->index->shouldNotReceive( 'add_document', 'delete_document' );
	$this->mirror->on_after_insert( 45, $this->posts[45] );
	$this->mirror->on_after_insert( 46, $this->posts[46] );
} );

it( 'reloads the post so a stale published snapshot is not re-added', function (): void {
	// A nested wp_update_post() in save_post moved the post to draft.
	$this->posts[45] = new WP_Post( [ 'ID' => 45, 'post_type' => 'post', 'post_status' => 'draft' ] );
	$this->index->shouldNotReceive( 'add_document', 'delete_document' );
	$this->mirror->on_after_insert( 45, $this->post );
} );

it( 'removes a post that leaves the published state', function (): void {
	$this->index->shouldReceive( 'delete_document' )->once()->with( 'post', '2_45' );
	$this->mirror->on_transition( 'draft', 'publish', $this->post );
} );

it( 'leaves publish-to-publish updates to the after-save upsert', function (): void {
	$this->index->shouldNotReceive( 'add_document', 'delete_document' );
	$this->mirror->on_transition( 'publish', 'publish', $this->post );
} );

it( 're-evaluates a published post when its Excluded-post flag changes', function (): void {
	veto_everything();
	Functions\when( 'get_post' )->justReturn( $this->post );
	$this->index->shouldReceive( 'delete_document' )->once()->with( 'post', '2_45' );
	$this->mirror->on_meta_change( 1, 45, '_loupe_search_exclude' );
} );

it( 'ignores unrelated meta and posts being deleted', function (): void {
	Functions\when( 'get_post' )->justReturn( $this->post );
	$this->index->shouldNotReceive( 'add_document', 'delete_document' );
	$this->mirror->on_meta_change( 1, 45, '_edit_lock' );

	$this->mirror->mark_deleting( 45 );
	$this->mirror->on_meta_change( [ 1 ], 45, '_loupe_search_exclude' );
} );
