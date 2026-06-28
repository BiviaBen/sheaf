// Drag/drop chapter reordering on a book's screen: the jquery-ui sortable plus
// its AJAX save (wp_ajax_sheaf_reorder). jquery-ui listens to real mouse events,
// so we drive mousedown/move/up rather than Playwright's HTML5 dragTo.

const { test, expect } = require( '@playwright/test' );
const { setupReorderFixture, teardownReorderFixture } = require( './helpers/fixtures' );

let fx;

test.beforeEach( () => {
	fx = setupReorderFixture();
} );

test.afterEach( () => {
	teardownReorderFixture( fx );
	fx = null;
} );

function bookScreen( id ) {
	return `/wp-admin/admin.php?page=sheaf-books&book=${ id }`;
}

async function rowOrder( page ) {
	return page
		.locator( '#sheaf-reorder tr[data-id]' )
		.evaluateAll( ( rows ) => rows.map( ( r ) => Number( r.getAttribute( 'data-id' ) ) ) );
}

async function dragRowAfter( page, dragId, afterId ) {
	const handle = page.locator( `#sheaf-reorder tr[data-id="${ dragId }"] .sheaf-reorder__handle` );
	const target = page.locator( `#sheaf-reorder tr[data-id="${ afterId }"]` );
	await handle.scrollIntoViewIfNeeded();
	const h = await handle.boundingBox();
	const t = await target.boundingBox();

	await page.mouse.move( h.x + h.width / 2, h.y + h.height / 2 );
	await page.mouse.down();
	// A small initial move starts the sortable; then travel past the target row's
	// bottom edge so the dragged row drops after it.
	await page.mouse.move( h.x + h.width / 2, h.y + h.height / 2 + 8, { steps: 5 } );
	await page.mouse.move( t.x + t.width / 2, t.y + t.height + 6, { steps: 14 } );
	await page.mouse.move( t.x + t.width / 2, t.y + t.height + 10, { steps: 4 } );
	await page.mouse.up();
}

test( 'dragging a chapter to the end reorders and persists', async ( { page } ) => {
	await page.goto( bookScreen( fx.book ) );

	const [ alpha, bravo, charlie ] = fx.chapters;
	await expect( page.locator( '#sheaf-reorder tr[data-id]' ) ).toHaveCount( 3 );
	expect( await rowOrder( page ) ).toEqual( [ alpha, bravo, charlie ] );

	await dragRowAfter( page, alpha, charlie );

	// The AJAX save reports success in the status line.
	await expect( page.locator( '#sheaf-reorder-status' ) ).toHaveText( /Order saved/i );

	// DOM reflects the new order immediately, and it survives a reload.
	expect( await rowOrder( page ) ).toEqual( [ bravo, charlie, alpha ] );

	await page.reload();
	expect( await rowOrder( page ) ).toEqual( [ bravo, charlie, alpha ] );
} );
