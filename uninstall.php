<?php
/**
 * Uninstall script for Participant Manager.
 *
 * @package ParticipantManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$participant_manager_participants_table = esc_sql( $wpdb->prefix . 'participants' );
$participant_manager_permissions_table  = esc_sql( $wpdb->prefix . 'participant_permissions' );

maybe_drop_table( $participant_manager_participants_table );
maybe_drop_table( $participant_manager_permissions_table );
