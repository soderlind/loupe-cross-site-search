<?php
/**
 * REST endpoints backing the network settings screen. Registered unconditionally
 * (REST requests are not `is_admin()`), gated by `manage_network_options`.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Settings_REST {

	private const NAMESPACE = 'loupe-cross-site/v1';

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/reindex', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'reindex_status' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reindex_start' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
		] );
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_network_options' );
	}

	public function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response( $this->payload(), 200 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'lcss_invalid_payload', __( 'Invalid settings payload.', 'loupe-cross-site-search' ), [ 'status' => 400 ] );
		}
		update_site_option( Settings::OPTION, Settings::sanitize( $input ) );
		return new \WP_REST_Response( $this->payload(), 200 );
	}

	public function reindex_status(): \WP_REST_Response {
		return new \WP_REST_Response( ( new Reindex_Scheduler() )->status(), 200 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reindex_start() {
		$scheduler = new Reindex_Scheduler();
		if ( ! Reindex_Scheduler::available() ) {
			return new \WP_Error( 'lcss_scheduler_unavailable', __( 'Action Scheduler is not available.', 'loupe-cross-site-search' ), [ 'status' => 503 ] );
		}
		$queued = $scheduler->schedule_all();
		return new \WP_REST_Response( [ 'queued' => $queued ] + $scheduler->status(), 200 );
	}

	/**
	 * Everything the settings UI needs: current settings, the site list, and the
	 * available public post types. Site names use get_blog_option (no switch).
	 *
	 * @return array<string,mixed>
	 */
	private function payload(): array {
		$sites = [];
		foreach ( get_sites( [ 'number' => 0, 'orderby' => 'path' ] ) as $site ) {
			$blog_id = (int) $site->blog_id;
			$sites[] = [
				'id'            => $blog_id,
				'name'          => (string) get_blog_option( $blog_id, 'blogname', '' ),
				'url'           => untrailingslashit( $site->domain . $site->path ),
				'public'        => (int) $site->public === 1,
				'archived'      => (int) $site->archived === 1,
				'participating' => Participation::is_participating( $blog_id ),
			];
		}

		$post_types = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
			$post_types[] = [ 'slug' => $pt->name, 'label' => $pt->labels->singular_name ];
		}

		return [
			'settings'  => Settings::get(),
			'sites'     => $sites,
			'postTypes' => $post_types,
		];
	}
}
