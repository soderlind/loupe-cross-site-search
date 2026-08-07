<?php
/**
 * Pest configuration: Brain Monkey lifecycle plus constant WordPress stubs that
 * every unit test relies on. Test-specific functions are mocked per test.
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;

uses()
	->beforeEach( function (): void {
		Monkey\setUp();

		Functions\stubs( [
			'__'           => static fn( $text ) => $text,
			'esc_html__'   => static fn( $text ) => $text,
			'esc_html'     => static fn( $text ) => $text,
			'esc_attr'     => static fn( $text ) => $text,
			'esc_url'      => static fn( $text ) => $text,
			'sanitize_key' => static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ),
		] );

		// apply_filters returns the value being filtered (2nd argument, 1-based).
		Functions\when( 'apply_filters' )->returnArg( 2 );
	} )
	->afterEach( function (): void {
		Monkey\tearDown();
	} )
	->in( 'Unit' );
