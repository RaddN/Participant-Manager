<?php
/**
 * Uninstall script for Participant Manager
 *
 * This file is executed when the plugin is deleted from the WordPress plugins list.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if this is a plugin uninstall request
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Get global database object
global $wpdb;

// Define the tables to be deleted
$table_participants = $wpdb->prefix . 'participants';
$table_permissions = $wpdb->prefix . 'participant_permissions';

// Delete the participants table
if ($wpdb->get_var("SHOW TABLES LIKE '$table_participants'") === $table_participants) {
    $wpdb->query("DROP TABLE IF EXISTS $table_participants;");
}

// Delete the participant_permissions table
if ($wpdb->get_var("SHOW TABLES LIKE '$table_permissions'") === $table_permissions) {
    $wpdb->query("DROP TABLE IF EXISTS $table_permissions;");
}