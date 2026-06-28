// Authenticate once before the suite runs, and stash the session so every test
// starts logged in as the wp-env admin. Saving storageState (rather than
// logging in per test) keeps the suite fast and avoids hammering wp-login.php.

const { chromium } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

module.exports = async () => {
	const baseURL = process.env.SHEAF_BASE_URL || 'http://localhost:8888';
	const user = process.env.SHEAF_ADMIN_USER || 'admin';
	const pass = process.env.SHEAF_ADMIN_PASS || 'password';

	const authFile = path.join( __dirname, '.auth', 'admin.json' );
	fs.mkdirSync( path.dirname( authFile ), { recursive: true } );

	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.goto( `${ baseURL }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', pass );
		await Promise.all( [
			page.waitForLoadState( 'networkidle' ),
			page.click( '#wp-submit' ),
		] );

		if ( ! /\/wp-admin\/?/.test( page.url() ) ) {
			throw new Error(
				`Login failed — landed on ${ page.url() }. Is wp-env running and are the ` +
				`SHEAF_ADMIN_USER / SHEAF_ADMIN_PASS correct?`
			);
		}

		await page.context().storageState( { path: authFile } );
	} finally {
		await browser.close();
	}
};
