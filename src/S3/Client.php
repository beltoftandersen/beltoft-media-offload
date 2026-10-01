<?php
namespace BeltoftMediaOffload\S3;

use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\Core\Exception\Http\NetworkException;
use AsyncAws\S3\S3Client as AwsS3Client;
use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around async-aws/s3 exposing exactly the three operations
 * this plugin needs. Same public interface as the previous hand-rolled
 * SigV4 client, so Offloader and SettingsPage don't need to change.
 */
class Client {

	private $endpoint;
	private $access_key;
	private $secret_key;
	private $bucket;
	private $region;
	private $path_style;
	private $use_ssl;
	private $timeout;

	/** @var AwsS3Client|null */
	private $sdk;

	public function __construct( array $config ) {
		$this->endpoint   = untrailingslashit( (string) $config['endpoint'] );
		$this->access_key = (string) $config['access_key'];
		$this->secret_key = (string) $config['secret_key'];
		$this->bucket     = (string) $config['bucket'];
		$this->region     = ! empty( $config['region'] ) ? (string) $config['region'] : 'us-east-1';
		$this->path_style = ! empty( $config['path_style'] );
		$this->use_ssl    = ! empty( $config['use_ssl'] );
		$this->timeout    = ! empty( $config['timeout'] ) ? (float) $config['timeout'] : 15.0;
	}

	/**
	 * Builds a client from the saved settings.
	 *
	 * @param array $overrides Optional config keys (e.g. 'bucket', 'endpoint')
	 *                         that take precedence over the saved settings —
	 *                         used to target the bucket/endpoint an attachment
	 *                         was originally offloaded to.
	 */
	public static function from_options( array $overrides = array() ) {
		$config = array(
			'endpoint'   => Options::endpoint(),
			'access_key' => Options::get( 'access_key' ),
			'secret_key' => Options::secret_key(),
			'bucket'     => Options::get( 'bucket' ),
			'region'     => Options::get( 'region' ),
			'path_style' => '1' === (string) Options::get( 'path_style' ),
			'use_ssl'    => '1' === (string) Options::get( 'use_ssl' ),
			'timeout'    => 15,
		);

		foreach ( $overrides as $key => $value ) {
			if ( array_key_exists( $key, $config ) && null !== $value && '' !== $value ) {
				$config[ $key ] = $value;
			}
		}

		return new self( $config );
	}

	/**
	 * @param string|resource $body A string, or an open file resource (e.g.
	 *                              from fopen()) to stream the upload instead
	 *                              of loading the whole file into memory.
	 *                              Callers are responsible for closing a
	 *                              resource they pass in.
	 */
	public function put_object( $key, $body, $content_type = '' ) {
		$input = array(
			'Bucket' => $this->bucket,
			'Key'    => $key,
			'Body'   => $body,
		);
		if ( $content_type ) {
			$input['ContentType'] = $content_type;
		}

		return $this->call(
			function () use ( $input ) {
				return $this->sdk()->putObject( $input );
			}
		);
	}

	public function head_object( $key ) {
		return $this->call(
			function () use ( $key ) {
				return $this->sdk()->headObject(
					array(
						'Bucket' => $this->bucket,
						'Key'    => $key,
					)
				);
			}
		);
	}

	public function delete_object( $key ) {
		return $this->call(
			function () use ( $key ) {
				return $this->sdk()->deleteObject(
					array(
						'Bucket' => $this->bucket,
						'Key'    => $key,
					)
				);
			}
		);
	}

