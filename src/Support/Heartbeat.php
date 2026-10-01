<?php
namespace BeltoftMediaOffload\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a lock alive during one long transfer. A request holding a Lock
 * sets a callback (e.g. Lock::refresh); uploads and downloads call beat()
 * as data moves, which runs the callback at most every INTERVAL seconds.
 * Without it a single multi-gigabyte upload would outlive the lock's TTL
 * and let a second request start the same work.
 */
class Heartbeat {

	const INTERVAL = 15;

	/** Stream filter name, registered once (see HeartbeatFilter). */
	const FILTER = 'beltoft_media_offload.heartbeat';

	/** @var callable|null */
	private static $callback = null;

	private static $last = 0;

	/**
	 * Runs $work with $callback added to the heartbeat, restoring the
	 * previous one afterwards. Nested calls chain: an outer lock (e.g. the
	 * bulk lock) keeps beating while an inner one (the attachment lock) is
	 * held.
	 *
	 * @return mixed $work's return value.
	 */
	public static function during( callable $callback, callable $work ) {
		$previous       = self::$callback;
		self::$callback = null === $previous ? $callback : function () use ( $previous, $callback ) {
			call_user_func( $previous );
			call_user_func( $callback );
		};
		// Only the outermost call starts the interval; a nested one must not
		// delay the outer lock's next beat.
		if ( null === $previous ) {
			self::$last = time();
		}
		try {
			return $work();
		} finally {
			self::$callback = $previous;
		}
	}

	public static function active() {
		return null !== self::$callback;
	}

	public static function beat() {
		if ( null !== self::$callback && time() - self::$last >= self::INTERVAL ) {
			self::$last = time();
			call_user_func( self::$callback );
		}
	}

	/**
	 * Adds a pass-through read filter that beats as the SDK reads $stream.
	 *
	 * @param resource $stream Open file stream.
	 */
	public static function attach( $stream ) {
		if ( ! self::active() || ! is_resource( $stream ) ) {
			return;
		}
		if ( ! in_array( self::FILTER, stream_get_filters(), true ) ) {
			stream_filter_register( self::FILTER, HeartbeatFilter::class );
		}
		stream_filter_append( $stream, self::FILTER, STREAM_FILTER_READ );
	}
}
