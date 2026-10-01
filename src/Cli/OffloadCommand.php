<?php
namespace BeltoftMediaOffload\Cli;

use BeltoftMediaOffload\Media\LocalFetcher;
use BeltoftMediaOffload\Media\Offloader;
use BeltoftMediaOffload\Media\ServerRule;
use BeltoftMediaOffload\Support\AttachmentLock;
use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Manage media offload from the command line.
 */
class OffloadCommand {

	public static function register() {
		\WP_CLI::add_command( 'media-offload', __CLASS__ );
	}

	/**
	 * Shows what's pending and whether the server rule works.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-offload status
	 */
	public function status( $args, $assoc_args ) {
		\WP_CLI::log( sprintf( 'Pending: %d', Offloader::count_unoffloaded() ) );
		\WP_CLI::log( sprintf( 'Failed bucket deletions queued for retry: %d', count( Offloader::orphans() ) ) );
		\WP_CLI::log( sprintf( 'Local deletes waiting: %d', Offloader::count_deferred() ) );
		$rule = ServerRule::state();
		\WP_CLI::log( sprintf( 'Server rule: %s', ServerRule::is_verified() ? 'working' : ( '' !== $rule['message'] ? $rule['message'] : 'not checked' ) ) );
		if ( '1' === (string) Options::get( 'delete_local' ) && ! ServerRule::is_verified() ) {
			\WP_CLI::warning( '"Delete local files" is paused until the server rule works (wp media-offload check-server).' );
		}
		$excluded = Offloader::offloaded_excluded_ids();
		if ( ! empty( $excluded ) ) {
			\WP_CLI::warning( sprintf( '%d attachment(s) under an excluded path are still in the bucket: %s. Run `wp media-offload unoffload --excluded` to remove them from it.', count( $excluded ), implode( ', ', $excluded ) ) );
		}
	}

	/**
	 * Writes the Apache rule (on Apache) and checks that files missing
	 * locally are served from the bucket. "Delete local files" only acts
	 * while this check passes. Prints the nginx snippet otherwise.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-offload check-server
	 *
	 * @subcommand check-server
	 */
	public function check_server( $args, $assoc_args ) {
		$state = ServerRule::check();
		if ( $state['ok'] ) {
			\WP_CLI::success( 'Server rule works: files missing locally are served from ' . $state['base'] );
			return;
		}
		if ( ! ServerRule::is_apache() ) {
			\WP_CLI::log( "Add this to the site's nginx server block, reload nginx, then run this command again:\n\n" . ServerRule::nginx_snippet() . "\n" );
		}
		\WP_CLI::error( $state['message'] );
	}

	/**
	 * Offloads all pending media in batches.
	 *
	 * [--batch=<number>]
	 * : Attachments per batch. Default 20.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-offload run --batch=50
	 */
	public function run( $args, $assoc_args ) {
		$batch    = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 20;
		$total_ok = 0;
		$failed   = array();

		// Failed IDs are excluded from later batches, so the loop ends: each
		// batch either offloads something or grows the exclude list.
		while ( true ) {
			$ids = Offloader::unoffloaded_ids( $batch, 0, $failed );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				$result = Offloader::offload( $id );
				if ( is_wp_error( $result ) && 'bmo_excluded' === $result->get_error_code() ) {
					\WP_CLI::log( sprintf( 'Attachment %d skipped: %s', $id, $result->get_error_message() ) );
					continue;
				}
				if ( is_wp_error( $result ) ) {
					\WP_CLI::warning( sprintf( 'Attachment %d failed: %s', $id, $result->get_error_message() ) );
					$failed[] = (int) $id;
					continue;
				}
				++$total_ok;
			}
		}