	/**
	 * Downloads an object to a local file. Streams into a temporary file in
	 * the same directory and renames it into place, so a partial download
	 * never appears under the real name and concurrent downloads of the
	 * same object can't interleave.
	 *
	 * @return true|\WP_Error
	 */
	public function download_object( $key, $path ) {
		$dir = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'bmo_mkdir_failed', 'Could not create directory: ' . $dir );
		}

		$tmp = $path . '.bmo-' . wp_generate_password( 8, false ) . '.tmp';
		try {
			$result = $this->sdk()->getObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $key,
				)
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a downloaded object to a local temp file.
			$out = fopen( $tmp, 'wb' );
			if ( false === $out ) {
				return new \WP_Error( 'bmo_write_failed', 'Could not write: ' . $tmp );
			}
			$written = 0;
			foreach ( $result->getBody()->getChunks() as $chunk ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming a downloaded object to a local temp file.
				$bytes = fwrite( $out, $chunk );
				if ( false === $bytes || strlen( $chunk ) !== $bytes ) {
					throw new \RuntimeException( 'Write failed (disk full?): ' . $tmp );
				}
				$written += $bytes;
				\BeltoftMediaOffload\Support\Heartbeat::beat();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the temp file opened above.
			if ( ! fclose( $out ) ) {
				throw new \RuntimeException( 'Could not finish writing: ' . $tmp );
			}

			// A truncated file must never be put in place: resync would upload
			// it over the intact object.
			$expected = $result->getContentLength();
			if ( null !== $expected && $written !== $expected ) {
				throw new \RuntimeException( sprintf( 'Incomplete download of %s: %d of %d bytes.', $key, $written, $expected ) );
			}
		} catch ( \Throwable $e ) {
			if ( isset( $out ) && is_resource( $out ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the temp file opened above.
				fclose( $out );
			}
			wp_delete_file( $tmp );
			return new \WP_Error( 'bmo_s3_error', self::message_with_cause( $e ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic move of the completed download into place.
		if ( ! rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'bmo_rename_failed', 'Could not move download into place: ' . $path );
		}
		return true;
	}

	/**
	 * Runs an SDK call, forces it to resolve (so failures surface here
	 * rather than lazily wherever the caller next touches the result), and
	 * translates any failure into a WP_Error.
	 *
	 * @return true|\WP_Error
	 */
	private function call( callable $operation ) {
		// Every request, not just streamed bodies, keeps a held lock alive:
		// a batch of slow deletes or HEADs must not let it expire.
		\BeltoftMediaOffload\Support\Heartbeat::beat();
		try {
			$result = $operation();
			$result->resolve();
			return true;
		} catch ( HttpException | NetworkException $e ) {
			return new \WP_Error( 'bmo_s3_http_error', self::message_with_cause( $e ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'bmo_s3_error', self::message_with_cause( $e ) );
		}
	}

	/**
	 * async-aws's own exception messages are generic ("Could not contact
	 * remote server."); the useful detail (e.g. "Could not resolve host")
	 * is on the wrapped Symfony transport exception.
	 */
	private static function message_with_cause( \Throwable $e ) {
		$message = $e->getMessage();
		$cause   = $e->getPrevious();
		if ( $cause && $cause->getMessage() && $cause->getMessage() !== $message ) {
			$message .= ' (' . $cause->getMessage() . ')';
		}
		return $message;
	}

	private function sdk() {
		if ( null === $this->sdk ) {
			$this->sdk = new AwsS3Client(
				array(
					'endpoint'          => $this->effective_endpoint(),
					'pathStyleEndpoint' => $this->path_style,
					'region'            => $this->region,
					'accessKeyId'       => $this->access_key,
					'accessKeySecret'   => $this->secret_key,
				),
				null,
				// Idle timeout, not a total one: long uploads keep going
				// while data moves, an unreachable bucket fails fast.
				\Symfony\Component\HttpClient\HttpClient::create( array( 'timeout' => $this->timeout ) )
			);
		}
		return $this->sdk;
	}

	/**
	 * When "Use SSL" is on, always talk https to the API regardless of the
	 * scheme written in the endpoint URL. When off, the endpoint's own scheme
	 * is used (defaulting to http if it has none).
	 */
	private function effective_endpoint() {
		$parsed = wp_parse_url( $this->endpoint );
		$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
		$port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';

		if ( $this->use_ssl ) {
			$scheme = 'https';
		} else {
			$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'http';
		}

		return $scheme . '://' . $host . $port;
	}
}
