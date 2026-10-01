<?php
namespace BeltoftMediaOffload\Licensing;

use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Remote license validation via the license server at beltoft.net. Same
 * server and protocol as this codebase's other Beltoft plugins (e.g.
 * beltoft-gift-cards-pro) — a free ($0) "subscription" product for this
 * plugin, keyed by plugin slug rather than a numeric item ID.
 *
 * Offloading itself is NOT gated by this — the plugin fully works without
 * a license. A license only unlocks WordPress's built-in update mechanism
 * (see Updater); it's entirely optional for anyone who's fine updating the
 * plugin manually.
 *
 * License statuses:
 *   'valid'           - Active license. Automatic updates enabled.
 *   'inactive'        - Expired/revoked on server. Updates disabled.
 *   'domain_mismatch' - Not activated on this domain. Updates disabled.
 *   'invalid_key'     - Key not found on server. Updates disabled.
 *   ''                - No key entered yet. Updates disabled (the default;
 *                       not itself an error state, so no admin notice).
 */
class License {

	public static function init() {
		// Self-healing: the activation hook only fires on a fresh activation,
		// not when plugin files are replaced in place during an update, so
		// re-check on every load rather than risk the cron silently vanishing.
		if ( ! wp_next_scheduled( 'bmo_license_check' ) ) {
			wp_schedule_event( time(), 'daily', 'bmo_license_check' );
		}

		add_action( 'bmo_license_check', array( __CLASS__, 'cron_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

		add_action( 'wp_ajax_bmo_activate_license', array( __CLASS__, 'ajax_activate' ) );
		add_action( 'wp_ajax_bmo_deactivate_license', array( __CLASS__, 'ajax_deactivate' ) );
	}

	/**
	 * Whether this site has an active license — the only thing this gates
	 * is Updater's automatic-update mechanism. Only 'valid' counts; unlike
	 * some license-gated Beltoft plugins, there's no separate "expired but
	 * still gets X" tier here, since there's nothing else to gate.
	 */
	public static function is_active() {
		return 'valid' === Options::get( 'license_status' );
	}

	/**
	 * @return array{success:bool,message:string}
	 */
	public static function activate( $key ) {
		$key = strtoupper( trim( (string) $key ) );

		if ( empty( $key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Please enter a license key.', 'beltoft-media-offload' ),
			);
		}

		$domain   = self::get_domain();
		$response = self::api_request(
			'/license/activate',
			array(
				'license_key' => $key,
				'domain'      => $domain,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not connect to license server. Please try again.', 'beltoft-media-offload' ),
			);
		}

		$http_code = isset( $response['_http_code'] ) ? $response['_http_code'] : 200;

		if ( 404 === $http_code ) {
			if ( self::is_license_not_found_response( $response ) ) {
				return array(
					'success' => false,
					'message' => __( 'License key not found. Please check and try again.', 'beltoft-media-offload' ),
				);
			}
			return array(
				'success' => false,
				'message' => __( 'License endpoint unavailable. Please try again in a moment.', 'beltoft-media-offload' ),
			);
		}

		if ( 403 === $http_code ) {
			$server_msg = ! empty( $response['message'] ) ? sanitize_text_field( $response['message'] ) : '';

			if ( false !== stripos( $server_msg, 'not activated on this domain' ) ) {
				Options::save(
					array(
						'license_key'    => $key,
						'license_status' => 'domain_mismatch',
					)
				);
			} else {
				Options::save(
					array(
						'license_key'    => $key,
						'license_status' => 'inactive',
					)
				);
			}

			return array(
				'success' => false,
				'message' => $server_msg ? $server_msg : __( 'License activation failed.', 'beltoft-media-offload' ),
			);
		}

		if ( empty( $response['success'] ) ) {
			return array(
				'success' => false,
				'message' => ! empty( $response['message'] )
					? sanitize_text_field( $response['message'] )
					: __( 'License activation failed.', 'beltoft-media-offload' ),
			);
		}

		$validate = self::api_request(
			'/license/validate',
			array(
				'license_key' => $key,
				'domain'      => $domain,
			)
		);

		$license_data = array(
			'license_key'          => $key,
			'license_status'       => 'valid',
			'license_last_checked' => current_time( 'mysql' ),
		);

		if ( ! is_wp_error( $validate ) && ! empty( $validate['valid'] ) ) {
			$license_data['license_expires']         = ! empty( $validate['expires_at'] ) ? sanitize_text_field( $validate['expires_at'] ) : '';
			$license_data['license_remote_version']  = ! empty( $validate['current_version'] ) ? sanitize_text_field( $validate['current_version'] ) : '';
			$license_data['license_max_activations'] = isset( $validate['max_activations'] ) ? absint( $validate['max_activations'] ) : '';
		}

		Options::save( $license_data );

		return array(
			'success' => true,
			'message' => __( 'License activated successfully.', 'beltoft-media-offload' ),
		);
	}

	/**
	 * Deactivate on the remote server and clear local activation state.
	 * Keeps the key stored so re-activation is a single click.
	 *
	 * @return array{success:bool,message:string}
	 */
	public static function deactivate() {
		$key = Options::get( 'license_key' );
		if ( empty( $key ) ) {
			return array(
				'success' => false,
				'message' => __( 'No license key to deactivate.', 'beltoft-media-offload' ),
			);
		}

		$response = self::api_request(
			'/license/deactivate',
			array(
				'license_key' => $key,
				'domain'      => self::get_domain(),
			)
		);

		$remote_unconfirmed = false;
		if ( is_wp_error( $response ) ) {
			$remote_unconfirmed = true;
		} else {
			$http_code = isset( $response['_http_code'] ) ? (int) $response['_http_code'] : 0;
			if ( 0 === $http_code || 429 === $http_code || $http_code >= 500 ) {
				$remote_unconfirmed = true;
			}
		}

		Options::save(
			array(
				'license_status'          => '',
				'license_expires'         => '',
				'license_last_checked'    => '',
				'license_remote_version'  => '',
				'license_max_activations' => '',
			)
		);

		return array(
			'success' => true,
			'message' => $remote_unconfirmed
				? __( 'License deactivated locally. Remote deactivation could not be confirmed right now.', 'beltoft-media-offload' )
				: __( 'License deactivated successfully.', 'beltoft-media-offload' ),
		);
	}

	/**
	 * Deactivate on the remote server only, without touching local state.
	 * Used on plugin deactivation to free the activation slot.
	 */
	public static function remote_deactivate() {
		$key = Options::get( 'license_key' );
		if ( empty( $key ) ) {
			return;
		}

		self::api_request(
			'/license/deactivate',
			array(
				'license_key' => $key,
				'domain'      => self::get_domain(),
			)
		);
	}

	/**
	 * Re-register this domain's activation after reactivation (the license
	 * key survives deactivation, but the remote activation slot doesn't).
	 */
	public static function reactivate_if_key_present() {
		$key = Options::get( 'license_key' );
		if ( ! empty( $key ) ) {
			self::activate( $key );
		}
	}

	/**
	 * Daily cron: re-validate the stored key against the server.
	 */
	public static function cron_check() {
		$key = Options::get( 'license_key' );
		if ( empty( $key ) ) {
			return;
		}

		$response = self::api_request(
			'/license/validate',
			array(
				'license_key' => $key,
				'domain'      => self::get_domain(),
			)
		);

		if ( is_wp_error( $response ) ) {
			Options::save( array( 'license_last_checked' => current_time( 'mysql' ) ) );
			return;
		}

		$http_code = isset( $response['_http_code'] ) ? $response['_http_code'] : 200;

		if ( 429 === $http_code || $http_code >= 500 ) {
			Options::save( array( 'license_last_checked' => current_time( 'mysql' ) ) );
			return;
		}

		if ( 404 === $http_code ) {
			if ( ! self::is_license_not_found_response( $response ) ) {
				Options::save( array( 'license_last_checked' => current_time( 'mysql' ) ) );
				return;
			}
			Options::save(
				array(
					'license_status'       => 'invalid_key',
					'license_last_checked' => current_time( 'mysql' ),
				)
			);
			return;
		}

		if ( 403 === $http_code ) {
			$server_msg = ! empty( $response['message'] ) ? $response['message'] : '';
			$new_status = ( false !== stripos( $server_msg, 'not activated on this domain' ) ) ? 'domain_mismatch' : 'inactive';

			Options::save(
				array(
					'license_status'       => $new_status,
					'license_last_checked' => current_time( 'mysql' ),
				)
			);
			return;
		}

		if ( ! empty( $response['valid'] ) && ! empty( $response['license_active'] ) ) {
			Options::save(
				array(
					'license_status'          => 'valid',
					'license_expires'         => ! empty( $response['expires_at'] ) ? sanitize_text_field( $response['expires_at'] ) : '',
					'license_last_checked'    => current_time( 'mysql' ),
					'license_remote_version'  => ! empty( $response['current_version'] ) ? sanitize_text_field( $response['current_version'] ) : '',
					'license_max_activations' => isset( $response['max_activations'] ) ? absint( $response['max_activations'] ) : '',
				)
			);
		} else {
			// Valid-but-inactive and outright-invalid both land here —
			// either way automatic updates stay disabled and the admin
			// notice tells the operator to re-check on the settings page.
			Options::save(
				array(
					'license_status'       => 'inactive',
					'license_last_checked' => current_time( 'mysql' ),
				)
			);
		}
	}

	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$status = Options::get( 'license_status' );
		$url    = admin_url( 'options-general.php?page=beltoft-media-offload' );

		// An empty status covers two cases, and neither gets a notice: no key
		// was ever entered (a normal, permanent state — a license is only for
		// automatic updates), or a key IS stored but deactivate() cleared the
		// status (a deliberate choice; deactivate() keeps the key so
		// re-activating is one click, so this is the expected steady state
		// for anyone who turned it off on purpose, not an error to nag about).
		if ( empty( $status ) ) {
			return;
		}

		if ( 'inactive' === $status ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				wp_kses(
					/* translators: %s: license settings page URL */
					__( 'Beltoft Media Offload: License inactive. <a href="%s">Check your license</a> to keep receiving automatic updates.', 'beltoft-media-offload' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $url )
			);
			echo '</p></div>';
			return;
		}

		if ( 'domain_mismatch' === $status ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				wp_kses(
					/* translators: %s: license settings page URL */
					__( 'Beltoft Media Offload: License is not activated on this domain. <a href="%s">Deactivate your old domain</a> and reactivate here.', 'beltoft-media-offload' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $url )
			);
			echo '</p></div>';
			return;
		}

		if ( 'invalid_key' === $status ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				wp_kses(
					/* translators: %s: license settings page URL */
					__( 'Beltoft Media Offload: License key not found. Please <a href="%s">check your license key</a>.', 'beltoft-media-offload' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $url )
			);
			echo '</p></div>';
		}
	}

	public static function ajax_activate() {
		check_ajax_referer( 'bmo_license', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beltoft-media-offload' ) ) );
		}

		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a license key.', 'beltoft-media-offload' ) ) );
		}

		$result = self::activate( $key );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	public static function ajax_deactivate() {
		check_ajax_referer( 'bmo_license', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beltoft-media-offload' ) ) );
		}

		$result = self::deactivate();
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Send a request to the license server API. Public so Updater can use it
	 * for the download endpoint.
	 *
	 * @return array|\WP_Error Decoded JSON response with '_http_code', or WP_Error.
	 */
	public static function api_request( $endpoint, array $body ) {
		$body['plugin_slug'] = dirname( BMO_BASENAME );
		$url                 = BMO_LICENSE_SERVER . $endpoint;

		$response = wp_remote_post(
			$url,
			array(
				'body'    => wp_json_encode( $body ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_body = (string) wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		$decoded['_http_code'] = (int) wp_remote_retrieve_response_code( $response );
		$decoded['_raw_body']  = $raw_body;

		return $decoded;
	}

	private static function is_license_not_found_response( array $response ) {
		$http_code = isset( $response['_http_code'] ) ? (int) $response['_http_code'] : 0;
		if ( 404 !== $http_code ) {
			return false;
		}

		$message = self::extract_response_message( $response );

		return false !== strpos( $message, 'license not found' )
			|| false !== strpos( $message, 'license key not found' );
	}

	private static function extract_response_message( array $response ) {
		$parts = array();

		if ( isset( $response['message'] ) && is_scalar( $response['message'] ) ) {
			$parts[] = (string) $response['message'];
		}
		if ( isset( $response['error'] ) && is_scalar( $response['error'] ) ) {
			$parts[] = (string) $response['error'];
		}
		if ( isset( $response['_raw_body'] ) && is_string( $response['_raw_body'] ) ) {
			$parts[] = $response['_raw_body'];
		}

		return strtolower( trim( implode( ' ', $parts ) ) );
	}

	private static function get_domain() {
		return untrailingslashit( home_url() );
	}
}
