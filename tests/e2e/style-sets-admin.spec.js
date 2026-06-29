// The Style Sets admin screen: creating a set and adding styles through the UI,
// bulk-assigning a set across books, and deleting a set. Uses E2E-prefixed names
// so cleanupE2E() leaves the author's real library untouched.

const { test, expect } = require( '@playwright/test' );
const { setupSelectorFixture, cleanupE2E, activeSets } = require( './helpers/fixtures' );
const { wpEvalJson } = require( './helpers/wp' );

const ADMIN = '/wp-admin/admin.php?page=sheaf-style-sets';

let book;

test.beforeAll( () => {
	book = setupSelectorFixture().book; // a real book (page + chapter) to assign to
} );

test.afterAll( () => {
	cleanupE2E();
} );

test( 'create a set and add inline + block styles through the UI', async ( { page } ) => {
	await page.goto( ADMIN );

	// Create via the in-table form (last row).
	await page.locator( '.sheaf-add-row input[name="label"]' ).fill( 'E2E Admin Set' );
	await page.locator( '.sheaf-add-row' ).getByRole( 'button', { name: 'Create new set' } ).click();

	const detail = page.locator( '#sheaf-set-detail' );
	await expect( detail.locator( 'h2' ) ).toContainText( 'E2E Admin Set' );

	// Add an inline style. Properties use progressive disclosure: pick one from
	// "Add property", then fill the row that appears. (Submit via Enter on the
	// name field: the live preview reflows under the submit button, making a
	// direct button click flaky.)
	await detail.locator( 'select[name="kind"]' ).selectOption( 'inline' );
	await detail.locator( '.sheaf-add-prop' ).selectOption( 'font-style' );
	await detail.locator( 'input[name="props[font-style]"]' ).fill( 'italic' );
	await detail.locator( 'input[name="label"]' ).fill( 'E2E Whisper' );
	await detail.locator( 'input[name="label"]' ).press( 'Enter' );
	await expect( page.locator( '#sheaf-set-detail' ).getByText( 'E2E Whisper' ) ).toBeVisible();

	// Add a block style.
	await page.locator( '#sheaf-set-detail select[name="kind"]' ).selectOption( 'block' );
	await page.locator( '#sheaf-set-detail .sheaf-add-prop' ).selectOption( 'text-align' );
	await page.locator( '#sheaf-set-detail input[name="props[text-align]"]' ).fill( 'center' );
	await page.locator( '#sheaf-set-detail input[name="label"]' ).fill( 'E2E Stanza' );
	await page.locator( '#sheaf-set-detail input[name="label"]' ).press( 'Enter' );
	await expect( page.locator( '#sheaf-set-detail' ).getByText( 'E2E Stanza' ) ).toBeVisible();
} );

test( 'bulk-assign a set to a book', async ( { page } ) => {
	const set = wpEvalJson( "$s = \\Sheaf\\Style_Sets::save_set( 'E2E Bulk Set' ); echo wp_json_encode( $s );" );

	await page.goto( ADMIN );
	await page.locator( `.sheaf-bulk-open[data-set="${ set }"]` ).click();

	const dialog = page.locator( `#sheaf-bulk-${ set }` );
	await expect( dialog ).toBeVisible();
	await dialog.locator( `input.sheaf-bulk-book[value="${ book }"]` ).check();
	await dialog.getByRole( 'button', { name: 'Save' } ).click();

	// Persisted (the page reloads on success).
	await expect.poll( () => activeSets( book ) ).toContain( set );
} );

test( 'delete a set from the list with confirmation', async ( { page } ) => {
	const set = wpEvalJson( "$s = \\Sheaf\\Style_Sets::save_set( 'E2E Delete Set' ); echo wp_json_encode( $s );" );

	await page.goto( ADMIN );
	page.on( 'dialog', ( d ) => d.accept() );

	const row = page.locator( 'tr' ).filter( { has: page.locator( `.sheaf-bulk-open[data-set="${ set }"]` ) } );
	await row.hover();
	await row.locator( '.sheaf-link-danger' ).click();

	await expect
		.poll( () => wpEvalJson( `echo wp_json_encode( (bool) \\Sheaf\\Style_Sets::get_set( '${ set }' ) );` ) )
		.toBe( false );
} );
