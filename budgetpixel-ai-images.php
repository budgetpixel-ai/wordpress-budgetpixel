<?php
/**
 * Plugin Name:       BudgetPixel AI Images
 * Plugin URI:        https://budgetpixel.com/plugins/wordpress
 * Description:       Generate a featured image or inline image for any post from its title and excerpt, with 25+ AI image models and a credit cost preview before every run. Bulk-fill missing featured images from WP-CLI.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            BudgetPixel
 * Author URI:        https://budgetpixel.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       budgetpixel-ai-images
 *
 * @package BudgetPixel
 */

defined( 'ABSPATH' ) || exit;

define( 'BUDGETPIXEL_VERSION', '1.0.0' );
define( 'BUDGETPIXEL_PLUGIN_FILE', __FILE__ );
define( 'BUDGETPIXEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUDGETPIXEL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// The API origin. Override in wp-config.php for a staging API:
//   define( 'BUDGETPIXEL_API_BASE', 'https://api.example.test/v1' );
if ( ! defined( 'BUDGETPIXEL_API_BASE' ) ) {
	define( 'BUDGETPIXEL_API_BASE', 'https://api.budgetpixel.com/v1' );
}

require_once BUDGETPIXEL_PLUGIN_DIR . 'includes/class-budgetpixel-settings.php';
require_once BUDGETPIXEL_PLUGIN_DIR . 'includes/class-budgetpixel-api-client.php';
require_once BUDGETPIXEL_PLUGIN_DIR . 'includes/class-budgetpixel-media.php';
require_once BUDGETPIXEL_PLUGIN_DIR . 'includes/class-budgetpixel-rest.php';
require_once BUDGETPIXEL_PLUGIN_DIR . 'includes/class-budgetpixel-editor.php';

BudgetPixel_Settings::init();
BudgetPixel_REST::init();
BudgetPixel_Editor::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BUDGETPIXEL_PLUGIN_DIR . 'includes/class-budgetpixel-cli.php';
	WP_CLI::add_command( 'budgetpixel', 'BudgetPixel_CLI' );
}

/**
 * "Settings" link on the Plugins screen row.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'options-general.php?page=budgetpixel' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'budgetpixel-ai-images' ) . '</a>' );
		return $links;
	}
);
