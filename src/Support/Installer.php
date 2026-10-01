<?php
namespace BeltoftMediaOffload\Support;

use BeltoftMediaOffload\Media\Offloader;
use BeltoftMediaOffload\Media\ServerRule;

defined( 'ABSPATH' ) || exit;

/**
 * Activation-time setup.
 */
class Installer {

	public static function activate() {
		if ( false === get_option( Options::OPTION, false ) ) {
			add_option( Options::OPTION, Options::defaults() );
		}
		// No default secret is seeded; created with autoload off so the flag
		// is right from the start.
		if ( false === get_option( Options::SECRET_OPTION, false ) ) {
			add_option( Options::SECRET_OPTION, '', '', false );
		}

		// Deactivation clears the scheduled events; restore what's needed.
		if ( ! empty( Offloader::orphans() ) ) {
			Offloader::schedule_orphan_retry();
		}
		if ( Offloader::count_deferred() > 0 ) {
			Offloader::schedule_sweep();
		}
		ServerRule::schedule_daily_check();
		if ( Options::is_configured() ) {
			ServerRule::check();
		}

		if ( ! wp_next_scheduled( 'bmo_license_check' ) ) {
			wp_schedule_event( time(), 'daily', 'bmo_license_check' );
		}
		// Deactivating frees the license's activation slot on the server;
		// re-register this domain on reactivation.
		\BeltoftMediaOffload\Licensing\License::reactivate_if_key_present();
	}

	/**
	 * The server rule stays: files whose local copy is gone keep working
	 * while the plugin is off.
	 */
	public static function deactivate() {
		\BeltoftMediaOffload\Licensing\License::remote_deactivate();
		wp_clear_scheduled_hook( 'bmo_license_check' );
		wp_clear_scheduled_hook( Offloader::RETRY_HOOK );
		wp_clear_scheduled_hook( Offloader::SWEEP_HOOK );
		wp_clear_scheduled_hook( ServerRule::CHECK_HOOK );
		wp_clear_scheduled_hook( ServerRule::RECHECK_HOOK );
	}
}
