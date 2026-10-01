<?php
namespace BeltoftMediaOffload\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings storage.
 *
 * Non-secret fields live in the autoloaded `bmo_options` array. The secret
 * access key lives in its own non-autoloaded option so it is never pulled
 * into every page load, and is never processed by the `bmo_options`
 * sanitize callback.
 */
class Options {

	const OPTION        = 'bmo_options';
	const SECRET_OPTION = 'bmo_secret_access_key';
	const SETTING_GROUP = 'bmo_settings';

	/**
	 * Checkbox posted with the settings form to remove the saved secret key
	 * (a blank secret field means "keep it", see sanitize_secret()).
	 */
	const CLEAR_SECRET_FIELD = 'bmo_clear_secret';

	/**
	 * Nothing site-specific is pre-filled: endpoint, bucket, credentials
	 * and public domain are entered under Settings > Media Offload. A
	 * blank endpoint means AWS S3 in the configured region (see
	 * endpoint()).
	 */
	public static function defaults() {
		return array(
			'enabled'       => '0',
			'endpoint'      => '',
			'access_key'    => '',
			'bucket'        => '',
			'region'        => 'us-east-1',
			'path_style'    => '1',
			'use_ssl'       => '0',
			'public_domain' => '',
			// Only takes effect while the server rule works (see
			// delete_local_enabled()).
			'delete_local'  => '0',

			// Extra path prefixes (relative to uploads) never offloaded, one
			// per line; ALWAYS_EXCLUDED is added regardless.
			'excluded_paths' => '',

			// License (see Licensing\License) — never posted by the main
			// settings form, only ever written internally via save().
			'license_key'             => '',
			'license_status'          => '',
			'license_expires'         => '',
			'license_last_checked'    => '',
			'license_remote_version'  => '',
			'license_max_activations' => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * The S3 API endpoint; blank means AWS S3 in the configured region.
	 */
	public static function endpoint() {
		$endpoint = (string) self::get( 'endpoint' );
		if ( '' !== $endpoint ) {
			return $endpoint;
		}
		$region = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) self::get( 'region' ) ) );
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the storage API endpoint this plugin talks to (the site owner's own bucket), not a remote script or style.
		return 'https://s3.' . ( '' !== $region ? $region : 'us-east-1' ) . '.amazonaws.com';
	}

	public static function secret_key() {
		$value = get_option( self::SECRET_OPTION, '' );
		return is_string( $value ) ? $value : '';
	}

	public static function is_enabled() {
		return '1' === (string) self::get( 'enabled' );
	}

	/**
	 * Whether local files are deleted after upload: the setting is on AND
	 * the server rule that sends requests for missing local files to the
	 * bucket was verified for the current bucket URL. Without that rule,
	 * every hard-coded link to a deleted file would break.
	 */
	public static function delete_local_enabled() {
		return '1' === (string) self::get( 'delete_local' ) && \BeltoftMediaOffload\Media\ServerRule::is_verified();
	}

	/**
	 * Bucket and access key set: the bucket can be talked to at all.
	 */
	public static function is_configured() {
		return '' !== (string) self::get( 'bucket' ) && '' !== (string) self::get( 'access_key' ) && '' !== self::secret_key();
	}

	/**
	 * Upload paths never offloaded, whatever the setting says: WooCommerce's
	 * protected downloadable files must never land in a public bucket.
	 */
	const ALWAYS_EXCLUDED = array( 'woocommerce_uploads/' );

	/**
	 * Path prefixes (relative to the uploads directory) never offloaded.
	 *
	 * @return string[]
	 */
	public static function excluded_paths() {
		$paths = array_merge( self::ALWAYS_EXCLUDED, self::parse_excluded_paths( (string) self::get( 'excluded_paths' ) ) );

		/**
		 * Path prefixes (relative to the uploads directory) never offloaded.
		 *
		 * @param string[] $paths Prefixes.
		 */
		return self::normalize_paths( (array) apply_filters( 'beltoft_media_offload_excluded_paths', $paths ) );
	}

