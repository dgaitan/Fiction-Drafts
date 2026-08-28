# Fiction Drafts

Creates complete, downloadable copies of a WordPress site as resumable background jobs that never time out.

Fiction Drafts copies your site — database, files, or both — into one or more `.zip` volumes you can download and verify.

**It is an export-only tool.** It does not restore, migrate, or rewrite URLs, and it is not going to. That constraint is enforced by a test, not by intention.

| | |
|---|---|
| **Requires** | WordPress 6.4+, PHP 8.1+ |
| **Tested to** | WordPress 6.9 |
| **Version** | 0.1.0 |
| **License** | GPL-2.0-or-later |

---

## Install

### From a release zip

Download `fiction-drafts-<version>.zip` and install it the ordinary way: **Plugins → Add New → Upload Plugin → Activate**. The archive is self-contained — the Composer autoloader, the bundled Action Scheduler, and the compiled admin bundle are all inside it, so nothing needs to be built on the server.

Build one yourself with `bun run package`; see [Building a release zip](#building-a-release-zip).

### From source

This repository is the source, not a distributable build. `vendor/` and `build/` are deliberately not committed, so a plain clone dropped into `wp-content/plugins/` will activate and then do nothing — there is no autoloader and no admin bundle until you produce them.

```bash
git clone git@github.com:dgaitan/Fiction-Drafts.git fiction-drafts
cd fiction-drafts
composer install --no-dev   # Action Scheduler and the autoloader
bun install && bun run build   # the admin screen
```

Then drop the directory in `wp-content/plugins/` and activate. Activation generates the storage directory and its random suffix.

## Use

**Backups** appears under Tools. Pick a profile, start a backup, watch it step, download the volumes when it completes.

Five profiles: `full`, `database_only`, `files_only`, `files_no_media`, `custom`.

Everything the screen does is available over REST under `fiction-drafts/v1`:

A **job** is a run in progress; a **backup** is what a finished job left behind. They are separate resources because they have separate lifetimes — cancelling a job is not deleting a backup.

| Route | Purpose |
|---|---|
| `POST /jobs` | start a backup |
| `GET /jobs/{uuid}` | one job's status and progress |
| `DELETE /jobs/{uuid}` | cancel a running job |
| `GET /backups` | list completed backups and their volumes |
| `DELETE /backups/{uuid}` | delete a completed backup and its archives |
| `POST /backups/{uuid}/download-token` | mint a download link for one volume |
| `GET /settings` · `PUT /settings` | read and update settings |

## Security

A backup archive is the most sensitive file on your server. Even with `wp-config.php` left out, it contains every user's password hash, every session token, and any API key a plugin has stored in your options table. Treat a downloaded archive the way you would treat a database dump, because that is what it is.

### wp-config.php is excluded by default

Including it is a **per-job** choice you make each time, and it never carries over to the next backup. That file holds your database password and all eight authentication salts; with it, an archive is enough to connect to your database directly and to forge a login cookie for any account. The Backups screen shows which archives contain it.

### What actually protects your archives

Four things, and it is worth knowing which of them is doing the work on *your* server.

1. **The download endpoint is the only way an archive is served.** Every download passes four checks in order: logged in, capable, valid nonce, unspent download link.
2. **Download links are single-use and last five minutes.** A link is bound to your user, one backup, and one volume. Using it spends it. Links are stored as SHA-256 hashes — so a copy of your database, *including one this plugin made*, contains no usable download links. The five minutes bounds when a download may **start**; a transfer already under way is never interrupted, so a multi-hour download of a large archive is fine.
3. **The storage directory has 32 random characters in its name.** That is the only thing between a stranger and your archives if your server serves the directory directly.
4. **`.htaccess` — on Apache only.**

> **On nginx, `.htaccess` does nothing. This was measured, not assumed.**
> A file placed in `wp-content` with `Require all denied` beside it was requested over HTTP while logged out, and the server returned it with `200 OK`. On nginx, protections 1 and 3 are what you actually have.

For archives that are physically unreachable by URL, point the storage somewhere the web server does not serve:

```php
define( 'FICTION_DRAFTS_STORAGE_DIR', '/home/you/private/fiction-drafts' );
```

That is the strongest option available — a path the web server cannot serve cannot be requested. The download endpoint honours the constant, so downloads keep working.

### Multisite

Not supported in this release. `manage_options` is a per-site capability, so on a network install it belongs to every subsite administrator while the archives belong to one site. Rather than leave that open, Fiction Drafts requires `manage_network_options` whenever `is_multisite()` is true.

On a network where you activated the plugin for a single site, that will refuse a site administrator who ought to be allowed. Deliberate: a refused download is a better failure than another site's password hashes.

## Verifying and resuming a download

Every volume's SHA-256 is shown on the Backups screen, computed when the archive was sealed. **Check it after downloading.** This matters more here than for most files: a `.zip` keeps its index at the *end*, so an archive whose download was cut short is not a partial backup — it is a file that will not open at all.

Interrupted downloads resume rather than restart. The endpoint supports HTTP range requests, so `curl -C -`, `wget -c`, or any download manager picks up where it stopped.

## Background processing

Fiction Drafts bundles **its own copy of Action Scheduler**, so background work never depends on WooCommerce or any other plugin being installed.

Backups run one bounded step at a time. A step stops when its time budget runs out and records exactly where to resume, so a backup on a large site never depends on a single long request finishing. Every stage must complete at least one unit of work per step; a stage that returns without advancing its resume position fails its job with a message naming the stage, rather than being re-queued forever.

On a very low-traffic site the queue can idle. `wp action-scheduler run` forces it along.

## Uninstall

Deleting the plugin removes everything it created: its two `fdrafts_` tables, its `fiction_drafts_` options, the storage directory and every archive in it, and the `fiction-drafts` Action Scheduler group.

It deliberately does **not** drop the `actionscheduler_*` tables. Action Scheduler is a shared library — WooCommerce and anything else that bundles it use the same tables — and dropping them here would break every one of those plugins at the moment this one is removed.

**Single site only.** Uninstall cleans the site it runs on; on a network it does not loop over the others.

## Development

```bash
composer check      # phpcs, then phpstan, then phpunit
composer test       # phpunit alone
composer lint       # phpcs
composer analyse    # phpstan level 6
bun run start       # watch the admin screen
bun run lint:js
bun run package     # build dist/fiction-drafts-<version>.zip
```

Standards: PHP 8.1, `declare(strict_types=1)`, PSR-4 `FictionDrafts\` → `src/`, WordPress + WordPress-Extra coding standards, PHPStan level 6.

See [CLAUDE.md](CLAUDE.md) for the architecture and the invariants that must not be broken, and [ISA.md](ISA.md) for the criteria each release was verified against.

### Building a release zip

```bash
bun run package                    # or: composer package
bun run package -- --skip-build    # reuse the existing build/ output
bun run package -- --keep-staging  # leave dist/staging/ to inspect
bun run package -- --out ./release
```

Writes `dist/fiction-drafts-<version>.zip` — one top-level `fiction-drafts/` directory, which is the folder name WordPress will install it as.

Three things about how it works are deliberate:

- **It never writes to your working tree.** The release needs `composer install --no-dev`; the repository needs phpcs, phpstan and phpunit. Installing in place would silently disarm `composer check` for whoever cut the release. The plugin is copied to `dist/staging/` and Composer runs there instead.
- **What ships is an allow-list, not an ignore-list.** An ignore-list fails open, and this is a plugin whose archives contain the site's secrets — a stray `.sql` or `.env` in the plugin root must not be able to ride along by default.
- **The checks read the finished zip, not the staging directory.** Verifying the staging directory only proves the copy step agreed with itself. The packer re-opens the archive and compares it against the repository: every `src/` PHP file accounted for, the autoloader and Action Scheduler present, no tests or dev dependencies, no macOS metadata, the packaged header declaring the expected version. Any failure exits non-zero and says the archive is not fit to release.

The build also refuses to start if the plugin header, `FICTION_DRAFTS_VERSION` and `package.json` disagree about the version, so a release cannot report one version while branching on another.

The printed `sha256` identifies the artifact you are about to distribute. It is not reproducible across runs — zip records modification times — so publish the hash of the file you actually ship.

## Roadmap

v0.1.0 is manual backup and secure download. Deferred to v0.2.0: recurring schedules, WP-CLI, remote destinations, encryption at rest, and multisite.

Restore is not on the roadmap at any version.

## Changelog

### 0.1.0
Initial release: manual backup and secure download.
