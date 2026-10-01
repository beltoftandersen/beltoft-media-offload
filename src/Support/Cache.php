<?php
namespace BeltoftMediaOffload\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Purges full-page caches whose pages may link to bucket URLs that no
 * longer work: after the bucket URL changes, and after `unoffload`
 * deletes bucket copies.
 */
class Cache {

	public static function purge() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		// LiteSpeed Cache's documented purge action.
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a third-party plugin's hook, fired to purge its cache.

		/**
		 * Fires when cached pages should be purged because bucket URLs in
		 * them may no longer work. Hook any other page cache or CDN here.
		 */
		do_action( 'beltoft_media_offload_purge_page_cache' );
	}
}
