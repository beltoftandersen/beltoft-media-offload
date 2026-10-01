<?php
namespace BeltoftMediaOffload\Media;

use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Points offloaded media at the bucket on front-end pages, through
 * WordPress's own URL filters only.
 *
 * Never in the admin, AJAX, REST, cron or WP-CLI: those keep local URLs,
 * so editors, page builders and API clients only ever store local upload
 * URLs. Stored data never depends on the bucket, and a local URL always
 * works — served from disk, or redirected to the bucket by the server
 * rule (see ServerRule) once the local file is gone.
 */
class UrlRewriter {

	public static function init() {
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 10, 5 );
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img_tag' ), 10, 3 );
	}

	/**
	 * Whether this request shows bucket URLs: a front-end page view, with
	 * the bucket verified to serve files publicly at the current URL (see
	 * ServerRule::check()). Otherwise local URLs, which work either way.
	 */
	public static function applies() {
		$front_end = ! is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI );

		/**
		 * Whether offloaded media is linked to the bucket in this request.
		 * Local URLs work everywhere either way.
		 *
		 * @param bool $front_end Whether this is a front-end page view.
		 */
		return (bool) apply_filters( 'beltoft_media_offload_bucket_urls', $front_end ) && ServerRule::bucket_reachable();
	}

	public static function filter_attachment_url( $url, $attachment_id ) {
		$data = self::record( $attachment_id );
		if ( ! $data ) {
			return $url;
		}
		$key = Offloader::object_key( get_attached_file( (int) $attachment_id, true ), Offloader::stored_prefix( $data ) );
		return in_array( $key, (array) $data['objects'], true ) ? self::public_url_for_object( $key, $data ) : $url;
	}

	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$data = self::record( $attachment_id );
		if ( ! $data || ! is_array( $sources ) ) {
			return $sources;
		}
		// $source['url'] ends in the file name; sizes share the original's
		// directory.
		$by_name = array();
		foreach ( (array) $data['objects'] as $object_key ) {
			$by_name[ basename( $object_key ) ] = $object_key;
		}
		foreach ( $sources as $width => $source ) {
			$name = basename( (string) $source['url'] );
			if ( isset( $by_name[ $name ] ) ) {
				$sources[ $width ]['url'] = self::public_url_for_object( $by_name[ $name ], $data );
			}
		}
		return $sources;
	}

	/**
	 * Image tags in post content carry local URLs (that's what editors
	 * store). Swapping them here saves visitors the redirect; any URL left
	 * local still works through the server rule.
	 */
	public static function filter_content_img_tag( $image, $context, $attachment_id ) {
		$data = self::record( $attachment_id );
		if ( ! $data ) {
			return $image;
		}
		$base = trailingslashit( wp_get_upload_dir()['baseurl'] );
		$map  = array();
		foreach ( (array) $data['objects'] as $object_key ) {
			$relative = Offloader::relative_path_for_key( $object_key, Offloader::stored_prefix( $data ) );
			if ( null !== $relative ) {
				$map[ $base . $relative ] = self::public_url_for_object( $object_key, $data );
			}
		}
		// strtr() replaces the longest match first: photo.jpg.webp before
		// photo.jpg.
		return strtr( $image, $map );
	}

	/**
	 * The attachment's offload record, when this request shows bucket URLs.
	 */
	private static function record( $attachment_id ) {
		if ( ! $attachment_id || ! self::applies() ) {
			return null;
		}
		$data = get_post_meta( (int) $attachment_id, Offloader::META_KEY, true );
		return empty( $data['objects'] ) ? null : $data;
	}

	/**
	 * Public URL of an object. With a public domain (e.g. a CDN), that
	 * domain plus the key (plus the bucket for path-style). Otherwise the
	 * endpoint the record was stored with: "Use SSL" forces https;
	 * path-style /bucket/key, virtual-hosted bucket.host/key only for real
	 * DNS names.
	 *
	 * @param string $object_key Object key ('' or a prefix gives the base
	 *                           URL, with a trailing slash).
	 * @param array  $data       Record or location (bucket, endpoint,
	 *                           path_style, use_ssl).
	 */
	public static function public_url_for_object( $object_key, array $data ) {
		$bucket      = (string) $data['bucket'];
		// Like core's own upload URLs: non-ASCII stays as is (core matches
		// srcset sources by file name); only characters that would end or
		// change a URL are escaped.
		$key_encoded = str_replace( array( '%', ' ', '#', '?', '"', '<', '>' ), array( '%25', '%20', '%23', '%3F', '%22', '%3C', '%3E' ), (string) $object_key );

		$domain = Options::public_domain();
		if ( '' !== $domain ) {
			if ( '1' === (string) Options::get( 'path_style' ) ) {
				return $domain . '/' . rawurlencode( $bucket ) . '/' . $key_encoded;
			}
			return $domain . '/' . $key_encoded;
		}

		$parsed = wp_parse_url( (string) $data['endpoint'] );
		if ( empty( $parsed['host'] ) ) {
			return '/' . $key_encoded;
		}
		$scheme = '1' === (string) $data['use_ssl'] ? 'https' : ( isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'http' );
		$host   = $parsed['host'];
		$port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';

		if ( '1' === (string) $data['path_style'] || ! self::supports_virtual_host( $host, $bucket, 'https' === $scheme ) ) {
			return $scheme . '://' . $host . $port . '/' . rawurlencode( $bucket ) . '/' . $key_encoded;
		}
		return $scheme . '://' . $bucket . '.' . $host . $port . '/' . $key_encoded;
	}

	/**
	 * Virtual-hosted URLs (bucket.host) only work for a real DNS name: not
	 * an IP address or single-label host (e.g. "minio"), and not a dotted
	 * bucket name over https, which a *.host wildcard certificate doesn't
	 * cover.
	 */
	private static function supports_virtual_host( $host, $bucket, $https ) {
		if ( false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) || false === strpos( $host, '.' ) ) {
			return false;
		}
		return ! ( $https && false !== strpos( $bucket, '.' ) );
	}
}
