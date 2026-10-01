<?php
namespace BeltoftMediaOffload\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One lock per attachment around everything that changes its files or its
 * offload record (offload, resync, deferred local delete, bucket delete,
 * downloading files back, unoffload), so two requests — say the cron
 * sweep and `wp media-offload unoffload` — can never interleave on the same
 * attachment. Reentrant within a request (nested calls just run), kept
 * alive during long transfers by Heartbeat, and backed by Support\Lock.
 */
class AttachmentLock {

	const PREFIX = 'bmo_att_lock_';
	const TTL    = 300;

	/** Seconds to wait for another request to finish before giving up. */
	const WAIT = 10;

	/** @var array<int,string> attachment ID => owner token, while held. */
	private static $held = array();

	/**
	 * Refreshes the lock and says whether this request still holds it —
	 * checked right before anything destructive (deleting local files or
	 * bucket copies), in case a stalled request lost it to a takeover.
	 * True outside a lock (nothing to lose).
	 */
	public static function still_held( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! isset( self::$held[ $attachment_id ] ) ) {
			return true;
		}
		return Lock::refresh( self::PREFIX . $attachment_id, self::$held[ $attachment_id ] );
	}

	/**
	 * Runs $work while holding the attachment's lock.
	 *
	 * @return mixed|\WP_Error $work's return value, or a `bmo_busy` error if
	 *                         another request kept the lock for $wait seconds.
	 */
	public static function run( $attachment_id, callable $work, $wait = self::WAIT ) {
		$attachment_id = (int) $attachment_id;
		if ( isset( self::$held[ $attachment_id ] ) ) {
			return $work();
		}

		$name     = self::PREFIX . $attachment_id;
		$deadline = microtime( true ) + $wait;
		while ( false === ( $token = Lock::acquire( $name, self::TTL ) ) ) {
			if ( microtime( true ) >= $deadline ) {
				return new \WP_Error( 'bmo_busy', sprintf( 'Attachment %d is being processed by another request; try again shortly.', $attachment_id ) );
			}
			usleep( 250000 );
		}

		self::$held[ $attachment_id ] = $token;

		// Anything this request read about the attachment before it waited
		// may have been changed by the request that held the lock: drop the
		// cached meta so the work below reads the current record.
		// Only this attachment's entries: clean_post_cache() would also flush
		// every cached query site-wide and trigger page-cache purges.
		wp_cache_delete( $attachment_id, 'post_meta' );
		wp_cache_delete( $attachment_id, 'posts' );

		try {
			return Heartbeat::during(
				function () use ( $name, $token ) {
					Lock::refresh( $name, $token );
				},
				$work
			);
		} finally {
			Lock::release( $name, $token );
			unset( self::$held[ $attachment_id ] );
		}
	}
}
