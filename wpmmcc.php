<?php
/**
 * Plugin Name:       WPMMCC
 * Plugin URI:        https://www.wpmm.cc
 * Description:       Cross-site bidirectional content synchronization engine for WordPress.
 * Version:           1.0.0
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
define( 'WPMMCC_VERSION', '1.0.0' );
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
