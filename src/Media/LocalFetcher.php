<?php
namespace BeltoftMediaOffload\Media;

use BeltoftMediaOffload\Support\AttachmentLock;
use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Brings an offloaded attachment's files back to local disk when something
 * genuinely needs them there (image editing, thumbnail regeneration, or
 * code that asks via beltoft_media_offload_ensure_local()), so "Delete local files" doesn't
 * break features that read media from disk.
 *
 * Deliberately NOT a global get_attached_file() hook: WordPress, themes and
 * this plugin itself call that on ordinary page views and Media Library
 * listings, which would pull the whole library back down. Downloads happen
 * only in the contexts is_fetch_context() recognises.
 *
 * Downloaded files are flagged like a postponed delete, so the sweep
 * removes them again (after resyncing anything new) once they've been
 * local for Offloader::MIN_LOCAL_SECONDS.
 */
class LocalFetcher {

	/**
	 * Seconds one request may spend downloading before giving up on the
	 * remaining files.
	 */
	const TIME_BUDGET = 30;

	/** @var bool Set while an explicitly fetching context runs. */
	private static $forced = false;

	public static function init() {
		// The image editor loads its source only through this filter.
		add_filter( 'load_image_to_edit_path', array( __CLASS__, 'filter_edit_path' ), 10, 3 );
		add_filter( 'get_attached_file', array( __CLASS__, 'filter_attached_file' ), 10, 2 );
		add_filter( 'wp_get_original_image_path', array( __CLASS__, 'filter_attached_file' ), 10, 2 );
	}

	/**
	 * Makes sure every file of an offloaded attachment exists locally,
	 * downloading whatever is missing.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $keep          True to keep the files local (restore);
	 *                            false flags them for the sweep to delete
	 *                            again when delete_local is on.
	 * @param int  $time_budget   Seconds to spend downloading; 0 means no
	 *                            limit (WP-CLI).
	 * @return true|\WP_Error
	 */
	public static function ensure_local( $attachment_id, $keep = false, $time_budget = self::TIME_BUDGET ) {
		$attachment_id = (int) $attachment_id;
		$data          = get_post_meta( $attachment_id, Offloader::META_KEY, true );
		if ( empty( $data ) || empty( $data['objects'] ) || empty( self::missing_files( $data ) ) ) {
			return true;
		}

		// The attachment lock also waits for another request downloading the
		// same files, and keeps itself alive during long downloads.
		return AttachmentLock::run(
			$attachment_id,
			function () use ( $attachment_id, $keep, $time_budget ) {
				return self::download_missing( $attachment_id, $keep, $time_budget );
			}
		);
	}

	private static function download_missing( $attachment_id, $keep, $time_budget ) {
		// Re-read under the lock: another request may have changed it.
		$data = get_post_meta( $attachment_id, Offloader::META_KEY, true );
		if ( empty( $data ) || empty( $data['objects'] ) ) {
			return true;
		}
		$missing = self::missing_files( $data );
		if ( empty( $missing ) ) {
			return true;
		}

		$client    = Offloader::client_for( $data );
		$started   = microtime( true );
		$synced_at = (int) $data['synced_at'];
		$error     = null;

		foreach ( $missing as $object_key => $path ) {
			if ( $time_budget > 0 && ( microtime( true ) - $started ) > $time_budget ) {
				$error = new \WP_Error( 'bmo_fetch_timeout', 'Download time budget exceeded for attachment ' . $attachment_id . '.' );
				break;
			}
			$result = $client->download_object( $object_key, $path );
			if ( is_wp_error( $result ) ) {
				$error = $result;
				break;
			}
			// Same content as the bucket copy: give it a modification time no
			// later than the last sync, or resync would upload it straight
			// back (it re-uploads files modified since synced_at).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- resetting the modification time of a media file this plugin just downloaded; WP_Filesystem may not be direct in this context.
			touch( $path, $synced_at - 1 );
		}

		// Whatever did arrive is local now: let the sweep remove it again
		// later (after resyncing new sizes), unless it's a restore.
		if ( ! $keep && Options::delete_local_enabled() ) {
			Offloader::defer_local_delete( $attachment_id, true );
		}

		return $error ? $error : true;
	}

