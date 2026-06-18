<?php
/**
 * Plugin Name:       Sheaf
 * Description:       Publish novels as one chapter per post, organised into books and series built from ordinary Pages.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            Sheaf
 * License:           GPL-2.0-or-later
 * Text Domain:       sheaf
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHEAF_VERSION', '0.1.0' );
define( 'SHEAF_FILE', __FILE__ );
define( 'SHEAF_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHEAF_URL', plugin_dir_url( __FILE__ ) );

require_once SHEAF_DIR . 'includes/class-books.php';
require_once SHEAF_DIR . 'includes/class-chapters.php';
require_once SHEAF_DIR . 'includes/class-permalinks.php';
require_once SHEAF_DIR . 'includes/class-renderer.php';
require_once SHEAF_DIR . 'includes/class-frontend.php';
require_once SHEAF_DIR . 'includes/class-blocks.php';
require_once SHEAF_DIR . 'includes/class-admin.php';
require_once SHEAF_DIR . 'includes/class-plugin.php';

Plugin::instance()->boot();

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );
