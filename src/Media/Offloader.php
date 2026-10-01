<?php
namespace BeltoftMediaOffload\Media;

use BeltoftMediaOffload\S3\Client as S3Client;
use BeltoftMediaOffload\Support\AttachmentLock;
use BeltoftMediaOffload\Support\Heartbeat;
use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Core offload logic, shared by the upload hooks, the bulk-offload admin
 * page and the WP-CLI command. The only place local files and bucket
 * objects are deleted: local files only after every object has been
 * uploaded and verified, and only while the server rule works (see
 * Options::delete_local_enabled()).
 */
class Offloader {

	const META_KEY = '_bmo_offload_data';

	/**
	 * Post meta set on attachments under an excluded path (see
	 * Options::excluded_paths()), so bulk/CLI queries don't keep returning
	 * them.
	 */
	const SKIP_META = '_bmo_skip';

	/**
	 * Post meta on attachments taken out of the bucket with `wp media-offload
	 * unoffload`: they're never offloaded again.
	 */
	const KEEP_LOCAL_META = '_bmo_keep_local';

	/**
	 * Non-autoloaded option holding bucket objects whose deletion failed
	 * (bucket unreachable, credentials rotated, ...) after their record was
	 * dropped. Retried by RETRY_HOOK and `wp media-offload retry-deletes`.
	 */
	const ORPHANS_OPTION = 'bmo_orphaned_objects';
	const RETRY_HOOK     = 'bmo_retry_orphans';

	/**
	 * Post meta (unix time) on an attachment whose local delete waits:
	 * another plugin is still generating files for it in the background
	 * (see should_defer_local_delete()), or its files were downloaded back
	 * (see LocalFetcher). Cleared by finish_deferred_delete().
	 */
	const DEFERRED_META = '_bmo_local_delete_pending';
	const SWEEP_HOOK    = 'bmo_deferred_delete_sweep';

	/**
	 * How long the sweep keeps waiting on an attachment still reported as
	 * pending before deleting its local files anyway.
	 */
	const MAX_DEFER_SECONDS = 2 * DAY_IN_SECONDS;

	/** Attachments per sweep query. */
	const SWEEP_BATCH = 50;

	/**
	 * Minimum seconds a postponed delete waits (files downloaded back for
	 * editing shouldn't vanish mid-edit).
	 */
	const MIN_LOCAL_SECONDS = 600;

	/**
	 * Failed deletions retried per run, and how many failures in a row end
	 * a run early (the bucket is most likely still unreachable).
	 */
	const ORPHAN_BATCH           = 200;
	const ORPHAN_MAX_CONSECUTIVE = 3;

	/** Seconds a cron run may spend before handing the rest to the next. */
	const CRON_TIME_BUDGET = 20;

	/**
	 * WordPress's own derived-file suffixes: sizes (-300x200), the big-image
	 * copy (-scaled), rotated originals (-rotated), edited images
	 * (-e1234567890123), optionally followed by a size.
	 */
	const DERIVED_SUFFIX_PATTERN = '/^(?:\\d+x\\d+|scaled|rotated|e\\d{13})(?:-\\d+x\\d+)?$/';

	/**
	 * Format siblings some plugins (e.g. beltoft-webp) place next to an
	 * image, named by appending: photo.jpg -> photo.jpg.webp.
	 */
	const SIBLING_FORMAT_EXTENSIONS = array( 'avif', 'webp' );

	/**
	 * Files larger than this aren't offloaded during the upload request
	 * (see AttachmentHooks); the bulk tool / WP-CLI picks them up. 500 MB.
	 */
	const MAX_SYNC_UPLOAD_BYTES = 524288000;

	/**
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      In-flight attachment metadata (from the
	 *                                  wp_generate_attachment_metadata filter),
	 *                                  which can list files not saved yet.
	 *                                  Null reads the saved metadata.
	 * @return true|\WP_Error
	 */
	public static function offload( $attachment_id, $metadata = null ) {
		if ( self::is_offloaded( $attachment_id ) ) {
			return true;
		}
		return AttachmentLock::run(
			$attachment_id,
			function () use ( $attachment_id, $metadata ) {
				return self::offload_locked( (int) $attachment_id, $metadata );
			}
		);
	}

