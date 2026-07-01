<?php
/**
 * Unit tests for full-book scrolling settings (Sheaf\Scroll_Settings) and the
 * page-count estimator (Sheaf\Pages). CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-scroll.php
 *
 * Creates a throwaway book Page + chapters and deletes them again, so it is
 * safe to run on a live site.
 *
 * @package Sheaf
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$pass  = 0;
$fail  = 0;
$check = function ( bool $cond, string $label ) use ( &$pass, &$fail ) {
	if ( $cond ) {
		++$pass;
		WP_CLI::log( "  ok   $label" );
	} else {
		++$fail;
		WP_CLI::log( "  FAIL $label" );
	}
};

$created = [];

// Pin the page rate so page maths are deterministic regardless of any filter.
add_filter( 'sheaf_words_per_page', static fn() => 300, 99 );

// N words of plain content, so Words::count_in returns exactly N.
$content_of = static fn( int $words ): string => $words > 0 ? trim( str_repeat( 'word ', $words ) ) : '';

$make_chapter = static function ( int $book, string $title, int $words, int $order, bool $section = false ) use ( &$created, $content_of ): int {
	$id = (int) wp_insert_post(
		[
			'post_type'    => \Sheaf\Chapters::POST_TYPE,
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => $content_of( $words ),
			'menu_order'   => $order,
		]
	);
	update_post_meta( $id, \Sheaf\Books::BOOK_META, $book );
	if ( $section ) {
		update_post_meta( $id, \Sheaf\Chapters::SECTION_META, true );
	}
	\Sheaf\Words::refresh( $id );
	$created[] = $id;
	return $id;
};

try {
	/* -------------------------------------------------- Scroll_Settings ---- */

	$d = \Sheaf\Scroll_Settings::defaults();
	$check( false === $d['enabled'], 'default: disabled' );
	$check( true === $d['chapter_titles'], 'default: chapter titles on' );
	$check( 'page_break' === $d['chapter_break'], 'default: chapter break = page_break' );

	// sanitize(): enum clamp, form checkbox semantics, verbatim trimmed HTML.
	$clean = \Sheaf\Scroll_Settings::sanitize(
		[
			'enabled'            => '1',
			'chapter_break'      => 'bogus',
			'chapter_break_html' => '  <hr class="x">  ',
			'section_break'      => 'hr',
			// chapter_titles intentionally absent.
		]
	);
	$check( true === $clean['enabled'], 'sanitize: "1" -> true' );
	$check( 'page_break' === $clean['chapter_break'], 'sanitize: unknown break -> default' );
	$check( 'hr' === $clean['section_break'], 'sanitize: valid break kept' );
	$check( false === $clean['chapter_titles'], 'sanitize: absent checkbox -> false' );
	$check( '<hr class="x">' === $clean['chapter_break_html'], 'sanitize: HTML trimmed, kept verbatim' );

	// from_request() reads the sheaf_scroll[...] namespace.
	$req = \Sheaf\Scroll_Settings::from_request(
		[ 'sheaf_scroll' => [ 'enabled' => '1', 'show_full_toc' => '1' ] ]
	);
	$check( true === $req['enabled'] && true === $req['show_full_toc'], 'from_request: reads sheaf_scroll[]' );
	$check( false === $req['show_page_numbers'], 'from_request: unspecified stays false' );

	// lint_html(): clean markup passes, unbalanced markup warns, never strips.
	$check( [] === \Sheaf\Scroll_Settings::lint_html( '<hr>' ), 'lint: void tag is clean' );
	$check( [] === \Sheaf\Scroll_Settings::lint_html( '<div class="d"><span>ok</span></div>' ), 'lint: balanced markup is clean' );
	// libxml (like a browser) recovers unclosed tags silently, but flags genuine
	// malformation: mismatched nesting and stray end tags.
	$check( ! empty( \Sheaf\Scroll_Settings::lint_html( '<b><i>x</b></i>' ) ), 'lint: mismatched nesting warns' );
	$check( ! empty( \Sheaf\Scroll_Settings::lint_html( 'hello </div>' ) ), 'lint: stray end tag warns' );
	// Foreign (SVG/MathML) and custom-element tags are valid HTML5 dividers, not
	// malformation — they must not warn (libxml calls them "invalid").
	$check( [] === \Sheaf\Scroll_Settings::lint_html( '<svg viewBox="0 0 10 10"><line x1="0" y1="0" x2="10" y2="10"/></svg>' ), 'lint: inline SVG is clean' );
	$check( [] === \Sheaf\Scroll_Settings::lint_html( '<my-divider>x</my-divider>' ), 'lint: custom element is clean' );

	// break_html(): HTML only surfaces for the divider break choices.
	$check( '<hr>' === \Sheaf\Scroll_Settings::break_html( [ 'chapter_break' => 'hr', 'chapter_break_html' => '<hr>' ], 'chapter_break' ), 'break_html: returned for hr' );
	$check( '' === \Sheaf\Scroll_Settings::break_html( [ 'chapter_break' => 'page_break', 'chapter_break_html' => '<hr>' ], 'chapter_break' ), 'break_html: empty for page_break' );

	// No book -> defaults.
	$check( \Sheaf\Scroll_Settings::defaults() === \Sheaf\Scroll_Settings::get( 0 ), 'get(0): defaults' );

	// save()/get() round-trip on a real Page.
	$book = (int) wp_insert_post(
		[ 'post_type' => 'page', 'post_title' => 'Scroll Test Book', 'post_status' => 'publish' ]
	);
	$created[] = $book;

	\Sheaf\Scroll_Settings::save(
		$book,
		[
			'enabled'            => true,
			'chapter_break'      => 'hr',
			'chapter_break_html' => '<hr class="c">',
			'show_page_numbers'  => true,
		]
	);
	$got = \Sheaf\Scroll_Settings::get( $book );
	$check( true === $got['enabled'], 'round-trip: enabled' );
	$check( 'hr' === $got['chapter_break'], 'round-trip: chapter_break' );
	$check( '<hr class="c">' === $got['chapter_break_html'], 'round-trip: chapter_break_html verbatim' );
	$check( true === $got['show_page_numbers'], 'round-trip: show_page_numbers' );
	$check( true === \Sheaf\Scroll_Settings::enabled( $book ), 'enabled() helper true' );

	/* --------------------------------------------------------------- Pages -- */

	$check( 300 === \Sheaf\Pages::words_per_page(), 'pages: wpp filter honored' );
	$check( 0 === \Sheaf\Pages::for_words( 0 ), 'pages: 0 words -> 0 pages' );
	$check( 1 === \Sheaf\Pages::for_words( 1 ), 'pages: any content >= 1 page' );
	$check( 1 === \Sheaf\Pages::for_words( 300 ), 'pages: 300 words -> 1 page' );
	$check( 2 === \Sheaf\Pages::for_words( 301 ), 'pages: 301 words -> 2 pages' );

	// Cumulative book map, with a section interleaved (0 words, 0 pages).
	$c1 = $make_chapter( $book, 'One', 300, 1 );
	$c2 = $make_chapter( $book, 'Part Two', 0, 2, true );
	$c3 = $make_chapter( $book, 'Two', 600, 3 );

	$map = \Sheaf\Pages::book_map( $book );
	$check( 900 === $map['total_words'], 'map: total words sums non-section chapters' );
	$check( 3 === $map['total_pages'], 'map: 900 words -> 3 pages' );
	$check( 1 === $map['chapters'][ $c1 ]['start_page'], 'map: first chapter on page 1' );
	$check( 0 === $map['chapters'][ $c2 ]['pages'] && $map['chapters'][ $c2 ]['is_section'], 'map: section spans 0 pages' );
	$check( 2 === $map['chapters'][ $c3 ]['start_page'], 'map: third chapter starts on page 2' );
	$check( 2 === $map['chapters'][ $c3 ]['pages'], 'map: 600-word chapter spans 2 pages' );
} finally {
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
}

WP_CLI::log( sprintf( "\n%d passed, %d failed", $pass, $fail ) );
if ( $fail > 0 ) {
	WP_CLI::error( 'test-scroll: failures above' );
}
