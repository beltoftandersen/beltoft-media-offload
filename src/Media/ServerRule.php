<?php
namespace BeltoftMediaOffload\Media;

use BeltoftMediaOffload\S3\Client as S3Client;
use BeltoftMediaOffload\Support\Cache;
use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The web server rule that makes "Delete local files" safe: a request for
 * an upload that isn't on disk is redirected to the same path in the
 * bucket. Object keys mirror the uploads directory, so one rule covers
 * every link to every file, wherever it's stored.
 *
 * check() verifies, as a visitor would see it:
 * - the bucket serves files publicly at the URL the plugin links to
 *   ("bucket reachable": required for bucket URLs on the front end);
 * - a file missing locally redirects there, and a file that exists is
 *   still served ("rule verified": required for deleting local files).
 *
 * Apache: the rule lives in the uploads directory's .htaccess, written
 * once "Delete local files" is on and kept from then on; a failing check
 * puts the previous version back. nginx: nginx_snippet() in its config.
 */
class ServerRule {

	/**
	 * Last check: {base, bucket_ok, ok, message, checked_at}.
	 */
	const OPTION = 'bmo_server_rule';

	const CHECK_HOOK = 'bmo_server_check';

	const MARKER = 'Beltoft Media Offload';

	/**
	 * A verification older than this no longer allows local deletes (the
	 * daily check isn't running, so a broken rule would go unnoticed).
	 */
	const MAX_AGE = 3 * DAY_IN_SECONDS;

	/** @var bool Settings changed in this request; re-check at shutdown. */
	private static $recheck = false;

	/** @var array|null Per-request cache of state() + target_base(). */
	private static $cache = null;

