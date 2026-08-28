# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

**Fiction Drafts** is a WordPress plugin that copies a site — database, files, or both — into one or more downloadable `.zip` volumes. The work runs as resumable, time-boxed background jobs, so a backup on a large site never depends on a single long request finishing.

It is an **export** tool. Its whole job is to produce an archive and hand it to an administrator safely.

## Invariants

These are decisions, not preferences. Breaking one is a defect even if every test passes.

### 1. Export only. There is no restore, and there will not be

No import, no unzip-into-place, no URL rewriting, no `DROP TABLE`, no `UPDATE` against site content. This is enforced by `tests/Unit/ExportOnlyBoundaryTest.php`, which sweeps `src/` for the constructs a restore path needs and fails on them.

That test carries a small allow-list (`OptionGrantStore`, `Migrator`, `SettingsRepository`, `StorageLocator`). **If it fails on a file you added, the failure is the review.** Adding an entry requires a written reason in the test itself, not a silent append.

### 2. Action Scheduler is bundled, and never WooCommerce's

It is a composer dependency (`woocommerce/action-scheduler`) loaded from this plugin's own `vendor/`. Nothing may branch on WooCommerce being installed, and nothing may assume another plugin loaded the library first.

`uninstall.php` must never drop `actionscheduler_*` tables — they are shared with every other plugin that bundles the library.

### 3. `wp-config.php` is excluded by default, opt-in per job

The opt-in is `include_wp_config` inside the job's `options` JSON. It is **not** a property of `BackupProfile`, and `BackupProfile` must not grow an `includesWpConfig()` method. A profile is a reusable shape; including the file holding the database password and all eight salts is a decision made per backup, deliberately, and never inherited.

### 4. The storage directory's name is a secret

`wp-content/fiction-drafts-{32 hex}/`. The suffix is generated once at activation and kept in the non-autoloaded option `fiction_drafts_storage_slug`. Never hardcode it, never derive it from anything guessable, never rename the directory — every path is derived from the stored value, so a rename orphans the archives.

`FICTION_DRAFTS_STORAGE_DIR` overrides the location entirely; anything that resolves a path must go through `StorageLocator`, and anything that accepts one must go through `PathGuard`.

### 5. A grant is not a token, and the store is not the credential

`OptionGrantStore` holds `hash('sha256', $token)` and never the token. The reason is specific to this plugin: **its job is to put `wp_options` into a downloadable archive.** A plaintext grant would be copied, still valid, into the very backup it authorises.

## Architecture

```
fiction-drafts.php        bootstrap; defines constants, starts Plugin
src/
  Container/              tiny PSR-11 container + ServiceProvider contract
  Contracts/              interfaces the layers agree on
  Domain/                 BackupJob, BackupProfile, ArchiveVolume, JobStatus — no I/O
  Persistence/            job/volume stores, MySqlJobLock, settings
  Database/               schema, Migrator, dump writer
  Files/                  filesystem scanning
  Archive/                ZipWriter / PclZipWriter, VolumeNaming
  Backup/                 Scheduler, StageRunner, StageRegistry, watchdog, retention
    Stages/               Prepare → Database → FileScan → Archive → Finalize
  Download/               grants, ByteRange, PathGuard, DownloadHandler, ResponseEmitter
  Rest/                   fiction-drafts/v1 controllers
  Admin/                  the Tools → Backups screen
assets/app/               React source (built by wp-scripts into build/)
tests/                    Unit tests, Support doubles, stubs
ISA.md                    the system of record: criteria, decisions, changelog
```

**Wiring lives in service providers**, one per namespace, registered in `src/Plugin.php`. A new class is constructed in a provider, not with `new` at a call site.

**Stages are re-entrant and time-boxed.** Each `run()` does bounded work and records a resume position. A stage that returns without advancing fails the job by name rather than being re-queued forever. `StageRunner` re-reads the job after `run()` and compare-and-swaps on status, so a cancel is honoured at a stage boundary.

## Commands

```bash
composer check       # phpcs → phpstan → phpunit. This is the gate.
composer test
composer lint        # phpcs; lint-fix runs phpcbf
composer analyse     # phpstan level 6
bun run build        # assets/app → build/
bun run start
bun run lint:js
bun run package      # dist/fiction-drafts-<version>.zip, installable via Plugins → Add New
```

**bun, never npm or npx.**

`tools/package.ts` builds the release archive. It stages a copy under `dist/staging/` and runs `composer install --no-dev` **there** — never in the working tree, which would silently disarm `composer check` for whoever cut the release. What ships is an allow-list (`SHIPPED`), not an ignore-list, because an ignore-list fails open and this plugin's archives contain the site's secrets. The verification pass re-opens the finished zip and compares it against the repository rather than against the staging directory it just wrote; a check that reads only what the packer produced cannot see what the packer dropped.

Adding a production Composer dependency needs no change here — the vendor checks are derived from `composer.json`. Adding a new top-level file or directory that must ship does: add it to `SHIPPED`, or the archive's top-level assertion fails.

`phpcs.xml.dist`, `phpstan.neon.dist` and `phpunit.xml.dist` are the committed contract. Loosening one to make a change pass is not a fix — the local un-suffixed variants are gitignored precisely so a per-machine override cannot become everyone's gate.

## Conventions

The full pattern guide — with the failure each rule prevents and a pre-commit
checklist — is the `fiction-drafts-patterns` skill in `.agents/skills/`. Load it before
writing or reviewing code here. What follows is the short form.

- PHP 8.1, `declare( strict_types=1 )` in every file, PSR-4 `FictionDrafts\` → `src/`
- WordPress + WordPress-Extra coding standards; a `phpcs:ignore` needs a reason on the same line
- Constructor property promotion, `readonly` where the value does not change
- Domain objects are immutable; `with()` returns a copy
- Comments explain *why*, and are worth their length only when the reasoning is not recoverable from the code

## Testing

Roughly 620 unit tests, no WordPress bootstrap — `tests/stubs/` provides the core functions.

Three habits this codebase learned the hard way, each from a real defect:

- **A test double must not be kinder than the real thing.** `do_action` originally dispatched with no arguments where core substitutes an empty string; the download handler declared `?array` and passed every test, then threw a `TypeError` on the first real click. When a stub stands in for core, mirror core's actual behaviour, including its quirks.
- **Testing a callback and testing its registration still leaves the composition untested.** For anything reached through a hook, route, or scheduler, write one test that fires the real dispatcher through the real registration.
- **A probe needs a control.** A check that also passes when the feature is absent is not a check. Revert the fix and watch the new test fail before believing it.

`ISA.md` records the criteria each release was verified against, the decisions behind them, and a changelog of conjectures that were refuted. **Read the relevant section before changing behaviour it covers**, and append to it when you change what "done" means.

## Scope

v0.1.0 is manual backup and secure download. Recurring schedules, WP-CLI, remote destinations, encryption at rest, and multisite are v0.2.0.

Restore is not on the roadmap at any version.
