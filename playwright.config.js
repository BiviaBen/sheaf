// Playwright configuration for the Sheaf E2E harness.
//
// Drives a real headless Chromium against the running wp-env site (the same
// site `wpenv start` serves on :8888), so it exercises the actual Gutenberg
// editor UI that the PHP unit tests cannot reach. Dev-only: not part of the
// shipped plugin.
//
// Override the target / credentials with env vars when needed:
//   SHEAF_BASE_URL   (default http://localhost:8888)
//   SHEAF_ADMIN_USER (default admin)        -- wp-env's default admin
//   SHEAF_ADMIN_PASS (default password)

const { defineConfig, devices } = require( '@playwright/test' );

const BASE_URL = process.env.SHEAF_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig( {
	testDir: './tests/e2e',

	// Log in once, reuse the session for every test (see global-setup.js).
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),

	timeout: 30_000,
	expect: { timeout: 10_000 },

	// CI is stricter: no accidental test.only, retry flaky network once.
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never' } ] ] : [ [ 'list' ] ],

	use: {
		baseURL: BASE_URL,
		storageState: 'tests/e2e/.auth/admin.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
} );
