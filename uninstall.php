<?php
/**
 * Uninstall cleanup: removes plugin settings and working flags.
 *
 * Kept on purpose: bucket objects, the offload records (_bmo_offload_data,
 * the only record of what's in the bucket), _bmo_keep_local (files taken
 * out of the bucket must not be uploaded again by a reinstall) and the
 * uploads .htaccess rule (files whose local copy is gone keep working; it
 * only acts on files missing from disk).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans up the current site (on multisite, each site has its own options,
 * post meta and cron events).
 */
function beltoft_media_offload_uninstall_site() {
	global $wpdb;

	array_map(
		'delete_option',
		array(
			'bmo_options',
			'bmo_secret_access_key',
			'bmo_orphaned_objects',
			'bmo_bulk_lock',
			'bmo_bulk_inflight',
			'bmo_server_rule',
		)
	);
	array_map( 'wp_clear_scheduled_hook', array( 'bmo_retry_orphans', 'bmo_deferred_delete_sweep', 'bmo_server_check', 'bmo_server_recheck', 'bmo_license_check' ) );
	array_map( 'delete_post_meta_by_key', array( '_bmo_local_delete_pending', '_bmo_skip' ) );

	// Per-attachment lock rows left behind.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removing this plugin's own lock rows on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'bmo_att_lock_' ) . '%' ) );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $beltoft_media_offload_site_id ) {
		switch_to_blog( $beltoft_media_offload_site_id );
		beltoft_media_offload_uninstall_site();
		restore_current_blog();
	}
} else {
	beltoft_media_offload_uninstall_site();
}
