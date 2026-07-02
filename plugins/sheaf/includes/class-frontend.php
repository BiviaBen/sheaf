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

	/** True only while render_fragment() runs a chapter through the_content. */
	private static bool $in_fragment = false;

	public static function register(): void {
		add_shortcode( 'sheaf_toc', [ self::class, 'toc_shortcode' ] );
		add_shortcode( 'sheaf_breadcrumbs', [ self::class, 'breadcrumbs_shortcode' ] );
		add_shortcode( 'sheaf_chapter_nav', [ self::class, 'chapter_nav_shortcode' ] );
		// Wrap a chapter's body in a .sheaf-chapter region *before* breadcrumbs
		// (9) and chapter-nav (11), so that chrome stays outside the region the
		// full-book reader splices and re-fetches.
		add_filter( 'the_content', [ self::class, 'wrap_chapter_content' ], 8 );
		add_filter( 'the_content', [ self::class, 'auto_breadcrumbs' ], 9 );
		add_filter( 'the_content', [ self::class, 'auto_chapter_nav' ], 11 );
		add_filter( 'body_class', [ self::class, 'body_class' ] );
		add_action( 'wp_head', [ self::class, 'print_style_css' ], 20 );

		// Full-book scrolling: serve a lightweight body-only fragment from the
		// canonical URL when asked, enqueue the reader with its book "spine", and
		// let caches distinguish fragment from full-page responses.
		add_action( 'template_redirect', [ self::class, 'maybe_serve_fragment' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_reader' ] );
		add_action( 'send_headers', [ self::class, 'vary_on_fragment' ] );

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

		// Always render the chapter's own trail here. In full-book view the
		// reader rewrites it to end at the book (client-side, so the plain
		// single-chapter fallback — no JS, or opted out — stays correct).
		return Renderer::breadcrumbs( (int) get_the_ID() ) . $content;
	}

	/**
	 * Wrap a chapter's body in a `.sheaf-chapter` region carrying its id and
	 * start page, but only when its book has full-book scrolling enabled. This
	 * is the marker the reader uses to find the current chapter and to extract
	 * the body from a fetched fragment, independent of the theme's markup.
	 *
	 * Runs inside the loop (main or a secondary fragment loop), so breadcrumbs
	 * and chapter-nav — which only fire on the main query — stay outside it.
	 */
	public static function wrap_chapter_content( string $content ): string {
		// Fire on the main chapter view or inside render_fragment(); never on
		// some other secondary the_content pass on the page.
		if ( ! self::$in_fragment && ! ( in_the_loop() && is_main_query() ) ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( Chapters::POST_TYPE !== get_post_type( $id ) ) {
			return $content;
		}
		$book_id = Books::get_book_id( $id );
		if ( ! $book_id || ! Scroll_Settings::enabled( $book_id ) ) {
			return $content;
		}

		$map   = Pages::book_map( $book_id );
		$start = (int) ( $map['chapters'][ $id ]['start_page'] ?? 1 );

		return sprintf(
			'<div class="sheaf-chapter" data-chapter-id="%1$d" data-page-start="%2$d">%3$s</div>',
			$id,
			$start,
			$content
		);
	}

	/**
	 * When a chapter is requested with the X-Sheaf-Fragment header, return just
	 * its `.sheaf-chapter` body region and stop. The request still hits the
	 * canonical chapter URL, so server logs count it as a real view, but the
	 * payload skips the theme's chrome. Requested by the reader as it loads the
	 * next/previous chapter.
	 */
	public static function maybe_serve_fragment(): void {
		if ( empty( $_SERVER['HTTP_X_SHEAF_FRAGMENT'] ) || ! is_singular( Chapters::POST_TYPE ) ) {
			return;
		}

		$id      = (int) get_queried_object_id();
		$book_id = Books::get_book_id( $id );

		nocache_headers();
		header( 'Vary: X-Sheaf-Fragment' );

		// Feature off for this book: nothing to splice. 204 tells the reader to
		// fall back to normal navigation.
		if ( ! $book_id || ! Scroll_Settings::enabled( $book_id ) ) {
			status_header( 204 );
			exit;
		}

		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Sheaf-Fragment: 1' );
		echo self::render_fragment( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content output, escaped by its own filters.
		exit;
	}

	/**
	 * Render one chapter's `.sheaf-chapter` region via a secondary loop. Because
	 * it isn't the main query, the breadcrumb/nav auto-filters skip, while
	 * wrap_chapter_content still wraps — so the output is body-only.
	 */
	private static function render_fragment( int $chapter_id ): string {
		$query = new \WP_Query(
			[
				'p'                   => $chapter_id,
				'post_type'           => Chapters::POST_TYPE,
				'posts_per_page'      => 1,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			]
		);

		$html              = '';
		self::$in_fragment = true;
		try {
			while ( $query->have_posts() ) {
				$query->the_post();
				$html = apply_filters( 'the_content', get_the_content() );
			}
		} finally {
			self::$in_fragment = false;
			wp_reset_postdata();
		}

		return $html;
	}

	/**
	 * Advertise that a chapter's response varies on the fragment header, so a
	 * shared cache never serves a body-only fragment where a full page belongs
	 * (or vice versa).
	 */
	public static function vary_on_fragment(): void {
		if ( is_singular( Chapters::POST_TYPE ) ) {
			header( 'Vary: X-Sheaf-Fragment', false );
		}
	}

	/**
	 * On a chapter whose book has full-book scrolling on, enqueue the reader and
	 * hand it the book "spine" (every chapter's id/title/url/word-count/page and
	 * the book's settings) so it can scroll, label and address chapters without
	 * a round trip for structure.
	 */
	public static function enqueue_reader(): void {
		if ( ! is_singular( Chapters::POST_TYPE ) ) {
			return;
		}
		$chapter_id = (int) get_queried_object_id();
		$book_id    = Books::get_book_id( $chapter_id );
		if ( ! $book_id || ! Scroll_Settings::enabled( $book_id ) ) {
			return;
		}

		$css     = SHEAF_DIR . 'assets/reader.css';
		$js      = SHEAF_DIR . 'assets/reader.js';
		$css_ver = file_exists( $css ) ? (string) filemtime( $css ) : SHEAF_VERSION;
		$js_ver  = file_exists( $js ) ? (string) filemtime( $js ) : SHEAF_VERSION;

		wp_enqueue_style( 'sheaf-reader', SHEAF_URL . 'assets/reader.css', [], $css_ver );
		wp_enqueue_script( 'sheaf-reader', SHEAF_URL . 'assets/reader.js', [], $js_ver, true );
		wp_add_inline_script(
			'sheaf-reader',
			'window.SheafScroll = ' . wp_json_encode( self::build_spine( $book_id, $chapter_id ) ) . ';',
			'before'
		);
	}

	/**
	 * The data the reader needs up front: the book's chapters in reading order
	 * (id, title, canonical URL, words, reading minutes, start page, section
	 * flag) plus the resolved display settings.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_spine( int $book_id, int $chapter_id ): array {
		$settings = Scroll_Settings::get( $book_id );
		$map      = Pages::book_map( $book_id );

		$chapters = [];
		foreach ( Books::get_chapters( $book_id ) as $chapter ) {
			$id    = (int) $chapter->ID;
			$info  = $map['chapters'][ $id ] ?? [];
			$words = (int) ( $info['words'] ?? 0 );

			$chapters[] = [
				'id'        => $id,
				'title'     => get_the_title( $chapter ),
				'url'       => get_permalink( $chapter ),
				'words'     => $words,
				'minutes'   => Words::reading_minutes( $words ),
				'startPage' => (int) ( $info['start_page'] ?? 1 ),
				'pages'     => (int) ( $info['pages'] ?? 0 ),
				'isSection' => (bool) ( $info['is_section'] ?? false ),
			];
		}

		return [
			'bookId'     => $book_id,
			'bookTitle'  => get_the_title( $book_id ),
			'bookUrl'    => get_permalink( $book_id ),
			'bookCrumbs' => Renderer::breadcrumbs( $book_id ),
			'currentId'  => $chapter_id,
			'totalPages' => (int) $map['total_pages'],
			'settings'   => [
				'chapterTitles'        => (bool) $settings['chapter_titles'],
				'chapterBreak'         => (string) $settings['chapter_break'],
				'chapterBreakHtml'     => (string) $settings['chapter_break_html'],
				'specialSectionBreaks' => (bool) $settings['special_section_breaks'],
				'sectionBreak'         => (string) $settings['section_break'],
				'sectionBreakHtml'     => (string) $settings['section_break_html'],
				'showPageNumbers'      => (bool) $settings['show_page_numbers'],
				'showFullToc'          => (bool) $settings['show_full_toc'],
			],
			'chapters'   => $chapters,
		];
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
