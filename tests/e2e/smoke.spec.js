// Read-only smoke tests: prove the harness can reach the plugin's admin screens
// while authenticated. These mutate no data, so they are safe to run against a
// live site (e.g. while someone is reviewing it).

const { test, expect } = require( '@playwright/test' );

test.describe( 'Sheaf admin smoke', () => {
	test( 'dashboard loads when authenticated', async ( { page } ) => {
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '#adminmenu' ) ).toBeVisible();
	} );

	test( 'chapter list screen loads', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=sheaf_chapter' );
		await expect( page.locator( 'h1' ).first() ).toHaveText( /Chapters/ );
	} );

	test( 'Style Sets admin screen loads', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=sheaf-style-sets' );
		await expect( page.locator( 'h1' ).first() ).toBeVisible();
	} );
} );
