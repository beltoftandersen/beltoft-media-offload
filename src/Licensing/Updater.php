<?php
namespace BeltoftMediaOffload\Licensing;

use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress update mechanism, gated on License::is_active(). Checks for new
 * versions via the cached license_remote_version option (populated by
 * License::cron_check()/activate()). Downloads use a fresh signed URL
 * fetched at download time via upgrader_pre_download, since signed URLs
 * have a short TTL.
 */
class Updater {

	const DOWNLOAD_SENTINEL = 'bmo-deferred-download';

	public static function init() {
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'get_download_package' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
	}

	/**
	 * Inject update information into the WordPress update transient.
	 */
	public static function check_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( ! License::is_active() ) {
			return $transient;
		}

		$remote_version = Options::get( 'license_remote_version' );
		if ( empty( $remote_version ) ) {
			return $transient;
		}

		if ( ! version_compare( BMO_VERSION, $remote_version, '<' ) ) {
			return $transient;
		}

		$update                = new \stdClass();
		$update->slug          = dirname( BMO_BASENAME );
		$update->plugin        = BMO_BASENAME;
		$update->new_version   = $remote_version;
		$update->url           = BMO_LICENSE_SERVER;
		$update->package       = self::DOWNLOAD_SENTINEL; // Real URL fetched in get_download_package().
		$update->requires_php  = '8.2';
		$update->tested        = get_bloginfo( 'version' );

		$transient->response[ BMO_BASENAME ] = $update;

		return $transient;
	}

	/**
	 * Provide plugin information for the "View details" modal.
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || dirname( BMO_BASENAME ) !== $args->slug ) {
			return $result;
		}

		$remote_version = Options::get( 'license_remote_version' );

		return (object) array(
			'name'         => 'Beltoft Media Offload',
			'slug'         => dirname( BMO_BASENAME ),
			'version'      => $remote_version ? $remote_version : BMO_VERSION,
			'author'       => '<a href="' . esc_url( BMO_LICENSE_SERVER ) . '">beltoft.net</a>',
			'homepage'     => BMO_LICENSE_SERVER,
			'requires'     => '6.2',
			'tested'       => get_bloginfo( 'version' ),
			'requires_php' => '8.2',
			'sections'     => array(
				'description' => 'Offloads media library uploads to any S3-compatible object storage bucket, with a bulk tool and WP-CLI support for existing media.',
			),
		);
	}

	/**
	 * Fetch a fresh signed download URL at the moment WordPress actually
	 * downloads the package — the transient stores a sentinel instead of a
	 * real URL because signed URLs have a short TTL.
	 *
	 * @return bool|string|\WP_Error
	 */
	public static function get_download_package( $reply, $package, $upgrader ) {
		if ( self::DOWNLOAD_SENTINEL !== $package ) {
			return $reply;
		}

		$license_key = Options::get( 'license_key' );
		if ( empty( $license_key ) ) {
			return new \WP_Error(
				'bmo_no_license',
				__( 'A valid license key is required to download updates.', 'beltoft-media-offload' )
			);
		}

		// Try up to 2 times: the signed URL may expire between the initial
		// request and the actual download.
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$result = self::fetch_and_download( $license_key );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			if ( 2 === $attempt ) {
				return $result;
			}
		}

		return new \WP_Error( 'bmo_download_error', __( 'Download failed.', 'beltoft-media-offload' ) );
	}

	/**
	 * @return string|\WP_Error Temp file path on success, WP_Error on failure.
	 */
	private static function fetch_and_download( $license_key ) {
		$response = License::api_request(
			'/license/download',
			array(
				'license_key' => $license_key,
				'domain'      => untrailingslashit( home_url() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'bmo_download_error',
				__( 'Could not connect to license server to download the update.', 'beltoft-media-offload' )
			);
		}

		if ( empty( $response['download_url'] ) ) {
			$message = ! empty( $response['message'] )
				? sanitize_text_field( $response['message'] )
				: __( 'Could not retrieve download URL from license server.', 'beltoft-media-offload' );

			return new \WP_Error( 'bmo_no_download_url', $message );
		}

		return download_url( esc_url_raw( $response['download_url'] ) );
	}

	/**
	 * Clear the cached remote version after a successful update, forcing
	 * the next cron check to re-fetch the latest version.
	 */
	public static function after_update( $upgrader, $options ) {
		if ( 'update' !== ( isset( $options['action'] ) ? $options['action'] : '' ) || 'plugin' !== ( isset( $options['type'] ) ? $options['type'] : '' ) ) {
			return;
		}

		// WP core uses different shapes depending on the update path. The
		// plugins.php "Update Now" link (AJAX, wp_ajax_update_plugin) and the
		// bulk-select flow both go through bulk_upgrade(), which sets a
		// plural 'plugins' array — already handled before this fix existed.
		// The singular 'plugin' string comes from Plugin_Upgrader::upgrade()
		// directly: the non-JS update.php?action=upgrade-plugin link, and
		// WP's own background/automatic updates (WP_Automatic_Updater). That
		// second, common path was the actual gap this fixes.
		$plugins = isset( $options['plugins'] ) ? (array) $options['plugins'] : array();
		if ( ! empty( $options['plugin'] ) ) {
			$plugins[] = $options['plugin'];
		}

		if ( ! in_array( BMO_BASENAME, $plugins, true ) ) {
			return;
		}

		Options::save( array( 'license_remote_version' => '' ) );
	}
}
