# Sheaf E2E test harness

Browser-level tests that drive the real WordPress admin (Gutenberg included)
against the running `wp-env` site. They cover what the PHP unit tests can't —
the in-editor UI: the style-set toolbar formats, the paragraph block-style
panel, the book-change warning, and the import preview screen.

**Dev-only.** None of this ships in the plugin zip — the release build copies
only `plugins/sheaf/` and excludes `node_modules`.

## Prerequisites

1. The site must be up: `wpenv start` (serves http://localhost:8888).
2. Install once:

   ```bash
   npm install
   npx playwright install --with-deps chromium
   ```

   `--with-deps` installs the system libraries headless Chromium needs (apt;
   requires root). On this machine those deps are already installed.

## Running

```bash
npm run test:e2e          # headless
npm run test:e2e:headed   # watch it drive a browser
npm run test:e2e:ui       # Playwright's interactive UI
npm run test:e2e:report   # open the last HTML report
```

## Configuration

Override via env vars (defaults match wp-env):

| Var                | Default                  |
| ------------------ | ------------------------ |
| `SHEAF_BASE_URL`   | `http://localhost:8888`  |
| `SHEAF_ADMIN_USER` | `admin`                  |
| `SHEAF_ADMIN_PASS` | `password`               |

## How it works

`global-setup.js` logs in once and saves the session to
`tests/e2e/.auth/admin.json` (gitignored); every test starts authenticated via
`storageState`.

## Conventions

- **Keep specs self-contained.** A spec that needs fixtures (a book with active
  style sets, a chapter) must create them and clean them up, so the suite leaves
  the site as it found it — important while the site doubles as a review target.
- `smoke.spec.js` is read-only and mutates nothing; use it as the baseline.
