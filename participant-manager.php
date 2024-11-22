<?php

/**
 * Plugin Name: Participant Manager
 * Description: Manage participants with registration details and user permissions.
 * Version: 1.5
 * Author: Raihan Hossain
 * Author URI: https://www.linkedin.com/in/raihan-hossain-/
 * License: GPLv2 or later
 * Text Domain: participant-manager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Enqueue CSS
add_action('admin_enqueue_scripts', 'pm_enqueue_styles');

function pm_enqueue_styles()
{
    wp_enqueue_style('pm-styles', plugin_dir_url(__FILE__) . 'css/styles.css');
}


register_activation_hook(__FILE__, 'pm_create_database');

function pm_create_database() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'participants';
    $table_permissions = $wpdb->prefix . 'participant_permissions';
    $charset_collate = $wpdb->get_charset_collate();

    // Check and create participants table
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            participant_name tinytext NOT NULL,
            registration_no tinytext NOT NULL,
            passport_no tinytext NOT NULL,
            passport_issuing_country tinytext NOT NULL,
            registration_status tinytext NOT NULL,
            cancellation_reason text DEFAULT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Check and create permissions table
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_permissions'") != $table_permissions) {
        $sql_permissions = "CREATE TABLE $table_permissions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            role varchar(255) NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_permissions);
    }
}

// Admin menu
add_action('admin_menu', 'pm_add_admin_menu');

function pm_add_admin_menu() {
    global $current_user;
    add_menu_page('Participant Manager', 'Participants', 'manage_options', 'participant_manager', 'pm_participants_page');
    add_submenu_page('participant_manager', 'Add New Participant', 'Add New', 'manage_options', 'add_participant', 'pm_add_participant_page');
    if (in_array('administrator', $current_user->roles)) {
        add_submenu_page('participant_manager', 'User Permissions', 'User Permissions', 'manage_options', 'participant_permissions', 'pm_user_permissions_page');
    }
    
}


// Permissions page
function pm_user_permissions_page() {
    global $wpdb;
    $table_permissions = $wpdb->prefix . 'participant_permissions';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Handle form submission for permissions
        $user_ids = $_POST['user_ids'] ?? [];

        // Clear existing permissions
        $wpdb->query("DELETE FROM $table_permissions");

        // Insert new permissions
        foreach ($user_ids as $user_id) {
            $wpdb->insert($table_permissions, [
                'user_id' => intval($user_id),
                'role' => 'participant_manager', // Assign a generic role name
            ]);
        }
        echo '<div class="updated"><p>Permissions updated successfully!</p></div>';
    }

    // Get users and their permissions
    $users = get_users();
    $permitted_users = $wpdb->get_col("SELECT user_id FROM $table_permissions");

    echo '<h1>User Permissions</h1>';
    echo '<form method="post">';
    echo '<h2>Select Users for Permissions</h2>';
    
    foreach ($users as $user) {
        $checked = in_array($user->ID, $permitted_users) ? 'checked' : '';
        echo '<input type="checkbox" name="user_ids[]" value="' . esc_attr($user->ID) . '" ' . $checked . '>' . esc_html($user->display_name) . '<br>';
    }

    echo '<input type="submit" value="Update Permissions">';
    echo '</form>';
}



// Check if user has access to the plugin functionality
function pm_user_can_access() {
    global $wpdb, $current_user;
    $table_permissions = $wpdb->prefix . 'participant_permissions';

    // Check if user has permission
    $permissions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_permissions WHERE user_id = %d", $current_user->ID));

    // Check if the user has any permissions
    if (!empty($permissions)) {
        return true;
    }

    // Check if the user is a WordPress administrator
    if (in_array('administrator', $current_user->roles)) {
        return true;
    }

    return false;
}
function allow_editor_access_participant_manager() {
    if (current_user_can('editor')) {
        // Check if the editor has access to the Participant Manager
        if (!pm_user_can_access()) {
            $role = get_role('editor');
            // Only remove the capability if it exists
            if ($role && $role->has_cap('manage_options')) {
                $role->remove_cap('manage_options');
            }
        } else {
            $role = get_role('editor');
            // Only add the capability if it doesn't already exist
            if ($role && !$role->has_cap('manage_options')) {
                $role->add_cap('manage_options');
            }
        }
    }
}

add_action('admin_init', 'allow_editor_access_participant_manager');
function custom_editor_menu() {
    if (current_user_can('editor')) {
 
        remove_menu_page('options-general.php');
		remove_menu_page('tools.php');
		remove_menu_page('plugins.php');
		remove_menu_page('plugins.php');
		remove_menu_page('edit.php?post_type=page');
    
    }
}

add_action('admin_menu', 'custom_editor_menu');
function hide_wpforms_menu() {
    // Only apply this to the admin area
    if (is_admin() && current_user_can('editor')) {
        echo '<style>
            #toplevel_page_wpforms-overview,#toplevel_page_astra,#toplevel_page_wpseo_workouts,#toplevel_page_spectra,#toplevel_page_pisol-cefw,#menu-posts-product .wp-first-item {
                display: none !important;
            }
        </style>';
    }
}

add_action('admin_head', 'hide_wpforms_menu');

// Display participant list
function pm_participants_page() {
    if (!pm_user_can_access()) {
        echo '<h1>You do not have permission to access this page.</h1>';
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'participants';

    // Handle deletion
    if (isset($_GET['delete'])) {
        $wpdb->delete($table_name, array('id' => intval($_GET['delete'])));
    }

    // Pagination settings
    $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10; // Default rows per page
    $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1; // Current page
    $offset = ($current_page - 1) * $per_page; // Offset for SQL query

    // Get total participants count
    $total_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_pages = ceil($total_count / $per_page);

    // Fetch participants
    $participants = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name LIMIT %d OFFSET %d", $per_page, $offset));

    echo '<h1>Participants</h1>';
    echo '<form method="post" id="participant-search-form" onsubmit="return searchParticipants();">
    <input type="text" id="search-input" placeholder="Search for participants">
    <input type="submit" value="Search">
    </form>';

    echo '<table class="pm-table">';
    echo '<tr class="rPartihead"><th>SN</th><th>Participant Name</th><th>Registration No</th><th>Passport No</th><th>Passport Issuing Country</th><th>Registration Status</th><th>Actions</th></tr>';

    foreach ($participants as $participant) {
        echo '<tr>
        <td>' . esc_html($participant->id) . '</td>
        <td>' . esc_html($participant->participant_name) . '</td>
        <td>' . esc_html($participant->registration_no) . '</td>
        <td>' . esc_html($participant->passport_no) . '</td>
        <td>' . esc_html($participant->passport_issuing_country) . '</td>
        <td>' . esc_html($participant->registration_status) . '</td>
        <td class="rpartiaction">
        <a href="?page=participant_manager&edit=' . esc_attr($participant->id) . '">Edit</a>
        <a style="background:red;" href="?page=participant_manager&delete=' . esc_attr($participant->id) . '" onclick="return confirm(\'Are you sure?\');">Delete</a>
        </td>
        </tr>';
    }

    echo '</table>';

    // Pagination links
    echo '<div class="rpmpagination">';
    for ($i = 1; $i <= $total_pages; $i++) {
        echo '<a href="?page=participant_manager&paged=' . $i . '&per_page=' . $per_page . '"' . (($i === $current_page) ? ' class="active"' : '') . '>' . $i . '</a> ';
    }
    echo '</div>';

    echo 'Use the <b>[participant_verification]</b> shortcode anywhere on your website.';

    // Check if edit parameter is set
    if (isset($_GET['edit'])) {
        pm_edit_participant_page(intval($_GET['edit']));
    }

    // Add the search script
    echo '<style>.rpmpagination {
        display: flex !important;
        flex-direction: row;
        justify-content: center;
        gap: 20px;
        padding-top: 20px !important;
    }
    .rpmpagination a {
        padding: 10px 15px;
        background: #ffa7a7;
        color: white;
        border-radius: 8px;
    }
    .rpmpagination a.active {
        background: red !important;
    }</style>
    <script>
    function searchParticipants() {
        const input = document.getElementById("search-input").value.toLowerCase();
        const rows = document.querySelectorAll(".pm-table tr:not(.rPartihead)");

        rows.forEach(row => {
            const cells = row.getElementsByTagName("td");
            let found = false;

            for (let i = 0; i < cells.length; i++) {
                if (cells[i].innerText.toLowerCase().includes(input)) {
                    found = true;
                    break;
                }
            }

            row.style.display = found ? "" : "none";
        });

        // Prevent form submission to allow filtering
        return false;
    }

    // Handle keydown for real-time search
    document.getElementById("search-input").addEventListener("keydown", function(event) {
        if (event.key === "Enter") {
            event.preventDefault(); // Prevent the form from submitting on Enter
            searchParticipants(); // Call search function on Enter
        }
    });
    </script>';
}

// Add new participant
function pm_add_participant_page() {
    if (!pm_user_can_access()) {
        echo '<h1>You do not have permission to access this page.</h1>';
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'participants';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $cancellation_reason = ($_POST['registration_status'] === 'Cancel') ? sanitize_textarea_field($_POST['cancellation_reason']) : '';

        $wpdb->insert($table_name, array(
            'participant_name' => sanitize_text_field($_POST['participant_name']),
            'registration_no' => sanitize_text_field($_POST['registration_no']),
            'passport_no' => sanitize_text_field($_POST['passport_no']),
            'passport_issuing_country' => sanitize_text_field($_POST['passport_issuing_country']),
            'registration_status' => sanitize_text_field($_POST['registration_status']),
            'cancellation_reason' => $cancellation_reason,
        ));
        echo '<div class="updated"><p>Participant added successfully!</p></div>';
    }

    echo '<form method="post" class="pm-form">
    <h1 class="formtitle">Add New Participant</h1>
    <div class="pm-row">
        <label for="participant_name">Participant Name:</label>
        <input type="text" name="participant_name" placeholder="Participant Name" required>
    </div>
    <div class="pm-row">
        <label for="registration_no">Registration No:</label>
        <input type="text" name="registration_no" placeholder="Registration No" required>
    </div>
    <div class="pm-row">
        <label for="passport_no">Passport No:</label>
        <input type="text" name="passport_no" placeholder="Passport No" required>
    </div>
    <div class="pm-row">
        <label for="passport_issuing_country">Passport Issuing Country:</label>
        <input type="text" name="passport_issuing_country" placeholder="Passport Issuing Country" required>
    </div>
    <div class="pm-row">
        <label for="registration_status">Registration Status:</label>
        <select name="registration_status" id="registration_status" onchange="toggleCancellationReason(this.value)">
            <option value="Confirm">Confirm</option>
            <option value="Cancel">Cancel</option>
        </select>
    </div>
    <div class="pm-row" id="cancellation_reason_row" style="display:none!important;">
        <label for="cancellation_reason">Reason:</label>
        <textarea name="cancellation_reason" placeholder="Reason for Cancellation"></textarea>
    </div>
    <div class="pm-row">
        <input type="submit" value="Add Participant">
    </div>
    </form>
    <script>
    function toggleCancellationReason(status) {
        document.getElementById("cancellation_reason_row").style.display = (status === "Cancel") ? "block" : "none";
    }
    </script>';
}

// Edit participant
function pm_edit_participant_page($id) {
    if (!pm_user_can_access()) {
        echo '<h1>You do not have permission to access this page.</h1>';
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'participants';

    // Fetch participant data
    $participant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $cancellation_reason = ($_POST['registration_status'] === 'Cancel') ? sanitize_textarea_field($_POST['cancellation_reason']) : '';

        $wpdb->update($table_name, array(
            'participant_name' => sanitize_text_field($_POST['participant_name']),
            'registration_no' => sanitize_text_field($_POST['registration_no']),
            'passport_no' => sanitize_text_field($_POST['passport_no']),
            'passport_issuing_country' => sanitize_text_field($_POST['passport_issuing_country']),
            'registration_status' => sanitize_text_field($_POST['registration_status']),
            'cancellation_reason' => $cancellation_reason,
        ), array('id' => $id));
        echo '<div class="updated"><p>Participant updated successfully!</p></div>';
        return; // Prevent form from displaying after update
    }

    // Use isset to avoid undefined property warning
    $cancellation_reason_value = isset($participant->cancellation_reason) ? esc_textarea($participant->cancellation_reason) : '';

    echo '<form method="post" class="pm-form">
    <h1 class="formtitle">Edit Participant</h1>
    <div class="pm-row">
        <label for="participant_name">Participant Name:</label>
        <input type="text" name="participant_name" value="' . esc_attr($participant->participant_name) . '" required>
    </div>
    <div class="pm-row">
        <label for="registration_no">Registration No:</label>
        <input type="text" name="registration_no" value="' . esc_attr($participant->registration_no) . '" required>
    </div>
    <div class="pm-row">
        <label for="passport_no">Passport No:</label>
        <input type="text" name="passport_no" value="' . esc_attr($participant->passport_no) . '" required>
    </div>
    <div class="pm-row">
        <label for="passport_issuing_country">Passport Issuing Country:</label>
        <input type="text" name="passport_issuing_country" value="' . esc_attr($participant->passport_issuing_country) . '" required>
    </div>
    <div class="pm-row">
        <label for="registration_status">Registration Status:</label>
        <select name="registration_status" id="registration_status" onchange="toggleCancellationReason(this.value)">
            <option value="Confirm"' . selected($participant->registration_status, 'Confirm', false) . '>Confirm</option>
            <option value="Cancel"' . selected($participant->registration_status, 'Cancel', false) . '>Cancel</option>
        </select>
    </div>
    <div class="pm-row" id="cancellation_reason_row" style="display:' . ($participant->registration_status === 'Cancel' ? 'flex !important' : 'none!important') . ';">
        <label for="cancellation_reason">Reason:</label>
        <textarea name="cancellation_reason" placeholder="Reason for Cancellation">' . $cancellation_reason_value . '</textarea>
    </div>
    <div class="pm-row">
        <input type="submit" value="Update Participant">
    </div>
    </form>
    <script>
    function toggleCancellationReason(status) {
        document.getElementById("cancellation_reason_row").style.display = (status === "Cancel") ? "block" : "none";
    }
    </script>';
}

// Shortcode for verification form
add_shortcode('participant_verification', 'pm_verification_form');

function pm_verification_form()
{
    ob_start();
?>
    <form method="post" class="pm-form">
        <div class="pm-row">
            <label for="registration_no">Registration No:</label>
            <input type="text" name="registration_no" placeholder="Registration No" required>
        </div>
        <div class="pm-row">
            <label for="passport_no">Passport No:</label>
            <input type="text" name="passport_no" placeholder="Passport No" required>
        </div>
        <div class="pm-row">
            <input type="submit" name="verify_participant" value="Verify">
        </div>
    </form>
<?php
    if (isset($_POST['verify_participant'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'participants';
        $registration_no = sanitize_text_field($_POST['registration_no']);
        $passport_no = sanitize_text_field($_POST['passport_no']);

        $participant = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE registration_no = %s AND passport_no = %s", $registration_no, $passport_no));

        if ($participant) {
            if (esc_html($participant->registration_status) === "Confirm") {
                echo '<style>p.rmessage {font-weight: normal;text-transform: uppercase;}.rmessage svg{fill:#4caf50;width:16px;height:16px; margin-bottom: -2px;}</style><p class="rmessage"><span style="color:green; font-weight:bold;">' . esc_html($participant->participant_name) . '</span> Registration has been: <span style="font-weight:bold;">Successfully verified <svg xmlns="https://www.w3.org/2000/svg" viewBox="0 0 512 512" role="graphics-symbol" aria-hidden="false" aria-label=""><path d="M0 256C0 114.6 114.6 0 256 0C397.4 0 512 114.6 512 256C512 397.4 397.4 512 256 512C114.6 512 0 397.4 0 256zM371.8 211.8C382.7 200.9 382.7 183.1 371.8 172.2C360.9 161.3 343.1 161.3 332.2 172.2L224 280.4L179.8 236.2C168.9 225.3 151.1 225.3 140.2 236.2C129.3 247.1 129.3 264.9 140.2 275.8L204.2 339.8C215.1 350.7 232.9 350.7 243.8 339.8L371.8 211.8z"></path></svg></span></p>';
            }elseif(esc_html($participant->registration_status) === "Cancel"){
                echo '<style>p.rmessage {font-weight: normal; text-transform: uppercase;}.rmessage svg{fill:red;width:16px;height:16px;margin-bottom: -2px;}</style><p class="rmessage"><span style="color:red; font-weight:bold;">' . esc_html($participant->participant_name) . '</span> Registration has been: <span style="color:red; font-weight:bold;">Canceled</span> due to <span style="font-weight:bold;color:red;"> '.esc_html($participant->cancellation_reason) .' <svg xmlns="https://www.w3.org/2000/svg" viewBox="0 0 448 512" role="graphics-symbol" aria-hidden="false" aria-label=""><path d="M384 32C419.3 32 448 60.65 448 96V416C448 451.3 419.3 480 384 480H64C28.65 480 0 451.3 0 416V96C0 60.65 28.65 32 64 32H384zM143 208.1L190.1 255.1L143 303C133.7 312.4 133.7 327.6 143 336.1C152.4 346.3 167.6 346.3 176.1 336.1L223.1 289.9L271 336.1C280.4 346.3 295.6 346.3 304.1 336.1C314.3 327.6 314.3 312.4 304.1 303L257.9 255.1L304.1 208.1C314.3 199.6 314.3 184.4 304.1 175C295.6 165.7 280.4 165.7 271 175L223.1 222.1L176.1 175C167.6 165.7 152.4 165.7 143 175C133.7 184.4 133.7 199.6 143 208.1V208.1z"></path></svg></span></p>';
            }
            echo '<div class="tableresult"><table class="pm-table">';
            echo '<tr class="rPartihead"><th>Participant Full Name</th><th>Registration No</th><th>Passport No</th><th>Passport Issuing Country</th></tr>';
            echo '<tr>
                <td>' . esc_html($participant->participant_name) . '</td>
                <td>' . esc_html($participant->registration_no) . '</td>
                <td>' . esc_html($participant->passport_no) . '</td>
                <td>' . esc_html($participant->passport_issuing_country) . '</td>
              </tr>';
            echo '</table></div>';
        } else {
            echo '<p>Participant not found.</p>';
        }
    }
    return ob_get_clean();
}
