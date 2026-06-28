// Assigning chapters to a book from the chapter list via Quick Edit and Bulk
// Edit. Quick Edit is wired by admin-inline.js (it pre-fills the Book field from
// the row's stored book); Bulk Edit applies one book to several chapters at once.

const { test, expect } = require( '@playwright/test' );
const {
	setupInlineEditFixture,
	teardownInlineEditFixture,
	chapterBook,
} = require( './helpers/fixtures' );

let fx;

test.beforeAll( () => {
	fx = setupInlineEditFixture();
} );

test.afterAll( () => {
	teardownInlineEditFixture( fx );
} );

// The chapter list, narrowed to one book so only the fixture chapters show.
function listForBook( id ) {
	return `/wp-admin/edit.php?post_type=sheaf_chapter&sheaf_book=${ id }`;
}

test( 'Quick Edit pre-fills the current book and reassigns it', async ( { page } ) => {
	const chapter = fx.chapters[ 0 ];
	await page.goto( listForBook( fx.book1 ) );

	const row = page.locator( `#post-${ chapter }` );
	await expect( row ).toBeVisible();
	await row.hover();
	await row.locator( 'button.editinline' ).click();

	// admin-inline.js copies the row's stored book into the Quick Edit select.
	const select = page.locator( `#edit-${ chapter } select[name="sheaf_book"]` );
	await expect( select ).toBeVisible();
	await expect( select ).toHaveValue( String( fx.book1 ) );

	// Reassign to the second book and save.
	await select.selectOption( String( fx.book2 ) );
	await page.locator( `#edit-${ chapter } button.save` ).click();

	// The row's Book column updates in place (AJAX), and the meta really changed.
	await expect( page.locator( `#post-${ chapter } .column-sheaf_book` ) ).toHaveText(
		new RegExp( fx.book2Title )
	);
	expect( chapterBook( chapter ) ).toBe( fx.book2 );
} );

test( 'Bulk Edit reassigns several chapters at once', async ( { page } ) => {
	const [ , c2, c3 ] = fx.chapters;
	await page.goto( listForBook( fx.book1 ) );

	await page.locator( `#cb-select-${ c2 }` ).check();
	await page.locator( `#cb-select-${ c3 }` ).check();

	await page.locator( '#bulk-action-selector-top' ).selectOption( 'edit' );
	await page.locator( '#doaction' ).click();

	const bulkSelect = page.locator( '#bulk-edit select[name="sheaf_book"]' );
	await expect( bulkSelect ).toBeVisible();
	await bulkSelect.selectOption( String( fx.book2 ) );

	// Update submits the form and reloads the list.
	await Promise.all( [
		page.waitForLoadState( 'load' ),
		page.locator( '#bulk_edit' ).click(),
	] );

	// Both chapters now belong to book two.
	expect( chapterBook( c2 ) ).toBe( fx.book2 );
	expect( chapterBook( c3 ) ).toBe( fx.book2 );

	// And they show under the book-two filter in the UI.
	await page.goto( listForBook( fx.book2 ) );
	await expect( page.locator( `#post-${ c2 }` ) ).toBeVisible();
	await expect( page.locator( `#post-${ c3 }` ) ).toBeVisible();
} );
