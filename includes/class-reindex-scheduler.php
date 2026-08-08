<?php
/**
 * Background reindex via Action Scheduler.
 *
 * A "Reindex now" action queues one async job per participating site on the hub
 * site's queue. Each job reindexes its site (switching into that site's context
 * for the pass). This is the convenient, reliable admin path; the WP-CLI
 * `reindex` command remains the fully faithful, per-process option.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Reindex_Scheduler {

	public const HOOK  = 'lcss_reindex_site';
	public const GROUP = 'loupe-cross-site';

	private const STATUS_OPTION = 'lcss_reindex_status';

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run_site' ], 10, 1 );
	}

	public static function available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Queue an async reindex job for every participating site.
	 *
	 * @return int Number of jobs queued.
	 */
	public function schedule_all(): int {
		if ( ! self::available() ) {
			return 0;
		}

		$site_ids = Participation::get_participating_site_ids();
		$queued   = 0;
		foreach ( $site_ids as $blog_id ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::HOOK, [ 'blog_id' => $blog_id ], self::GROUP ) ) {
				continue;
			}
			as_enqueue_async_action( self::HOOK, [ 'blog_id' => $blog_id ], self::GROUP );
			$queued++;
		}

		update_site_option( self::STATUS_OPTION, [
			'queued'      => count( $site_ids ),
			'started_at'  => time(),
			'finished_at' => 0,
		] );

		return $queued;
	}

	/**
	 * Action handler: reindex one site.
	 *
	 * @param int $blog_id Site ID (from the action args).
	 */
	public function run_site( $blog_id ): void {
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 || ! Participation::is_participating( $blog_id ) ) {
			$this->mark_progress();
			return;
		}

		$switched = false;
		if ( get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			$index = new Combined_Index( Settings::get_post_types(), Settings::get_language() );
			Reindexer::run( $blog_id, $index );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[loupe-cross-site] scheduled reindex failed for blog %d: %s', $blog_id, $e->getMessage() ) );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
			$this->mark_progress();
		}
	}

	/**
	 * Current reindex status for the UI.
	 *
	 * @return array{available:bool,queued:int,pending:int,started_at:int,finished_at:int}
	 */
	public function status(): array {
		$saved = get_site_option( self::STATUS_OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return [
			'available'   => self::available(),
			'queued'      => (int) ( $saved['queued'] ?? 0 ),
			'pending'     => $this->pending_count(),
			'started_at'  => (int) ( $saved['started_at'] ?? 0 ),
			'finished_at' => (int) ( $saved['finished_at'] ?? 0 ),
		];
	}

	private function mark_progress(): void {
		if ( 0 !== $this->pending_count() ) {
			return;
		}
		$saved = get_site_option( self::STATUS_OPTION, [] );
		if ( is_array( $saved ) ) {
			$saved['finished_at'] = time();
			update_site_option( self::STATUS_OPTION, $saved );
		}
	}

	private function pending_count(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$count = 0;
		foreach ( [ \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ] as $status ) {
			$ids = as_get_scheduled_actions( [
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => $status,
				'per_page' => -1,
			], 'ids' );
			$count += count( (array) $ids );
		}
		return $count;
	}
}
