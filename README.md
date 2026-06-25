# sheaf

WordPress plugin development workspace. Targets **WordPress 7.0** / **PHP 8.3**.

Plugins live under [`plugins/`](plugins/); each subfolder is a self-contained
plugin that can be deployed by copying it into a production
`wp-content/plugins/` directory.

## Deploying Sheaf to a WordPress site

Sheaf has **no build step** — no npm, no compiled assets. Blocks register from
their `block.json` + PHP render callbacks, and the editor scripts use the
`wp.*` globals already shipped by WordPress. Deployment is just copying the
plugin folder into place.

**Requirements on the target site:**

- WordPress **7.0** or newer
- PHP **8.3** or newer

### 1. Copy the plugin into `wp-content/plugins/`

Copy only the [`plugins/sheaf/`](plugins/sheaf/) directory (not this whole
repo) so the result is `wp-content/plugins/sheaf/sheaf.php`.

```bash
# from a clone of this repo, on the target server:
rsync -a plugins/sheaf/ /path/to/wordpress/wp-content/plugins/sheaf/
```

Or upload a zip of `plugins/sheaf/` through **Plugins → Add New → Upload
Plugin** in wp-admin. (Zip the `sheaf` folder itself, so the archive contains
`sheaf/sheaf.php`.)

### 2. Activate it

Via wp-admin: **Plugins → Sheaf → Activate**. Or with WP-CLI:

```bash
wp plugin activate sheaf
```

Activation sets the site to pretty permalinks (`/%postname%/`) **if no
permalink structure is set yet**, then flushes the rewrite rules so the nested
chapter URLs resolve immediately. Nested chapter URLs require pretty
permalinks — if the site already uses a custom structure, just make sure it is
not "Plain", then visit **Settings → Permalinks** once and save to flush.

### 3. (Optional) Seed sample content

For a demo / smoke test, the idempotent seeder creates a few fictional books,
series Pages, and chapters:

```bash
wp eval-file wp-content/plugins/sheaf/tools/seed.php
```

It is safe to re-run; it updates the same fixtures rather than duplicating
them. Skip this on a real site.

### Using it

There is no database-driven page template. The author builds **books and
series as ordinary Pages**, then writes each chapter as a *Chapter* post
(**Sheafs → New Chapter**) assigned to its book. Reading order is the page
"Order" field (`menu_order`). Add a table of contents with the **Sheaf TOC**
block or `[sheaf_toc]`; breadcrumbs are added to chapter views automatically.

## Local development

A full WordPress 7.0 instance runs in Docker via
[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env), configured in
[`.wp-env.json`](.wp-env.json).

```bash
wp-env start      # boot WordPress 7.0 at http://localhost:8888
wp-env stop       # shut it down
wp-env clean all  # wipe the database back to a fresh install
```

- **Site:** http://localhost:8888  (admin: `admin` / `password`)
- **Database:** managed by wp-env (not committed)

### Viewing from your machine

wp-env binds to `localhost:8888` on the server. Forward it over SSH:

```bash
ssh -L 8888:localhost:8888 <user>@<server>
```

then open http://localhost:8888 locally.

## Requirements (on the server)

- Docker (running)
- Node.js + `@wordpress/env` (`npm install -g @wordpress/env`)
