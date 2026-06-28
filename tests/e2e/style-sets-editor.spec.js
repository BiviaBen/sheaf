// Phase-4 editor integration, verified in the real Gutenberg editor — the
// surface the PHP unit tests can't reach. Uses a self-cleaning fixture (a book
// with one inline + one block style active, and a chapter in that book).

const { test, expect } = require( '@playwright/test' );
const { setupStyleFixture, teardownStyleFixture } = require( './helpers/fixtures' );

let fx;

test.beforeAll( () => {
	fx = setupStyleFixture();
} );

test.afterAll( () => {
	teardownStyleFixture( fx );
} );

async function openChapterEditor( page, id ) {
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	// Wait for the block editor data layer to be ready.
	await page.waitForFunction(
		() => window.wp && wp.data && wp.data.select( 'core/rich-text' ) && wp.data.select( 'core/blocks' )
	);
	// Dismiss the welcome guide if it pops up, so it can't overlay the metabox.
	await page.evaluate( () => {
		for ( const scope of [ 'core/edit-post', 'core' ] ) {
			try {
				wp.data.dispatch( 'core/preferences' ).set( scope, 'welcomeGuide', false );
			} catch ( e ) {} // eslint-disable-line no-empty
		}
	} );
	await page.keyboard.press( 'Escape' ).catch( () => {} );
}

test.describe( 'Style sets — chapter editor', () => {
	test( 'active styles are localized into the editor', async ( { page } ) => {
		await openChapterEditor( page, fx.chapter );

		const data = await page.evaluate( () => window.SheafStyles );
		expect( data, 'SheafStyles should be localized on the chapter editor' ).toBeTruthy();
		// wp_localize_script serializes the id as a string.
		expect( Number( data.bookId ) ).toBe( fx.book );

		const titles = ( data.styles || [] ).map( ( s ) => s.title );
		expect( titles ).toContain( 'E2E Computer Voice' );
		expect( titles ).toContain( 'E2E Verse' );
	} );

	test( 'inline style is registered as a rich-text format', async ( { page } ) => {
		await openChapterEditor( page, fx.chapter );

		const formats = await page.evaluate( () =>
			wp.data.select( 'core/rich-text' ).getFormatTypes().map( ( f ) => f.name )
		);
		expect( formats ).toContain( fx.inlineFormat );
	} );

	test( 'block style is registered as a paragraph variation', async ( { page } ) => {
		await openChapterEditor( page, fx.chapter );

		const styleNames = await page.evaluate( () =>
			wp.data.select( 'core/blocks' ).getBlockStyles( 'core/paragraph' ).map( ( s ) => s.name )
		);
		expect( styleNames ).toContain( fx.blockName );
	} );

	test( 'changing the book warns to save and reload', async ( { page } ) => {
		await openChapterEditor( page, fx.chapter );

		// The classic Book meta box renders below the editor; switch it away from
		// the chapter's current book.
		const select = page.locator( '#sheaf-book-books' );
		await expect( select ).toBeVisible();
		await select.selectOption( '0' );

		// Match the notice itself, not the screen-reader live region that mirrors it.
		await expect(
			page.locator( '.components-notice__content', {
				hasText: /Save and reload to refresh the available styles/i,
			} )
		).toBeVisible();
	} );
} );