	public static function init() {
		add_action( self::CHECK_HOOK, array( __CLASS__, 'check' ) );
		add_action( 'init', array( __CLASS__, 'schedule_daily_check' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'forget' ) );
		add_action( 'delete_option_' . self::OPTION, array( __CLASS__, 'forget' ) );
		foreach ( array( Options::OPTION, Options::SECRET_OPTION ) as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'schedule_recheck' ) );
			add_action( 'add_option_' . $option, array( __CLASS__, 'schedule_recheck' ) );
		}
	}

	/**
	 * One check per request however many settings changed, after they're
	 * all saved.
	 */
	public static function schedule_recheck() {
		self::$cache = null;
		if ( ! self::$recheck ) {
			self::$recheck = true;
			add_action( 'shutdown', array( __CLASS__, 'check' ) );
		}
	}

	/**
	 * Drops the per-request cache (the stored result changed).
	 */
	public static function forget() {
		self::$cache = null;
	}

	public static function schedule_daily_check() {
		if ( Options::is_configured() && ! wp_next_scheduled( self::CHECK_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CHECK_HOOK );
		}
	}

	/**
	 * Bucket URL this site's uploads directory maps to, with a trailing
	 * slash: <public domain or endpoint URL>/<key prefix>.
	 */
	public static function target_base() {
		return UrlRewriter::public_url_for_object( Offloader::key_prefix(), Offloader::current_location() );
	}

	/**
	 * URL path of this site's uploads directory, e.g. /wp-content/uploads.
	 */
	public static function uploads_path() {
		$path = wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
		return untrailingslashit( is_string( $path ) ? $path : '' );
	}

	/**
	 * Whether the bucket was verified to serve files publicly at the
	 * current URL: front-end pages may link to it.
	 */
	public static function bucket_reachable() {
		$c = self::cached();
		return $c['current'] && ! empty( $c['state']['bucket_ok'] );
	}

	/**
	 * Whether the redirect rule was verified for the current bucket URL
	 * recently: local files may be deleted.
	 */
	public static function is_verified() {
		$c        = self::cached();
		$verified = $c['current'] && ! empty( $c['state']['ok'] ) && time() - (int) $c['state']['checked_at'] < self::MAX_AGE;

		/**
		 * Whether the server rule counts as working. For hosts where the
		 * site can't request its own URLs (loopback blocked) but the rule
		 * was verified by hand.
		 *
		 * @param bool $verified Result of the last automatic check.
		 */
		return (bool) apply_filters( 'beltoft_media_offload_server_rule_verified', $verified );
	}

	private static function cached() {
		if ( null === self::$cache ) {
			$state       = self::state();
			self::$cache = array(
				'state'   => $state,
				'current' => '' !== $state['base'] && self::target_base() === $state['base'],
			);
		}
		return self::$cache;
	}

	/**
	 * @return array{base:string,bucket_ok:bool,ok:bool,message:string,checked_at:int}
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'base'       => '',
				'bucket_ok'  => false,
				'ok'         => false,
				'message'    => '',
				'checked_at' => 0,
			)
		);
	}

	/**
	 * Runs the checks (see the class comment) with a small test file
	 * uploaded to the bucket only, and one written locally only.
	 *
	 * @return array See state().
	 */
	public static function check() {
		$previous = self::state();
		$base     = self::target_base();
		$state    = array(
			'base'       => $base,
			'bucket_ok'  => false,
			'ok'         => false,
			'message'    => '',
			'checked_at' => time(),
		);

		$problem = self::base_problem( $base );
		if ( null !== $problem ) {
			$state['message'] = $problem;
			return self::save( $state, $previous );
		}

		$name   = 'beltoft-media-offload-check-' . wp_generate_password( 12, false ) . '.txt';
		$key    = Offloader::key_prefix() . $name;
		$token  = wp_generate_password( 24, false );
		$client = S3Client::from_options();
		$put    = $client->put_object( $key, $token, 'text/plain' );
		if ( is_wp_error( $put ) ) {
			/* translators: %s: error message */
			$state['message'] = sprintf( __( 'Could not upload a test file: %s', 'beltoft-media-offload' ), $put->get_error_message() );
			return self::save( $state, $previous );
		}

		$state['bucket_ok'] = self::serves( $base . $name, $token, 3 );
		if ( ! $state['bucket_ok'] ) {
			/* translators: %s: URL */
			$state['message'] = sprintf( __( 'The bucket does not serve files publicly at %s. Check the public domain and that the bucket allows public reads.', 'beltoft-media-offload' ), $base );
		} else {
			$state = self::check_rule( $state, $name, $token );
		}

		$client->delete_object( $key );
		return self::save( $state, $previous );
	}

	/**
	 * The redirect half of check(): writes the Apache rule when it's
	 * managed here, then requests a missing and an existing file. On
	 * failure the previous .htaccess block is put back, so a typo in the
	 * settings never repoints working links.
	 */
	private static function check_rule( array $state, $name, $token ) {
		$uploads  = wp_get_upload_dir();
		$htaccess = trailingslashit( $uploads['basedir'] ) . '.htaccess';
		$before   = self::read_block( $htaccess );
		$manage   = '1' === (string) Options::get( 'delete_local' ) || ! empty( $before );
		if ( $manage && ! self::write_block( $htaccess, self::apache_rules() ) ) {
			/* translators: %s: file path */
			$state['message'] = sprintf( __( 'Could not write %s; add the rule by hand.', 'beltoft-media-offload' ), $htaccess );
			return $state;
		}

		$local_name = 'beltoft-media-offload-local-' . wp_generate_password( 12, false ) . '.txt';
		$local_file = trailingslashit( $uploads['basedir'] ) . $local_name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a tiny probe file in uploads, removed right after.
		file_put_contents( $local_file, $token );

		$base     = trailingslashit( $uploads['baseurl'] );
		$response = self::request( $base . $name, 0 );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$location = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'location' );
		$expected = $state['base'] . $name;

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			$state['message'] = sprintf( __( 'The site could not request its own uploads URL: %s', 'beltoft-media-offload' ), $response->get_error_message() );
		} elseif ( ! ( ( $code >= 300 && $code < 400 && $location === $expected ) || ( 200 === $code && wp_remote_retrieve_body( $response ) === $token ) ) ) {
			$state['message'] = ( $code >= 300 && $code < 400 )
				/* translators: 1: URL the server redirected to, 2: expected URL */
				? sprintf( __( 'Files missing locally redirect to %1$s instead of %2$s.', 'beltoft-media-offload' ), $location, $expected )
				/* translators: %d: HTTP status code */
				: sprintf( __( 'A file missing locally answered HTTP %d instead of redirecting to the bucket. Add the server rule.', 'beltoft-media-offload' ), $code );
		} elseif ( ! self::serves( $base . $local_name, $token, 0 ) ) {
			$state['message'] = __( 'With the rule in place, files that exist locally are no longer served. The server does not allow this rule here.', 'beltoft-media-offload' );
		} else {
			$state['ok'] = true;
		}
		wp_delete_file( $local_file );

		if ( ! $state['ok'] && $manage ) {
			self::write_block( $htaccess, $before );
		}
		return $state;
	}

	/**
	 * Why a bucket URL can't work for visitors, or null.
	 */
	private static function base_problem( $base ) {
		if ( ! Options::is_configured() ) {
			return __( 'Enter the bucket and credentials first.', 'beltoft-media-offload' );
		}
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );
		if ( 0 !== strpos( $base, 'http://' ) && 0 !== strpos( $base, 'https://' ) || '' === $host ) {
			return __( 'No public bucket URL: set a public domain or a full endpoint URL.', 'beltoft-media-offload' );
		}
		$ip       = trim( $host, '[]' );
		$internal = false === strpos( $host, '.' )
			|| preg_match( '/\.(local|internal|localhost|lan|home\.arpa)$/i', $host )
			|| ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) && false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) );

		/**
		 * Whether a bucket URL on an internal host (single-label name,
		 * private IP, .local, ...) is allowed, e.g. for an intranet site.
		 *
		 * @param bool   $allowed Default false.
		 * @param string $host    Host name.
		 */
		if ( $internal && ! apply_filters( 'beltoft_media_offload_allow_internal_bucket_url', false, $host ) ) {
			/* translators: %s: host name */
			return sprintf( __( 'Visitors cannot reach %s: it is an internal address. Set a public domain for the bucket.', 'beltoft-media-offload' ), $host );
		}
		return null;
	}

	/**
	 * Whether $url answers 200 with exactly $body, following $redirects.
	 */
	private static function serves( $url, $body, $redirects ) {
		$response = self::request( $url, $redirects );
		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && wp_remote_retrieve_body( $response ) === $body;
	}

	private static function request( $url, $redirects ) {
		return wp_remote_get(
			$url,
			array(
				'redirection' => $redirects,
				'timeout'     => 15,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				// Same as core's own loopback requests (site health, cron).
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter for loopback requests.
			)
		);
	}

	private static function save( array $state, array $previous ) {
		update_option( self::OPTION, $state, false );
		self::$cache = null;
		// Cached pages link to the old bucket URL (or to it at all).
		if ( '' !== $previous['base'] && ( $previous['base'] !== $state['base'] || ! empty( $previous['bucket_ok'] ) !== ! empty( $state['bucket_ok'] ) ) ) {
			Cache::purge();
		}
		return $state;
	}

	/**
	 * Whether this web request runs on Apache (unknown under WP-CLI).
	 */
	public static function is_apache() {
		global $is_apache;
		return ! empty( $is_apache );
	}

	/**
	 * The .htaccess block for this site's uploads directory. The pattern is
	 * relative to that directory, so it works in subdirectory installs and
	 * multisite subsites (each has its own uploads directory). Inherit
	 * keeps rewrite rules of parent directories (image converters, hotlink
	 * protection) working for uploads; they run after this one, which only
	 * acts on missing files.
	 *
	 * @return string[] Lines.
	 */
	public static function apache_rules() {
		// Literal % and $ in the substitution would be read as references.
		$base = str_replace( array( '%', '$' ), array( '\\%', '\\$' ), self::target_base() );
		return array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteOptions Inherit',
			'RewriteCond %{REQUEST_FILENAME} !-f',
			'RewriteCond %{REQUEST_FILENAME} !-d',
			'RewriteRule ^(.+)$ ' . $base . '$1 [R=302,L]',
			'</IfModule>',
		);
	}

	/**
	 * @return string[] Rule lines of this plugin's block in $file, without
	 *                  the comment lines insert_with_markers() adds itself.
	 */
	private static function read_block( $file ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$lines = file_exists( $file ) ? extract_from_markers( $file, self::MARKER ) : array();
		return array_values(
			array_filter(
				$lines,
				function ( $line ) {
					return '' !== trim( $line ) && 0 !== strpos( ltrim( $line ), '#' );
				}
			)
		);
	}

	private static function write_block( $file, array $lines ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		return insert_with_markers( $file, self::MARKER, $lines );
	}

	/**
	 * The nginx config for this site, to add inside its server block.
	 *
	 * The ^~ prefix location takes precedence over regex locations, so the
	 * rule always applies to uploads; the nested locations keep the usual
	 * protections (hidden files, PHP), and WooCommerce's protected downloads
	 * stay reachable only internally (X-Accel-Redirect). $request_uri keeps
	 * the original URL encoding of the file name.
	 */
	public static function nginx_snippet() {
		$path  = self::uploads_path();
		$base  = self::target_base();
		$named = '@beltoft_media_offload_' . get_current_blog_id();
		$regex = preg_replace( '/[.\\\\+*?\\[\\]^$(){}|]/', '\\\\$0', $path );
		return implode(
			"\n",
			array(
				'# Beltoft Media Offload: uploads missing locally are served from the bucket.',
				'location ^~ ' . $path . '/woocommerce_uploads/ {',
				'    internal;',
				'}',
				'location ^~ ' . $path . '/ {',
				'    location ~ /\\. { deny all; }',
				'    location ~* \\.(php|phtml|phar)$ { deny all; }',
				'    # Add cache headers here (e.g. expires 30d;), and any WebP/AVIF',
				'    # variables before $uri, as other locations no longer apply here.',
				'    try_files $uri ' . $named . ';',
				'}',
				'location ' . $named . ' {',
				'    if ($request_uri ~ "^' . $regex . '/([^?]+)") {',
				'        return 302 ' . $base . '$1;',
				'    }',
				'    return 404;',
				'}',
			)
		);
	}
}