	private static function offload_locked( $attachment_id, $metadata ) {
		if ( self::is_offloaded( $attachment_id ) ) {
			return true;
		}
		if ( '' !== get_post_meta( $attachment_id, self::KEEP_LOCAL_META, true ) ) {
			return new \WP_Error( 'bmo_excluded', 'Attachment ' . $attachment_id . ' was taken out of the bucket with unoffload and stays local.' );
		}
		if ( self::is_excluded( $attachment_id ) ) {
			update_post_meta( $attachment_id, self::SKIP_META, '1' );
			return new \WP_Error( 'bmo_excluded', 'Attachment ' . $attachment_id . ' is under an excluded path and is not offloaded.' );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'bmo_missing_file', 'Local file not found for attachment ' . $attachment_id . '.' );
		}

		$sync_started = time();
		$location     = self::current_location();
		$files        = self::collect_files( $attachment_id, $file, $location['prefix'], $metadata );
		$client       = S3Client::from_options();
		foreach ( $files as $object_key => $path ) {
			$put = self::upload_one( $client, $object_key, $path );
			if ( is_wp_error( $put ) ) {
				// Objects already uploaded stay; the next attempt overwrites
				// them under the same keys.
				return $put;
			}
		}
		self::forget_orphans( $location, array_keys( $files ) );

		$record = $location + array(
			'objects'   => array_keys( $files ),
			// Files modified after this are re-uploaded by resync.
			'synced_at' => $sync_started,
		);
		$abort = self::abort_before_write( $attachment_id, $location, array_keys( $files ) );
		if ( $abort ) {
			return $abort;
		}
		update_post_meta( $attachment_id, self::META_KEY, $record );

