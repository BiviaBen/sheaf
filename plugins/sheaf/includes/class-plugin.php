<?php
/**
 * Bootstraps the plugin and owns activation/deactivation.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/**
	 * Wire every subsystem to its hooks.
	 */
	public function boot(): void {
		Chapters::register();
		Books::register();
		Words::register();
		Permalinks::register();
		Frontend::register();
		Blocks::register();
		Admin::register();
		Books_Admin::register();
		Import::register();
	}

	/**
	 * On activation: ensure the CPT + rewrite tags exist, make sure the site
	 * uses pretty permalinks (the nested chapter URLs depend on them), then
	 * flush so chapter routing works immediately.
	 */
	public static function activate(): void {
		Chapters::register();

		if ( ! get_option( 'permalink_structure' ) ) {
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			update_option( 'rewrite_rules', '' );
		}

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
