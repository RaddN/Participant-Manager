<?php
/**
 * Plugin Name: Participant Manager
 * Description: Manage participants with registration details and user permissions.
 * Version: 1.5
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Raihan Hossain
 * Author URI: https://www.linkedin.com/in/raihan-hossain-/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: participant-manager
 * Domain Path: /languages
 *
 * @package ParticipantManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Participant_Manager_Plugin', false ) ) {
	/**
	 * Main plugin controller.
	 */
	final class Participant_Manager_Plugin {
		const VERSION           = '1.5';
		const TEXT_DOMAIN       = 'participant-manager';
		const PAGE_PARTICIPANTS = 'participant_manager';
		const PAGE_ADD          = 'add_participant';
		const PAGE_PERMISSIONS  = 'participant_permissions';
		const SHORTCODE         = 'participant_verification';
		const PERMISSION_ROLE   = 'participant_manager';
		const PER_PAGE_OPTION   = 'participant_manager_per_page';
		const PARTICIPANTS_SCREEN_ID = 'toplevel_page_participant_manager';

		/**
		 * Admin hook suffixes registered for this plugin.
		 *
		 * @var string[]
		 */
		private static $admin_hook_suffixes = array();

		/**
		 * Hook suffix for the participants list page.
		 *
		 * @var string
		 */
		private static $participants_hook_suffix = '';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
			add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
			add_shortcode( self::SHORTCODE, array( __CLASS__, 'verification_form' ) );
			add_filter( 'set-screen-option', array( __CLASS__, 'set_screen_option' ), 10, 3 );
			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_privacy_exporter' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_privacy_eraser' ) );
		}

		/**
		 * Create custom tables.
		 *
		 * @return void
		 */
		public static function activate() {
			global $wpdb;

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$charset_collate  = $wpdb->get_charset_collate();
			$participants     = self::participants_table();
			$permissions      = self::permissions_table();
			$participants_sql = "CREATE TABLE {$participants} (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				participant_name tinytext NOT NULL,
				registration_no tinytext NOT NULL,
				passport_no tinytext NOT NULL,
				passport_issuing_country tinytext NOT NULL,
				registration_status tinytext NOT NULL,
				cancellation_reason text DEFAULT NULL,
				PRIMARY KEY  (id)
			) {$charset_collate};";
			$permissions_sql  = "CREATE TABLE {$permissions} (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				role varchar(255) NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id)
			) {$charset_collate};";

			dbDelta( $participants_sql );
			dbDelta( $permissions_sql );
		}

		/**
		 * Add admin menu pages.
		 *
		 * @return void
		 */
		public static function add_admin_menu() {
			if ( ! self::user_can_access() ) {
				return;
			}

			self::$participants_hook_suffix = add_menu_page(
				__( 'Participant Manager', 'participant-manager' ),
				__( 'Participants', 'participant-manager' ),
				'read',
				self::PAGE_PARTICIPANTS,
				array( __CLASS__, 'participants_page' ),
				'dashicons-groups',
				56
			);
			self::$admin_hook_suffixes[] = self::$participants_hook_suffix;
			add_action( 'load-' . self::$participants_hook_suffix, array( __CLASS__, 'load_participants_screen' ) );

			self::$admin_hook_suffixes[] = add_submenu_page(
				self::PAGE_PARTICIPANTS,
				__( 'Add New Participant', 'participant-manager' ),
				__( 'Add New', 'participant-manager' ),
				'read',
				self::PAGE_ADD,
				array( __CLASS__, 'add_participant_page' )
			);

			if ( current_user_can( 'manage_options' ) ) {
				self::$admin_hook_suffixes[] = add_submenu_page(
					self::PAGE_PARTICIPANTS,
					__( 'User Permissions', 'participant-manager' ),
					__( 'User Permissions', 'participant-manager' ),
					'manage_options',
					self::PAGE_PERMISSIONS,
					array( __CLASS__, 'user_permissions_page' )
				);
			}
		}

		/**
		 * Configure Screen Options for the participant list.
		 *
		 * @return void
		 */
		public static function load_participants_screen() {
			$screen = get_current_screen();
			if ( ! $screen ) {
				return;
			}

			add_screen_option(
				'per_page',
				array(
					'default' => 10,
					'label'   => __( 'Participants per page', 'participant-manager' ),
					'option'  => self::PER_PAGE_OPTION,
				)
			);

			add_filter( 'manage_' . $screen->id . '_columns', array( __CLASS__, 'participant_columns' ) );
		}

		/**
		 * Save Screen Options values.
		 *
		 * @param mixed  $status Current status.
		 * @param string $option Option name.
		 * @param int    $value  Submitted value.
		 * @return mixed
		 */
		public static function set_screen_option( $status, $option, $value ) {
			if ( self::PER_PAGE_OPTION !== $option ) {
				return $status;
			}

			return max( 1, min( 100, absint( $value ) ) );
		}

		/**
		 * Enqueue admin assets only on plugin pages.
		 *
		 * @param string $hook_suffix Current admin screen hook suffix.
		 * @return void
		 */
		public static function enqueue_admin_assets( $hook_suffix ) {
			if ( ! in_array( $hook_suffix, self::$admin_hook_suffixes, true ) ) {
				return;
			}

			wp_enqueue_style(
				'participant-manager-styles',
				plugins_url( 'css/styles.css', __FILE__ ),
				array(),
				self::VERSION
			);
			wp_enqueue_script(
				'participant-manager-admin',
				plugins_url( 'js/admin.js', __FILE__ ),
				array(),
				self::VERSION,
				true
			);
		}

		/**
		 * Enqueue the shared stylesheet on the frontend for the verification shortcode.
		 *
		 * @return void
		 */
		public static function enqueue_frontend_assets() {
			if ( is_admin() ) {
				return;
			}

			wp_enqueue_style(
				'participant-manager-styles',
				plugins_url( 'css/styles.css', __FILE__ ),
				array(),
				self::VERSION
			);
		}

		/**
		 * Handle admin state-changing actions before admin output begins.
		 *
		 * @return void
		 */
		public static function handle_admin_actions() {
			if ( ! is_admin() ) {
				return;
			}

			$page = self::request_key( INPUT_GET, 'page' );
			if ( ! in_array( $page, array( self::PAGE_PARTICIPANTS, self::PAGE_ADD, self::PAGE_PERMISSIONS ), true ) ) {
				return;
			}

			if ( ! self::user_can_access() ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'participant-manager' ) );
			}

			if ( self::PAGE_PARTICIPANTS === $page ) {
				self::handle_participant_screen_options();
				self::handle_delete_participant();
				self::handle_update_participant();
			}

			if ( self::PAGE_ADD === $page ) {
				self::handle_add_participant();
			}

			if ( self::PAGE_PERMISSIONS === $page ) {
				self::handle_permissions_update();
			}
		}

		/**
		 * Render participant list.
		 *
		 * @return void
		 */
		public static function participants_page() {
			if ( ! self::user_can_access() ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'participant-manager' ) );
			}

			$edit_id = self::request_int( INPUT_GET, 'edit' );
			if ( $edit_id ) {
				self::edit_participant_page( $edit_id );
				return;
			}

			global $wpdb;

			$table_name   = self::participants_table();
			$per_page     = self::get_participants_per_page();
			$current_page = max( 1, self::request_int( INPUT_GET, 'paged', 1 ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table with sanitized table name.
			$total_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE 1 = %d", 1 ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$total_pages = max( 1, (int) ceil( $total_count / $per_page ) );

			if ( $current_page > $total_pages ) {
				$current_page = $total_pages;
			}

			$offset = ( $current_page - 1 ) * $per_page;

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table with sanitized table name.
			$participants = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, participant_name, registration_no, passport_no, passport_issuing_country, registration_status FROM {$table_name} ORDER BY id DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$columns              = self::visible_participant_columns();
			$visible_column_count = max( 1, count( $columns ) );

			self::render_admin_header(
				__( 'Participants', 'participant-manager' ),
				__( 'Review, search, edit, and remove participant registration records.', 'participant-manager' ),
				'dashicons-groups'
			);
			self::render_admin_notice();
			?>
			<div class="pm-toolbar">
				<form method="get" class="pm-search-form" data-pm-search-form>
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_PARTICIPANTS ); ?>">
					<label class="screen-reader-text" for="participant-manager-search"><?php esc_html_e( 'Search participants', 'participant-manager' ); ?></label>
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input type="search" id="participant-manager-search" class="pm-search-input" placeholder="<?php esc_attr_e( 'Search participants', 'participant-manager' ); ?>" data-pm-search-input>
				</form>
				<a class="button button-primary pm-button-with-icon" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_ADD ) ); ?>">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Add New', 'participant-manager' ); ?>
				</a>
			</div>

			<div class="pm-card">
				<div class="pm-table-wrap">
					<table class="pm-table wp-list-table widefat fixed striped" data-pm-participants-table>
						<thead>
							<tr>
								<?php foreach ( $columns as $column_key => $column_label ) : ?>
									<th id="<?php echo esc_attr( $column_key ); ?>" scope="col" class="<?php echo esc_attr( 'manage-column column-' . $column_key ); ?>"><?php echo esc_html( $column_label ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $participants ) ) : ?>
								<tr>
									<td colspan="<?php echo esc_attr( $visible_column_count ); ?>" class="pm-empty-state">
										<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
										<?php esc_html_e( 'No participants found.', 'participant-manager' ); ?>
									</td>
								</tr>
							<?php else : ?>
								<?php foreach ( $participants as $participant ) : ?>
									<?php
									$edit_url   = add_query_arg(
										array(
											'page' => self::PAGE_PARTICIPANTS,
											'edit' => absint( $participant->id ),
										),
										admin_url( 'admin.php' )
									);
									$delete_url = wp_nonce_url(
										add_query_arg(
											array(
												'page'   => self::PAGE_PARTICIPANTS,
												'delete' => absint( $participant->id ),
											),
											admin_url( 'admin.php' )
										),
										'participant_manager_delete_' . absint( $participant->id )
									);
									$status_class = 'Cancel' === $participant->registration_status ? 'pm-status-cancelled' : 'pm-status-confirmed';
									?>
									<tr>
										<?php foreach ( $columns as $column_key => $column_label ) : ?>
											<td class="<?php echo esc_attr( 'column-' . $column_key ); ?>">
												<?php self::render_participant_column( $column_key, $participant, $edit_url, $delete_url, $status_class ); ?>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<?php self::render_pagination( $current_page, $total_pages, $per_page ); ?>
			</div>

			<p class="pm-shortcode-note">
				<span class="dashicons dashicons-shortcode" aria-hidden="true"></span>
				<?php esc_html_e( 'Use the', 'participant-manager' ); ?>
				<code>[participant_verification]</code>
				<?php esc_html_e( 'shortcode anywhere on your website.', 'participant-manager' ); ?>
			</p>
			</div>
			<?php
		}

		/**
		 * Render add participant form.
		 *
		 * @return void
		 */
		public static function add_participant_page() {
			if ( ! self::user_can_access() ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'participant-manager' ) );
			}

			self::render_admin_header(
				__( 'Add New Participant', 'participant-manager' ),
				__( 'Create a participant record for verification.', 'participant-manager' ),
				'dashicons-plus-alt2'
			);
			self::render_admin_notice();
			self::render_participant_form(
				array(
					'action'      => admin_url( 'admin.php?page=' . self::PAGE_ADD ),
					'nonce'       => 'participant_manager_add',
					'submit_text' => __( 'Add Participant', 'participant-manager' ),
					'title'       => __( 'Participant Details', 'participant-manager' ),
				)
			);
			echo '</div>';
		}

		/**
		 * Render user permissions page.
		 *
		 * @return void
		 */
		public static function user_permissions_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'participant-manager' ) );
			}

			global $wpdb;

			$table_permissions = self::permissions_table();
			$users             = get_users(
				array(
					'fields' => array( 'ID', 'display_name', 'user_email' ),
					'orderby' => 'display_name',
					'order'  => 'ASC',
				)
			);

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table with sanitized table name.
			$permitted_users = array_map(
				'absint',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT user_id FROM {$table_permissions} WHERE role = %s",
						self::PERMISSION_ROLE
					)
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			self::render_admin_header(
				__( 'User Permissions', 'participant-manager' ),
				__( 'Choose which users can access participant records.', 'participant-manager' ),
				'dashicons-shield'
			);
			self::render_admin_notice();
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_PERMISSIONS ) ); ?>" class="pm-card pm-permissions-form">
				<?php wp_nonce_field( 'participant_manager_permissions', 'participant_manager_nonce' ); ?>
				<div class="pm-section-heading">
					<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
					<h2><?php esc_html_e( 'Allowed Users', 'participant-manager' ); ?></h2>
				</div>
				<div class="pm-permission-grid">
					<?php foreach ( $users as $user ) : ?>
						<label class="pm-permission-user">
							<input type="checkbox" name="user_ids[]" value="<?php echo esc_attr( absint( $user->ID ) ); ?>" <?php checked( in_array( absint( $user->ID ), $permitted_users, true ) ); ?>>
							<span class="pm-user-avatar" aria-hidden="true"><?php echo esc_html( self::get_initials( $user->display_name ) ); ?></span>
							<span class="pm-user-meta">
								<strong><?php echo esc_html( $user->display_name ); ?></strong>
								<small><?php echo esc_html( $user->user_email ); ?></small>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="submit">
					<button type="submit" class="button button-primary pm-button-with-icon">
						<span class="dashicons dashicons-saved" aria-hidden="true"></span>
						<?php esc_html_e( 'Update Permissions', 'participant-manager' ); ?>
					</button>
				</p>
			</form>
			</div>
			<?php
		}

		/**
		 * Render verification shortcode.
		 *
		 * @return string
		 */
		public static function verification_form() {
			$result = self::get_verification_result();

			ob_start();
			?>
			<div class="pm-frontend">
				<form method="post" class="pm-form pm-verification-form">
					<?php wp_nonce_field( 'participant_manager_verify', 'participant_manager_verify_nonce' ); ?>
					<div class="pm-row">
						<label for="participant-manager-registration-no"><?php esc_html_e( 'Registration No', 'participant-manager' ); ?></label>
						<input type="text" id="participant-manager-registration-no" name="registration_no" placeholder="<?php esc_attr_e( 'Registration No', 'participant-manager' ); ?>" required>
					</div>
					<div class="pm-row">
						<label for="participant-manager-passport-no"><?php esc_html_e( 'Passport No', 'participant-manager' ); ?></label>
						<input type="text" id="participant-manager-passport-no" name="passport_no" placeholder="<?php esc_attr_e( 'Passport No', 'participant-manager' ); ?>" required>
					</div>
					<div class="pm-row pm-actions-row">
						<button type="submit" name="participant_manager_verify" value="1" class="pm-primary-button">
							<?php esc_html_e( 'Verify', 'participant-manager' ); ?>
						</button>
					</div>
				</form>
				<?php self::render_verification_result( $result ); ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Register privacy exporter.
		 *
		 * @param array $exporters Registered exporters.
		 * @return array
		 */
		public static function register_privacy_exporter( $exporters ) {
			$exporters['participant-manager'] = array(
				'exporter_friendly_name' => __( 'Participant Manager', 'participant-manager' ),
				'callback'               => array( __CLASS__, 'privacy_exporter' ),
			);

			return $exporters;
		}

		/**
		 * Add plugin privacy policy guidance.
		 *
		 * @return void
		 */
		public static function add_privacy_policy_content() {
			if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
				return;
			}

			$content = '<p>' . esc_html__( 'Participant Manager stores participant names, registration numbers, passport numbers, passport issuing countries, registration status, and optional cancellation reasons in custom database tables. This data is used to manage and verify participant registrations and is not automatically linked to WordPress user email addresses.', 'participant-manager' ) . '</p>';

			wp_add_privacy_policy_content(
				__( 'Participant Manager', 'participant-manager' ),
				$content
			);
		}

		/**
		 * Exporter callback. Participant records are not linked to WordPress account email addresses.
		 *
		 * @param string $email_address User email address.
		 * @param int    $page          Export page.
		 * @return array
		 */
		public static function privacy_exporter( $email_address, $page = 1 ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		/**
		 * Register privacy eraser.
		 *
		 * @param array $erasers Registered erasers.
		 * @return array
		 */
		public static function register_privacy_eraser( $erasers ) {
			$erasers['participant-manager'] = array(
				'eraser_friendly_name' => __( 'Participant Manager', 'participant-manager' ),
				'callback'             => array( __CLASS__, 'privacy_eraser' ),
			);

			return $erasers;
		}

		/**
		 * Eraser callback. Participant records are not linked to WordPress account email addresses.
		 *
		 * @param string $email_address User email address.
		 * @param int    $page          Eraser page.
		 * @return array
		 */
		public static function privacy_eraser( $email_address, $page = 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		/**
		 * Check whether the current user can access participant management.
		 *
		 * @return bool
		 */
		private static function user_can_access() {
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			$user_id = get_current_user_id();
			if ( ! $user_id ) {
				return false;
			}

			global $wpdb;

			$table_permissions = self::permissions_table();

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table with sanitized table name.
			$has_permission = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_permissions} WHERE user_id = %d AND role = %s",
					$user_id,
					self::PERMISSION_ROLE
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			return $has_permission > 0;
		}

		/**
		 * Handle participant deletion.
		 *
		 * @return void
		 */
		private static function handle_delete_participant() {
			$delete_id = self::request_int( INPUT_GET, 'delete' );
			if ( ! $delete_id ) {
				return;
			}

			self::verify_nonce_from_request( 'participant_manager_delete_' . $delete_id, INPUT_GET, '_wpnonce' );

			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$wpdb->delete(
				self::participants_table(),
				array( 'id' => $delete_id ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			self::redirect_to_participants( 'deleted' );
		}

		/**
		 * Save hidden participant list columns from Screen Options.
		 *
		 * @return void
		 */
		private static function handle_participant_screen_options() {
			if ( ! self::is_post_request() || ! self::post_has_key( 'screen-options-apply' ) ) {
				return;
			}

			self::verify_nonce_from_request( 'screen-options-nonce', INPUT_POST, 'screenoptionnonce' );

			$hidden_columns = array();
			foreach ( array_keys( self::participant_columns() ) as $column_key ) {
				if ( ! self::post_has_key( $column_key . '-hide' ) ) {
					$hidden_columns[] = $column_key;
				}
			}

			$screen_id = self::$participants_hook_suffix ? self::$participants_hook_suffix : self::PARTICIPANTS_SCREEN_ID;

			update_user_option(
				get_current_user_id(),
				'manage' . $screen_id . 'columnshidden',
				$hidden_columns,
				true
			);
		}

		/**
		 * Handle participant creation.
		 *
		 * @return void
		 */
		private static function handle_add_participant() {
			if ( ! self::is_post_request() ) {
				return;
			}

			self::verify_nonce_from_request( 'participant_manager_add', INPUT_POST, 'participant_manager_nonce' );

			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
			$wpdb->insert(
				self::participants_table(),
				self::get_participant_post_data(),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

			self::redirect_to_page( self::PAGE_ADD, 'added' );
		}

		/**
		 * Handle participant update.
		 *
		 * @return void
		 */
		private static function handle_update_participant() {
			if ( ! self::is_post_request() ) {
				return;
			}

			$edit_id = self::request_int( INPUT_GET, 'edit' );
			if ( ! $edit_id ) {
				return;
			}

			self::verify_nonce_from_request( 'participant_manager_edit_' . $edit_id, INPUT_POST, 'participant_manager_nonce' );

			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$wpdb->update(
				self::participants_table(),
				self::get_participant_post_data(),
				array( 'id' => $edit_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			self::redirect_to_participants( 'updated' );
		}

		/**
		 * Handle user permission updates.
		 *
		 * @return void
		 */
		private static function handle_permissions_update() {
			if ( ! self::is_post_request() ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to update permissions.', 'participant-manager' ) );
			}

			self::verify_nonce_from_request( 'participant_manager_permissions', INPUT_POST, 'participant_manager_nonce' );

			global $wpdb;

			$user_ids = array_unique( self::post_int_array( 'user_ids' ) );
			$table    = self::permissions_table();

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$wpdb->delete(
				$table,
				array( 'role' => self::PERMISSION_ROLE ),
				array( '%s' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $user_ids as $user_id ) {
				if ( ! get_user_by( 'id', $user_id ) ) {
					continue;
				}

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
				$wpdb->insert(
					$table,
					array(
						'user_id' => $user_id,
						'role'    => self::PERMISSION_ROLE,
					),
					array( '%d', '%s' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
			}

			self::redirect_to_page( self::PAGE_PERMISSIONS, 'permissions-updated' );
		}

		/**
		 * Render edit participant form.
		 *
		 * @param int $id Participant ID.
		 * @return void
		 */
		private static function edit_participant_page( $id ) {
			global $wpdb;

			$table_name = self::participants_table();

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table with sanitized table name.
			$participant = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, participant_name, registration_no, passport_no, passport_issuing_country, registration_status, cancellation_reason FROM {$table_name} WHERE id = %d",
					$id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			self::render_admin_header(
				__( 'Edit Participant', 'participant-manager' ),
				__( 'Update the selected participant registration record.', 'participant-manager' ),
				'dashicons-edit'
			);
			self::render_admin_notice();

			if ( ! $participant ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Participant not found.', 'participant-manager' ) . '</p></div>';
				echo '</div>';
				return;
			}

			self::render_participant_form(
				array(
					'action'       => add_query_arg(
						array(
							'page' => self::PAGE_PARTICIPANTS,
							'edit' => absint( $participant->id ),
						),
						admin_url( 'admin.php' )
					),
					'nonce'       => 'participant_manager_edit_' . absint( $participant->id ),
					'submit_text' => __( 'Update Participant', 'participant-manager' ),
					'title'       => __( 'Participant Details', 'participant-manager' ),
					'participant' => $participant,
					'back_url'    => admin_url( 'admin.php?page=' . self::PAGE_PARTICIPANTS ),
					'back_label'  => __( 'Back to Participants', 'participant-manager' ),
				)
			);
			echo '</div>';
		}

		/**
		 * Render the add/edit participant form.
		 *
		 * @param array $args Form args.
		 * @return void
		 */
		private static function render_participant_form( $args ) {
			$participant = isset( $args['participant'] ) ? $args['participant'] : null;
			$status      = $participant ? $participant->registration_status : 'Confirm';
			?>
			<form method="post" action="<?php echo esc_url( $args['action'] ); ?>" class="pm-form pm-card pm-admin-form">
				<?php wp_nonce_field( $args['nonce'], 'participant_manager_nonce' ); ?>
				<div class="pm-section-heading">
					<span class="dashicons dashicons-id" aria-hidden="true"></span>
					<h2><?php echo esc_html( $args['title'] ); ?></h2>
				</div>
				<div class="pm-row">
					<label for="participant-name"><?php esc_html_e( 'Participant Name', 'participant-manager' ); ?></label>
					<input type="text" id="participant-name" name="participant_name" value="<?php echo esc_attr( $participant ? $participant->participant_name : '' ); ?>" placeholder="<?php esc_attr_e( 'Participant Name', 'participant-manager' ); ?>" required>
				</div>
				<div class="pm-row">
					<label for="registration-no"><?php esc_html_e( 'Registration No', 'participant-manager' ); ?></label>
					<input type="text" id="registration-no" name="registration_no" value="<?php echo esc_attr( $participant ? $participant->registration_no : '' ); ?>" placeholder="<?php esc_attr_e( 'Registration No', 'participant-manager' ); ?>" required>
				</div>
				<div class="pm-row">
					<label for="passport-no"><?php esc_html_e( 'Passport No', 'participant-manager' ); ?></label>
					<input type="text" id="passport-no" name="passport_no" value="<?php echo esc_attr( $participant ? $participant->passport_no : '' ); ?>" placeholder="<?php esc_attr_e( 'Passport No', 'participant-manager' ); ?>" required>
				</div>
				<div class="pm-row">
					<label for="passport-country"><?php esc_html_e( 'Passport Issuing Country', 'participant-manager' ); ?></label>
					<input type="text" id="passport-country" name="passport_issuing_country" value="<?php echo esc_attr( $participant ? $participant->passport_issuing_country : '' ); ?>" placeholder="<?php esc_attr_e( 'Passport Issuing Country', 'participant-manager' ); ?>" required>
				</div>
				<div class="pm-row">
					<label for="registration-status"><?php esc_html_e( 'Registration Status', 'participant-manager' ); ?></label>
					<select id="registration-status" name="registration_status" data-pm-status-control>
						<option value="Confirm" <?php selected( $status, 'Confirm' ); ?>><?php esc_html_e( 'Confirm', 'participant-manager' ); ?></option>
						<option value="Cancel" <?php selected( $status, 'Cancel' ); ?>><?php esc_html_e( 'Cancel', 'participant-manager' ); ?></option>
					</select>
				</div>
				<div class="pm-row <?php echo 'Cancel' === $status ? '' : 'pm-is-hidden'; ?>" data-pm-cancellation-row>
					<label for="cancellation-reason"><?php esc_html_e( 'Reason', 'participant-manager' ); ?></label>
					<textarea id="cancellation-reason" name="cancellation_reason" placeholder="<?php esc_attr_e( 'Reason for Cancellation', 'participant-manager' ); ?>"><?php echo esc_textarea( $participant ? $participant->cancellation_reason : '' ); ?></textarea>
				</div>
				<div class="pm-form-actions">
					<button type="submit" class="button button-primary pm-button-with-icon">
						<span class="dashicons dashicons-saved" aria-hidden="true"></span>
						<?php echo esc_html( $args['submit_text'] ); ?>
					</button>
					<?php if ( ! empty( $args['back_url'] ) ) : ?>
						<a class="button" href="<?php echo esc_url( $args['back_url'] ); ?>"><?php echo esc_html( $args['back_label'] ); ?></a>
					<?php endif; ?>
				</div>
			</form>
			<?php
		}

		/**
		 * Build participant data from POST.
		 *
		 * @return array
		 */
		private static function get_participant_post_data() {
			$status = self::post_text( 'registration_status' );
			if ( ! in_array( $status, array( 'Confirm', 'Cancel' ), true ) ) {
				$status = 'Confirm';
			}

			$cancellation_reason = 'Cancel' === $status ? self::post_textarea( 'cancellation_reason' ) : '';

			return array(
				'participant_name'          => self::post_text( 'participant_name' ),
				'registration_no'           => self::post_text( 'registration_no' ),
				'passport_no'               => self::post_text( 'passport_no' ),
				'passport_issuing_country'  => self::post_text( 'passport_issuing_country' ),
				'registration_status'       => $status,
				'cancellation_reason'       => $cancellation_reason,
			);
		}

		/**
		 * Get verification result for frontend submission.
		 *
		 * @return array|null
		 */
		private static function get_verification_result() {
			if ( ! self::is_post_request() || ! self::post_has_key( 'participant_manager_verify' ) ) {
				return null;
			}

			$nonce = self::post_text( 'participant_manager_verify_nonce' );
			if ( ! wp_verify_nonce( $nonce, 'participant_manager_verify' ) ) {
				return array(
					'type'    => 'error',
					'message' => __( 'Verification could not be completed. Please refresh the page and try again.', 'participant-manager' ),
				);
			}

			$registration_no = self::post_text( 'registration_no' );
			$passport_no     = self::post_text( 'passport_no' );

			if ( '' === $registration_no || '' === $passport_no ) {
				return array(
					'type'    => 'error',
					'message' => __( 'Please enter both registration and passport numbers.', 'participant-manager' ),
				);
			}

			global $wpdb;

			$table_name = self::participants_table();

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table with sanitized table name.
			$participant = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT participant_name, registration_no, passport_no, passport_issuing_country, registration_status, cancellation_reason FROM {$table_name} WHERE registration_no = %s AND passport_no = %s LIMIT 1",
					$registration_no,
					$passport_no
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! $participant ) {
				return array(
					'type'    => 'not-found',
					'message' => __( 'Participant not found.', 'participant-manager' ),
				);
			}

			return array(
				'type'        => 'participant',
				'participant' => $participant,
			);
		}

		/**
		 * Render frontend verification result.
		 *
		 * @param array|null $result Verification result.
		 * @return void
		 */
		private static function render_verification_result( $result ) {
			if ( empty( $result ) ) {
				return;
			}

			if ( 'participant' !== $result['type'] ) {
				$notice_class = 'not-found' === $result['type'] ? 'pm-verification-result--warning' : 'pm-verification-result--error';
				echo '<div class="pm-verification-result ' . esc_attr( $notice_class ) . '"><span class="pm-result-icon" aria-hidden="true"></span><p>' . esc_html( $result['message'] ) . '</p></div>';
				return;
			}

			$participant = $result['participant'];
			$is_confirmed = 'Confirm' === $participant->registration_status;
			$result_class = $is_confirmed ? 'pm-verification-result--success' : 'pm-verification-result--error';
			?>
			<div class="pm-verification-result <?php echo esc_attr( $result_class ); ?>">
				<span class="pm-result-icon" aria-hidden="true"></span>
				<p>
					<strong><?php echo esc_html( $participant->participant_name ); ?></strong>
					<?php if ( $is_confirmed ) : ?>
						<?php esc_html_e( 'registration has been successfully verified.', 'participant-manager' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'registration has been canceled.', 'participant-manager' ); ?>
						<?php if ( ! empty( $participant->cancellation_reason ) ) : ?>
							<?php esc_html_e( 'Reason:', 'participant-manager' ); ?>
							<strong><?php echo esc_html( $participant->cancellation_reason ); ?></strong>
						<?php endif; ?>
					<?php endif; ?>
				</p>
			</div>
			<div class="pm-table-wrap tableresult">
				<table class="pm-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Participant Full Name', 'participant-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Registration No', 'participant-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Passport No', 'participant-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Passport Issuing Country', 'participant-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><?php echo esc_html( $participant->participant_name ); ?></td>
							<td><?php echo esc_html( $participant->registration_no ); ?></td>
							<td><?php echo esc_html( $participant->passport_no ); ?></td>
							<td><?php echo esc_html( $participant->passport_issuing_country ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
			<?php
		}

		/**
		 * Render admin page header.
		 *
		 * @param string $title    Header title.
		 * @param string $subtitle Header subtitle.
		 * @param string $icon     Dashicon class.
		 * @return void
		 */
		private static function render_admin_header( $title, $subtitle, $icon ) {
			?>
			<div class="wrap pm-admin-wrap">
				<div class="pm-admin-header">
					<div class="pm-admin-icon">
						<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
					</div>
					<div>
						<h1><?php echo esc_html( $title ); ?></h1>
						<p><?php echo esc_html( $subtitle ); ?></p>
					</div>
				</div>
			<?php
		}

		/**
		 * Render an admin notice from a query flag.
		 *
		 * @return void
		 */
		private static function render_admin_notice() {
			$message_key = self::request_key( INPUT_GET, 'participant_manager_message' );
			$messages    = array(
				'added'               => __( 'Participant added successfully.', 'participant-manager' ),
				'updated'             => __( 'Participant updated successfully.', 'participant-manager' ),
				'deleted'             => __( 'Participant deleted successfully.', 'participant-manager' ),
				'permissions-updated' => __( 'Permissions updated successfully.', 'participant-manager' ),
			);

			if ( empty( $messages[ $message_key ] ) ) {
				return;
			}

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $message_key ] ) . '</p></div>';
		}

		/**
		 * Render pagination links.
		 *
		 * @param int $current_page Current page number.
		 * @param int $total_pages  Total pages.
		 * @param int $per_page     Rows per page.
		 * @return void
		 */
		private static function render_pagination( $current_page, $total_pages, $per_page ) {
			if ( $total_pages <= 1 ) {
				return;
			}

			echo '<nav class="pm-pagination" aria-label="' . esc_attr__( 'Participant pagination', 'participant-manager' ) . '">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				$url = add_query_arg(
					array(
						'page'     => self::PAGE_PARTICIPANTS,
						'paged'    => $i,
						'per_page' => $per_page,
					),
					admin_url( 'admin.php' )
				);
				printf(
					'<a href="%1$s" class="%2$s">%3$s</a>',
					esc_url( $url ),
					esc_attr( $i === $current_page ? 'active' : '' ),
					esc_html( (string) $i )
				);
			}
			echo '</nav>';
		}

		/**
		 * Get all participant list columns.
		 *
		 * @return array
		 */
		public static function participant_columns() {
			return array(
				'id'                       => __( 'SN', 'participant-manager' ),
				'participant_name'         => __( 'Participant Name', 'participant-manager' ),
				'registration_no'          => __( 'Registration No', 'participant-manager' ),
				'passport_no'              => __( 'Passport No', 'participant-manager' ),
				'passport_issuing_country' => __( 'Passport Issuing Country', 'participant-manager' ),
				'registration_status'      => __( 'Registration Status', 'participant-manager' ),
				'actions'                  => __( 'Actions', 'participant-manager' ),
			);
		}

		/**
		 * Get visible participant columns for the current screen.
		 *
		 * @return array
		 */
		private static function visible_participant_columns() {
			$columns = self::participant_columns();
			$screen  = get_current_screen();

			if ( ! $screen ) {
				return $columns;
			}

			$hidden = get_hidden_columns( $screen );
			foreach ( $hidden as $column_key ) {
				unset( $columns[ $column_key ] );
			}

			if ( empty( $columns ) ) {
				return array( 'participant_name' => __( 'Participant Name', 'participant-manager' ) );
			}

			return $columns;
		}

		/**
		 * Render one participant table column.
		 *
		 * @param string $column_key   Column key.
		 * @param object $participant  Participant row.
		 * @param string $edit_url     Edit URL.
		 * @param string $delete_url   Delete URL.
		 * @param string $status_class Status class.
		 * @return void
		 */
		private static function render_participant_column( $column_key, $participant, $edit_url, $delete_url, $status_class ) {
			switch ( $column_key ) {
				case 'id':
					echo esc_html( absint( $participant->id ) );
					break;
				case 'participant_name':
					echo esc_html( $participant->participant_name );
					break;
				case 'registration_no':
					echo esc_html( $participant->registration_no );
					break;
				case 'passport_no':
					echo esc_html( $participant->passport_no );
					break;
				case 'passport_issuing_country':
					echo esc_html( $participant->passport_issuing_country );
					break;
				case 'registration_status':
					echo '<span class="pm-status ' . esc_attr( $status_class ) . '">' . esc_html( $participant->registration_status ) . '</span>';
					break;
				case 'actions':
					?>
					<div class="pm-row-actions">
						<a class="button button-small pm-button-with-icon" href="<?php echo esc_url( $edit_url ); ?>">
							<span class="dashicons dashicons-edit" aria-hidden="true"></span>
							<?php esc_html_e( 'Edit', 'participant-manager' ); ?>
						</a>
						<a class="button button-small button-link-delete pm-button-with-icon" href="<?php echo esc_url( $delete_url ); ?>" data-pm-confirm="<?php esc_attr_e( 'Are you sure you want to delete this participant?', 'participant-manager' ); ?>">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							<?php esc_html_e( 'Delete', 'participant-manager' ); ?>
						</a>
					</div>
					<?php
					break;
			}
		}

		/**
		 * Get user-selected participants per page.
		 *
		 * @return int
		 */
		private static function get_participants_per_page() {
			$per_page = (int) get_user_option( self::PER_PAGE_OPTION );

			if ( $per_page < 1 ) {
				$per_page = 10;
			}

			return max( 1, min( 100, $per_page ) );
		}

		/**
		 * Verify a nonce from request data.
		 *
		 * @param string $action Nonce action.
		 * @param int    $type   INPUT_GET or INPUT_POST.
		 * @param string $key    Request key.
		 * @return void
		 */
		private static function verify_nonce_from_request( $action, $type, $key ) {
			$nonce = self::request_text( $type, $key );
			if ( ! wp_verify_nonce( $nonce, $action ) ) {
				wp_die( esc_html__( 'Security check failed. Please refresh the page and try again.', 'participant-manager' ) );
			}
		}

		/**
		 * Redirect to participant list.
		 *
		 * @param string $message Message key.
		 * @return void
		 */
		private static function redirect_to_participants( $message ) {
			self::redirect_to_page( self::PAGE_PARTICIPANTS, $message );
		}

		/**
		 * Redirect to an admin page with a message.
		 *
		 * @param string $page    Page slug.
		 * @param string $message Message key.
		 * @return void
		 */
		private static function redirect_to_page( $page, $message ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                        => $page,
						'participant_manager_message' => $message,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Get participants table name.
		 *
		 * @return string
		 */
		private static function participants_table() {
			global $wpdb;

			return esc_sql( $wpdb->prefix . 'participants' );
		}

		/**
		 * Get permissions table name.
		 *
		 * @return string
		 */
		private static function permissions_table() {
			global $wpdb;

			return esc_sql( $wpdb->prefix . 'participant_permissions' );
		}

		/**
		 * Determine whether the current request is POST.
		 *
		 * @return bool
		 */
		private static function is_post_request() {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

			return 'POST' === strtoupper( $method );
		}

		/**
		 * Get an integer from GET or POST.
		 *
		 * @param int    $type    Input type.
		 * @param string $key     Request key.
		 * @param int    $default Default value.
		 * @return int
		 */
		private static function request_int( $type, $key, $default = 0 ) {
			$value = filter_input( $type, $key, FILTER_VALIDATE_INT );

			if ( false === $value || null === $value ) {
				return absint( $default );
			}

			return absint( $value );
		}

		/**
		 * Get a sanitized key from GET or POST.
		 *
		 * @param int    $type Input type.
		 * @param string $key  Request key.
		 * @return string
		 */
		private static function request_key( $type, $key ) {
			return sanitize_key( (string) filter_input( $type, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		}

		/**
		 * Get sanitized text from GET or POST.
		 *
		 * @param int    $type Input type.
		 * @param string $key  Request key.
		 * @return string
		 */
		private static function request_text( $type, $key ) {
			$value = filter_input( $type, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );

			if ( null === $value || false === $value ) {
				return '';
			}

			return sanitize_text_field( wp_unslash( $value ) );
		}

		/**
		 * Determine whether a POST key exists.
		 *
		 * @param string $key POST key.
		 * @return bool
		 */
		private static function post_has_key( $key ) {
			return null !== filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		}

		/**
		 * Get sanitized POST text.
		 *
		 * @param string $key POST key.
		 * @return string
		 */
		private static function post_text( $key ) {
			$value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );

			if ( null === $value || false === $value ) {
				return '';
			}

			return sanitize_text_field( wp_unslash( $value ) );
		}

		/**
		 * Get sanitized POST textarea text.
		 *
		 * @param string $key POST key.
		 * @return string
		 */
		private static function post_textarea( $key ) {
			$value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );

			if ( null === $value || false === $value ) {
				return '';
			}

			return sanitize_textarea_field( wp_unslash( $value ) );
		}

		/**
		 * Get integer array from POST.
		 *
		 * @param string $key POST key.
		 * @return int[]
		 */
		private static function post_int_array( $key ) {
			$values = filter_input( INPUT_POST, $key, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );

			if ( ! is_array( $values ) ) {
				return array();
			}

			return array_values( array_filter( array_map( 'absint', wp_unslash( $values ) ) ) );
		}

		/**
		 * Build display initials.
		 *
		 * @param string $name Display name.
		 * @return string
		 */
		private static function get_initials( $name ) {
			$parts    = preg_split( '/\s+/', trim( $name ) );
			$initials = '';

			foreach ( $parts as $part ) {
				if ( '' === $part ) {
					continue;
				}

				$initials .= strtoupper( substr( $part, 0, 1 ) );

				if ( strlen( $initials ) >= 2 ) {
					break;
				}
			}

			return '' !== $initials ? $initials : 'U';
		}
	}
}

Participant_Manager_Plugin::init();
register_activation_hook( __FILE__, array( 'Participant_Manager_Plugin', 'activate' ) );