	/**
	 * `load_image_to_edit_path`: the image editor needs the real file.
	 */
	public static function filter_edit_path( $filepath, $attachment_id, $size = 'full' ) {
		// When the file is missing, core has already swapped in the
		// attachment URL (the bucket), which file_exists() never finds.
		if ( file_exists( $filepath ) || ! Offloader::is_offloaded( $attachment_id ) ) {
			return $filepath;
		}
		if ( is_wp_error( self::ensure_local( $attachment_id ) ) ) {
			return $filepath;
		}

		// Hand the editor the local file just downloaded, not the URL.
		$local = get_attached_file( $attachment_id, true );
		if ( 'full' !== $size ) {
			$intermediate = image_get_intermediate_size( $attachment_id, $size );
			if ( $intermediate && ! empty( $intermediate['file'] ) ) {
				$local = trailingslashit( dirname( $local ) ) . $intermediate['file'];
			}
		}
		return file_exists( $local ) ? $local : $filepath;
	}

	/**
	 * `get_attached_file` / `wp_get_original_image_path`: only fetches in a
	 * recognised context (see is_fetch_context()); a no-op everywhere else.
	 */
	public static function filter_attached_file( $file, $attachment_id ) {
		// Called for every image on ordinary page views: bail out before
		// touching the filesystem unless this request can fetch at all.
		if ( ! $file || ! self::is_fetch_context( (int) $attachment_id ) || file_exists( $file ) ) {
			return $file;
		}
		self::ensure_local( (int) $attachment_id );
		return $file;
	}

	/**
	 * Runs $callback with fetching forced on, for code that reads media
	 * from disk through get_attached_file() outside the known contexts.
	 *
	 * @return mixed The callback's return value.
	 */
	public static function with_fetching( callable $callback ) {
		$previous     = self::$forced;
		self::$forced = true;
		try {
			return $callback();
		} finally {
			self::$forced = $previous;
		}
	}

	/**
	 * Contexts that read media from disk and would fail without it:
	 * - the image editor and crop tools (admin-ajax and REST),
	 * - thumbnail regeneration (`wp media regenerate`, the Regenerate
	 *   Thumbnails plugin's REST route),
	 * - anything inside with_fetching(),
	 * - anything the `beltoft_media_offload_fetch_on_miss` filter says yes
	 *   to.
	 */
	private static function is_fetch_context( $attachment_id ) {
		$active = self::$forced || self::request_fetches();

		if ( ! $active && ! has_filter( 'beltoft_media_offload_fetch_on_miss' ) ) {
			return false;
		}

		/**
		 * Whether reading this attachment's file should download it from the
		 * bucket if it's missing locally.
		 *
		 * @param bool $active        Whether a built-in context matched.
		 * @param int  $attachment_id Attachment ID.
		 */
		return (bool) apply_filters( 'beltoft_media_offload_fetch_on_miss', $active, $attachment_id );
	}

	/**
	 * Whether this request is one of the built-in fetching contexts;
	 * computed once per request.
	 */
	private static function request_fetches() {
		static $fetches = null;
		if ( null !== $fetches ) {
			return $fetches;
		}
		$active = false;

		if ( ! $active && wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reading which core AJAX action is running; that handler verifies its own nonce.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$active = in_array( $action, array( 'image-editor', 'imgedit-preview', 'crop-image' ), true );
		}

		if ( ! $active && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route  = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
			$active = 1 === preg_match( '#^/wp/v2/media/\d+/(edit|post-process)|^/regenerate-thumbnails/#', $route );
		}

		if ( ! $active && defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			$args   = \WP_CLI::get_runner()->arguments;
			$active = isset( $args[0], $args[1] ) && 'media' === $args[0] && 'regenerate' === $args[1];
		}

		// A REST request only identifies itself (REST_REQUEST, the route)
		// during parse_request, so a "no" is only final once the request
		// type is known: after parse_request, or for AJAX and WP-CLI.
		if ( $active || did_action( 'parse_request' ) || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$fetches = $active;
		}
		return $active;
	}

	/**
	 * Whether every file of an offloaded attachment exists on local disk.
	 */
	public static function all_local( $attachment_id ) {
		$data = get_post_meta( (int) $attachment_id, Offloader::META_KEY, true );
		return ! empty( $data['objects'] ) && empty( self::missing_files( $data ) );
	}

	/**
	 * @return array<string,string> object key => local path, for files that
	 *                              don't exist locally
	 */
	private static function missing_files( array $data ) {
		$prefix  = Offloader::stored_prefix( $data );
		$missing = array();
		foreach ( (array) $data['objects'] as $object_key ) {
			$path = Offloader::local_path_for_key( $object_key, $prefix );
			if ( null !== $path && ! file_exists( $path ) ) {
				$missing[ $object_key ] = $path;
			}
		}
		return $missing;
	}

}
