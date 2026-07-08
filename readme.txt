=== Participant Manager ===
Contributors: Raihan Hossain
Tags: participant, management, permissions
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage participant registrations, permissions, and verification from WordPress.

== Description ==

Participant Manager is a WordPress plugin that allows you to manage participants with registration details and user permissions. This plugin provides an interface to add, edit, and delete participant data, as well as manage user permissions for accessing participant information.

Use the `[participant_verification]` shortcode on a public page to let visitors verify a registration number and passport number.

== Installation ==

1. Upload the `participant-manager` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to the Participant Manager menu in the admin panel to start managing participants.

== Frequently Asked Questions ==

= How do I add a new participant? =

1. Navigate to the Participant Manager menu.
2. Click on "Add New Participant."
3. Fill out the required fields and click "Add Participant."

= How do I manage user permissions? =

1. Go to the User Permissions section under the Participant Manager menu.
2. Select the users you want to grant or revoke permissions for.
3. Click "Update Permissions."

== Privacy ==

Participant Manager stores participant names, registration numbers, passport numbers, passport issuing countries, registration status, and optional cancellation reasons in custom database tables. This data is not automatically linked to WordPress user email addresses.

== Changelog ==

= 1.5.5 =
* Hardened request handling, database operations, nonces, escaping, and admin/frontend presentation.

= 1.1 =
* Initial release of the Participant Manager plugin.

== Upgrade Notice ==

= 1.5.5 =
Security and interface hardening update.

== Screenshots ==

1. Participant management interface.
2. Add participant form.
3. User permissions interface.

== Author Information ==

This plugin is developed by Raihan Hossain. For support or inquiries, please visit [https://www.linkedin.com/in/raihan-hossain-/](https://www.linkedin.com/in/raihan-hossain-/).

== License ==

This plugin is licensed under the GNU General Public License v2 or later. See the [GNU General Public License](https://www.gnu.org/licenses/gpl-2.0.html) for more details.
