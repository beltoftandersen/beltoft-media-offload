<?php
namespace BeltoftMediaOffload\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Cross-request lock stored as an options row. add_option() can't be used:
 * it's a read followed by an upsert, so two requests can both "win". Here
 * the acquire is a single INSERT IGNORE on the unique option_name, so only
 * one request's insert lands.
 *
 * The row holds "<owner token>|<heartbeat time>". Only the holder's token
 * can refresh or release it, so a request whose lock was taken over (after
 * $ttl seconds without a heartbeat) can never free the new holder's lock.
 */
class Lock {

	/**
	 * @return string|false Owner token to pass to refresh()/release(), or
	 *                      false if someone else holds the lock.
	 */
	public static function acquire( $name, $ttl ) {
		global $wpdb;

		// Take over an abandoned lock (no heartbeat for $ttl seconds).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock row; must bypass the options cache.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d", $name, time() - (int) $ttl ) );

		$token = wp_generate_password( 12, false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock row; must bypass the options cache.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token . '|' . time() ) );

		return 1 === (int) $wpdb->rows_affected ? $token : false;
	}

	/**
	 * Heartbeat: keeps a long-running holder's lock from looking abandoned.
	 *
	 * @return bool False if the lock is no longer ours (taken over).
	 */
	public static function refresh( $name, $token ) {
		global $wpdb;
		// Microsecond precision so the row always changes: MySQL counts only
		// changed rows, and "no row changed" must mean "not ours".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock row; must bypass the options cache.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", $token . '|' . sprintf( '%.6F', microtime( true ) ), $name, $wpdb->esc_like( $token . '|' ) . '%' ) );
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Releases the lock only if it's still ours.
	 */
	public static function release( $name, $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock row; must bypass the options cache.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $name, $wpdb->esc_like( $token . '|' ) . '%' ) );
		wp_cache_delete( $name, 'options' );
	}
}