		if ( ! empty( $failed ) ) {
			\WP_CLI::warning( sprintf( '%d attachment(s) failed and were skipped: %s', count( $failed ), implode( ', ', $failed ) ) );
		}
		\WP_CLI::success( sprintf( 'Offloaded %d attachment(s).', $total_ok ) );
	}

	/**
	 * Uploads files of already-offloaded attachments that aren't in the
	 * bucket yet, e.g. .webp/.avif siblings a converter wrote directly to
	 * disk. Runs automatically after `wp beltoft-webp backfill`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-offload resync
	 */
	public function resync( $args, $assoc_args ) {
		$result = Offloader::resync_all_offloaded();
		foreach ( $result['errors'] as $error ) {
			\WP_CLI::warning( $error );
		}
		\WP_CLI::success( sprintf( 'Checked %d offloaded attachment(s), updated %d.', $result['checked'], $result['updated'] ) );
	}

	/**
	 * Retries deleting bucket objects whose removal failed earlier. Also
	 * retried automatically every hour while any are queued.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-offload retry-deletes
	 *
	 * @subcommand retry-deletes
	 */
	public function retry_deletes( $args, $assoc_args ) {
		// Batches until the queue is empty or stops shrinking (still failing).
		$deleted = 0;
		do {
			$before   = count( Offloader::orphans() );
			$result   = Offloader::retry_orphans( 0 );
			$deleted += $result['deleted'];
		} while ( $result['remaining'] > 0 && $result['remaining'] < $before );

		if ( $result['remaining'] > 0 ) {
			\WP_CLI::warning( sprintf( 'Deleted %d object(s); %d still failing and remain queued.', $deleted, $result['remaining'] ) );
			return;
		}
		\WP_CLI::success( sprintf( 'Deleted %d object(s); nothing left queued.', $deleted ) );
	}

	/**
	 * Completes local deletes that wait for another plugin (e.g.
	 * beltoft-webp) or for files downloaded back. Runs automatically every
	 * hour while any wait.
	 *
	 * [--force]
	 * : Also finish attachments still reported as pending, regardless of
	 * how long they have been waiting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-offload finish-deferred --force
	 *
	 * @subcommand finish-deferred
	 */
	public function finish_deferred( $args, $assoc_args ) {
		$result = Offloader::sweep_deferred_deletes( ! empty( $assoc_args['force'] ), 0 );
		foreach ( $result['errors'] as $error ) {
			\WP_CLI::warning( $error );
		}
		\WP_CLI::success( sprintf( 'Finished %d attachment(s); %d still waiting.', $result['finished'], Offloader::count_deferred() ) );
	}

	/**
	 * Downloads offloaded attachments' files back to local disk and keeps
	 * them there (they stay in the bucket too).
	 *
	 * [<id>...]
	 * : Attachment IDs to restore.
	 *
	 * [--all]
	 * : Restore every offloaded attachment.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-offload restore 123 456
	 *     wp media-offload restore --all
	 */
	public function restore( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['all'] ) ) {
			$args = Offloader::offloaded_ids();
		}
		if ( empty( $args ) ) {
			\WP_CLI::error( 'Give attachment IDs or --all.' );
		}
		if ( Options::delete_local_enabled() ) {
			\WP_CLI::warning( '"Delete local files" is on: new uploads are still deleted locally. Turn it off to keep everything local.' );
		}

		$ok = 0;
		foreach ( array_map( 'absint', $args ) as $id ) {
			// Download and drop any waiting local delete as one unit, so the
			// sweep can't remove the files in between.
			$result = AttachmentLock::run(
				$id,
				function () use ( $id ) {
					$fetched = LocalFetcher::ensure_local( $id, true, 0 );
					if ( ! is_wp_error( $fetched ) ) {
						delete_post_meta( $id, Offloader::DEFERRED_META );
					}
					return $fetched;
				},
				60
			);
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( 'Attachment %d: %s', $id, $result->get_error_message() ) );
				continue;
			}
			++$ok;
		}
		\WP_CLI::success( sprintf( 'Restored %d attachment(s) locally.', $ok ) );
	}

	/**
	 * Takes attachments out of the bucket for good: brings their files back
	 * to local disk, deletes the bucket copies and never offloads them
	 * again. For files that must not be public, e.g. ones under a path
	 * excluded after they were offloaded. Links keep working: stored
	 * content only holds local URLs.
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--excluded]
	 * : Every offloaded attachment under an excluded path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-offload unoffload --excluded
	 *     wp media-offload unoffload 123
	 */
	public function unoffload( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['excluded'] ) ) {
			$args = Offloader::offloaded_excluded_ids();
		}
		$ids = array_values( array_filter( array_map( 'absint', (array) $args ), array( Offloader::class, 'is_offloaded' ) ) );
		if ( empty( $ids ) ) {
			\WP_CLI::success( 'Nothing to do.' );
			return;
		}

		$ok     = 0;
		$queued = 0;
		foreach ( $ids as $id ) {
			$twins = array_diff( array_filter( Offloader::twins( $id ), array( Offloader::class, 'is_offloaded' ) ), $ids );
			// One unit under the attachment lock: no sweep, resync or upload
			// can touch this attachment's files or record in between.
			$result = AttachmentLock::run(
				$id,
				function () use ( $id ) {
					if ( ! Offloader::is_offloaded( $id ) ) {
						return 0;
					}
					// First, so nothing offloads or deletes locally meanwhile.
					update_post_meta( $id, Offloader::KEEP_LOCAL_META, '1' );
					$fetched = LocalFetcher::ensure_local( $id, true, 0 );
					if ( ! is_wp_error( $fetched ) && ! LocalFetcher::all_local( $id ) ) {
						$fetched = new \WP_Error( 'bmo_not_local', 'not every file is on local disk' );
					}
					if ( is_wp_error( $fetched ) ) {
						// Never remove the bucket copy without a complete local
						// one. Still offloaded: let the sweep clean up whatever
						// did get downloaded.
						delete_post_meta( $id, Offloader::KEEP_LOCAL_META );
						if ( Options::delete_local_enabled() ) {
							Offloader::defer_local_delete( $id, true );
						}
						return $fetched;
					}
					if ( ! AttachmentLock::still_held( $id ) ) {
						delete_post_meta( $id, Offloader::KEEP_LOCAL_META );
						return new \WP_Error( 'bmo_busy', 'lost the attachment lock; bucket copies kept' );
					}
					delete_post_meta( $id, Offloader::DEFERRED_META );
					return Offloader::delete_offloaded( $id );
				},
				60
			);
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( 'Attachment %d: %s. Left offloaded.', $id, $result->get_error_message() ) );
				continue;
			}
			if ( ! empty( $twins ) ) {
				\WP_CLI::warning( sprintf( 'Attachment %d shares its files with attachment(s) %s (translations or copies) that are offloaded too; the bucket copies stay until those are unoffloaded or deleted.', $id, implode( ', ', $twins ) ) );
			}
			$queued += (int) $result;
			++$ok;
		}

		if ( $ok > 0 ) {
			// Cached pages may link to the bucket copies just deleted.
			\BeltoftMediaOffload\Support\Cache::purge();
		}
		if ( $queued > 0 ) {
			\WP_CLI::warning( sprintf( '%d bucket object(s) could not be deleted right now and are queued; deletion is retried hourly, or run `wp media-offload retry-deletes`.', $queued ) );
		}
		\WP_CLI::success( sprintf( '%d attachment(s) are local-only now and will not be offloaded again.', $ok ) );
	}
}
