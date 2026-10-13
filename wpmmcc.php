<?php
/**
 * Plugin Name:       WPMMCC
 * Plugin URI:        https://www.wpmm.cc
 * Description:       Copy posts, terms, and media between paired WordPress sites. Direct pairing only syncs. The WPTSALL Client can translate the title, content, and excerpt while syncing.
 * Version:           2.1.10
 * Author:            WPMMCC Team
 * Author URI:        https://www.wpmm.cc
 * Text Domain:       wpmmcc
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WPMMCC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 1. Identity and Version Constants (Identity Contract v1.0 single truth)
define( 'WPMMCC_VERSION', '2.1.10' );
define( 'WPMMCC_IDENTITY', 'wpmmcc' );
define( 'WPMMCC_PROTOCOL_MIN', 1 );
define( 'WPMMCC_PROTOCOL_CURRENT', 1 );

// 2. Directory & Path Constants
define( 'WPMMCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMMCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPMMCC_PLUGIN_FILE', __FILE__ );

// 3. Load Bootstrap and Dependency Files
require_once WPMMCC_PLUGIN_DIR . 'includes/class-wpmmcc.php';
require_once WPMMCC_PLUGIN_DIR . 'includes/class-wpmmcc-activator.php';
require_once WPMMCC_PLUGIN_DIR . 'includes/class-wpmmcc-deactivator.php';

// 4. Initialize Plugin Instance (Registers PSR-4 Autoloader)
WPMMCC\Plugin::instance();

// 5. Register Activation and Deactivation Hooks
register_activation_hook( __FILE__, array( 'WPMMCC\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPMMCC\Deactivator', 'deactivate' ) );

// WordPress replaces plugin files without firing the activation hook.
// 2.0.1 stored wpmmcc_db_version as 2.0.0, which is numerically newer than
// this plugin's schema stamp 1.3.0, so the check is an exact mismatch.
add_action( 'plugins_loaded', array( 'WPMMCC\Activator', 'maybe_upgrade' ), 5 );
