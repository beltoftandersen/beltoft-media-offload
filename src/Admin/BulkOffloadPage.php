<?php
namespace BeltoftMediaOffload\Admin;

use BeltoftMediaOffload\Media\Offloader;
use BeltoftMediaOffload\Support\Heartbeat;
use BeltoftMediaOffload\Support\Lock;

defined( 'ABSPATH' ) || exit;

class BulkOffloadPage {

	const BATCH_SIZE = 10;

	/**
	 * Seconds of work per AJAX request before handing back to the browser.
	 */
	const TIME_BUDGET = 15;

	/**
	 * Site-wide lock (see Support\Lock) so only one batch runs at a time — a retry after a proxy timeout, or a
	 * second tab, would otherwise work on the same attachments as a request
	 * still running. Considered abandoned after LOCK_TTL without a
	 * heartbeat (PHP died while holding it).
	 */
	const LOCK_OPTION = 'bmo_bulk_lock';
	const LOCK_TTL    = 900;

	/**
	 * Attachment the lock holder is currently uploading. Only read by the
	 * next holder, i.e. once the previous request is known to be gone: if
	 * it's still set, that request died on this attachment.
	 */
	const INFLIGHT_OPTION = 'bmo_bulk_inflight';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_bmo_bulk_batch', array( __CLASS__, 'ajax_batch' ) );
	}

	public static function add_menu() {
		add_media_page(
			__( 'Bulk Offload', 'beltoft-media-offload' ),
			__( 'Bulk Offload', 'beltoft-media-offload' ),
			'manage_options',
			'beltoft-media-offload-bulk',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue( $hook ) {
		if ( 'media_page_beltoft-media-offload-bulk' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'bmo-bulk-offload',
			plugins_url( 'assets/bulk-offload.js', BMO_FILE ),
			array(),
			BMO_VERSION,
			true
		);
		wp_localize_script(
			'bmo-bulk-offload',
			'bmoBulk',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bmo_bulk_batch' ),
			)
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'beltoft-media-offload' ) );
		}
		$remaining = Offloader::count_unoffloaded();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Offload Existing Media', 'beltoft-media-offload' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %d: number of attachments not yet offloaded */
					esc_html__( '%d attachment(s) not yet offloaded.', 'beltoft-media-offload' ),
					(int) $remaining
				);
				?>
			</p>
			<?php
			$orphans = count( Offloader::orphans() );
			if ( $orphans > 0 ) {
				echo '<div class="notice notice-warning inline"><p>';
				printf(
					/* translators: %d: number of bucket objects */
					esc_html__( '%d object(s) of deleted attachments could not be removed from the bucket. Deletion is retried automatically every hour, or run `wp media-offload retry-deletes`.', 'beltoft-media-offload' ),
					(int) $orphans
				);
				echo '</p></div>';
			}
			?>
			<button id="bmo-bulk-start" class="button button-primary"><?php esc_html_e( 'Offload All', 'beltoft-media-offload' ); ?></button>
			<div id="bmo-bulk-progress" style="margin-top:1em;"></div>
			<ul id="bmo-bulk-errors"></ul>
		</div>
		<?php
	}

	public static function ajax_batch() {
		check_ajax_referer( 'bmo_bulk_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'beltoft-media-offload' ) ), 403 );
		}

		// IDs that already failed earlier in this page session, so they
		// don't block later attachments from being reached.
		$exclude = array();
		if ( isset( $_POST['exclude'] ) ) {
			$raw     = sanitize_text_field( wp_unslash( $_POST['exclude'] ) );
			$exclude = array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
		}

		$lock = self::acquire_lock();
		if ( false === $lock ) {
			wp_send_json_success(
				array(
					'busy'      => true,
					'remaining' => Offloader::count_unoffloaded(),
				)
			);
		}

		$ok     = 0;
		$errors = array();
		$failed = $exclude;

		$stale = (int) get_option( self::INFLIGHT_OPTION, 0 );
		if ( $stale && ! Offloader::is_offloaded( $stale ) ) {
			/* translators: %d: attachment ID */
			$errors[] = sprintf( __( '%d: the request timed out or crashed while uploading this attachment; use `wp media-offload run` for very large files.', 'beltoft-media-offload' ), $stale );
			$failed[] = $stale;
		}
		delete_option( self::INFLIGHT_OPTION );

		$ids       = Offloader::unoffloaded_ids( self::BATCH_SIZE, 0, $failed );
		$processed = 0;
		$started   = microtime( true );

		foreach ( $ids as $id ) {
			// Stop taking new work well before a typical max_execution_time;
			// the next request continues where this one left off.
			if ( $processed > 0 && ( microtime( true ) - $started ) > self::TIME_BUDGET ) {
				break;
			}

			// Taken over (this request stalled past LOCK_TTL, e.g. waiting on a
			// slow bucket response): another batch runs now, so stop rather
			// than duplicate its work.
			if ( ! Lock::refresh( self::LOCK_OPTION, $lock ) ) {
				break;
			}
			update_option( self::INFLIGHT_OPTION, (int) $id, false );
			// Refreshed during the upload too, so one very large file can't
			// outlive the lock and let a second batch start.
			$result = Heartbeat::during(
				function () use ( $lock ) {
					Lock::refresh( self::LOCK_OPTION, $lock );
				},
				function () use ( $id ) {
					return Offloader::offload( $id );
				}
			);
			delete_option( self::INFLIGHT_OPTION );
			++$processed;

			// Excluded paths are flagged and drop out of later queries.
			if ( is_wp_error( $result ) && 'bmo_excluded' === $result->get_error_code() ) {
				continue;
			}
			if ( is_wp_error( $result ) ) {
				$errors[] = $id . ': ' . $result->get_error_message();
				$failed[] = (int) $id;
				continue;
			}
			++$ok;
		}

		Lock::release( self::LOCK_OPTION, $lock );

		$remaining = Offloader::count_unoffloaded();

		wp_send_json_success(
			array(
				'processed'  => $processed,
				'succeeded'  => $ok,
				'errors'     => $errors,
				'failed_ids' => array_values( array_unique( $failed ) ),
				'remaining'  => $remaining,
			)
		);
	}

	private static function acquire_lock() {
		return Lock::acquire( self::LOCK_OPTION, self::LOCK_TTL );
	}
}
