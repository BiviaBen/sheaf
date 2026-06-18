# sheaf

WordPress plugin development workspace. Targets **WordPress 7.0** / **PHP 8.3**.

Plugins live under [`plugins/`](plugins/); each subfolder is a self-contained
plugin that can be deployed by copying it into a production
`wp-content/plugins/` directory.

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
