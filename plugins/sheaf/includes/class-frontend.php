<?php
/**
 * Front-end shortcodes and the automatic chapter breadcrumb.
 *
 * - [sheaf_toc book="123|slug"]   table of contents (opt-in, anywhere)
 * - [sheaf_breadcrumbs]           breadcrumb trail (opt-in, anywhere)
 * - Breadcrumbs are also auto-prepended to single chapter views (the one
 *   piece of automatic chrome, since chapters are plugin-presented). The TOC
 *   is never auto-injected. Both behaviours are filterable.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Frontend {

	public static function register(): void {
		add_shortcode( 'sheaf_toc', [ self::class, 'toc_shortcode' ] );
		add_shortcode( 'sheaf_breadcrumbs', [ self::class, 'breadcrumbs_shortcode' ] );
		add_filter( 'the_content', [ self::class, 'auto_breadcrumbs' ], 9 );
	}

	public static function toc_shortcode( $atts ): string {
		$atts    = shortcode_atts( [ 'book' => '' ], $atts, 'sheaf_toc' );
		$book_id = self::resolve_book_attr( (string) $atts['book'] );
		return Renderer::toc( $book_id );
	}

	public static function breadcrumbs_shortcode( $atts ): string {
		return Renderer::breadcrumbs();
	}

	/**
	 * Prepend breadcrumbs to a single chapter's content.
	 */
	public static function auto_breadcrumbs( string $content ): string {
		if ( ! is_singular( Chapters::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		/** Filter: return false to disable automatic chapter breadcrumbs. */
		if ( ! apply_filters( 'sheaf_auto_breadcrumbs', true ) ) {
			return $content;
		}

		return Renderer::breadcrumbs( (int) get_the_ID() ) . $content;
	}

	/**
	 * Turn a shortcode "book" attribute (numeric ID or a Page path/slug) into
	 * a book Page ID. Empty falls back to auto-detection in the Renderer.
	 */
	private static function resolve_book_attr( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		$page = get_page_by_path( $value, OBJECT, 'page' );
		return $page ? (int) $page->ID : 0;
	}
}
