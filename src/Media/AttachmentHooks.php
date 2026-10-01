<?php
namespace BeltoftMediaOffload\Media;

use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

class AttachmentHooks {

	/** @var array<int,bool> Attachments whose sizes are being created right now. */
	private static $generating = array();

	/**
	 * @var bool A PDF's preview and sizes are being created right now.
	 *           fallback_intermediate_image_sizes carries no attachment ID,
	 *           and core creates one PDF at a time.
	 */
	private static $generating_pdf = false;

	public static function init() {
		// Priority 20, deliberately later than the WP core/plugin default
		// of 10: other plugins that write additional sibling files for an
		// attachment on this same filter (e.g. beltoft-webp's .avif/.webp
		// generation) need to have already run before Offloader looks at
		// what's on disk, or their files won't exist yet to be picked up —
		// and offload() only ever runs once per attachment, so a miss here
		// isn't retried later without a manual bulk/CLI re-run.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_attachment_metadata' ), 20, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'on_update_attachment_metadata' ), 20, 2 );

		// While WordPress creates an attachment's sizes it saves metadata
		// after each one; mark that window (see on_update_attachment_metadata).
		// Both filters fire at the start of size creation, before any save.
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'mark_generating_threshold' ), PHP_INT_MAX, 4 );
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'mark_generating_sizes' ), PHP_INT_MAX, 3 );
		// PDFs skip both: core renders the preview, saves metadata, then
		// creates its sizes from it.
		add_filter( 'fallback_intermediate_image_sizes', array( __CLASS__, 'mark_generating_pdf' ), PHP_INT_MAX );
		// The REST API also applies that filter, outside generation, to list
		// missing sizes; its response is prepared last.
		add_filter( 'rest_prepare_attachment', array( __CLASS__, 'clear_generating_pdf' ) );
		add_filter( 'wp_unique_filename', array( Offloader::class, 'filter_unique_filename' ), 10, 3 );
		add_action( Offloader::RETRY_HOOK, array( __CLASS__, 'on_retry_orphans' ) );
		add_action( Offloader::SWEEP_HOOK, array( __CLASS__, 'on_sweep' ) );

		// Optional integration, like the backfill hook below: beltoft-webp
		// converting an attachment in the background reports it as pending
		// (see Offloader::should_defer_local_delete()) and fires this when
		// done, successful or not. Inert if that plugin isn't installed.
		add_action( 'beltoft_webp_attachment_conversion_finished', array( __CLASS__, 'on_conversion_finished' ) );

		// Optional integration, no hard dependency: beltoft-webp's backfill
		// command converts files directly on the filesystem and never fires
		// wp_generate_attachment_metadata, so an already-offloaded attachment
		// would never pick up siblings it creates. If that plugin isn't
		// installed, this action simply never fires and the line is inert.
		add_action( 'beltoft_webp_backfill_complete', array( __CLASS__, 'on_beltoft_webp_backfill_complete' ) );
	}

	/**
	 * Runs after WordPress has generated all image sizes (and after any
	 * same-hook sibling-file generators at a lower priority — see init()).
	 * Offloads, but always returns metadata unchanged: offload failure
	 * must never break the upload response.
	 *
	 * Also fires when sizes are regenerated for an existing attachment
	 * (Regenerate Thumbnails, a new theme size); an already-offloaded
	 * attachment is then resynced so the new files reach the bucket
	 * instead of being referenced there and 404ing.
	 *
	 * The in-flight $metadata is passed through because it can contain
	 * entries not saved yet (e.g. the 'full' preview image of a PDF).
	 *
	 * Files over Offloader::MAX_SYNC_UPLOAD_BYTES are left for the bulk tool
	 * / WP-CLI (request duration, not memory: uploads are streamed).
	 */
	public static function on_generate_attachment_metadata( $metadata, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		unset( self::$generating[ $attachment_id ] );
		self::$generating_pdf = false;

		// Regardless of the Enabled toggle: this attachment is already served
		// from the bucket, so anything new must be there too.
		if ( Offloader::is_offloaded( $attachment_id ) ) {
			Offloader::resync_offloaded_attachment( $attachment_id, $metadata );
			return $metadata;
		}

		if ( ! Options::is_enabled() ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );
		if ( $file && file_exists( $file ) && filesize( $file ) > Offloader::MAX_SYNC_UPLOAD_BYTES ) {
			return $metadata;
		}

		Offloader::offload( $attachment_id, $metadata );
		return $metadata;
	}

	/**
	 * Uploads whatever the background conversion produced. If the local
	 * delete was postponed at offload time, it's completed too (after a
	 * successful upload; on failure the flag stays for the sweep to retry).
	 * Without a postponed delete (delete_local off, or the attachment not
	 * offloaded when the job was queued) it's a plain resync.
	 */
	public static function on_conversion_finished( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( Offloader::is_local_delete_deferred( $attachment_id ) ) {
			Offloader::finish_deferred_delete( $attachment_id );
		} else {
			Offloader::resync_offloaded_attachment( $attachment_id );
		}
	}

	// Cron callbacks wrap the Offloader calls so no stray hook argument can
	// land in their $time_budget parameter.
	public static function on_sweep() {
		Offloader::sweep_deferred_deletes( false, Offloader::CRON_TIME_BUDGET );
	}

	public static function on_retry_orphans() {
		Offloader::retry_orphans( Offloader::CRON_TIME_BUDGET );
	}

	/**
	 * Files written and saved only through wp_update_attachment_metadata()
	 * — the image editor (crop/rotate/scale, restore original), image
	 * optimizers, scripts — would otherwise never reach the bucket, so
	 * resync against the metadata being saved.
	 *
	 * Except while WordPress is creating the attachment's sizes (upload,
	 * regeneration): it saves metadata after every single size there, and
	 * a resync (with delete_local on) would delete each new size before
	 * wp_generate_attachment_metadata's own filters (beltoft-webp, image
	 * optimizers) have run. on_generate_attachment_metadata covers that.
	 */
	public static function on_update_attachment_metadata( $data, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! is_array( $data ) || isset( self::$generating[ $attachment_id ] ) ) {
			return $data;
		}
		if ( self::$generating_pdf && 'application/pdf' === get_post_mime_type( $attachment_id ) ) {
			return $data;
		}
		if ( Offloader::is_offloaded( $attachment_id ) ) {
			Offloader::resync_offloaded_attachment( $attachment_id, $data );
		}
		return $data;
	}

	/**
	 * `big_image_size_threshold` (pass-through): size creation starts.
	 */
	public static function mark_generating_threshold( $threshold, $imagesize, $file, $attachment_id ) {
		self::$generating[ (int) $attachment_id ] = true;
		return $threshold;
	}

	/**
	 * `intermediate_image_sizes_advanced` (pass-through): size creation
	 * starts (also for regeneration without the big-image step).
	 */
	public static function mark_generating_sizes( $sizes, $image_meta, $attachment_id = 0 ) {
		if ( $attachment_id ) {
			self::$generating[ (int) $attachment_id ] = true;
		}
		return $sizes;
	}

	/**
	 * `fallback_intermediate_image_sizes` (pass-through): a PDF's preview
	 * and sizes are about to be created.
	 */
	public static function mark_generating_pdf( $sizes ) {
		self::$generating_pdf = true;
		return $sizes;
	}

	/**
	 * `rest_prepare_attachment` (pass-through): see init().
	 */
	public static function clear_generating_pdf( $response ) {
		self::$generating_pdf = false;
		return $response;
	}

	public static function on_delete_attachment( $attachment_id ) {
		Offloader::delete_offloaded( (int) $attachment_id, true );
	}

	/**
	 * See init()'s comment. Runs synchronously — this only ever fires from
	 * a WP-CLI command's own process, not a web request, so there's no
	 * request-timeout concern with scanning every offloaded attachment.
	 */
	public static function on_beltoft_webp_backfill_complete() {
		$result = Offloader::resync_all_offloaded();

		if ( defined( 'WP_CLI' ) && WP_CLI && ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				\WP_CLI::warning( 'beltoft-media-offload resync: ' . $error );
			}
		}
	}
}
