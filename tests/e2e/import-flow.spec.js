// End-to-end import: upload a .docx with named Word styles, and — because the
// target book has no style sets — create a new set from the found styles, then
// create the draft chapter carrying the mapped classes.

const { test, expect } = require( '@playwright/test' );
const { setupSelectorFixture, cleanupE2E } = require( './helpers/fixtures' );
const { wpEvalJson } = require( './helpers/wp' );
const { makeDocx } = require( './helpers/docx' );

let book;

test.beforeAll( () => {
	book = setupSelectorFixture().book; // a real book with no active style sets
} );

test.afterAll( () => {
	cleanupE2E();
} );

test( 'upload → create a set from found Word styles → create a draft', async ( { page } ) => {
	const buffer = await makeDocx();

	// Upload.
	await page.goto( `/wp-admin/admin.php?page=sheaf-import&sheaf_book=${ book }` );
	const named = page.locator( 'input[name="settings[keep_named_styles]"]' );
	if ( ! ( await named.isChecked() ) ) {
		await named.check();
	}
	await page.locator( 'input[name="sheaf_files[]"]' ).setInputFiles( {
		name: 'E2E Import.docx',
		mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		buffer,
	} );
	await page.getByRole( 'button', { name: /Upload and preview/i } ).click();

	// Preview: found Word styles + the create-new-set field (book has no sets).
	await expect( page.getByRole( 'heading', { name: 'Word styles' } ) ).toBeVisible();
	await expect( page.getByText( 'ComputerVoice' ) ).toBeVisible();
	await expect( page.getByText( 'Verse', { exact: true } ) ).toBeVisible();
	await page.locator( 'input[name="new_set"]' ).fill( 'E2E Imported Set' );

	// Create drafts. (Force the click: this submit button sits in a region the
	// WP admin keeps micro-reflowing, so the actionability "stable" check hangs;
	// the button itself is a plain, unobscured submit.)
	await page.getByRole( 'button', { name: /Create .*draft/i } ).click( { force: true } );
	await page.waitForLoadState( 'load' );

	// A set was created from the found styles, activated on the book, and a draft
	// chapter carries both mapped classes.
	const result = wpEvalJson( `
		$active = \\Sheaf\\Style_Sets::active_sets( ${ book } );
		$set    = $active[0] ?? '';
		$sd     = $set ? \\Sheaf\\Style_Sets::get_set( $set ) : null;
		$cls_in = $set ? \\Sheaf\\Style_Sets::style_class( $set, 'computervoice' ) : '';
		$cls_bl = $set ? \\Sheaf\\Style_Sets::css_class( $set, 'verse', 'block' ) : '';
		$chaps  = get_posts( [ 'post_type' => 'sheaf_chapter', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => \\Sheaf\\Books::BOOK_META, 'meta_value' => ${ book }, 'fields' => 'ids' ] );
		$in = false; $bl = false;
		foreach ( $chaps as $cid ) {
			$c = (string) get_post_field( 'post_content', $cid );
			if ( $cls_in && false !== strpos( $c, $cls_in ) ) { $in = true; }
			if ( $cls_bl && false !== strpos( $c, $cls_bl ) ) { $bl = true; }
		}
		echo wp_json_encode( [ 'set' => $set, 'styles' => array_keys( (array) ( $sd['styles'] ?? [] ) ), 'inline' => $in, 'block' => $bl ] );
	` );

	expect( result.set, 'a new set should be created and activated' ).not.toBe( '' );
	expect( result.styles ).toContain( 'computervoice' );
	expect( result.styles ).toContain( 'verse' );
	expect( result.inline, 'imported draft carries the inline span class' ).toBe( true );
	expect( result.block, 'imported draft carries the block style class' ).toBe( true );
} );
