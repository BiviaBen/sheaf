<?php
/**
 * Editor integration for style sets.
 *
 * Enqueues the chapter editor script and hands it the styles available to the
 * chapter's book: inline styles become rich-text formats, block styles become
 * paragraph block-style variations. The set of styles is computed per book at
 * load time; the script only warns if the author changes the book afterwards.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Style_Sets_Editor {

	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', [ self::class, 'enqueue' ] );
		add_action( 'enqueue_block_assets', [ self::class, 'enqueue_canvas_css' ] );
	}

	/**
	 * Put the style-set CSS inside the editor's content iframe so a style is
	 * visible the moment it is applied, not only after saving. enqueue_block_assets
	 * is the hook that reaches the iframe; the front end already gets this CSS via
	 * Frontend::print_style_css(), so we only add it on the admin side here.
	 */
	public static function enqueue_canvas_css(): void {
		if ( ! is_admin() ) {
			return;
		}
		$css = Frontend::style_css();
		if ( '' === $css ) {
			return;
		}
		wp_register_style( 'sheaf-style-sets-editor', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline-only handle.
		wp_enqueue_style( 'sheaf-style-sets-editor' );
		wp_add_inline_style( 'sheaf-style-sets-editor', $css );
	}

	/**
	 * Load the editor controls on the chapter editor only, seeded with the
	 * chapter book's active styles.
	 */
	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || Chapters::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post    = get_post();
		$book_id = $post instanceof \WP_Post ? (int) Books::get_book_id( (int) $post->ID ) : 0;

		// Version by file mtime so edits bust the cache during development.
		$asset = SHEAF_DIR . 'assets/editor-styles.js';
		$ver   = file_exists( $asset ) ? (string) filemtime( $asset ) : SHEAF_VERSION;

		wp_enqueue_script(
			'sheaf-editor-styles',
			SHEAF_URL . 'assets/editor-styles.js',
			[ 'wp-rich-text', 'wp-block-editor', 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-data', 'wp-dom-ready' ],
			$ver,
			true
		);
		wp_localize_script(
			'sheaf-editor-styles',
			'SheafStyles',
			[
				'bookId' => $book_id,
				'styles' => self::styles_for_book( $book_id ),
				'i18n'   => [
					'bookChanged' => __( 'You changed this chapter’s book. Save and reload to refresh the available styles.', 'sheaf' ),
					'stylesLabel' => __( 'Styles', 'sheaf' ),
				],
			]
		);
	}

	/**
	 * The active styles for a book, flattened for the editor script: one entry
	 * per style across the book's active sets, tagged by kind.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function styles_for_book( int $book_id ): array {
		$out = [];
		foreach ( Style_Sets::active_sets( $book_id ) as $set ) {
			$set_data = Style_Sets::get_set( $set );
			if ( ! $set_data ) {
				continue;
			}
			$set_label = '' !== (string) ( $set_data['label'] ?? '' ) ? (string) $set_data['label'] : (string) $set;

			foreach ( (array) ( $set_data['styles'] ?? [] ) as $style => $def ) {
				$kind  = in_array( $def['kind'] ?? 'inline', Style_Sets::KINDS, true ) ? (string) $def['kind'] : 'inline';
				$title = '' !== (string) ( $def['label'] ?? '' ) ? (string) $def['label'] : (string) $style;

				if ( 'block' === $kind ) {
					$out[] = [
						'kind'      => 'block',
						'blockName' => Style_Sets::block_style_name( (string) $set, (string) $style ),
						'title'     => $title,
						'setLabel'  => $set_label,
					];
					continue;
				}

				$class = Style_Sets::style_class( (string) $set, (string) $style );
				$out[] = [
					'kind'     => 'inline',
					'name'     => 'sheaf/' . $class,
					'class'    => $class,
					'title'    => $title,
					'setLabel' => $set_label,
				];
			}
		}
		return $out;
	}
}
