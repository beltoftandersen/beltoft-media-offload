<?php
namespace BeltoftMediaOffload\Admin;

use BeltoftMediaOffload\Licensing\License;
use BeltoftMediaOffload\Media\Offloader;
use BeltoftMediaOffload\Media\ServerRule;
use BeltoftMediaOffload\S3\Client as S3Client;
use BeltoftMediaOffload\Support\Options;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_bmo_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_post_bmo_check_server', array( __CLASS__, 'handle_check_server' ) );
	}

	public static function enqueue( $hook ) {
		if ( 'settings_page_beltoft-media-offload' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'bmo-admin-license',
			plugins_url( 'assets/admin-license.js', BMO_FILE ),
			array(),
			BMO_VERSION,
			true
		);
		wp_localize_script(
			'bmo-admin-license',
			'bmoLicense',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bmo_license' ),
				'i18n'    => array(
					'activate'            => __( 'Activate', 'beltoft-media-offload' ),
					'activating'          => __( 'Activating…', 'beltoft-media-offload' ),
					'activationFailed'    => __( 'Activation failed.', 'beltoft-media-offload' ),
					'deactivate'          => __( 'Deactivate', 'beltoft-media-offload' ),
					'deactivating'        => __( 'Deactivating…', 'beltoft-media-offload' ),
					'deactivationFailed'  => __( 'Deactivation failed.', 'beltoft-media-offload' ),
					'confirmDeactivate'   => __( 'Deactivate this license on this site?', 'beltoft-media-offload' ),
					'requestFailed'       => __( 'Request failed. Please try again.', 'beltoft-media-offload' ),
				),
			)
		);
	}

	public static function add_menu() {
		add_options_page(
			__( 'Media Offload', 'beltoft-media-offload' ),
			__( 'Media Offload', 'beltoft-media-offload' ),
			Options::capability(),
			'beltoft-media-offload',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		// options.php checks this when the form is saved.
		add_filter(
			'option_page_capability_' . Options::SETTING_GROUP,
			function () {
				return Options::capability();
			}
		);
		register_setting(
			Options::SETTING_GROUP,
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'BeltoftMediaOffload\\Support\\Options', 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);
		register_setting(
			Options::SETTING_GROUP,
			Options::SECRET_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( 'BeltoftMediaOffload\\Support\\Options', 'sanitize_secret' ),
				'default'           => '',
			)
		);
	}

	public static function render_page() {
		if ( ! current_user_can( Options::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'beltoft-media-offload' ) );
		}

		$options = Options::all();
		$secret  = Options::secret_key();

		if ( isset( $_GET['bmo_test_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a prior admin-post result, not a state change.
			$result = sanitize_text_field( wp_unslash( $_GET['bmo_test_result'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'success' === $result ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Connection test succeeded.', 'beltoft-media-offload' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Connection test failed: ', 'beltoft-media-offload' ) . esc_html( $result ) . '</p></div>';
			}
		}
		$still_offloaded = count( Offloader::offloaded_excluded_ids() );
		if ( $still_offloaded > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of attachments */
					_n( '%d attachment under a "Never offload" path (for example a WooCommerce download) was offloaded before it was excluded and is still in the public bucket. Run `wp media-offload unoffload --excluded` to bring it back and remove it from the bucket.', '%d attachments under a "Never offload" path (for example WooCommerce downloads) were offloaded before they were excluded and are still in the public bucket. Run `wp media-offload unoffload --excluded` to bring them back and remove them from the bucket.', $still_offloaded, 'beltoft-media-offload' ),
					$still_offloaded
				)
			) . '</p></div>';
		}
		$rule = ServerRule::state();
		if ( '1' === (string) $options['delete_local'] && ! ServerRule::is_verified() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( '"Delete local files" is on but paused: the server rule below is not working, so local files are kept until it is.', 'beltoft-media-offload' ) . '</p></div>';
		}
		$license_key    = Options::get( 'license_key' );
		$license_status = Options::get( 'license_status' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Media Offload Settings', 'beltoft-media-offload' ); ?></h1>

			<h2><?php esc_html_e( 'License', 'beltoft-media-offload' ); ?></h2>
			<p>
				<?php
				if ( License::is_active() ) {
					esc_html_e( 'License active — automatic updates are enabled.', 'beltoft-media-offload' );
				} else {
					esc_html_e( 'Optional. A free license enables automatic updates for this plugin; everything else works without one.', 'beltoft-media-offload' );
				}
				?>
			</p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="bmo-license-key"><?php esc_html_e( 'License Key', 'beltoft-media-offload' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="bmo-license-key" value="<?php echo esc_attr( $license_key ); ?>" <?php disabled( License::is_active() ); ?> />
						<?php if ( License::is_active() ) : ?>
							<button type="button" id="bmo-deactivate-license" class="button"><?php esc_html_e( 'Deactivate', 'beltoft-media-offload' ); ?></button>
						<?php else : ?>
							<button type="button" id="bmo-activate-license" class="button button-primary"><?php esc_html_e( 'Activate', 'beltoft-media-offload' ); ?></button>
						<?php endif; ?>
						<p id="bmo-license-message"></p>
						<?php if ( $license_status && ! License::is_active() ) : ?>
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: license status */ __( 'Status: %s', 'beltoft-media-offload' ), $license_status ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Storage Settings', 'beltoft-media-offload' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( Options::SETTING_GROUP ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="bmo_enabled"><?php esc_html_e( 'Enabled', 'beltoft-media-offload' ); ?></label></th>
						<td><input type="checkbox" id="bmo_enabled" name="<?php echo esc_attr( Options::OPTION ); ?>[enabled]" value="1" <?php checked( '1', $options['enabled'] ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_endpoint"><?php esc_html_e( 'Endpoint URL', 'beltoft-media-offload' ); ?></label></th>
						<td><input type="text" class="regular-text" id="bmo_endpoint" name="<?php echo esc_attr( Options::OPTION ); ?>[endpoint]" value="<?php echo esc_attr( $options['endpoint'] ); ?>" placeholder="<?php echo esc_attr( Options::endpoint() ); ?>" /> <span class="description"><?php esc_html_e( 'Leave blank for AWS S3 in the region below. Endpoint, bucket, path-style and SSL can\'t be changed while media is offloaded.', 'beltoft-media-offload' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_access_key"><?php esc_html_e( 'Access Key', 'beltoft-media-offload' ); ?></label></th>
						<td><input type="text" class="regular-text" id="bmo_access_key" name="<?php echo esc_attr( Options::OPTION ); ?>[access_key]" value="<?php echo esc_attr( $options['access_key'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_secret_key"><?php esc_html_e( 'Secret Key', 'beltoft-media-offload' ); ?></label></th>
						<td>
							<?php // The stored secret is never printed into the page; a blank submit keeps it (see Options::sanitize_secret()). ?>
							<input type="password" class="regular-text" id="bmo_secret_key" name="<?php echo esc_attr( Options::SECRET_OPTION ); ?>" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $secret ? __( 'Saved — leave blank to keep', 'beltoft-media-offload' ) : '' ); ?>" />
							<?php if ( '' !== $secret ) : ?>
								<p class="description"><?php esc_html_e( 'A secret key is saved. Enter a new one only to replace it.', 'beltoft-media-offload' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( Options::CLEAR_SECRET_FIELD ); ?>" value="1" /> <?php esc_html_e( 'Remove the saved secret key', 'beltoft-media-offload' ); ?></label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_bucket"><?php esc_html_e( 'Bucket', 'beltoft-media-offload' ); ?></label></th>
						<td><input type="text" class="regular-text" id="bmo_bucket" name="<?php echo esc_attr( Options::OPTION ); ?>[bucket]" value="<?php echo esc_attr( $options['bucket'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_region"><?php esc_html_e( 'Region', 'beltoft-media-offload' ); ?></label></th>
						<td><input type="text" class="regular-text" id="bmo_region" name="<?php echo esc_attr( Options::OPTION ); ?>[region]" value="<?php echo esc_attr( $options['region'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_path_style"><?php esc_html_e( 'Path-Style Addressing', 'beltoft-media-offload' ); ?></label></th>
						<td><input type="checkbox" id="bmo_path_style" name="<?php echo esc_attr( Options::OPTION ); ?>[path_style]" value="1" <?php checked( '1', $options['path_style'] ); ?> /> <span class="description"><?php esc_html_e( 'Required for MinIO.', 'beltoft-media-offload' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_use_ssl"><?php esc_html_e( 'Use SSL for API calls', 'beltoft-media-offload' ); ?></label></th>
						<td><input type="checkbox" id="bmo_use_ssl" name="<?php echo esc_attr( Options::OPTION ); ?>[use_ssl]" value="1" <?php checked( '1', $options['use_ssl'] ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_public_domain"><?php esc_html_e( 'Public Domain', 'beltoft-media-offload' ); ?></label></th>
						<td><input type="text" class="regular-text" id="bmo_public_domain" name="<?php echo esc_attr( Options::OPTION ); ?>[public_domain]" value="<?php echo esc_attr( $options['public_domain'] ); ?>" placeholder="https://media.example.com" /> <span class="description"><?php esc_html_e( 'Used to build the public URL for offloaded media. Leave blank to serve directly from the endpoint above (only works if that endpoint is itself publicly reachable).', 'beltoft-media-offload' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_delete_local"><?php esc_html_e( 'Delete local files after upload', 'beltoft-media-offload' ); ?></label></th>
						<td><input type="checkbox" id="bmo_delete_local" name="<?php echo esc_attr( Options::OPTION ); ?>[delete_local]" value="1" <?php checked( '1', $options['delete_local'] ); ?> />
							<p class="description"><?php esc_html_e( 'Only takes effect while the server rule below works: it sends requests for files missing locally to the bucket, so every link keeps working. Image editing, thumbnail regeneration and plugins that ask for it download files back when needed.', 'beltoft-media-offload' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmo_excluded_paths"><?php esc_html_e( 'Never offload', 'beltoft-media-offload' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="3" id="bmo_excluded_paths" name="<?php echo esc_attr( Options::OPTION ); ?>[excluded_paths]"><?php echo esc_textarea( $options['excluded_paths'] ); ?></textarea>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: always-excluded paths */
										__( 'Paths inside uploads never to offload, one per line (e.g. "2024/private/"). Files offloaded before a path was excluded stay in the bucket until `wp media-offload unoffload --excluded`. Always excluded: %s', 'beltoft-media-offload' ),
										implode( ', ', Options::ALWAYS_EXCLUDED )
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bmo_test_connection" />
				<?php wp_nonce_field( 'bmo_test_connection' ); ?>
				<?php submit_button( __( 'Test Connection', 'beltoft-media-offload' ), 'secondary' ); ?>
			</form>

			<h2 id="bmo-server-rule"><?php esc_html_e( 'Server rule', 'beltoft-media-offload' ); ?></h2>
			<p><?php esc_html_e( 'Requests for uploads that are no longer on disk are redirected to the same file in the bucket. Required for "Delete local files".', 'beltoft-media-offload' ); ?></p>
			<?php
			$current = $rule['checked_at'] && $rule['base'] === ServerRule::target_base();
			$lines   = array(
				__( 'Bucket serves files publicly:', 'beltoft-media-offload' ) => ServerRule::bucket_reachable(),
				__( 'Files missing locally are redirected to it:', 'beltoft-media-offload' ) => ServerRule::is_verified(),
			);
			echo '<ul>';
			foreach ( $lines as $label => $ok ) {
				echo '<li><strong>' . esc_html( $label ) . '</strong> ' . ( $ok ? '<span style="color:#008a20;">' . esc_html__( 'yes', 'beltoft-media-offload' ) . '</span>' : '<span style="color:#b32d2e;">' . esc_html__( 'no', 'beltoft-media-offload' ) . '</span>' ) . '</li>';
			}
			echo '</ul>';
			if ( ! $current ) {
				echo '<p>' . esc_html__( 'Not checked for the current settings yet.', 'beltoft-media-offload' ) . '</p>';
			} elseif ( '' !== $rule['message'] ) {
				echo '<p style="color:#b32d2e;">' . esc_html( $rule['message'] ) . '</p>';
			}
			if ( $rule['checked_at'] ) {
				/* translators: %s: date and time */
				echo '<p class="description">' . esc_html( sprintf( __( 'Last checked %s; checked again daily and whenever settings change. Without a public bucket, media is linked locally; without the redirect, local files are kept.', 'beltoft-media-offload' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $rule['checked_at'] ) ) ) . '</p>';
			}
			?>
			<?php if ( ServerRule::is_apache() ) : ?>
				<p><?php esc_html_e( 'Apache: the rule is written to the .htaccess file in the uploads directory once "Delete local files" is on, and kept from then on.', 'beltoft-media-offload' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'nginx: add this inside the site\'s server block (replacing any existing location for the uploads directory), reload nginx, then check again.', 'beltoft-media-offload' ); ?></p>
				<textarea class="large-text code" rows="14" readonly="readonly"><?php echo esc_textarea( ServerRule::nginx_snippet() ); ?></textarea>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bmo_check_server" />
				<?php wp_nonce_field( 'bmo_check_server' ); ?>
				<?php submit_button( __( 'Check now', 'beltoft-media-offload' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_check_server() {
		if ( ! current_user_can( Options::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'beltoft-media-offload' ) );
		}
		check_admin_referer( 'bmo_check_server' );
		ServerRule::check();
		wp_safe_redirect( admin_url( 'options-general.php?page=beltoft-media-offload#bmo-server-rule' ) );
		exit;
	}

	public static function handle_test_connection() {
		if ( ! current_user_can( Options::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'beltoft-media-offload' ) );
		}
		check_admin_referer( 'bmo_test_connection' );

		$client = S3Client::from_options();
		$key    = 'bmo-test/' . uniqid( 'conn-', true ) . '.txt';
		$result = $client->put_object( $key, 'beltoft-media-offload connection test', 'text/plain' );

		$notice = 'success';
		if ( is_wp_error( $result ) ) {
			$notice = $result->get_error_message();
		} else {
			$client->delete_object( $key );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'beltoft-media-offload',
					'bmo_test_result' => rawurlencode( $notice ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
