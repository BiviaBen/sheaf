// The "Show all pages" toggle that appears on both book selectors: the chapter
// editor's Book meta box and the Import screen's "Add to book" picker. Checking
// it swaps a books-only <select> for the full page list, flipping which control
// is visible + enabled (disabled controls aren't submitted, so only one value
// is ever sent).

const { test, expect } = require( '@playwright/test' );
const { setupSelectorFixture, teardownSelectorFixture } = require( './helpers/fixtures' );

let fx;

test.beforeAll( () => {
	fx = setupSelectorFixture();
} );

test.afterAll( () => {
	teardownSelectorFixture( fx );
} );

async function dismissWelcomeGuide( page ) {
	await page.evaluate( () => {
		for ( const scope of [ 'core/edit-post', 'core' ] ) {
			try {
				wp.data.dispatch( 'core/preferences' ).set( scope, 'welcomeGuide', false );
			} catch ( e ) {} // eslint-disable-line no-empty
		}
	} );
	await page.keyboard.press( 'Escape' ).catch( () => {} );
}

async function assertToggle( page, ids ) {
	const books = page.locator( `#${ ids.books }` );
	const all = page.locator( `#${ ids.all }` );
	const cb = page.locator( `#${ ids.cb }` );
	const note = page.locator( `#${ ids.note }` );

	await expect( cb ).toBeVisible();

	// Default: books-only selector is live; the all-pages list is hidden+disabled.
	await expect( books ).toBeVisible();
	await expect( books ).toBeEnabled();
	await expect( all ).toBeHidden();
	await expect( all ).toBeDisabled();
	await expect( note ).toBeHidden();

	// Toggle on: the two swap roles, and the explainer note appears.
	await cb.check();
	await expect( books ).toBeHidden();
	await expect( books ).toBeDisabled();
	await expect( all ).toBeVisible();
	await expect( all ).toBeEnabled();
	await expect( note ).toBeVisible();

	// The all-pages list is a superset of books-only (our fixture adds a non-book
	// page, so it is strictly larger).
	const booksOptions = await books.locator( 'option' ).count();
	const allOptions = await all.locator( 'option' ).count();
	expect( allOptions ).toBeGreaterThan( booksOptions );

	// Toggle back off: original state restored.
	await cb.uncheck();
	await expect( books ).toBeVisible();
	await expect( books ).toBeEnabled();
	await expect( all ).toBeHidden();
	await expect( all ).toBeDisabled();
	await expect( note ).toBeHidden();
}

test( 'chapter editor Book meta box toggles to all pages', async ( { page } ) => {
	await page.goto( `/wp-admin/post.php?post=${ fx.chapter }&action=edit` );
	await page.waitForFunction( () => window.wp && wp.data && wp.data.select( 'core/editor' ) );
	await dismissWelcomeGuide( page );
	await expect( page.locator( '#sheaf-book-allpages' ) ).toBeVisible();

	await assertToggle( page, {
		books: 'sheaf-book-books',
		all: 'sheaf-book-all',
		cb: 'sheaf-book-allpages',
		note: 'sheaf-book-allpages-note',
	} );
} );

test( 'import screen book picker toggles to all pages', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=sheaf-import' );
	await expect( page.locator( '#sheaf-import-book-allpages' ) ).toBeVisible();

	await assertToggle( page, {
		books: 'sheaf-import-book',
		all: 'sheaf-import-book-all',
		cb: 'sheaf-import-book-allpages',
		note: 'sheaf-import-book-allpages-note',
	} );
} );
