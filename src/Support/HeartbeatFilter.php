<?php
namespace BeltoftMediaOffload\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pass-through stream filter: hands every bucket on unchanged and calls
 * Heartbeat::beat() as data is read (see Heartbeat::attach()).
 */
class HeartbeatFilter extends \php_user_filter {

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- signature defined by php_user_filter.
	public function filter( $in, $out, &$consumed, bool $closing ): int {
		// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the standard php_user_filter bucket loop.
		while ( $bucket = stream_bucket_make_writeable( $in ) ) {
			$consumed += $bucket->datalen;
			stream_bucket_append( $out, $bucket );
		}
		Heartbeat::beat();
		return PSFS_PASS_ON;
	}
}