		self::delete_local_after_upload( $attachment_id, $files );
		return true;
	}

	/**
	 * Local files just uploaded: deleted now, or later by
	 * finish_deferred_delete() if another plugin still needs them.
	 *
	 * @param array<string,string> $files object key => local path.
	 */
	private static function delete_local_after_upload( $attachment_id, array $files ) {
		if ( ! Options::delete_local_enabled() || self::is_local_delete_deferred( $attachment_id ) ) {
			return;
		}
		if ( self::should_defer_local_delete( $attachment_id ) ) {
			self::defer_local_delete( $attachment_id );
			return;
		}
		foreach ( $files as $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Last check before writing a record: the attachment may have been
	 * deleted while its files uploaded (its objects are then queued for
	 * deletion), or this request may have lost its lock to another one
	 * (which uploads the same keys and writes the record itself).
	 *
	 * @return \WP_Error|null
	 */
	private static function abort_before_write( $attachment_id, array $location, array $keys ) {
		if ( ! self::post_exists( $attachment_id ) ) {
			self::queue_orphans( $location, $keys );
			return new \WP_Error( 'bmo_deleted', sprintf( 'Attachment %d was deleted while its files were uploading.', $attachment_id ) );
		}
		if ( ! AttachmentLock::still_held( $attachment_id ) ) {
			return new \WP_Error( 'bmo_busy', sprintf( 'Attachment %d was taken over by another request while its files were uploading.', $attachment_id ) );
		}
		return null;
	}

	/**
	 * Whether the post row exists, straight from the database (this
	 * request's cache may be stale). A failed query counts as "exists".
	 */
	private static function post_exists( $attachment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check that must bypass the cache.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $attachment_id ) );
		return null !== $found || '' !== $wpdb->last_error;
	}

	/**
	 * Where new uploads go: everything needed to address the objects later,
	 * stored with each record so changing settings never orphans them.
	 *
	 * @return array{bucket:string,endpoint:string,path_style:string,use_ssl:string,prefix:string}
	 */
	public static function current_location() {
		return array(
			'bucket'     => (string) Options::get( 'bucket' ),
			'endpoint'   => Options::endpoint(),
			'path_style' => (string) Options::get( 'path_style' ),
			'use_ssl'    => (string) Options::get( 'use_ssl' ),
			'prefix'     => self::key_prefix(),
		);
	}

	/**
	 * Whether another plugin still needs this attachment's local files —
	 * e.g. beltoft-webp converting its images in the background. Offloading
	 * still happens immediately; only the local delete waits.
	 */
	public static function should_defer_local_delete( $attachment_id ) {
		return (bool) apply_filters( 'beltoft_webp_conversion_pending', false, (int) $attachment_id );
	}

	public static function is_local_delete_deferred( $attachment_id ) {
		return '' !== get_post_meta( (int) $attachment_id, self::DEFERRED_META, true );
	}

	/**
	 * @param bool $refresh Restart the waiting time even if already flagged
	 *                      (files were just downloaded back).
	 */
	public static function defer_local_delete( $attachment_id, $refresh = false ) {
		if ( $refresh || ! self::is_local_delete_deferred( $attachment_id ) ) {
			update_post_meta( $attachment_id, self::DEFERRED_META, time() );
		}
		self::schedule_sweep();
	}

	/**
	 * Whether the attachment's file lives under an excluded path.
	 */
	public static function is_excluded( $attachment_id ) {
		return Options::path_is_excluded( (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true ) );
	}

	/**
	 * Offloaded attachments under an excluded path (offloaded before the
	 * path was excluded).
	 *
	 * @return int[]
	 */
	public static function offloaded_excluded_ids() {
		global $wpdb;
		$ids = array();
		foreach ( Options::excluded_paths() as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin/CLI query joining two meta keys; no API does this join.
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT f.post_id FROM {$wpdb->postmeta} f INNER JOIN {$wpdb->postmeta} o ON o.post_id = f.post_id AND o.meta_key = %s WHERE f.meta_key = '_wp_attached_file' AND f.meta_value LIKE BINARY %s",
					self::META_KEY,
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			$ids = array_merge( $ids, array_map( 'intval', $found ) );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Completes a postponed local delete: resyncs first (uploading whatever
	 * the background job produced), and deletes the local files only if
	 * that succeeded. On failure the flag stays and the sweep retries.
	 *
	 * @return true|\WP_Error
	 */
	public static function finish_deferred_delete( $attachment_id ) {
		return AttachmentLock::run(
			$attachment_id,
			function () use ( $attachment_id ) {
				return self::finish_deferred_delete_locked( (int) $attachment_id );
			}
		);
	}

	private static function finish_deferred_delete_locked( $attachment_id ) {
		if ( ! self::is_local_delete_deferred( $attachment_id ) ) {
			return true;
		}
		// Kept local: delete_local off, not offloaded, excluded (never
		// synced, so new local files may exist nowhere else), or taken out
		// of the bucket.
		if ( '1' !== (string) Options::get( 'delete_local' ) || ! self::is_offloaded( $attachment_id ) || self::is_excluded( $attachment_id ) || '' !== get_post_meta( $attachment_id, self::KEEP_LOCAL_META, true ) ) {
			delete_post_meta( $attachment_id, self::DEFERRED_META );
			return true;
		}
		// Paused while the server rule isn't verified: keep the flag.
		if ( ! Options::delete_local_enabled() ) {
			return new \WP_Error( 'bmo_paused', 'Local deletes are paused until the server rule works.' );
		}

		$resync = self::resync_offloaded_attachment( $attachment_id );
		if ( is_wp_error( $resync ) ) {
			return $resync;
		}
		if ( ! AttachmentLock::still_held( $attachment_id ) ) {
			return new \WP_Error( 'bmo_busy', 'Lost the attachment lock; local files kept for the next sweep.' );
		}

		$data   = get_post_meta( $attachment_id, self::META_KEY, true );
		$prefix = self::stored_prefix( $data );
		foreach ( (array) $data['objects'] as $object_key ) {
			$path = self::local_path_for_key( $object_key, $prefix );
			if ( null !== $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		delete_post_meta( $attachment_id, self::DEFERRED_META );
		return true;
	}

	/**
	 * Safety net for postponed local deletes whose "finished" signal never
	 * arrives. An attachment no longer reported as pending is finished
	 * right away; one still pending waits up to MAX_DEFER_SECONDS.
	 *
	 * @param bool $force       Finish every flagged attachment regardless of
	 *                          pending state or age (WP-CLI).
	 * @param int  $time_budget Seconds to spend; 0 means no limit (WP-CLI).
	 * @return array{finished:int,waiting:int,errors:string[]}
	 */
	public static function sweep_deferred_deletes( $force = false, $time_budget = self::CRON_TIME_BUDGET ) {
		$finished    = 0;
		$waiting     = 0;
		$errors      = array();
		$seen        = array();
		$started     = microtime( true );
		$out_of_time = false;

		do {
			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => self::SWEEP_BATCH,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only attachments carrying our own deferred-delete flag, from a background cron/CLI run.
				'meta_query'     => array(
					array(
						'key'     => self::DEFERRED_META,
						'compare' => 'EXISTS',
					),
				),
			);
			if ( ! empty( $seen ) ) {
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- bounded to flagged attachments already handled in this run.
				$args['post__not_in'] = $seen;
			}
			$ids = get_posts( $args );

			foreach ( $ids as $id ) {
				if ( $time_budget > 0 && ( microtime( true ) - $started ) > $time_budget ) {
					$out_of_time = true;
					break 2;
				}
				$seen[] = (int) $id;

				$age = time() - (int) get_post_meta( $id, self::DEFERRED_META, true );
				if ( ! $force && ( $age < self::MIN_LOCAL_SECONDS || ( $age < self::MAX_DEFER_SECONDS && self::should_defer_local_delete( $id ) ) ) ) {
					++$waiting;
					continue;
				}

				$result = self::finish_deferred_delete( $id );
				if ( is_wp_error( $result ) ) {
					$errors[] = $id . ': ' . $result->get_error_message();
					continue;
				}
				++$finished;
			}
		} while ( ! empty( $ids ) );

		if ( self::count_deferred() > 0 ) {
			self::schedule_sweep( $out_of_time ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
		}

		return array(
			'finished' => $finished,
			'waiting'  => $waiting,
			'errors'   => $errors,
		);
	}

	public static function count_deferred() {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only attachments carrying our own deferred-delete flag; sweep and status output.
				'meta_query'     => array(
					array(
						'key'     => self::DEFERRED_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	public static function schedule_sweep( $delay = HOUR_IN_SECONDS ) {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_single_event( time() + (int) $delay, self::SWEEP_HOOK );
		}
	}

	/**
	 * Removes an attachment's objects from the bucket and drops its record
	 * (attachment deleted, or `unoffload`).
	 *
	 * Media translation plugins (Polylang, WPML) and duplicators create
	 * attachments sharing another's files (see keep_for_twins()).
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $deleting      True when the attachment itself is being
	 *                            deleted (not just taken out of the bucket).
	 * @return int Objects whose deletion failed (queued for retry).
	 */
	public static function delete_offloaded( $attachment_id, $deleting = false ) {
		$attachment_id = (int) $attachment_id;
		$result        = AttachmentLock::run(
			$attachment_id,
			function () use ( $attachment_id, $deleting ) {
				return self::delete_offloaded_locked( $attachment_id, $deleting );
			},
			30
		);
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		// Still busy after waiting (e.g. deleted while a long upload runs):
		// queue the objects for the retry job rather than leave them
		// untracked. An upload still running queues its own when it finds
		// the attachment gone (see abort_before_write()).
		$data = get_post_meta( $attachment_id, self::META_KEY, true );
		$keys = empty( $data['objects'] ) || self::keep_for_twins( $attachment_id, $data, $deleting ) ? array() : (array) $data['objects'];
		if ( ! empty( $keys ) ) {
			self::queue_orphans( $data, $keys );
		}
		delete_post_meta( $attachment_id, self::META_KEY );
		return count( $keys );
	}

	private static function delete_offloaded_locked( $attachment_id, $deleting ) {
		$data = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( empty( $data['objects'] ) ) {
			return 0;
		}

		$failed = array();
		if ( ! self::keep_for_twins( $attachment_id, $data, $deleting ) ) {
			// The bucket/endpoint this attachment was offloaded to, not
			// whatever the settings say now.
			$client = self::client_for( $data );
			foreach ( (array) $data['objects'] as $object_key ) {
				if ( is_wp_error( $client->delete_object( $object_key ) ) ) {
					$failed[] = $object_key;
				}
			}
		}
		if ( ! empty( $failed ) ) {
			self::queue_orphans( $data, $failed );
		}
		delete_post_meta( $attachment_id, self::META_KEY );
		return count( $failed );
	}

	/**
	 * Whether the objects must stay because another attachment uses the
	 * same file:
	 * - deleting: always while one exists; the record moves to one that has
	 *   none, so the objects go with the last of them (its links may rely
	 *   on them, through the server rule);
	 * - unoffload: only while one is offloaded itself (the files are local
	 *   again, so one without a record is served from disk).
	 */
	private static function keep_for_twins( $attachment_id, array $data, $deleting ) {
		$twins = self::twins( $attachment_id );
		if ( ! $deleting ) {
			return ! empty( array_filter( $twins, array( __CLASS__, 'is_offloaded' ) ) );
		}
		foreach ( $twins as $twin ) {
			if ( ! self::is_offloaded( $twin ) ) {
				update_post_meta( $twin, self::META_KEY, $data );
				break;
			}
		}
		return ! empty( $twins );
	}

	/**
	 * Other attachments using the same main file (exact bytes: the column's
	 * collation ignores case and accents).
	 *
	 * @return int[]
	 */
	public static function twins( $attachment_id ) {
		global $wpdb;
		$file = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- only when an offloaded attachment's record is removed; must be fresh.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND BINARY meta_value = %s AND post_id <> %d ORDER BY post_id LIMIT 20", $file, (int) $attachment_id ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Whether any attachment of this site is offloaded (one indexed query).
	 */
	public static function has_offloaded() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- settings-save check; must be fresh.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::META_KEY ) );
	}

	public static function is_offloaded( $attachment_id ) {
		$data = get_post_meta( (int) $attachment_id, self::META_KEY, true );
		return ! empty( $data['objects'] );
	}

	/**
	 * Uploads files of an already-offloaded attachment that aren't in the
	 * bucket yet (a size added by regeneration, a sibling a converter wrote
	 * later, an edited image) or changed since the last sync.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      In-flight metadata; see offload().
	 * @return true|\WP_Error
	 */
	public static function resync_offloaded_attachment( $attachment_id, $metadata = null ) {
		// Most metadata saves have nothing to upload: decide that without the
		// lock (unfiltered path, so it never triggers a download).
		if ( null === self::resync_plan( (int) $attachment_id, $metadata, true ) ) {
			return true;
		}
		return AttachmentLock::run(
			$attachment_id,
			function () use ( $attachment_id, $metadata ) {
				return self::resync_locked( (int) $attachment_id, $metadata );
			}
		);
	}

	/**
	 * @return array{0:array,1:array<string,string>,2:int}|null [record, key => path, sync start], or null with nothing to do
	 */
	private static function resync_plan( $attachment_id, $metadata, $unfiltered = false ) {
		$data = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( empty( $data['objects'] ) || self::is_excluded( $attachment_id ) ) {
			return null;
		}
		$file = get_attached_file( $attachment_id, $unfiltered );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}

		$sync_started = time();
		$have         = array_flip( (array) $data['objects'] );
		$synced_at    = (int) $data['synced_at'];
		$new_files    = array();
		foreach ( self::collect_files( $attachment_id, $file, self::stored_prefix( $data ), $metadata ) as $object_key => $path ) {
			// >=: a file rewritten within the second the last sync started.
			if ( ! isset( $have[ $object_key ] ) || filemtime( $path ) >= $synced_at ) {
				$new_files[ $object_key ] = $path;
			}
		}
		return empty( $new_files ) ? null : array( $data, $new_files, $sync_started );
	}

	private static function resync_locked( $attachment_id, $metadata ) {
		$plan = self::resync_plan( $attachment_id, $metadata );
		if ( null === $plan ) {
			return true;
		}
		list( $data, $new_files, $sync_started ) = $plan;

		$client = self::client_for( $data );
		foreach ( $new_files as $object_key => $path ) {
			$put = self::upload_one( $client, $object_key, $path );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
		}
		self::forget_orphans( $data, array_keys( $new_files ) );

		$data['objects']   = array_values( array_unique( array_merge( (array) $data['objects'], array_keys( $new_files ) ) ) );
		$data['synced_at'] = $sync_started;
		$abort             = self::abort_before_write( $attachment_id, $data, array_keys( $new_files ) );
		if ( $abort ) {
			return $abort;
		}
		update_post_meta( $attachment_id, self::META_KEY, $data );

		self::delete_local_after_upload( $attachment_id, $new_files );
		return true;
	}

	/**
	 * Resyncs every offloaded attachment (WP-CLI, or after beltoft-webp's
	 * backfill wrote siblings directly to disk).
	 *
	 * @return array{checked:int,updated:int,errors:string[]}
	 */
	public static function resync_all_offloaded() {
		$ids     = self::offloaded_ids();
		$updated = 0;
		$errors  = array();
		foreach ( $ids as $id ) {
			$before = get_post_meta( $id, self::META_KEY, true );
			$result = self::resync_offloaded_attachment( $id );
			if ( is_wp_error( $result ) ) {
				$errors[] = $id . ': ' . $result->get_error_message();
				continue;
			}
			if ( get_post_meta( $id, self::META_KEY, true ) !== $before ) {
				++$updated;
			}
		}
		return array(
			'checked' => count( $ids ),
			'updated' => $updated,
			'errors'  => $errors,
		);
	}

	/**
	 * @return int[] Every offloaded attachment.
	 */
	public static function offloaded_ids() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- occasional CLI bulk operation, not a hot path.
					'meta_query'     => array(
						array(
							'key'     => self::META_KEY,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);
	}

	/**
	 * Retries queued failed deletions, up to ORPHAN_BATCH per run. Progress
	 * is saved against a fresh read of the queue, so entries queued
	 * meanwhile are never lost.
	 *
	 * @param int $time_budget Seconds to spend; 0 means no limit (WP-CLI).
	 * @return array{deleted:int,remaining:int}
	 */
	public static function retry_orphans( $time_budget = self::CRON_TIME_BUDGET ) {
		$done     = array();
		$deleted  = 0;
		$failures = 0;
		$clients  = array();
		$started  = microtime( true );

		foreach ( array_slice( self::orphans(), 0, self::ORPHAN_BATCH ) as $orphan ) {
			if ( $failures >= self::ORPHAN_MAX_CONSECUTIVE || ( $time_budget > 0 && ( microtime( true ) - $started ) > $time_budget ) ) {
				break;
			}
			$client_id = md5( wp_json_encode( array( $orphan['bucket'], $orphan['endpoint'], $orphan['path_style'], $orphan['use_ssl'] ) ) );
			if ( ! isset( $clients[ $client_id ] ) ) {
				$clients[ $client_id ] = self::client_for( $orphan );
			}
			if ( is_wp_error( $clients[ $client_id ]->delete_object( $orphan['key'] ) ) ) {
				++$failures;
				continue;
			}
			$failures = 0;
			$done[]   = $orphan;
			++$deleted;
		}

		$remaining = self::remove_orphans( $done );
		if ( $remaining > 0 ) {
			// Failing: the bucket is likely still down, wait an hour.
			self::schedule_orphan_retry( $failures > 0 ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
		}
		return array(
			'deleted'   => $deleted,
			'remaining' => $remaining,
		);
	}

	/**
	 * @return int Entries left.
	 */
	private static function remove_orphans( array $done ) {
		$orphans = self::orphans();
		if ( ! empty( $done ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- in-memory identity key only, never stored.
			$done_ids = array_flip( array_map( 'serialize', $done ) );
			$orphans  = array_values(
				array_filter(
					$orphans,
					function ( $orphan ) use ( $done_ids ) {
						return ! isset( $done_ids[ serialize( $orphan ) ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- in-memory identity key only.
					}
				)
			);
			update_option( self::ORPHANS_OPTION, $orphans, false );
		}
		return count( $orphans );
	}

	/**
	 * @return array<int,array{bucket:string,endpoint:string,path_style:string,use_ssl:string,key:string}>
	 */
	public static function orphans() {
		$orphans = get_option( self::ORPHANS_OPTION, array() );
		return is_array( $orphans ) ? $orphans : array();
	}

	private static function queue_orphans( array $location, array $object_keys ) {
		$orphans = self::orphans();
		foreach ( $object_keys as $object_key ) {
			$orphans[] = array(
				'bucket'     => (string) $location['bucket'],
				'endpoint'   => (string) $location['endpoint'],
				'path_style' => (string) $location['path_style'],
				'use_ssl'    => (string) $location['use_ssl'],
				'key'        => (string) $object_key,
			);
		}
		update_option( self::ORPHANS_OPTION, $orphans, false );
		self::schedule_orphan_retry();
	}

	/**
	 * Drops queued deletions of keys just uploaded again (a deleted file's
	 * name reused while its bucket delete was failing): the retry must not
	 * delete the new file.
	 */
	private static function forget_orphans( array $location, array $object_keys ) {
		$orphans = self::orphans();
		if ( empty( $orphans ) ) {
			return;
		}
		$keys = array_flip( $object_keys );
		$kept = array_values(
			array_filter(
				$orphans,
				function ( $orphan ) use ( $location, $keys ) {
					return ! ( isset( $keys[ $orphan['key'] ] ) && $orphan['bucket'] === (string) $location['bucket'] && $orphan['endpoint'] === (string) $location['endpoint'] );
				}
			)
		);
		if ( count( $kept ) !== count( $orphans ) ) {
			update_option( self::ORPHANS_OPTION, $kept, false );
		}
	}

	public static function schedule_orphan_retry( $delay = HOUR_IN_SECONDS ) {
		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + (int) $delay, self::RETRY_HOOK );
		}
	}

	/**
	 * Client for the bucket/endpoint a record (or orphan) was stored with.
	 */
	public static function client_for( array $data ) {
		return S3Client::from_options(
			array(
				'bucket'     => $data['bucket'],
				'endpoint'   => $data['endpoint'],
				'path_style' => '1' === (string) $data['path_style'],
				'use_ssl'    => '1' === (string) $data['use_ssl'],
			)
		);
	}

	/**
	 * Streams one file to the bucket and verifies it arrived.
	 *
	 * @return true|\WP_Error
	 */
	private static function upload_one( $client, $object_key, $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a local media file so it never has to be fully loaded into memory.
		$stream = fopen( $path, 'rb' );
		if ( false === $stream ) {
			return new \WP_Error( 'bmo_read_failed', 'Could not open file: ' . $path );
		}
		// Keeps the caller's lock alive while a large file uploads.
		Heartbeat::attach( $stream );

		$put = $client->put_object( $object_key, $stream, self::mime_type( $path ) );
		if ( is_resource( $stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the stream opened above.
			fclose( $stream );
		}
		return is_wp_error( $put ) ? $put : $client->head_object( $object_key );
	}

	/**
	 * @param int   $limit   Max IDs to return.
	 * @param int   $offset  Query offset.
	 * @param int[] $exclude Attachment IDs to skip (e.g. known failures in
	 *                       the current run), so they cannot block later ones.
	 */
	public static function unoffloaded_ids( $limit = 10, $offset = 0, array $exclude = array() ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => (int) $limit,
			'offset'         => (int) $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- attachments missing our own meta keys; paginated admin/CLI listing.
			'meta_query'     => self::unoffloaded_meta_query(),
		);
		$exclude = array_filter( array_map( 'absint', $exclude ) );
		if ( ! empty( $exclude ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- bounded to IDs that failed during the current run.
			$args['post__not_in'] = array_values( $exclude );
		}
		return get_posts( $args );
	}

	public static function count_unoffloaded() {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- attachments missing our own meta keys; admin-page count.
				'meta_query'     => self::unoffloaded_meta_query(),
			)
		);
		return (int) $query->found_posts;
	}

	private static function unoffloaded_meta_query() {
		$query = array( 'relation' => 'AND' );
		foreach ( array( self::META_KEY, self::SKIP_META, self::KEEP_LOCAL_META ) as $key ) {
			$query[] = array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			);
		}
		return $query;
	}

	/**
	 * `wp_unique_filename` filter. WordPress only avoids names that exist on
	 * disk; once local files are deleted, a new upload could reuse an
	 * offloaded file's name and overwrite it in the bucket. Appends -1, -2,
	 * ... while the name, or anything WordPress derives from it (sizes,
	 * -scaled, ...), collides with an existing attachment's file, or the
	 * name exists in the bucket.
	 */
	public static function filter_unique_filename( $filename, $ext, $dir ) {
		$base_dir = self::uploads_basedir();
		$dir      = trailingslashit( wp_normalize_path( $dir ) );
		if ( 0 !== strpos( $dir, $base_dir ) || ! Options::is_configured() ) {
			return $filename;
		}

		$rel_dir   = substr( $dir, strlen( $base_dir ) );
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );
		$extension = '' !== $extension ? '.' . $extension : '';
		$name      = substr( $filename, 0, strlen( $filename ) - strlen( $extension ) );
		$stems     = self::attached_stems( $rel_dir, $name );
		$client    = S3Client::from_options();
		$prefix    = self::key_prefix() . $rel_dir;

		$candidate = $name;
		for ( $i = 1; $i < 100; $i++ ) {
			$taken = self::stem_conflicts( $candidate, $stems )
				|| ( $candidate !== $name && file_exists( $dir . $candidate . $extension ) )
				// A failed HEAD (404, or the bucket unreachable — then the
				// upload fails too) means free.
				|| ! is_wp_error( $client->head_object( $prefix . $candidate . $extension ) );
			if ( ! $taken ) {
				break;
			}
			$candidate = $name . '-' . $i;
		}
		return $candidate . $extension;
	}

	/**
	 * Stems of this site's attachments in $rel_dir whose name starts like
	 * $name (one prefix query).
	 *
	 * @return string[]
	 */
	private static function attached_stems( $rel_dir, $name ) {
		global $wpdb;
		$first = strtok( $name, '-' );
		$first = ( false === $first || '' === $first ) ? $name : $first;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check during an upload; a cached answer could be stale and cause an overwrite.
		$files = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", $wpdb->esc_like( $rel_dir . $first ) . '%' ) );

		$stems = array();
		foreach ( $files as $file ) {
			$file_dir = dirname( $file );
			if ( ( '.' === $file_dir ? '' : trailingslashit( $file_dir ) ) === $rel_dir ) {
				$stems[] = self::stem_of( basename( $file ) );
			}
		}
		return $stems;
	}

	/**
	 * Whether files named after $candidate would share an object key with
	 * files named after any of $stems: same stem, or one is a WordPress
	 * derivative of the other (photo vs photo-300x200 / photo-scaled).
	 */
	private static function stem_conflicts( $candidate, array $stems ) {
		foreach ( $stems as $stem ) {
			if ( $stem === $candidate || self::is_derived( $stem, $candidate ) || self::is_derived( $candidate, $stem ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_derived( $name, $base ) {
		return 0 === strpos( $name, $base . '-' ) && 1 === preg_match( self::DERIVED_SUFFIX_PATTERN, substr( $name, strlen( $base ) + 1 ) );
	}

	/**
	 * "photo.jpg" -> "photo".
	 */
	private static function stem_of( $filename ) {
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );
		return '' !== $extension ? substr( $filename, 0, -1 - strlen( $extension ) ) : $filename;
	}

	/**
	 * Object key prefix for the current site: multisite subsites keep their
	 * files under uploads/sites/<id>/, so keys always mirror the path under
	 * the network's uploads directory (which the server rule relies on).
	 */
	public static function key_prefix() {
		if ( is_multisite() && ! is_main_site() ) {
			return 'sites/' . get_current_blog_id() . '/';
		}
		return '';
	}

	public static function stored_prefix( $data ) {
		return ( is_array( $data ) && isset( $data['prefix'] ) ) ? (string) $data['prefix'] : '';
	}

	/**
	 * Path of an object relative to this site's uploads directory, or null
	 * if the key belongs to another site. Single place for the key -> local
	 * file mapping.
	 */
	public static function relative_path_for_key( $object_key, $prefix ) {
		$object_key = (string) $object_key;
		if ( '' === $prefix ) {
			return $object_key;
		}
		return 0 === strpos( $object_key, $prefix ) ? substr( $object_key, strlen( $prefix ) ) : null;
	}

	public static function local_path_for_key( $object_key, $prefix ) {
		$relative = self::relative_path_for_key( $object_key, $prefix );
		return null === $relative ? null : self::uploads_basedir() . $relative;
	}

	/**
	 * Normalised uploads directory with trailing slash, once per site per
	 * request (wp_get_upload_dir() runs the upload_dir filter).
	 */
	public static function uploads_basedir() {
		static $cache = array();
		$blog = get_current_blog_id();
		if ( ! isset( $cache[ $blog ] ) ) {
			$cache[ $blog ] = trailingslashit( wp_normalize_path( wp_get_upload_dir()['basedir'] ) );
		}
		return $cache[ $blog ];
	}

	/**
	 * Object key for a local file under the uploads directory.
	 */
	public static function object_key( $absolute_path, $prefix ) {
		$relative = substr( wp_normalize_path( $absolute_path ), strlen( self::uploads_basedir() ) );
		return $prefix . ltrim( (string) $relative, '/' );
	}

	/**
	 * @return array<string,string> object key => absolute local path
	 */
	private static function collect_files( $attachment_id, $original_file, $prefix, $metadata = null ) {
		$files = array();
		self::add_with_siblings( $files, $original_file, $prefix );

		if ( ! is_array( $metadata ) ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		$dir = trailingslashit( dirname( $original_file ) );

		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) && file_exists( $dir . $size['file'] ) ) {
					self::add_with_siblings( $files, $dir . $size['file'], $prefix );
				}
			}
		}
		// Unscaled original kept by WordPress for big images (> 2560px).
		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) && file_exists( $dir . $metadata['original_image'] ) ) {
			self::add_with_siblings( $files, $dir . $metadata['original_image'], $prefix );
		}
		return $files;
	}

	/**
	 * Adds one file plus any format siblings next to it on disk.
	 *
	 * @param array<string,string> $files Upload set being built, by reference.
	 */
	private static function add_with_siblings( array &$files, $path, $prefix ) {
		$files[ self::object_key( $path, $prefix ) ] = $path;
		foreach ( self::SIBLING_FORMAT_EXTENSIONS as $extension ) {
			if ( file_exists( $path . '.' . $extension ) ) {
				$files[ self::object_key( $path . '.' . $extension, $prefix ) ] = $path . '.' . $extension;
			}
		}
	}

	private static function mime_type( $path ) {
		$type = wp_check_filetype( $path );
		return ! empty( $type['type'] ) ? $type['type'] : 'application/octet-stream';
	}
}
