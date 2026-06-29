// Phase 2: the font-family field recognizes a typed family as installed,
// embeddable (catalog), or a plain/system font. Install-free — the embed
// round-trip itself is covered by the PHP test (test-fonts.php).

const { test, expect } = require( '@playwright/test' );
const { cleanupE2E } = require( './helpers/fixtures' );
const { wpEval } = require( './helpers/wp' );

test.beforeAll( () => {
	// A style set to edit, and a fake "installed" font for the embedded case.
	wpEval( `
		\\Sheaf\\Style_Sets::delete_set( 'e2e-fonts' );
		\\Sheaf\\Style_Sets::save_set( 'E2E Fonts' );
		$fam = wp_insert_post( [ 'post_type' => 'wp_font_family', 'post_status' => 'publish', 'post_title' => 'E2E Spike Serif', 'post_name' => 'e2e-spike-serif', 'post_content' => wp_json_encode( [ 'name' => 'E2E Spike Serif', 'slug' => 'e2e-spike-serif' ] ) ] );
		wp_insert_post( [ 'post_type' => 'wp_font_face', 'post_status' => 'publish', 'post_parent' => $fam, 'post_title' => 'E2E Spike Serif 400', 'post_content' => wp_json_encode( [ 'fontFamily' => 'E2E Spike Serif', 'fontWeight' => '400', 'fontStyle' => 'normal', 'src' => 'http://localhost:8888/wp-content/uploads/fonts/e2e-spike.woff2' ] ) ] );
		echo 'ok';
	` );
} );

test.afterAll( () => {
	wpEval( `
		foreach ( get_posts( [ 'post_type' => 'wp_font_family', 'name' => 'e2e-spike-serif', 'post_status' => 'any', 'numberposts' => -1 ] ) as $fam ) {
			foreach ( get_posts( [ 'post_type' => 'wp_font_face', 'post_parent' => $fam->ID, 'numberposts' => -1, 'post_status' => 'any' ] ) as $fc ) {
				wp_delete_post( $fc->ID, true );
			}
			wp_delete_post( $fam->ID, true );
		}
		echo 'ok';
	` );
	cleanupE2E();
} );

test( 'font-family recognizes installed, embeddable, and system fonts', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=sheaf-style-sets&set=e2e-fonts' );

	const detail = page.locator( '#sheaf-set-detail' );
	await detail.locator( '.sheaf-add-prop' ).selectOption( 'font-family' );
	const input = detail.locator( 'input[name="props[font-family]"]' );
	const status = detail.locator( '.sheaf-font-status' );

	// Installed (our fake family) → embedded indicator.
	await input.fill( 'E2E Spike Serif' );
	await expect( status ).toHaveText( /embedded/i );

	// In the catalog but not installed → an Embed button.
	await input.fill( 'EB Garamond' );
	const embed = detail.locator( '.sheaf-embed' );
	await expect( embed ).toBeVisible();
	await expect( embed ).toContainText( 'EB Garamond' );

	// Neither installed nor in the catalog → treated as a system font.
	await input.fill( 'ZZ Totally Made Up Face' );
	await expect( status ).toHaveText( /system font/i );
} );
