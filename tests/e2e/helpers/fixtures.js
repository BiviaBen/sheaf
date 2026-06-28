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

module.exports = { setupStyleFixture, teardownStyleFixture };
