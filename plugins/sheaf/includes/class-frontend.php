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
		add_shortcode( 'sheaf_chapter_nav', [ self::class, 'chapter_nav_shortcode' ] );
		add_filter( 'the_content', [ self::class, 'auto_breadcrumbs' ], 9 );
		add_filter( 'the_content', [ self::class, 'auto_chapter_nav' ], 11 );
		add_filter( 'body_class', [ self::class, 'body_class' ] );
		add_action( 'wp_head', [ self::class, 'print_style_css' ], 20 );

		// Themes navigate chapters by post date (a "previous"/"next" chapter from
		// some other book). Reading order is by book + menu_order, so suppress the
		// theme's adjacency for chapters; our chapter_nav provides the real links.
		add_filter( 'get_previous_post_where', [ self::class, 'suppress_chapter_adjacency' ], 10, 5 );
		add_filter( 'get_next_post_where', [ self::class, 'suppress_chapter_adjacency' ], 10, 5 );
	}

	/**
	 * Make a chapter have no date-based adjacent post, so the theme's built-in
	 * previous/next navigation finds nothing and renders nothing.
	 *
	 * @param string        $where The adjacent-post WHERE clause.
	 * @param bool          $in_same_term  Unused.
	 * @param int[]|string  $excluded_terms Unused.
	 * @param string        $taxonomy Unused.
	 * @param \WP_Post|null $post The post being navigated from.
	 */
	public static function suppress_chapter_adjacency( $where, $in_same_term = false, $excluded_terms = '', $taxonomy = '', $post = null ): string {
		if ( $post instanceof \WP_Post && Chapters::POST_TYPE === $post->post_type ) {
			return $where . ' AND 0 = 1';
		}
		return (string) $where;
	}

	/**
	 * Add CSS hooks to a chapter's <body>: a section-divider marker plus classes
	 * that map the chapter's place in the book/series hierarchy.
	 */
	public static function body_class( array $classes ): array {
		if ( ! is_singular( Chapters::POST_TYPE ) ) {
			return $classes;
		}

		$chapter_id = (int) get_queried_object_id();

		if ( Chapters::is_section( $chapter_id ) ) {
			$classes[] = 'sheaf-section';
		}

		return array_merge( $classes, self::hierarchy_classes( $chapter_id ) );
	}

	/**
	 * Body classes that locate a chapter in the book/series hierarchy, so authors
	 * can target CSS at a whole series, a single book, or one chapter. Two
	 * flavours, because each covers the other's weakness:
	 *
	 *   - Readable cumulative path classes — "sheaf-novels",
	 *     "sheaf-novels-long-war", "sheaf-novels-long-war-embers", and the
	 *     chapter "sheaf-novels-long-war-embers-1-the-cold-road". Easy to read
	 *     and author, but they change if a Page is renamed or moved.
	 *   - Stable id classes — "sheaf-book-114", "sheaf-page-98",
	 *     "sheaf-chapter-228" — which survive renames/moves but aren't readable.
	 *
	 * These are an authoring/override surface only; the named style sets emit
	 * their own globally-keyed CSS independently of these classes.
	 *
	 * @return string[]
	 */
	private static function hierarchy_classes( int $chapter_id ): array {
		$out     = [ 'sheaf-chapter-' . $chapter_id ];
		$book_id = Books::get_book_id( $chapter_id );
		if ( ! $book_id ) {
			return $out;
		}

		// Stable id classes. "sheaf-book-<id>" marks the chapter's direct book.
		// "sheaf-page-<id>" is emitted for the book *and* every ancestor, so a
		// single id selector (e.g. a series Page's) targets everything at or
		// below it — whether a chapter sits in that Page or in a child Book.
		$out[] = 'sheaf-book-' . $book_id;
		$out[] = 'sheaf-page-' . $book_id;
		foreach ( Books::ancestors( $book_id ) as $ancestor ) {
			$out[] = 'sheaf-page-' . (int) $ancestor->ID;
		}

		// Readable cumulative path: one class per ancestry level, then the chapter.
		$uri = get_page_uri( $book_id );
		if ( $uri ) {
			$prefix = 'sheaf';
			foreach ( explode( '/', $uri ) as $segment ) {
				$segment = sanitize_html_class( $segment );
				if ( '' === $segment ) {
					continue;
				}
				$prefix .= '-' . $segment;
				$out[]   = $prefix;
			}
			$slug = sanitize_html_class( (string) get_post_field( 'post_name', $chapter_id ) );
			if ( '' !== $slug ) {
				$out[] = $prefix . '-' . $slug;
			}
		}

		return $out;
	}

	/**
	 * Emit the whole style-set library as one global <style> block in the head.
	 *
	 * The CSS is keyed on each style's class alone (Style_Sets::style_class), so
	 * a class means the same thing wherever it appears — per-book activation
	 * governs what the editor and importer OFFER, not what is styled here. That
	 * is also why this prints on every front-end view rather than only on
	 * chapters: styled text can surface anywhere (an excerpt, a widget). v1
	 * prints inline; the identical rules can later become a single cacheable
	 * stylesheet without changing their meaning.
	 */
	public static function print_style_css(): void {
		if ( is_admin() ) {
			return;
		}
		// @font-face for referenced web fonts, then the style rules.
		$css = Fonts::font_face_css() . self::style_css();
		if ( '' === $css ) {
			return;
		}
		// Class names come from sanitize_title and the declarations are sanitised
		// at the source (Style_Sets::sanitize_props/sanitize_raw_css strip tags,
		// braces and angle brackets) — that is the boundary that makes this safe
		// to print raw. Escaping here would corrupt valid CSS such as quoted
		// font-family names.
		echo "<style id=\"sheaf-style-sets\">\n" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the style-set CSS: one rule per style across the whole library,
	 * skipping styles whose definition is empty.
	 */
	public static function style_css(): string {
		$rules = '';
		foreach ( Style_Sets::all() as $set => $data ) {
			foreach ( (array) ( $data['styles'] ?? [] ) as $style => $def ) {
				$decls = Style_Sets::declarations( (array) $def );
				if ( '' === $decls ) {
					continue;
				}
				$kind   = in_array( $def['kind'] ?? 'inline', Style_Sets::KINDS, true ) ? (string) $def['kind'] : 'inline';
				$rules .= '.' . Style_Sets::css_class( (string) $set, (string) $style, $kind ) . ' { ' . $decls . " }\n";
			}
		}
		return $rules;
	}

	public static function toc_shortcode( $atts ): string {
		$atts    = shortcode_atts(
			[
				'book'         => '',
				'reading_time' => 'yes',
			],
			$atts,
			'sheaf_toc'
		);
		$book_id = self::resolve_book_attr( (string) $atts['book'] );
		return Renderer::toc(
			$book_id,
			[ 'reading_time' => self::is_truthy( $atts['reading_time'] ) ]
		);
	}

	public static function breadcrumbs_shortcode( $atts ): string {
		return Renderer::breadcrumbs();
	}

	public static function chapter_nav_shortcode( $atts ): string {
		return Renderer::chapter_nav();
	}

	/**
	 * Append previous/next links to a single chapter's content.
	 */
	public static function auto_chapter_nav( string $content ): string {
		if ( ! is_singular( Chapters::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		/** Filter: return false to disable automatic chapter prev/next links. */
		if ( ! apply_filters( 'sheaf_auto_chapter_nav', true ) ) {
			return $content;
		}

		return $content . Renderer::chapter_nav( (int) get_the_ID() );
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
	 * Interpret a shortcode boolean attribute ("no"/"0"/"false" = false).
	 */
	private static function is_truthy( string $value ): bool {
		return ! in_array( strtolower( trim( $value ) ), [ 'no', '0', 'false', 'off', '' ], true );
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
