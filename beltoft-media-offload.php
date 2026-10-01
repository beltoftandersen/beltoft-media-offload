<?php
/**
 * Plugin Name:       Beltoft Media Offload
 * Description:       Offloads media library uploads to any S3-compatible object storage bucket. A web server rule serves files missing locally from the bucket, so local copies can be deleted safely.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            Internal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beltoft-media-offload
 *
 * @package BeltoftMediaOffload
 */

defined( 'ABSPATH' ) || exit;

// Vendored dependencies (async-aws/s3), committed under vendor/.
require_once __DIR__ . '/vendor/autoload.php';

// PSR-4 autoloader for the BeltoftMediaOffload\ namespace.
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'BeltoftMediaOffload\\' ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( 'BeltoftMediaOffload\\' ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
		$file     = plugin_dir_path( __FILE__ ) . 'src/' . $relative;
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

define( 'BMO_VERSION', '2.0.0' );

// Tells plugins that generate files for an attachment in the background
// (beltoft-webp) that "delete local files" waits while they report the
// attachment as pending (see Media\Offloader::should_defer_local_delete()).
define( 'BMO_SUPPORTS_DEFERRED_DELETE', true );

define( 'BMO_FILE', __FILE__ );
define( 'BMO_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMO_BASENAME', plugin_basename( __FILE__ ) );
define( 'BMO_LICENSE_SERVER', 'https://beltoft.net' );

register_activation_hook( __FILE__, array( 'BeltoftMediaOffload\\Support\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BeltoftMediaOffload\\Support\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		\BeltoftMediaOffload\Licensing\License::init();
		\BeltoftMediaOffload\Licensing\Updater::init();
		\BeltoftMediaOffload\Media\AttachmentHooks::init();
		\BeltoftMediaOffload\Media\UrlRewriter::init();
		\BeltoftMediaOffload\Media\ServerRule::init();
		\BeltoftMediaOffload\Media\LocalFetcher::init();
		if ( is_admin() ) {
			\BeltoftMediaOffload\Admin\SettingsPage::init();
			\BeltoftMediaOffload\Admin\BulkOffloadPage::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\BeltoftMediaOffload\Cli\OffloadCommand::register();
		}
	}
);

/**
 * Makes sure every file of an attachment exists on local disk, downloading
 * anything missing from the bucket. For code that reads media from disk
 * (PDF generators, exports) while "Delete local files" is on; the
 * downloaded files are removed again later by the sweep.
 *
 * @param int $attachment_id Attachment ID.
 * @return true|WP_Error
 */
function beltoft_media_offload_ensure_local( $attachment_id ) {
	return \BeltoftMediaOffload\Media\LocalFetcher::ensure_local( $attachment_id );
}
