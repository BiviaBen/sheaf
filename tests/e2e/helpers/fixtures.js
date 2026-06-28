// Self-cleaning fixtures for the style-set specs.
//
// Setup ADDS a uniquely named style set (one inline + one block style), a book
// Page that activates it, and a draft chapter in that book. It does not touch
// the author's existing sets, so the live site stays usable during a test run.
// Teardown removes exactly what setup created.

const { wpEval, wpEvalJson } = require( './wp.js' );

function setupStyleFixture() {
	const php = `
		$set  = \\Sheaf\\Style_Sets::save_set( 'E2E Fixture Set' );
		$in   = \\Sheaf\\Style_Sets::save_style( $set, [ 'label' => 'E2E Computer Voice', 'kind' => 'inline', 'props' => [ 'font-family' => 'monospace' ] ] );
		$bl   = \\Sheaf\\Style_Sets::save_style( $set, [ 'label' => 'E2E Verse', 'kind' => 'block', 'props' => [ 'text-align' => 'center' ] ] );
		$book = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'E2E Fixture Book', 'post_status' => 'publish' ] );
		update_post_meta( $book, \\Sheaf\\Style_Sets::BOOK_META, [ $set ] );
		$content = '<!-- wp:paragraph --><p>Fixture body text for E2E.</p><!-- /wp:paragraph -->';
		$chapter = wp_insert_post( [ 'post_type' => 'sheaf_chapter', 'post_title' => 'E2E Fixture Chapter', 'post_status' => 'draft', 'post_content' => $content ] );
		update_post_meta( $chapter, \\Sheaf\\Books::BOOK_META, $book );
		echo wp_json_encode( [
			'set'          => $set,
			'inKey'        => $in,
			'blKey'        => $bl,
			'book'         => (int) $book,
			'chapter'      => (int) $chapter,
			'inlineFormat' => 'sheaf/' . \\Sheaf\\Style_Sets::style_class( $set, $in ),
			'inlineClass'  => \\Sheaf\\Style_Sets::style_class( $set, $in ),
			'blockName'    => \\Sheaf\\Style_Sets::block_style_name( $set, $bl ),
			'blockClass'   => \\Sheaf\\Style_Sets::css_class( $set, $bl, 'block' ),
		] );
	`;
	return wpEvalJson( php );
}

function teardownStyleFixture( fx ) {
	if ( ! fx ) {
		return;
	}
	const php = `
		wp_delete_post( ${ Number( fx.chapter ) }, true );
		wp_delete_post( ${ Number( fx.book ) }, true );
		\\Sheaf\\Style_Sets::delete_set( '${ String( fx.set ).replace( /[^a-z0-9-]/gi, '' ) }' );
		echo 'ok';
	`;
	wpEval( php );
}

// Force-delete a list of post ids (chapters/pages) created by a fixture.
function deletePosts( ids ) {
	const list = ( ids || [] ).filter( Boolean ).map( Number ).join( ',' );
	if ( ! list ) {
		return;
	}
	wpEval( `foreach ( [ ${ list } ] as $id ) { wp_delete_post( $id, true ); } echo 'ok';` );
}

// A book with three published chapters in a known reading order (0,1,2).
function setupReorderFixture() {
	const php = `
		$book = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'E2E Reorder Book', 'post_status' => 'publish' ] );
		$titles = [ 'E2E Chapter Alpha', 'E2E Chapter Bravo', 'E2E Chapter Charlie' ];
		$ids = [];
		foreach ( $titles as $i => $t ) {
			$id = wp_insert_post( [ 'post_type' => 'sheaf_chapter', 'post_title' => $t, 'post_status' => 'publish', 'menu_order' => $i ] );
			update_post_meta( $id, \\Sheaf\\Books::BOOK_META, $book );
			$ids[] = (int) $id;
		}
		echo wp_json_encode( [ 'book' => (int) $book, 'chapters' => $ids, 'titles' => $titles ] );
	`;
	return wpEvalJson( php );
}

function teardownReorderFixture( fx ) {
	if ( fx ) {
		deletePosts( [ ...( fx.chapters || [] ), fx.book ] );
	}
}

// Two books and three chapters (all starting in book one) for Quick/Bulk Edit.
function setupInlineEditFixture() {
	const php = `
		$b1 = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'E2E Inline Book One', 'post_status' => 'publish' ] );
		$b2 = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'E2E Inline Book Two', 'post_status' => 'publish' ] );
		$ids = [];
		foreach ( [ 'E2E Inline Chapter One', 'E2E Inline Chapter Two', 'E2E Inline Chapter Three' ] as $t ) {
			$id = wp_insert_post( [ 'post_type' => 'sheaf_chapter', 'post_title' => $t, 'post_status' => 'publish' ] );
			update_post_meta( $id, \\Sheaf\\Books::BOOK_META, $b1 );
			$ids[] = (int) $id;
		}
		echo wp_json_encode( [
			'book1' => (int) $b1, 'book1Title' => 'E2E Inline Book One',
			'book2' => (int) $b2, 'book2Title' => 'E2E Inline Book Two',
			'chapters' => $ids,
		] );
	`;
	return wpEvalJson( php );
}

function teardownInlineEditFixture( fx ) {
	if ( fx ) {
		deletePosts( [ ...( fx.chapters || [] ), fx.book1, fx.book2 ] );
	}
}

// A book (with a chapter, so it counts as a book) plus a standalone Page that is
// NOT a book — so the "show all pages" list is a strict superset of books-only.
function setupSelectorFixture() {
	const php = `
		$book = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'E2E Selector Book', 'post_status' => 'publish' ] );
		$chapter = wp_insert_post( [ 'post_type' => 'sheaf_chapter', 'post_title' => 'E2E Selector Chapter', 'post_status' => 'draft' ] );
		update_post_meta( $chapter, \\Sheaf\\Books::BOOK_META, $book );
		$plain = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'E2E Plain Page (not a book)', 'post_status' => 'publish' ] );
		echo wp_json_encode( [ 'book' => (int) $book, 'chapter' => (int) $chapter, 'plain' => (int) $plain ] );
	`;
	return wpEvalJson( php );
}

function teardownSelectorFixture( fx ) {
	if ( fx ) {
		deletePosts( [ fx.chapter, fx.book, fx.plain ] );
	}
}

// Read a chapter's current book id (for asserting Quick/Bulk Edit persistence).
function chapterBook( chapterId ) {
	const out = wpEvalJson(
		`echo wp_json_encode( [ 'book' => (int) get_post_meta( ${ Number( chapterId ) }, \\Sheaf\\Books::BOOK_META, true ) ] );`
	);
	return out.book;
}

module.exports = {
	setupStyleFixture,
	teardownStyleFixture,
	setupReorderFixture,
	teardownReorderFixture,
	setupInlineEditFixture,
	teardownInlineEditFixture,
	setupSelectorFixture,
	teardownSelectorFixture,
	chapterBook,
};