	/**
	 * Whether a path relative to the uploads directory lies under one of
	 * the excluded prefixes. The single place that decides it.
	 */
	public static function path_is_excluded( $relative ) {
		$relative = ltrim( (string) $relative, '/' );
		foreach ( self::excluded_paths() as $prefix ) {
			if ( 0 === strpos( $relative, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The "Never offload" setting (one path per line) as a list of
	 * normalised prefixes.
	 *
	 * @return string[]
	 */
	public static function parse_excluded_paths( $text ) {
		return self::normalize_paths( preg_split( '/\r\n|\r|\n/', (string) $text ) );
	}

	/**
	 * Trims whitespace and leading slashes, drops empty entries (an empty
	 * prefix would match everything) and duplicates.
	 *
	 * @param array $paths Raw path prefixes.
	 * @return string[]
	 */
	public static function normalize_paths( array $paths ) {
		$out = array();
		foreach ( $paths as $path ) {
			$path = ltrim( trim( (string) $path ), '/' );
			if ( '' !== $path ) {
				$out[] = $path;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function public_domain() {
		return untrailingslashit( (string) self::get( 'public_domain' ) );
	}

	/**
	 * Programmatic write helper for trusted internal callers (Licensing\License,
	 * ad hoc scripts) — never fed raw request input directly. Accepts the same
	 * shape as defaults() plus an optional 'secret_key'. Splits the secret into
	 * its own option so it never enters the autoloaded array.
	 *
	 * Bypasses the registered Settings API sanitize callback: sanitize() always
	 * ignores license_* fields coming from $input (a settings-form submit must
	 * never be able to wipe or forge license state — see sanitize() below), so
	 * it can't be used to WRITE a new license value either. This is the only
	 * path that can. Merges into the current stored values (not defaults()),
	 * so a partial write — like License::activate() setting just the license_*
	 * keys — never touches the storage settings sitting alongside them.
	 */
	public static function save( array $values ) {
		if ( array_key_exists( 'secret_key', $values ) ) {
			// Also bypasses sanitize_secret(), whose "blank keeps the current
			// value" rule is for the form only; here '' really clears it.
			$secret_hook = 'sanitize_option_' . self::SECRET_OPTION;
			$secret_cb   = array( __CLASS__, 'sanitize_secret' );
			$secret_prio = has_filter( $secret_hook, $secret_cb );
			if ( false !== $secret_prio ) {
				remove_filter( $secret_hook, $secret_cb, (int) $secret_prio );
			}
			update_option( self::SECRET_OPTION, (string) $values['secret_key'], false );
			if ( false !== $secret_prio ) {
				add_filter( $secret_hook, $secret_cb, (int) $secret_prio );
			}
			unset( $values['secret_key'] );
		}

		$merged = wp_parse_args( $values, self::all() );

		$sanitize_hook = 'sanitize_option_' . self::OPTION;
		$sanitize_cb   = array( __CLASS__, 'sanitize' );
		$priority      = has_filter( $sanitize_hook, $sanitize_cb );

		if ( false !== $priority ) {
			remove_filter( $sanitize_hook, $sanitize_cb, (int) $priority );
		}

		update_option( self::OPTION, $merged );

		if ( false !== $priority ) {
			add_filter( $sanitize_hook, $sanitize_cb, (int) $priority );
		}
	}

	/**
	 * Settings API sanitize callback for `bmo_options`. Only ever reached via
	 * a real settings-form submit, or via update_option() while the filter
	 * above is attached (i.e. anything that isn't save()'s bypassed write).
	 */
	public static function sanitize( $input ) {
		$out = self::defaults();
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$out['enabled']    = ! empty( $input['enabled'] ) ? '1' : '0';
		$out['endpoint']   = isset( $input['endpoint'] ) ? esc_url_raw( untrailingslashit( trim( $input['endpoint'] ) ) ) : $out['endpoint'];
		$out['access_key'] = isset( $input['access_key'] ) ? sanitize_text_field( $input['access_key'] ) : $out['access_key'];
		// S3 bucket names: lowercase letters, digits, dots and hyphens (dots are legal and needed for CNAME-style buckets).
		$out['bucket']     = isset( $input['bucket'] ) ? preg_replace( '/[^a-z0-9.\-]/', '', strtolower( trim( (string) $input['bucket'] ) ) ) : $out['bucket'];
		$out['region']     = isset( $input['region'] ) ? sanitize_text_field( $input['region'] ) : $out['region'];
		$out['path_style'] = ! empty( $input['path_style'] ) ? '1' : '0';
		$out['use_ssl']    = ! empty( $input['use_ssl'] ) ? '1' : '0';

		$out['public_domain'] = isset( $input['public_domain'] ) && '' !== trim( $input['public_domain'] )
			? esc_url_raw( untrailingslashit( trim( $input['public_domain'] ) ) )
			: '';

		$out['delete_local'] = ! empty( $input['delete_local'] ) ? '1' : '0';

		// One bucket location per site: the server rule points all uploads
		// at one URL, so moving it while media is offloaded would break
		// every file whose local copy is gone.
		$current = self::all();
		$locked  = false;
		foreach ( array( 'endpoint', 'bucket', 'path_style', 'use_ssl' ) as $field ) {
			if ( (string) $out[ $field ] !== (string) $current[ $field ] && \BeltoftMediaOffload\Media\Offloader::has_offloaded() ) {
				$out[ $field ] = $current[ $field ];
				$locked        = true;
			}
		}
		if ( $locked && function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'bmo_location_locked', __( 'Endpoint, bucket, path-style and SSL were not changed: media is offloaded to the current bucket. Take it out first (wp media-offload unoffload), or move the objects and change the settings in the database.', 'beltoft-media-offload' ) );
		}

		$excluded              = isset( $input['excluded_paths'] ) ? self::parse_excluded_paths( sanitize_textarea_field( (string) $input['excluded_paths'] ) ) : array();
		$out['excluded_paths'] = implode( "\n", $excluded );

		// License fields are deliberately never taken from $input, under any
		// circumstance — the main settings form never includes them (so a
		// normal submit must not silently wipe the license), and a crafted
		// POST adding them must not be able to forge a license status either.
		// Always keep whatever is currently stored; only save()'s filter-
		// bypassed write path (used by Licensing\License) can change them.
		foreach ( array( 'license_key', 'license_status', 'license_expires', 'license_last_checked', 'license_remote_version', 'license_max_activations' ) as $license_field ) {
			$out[ $license_field ] = $current[ $license_field ];
		}

		return $out;
	}

	/**
	 * Settings API sanitize callback for `bmo_secret_access_key`. The
	 * settings page never echoes the stored secret back into the form, so a
	 * blank submit means "unchanged" and keeps the current value.
	 */
	public static function sanitize_secret( $input ) {
		// options.php has already verified the settings form's nonce and
		// capability before any sanitize callback runs.
		if ( ! empty( $_POST[ self::CLEAR_SECRET_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by options.php (check_admin_referer) before sanitize callbacks run.
			return '';
		}
		$input = sanitize_text_field( (string) $input );
		return '' === $input ? self::secret_key() : $input;
	}
}
