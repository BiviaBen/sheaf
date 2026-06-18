<?php
/**
 * Dynamic (server-rendered) blocks: sheaf/toc and sheaf/breadcrumbs.
 *
 * Rendering is done in PHP (shared with the shortcodes), so there is no build
 * step. The editor uses wp.serverSideRender via a single hand-written script.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Blocks {

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_blocks' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'enqueue_editor' ] );
	}

	public static function register_blocks(): void {
		register_block_type(
			SHEAF_DIR . 'blocks/toc',
			[ 'render_callback' => [ self::class, 'render_toc' ] ]
		);
		register_block_type(
			SHEAF_DIR . 'blocks/breadcrumbs',
			[ 'render_callback' => [ self::class, 'render_breadcrumbs' ] ]
		);
	}

	public static function render_toc( array $attributes ): string {
		return Renderer::toc( (int) ( $attributes['book'] ?? 0 ) );
	}

	public static function render_breadcrumbs( array $attributes ): string {
		return Renderer::breadcrumbs();
	}

	/**
	 * Register the editor representations of both blocks (no build step).
	 */
	public static function enqueue_editor(): void {
		wp_enqueue_script(
			'sheaf-blocks',
			SHEAF_URL . 'blocks/editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-i18n' ],
			SHEAF_VERSION,
			true
		);
	}
}
