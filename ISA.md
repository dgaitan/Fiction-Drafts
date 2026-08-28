---
task: "Fiction Drafts — export-only WordPress backup plugin"
project: fiction-drafts
effort: E3
effort_source: context-override
phase: complete
progress: 615/615
mode: interactive
started: 2026-08-28T16:05:00Z
updated: 2026-08-28T23:55:00Z
---

# ISA — Fiction Drafts

> Project ISA. System of record for the plugin. Sprints operate against it.
> Spec: `docs/fiction-drafts/about-the-plugin.md` · Plan: `docs/fiction-drafts/development-plan.md`
> **Active task: the release packager — a verified, installable distribution zip.**

## Problem

Sprints 0–5 built a complete engine and left it with no face. Every capability the plugin has —
five profiles, a five-stage resumable pipeline, a manifest, per-volume checksums, a disk-space
refusal, a retention sweep — is reachable only from a PHP shell. An administrator cannot start a
backup, cannot see one running, cannot tell two finished archives apart, and cannot free the disk
without SSH. Worse, three of those capabilities are *rules* that a UI will have to restate:
which profiles exist, what the stages are called, and that a retention count of `0` means keep
everything. The moment the client writes its own copy of any of them, there are two answers to one
question, and the one users see is the one that was not tested.

## Vision

An administrator opens one page, picks Everything, and watches a bar that names what it is doing —
"Checking there is room", then "Building the archive" — and never drops back to zero. When it
finishes, the list shows a date, a size, a volume count, and whether that archive contains the
database password. An engineer reading the client looks for the list of profiles and cannot find
one, looks for the stage names and cannot find those either, and realises the server hands both
down at boot. The UI has no opinions. It has a rendering of someone else's.

## Out of Scope

Sprint 6 is the admin page and the REST routes behind it. It does not build the download — the
single-use token, the `admin-post.php` streaming handler, `Range` support, and `PathGuard` are
Sprint 7's, and the list therefore reports whether a backup's volumes are present without offering
a way to fetch them. It does not add a scheduling UI; recurring backups are v0.2.0. It does not
touch the pipeline, the manifest, or the sweep — every number the screen shows already exists, and
a sprint that had to change the engine to display it would be describing a payload problem, not a
UI one. It adds no server-rendered form: there is exactly one PHP-emitted element on the page.

## Principles

- **A default that fails safe beats a default that fails convenient.** Unspecified means excluded.
- **One question, one answer, one place.** Two components that can disagree eventually will.
- **Prefer discovering an abstraction over choosing one.** Collapse only what the data already collapses.
- **State the honest consequence.** A protection that only works on some hosts is documented as such.

## Constraints

- PHP 8.1+, `declare(strict_types=1)`, PSR-4 under `FictionDrafts\`.
- WordPress 6.4 is the declared floor; option autoload must be correct on 6.4 *and* on 7.1.
- `wp-config.php` is a per-job option, never a profile property. `BackupProfile` must not grow an
  `includesWpConfig()` method — spec §6.3.
- All plugin options are `autoload = false`; the plugin must cost a front-end page load nothing.
- No new PHPCS exclusion without a comment explaining why (plan, Definition of Done).
- `composer check` (PHPCS, then PHPStan level 6, then PHPUnit) exits 0.

## Goal

One `manage_options`-gated admin page runs the whole engine from the browser: pick a profile,
opt `wp-config.php` in for this job only, watch a five-stage bar that never goes backwards, read a
failure in the words the engine wrote, list finished backups with date, profile, size, volume
count and checksums, delete one, and edit exclusions, volume size, and retention — served by
`BackupsController` and `SettingsController` on the existing capability gate, with every rule the
client renders handed down from PHP rather than restated in JSX, `composer check` and
`bun run lint:js` both green.

## Criteria

### BackupProfile — area predicates (spec §6.1 table, row by row)

- [x] ISC-1: `BackupProfile::Full->includesDatabase()` is `true`
- [x] ISC-2: `BackupProfile::Full->includesCore()` is `true`
- [x] ISC-3: `BackupProfile::Full->includesUploads()` is `true`
- [x] ISC-4: `BackupProfile::DatabaseOnly->includesDatabase()` is `true`
- [x] ISC-5: `BackupProfile::DatabaseOnly->includesCore()` is `false`
- [x] ISC-6: `BackupProfile::DatabaseOnly->includesUploads()` is `false`
- [x] ISC-7: `BackupProfile::FilesOnly->includesDatabase()` is `false`
- [x] ISC-8: `BackupProfile::FilesOnly->includesCore()` is `true`
- [x] ISC-9: `BackupProfile::FilesOnly->includesUploads()` is `true`
- [x] ISC-10: `BackupProfile::FilesNoMedia->includesDatabase()` is `true`
- [x] ISC-11: `BackupProfile::FilesNoMedia->includesCore()` is `true`
- [x] ISC-12: `BackupProfile::FilesNoMedia->includesUploads()` is `false`
- [x] ISC-13: `BackupProfile::Custom` returns `false` from all three predicates (default-deny)

### BackupProfile — defaultExclusions() (spec §6.2)

- [x] ISC-14: `defaultExclusions()` returns an `ExclusionSet` for every one of the five cases
- [x] ISC-15: every profile's set matches `wp-config.php`
- [x] ISC-16: `defaultExclusions()->without( 'wp-config.php' )` no longer matches `wp-config.php`
- [x] ISC-17: matches `wp-content/cache/object/x.php`
- [x] ISC-18: matches `wp-content/upgrade/tmp/x.php`
- [x] ISC-19: matches root-level `node_modules/lib/a.js`
- [x] ISC-20: matches nested `wp-content/themes/houzez/node_modules/a.js`
- [x] ISC-21: matches root-level `.git/config`
- [x] ISC-22: matches nested `wp-content/plugins/x/.git/config`
- [x] ISC-23: matches root-level `debug.log`
- [x] ISC-24: matches nested `wp-content/debug.log`
- [x] ISC-25: matches root-level `.DS_Store` and nested `wp-content/.DS_Store`
- [x] ISC-26: matches `wp-content/uploads/backwpup-a1b2/x.zip`
- [x] ISC-27: matches `wp-content/ai1wm-backups/x.wpress`
- [x] ISC-28: `FilesNoMedia` set matches `wp-content/uploads/2024/01/x.jpg`
- [x] ISC-29: Anti: `Full` set does NOT match `wp-content/uploads/2024/01/x.jpg`
- [x] ISC-30: Anti: `FilesNoMedia` set does NOT match `wp-content/uploads-custom/x.jpg` (no prefix bleed)
- [x] ISC-31: Anti: no profile's set matches `wp-content/themes/houzez/style.css`

### Settings value object

- [x] ISC-32: `Settings::defaults()->maxVolumeBytes()` is `1610612736` (1.5 GiB)
- [x] ISC-33: `Settings::defaults()->retentionCount()` is `5`
- [x] ISC-34: `Settings::defaults()->defaultProfile()` is `BackupProfile::Full`
- [x] ISC-35: `Settings::defaults()->exclusions()->isEmpty()` is `true`
- [x] ISC-36: `Settings::fromArray( $s->toArray() )` equals `$s` for a non-default instance
- [x] ISC-37: `Settings::fromArray( [] )` equals `Settings::defaults()` and raises no PHP notice
- [x] ISC-38: `Settings::fromArray( [ 'default_profile' => 'nonsense' ] )` falls back to `Full`
- [x] ISC-39: `Settings::fromArray` clamps `max_volume_bytes` below the floor up to the floor
- [x] ISC-40: `Settings::fromArray` clamps a negative `retention_count` to `0`

### SettingsRepository

- [x] ISC-41: `SettingsRepository::OPTION_NAME` is `'fiction_drafts_settings'`
- [x] ISC-42: `get()` returns `Settings::defaults()` when the option is absent
- [x] ISC-43: `save()` calls `add_option()` with autoload `false` when the option is absent
- [x] ISC-44: `save()` calls `update_option()` with autoload `false` when the option exists
- [x] ISC-45: `get()` after `save()` returns a `Settings` equal to the one saved
- [x] ISC-46: `get()` reads the option once per request (second call issues no further read)
- [x] ISC-47: Anti: no source file in `src/` references `wpdb` — **[SUPERSEDED at Sprint 2]** held true for the whole of Sprint 1, which was its purpose; `JobRepository` is the one file that may now, and ISC-149 replaces this criterion
- [x] ISC-48: live `SELECT autoload FROM wp_options` for `fiction_drafts_settings` returns `off`

### Gates and regression

- [x] ISC-49: Anti: `BackupProfile` still has no `includesWpConfig()` method
- [x] ISC-50: Anti: `phpcs.xml.dist` gains no new exclusion
- [x] ISC-51: `composer lint` exits 0
- [x] ISC-52: `composer analyse` exits 0
- [x] ISC-53: `composer test` exits 0 with more tests than Sprint 0's 51
- [x] ISC-54: `php -l` passes on every new file
- [x] ISC-55: `development-plan.md` marks Sprint 1 delivered

### Export-only boundary (added at VERIFY, from the advisor review)

- [x] ISC-25.1: every profile's set matches root-level `._foo` and nested `wp-content/._foo`
- [x] ISC-56: Anti: no file in `src/` declares a `restore*`, `import*`, `unzip*` or `extract*` method
- [x] ISC-57: Anti: `SettingsRepository` is the only file in `src/` calling `add_option`/`update_option`
- [x] ISC-58: Anti: no file in `src/` calls `wp_insert_post`, `wp_update_post`, `update_post_meta`, or `wp_insert_attachment`
- [x] ISC-59: the source scan finds a non-empty file list, so the three anti-criteria above cannot pass vacuously
- [x] ISC-60: **Sprint 2 (discharged)** — `JobManager` refuses to create a job whose profile and options select no content, returning a stated reason rather than an empty archive

### Sprint 2 — schema and storage

- [x] ISC-61: `Migrator::run()` creates `{prefix}fdrafts_jobs` — table present in `SHOW TABLES`
- [x] ISC-62: `Migrator::run()` creates `{prefix}fdrafts_volumes`
- [x] ISC-63: `fdrafts_jobs` has a UNIQUE index on `uuid`
- [x] ISC-64: `fdrafts_jobs` has an index on `status` and one on `created_at`
- [x] ISC-65: `fdrafts_volumes` has an index on `job_id`
- [x] ISC-66: every column in spec §8's two tables exists with the stated type
- [x] ISC-67: re-running `Migrator::run()` is a no-op — table checksums unchanged
- [x] ISC-68: `Migrator::run()` records `fiction_drafts_db_version` with `autoload = false`
- [x] ISC-69: `StorageLocator::ensure()` creates the base directory
- [x] ISC-70: the directory name carries 32 hex characters from a non-autoloaded option
- [x] ISC-71: the directory contains `index.php`, `.htaccess`, and `web.config`
- [x] ISC-72: defining `FICTION_DRAFTS_STORAGE_DIR` relocates the base directory
- [x] ISC-73: `StorageLocator::workingDir( $uuid )` sits inside the base directory

### Sprint 2 — the resumable engine (the sprint's centre)

- [x] ISC-74: **1,000 units, budget 0s — the run completes**
- [x] ISC-75: it takes 10 or more steps
- [x] ISC-76: the output contains exactly 1,000 lines
- [x] ISC-77: every unit 0..999 appears exactly once
- [x] ISC-78: the units appear in ascending order
- [x] ISC-79: the same stage with a 20-second budget completes in exactly one step
- [x] ISC-80: that single-step output is byte-identical to the 1,000-step output
- [x] ISC-81: Anti: no unit is duplicated across a resume boundary
- [x] ISC-82: Anti: no unit is skipped across a resume boundary
- [x] ISC-83: a stage returning `incomplete` with 0 processed and an unchanged cursor fails the job
- [x] ISC-84: that failure's message names the offending stage
- [x] ISC-85: Anti: a livelocking stage never causes an unbounded re-enqueue loop
- [x] ISC-86: the cursor is persisted only after the work it describes is done
- [x] ISC-87: `StageRunner` advances to the next stage when one completes
- [x] ISC-88: the cursor resets to `start()` on stage advance
- [x] ISC-89: `processed` resets on stage advance
- [x] ISC-90: the job is marked `completed` when the last stage completes
- [x] ISC-91: `completed_at` is set exactly when status becomes `completed`
- [x] ISC-92: a stage whose `appliesTo()` is false is skipped entirely
- [x] ISC-93: `DATABASE_ONLY` skips a stage that applies only to file profiles
- [x] ISC-94: stages are resolved through the `fiction_drafts/stages` filter, not a hard-coded array
- [x] ISC-95: a stage added by that filter runs in the pipeline
- [x] ISC-96: `StageRunner::step()` on an unknown uuid returns without error
- [x] ISC-97: `StageRunner::step()` on a terminal job does nothing
- [x] ISC-98: a stage that throws marks the job `failed` with the exception message
- [x] ISC-99: Anti: a throwing stage does not leave the job `running`

### Sprint 2 — job lifecycle and scheduling

- [x] ISC-100: `JobManager::create()` returns a job with a v4 uuid
- [x] ISC-101: a new job's status is `queued`
- [x] ISC-102: creating a job while another is `running` is refused
- [x] ISC-103: creating a job while another is `queued` is refused
- [x] ISC-104: the refusal carries a `409`-mapping reason
- [x] ISC-105: Anti: the refused attempt schedules nothing
- [x] ISC-106: `JobManager::create()` refuses a job selecting no content (ISC-60 discharged)
- [x] ISC-107: that refusal carries a `422`-mapping reason
- [x] ISC-108: a `CUSTOM` job with `include_database: true` is accepted
- [x] ISC-109: `BackupJob::selectsAnyContent()` is true for all four preset profiles
- [x] ISC-110: `BackupJob::selectsAnyContent()` is false for bare `CUSTOM`
- [x] ISC-111: `Scheduler::GROUP` is `fiction-drafts`
- [x] ISC-112: `Scheduler::HOOK_RUN_STEP` is `fiction_drafts/run_step`
- [x] ISC-113: creating a job enqueues `fiction_drafts/run_step` with the uuid as its only argument
- [x] ISC-114: that action lands in group `fiction-drafts`, verified by `as_get_scheduled_actions()`
- [x] ISC-115: `Scheduler` degrades safely when the `as_*` functions are absent
- [x] ISC-116: `JobManager::cancel()` sets status `cancelled`
- [x] ISC-117: cancelling unschedules that job's pending actions
- [x] ISC-118: `StaleJobWatchdog` fails a `running` job whose `updated_at` is 16 minutes old
- [x] ISC-119: Anti: the watchdog leaves a job updated 5 minutes ago alone
- [x] ISC-120: Anti: the watchdog never touches a terminal job
- [x] ISC-121: `FailureHandler` on `action_scheduler_failed_execution` marks the job `failed`
- [x] ISC-122: the stored `error` is the exception message
- [x] ISC-123: Anti: `FailureHandler` ignores actions whose hook is not ours

### Sprint 2 — REST surface

- [x] ISC-124: the namespace is `fiction-drafts/v1`
- [x] ISC-125: `POST /jobs` is registered
- [x] ISC-126: `GET /jobs/(?P<uuid>...)` is registered
- [x] ISC-127: `DELETE /jobs/(?P<uuid>...)` is registered
- [x] ISC-128: `POST /jobs` returns `202` with a uuid
- [x] ISC-129: `POST /jobs` returns `409` when a job is already active
- [x] ISC-130: `POST /jobs` returns `422` when nothing is selected
- [x] ISC-131: `GET /jobs/{uuid}` returns `status`, `stage`, `stage_label`, `processed`, `total`, `percent`, `error`
- [x] ISC-132: `GET /jobs/{uuid}` on an unknown uuid returns `404`
- [x] ISC-133: every route declares a permission callback
- [x] ISC-134: Anti: a user without `manage_options` gets `403` from every route
- [x] ISC-135: Anti: no REST response contains a filesystem path

### Sprint 2 — uninstall

- [x] ISC-136: `uninstall.php` drops `{prefix}fdrafts_jobs`
- [x] ISC-137: `uninstall.php` drops `{prefix}fdrafts_volumes`
- [x] ISC-138: it deletes every `fiction_drafts_*` option
- [x] ISC-139: it removes the storage directory recursively
- [x] ISC-140: it calls `as_unschedule_all_actions( '', [], 'fiction-drafts' )`
- [x] ISC-141: **Anti: `actionscheduler_*` tables still exist after uninstall**
- [x] ISC-142: Anti: `uninstall.php` contains no `DROP` of any table not prefixed `fdrafts_`
- [x] ISC-143: Anti: uninstall deletes no option outside the `fiction_drafts_` prefix

### Sprint 2 — gates

- [x] ISC-144: `composer check` exits 0
- [x] ISC-145: `php -l` passes on every new file
- [x] ISC-146: the Action Scheduler standalone harness still passes
- [x] ISC-147: Anti: no new PHPCS exclusion beyond one documented addition for the test double
- [x] ISC-148: ISC-48 (Sprint 1's deferred autoload probe) runs live and passes at all three points
- [x] ISC-149: replaces the superseded ISC-47 — `JobRepository` and `Migrator` are the only files in `src/` that reference `wpdb`, and all 6 of their read call sites go through `$wpdb->prepare()`
- [x] ISC-82.1: a stage with nothing to do completes rather than being failed by the guard
- [x] ISC-83.1: a stage reporting progress with an unchanged cursor also fails the job
- [x] ISC-151: `MySqlJobLock::acquire()` is refused on a second connection while held, and granted after release
- [x] ISC-152: `FailureHandler` registers a shutdown handler that attributes E_ERROR to the job being stepped
- [x] ISC-153: Anti: `uninstall.php` is single-site only — documented rather than silently partial
- [x] ISC-150: Sprint 0's deferred criterion — with WooCommerce active, `ActionScheduler_Versions::latest_version()` resolves and both plugins function

### Sprint 3 — table enumeration

- [x] ISC-154: `TableEnumerator::forSite()` returns every table whose name begins with `$wpdb->prefix`
- [x] ISC-155: it builds its pattern with `$wpdb->esc_like()` and runs a prepared `SHOW TABLES LIKE`
- [x] ISC-156: a table that merely *contains* the prefix but does not begin with it is not returned
- [x] ISC-157: `forSite( $blogId )` on multisite returns that site's prefix set and no other site's
- [x] ISC-158: the returned order is deterministic, so two runs of the same job agree on table order
- [x] ISC-159: names added to the `fiction_drafts/excluded_tables` filter are removed from the result
- [x] ISC-160: Anti: no table belonging to a different prefix in the same database is ever returned
- [x] ISC-161: the resolved list is written to `tables.json` in the working dir and re-read on resume, so a table created mid-job cannot shift the cursor

### Sprint 3 — the allow-list boundary

- [x] ISC-162: `SqlDumper` refuses a table name that is not in the list it was constructed with
- [x] ISC-163: the refusal is an exception the runner turns into a failed job, not a silent skip
- [x] ISC-164: Anti: no identifier reaches a SQL string in `src/Database/` without passing the allow-list first — verified by reading every interpolation site

### Sprint 3 — dump structure (spec §6.4)

- [x] ISC-165: `database.sql` opens with a comment header carrying generated-at, site URL, prefix, WP and PHP versions
- [x] ISC-166: the header contains `SET NAMES utf8mb4;`
- [x] ISC-167: the header contains `SET FOREIGN_KEY_CHECKS=0;`
- [x] ISC-168: the header contains `SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';`
- [x] ISC-169: the file ends with `SET FOREIGN_KEY_CHECKS=1;`
- [x] ISC-170: each table block contains `DROP TABLE IF EXISTS`
- [x] ISC-171: each table block contains the verbatim `SHOW CREATE TABLE` output
- [x] ISC-172: rows are emitted as one batched `INSERT INTO … VALUES (…),(…);` per batch, not one statement per row
- [x] ISC-173: a table with zero rows still produces a valid block, with no `INSERT`

### Sprint 3 — RowSerializer (each line here is a real corruption bug if missed)

- [x] ISC-174: `null` is emitted as unquoted `NULL`
- [x] ISC-175: Anti: `null` is never emitted as `''`
- [x] ISC-176: a string containing `'` is escaped and single-quoted
- [x] ISC-177: a string containing a newline is escaped rather than breaking the statement
- [x] ISC-178: a value in a binary column is emitted as a `0x…` hex literal
- [x] ISC-179: Anti: an empty value in a binary column is emitted as `''`, never as a bare `0x`
- [x] ISC-180: a value in a numeric column is emitted unquoted
- [x] ISC-181: a non-numeric value in a numeric column falls back to a quoted string rather than emitting invalid SQL
- [x] ISC-182: a value that is not valid UTF-8 in a text column is emitted as hex rather than truncated

### Sprint 3 — the round trip (the sprint's centre, live MySQL)

- [x] ISC-183: a fixture table holding a NULL, a `'`, a newline, an emoji, and a binary blob is dumped and re-imported into a scratch schema
- [x] ISC-184: every column of every fixture row matches the original byte for byte after re-import
- [x] ISC-185: the emoji survives as 4-byte utf8mb4, not as `?`
- [x] ISC-186: `SHA2()` of the blob after re-import equals the original's
- [x] ISC-187: the re-import is performed by piping the file on stdin, never by `SOURCE` — see the MySQL-9 client trap in the Changelog
- [x] ISC-188: importing a full site dump into an empty schema exits 0 with no warnings
- [x] ISC-189: the re-imported table's `SHOW CREATE TABLE` equals the original's

### Sprint 3 — resume, at byte granularity

- [x] ISC-190: after a budget-exhausted step the persisted cursor carries the output byte length
- [x] ISC-191: the next step's first `INSERT` continues at row `offset`, neither repeating nor skipping
- [x] ISC-192: on resume `database.sql` is truncated to the persisted byte length before anything is appended
- [x] ISC-193: **the kill-mid-write test** — a step killed after a partial write leaves a file that, once resumed, contains no duplicated and no truncated row
- [x] ISC-194: a 5,000-row fixture at a zero-second budget completes across 10 or more steps
- [x] ISC-195: the same fixture at a 20-second budget produces a byte-identical `database.sql`
- [x] ISC-196: every step advances the cursor, so StageRunner's unchanged-cursor guard never fires
- [x] ISC-197: Anti: no row appears twice and none is missing — row count and checksum after the resumed run equal the single-step run

### Sprint 3 — memory and batching

- [x] ISC-198: the batch is 500 rows by default
- [x] ISC-199: the batch size is filterable
- [x] ISC-200: peak memory dumping a 200,000-row fixture stays under 64 MB
- [x] ISC-201: with `SAVEQUERIES` defined true, `$wpdb->queries` is empty after each batch
- [x] ISC-202: `$wpdb->flush()` is called after each batch so the last result set is released
- [x] ISC-203: Anti: the dump file is never accumulated in a string — every batch is written with `fwrite` and released

### Sprint 3 — exclusions

- [x] ISC-204: with transient exclusion on, no `_transient_%` row appears in the dumped options table
- [x] ISC-205: with transient exclusion on, no `_site_transient_%` row appears
- [x] ISC-206: with transient exclusion off, transient rows are present
- [x] ISC-207: the exclusion predicate is applied to every batch, so offsets stay consistent across a resume
- [x] ISC-208: a table named in `fiction_drafts/excluded_tables` produces no block at all

### Sprint 3 — stage wiring

- [x] ISC-209: `DatabaseStage::appliesTo()` is true for `FULL`
- [x] ISC-210: it is true for `DATABASE_ONLY`
- [x] ISC-211: it is false for `FILES_ONLY`
- [x] ISC-212: it is true for `FILES_NO_MEDIA`
- [x] ISC-213: for `CUSTOM` it defers to the job's `include_database` option
- [x] ISC-214: the stage is registered on `fiction_drafts/stages`, and `StageRegistry::all()` contains it
- [x] ISC-215: a `DATABASE_ONLY` job run end to end through `StageRunner` reaches `completed` and leaves `database.sql` on disk

### Sprint 3 — REST progress fields (carried in from Sprint 2's advisor review)

- [x] ISC-216: `GET /jobs/{uuid}` exposes `stage_processed` and `stage_total`
- [x] ISC-217: it exposes an overall percentage that does not decrease at a stage boundary
- [x] ISC-218: `processed` and `total` keep their Sprint 2 meaning, so nothing already built breaks

### Sprint 3 — gates

- [x] ISC-219: `composer check` exits 0
- [x] ISC-220: `php -l` passes on every new file
- [x] ISC-221: Anti: no new PHPCS exclusion beyond documented, justified ones
- [x] ISC-222: Anti: nothing under `src/Database/` performs an import, a restore, or a `DROP DATABASE`
- [x] ISC-223: the Action Scheduler standalone harness still passes
- [x] ISC-224: the plan marks Sprint 3 delivered
- [x] ISC-225: Anti: the Local site is left exactly as found — plugin deactivated, no `fdrafts_` tables, no scratch schema, no fixture tables

### Sprint 3 — added at VERIFY, from the advisor review

- [x] ISC-226: batches of a composite-primary-key table are ordered by the **whole** key — `term_relationships` ships in every install
- [x] ISC-227: a table with no key at all is ordered by every sortable column
- [x] ISC-228: text and blob columns are left out of that fallback ordering, so it cannot become the most expensive part of the dump
- [x] ISC-229: a latin1 table holding double-encoded UTF-8 bytes round-trips byte for byte, compared with `HEX()` so no connection charset can hide a difference
- [x] ISC-230: a `timestamp` column does not shift when the copy is restored on a host in another time zone
- [x] ISC-231: the session time zone is restored after the step, including when the step throws
- [x] ISC-232: the header emits `SET TIME_ZONE='+00:00'`, so the importing session agrees with the reading one
- [x] ISC-233: an explicit `0` in an `AUTO_INCREMENT` column is not renumbered on import
- [x] ISC-234: a zero date (`0000-00-00 00:00:00`, which real WordPress sites carry in `post_date_gmt`) survives the import
- [x] ISC-235: a `VARCHAR` holding `0123` keeps its leading zero, and one holding `+44` keeps its sign
- [x] ISC-236: a 2 MiB `longtext` value survives whole
- [x] ISC-237: the header names the views, triggers, routines, and events the export leaves behind, rather than dropping them silently
- [x] ISC-238: **the differential** — every table of this real site hashes identically, server-side and ordered, after dump and restore
- [x] ISC-239: the header's `Generated` stamp is the job's creation time, so a dump that restarts from the beginning is still one file rather than two
- [x] ISC-240: Anti: the absence of a consistent snapshot across a multi-request dump is documented in the spec, not implied away

### Sprint 4 — FileWalker (the `FileSource` implementation)

- [x] ISC-241: `FileWalker` implements `FileSource` and yields from a generator, so a 100k-file site never holds every path in memory at once
- [x] ISC-242: every yielded path is root-relative, forward-slashed, and carries no leading slash
- [x] ISC-243: directory entries are visited in a deterministic order, so two runs put the same file on the same `files.jsonl` line
- [x] ISC-244: a symlinked file is skipped
- [x] ISC-245: a symlinked directory is skipped rather than descended into
- [x] ISC-246: a symlink pointing at its own parent does not hang or recurse — proved against a real self-referential link, not a mock
- [x] ISC-247: an excluded directory is never descended into, rather than walked and then discarded
- [x] ISC-248: a hard-excluded absolute path is skipped even though its name is not knowable statically — this is how the storage directory is kept out
- [x] ISC-249: Anti: an archive can never contain itself — a storage directory placed under the scan root yields zero entries
- [x] ISC-250: an unreadable directory is skipped without a PHP warning and without aborting the walk
- [x] ISC-251: a file that disappears between `scandir` and `filesize` is skipped rather than yielding a false size
- [x] ISC-252: Anti: `.` and `..` are never yielded
- [x] ISC-253: a dotfile that is not excluded — `.htaccess` — is yielded

### Sprint 4 — FileScanStage

- [x] ISC-254: `FileScanStage::id()` is `files` and it reaches the pipeline through the `fiction_drafts/stages` filter
- [x] ISC-255: it writes `files.jsonl` with exactly one `{"p":…,"s":…}` object per line
- [x] ISC-256: the completing `StageResult` reports a `total` equal to the line count of `files.jsonl`
- [x] ISC-257: `StageRunner` writes that total onto the job, so the progress bar shows a real number rather than a guess
- [x] ISC-258: the pending-directory queue lives on disk, not in the cursor — a deep tree cannot outgrow the cursor column
- [x] ISC-259: the cursor is `{ dir, read, files_bytes, dirs_bytes }` — a read position plus both append lengths
- [x] ISC-260: **the byte-offset resume boundary generalises** — on resume both `files.jsonl` and the queue are truncated to their persisted lengths before anything is appended
- [x] ISC-261: a scan resumed after a partial line was written produces the same `files.jsonl` as an uninterrupted one, byte for byte
- [x] ISC-262: at a zero-second budget the scan still makes forward progress and completes across many steps
- [x] ISC-263: the same scan at a 20-second budget completes in one step and produces byte-identical output
- [x] ISC-264: `appliesTo()` is false for `DATABASE_ONLY`
- [x] ISC-265: `appliesTo()` is true for `FULL`, `FILES_ONLY`, and `FILES_NO_MEDIA`
- [x] ISC-266: `FILES_NO_MEDIA` produces a `files.jsonl` containing zero paths beginning `wp-content/uploads/`
- [x] ISC-267: the control for ISC-266 — `FULL` over the same tree does contain `wp-content/uploads/` paths, so the absence is the exclusion and not an empty fixture
- [x] ISC-268: **`wp-config.php` is absent from `files.jsonl` for every profile including `FULL`** when `include_wp_config` is unset or false
- [x] ISC-269: with `include_wp_config: true` in the job's options, `wp-config.php` appears in `files.jsonl` exactly once
- [x] ISC-270: the flag lives in the job's `options` JSON — `BackupProfile` still has no `includesWpConfig()` method
- [x] ISC-271: the administrator's own patterns from `Settings` are unioned with the profile's defaults rather than replacing them
- [x] ISC-272: the `fiction_drafts/exclusions` filter adds patterns to every job
- [x] ISC-273: the scan skips `node_modules/`
- [x] ISC-274: the scan skips `.git/`
- [x] ISC-275: the scan skips `wp-content/cache/`
- [x] ISC-276: the scan skips `wp-content/upgrade/`

### Sprint 4 — ZipWriter

- [x] ISC-277: `ZipWriter` implements `ArchiveWriter`
- [x] ISC-278: `addFile()` stores an entry under its root-relative name, with no absolute path leaking into the archive
- [x] ISC-279: **the descriptor rule** — the archive is closed and reopened every 200 entries
- [x] ISC-280: a 5,000-file fixture archives successfully with `ulimit -n` lowered to 256
- [x] ISC-281: **the control for ISC-279** — with reopening disabled nothing reaches disk mid-run, and libzip's resident state grows without bound; the descriptor claim itself is refuted and recorded rather than repeated
- [x] ISC-282: `entryCount()` and `bytesWritten()` describe the volume currently open, not the job
- [x] ISC-283: `close()` is safe when nothing is open
- [x] ISC-284: Anti: no entry name is absolute and none contains `..`
- [x] ISC-285: `truncateTo( n )` removes every entry at index ≥ n and leaves a valid archive behind
- [x] ISC-286: a produced volume passes `unzip -t`

### Sprint 4 — the zip resume boundary

- [x] ISC-287: **a zip cannot resume by byte truncation** — its central directory sits at the end, so the cursor counts entries where `DatabaseStage` counted bytes
- [x] ISC-288: an archive step repeated because its cursor never persisted adds no entry twice
- [x] ISC-289: Anti: the finished archive contains no entry name twice

### Sprint 4 — PclZip fallback

- [x] ISC-290: `PclZipWriter` implements `ArchiveWriter`
- [x] ISC-291: `ArchiveWriterFactory` selects `ZipWriter` when `ZipArchive` exists
- [x] ISC-292: it selects `PclZipWriter` when `ZipArchive` does not exist, simulated rather than assumed
- [x] ISC-293: a `PclZipWriter` archive is readable by `unzip -t`
- [x] ISC-294: `PclZipWriter` truncates by entry index too, so resume is not specific to one zip implementation
- [x] ISC-295: PclZip is loaded from WordPress's own bundled copy rather than vendored a second time

### Sprint 4 — ArchiveStage

- [x] ISC-296: `ArchiveStage::id()` is `archive`
- [x] ISC-297: it adds `database.sql` when the job produced one
- [x] ISC-298: it adds `manifest.json` when one is present, so Sprint 5 needs no change here
- [x] ISC-299: `DATABASE_ONLY` archives only `database.sql` and never opens `files.jsonl`
- [x] ISC-300: it adds every path in `files.jsonl`, in file order
- [x] ISC-301: the cursor is `{ line, volume, entries }`
- [x] ISC-302: after a budget-exhausted step it resumes from that cursor and the final listing still matches `files.jsonl` exactly
- [x] ISC-303: **a unit of work is bounded in bytes** — a step stops once it has added more than the per-step byte cap, even with time to spare
- [x] ISC-304: that cap is filterable
- [x] ISC-305: with `max_volume_bytes` at 1 MB, a 3 MB fixture produces at least three volumes
- [x] ISC-306: volumes are named `…-part01.zip`, `-part02.zip`, `-part03.zip` — zero-padded, so they sort in creation order
- [x] ISC-307: **the boundary test** — the union of entries across every volume equals `files.jsonl` exactly: nothing lost at a boundary, nothing duplicated
- [x] ISC-308: a single file larger than `max_volume_bytes` goes into a volume of its own rather than being dropped
- [x] ISC-309: Anti: that oversized-volume case is documented in the spec rather than implied away
- [x] ISC-310: a file that vanished between the scan and the archive is skipped without failing the job
- [x] ISC-311: the stage's `total` is the `files.jsonl` line count
- [x] ISC-312: the archive is closed at the end of every step, so a volume on disk is always a valid zip
- [x] ISC-313: **the differential** — the entry list and per-entry SHA-256 across all volumes are compared against the filesystem itself, not against `files.jsonl`
- [x] ISC-314: Anti: no entry in any volume resolves outside the scan root
- [x] ISC-315: Anti: no volume contains the storage directory or another volume

### Sprint 4 — wiring

- [x] ISC-316: the pipeline order is database → files → archive
- [x] ISC-317: a `FULL` job runs all three stages to `completed` against the real site
- [x] ISC-318: each stage has a distinct, translated `stage_label`
- [x] ISC-319: the `ExclusionSet` is built once per job rather than once per directory
- [x] ISC-320: the storage hard-exclusion still applies when `FICTION_DRAFTS_STORAGE_DIR` relocates the root

### Sprint 4 — gates

- [x] ISC-321: `composer check` exits 0
- [x] ISC-322: `php -l` passes on every new file
- [x] ISC-323: Anti: no new PHPCS exclusion beyond documented, justified ones
- [x] ISC-324: Anti: nothing in the new code extracts, imports, or restores an archive
- [x] ISC-325: the Action Scheduler standalone harness still passes
- [x] ISC-326: the plan marks Sprint 4 delivered
- [x] ISC-327: Anti: the Local site is left exactly as found — plugin deactivated, no fixtures, no stray volumes


### Sprint 4 — added at VERIFY, from live measurement and the advisor review

- [x] ISC-328: a storage directory left behind by an *earlier* install is excluded by pattern — the runtime rule only knows about the current one
- [x] ISC-329: a filename that is not valid UTF-8 reaches the archive under its own bytes, rather than making `json_encode()` return false and disappearing from both the list and every check that compares against it
- [x] ISC-330: a second worker inside one job does nothing at all — resuming an archive mutates it, so an overlapping step is destructive rather than merely wasteful
- [x] ISC-330b: the control for ISC-330 — the same runner with a lock it can take does run the step
- [x] ISC-331: **the set differential** — the scan's output equals an independent `find(1)` walk with the same exclusions applied, because hashing what was archived cannot prove that nothing is missing
- [x] ISC-332: a file shorter than the length the cursor resumes at is refused, not padded — `ftruncate()` past the end fills with NUL bytes rather than failing
- [x] ISC-333: a volume holding fewer entries than its cursor claims is rebuilt from the line that volume began at, not skipped past
- [x] ISC-334: the volume-size projection counts the entry name twice, because a zip stores it twice and WordPress paths are long
- [x] ISC-335: a volume is sealed at 60,000 entries whatever its size — PclZip writes no ZIP64 record, and past 65,535 the count wraps
- [x] ISC-336: the archive cursor refuses an offset that is not the start of a line, so a re-run scan cannot be resumed into halfway through
- [x] ISC-337: a skipped symlink fires an action rather than vanishing — `wp-content/uploads` pointing at a mounted volume is a standard layout
- [x] ISC-338: Anti: what a zip does not carry — file modes, sub-second timestamps, empty directories — is documented rather than implied away
- [x] ISC-339: Anti: the PclZip fallback's limits are documented rather than discovered by whoever needs it




### Sprint 5 — Manifest, checksums, preflight, retention

**Manifest — what an archive says about itself**

- [x] ISC-340: `manifest.json` carries exactly the documented key set — no field missing, no undocumented field added
- [x] ISC-341: `site_url`, `home_url`, `wp_version`, `php_version`, `mysql_version`, `table_prefix`, and `active_theme` are read from the live site at job time rather than defaulted
- [x] ISC-342: `active_plugins` lists the plugin files active when the job ran
- [x] ISC-343: `profile` is the job's profile, and a `CUSTOM` job additionally records its per-area opt-ins, because the profile name alone does not say what a custom job copied
- [x] ISC-344: `includes_wp_config` is `false` for a default job
- [x] ISC-345: `includes_wp_config` is `true` only when the job opted in, read from `BackupJob::OPTION_INCLUDE_WP_CONFIG` and never from the profile
- [x] ISC-346: `file_count` equals the line count of `files.jsonl`
- [x] ISC-347: `total_bytes` equals the summed `s` field of `files.jsonl` plus the dump's size on disk
- [x] ISC-348: `skipped_symlinks` records how many links the scan passed over, so an archive missing a media library says so on its face
- [x] ISC-349: `created_at` is the job's creation time in UTC, not the moment the manifest was written
- [x] ISC-350: the in-archive manifest is picked up by `ArchiveStage` with no change to that stage — `EXTRAS` already names it
- [x] ISC-351: the sidecar manifest beside the volumes carries a `volumes` array with each volume's sequence, filename, bytes, and sha256
- [x] ISC-352: Anti: the in-archive manifest does not claim to carry volume checksums — a file cannot contain its own hash, and the two copies differ by exactly that, documented rather than silent

**Checksums and the volume ledger**

- [x] ISC-353: `sha256_file()` of each volume on disk equals the `sha256` recorded in `fdrafts_volumes`
- [x] ISC-354: the same hash appears in the sidecar manifest's `volumes` array
- [x] ISC-355: each volume row's `bytes` equals `filesize()` of the volume
- [x] ISC-356: the job's `size_bytes` equals the sum of its volumes' bytes
- [x] ISC-357: hashing a volume streams rather than loading it — peak memory stays under 64 MB for a volume far larger than that
- [x] ISC-358: a volume query returns one job's volumes and no other job's
- [x] ISC-359: the volume list is built by walking the job's own sequence naming from 1 to the first gap, never by globbing the storage directory
- [x] ISC-360: Anti: a stale volume left by a crashed earlier attempt is not checksummed, recorded, or offered

**Preflight**

- [x] ISC-361: `PrepareStage` fails the job with a human-readable message when `disk_free_space()` is below the required size × 1.2
- [x] ISC-362: a failed preflight schedules no further step — the job ends `failed` with an empty queue
- [x] ISC-363: the control for ISC-361 — the same job with space available passes preflight and advances to the archive
- [x] ISC-364: `PrepareStage` fails with a clear message when the storage directory is not writable
- [x] ISC-365: the required figure is measured, not guessed — the dump from `filesize()`, the files from the summed `s` field
- [x] ISC-366: `PrepareStage` sits between the scan and the archive, the first moment a real number exists and the last moment before large writes begin
- [x] ISC-367: a `DATABASE_ONLY` job passes preflight with a file count of zero rather than dividing by it

**Cancellation**

- [x] ISC-368: `DELETE /jobs/{uuid}` on a running job returns the job with status `cancelled`
- [x] ISC-369: cancelling unschedules that job's pending `fiction_drafts/run_step` actions and leaves other jobs' actions alone
- [x] ISC-370: cancelling removes the job's working directory
- [x] ISC-371: cancelling an already-finished job is a no-op returning the job unchanged, not an error
- [x] ISC-372: cancelling removes the partial volumes as well — half an archive that survives on disk is worse than none

**Finalize**

- [x] ISC-373: `FinalizeStage` deletes `database.sql`, `files.jsonl`, and the working directory
- [x] ISC-374: after a completed job the storage root holds volumes and the sidecar manifest and nothing else belonging to that job
- [x] ISC-375: `FinalizeStage` is last in the pipeline and applies to every job
- [x] ISC-376: the control for ISC-373 — the volumes are still intact and readable after the working directory is gone

**Retention**

- [x] ISC-377: with retention 3 and 4 completed backups, the sweep deletes the oldest backup's volumes
- [x] ISC-378: the sweep deletes that backup's sidecar manifest
- [x] ISC-379: the sweep deletes that backup's rows from both `fdrafts_jobs` and `fdrafts_volumes`
- [x] ISC-380: exactly 3 backups remain afterwards
- [x] ISC-381: retention never deletes a job that is `queued` or `running`
- [x] ISC-382: retention `0` keeps everything — the sweep deletes nothing
- [x] ISC-383: the sweep is attached to `fiction_drafts/retention_sweep`, which `Scheduler::scheduleRecurring()` already registers daily
- [x] ISC-384: Anti: the sweep never removes a path outside the storage root

**Regression and hygiene**

- [x] ISC-385: Anti: no restore path appears — no `extractTo`, no `extract`, no `DROP DATABASE` anywhere in `src/`
- [x] ISC-386: `composer check` exits 0
- [x] ISC-387: `php -l` is clean across `src/`, `tests/`, `uninstall.php`, and `fiction-drafts.php`
- [x] ISC-388: `phpcs.xml.dist` is byte-unchanged from Sprint 2
- [x] ISC-389: a `FULL` job runs all five stages to `completed` against the live site
- [x] ISC-390: Anti: the live site is left exactly as found — the plugin never activated, no stray directories, no rows, no options
- [x] ISC-391: the plan records Sprint 5 delivered, with a carried-forward block for Sprint 6


### Sprint 5 — added at VERIFY, from the advisor round

- [x] ISC-392: every deletion resolves its target with `realpath()` and refuses anything whose parent is not the storage root itself
- [x] ISC-393: the two halves of a volume filename are validated before use — a `created_at` of `../../../x` and a uuid of `/../../x` are database columns, not trusted values, and the composed path reaches `unlink()`
- [x] ISC-393b: the control for ISC-393 — the crafted path resolves to a real file, so the refusal is proof of something. An earlier version landed on nothing and passed without the guard present at all
- [x] ISC-394: a symlink planted in the storage root is refused rather than counted as a volume removed
- [x] ISC-395: `ZipArchive::close()`'s return value is checked — it is where every buffered entry is actually written, and a full disk reports itself there and nowhere else
- [x] ISC-396: the sidecar manifest is read back before the working directory is removed, because the write follows gigabytes of archive and destroying its only source leaves a backup nothing can describe
- [x] ISC-397: a volume missing from the middle of the set is refused — the entry total across volumes is checked against the file count the *scan* recorded, which the archive did not produce
- [x] ISC-397b: the control for ISC-397 — a complete volume set passes the same check
- [x] ISC-398: the two manifest copies are diffed against each other with `volumes` as the only permitted difference, rather than each being checked against a key list it could satisfy while a value diverged
- [x] ISC-399: a failed job's volumes and working directory are freed by the sweep — nothing resumes a failed job, and the keep-N policy only ever looked at completed backups
- [x] ISC-400: a failed job's row survives that cleanup, because its error message is the only record of what went wrong
- [x] ISC-401: writability is answered by creating a file and removing it, not by `is_writable()`, which is wrong under `open_basedir`, ACLs, and a read-only mount
- [x] ISC-402: an absent, empty, or unparseable retention setting falls back to the default rather than to zero — `(int)` of a missing option is also `0`, and a keep count that meant "keep none" would delete every backup on the site
- [x] ISC-403: a literal `0` is kept as `0`, which means keep everything
### Sprint 6 — the admin page and the REST surface behind it

- [x] ISC-404: an `Admin\AdminPage` registers one top-level menu on `admin_menu`, gated on `manage_options`
- [x] ISC-405: the menu callback prints exactly one `<div id="fiction-drafts-root">` and no other markup — every pixel after that is React's
- [x] ISC-406: assets enqueue only on the plugin's own screen, keyed on the hook suffix `add_menu_page()` returned, not on a global `admin_enqueue_scripts`
- [x] ISC-407: script version and dependencies are read from `build/index.asset.php`, never hardcoded — a rebuilt bundle must not be served from a stale browser cache
- [x] ISC-408: `build/index.css` is enqueued only when the file exists, because `wp-scripts` emits it only when the entry imports styles
- [x] ISC-409: the bootstrap object reaches the client through `wp_add_inline_script( …, 'before' )` on the app handle, so it is defined before the bundle runs
- [x] ISC-410: the bootstrap carries the profile catalogue built from `BackupProfile`, so the client never restates the §6.1 table
- [x] ISC-411: the bootstrap carries stage ids and labels built from the registered pipeline via `Stage::label()`, so the client never hardcodes a stage list
- [x] ISC-412: the bootstrap carries the §6.3 `wp-config.php` warning text, translated server-side
- [x] ISC-413: Anti: the bootstrap never carries a filesystem path, the table prefix, or the storage slug — the client learns nothing it could not already ask for
- [x] ISC-414: `wp_set_script_translations()` is called for the app handle, so the JS strings are translatable at all
- [x] ISC-415: `AdminServiceProvider` is registered in `Plugin::providers()` and the "Sprint 6 adds the Admin provider here" placeholder comment is gone

- [x] ISC-416: `GET /backups` returns completed jobs only — a queued, running, failed, or cancelled job is not a backup
- [x] ISC-417: each entry carries `uuid`, `created_at`, `profile`, `size_bytes`, and `volume_count`
- [x] ISC-418: each entry carries `size_human`, formatted server-side, so two clients cannot round the same number differently
- [x] ISC-419: `profile` carries both the slug and the translated label, for the same reason the stage labels do
- [x] ISC-420: backup metadata is read from the sidecar `…-manifest.json` via `Manifest::read()` — no route opens an archive to answer a list request
- [x] ISC-421: a backup whose sidecar manifest is missing is still listed, with `manifest: null` — missing metadata is not missing data, and a row that vanishes from the list is a backup nobody can delete
- [x] ISC-422: `available` is derived from `VolumeNaming::sequencesFor()`, so a row whose volumes are gone from disk is reported as unavailable rather than offered for download
- [x] ISC-423: each volume's `sequence`, `bytes`, and `sha256` are exposed, so a downloaded file can be checked against what the plugin sealed
- [x] ISC-424: `includes_wp_config` is surfaced per backup from its manifest, so an archive's blast radius is visible in the list rather than only inside the file
- [x] ISC-425: `DELETE /backups/{uuid}` removes volumes, sidecar, working directory, volume rows, and the job row
- [x] ISC-426: `DELETE /backups/{uuid}` on a job that is queued or running returns `409` — deleting files under a live worker is how a delete corrupts the thing it is tidying
- [x] ISC-427: `DELETE /backups/{uuid}` on an unknown uuid returns `404`
- [x] ISC-428: deletion and the retention sweep call one shared removal path, so the two cannot drift into disagreeing about what a backup consists of
- [x] ISC-429: Anti: no route in the namespace returns a filesystem path in any field
- [x] ISC-430: `DELETE /backups/{uuid}` fires `fiction_drafts/backup_deleted`, the same action the sweep fires

- [x] ISC-431: `GET /settings` returns `default_profile`, `exclusions`, `max_volume_bytes`, and `retention_count`
- [x] ISC-432: `PUT /settings` persists, and a subsequent `GET` returns what was written
- [x] ISC-433: `PUT /settings` with an unknown profile returns `400` rather than silently falling back to Full
- [x] ISC-434: `PUT /settings` with `max_volume_bytes` below the floor is clamped and the response reports the clamped value, so the form shows what was actually stored
- [x] ISC-435: `PUT /settings` with a negative `retention_count` is clamped to `0`
- [x] ISC-436: `PUT /settings` with a partial body leaves the unspecified fields at their stored values — a settings screen that saves one tab must not blank the others
- [x] ISC-437: exclusion patterns are sanitized per entry and a non-string entry is dropped, not fatal
- [x] ISC-438: the settings payload carries `min_volume_bytes` and states that `0` retention means keep everything, so the UI explains the rule rather than restating it
- [x] ISC-439: Anti: the settings payload never contains `include_wp_config` — the opt-in is not sticky because it has nowhere durable to live, not because a component remembers to clear it
- [x] ISC-440: Anti: no settings route writes an option whose name lacks the plugin's prefix

- [x] ISC-441: every controller in `src/Rest/` extends `AbstractController`
- [x] ISC-442: every route registered by this plugin has `permission_callback` pointing at `permissionCheck`
- [x] ISC-443: a user without `manage_options` receives `403` from every route in the namespace
- [x] ISC-444: the admin menu does not render for a user without `manage_options`
- [x] ISC-445: Anti: no route registers with `__return_true` as its permission callback
- [x] ISC-446: Anti: no controller re-states the capability string — `AbstractController::CAPABILITY` is the only occurrence of `manage_options` in `src/Rest/`

- [x] ISC-447: the app mounts a router with three routes: `/` (new backup and progress), `/backups`, `/settings`
- [x] ISC-448: the profile picker renders the five profiles from the bootstrap catalogue, not a literal in the component
- [x] ISC-449: choosing Custom reveals the per-area checkboxes and nothing else does
- [x] ISC-450: the "Include `wp-config.php`" checkbox renders below the picker and outside it, unchecked, carrying the §6.3 warning text
- [x] ISC-451: ticking it sends `include_wp_config: true` in the `POST /jobs` body; leaving it sends `false`
- [x] ISC-452: Anti: the checkbox's value is never read from settings, from a previous job, or from browser storage — its only source is a `useState` initialised to `false`
- [x] ISC-453: the progress view polls `GET /jobs/{uuid}` every 2 seconds
- [x] ISC-454: polling stops on `completed`, `failed`, and `cancelled`
- [x] ISC-455: the progress view renders `stage_label` from the payload
- [x] ISC-456: the bar is driven by `overall_percent`, which does not drop to zero at a stage boundary
- [x] ISC-457: a failed job renders `error` verbatim — a failed preflight names both figures and what to do, and a generic string throws that away
- [x] ISC-458: the backups view renders date, profile label, `size_human`, and volume count
- [x] ISC-459: deleting from the list asks for confirmation and then invalidates the list query rather than mutating a local array
- [x] ISC-460: the settings form binds every field and saves through `PUT /settings`
- [x] ISC-461: Anti: no `style=` attribute anywhere in `assets/app`, and every class name begins `fd-`

- [x] ISC-462: `bun run build` exits 0 and writes `build/index.js`
- [x] ISC-463: `build/index.asset.php` lists the `@wordpress/*` packages the app imports, which is what makes ISC-407 mean anything
- [x] ISC-464: `bun run lint:js` exits 0
- [x] ISC-465: `composer check` exits 0 — PHPCS, then PHPStan level 6, then PHPUnit
- [x] ISC-466: Anti: the plugin is never activated on this site; the census after the run shows no new tables, no new options, and no storage directory

- [x] ISC-467: the routes register on a real WordPress bootstrap — probed by reading `rest_get_server()->get_routes()` for the namespace
- [x] ISC-468: `GET /backups` renders a real completed backup on the live harness, with the size matching what `du` reports for its volumes
- [x] ISC-469: `DELETE /backups/{uuid}` frees real bytes on the live harness, measured on disk before and after
- [x] ISC-470: `PUT /settings` round-trips through the real option row and reads back with `get_option()` after a repository flush
- [x] ISC-471: a request made without the capability returns `403` from each route on the live harness
- [x] ISC-472: the control for ISC-471 — the identical request made *with* the capability returns `200`, so the refusal proves the gate rather than a mistyped route
- [x] ISC-473: `bun run lint:js` reports a non-zero file count — measured at OBSERVE it exits `2` with "all of the files matching the glob pattern `assets/app` are ignored", so a green lint would have proved nothing
- [x] ISC-474: the control for ISC-473 — a deliberate violation planted in the app makes the lint fail, and removing it makes it pass

### Sprint 6 — added by the advisor round

- [x] ISC-475: the sidecar manifest is projected through `Manifest::KEYS` rather than echoed — it is a file on disk, which makes it input, and a response whose shape depends on a file anyone with write access can edit is not a contract
- [x] ISC-475b: the control for ISC-475 — the legitimate keys still come through, so the rejections above are about projection rather than about a manifest that failed to load
- [x] ISC-476: `created_at_iso` and `completed_at_iso` are RFC 3339 with an explicit offset — a bare MySQL datetime given to `new Date()` is local time in some browsers and UTC in others
- [x] ISC-477: the client is handed exactly what `rest_url()` returns, byte for byte, so a site with Plain permalinks serving REST at `/?rest_route=` still works
- [x] ISC-478: Anti: no client file contains the literal `/wp-json` — hardcoding it is what makes a Plain-permalink site fail completely rather than partially
- [x] ISC-479: the delete route holds the same named lock `StageRunner` takes for a step, and re-reads the job inside it, so a delete cannot overlap a step
- [x] ISC-480: polling stops on `401` and `403` and says the session expired — a thirty-minute backup polls about nine hundred times, and a stale nonce would otherwise become a retry storm against an authenticated endpoint
- [x] ISC-481: an unmatched hash routes to the dashboard rather than a blank screen — a bare `href="#"` anywhere in the admin chrome is enough to reach one
- [x] ISC-482: the list route never calls `hash_file` or `file_get_contents` — every checksum was computed once by `FinalizeStage` and stored
- [x] ISC-482b: the control for ISC-482 — the checksum genuinely is in the payload, so the rule is about where it came from rather than about a field that does not exist
- [x] ISC-483: a uuid of `../../../../../../tmp` cannot reach a file outside the storage root, with a control file placed one level up that must survive

### Sprint 7 — Secure download and security pass

**Issuing a token**

- [x] ISC-484: `POST /backups/{uuid}/download-token` returns `200` with `url` and `expires_at`
- [x] ISC-485: `expires_at` is exactly 300 seconds after issue, in RFC 3339 with an explicit offset
- [x] ISC-486: the returned URL carries `action=fiction_drafts_download`, `job`, `volume`, `token` and `_wpnonce`, and nothing else
- [x] ISC-487: a token request for an unknown uuid returns `404`
- [x] ISC-488: a token request for a volume sequence the backup does not have returns `404`
- [x] ISC-489: a token request for a job that is not `completed` returns `409`
- [x] ISC-490: a token request made without the capability is refused by `permissionCheck()`
- [x] ISC-491: the control for ISC-490 — the identical request made *with* the capability returns `200`

**The token itself**

- [x] ISC-492: the store holds a hash of the token, never the token — a store readable by anything that can read options must not itself be a download credential
- [x] ISC-493: the token handed to the client is 64 hex characters from `random_bytes()`, not `uniqid()` or `wp_rand()`
- [x] ISC-494: `DownloadToken::consume()` returns the record once and `null` on every call after — single-use
- [x] ISC-495: `consume()` returns `null` for a record older than 300 seconds
- [x] ISC-496: `consume()` returns `null` when the presenting user id differs from the issuing user id
- [x] ISC-497: the comparison is `hash_equals()`, not `===` — a token check that returns early leaks its own answer through timing
- [x] ISC-498: expired records are removed when the store is next written, so a site that issues tokens for a year does not accumulate a year of them
- [x] ISC-499: the store option is non-autoloaded

**Streaming**

- [x] ISC-500: a valid download returns `200` with `Content-Type: application/zip`
- [x] ISC-501: the response carries `Content-Disposition: attachment` with the volume's own filename
- [x] ISC-502: `Content-Length` equals the volume's byte count on disk
- [x] ISC-503: the response carries `X-Content-Type-Options: nosniff`
- [x] ISC-504: the response carries `Accept-Ranges: bytes`
- [x] ISC-505: the streamed body is byte-identical to the volume on disk
- [x] ISC-506: every output buffer is cleared before the first byte is written
- [x] ISC-507: `set_time_limit( 0 )` is called before the loop
- [x] ISC-508: the read size is 8 MiB and the loop is `fread`/`echo`/`flush`
- [x] ISC-509: Anti: the download path contains no `readfile()`, `file_get_contents()`, or `stream_get_contents()` — each loads a whole archive into a 128 MB memory limit
- [x] ISC-510: streaming a fixture larger than the chunk size keeps peak memory growth under 64 MB, measured with `memory_get_peak_usage()`
- [x] ISC-511: the handler calls `exit` after the loop, so nothing appends to a binary body

**Range**

- [x] ISC-512: `Range: bytes=1048576-` returns `206`
- [x] ISC-513: the `206` carries `Content-Range: bytes 1048576-{last}/{size}` and a `Content-Length` of the slice, not of the file
- [x] ISC-514: the `206` body is byte-identical to that slice of the file
- [x] ISC-515: `Range: bytes=0-1023` returns the first 1024 bytes and a matching `Content-Range`
- [x] ISC-516: `Range: bytes=-1024` returns the last 1024 bytes — a suffix range is a valid range
- [x] ISC-517: a range starting beyond the end returns `416` with `Content-Range: bytes */{size}`
- [x] ISC-518: a malformed `Range` header is ignored and answered with a full `200`, per RFC 9110

**Refusals**

- [x] ISC-519: replaying the same token a second time returns `403`
- [x] ISC-520: a token issued to user A and presented by user B returns `403`
- [x] ISC-521: a token older than five minutes returns `403`
- [x] ISC-522: a request with a missing or wrong nonce returns `403`
- [x] ISC-523: a logged-out request returns `403`
- [x] ISC-524: a logged-in request without `manage_options` returns `403`
- [x] ISC-525: the control for ISC-519..524 — the identical request with everything valid returns `200` and the archive
- [x] ISC-526: every refusal happens before the file is opened, so a refused request cannot be measured against a successful one by how long it takes

**No paths, ever**

- [x] ISC-527: the handler reads only `job` and `volume`; a `path` or `file` parameter on the query string changes nothing about which file is served
- [x] ISC-528: `PathGuard::within()` rejects a `../` traversal
- [x] ISC-529: `PathGuard::within()` rejects a path whose `realpath()` resolves outside the base
- [x] ISC-530: `PathGuard::within()` rejects a symlink inside the base that points outside it
- [x] ISC-531: `PathGuard::within()` rejects a path that does not exist, rather than guessing
- [x] ISC-532: the control for ISC-528..531 — the legitimate volume path is accepted, so the rejections prove the guard rather than a broken base
- [x] ISC-533: the guard rejects a base that is a prefix string but not a parent directory — `/storage-evil/x` is not inside `/storage`
- [x] ISC-534: the download handler passes the resolved volume path through `PathGuard` before opening it
- [x] ISC-535: a volume sequence of `0`, `-1`, or `999999` returns `404` and never reaches the filesystem

**Storage directory**

- [x] ISC-536: the storage directory name carries 32 lowercase hex characters
- [x] ISC-537: the suffix is stored in a non-autoloaded option and is not regenerated on a second read
- [x] ISC-538: the storage root contains `index.php`, `.htaccess` and `web.config` after `ensure()`
- [x] ISC-539: defining `FICTION_DRAFTS_STORAGE_DIR` relocates the base, and the download handler serves from the relocated root

**The readme tells the truth**

- [x] ISC-540: `README.md` documents the download link as capability-checked, single-use, and five minutes long
- [x] ISC-541: `README.md` states that `.htaccess` protects Apache hosts only and that nginx ignores it
- [x] ISC-542: `README.md` documents `FICTION_DRAFTS_STORAGE_DIR` as the strongest option
- [x] ISC-543: `README.md` documents `wp action-scheduler run` as the manual queue nudge
- [x] ISC-544: `README.md` states that `wp-config.php` is excluded by default and what including it exposes
- [x] ISC-545: `README.md` states that a backup contains every user's password hash even without `wp-config.php`
- [x] ISC-546: on this nginx instance, a direct URL to a volume while logged out is measured, and the readme describes what was measured rather than what was hoped

**The §10 review pass**

- [x] ISC-547: every control in spec §10.1, §10.2 and §10.3 maps to a named test or a documented manual check, enumerated in the ISA `## Verification`
- [x] ISC-548: `manage_options` is scoped for multisite — on a network install the gate requires `manage_network_options`, closing the gap §10.2 states
- [x] ISC-549: a cancel is a flag the runner honours at a stage boundary rather than a race it usually wins — `StageRunner` re-reads and refuses to advance a job whose status left `running`
- [x] ISC-550: Anti: no route, handler, or CLI path in the plugin restores, imports, or writes outside the storage root
- [x] ISC-551: Anti: the plugin is never activated on this site — the census after the run finds zero `fdrafts_` tables, zero `fiction_drafts_` options, and zero storage directories
- [x] ISC-552: `composer check`, `bun run lint:js` and `bun run build` all exit `0`

### Sprint 7 — added by the advisor round and the live run

- [x] ISC-553: `get_option()` inside the grant lock is preceded by `wp_cache_delete()` on `options` and `notoptions` — non-autoloaded does not mean uncached, and a cached copy read inside a lock makes the lock guard a stale read
- [x] ISC-554: `issue()` returns an empty string rather than a token when the mutation could not be applied — a grant that was not written is not a grant
- [x] ISC-555: `consume()` refuses when the lock cannot be taken, so being unable to prove a token unspent is a refusal rather than a guess
- [x] ISC-556: the control for ISC-555 — the same grant is still claimable by a caller that *can* take the lock, so the refusal is about the lock rather than an empty store
- [x] ISC-557: the grant lock waits three seconds; the job locks keep their zero wait, because "a backup is already running" wants an immediate `409`
- [x] ISC-558: four simultaneous clients presenting one token produce exactly one `200` and three `403`s
- [x] ISC-559: the control for ISC-558 — four simultaneous clients presenting four *different* tokens produce four `200`s, so the result above is about single use rather than about contention
- [x] ISC-560: lock names are derived from `DB_NAME` as well as the table prefix — `GET_LOCK` names live on the MySQL server, and two installs both using `wp_` is the common case
- [x] ISC-561: the response carries `X-Accel-Buffering: no` — nginx otherwise spools the whole archive to `fastcgi_temp` before a byte reaches the client
- [x] ISC-562: `zlib.output_compression` is turned off explicitly — it is on by default on many shared hosts and it invalidates `Content-Length` and every `Content-Range` offset
- [x] ISC-563: `headers_sent()` is checked before the first header; a response that already started is refused rather than allowed to produce a `200` carrying a corrupt file
- [x] ISC-564: the response carries an `ETag` derived from the stored checksum
- [x] ISC-565: a `Range` with a non-matching `If-Range` is answered with a full `200`, per RFC 9110 §13.1.5
- [x] ISC-566: the control for ISC-565 — a matching `If-Range` still gets its `206`
- [x] ISC-567: the served size comes from `fstat()` on the already-open handle, not from a separate stat or the ledger row
- [x] ISC-568: the streaming loop stops when `connection_aborted()` reports the client gone
- [x] ISC-569: the control for ISC-568 — a client that is still there receives the whole body
- [x] ISC-570: a 2 GiB volume downloads complete and byte-identical through the real web server, and a range beginning two gigabytes in returns the correct slice
- [x] ISC-571: the CLI harness boots the database belonging to *this* document root, identified by the nginx config whose `root` is this install — not the most recently touched socket on the machine
- [x] ISC-572: the control for ISC-571 — `home_url()` names this site, so every live result describes the install under test
- [x] ISC-573: the download URL contains no HTML entities; `wp_nonce_url()` escapes for HTML and would have made every download `403`
- [x] ISC-574: the control for ISC-573 — the URL genuinely has separators that could have been escaped
- [x] ISC-575: the test double for `wp_nonce_url()` escapes the way core does, so the class of bug is visible to the unit tests rather than only to a live run
- [x] ISC-576: a `volume` parameter that is not a positive integer is refused rather than normalised — `absint( '-1' )` is `1`
- [x] ISC-577: headers are verified over real HTTP through this site's own nginx; under the CLI SAPI `header()` is a no-op and `headers_list()` is always empty, so a CLI-only header gate could not fail

**Post-release — the first real click (2026-08-28)**

- [x] ISC-578: `handle()` accepts the argument WordPress actually passes — core substitutes `''` for a hook fired with no arguments, so a typed `?array` first parameter is a `TypeError` on every real request
- [x] ISC-579: firing `admin_post_fiction_drafts_download` through `register()`, with core's calling convention, serves the archive — the control, since a handler that refused everything would pass ISC-578 alone
- [x] ISC-580: an injected array still wins over `$_GET`, which is what keeps the other 41 handler tests free of superglobals
- [x] ISC-581: the `do_action` test double reproduces core's `if ( empty( $arg ) ) { $arg[] = ''; }`, so the suite catches this class of defect without needing an ISC per callback

**Audit — security, performance, and duplication (2026-08-28)**

- [x] ISC-582: `Manifest::read()` refuses a sidecar past `MAX_READ_BYTES` rather than allocating it
- [x] ISC-583: a real sidecar still reads — the control, without which an unconditional `null` passes ISC-582
- [x] ISC-584: the ceiling is measured on bytes actually read, not on a prior `filesize()` that a growing file could race
- [x] ISC-585: a lost `slug()` race adopts the winner's directory instead of resolving a second storage root
- [x] ISC-586: the menu and the REST gate resolve the same capability, so multisite cannot show a screen whose every request is refused
- [x] ISC-587: `GET /backups` reads the volume ledger once per page, asserted by call count rather than by result shape
- [x] ISC-588: each backup still gets its own volumes — the control for the batch read
- [x] ISC-589: the per-entry size projection has one definition; no writer or stage keeps a private copy

**Release packaging — a distributable, installable archive (2026-08-28)**

- [x] ISC-590: `bun run package` exits 0 and writes `dist/fiction-drafts-{version}.zip`
- [x] ISC-591: the archive has exactly one top-level directory, named `fiction-drafts` — the folder WordPress installs it as
- [x] ISC-592: `unzip -t` reports the archive structurally sound
- [x] ISC-593: `vendor/autoload.php` is inside the archive
- [x] ISC-594: `vendor/woocommerce/action-scheduler/action-scheduler.php` is inside, so background work never waits on another plugin
- [x] ISC-595: `build/index.js` and `build/index.asset.php` are inside, so the admin screen renders without a build step on the server
- [x] ISC-596: every `src/` PHP file in the repository has a counterpart in the archive, compared by count against a tree the packer did not write
- [x] ISC-597: the *extracted* archive's autoloader resolves `FictionDrafts\Plugin` in a bare PHP process with no WordPress present
- [x] ISC-598: every shipped PHP file outside `vendor/` passes `php -l`
- [x] ISC-599: Anti: no `tests/` entry ships
- [x] ISC-600: Anti: `vendor/` holds nothing beyond what `composer.json`'s `require` names — the check is derived from the manifest, so it cannot go stale
- [x] ISC-601: both production packages are actually present — the control, without which an empty `vendor/` satisfies ISC-600
- [x] ISC-602: Anti: no `node_modules`, `.git`, `.agents`, `ISA.md`, `CLAUDE.md`, tool config, `assets/` source, or JS lockfile ships
- [x] ISC-603: Anti: no `.DS_Store` or AppleDouble entry ships
- [x] ISC-604: Anti: a planted `src/leaked-dump.sql` fails the run and exits 1 — the allow-list is proved by watching it refuse
- [x] ISC-605: a version disagreement between the plugin header, `FICTION_DRAFTS_VERSION` and `package.json` aborts before anything is staged
- [x] ISC-606: Anti: the working tree's `vendor/` is untouched by a release build — `composer check` still passes afterwards
- [x] ISC-607: the header *inside* the archive declares the version the build claimed

## Test Strategy

| isc | type | check | threshold | tool |
|---|---|---|---|---|
| ISC-484..491 | integration | POST /backups/{uuid}/download-token through the real WP_REST_Server | exact status + payload keys | scratchpad/sprint7.php |
| ISC-492..499 | unit + live | grant store: hashing, single use, expiry, sweep, cap, autoload | exact | GrantStoreTest + sprint7.php |
| ISC-500..511 | live HTTP | headers and body through this site's nginx and PHP-FPM | cmp(1) byte equality | scratchpad/sprint7.php |
| ISC-512..518 | unit + live | Range arithmetic against real slices, then over real HTTP | substr equality | ByteRangeTest + sprint7.php |
| ISC-519..526 | live HTTP | replay, cross-user, expiry, nonce, capability, with a positive control | exact status | scratchpad/sprint7.php |
| ISC-527..535 | unit + live | PathGuard containment, symlink refusal, no client-supplied paths | exact boolean / status | PathGuardTest + sprint7.php |
| ISC-536..539 | unit + live | storage suffix entropy, guard files, relocation constant | exact | DownloadBoundaryTest + sprint7.php |
| ISC-540..546 | live | readme claims matched against a measured nginx response | substring + HTTP 200 | scratchpad/sprint7.php |
| ISC-547..552 | sweep | §10 control-by-control walk, census, gate exit codes | exit 0 | composer check + sprint7.php |
| ISC-553..557 | unit | cache invalidation recorded, fail-closed lock paths, lock wait | exact | GrantStoreTest |
| ISC-558..560 | live HTTP | four concurrent claims, with a four-distinct-token control | exactly one 200 | scratchpad/sprint7.php |
| ISC-561..569 | unit + live | nginx buffering, zlib, headers_sent, ETag/If-Range, fstat, abort | exact | DownloadHandlerTest + sprint7.php |
| ISC-570 | live HTTP | 2 GiB sparse volume, full download and a 2 GB-offset range | cmp(1) + 206:4096 | scratchpad/sprint7.php |
| ISC-578..581 | unit + live HTTP | hook calling convention; signature reverted as control | 500+TypeError before, 403 after | DownloadHandlerTest, curl |
| ISC-571..577 | live | harness identity, URL escaping, strict volume parsing | exact | scratchpad/sprint7.php |
| ISC-1..13 | unit | enum predicate return values against spec §6.1 table | exact true/false | PHPUnit BackupProfileTest |
| ISC-14..31 | unit | `ExclusionSet::matches()` on representative paths | exact boolean | PHPUnit BackupProfileTest |
| ISC-32..40 | unit | value-object defaults, round-trip, coercion | exact equality | PHPUnit SettingsTest |
| ISC-41..46 | unit | repository against an in-memory option-store stub | call args recorded | PHPUnit SettingsRepositoryTest |
| ISC-47 | static | ripgrep for `wpdb` under `src/` | zero matches | ripgrep |
| ISC-48 | integration | live SELECT on the options table | value `off` | live PHP via Local's socket — **discharged in Sprint 2 as ISC-148** |
| ISC-49 | unit | `method_exists` negative assertion | false | PHPUnit DomainTest |
| ISC-50 | static | byte comparison of `phpcs.xml.dist` | unchanged | shasum |
| ISC-51..53 | command | `composer lint` / `analyse` / `test` | exit 0 | shell |
| ISC-54 | command | `php -l` sweep over `src/` and `tests/` | exit 0 | shell |
| ISC-55 | file | plan contains a Sprint 1 delivered marker | present | ripgrep |
| ISC-154..161 | unit | enumerator against a fake `wpdb` recording every prepared query | exact list + exact SQL | PHPUnit TableEnumeratorTest |
| ISC-162..164 | unit + reading | dumper refuses an unlisted name; every interpolation site read | exception thrown; zero unchecked sites | PHPUnit + manual read |
| ISC-165..173 | unit | string search of the generated dump for each required clause | present / absent | PHPUnit SqlDumperTest |
| ISC-174..182 | unit | serializer output for one pathological value per case | exact string | PHPUnit RowSerializerTest |
| ISC-183..189 | integration | dump a fixture, pipe it into a scratch schema, compare every column back | byte equality | live MySQL 8.0.35 via Local's socket |
| ISC-190..197 | integration | drive the stage at a zero-second budget and at 20s; diff the outputs | identical bytes, identical row set | live MySQL + StageRunner |
| ISC-198..203 | integration | `memory_get_peak_usage(true)` around a 200k-row dump; `$wpdb->queries` count | < 64 MB; count 0 | live MySQL |
| ISC-204..208 | integration | grep the dumped options block for transient keys | zero matches | live MySQL |
| ISC-209..215 | unit + integration | `appliesTo()` per profile; full job driven to `completed` | exact boolean; status `completed` | PHPUnit + live |
| ISC-216..218 | unit | REST payload key presence and monotonicity across a stage advance | keys present; never decreasing | PHPUnit JobsControllerTest |
| ISC-219..225 | command | `composer check`, `php -l`, harness, teardown census | exit 0; zero residue | shell |
| ISC-241..253 | unit | walker over a purpose-built temp tree with real symlinks | exact yielded set | PHPUnit FileWalkerTest |
| ISC-254..276 | unit + integration | scan a fixture tree at both budgets; diff the two `files.jsonl` | byte equality; exact absence sets | PHPUnit + live PHP |
| ISC-277..286 | unit + command | archive 5,000 files under `ulimit -n 256`; `unzip -t` | exit 0 both ways; control run must fail | live PHP under a lowered descriptor limit |
| ISC-287..289 | integration | re-run a step whose cursor never persisted; list entries | zero duplicate names | live PHP |
| ISC-290..295 | unit + command | force `ZipArchive` absent through the factory seam; `unzip -t` | correct class; exit 0 | PHPUnit + shell |
| ISC-296..315 | integration | drive the stage at both budgets with a 1 MB volume cap | union equals scan; per-entry SHA-256 equals disk | live PHP + `unzip` |
| ISC-316..320 | unit + integration | registry order; a full job driven to `completed` | exact id order; status `completed` | PHPUnit + live |
| ISC-321..327 | command | `composer check`, `php -l`, harness, plan update, teardown census | exit 0; zero residue | shell |
| ISC-328..331 | unit + integration | leftover-directory pattern; base64 path round trip; a lock that refuses; find(1) diff | exact sets; zero missing, zero extra | PHPUnit + live PHP on 36,882 real files |
| ISC-332..336 | unit + integration | truncate a file below its cursor; truncate a volume below its cursor; measure the projection | throws; rebuilt from vline; listing still matches | PHPUnit ArchiveStageTest + live |
| ISC-337..339 | reading | the action fires; the spec states each limitation | present | ripgrep over src/ and the spec |
| ISC-340..352 | unit + integration | manifest key set both directions; the two copies diffed against each other | exact key list; difference is exactly `volumes` | PHPUnit ManifestTest + live read of a real archive |
| ISC-353..358 | integration | every recorded hash compared against `shasum -a 256`; sizes against `filesize()` | zero differing; peak memory growth under 64 MB on 384 MB | live PHP + shasum(1) |
| ISC-359..360 | unit | sequence walk with a deliberate gap, and two jobs' volumes side by side | stops at the gap; never another job's | PHPUnit VolumeNamingTest |
| ISC-361..367 | unit + integration | injected free-space probe forces the refusal; the control passes at one byte more | throws / does not throw; job ends `failed` | PHPUnit PreflightTest + live pipeline |
| ISC-368..372 | unit + integration | cancel a job holding a working directory and partial volumes | both gone; another job's volumes intact | PHPUnit JobManagerTest + live |
| ISC-373..376 | unit + integration | the storage root listed after a completed job; `unzip -t` on every volume | exactly volumes plus sidecar; exit 0 | PHPUnit FinalizeStageTest + live |
| ISC-377..384 | unit + integration | five completed backups swept to two, with a running job present | oldest gone, newest kept, running untouched | PHPUnit RetentionSweeperTest + live MySQL |
| ISC-392..403 | unit + control | crafted rows and symlinks against the delete paths; a volume removed from the middle; the two manifests diffed | refusal, each with a control that fails without the guard | PHPUnit VolumeNamingTest, RetentionSweeperTest, FinalizeStageTest |
| ISC-385..391 | command | `composer check`, `php -l`, shasum of `phpcs.xml.dist`, the harness, teardown census | exit 0; zero residue | shell |
| ISC-404..415 | unit | admin menu, screen-scoped enqueue, bootstrap payload shape | exact | PHPUnit AdminPageTest |
| ISC-410, ISC-411 | live | catalogue and pipeline read on a real WordPress boot | 5 profiles / 5 stages | scratchpad/sprint6.php |
| ISC-413 | live | serialised bootstrap searched for table names, with a control | zero matches | scratchpad/sprint6.php |
| ISC-416..430 | unit | list contents, availability, delete outcomes and status codes | exact | PHPUnit BackupsControllerTest |
| ISC-468 | live differential | `wc -c` over the real volumes vs `size_bytes` | byte-exact | wc(1) against the payload |
| ISC-469 | live differential | `du -sk` before and after the delete | strictly smaller | du(1) |
| ISC-431..440 | unit | clamping, partial-body merge, sanitisation, absent wp-config field | exact | PHPUnit SettingsControllerTest |
| ISC-470 | live | `get_option()` read after `PUT`, outside the repository | exact | scratchpad/sprint6.php |
| ISC-441..446 | static sweep | `src/Rest/*.php` scanned for gate, callback, capability literal | zero violations | PHPUnit AdminBoundaryTest |
| ISC-443, ISC-471, ISC-472 | live | real `WP_REST_Server` dispatch: anonymous / demoted / administrator | 401 / 403 / 200 | scratchpad/sprint6.php |
| ISC-447..461 | static sweep | client files scanned for inline styles, prefixes, restated rules | zero violations | PHPUnit AdminBoundaryTest |
| ISC-462..465 | command | `bun run build`, `bun run lint:js`, `composer check` | exit 0 | shell |
| ISC-473, ISC-474 | command + control | lint file count, then a planted violation | fails then passes | shell |
| ISC-466 | live census | tables, options, storage dirs, active_plugins after teardown | all zero | scratchpad/sprint6.php |

| ISC-590..596, ISC-599..605, ISC-607 | build | the packer re-opens its own output and asserts against the repository | exit 0, every check OK | bun run package |
| ISC-597/598 | live | extract elsewhere, resolve classes and lint in a bare PHP process | class_exists true, no syntax errors | php -r / php -l |
| ISC-604/605 | control | plant a stray dump, then desynchronise the version; both must refuse | exit 1 both times | bun tools/package.ts |
| ISC-606 | regression | run the full gate after a release build | 635 tests, exit 0 | composer check |

## Features

| name | description | satisfies | depends_on | parallelizable |
|---|---|---|---|---|
| profile-predicates | Three area predicates on `BackupProfile`, one match each | ISC-1..13, ISC-49 | — | yes |
| profile-exclusions | `defaultExclusions()` implementing spec §6.2 plus the media rule | ISC-14..31 | profile-predicates | yes |
| settings-vo | `Settings` readonly value object with defaults, coercion, round-trip | ISC-32..40 | — | yes |
| settings-repo | `SettingsRepository` with per-request cache and autoload-false writes | ISC-41..47 | settings-vo | no |
| test-doubles | In-memory option-store stub extending the Sprint 0 WordPress stubs | ISC-41..46 | — | yes |
| gates | Lint, analyse, test, `php -l`, plan update | ISC-50..55 | all | no |
| table-enumerator | Prefix-aware, multisite-aware, `esc_like`-built, filterable, deterministic order | ISC-154..161 | — | yes |
| row-serializer | One method, one value, nine rules — NULL, binary, numeric, quote, newline, non-UTF8 | ISC-174..182 | — | yes |
| sql-dumper | Header, per-table blocks, batched INSERTs, footer; allow-list boundary | ISC-162..173, ISC-198..208 | table-enumerator, row-serializer | no |
| database-stage | The resumable stage: byte-offset cursor, truncate-on-resume, budget loop | ISC-190..197, ISC-209..215 | sql-dumper | no |
| rest-progress | `stage_processed` / `stage_total` / monotonic overall on the job payload | ISC-216..218 | — | yes |
| roundtrip-harness | Live fixture, dump, pipe-import, column-by-column comparison | ISC-183..189 | database-stage | no |
| sprint3-gates | `composer check`, `php -l`, AS harness, plan update, site teardown | ISC-219..225 | all | no |
| file-walker | Generator-based `FileSource`: deterministic order, no symlinks, prune-before-descend | ISC-241..253 | — | yes |
| file-scan-stage | Resumable scan writing `files.jsonl` with a disk-backed directory queue | ISC-254..276 | file-walker | no |
| zip-writer | `ZipArchive` writer with the 200-entry reopen rule and entry-index truncation | ISC-277..286 | — | yes |
| pclzip-writer | Fallback writer over WordPress's bundled PclZip, same truncation contract | ISC-290..295 | zip-writer | yes |
| writer-factory | Selects a writer, and is the seam that lets the absence of `ext-zip` be simulated | ISC-291..292 | zip-writer, pclzip-writer | yes |
| archive-stage | Volumes, the byte-bounded step, and the entry-count resume boundary | ISC-296..315, ISC-287..289 | file-scan-stage, writer-factory | no |
| volume-naming | One place that names a job's volumes, so finalize, retention, and cancel find what the archive wrote | ISC-359, ISC-360 | — | yes |
| manifest | The provenance record, built once and written twice | ISC-340..352 | volume-naming | no |
| volume-ledger | `VolumeStore` + `VolumeRepository` — the `fdrafts_volumes` rows, finally written | ISC-353..358 | volume-naming | no |
| preflight | Writability and free-space gates with an injectable probe | ISC-361..367 | manifest | no |
| cancel | Cancellation removes the working directory *and* the partial volumes | ISC-368..372 | volume-naming | yes |
| finalize | Hash, record, write the sidecar, clean up — in that order | ISC-373..376 | volume-ledger, manifest | no |
| retention | Keep N completed backups, delete the rest, never an active one | ISC-377..384 | volume-ledger | no |
| sprint4-wiring | Stage registration order, labels, storage hard-exclusion | ISC-316..320 | archive-stage | no |
| sprint4-gates | `composer check`, `php -l`, AS harness, plan update, site teardown | ISC-321..327 | all | no |
| admin-page | Menu registration, one root div, screen-scoped asset enqueue, bootstrap payload | satisfies: [ISC-404..415, ISC-444] | depends_on: [] | parallelizable: false |
| backups-api | `GET /backups`, `DELETE /backups/{uuid}`, sidecar-backed presentation, shared removal path | satisfies: [ISC-416..430] | depends_on: [] | parallelizable: true |
| settings-api | `GET|PUT /settings`, clamping, partial-body merge, sanitisation | satisfies: [ISC-431..440] | depends_on: [] | parallelizable: true |
| rest-boundary | Controller/permission invariants asserted by a static sweep of `src/Rest/` | satisfies: [ISC-441..446] | depends_on: [backups-api, settings-api] | parallelizable: false |
| react-app | Router, profile picker, wp-config opt-in, progress, backups list, settings form | satisfies: [ISC-447..461] | depends_on: [admin-page, backups-api, settings-api] | parallelizable: false |
| build-gate | `.jsx`→`.js` rename so ESLint 10 sees the app; build and lint proven with a control | satisfies: [ISC-462..465, ISC-473, ISC-474] | depends_on: [react-app] | parallelizable: false |
| live-probe | Real WordPress bootstrap, real `WP_REST_Server` dispatch, disk measured before/after | satisfies: [ISC-466..472] | depends_on: [build-gate] | parallelizable: false |

**Sprint 7**

| name | description | satisfies | depends_on | parallelizable |
|---|---|---|---|---|
| PathGuard | one containment predicate: realpath both sides, parent-prefix match, symlinks refused | satisfies: [ISC-528..534] | depends_on: [] | parallelizable: true |
| ByteRange | parse one Range header against a size; none / satisfiable / unsatisfiable | satisfies: [ISC-512..518] | depends_on: [] | parallelizable: true |
| ResponseEmitter | injectable header/status/buffer/write sink, so headers are assertable at all | satisfies: [ISC-500..511] | depends_on: [] | parallelizable: true |
| DownloadTokenStore | hashed, single-use, user+job+volume-bound, swept, lock-guarded | satisfies: [ISC-492..499] | depends_on: [] | parallelizable: true |
| DownloadController | POST /backups/{uuid}/download-token — issues the URL and expiry | satisfies: [ISC-484..491] | depends_on: [DownloadTokenStore] | parallelizable: false |
| DownloadHandler | the admin-post gates, ledger lookup, guard, and stream | satisfies: [ISC-500..527, ISC-535] | depends_on: [PathGuard, ByteRange, ResponseEmitter, DownloadTokenStore] | parallelizable: false |
| MultisiteGate | manage_network_options on multisite, closing the §10.2 gap | satisfies: [ISC-548] | depends_on: [] | parallelizable: true |
| CancelCompareAndSwap | StageRunner refuses to advance a job whose status left running | satisfies: [ISC-549] | depends_on: [] | parallelizable: true |
| SecurityReviewPass | readme rewrite plus the §10 control-by-control walk | satisfies: [ISC-536..547, ISC-550..552] | depends_on: [DownloadHandler] | parallelizable: false |

**Release packaging**

| name | description | satisfies | depends_on | parallelizable |
|---|---|---|---|---|
| ReleasePacker | stage an allow-listed copy, install --no-dev there, zip it | satisfies: [ISC-590..596] | depends_on: [] | parallelizable: false |
| ArchiveVerifier | re-open the finished zip and diff it against the repository | satisfies: [ISC-596..603, ISC-607] | depends_on: [ReleasePacker] | parallelizable: false |
| VersionGate | refuse to build while the header, the constant and package.json disagree | satisfies: [ISC-605] | depends_on: [] | parallelizable: true |

## Decisions

### 2026-08-28 — the release archive is staged, allow-listed, and verified from the outside

**Not `git archive`.** `.gitignore` excludes `/vendor/` and `/build/` because both are reproducible
from lockfiles. That is right for the repository and fatal for a release: a zip of the tracked files
alone has no autoloader, no bundled Action Scheduler, and no admin bundle. It installs, activates,
and does nothing — the failure surfaces on someone else's site, which is the worst place to find it.

**Not `composer install --no-dev` in place.** The vendor tree a release needs is not the vendor tree
the repository needs. Installing in place would delete phpcs, phpstan and phpunit from the working
tree and silently disarm `composer check` for whoever cut the release; they would find out from CI.
The plugin is copied to `dist/staging/` and Composer runs there. ISC-606 asserts the working tree
survived, because the claim is only worth what it is measured by.

**An allow-list, not an ignore-list.** An ignore-list fails open — the day a database dump, an
`.env`, or a stray archive lands in the plugin root, an ignore-list ships it. For a plugin whose
entire job is packaging the site's secrets into a file, failing open is not an acceptable default.
`SHIPPED` names what goes; everything else has to be added deliberately. ISC-604 plants a dump and
watches the build refuse, because an allow-list nobody has seen refuse is an assumption.

**The checks read the zip, not the staging directory.** Verifying staging only proves the copy step
agreed with itself — a differential over two artefacts the same code wrote cannot see what is missing
from both. So the verifier re-opens the finished archive and compares it against the repository,
counting `src/` PHP files against a tree it did not produce (ISC-596), and the strongest probe
extracts the archive somewhere else entirely and resolves `FictionDrafts\Plugin` through its own
autoloader in a bare PHP process (ISC-597).

**Vendor checks are derived from `composer.json`.** Naming the dev packages to exclude would be a
second list to keep in step with the first, and it would go stale on the first transitive dependency.
The verifier reads `require` and asserts both directions: nothing outside it ships, and everything
inside it does (ISC-600 with ISC-601 as its control).

**Three files state the version and nothing reconciles them.** The header is what WordPress displays
and what an updater compares; `FICTION_DRAFTS_VERSION` is what the code branches on. A release built
while they disagree reports one version and behaves like another, and the mismatch stays invisible
until a migration guarded by the constant fails to run. The build refuses to start (ISC-605).

**Show your math — delegation floor.** No agent was spawned. The work is one script against one
repository whose conventions are already loaded; a delegate would have had to be told everything this
context already holds, and the verification is mechanical rather than a matter of judgement.

**Not reproducible byte-for-byte, and said so rather than implied.** Zip records modification times,
so two builds of an identical tree hash differently. Making them identical means normalising mtimes
and entry order, which is a real project and was not asked for. The printed `sha256` identifies the
artefact being shipped, and the readme says exactly that instead of letting a reader assume more.

### 2026-08-28 — readme.txt becomes README.md

The WordPress.org plugin directory parses `readme.txt` in its own format and nothing else,
so dropping it forfeits a wp.org listing until it is regenerated. Traded deliberately: this
repository is the source of truth and its front page is GitHub, where `readme.txt` renders as
an unformatted wall of text. Every claim ISC-540..545 assert was carried across verbatim and
re-verified against `README.md`, so the criteria still bind — they now name the file that
exists. A wp.org release, if it happens, generates `readme.txt` from `README.md` at package
time rather than maintaining two documents by hand.

Added `CLAUDE.md`: what the plugin is, the five invariants that are decisions rather than
preferences, the architecture map, and the three testing habits each learned from a real
defect. Its purpose is to make the next session's first hour unnecessary.

### 2026-08-28 — Sprint 6

**Delegation floor: 0 against a soft floor of 2 — show your math.** The session's operating
instruction is verbatim "Do not call the AgentTool unless the user requested it. Do not use
workflows or deep-research unless the user requested it." That outranks the Algorithm's soft
delegation floor, which is relaxable by construction. What the un-selected delegation would have
done: a Forge pass over the two new controllers looking for missed status codes, and a parallel
reader over `@wordpress/components` v40 for deprecated prop signatures. The first is replaced by
the Advisor call at the commitment boundary; the second by reading the installed type
definitions, which is a better source than a summary of them.

**The advisor round: eleven adopted, four already done, three deferred with reasons.**

Adopted, in order of how badly they would have hurt: the sidecar manifest was echoed into the
response after `json_decode`, so a file in the storage directory decided the payload's shape — now
projected through `Manifest::KEYS` with non-scalars dropped; the delete route now holds the
runner's own step lock and re-reads the job inside it; polling stops on `401`/`403` instead of
turning a stale nonce into nine hundred failing requests; timestamps are also emitted as RFC 3339;
an unmatched hash routes to the dashboard; `/wp-json` is banned from the client.

Four findings did not hold against the code. The advisor's most severe — an unguarded recursive
delete — is already stopped three times over: `sanitize_key()` on the uuid, the UUID pattern in the
route itself, and a `realpath()` containment check in `removeDirectory()`. It called
`wp_localize_script` "the single most likely breakage"; this uses `wp_add_inline_script` with
`wp_json_encode`, which is the fix it recommends. It asked whether the v5 `refetchInterval`
signature was ported correctly; it was checked against the installed type definitions at THINK. It
asked for a path check on the response; ISC-429 has asserted it since the first draft. Each was
answered by reading the code rather than by accepting the list — but two of them became tests,
because a guarantee nobody asserts is a guarantee that erodes.

**Deferred, with reasons.** *Compare-and-swap on the job status* is the complete fix for the delete
race and is the same fix Sprint 5 already deferred to Sprint 7 — one writer, no window. The lock
taken here closes the common case without pretending to be that. *Multisite scoping* is real:
`manage_options` is a per-site capability, and a network-activated install would let a subsite
administrator list and delete the main site's backups. It belongs with Sprint 7's security pass, and
it is now written into the spec's known limitations rather than left as an unstated assumption.
*JavaScript unit tests* are not in this sprint's declared gates, which name `lint:js`; the polling
terminal set is a named constant so the state machine is at least stated in one place.



**`assets/app/*.jsx` becomes `*.js`.** ESLint 10 uses flat config, and `@wordpress/scripts` 34's
`eslint.config.cjs` declares no block matching `.jsx`, so `assets/app` was globally ignored:
`bun run lint:js` exits `2` today with "all of the files matching the glob pattern are ignored".
Two fixes existed — add a project flat-config block for `.jsx`, or put the JSX in `.js` files as
every `@wordpress/*` package does. The rename was chosen because it removes a config file rather
than adding one, and because a lint gate that needs local configuration to see the code is the
kind of gate that stops seeing it again later.

**`HashRouter`, not `BrowserRouter`.** The page lives at `admin.php?page=fiction-drafts`; there is
no rewrite behind it, so a `BrowserRouter` push to `/backups` produces a 404 from WordPress on the
next reload. The hash is ugly and it is the only option that survives a refresh and a bookmark.

**The bootstrap hands down rules, not just credentials.** `wp_add_inline_script` carries the REST
root, the nonce, the profile catalogue built from `BackupProfile`, the stage labels built from
`Stage::label()`, and the §6.3 warning text. Every one of those is a rule the server already owns.
A client that restates one has created a second answer to a question that had one, and the copy
users see is the copy no PHPUnit test covers.

**A backup whose sidecar manifest is missing stays in the list.** The tempting shape is to build
the list from manifests and skip what cannot be read. That makes an unreadable backup invisible,
and an invisible backup cannot be deleted — the disk never comes back. The list is built from the
job rows, which are the thing that always exists; the manifest enriches an entry and its absence
is reported as `manifest: null`.


- **2026-08-28 — tier escalated to E4 by conversation context.** The classifier returned E3 for
  "go ahead with sprint 2", having seen the prompt in isolation. The plan grades this sprint size
  L and risk High — the engine, spanning persistence, scheduling, REST, and uninstall — and it is
  the sprint every later one depends on. Escalated per the override rule; thinking floor met at
  nine of a required six.
- **2026-08-28 — `JobStore` is an interface, and it lives in `Persistence`, not `Contracts`.**
  The resumability proof must demonstrate the *engine's* behaviour, so it runs against an
  in-memory store; a failure can then only be the runner's fault. It is not one of the four
  architectural contracts `src/Contracts/` holds, and putting it there would have broken the
  Sprint 0 test that pins that directory's shape.
- **2026-08-28 — `Scheduler` is not final.** The proof substitutes a recording subclass so
  enqueues are observable and the test can drive the step loop itself, making the step count
  deterministic. A real queue would make both unknowable.
- **2026-08-28 — `processed` resets when the stage advances.** Each stage counts in its own units
  — rows, then files, then archive entries — and a single counter that mixed them would be
  meaningless. The consequence is that the REST payload must expose per-stage figures; recorded
  as a Sprint 3 requirement so the progress bar never appears to run backwards.
- **2026-08-28 — a MySQL named lock, not an option row or a unique index.** `findActive()`
  followed by `insert()` is not atomic. An option row used as a mutex has no automatic release,
  so a fatal between acquire and release wedges the plugin until someone deletes the row by hand.
  A UNIQUE index on a generated column is the most correct answer but `dbDelta()` does not handle
  generated columns or functional indexes reliably. `GET_LOCK` releases when the connection dies,
  which is exactly the behaviour wanted after a fatal — verified across two live connections.
- **2026-08-28 — advisor review, second round; four adopted, three deferred with criteria.**
  - *Adopted:* the forward-progress guard was one-sided. It failed on unchanged-cursor **and**
    zero-processed, so a stage reporting work while never advancing its resume position would
    still loop. The test is now the cursor alone — an unfinished step that returns the cursor it
    was given is always a bug (ISC-83.1).
  - *Adopted:* fatals never reach a catch block, so `FailureHandler` now also runs a
    `register_shutdown_function` that reads `error_get_last()` and attributes an OOM or timeout
    to the job being stepped. Previously the watchdog caught it fifteen minutes later with a
    message that said only "it stopped responding".
  - *Adopted:* the single-active-job race, closed with the named lock above.
  - *Adopted:* an explicit test that a stage with legitimately nothing to do completes rather
    than being failed by the guard. The advisor predicted this as a live bug; it was not present,
    because the do-while checks its terminal condition first — but it is subtle enough that
    nothing was stopping a later refactor from breaking it (ISC-82.1).
  - *Deferred with a written criterion:* the at-least-once resume boundary over non-transactional
    file writes. The advisor is right that a crash mid-batch duplicates rows in an
    append-only dump that still looks valid, and right that no unit test can find it. It is not a
    Sprint 2 defect — there is no stage that writes a file yet — so the fix (a byte offset in the
    cursor, truncate-then-append on resume) is written into Sprint 3's brief where the first such
    stage lands.
  - *Deferred:* byte-bounded units, and persisting the resolved stage list on the job. Both
    recorded in Sprint 3's brief. The stage-list risk has a partial mitigation already: the
    runner fails loudly when a stage id no longer resolves rather than silently skipping it.
  - *Not adopted:* nothing was rejected outright this round.
- **2026-08-28 — Cato was not run, and E4 requires it.** Verification Doctrine Rule 2a makes a
  cross-vendor audit mandatory at E4. This session's operating instructions prohibit spawning
  agents unless the user asks. What the audit would have bought is a second vendor's read on the
  engine; what stood in for it was two advisor rounds and, more importantly, 54 live checks
  against a real WordPress 7.1 and MySQL 8 — which is how the reserved-word bug was found, and
  is evidence no model's opinion could have replaced.

- **2026-08-28 — `Custom` predicates default-deny.** Spec §6.1 marks every `CUSTOM` column
  "opt-in", which means the profile alone cannot answer. The predicates return `false` and the
  per-area opt-ins live in the job's `options` JSON, resolved by `Stage::appliesTo( BackupJob )`
  in Sprint 2. Returning `true` would make a half-configured custom job copy everything the
  administrator never asked for — the same failure shape §6.3 rejects for `wp-config.php`.
- **2026-08-28 — one predicate covers core, plugins, themes and root files.** Spec §6.1's table
  has four separate file columns, but they carry identical values in all five rows. Adding
  `includesPlugins()` and `includesThemes()` would create three ways to disagree about one fact.
  `includesCore()` is documented as "site files other than uploads" and the doc-block reproduces
  the table so the collapse is auditable rather than implicit.
- **2026-08-28 — explicit `add_option()` when the option is absent.** WordPress 7.1's
  `update_option()` honours a non-null autoload argument for existing options
  (`wp-includes/option.php`, the `null !== $autoload` branch), but that branch did not exist on
  6.4/6.5, which the plugin header still declares as the floor. Branching on absence is correct
  across the whole declared range and costs one `get_option()` the repository was making anyway.
- **2026-08-28 — a typed `Settings` value object, not an array.** Spec §5's file list names
  `SettingsRepository` but no `Settings` class. Sprints 4, 5 and 6 all read these values;
  an array shape shared across three sprints and a REST boundary drifts. The class is small
  and makes `fromArray()` the single coercion point.
- **2026-08-28 — `retention_count` defaults to 5.** The spec fixes no default; Sprint 5's
  acceptance criterion only exercises "keep 3". Zero would mean unbounded growth, the exact
  failure story S5-5 exists to prevent.
- **2026-08-28 — show your math on the delegation floor.** E3's soft delegation floor is 2 and
  this run used 0. The session's operating instructions prohibit spawning agents unless the user
  asks. What the un-selected delegation would have bought: a second code producer on the exclusion
  glob set, and a second reader on the autoload branch. Both were instead resolved against primary
  sources — spec §6.1/§6.2 for the globs, `wp-includes/option.php` read directly for autoload —
  which is stronger evidence than a second model's opinion would have been.

- **2026-08-28 — advisor review at the commitment boundary; three of six points adopted.**
  - *Adopted:* `._*` AppleDouble files added to the always-excluded list — a site developed on a
    Mac accumulates them in every directory (ISC-25.1).
  - *Adopted:* the export-only boundary had no anti-criterion. Nothing asserted that no restore
    path exists, that only the settings option is written, or that no post data is touched.
    `ExportOnlyBoundaryTest` now asserts all three (ISC-56..59). A boundary nobody asserts erodes.
  - *Adopted:* the deferred autoload probe was one `SELECT`; it is now three — after first save,
    after second save, and after delete-then-recreate. The second-save path is exactly where
    autoload silently regresses, and it is the path a single probe would miss.
  - *Adopted as a Sprint 2 criterion, not a Sprint 1 fix:* the advisor is right that a `Custom`
    job with nothing selected would produce an empty archive reporting success — data loss with a
    clean exit code. The fix is a guard that refuses to start such a job, and there is no job
    creation path yet to put it in. Recorded as ISC-60 against Sprint 2's `JobManager` so it
    cannot evaporate. Its alternative — making `Custom` carry a `Selection` — is what
    `Stage::appliesTo( BackupJob )` already does by reading the job's options; the architecture
    has the mechanism, it is the guard that was missing.
  - *Rejected — `includesCore()` should split into four predicates.* The argument is that core,
    plugins, themes and root files do not co-vary in general. True in general, false for the five
    profiles spec §6.1 fixes for v0.1.0, where all four columns are identical in all five rows.
    A sixth profile ("wp-content without core") would add a case and its predicate together, as
    one reviewed change. The naming objection is fair and answered in place: the doc-block
    reproduces the table and states plainly that one predicate answers for four columns. Renaming
    to `includesFiles()` would also contradict the sprint's own written acceptance criterion,
    which names `includesCore()`.
  - *Rejected — add a `version` field to the settings payload.* Spec §8 already defines
    `fiction_drafts_db_version` for migrations. Two version numbers that can disagree is the
    precise failure this sprint's other decisions were made to avoid.
  - *Already handled, advisor lacked the context:* archive self-inclusion (the storage directory is
    excluded at runtime by `FileWalker` because its 32-hex suffix is generated at activation and is
    not knowable statically — spec §6.2), glob-versus-substring matching semantics (glob, with
    ISC-30's `uploads-custom` prefix-bleed test as the adversarial case it asked for), and every
    default-exclusion path it listed except `._*`.

### Sprint 3

- **2026-08-28 — tier escalated to E4 again, for a different reason.** Sprint 2's escalation was
  about breadth: the engine touched persistence, scheduling, REST, and uninstall. This one is
  about the *failure mode*. Every mistake available in a SQL dump is silent: a mis-escaped quote,
  a NULL collapsed to an empty string, a duplicated row in a table with no primary key. The file
  still imports, the site still loads, and one value somewhere is wrong. That is the class of bug
  E4's verification depth exists for. Thinking floor met at ten of a required six.
- **2026-08-28 — `DatabaseConnection` is a new interface, and `WpdbConnection` is its only
  implementation.** The same argument that made `JobStore` an interface in Sprint 2, sharpened: the
  rules this sprint has to get exactly right are the escaping rules, and a test that needed a live
  MySQL server to check them would have been written loosely enough to miss them. Four dump
  classes now talk to an interface; one file touches the global. The live round trip verifies the
  assembly rather than standing in for the unit tests.
- **2026-08-28 — `INSERT` statements name their columns explicitly.** The positional form
  `INSERT INTO t VALUES (…)` is shorter and wrong: MySQL refuses any INSERT that supplies a value
  for a `VIRTUAL`/`STORED GENERATED` column, so one generated column anywhere on a site makes the
  whole dump unimportable. Naming the insertable columns also survives a target whose column order
  differs. Cost: a longer statement. The fixture in the live harness has a real generated column,
  and its `total` is absent from the INSERT and recomputed correctly after import.
- **2026-08-28 — batches are read `ORDER BY` the primary key when there is a single-column one.**
  `LIMIT n OFFSET m` has no guaranteed order, and this dump reads its batches from separate HTTP
  requests, minutes apart, on a site still taking writes. Without an order, "resume at offset N"
  does not mean the same thing on the second request as on the first. A composite key gives no
  single column to order by, so those tables keep the server's order and the gap is documented
  rather than papered over.
- **2026-08-28 — one INSERT statement is capped at 1 MiB, independently of the 500-row batch.**
  Not a memory measure. The host that imports the file is not the host that wrote it, and MySQL
  rejects any statement above its `max_allowed_packet` — 1 GiB on this development instance, but
  commonly 4 MiB or even 1 MiB on shared hosting. A 500-row batch of `longtext` clears all of
  those. The cap is on the statement because that is what the importing server measures.
- **2026-08-28 — the resolved table list lives in `tables.json` in the working directory.** The
  cursor addresses tables by index, so a table created mid-backup would shift every index after
  it. Persisting the list is the fix; the working directory rather than the job row is the same
  call `files.jsonl` gets in Sprint 4 — derived data of unbounded size does not belong in the
  database. This also discharges, for this stage, the "persist the resolved stage list" item
  carried in from Sprint 2's advisor review, one level down.
- **2026-08-28 — `exclude_transients` is a per-job option defaulting to true.** It follows the
  pattern the principal set for `wp-config.php`: a per-job choice in the job's `options` JSON, not
  a profile property and not a setting. Unlike `include_wp_config` it defaults to *on*, because a
  transient is a cache row with its own expiry and a copy of the site regenerates them — but an
  administrator debugging a transient problem can take one copy that keeps them.
- **2026-08-28 — `StageResult` gained an optional `total`, and `advance()` now resets it.** A stage
  is the only thing that can say how big its own work is, and asking for it separately would mean
  a second call with a second failure mode. Null means "cannot say yet", so the runner leaves a
  known total alone rather than zeroing it. `advance()` resets `total` alongside `processed`
  because rows and files are not the same unit — which is precisely why the REST payload now
  carries `stage_processed`/`stage_total` next to a whole-job figure that does not jump backwards.
- **2026-08-28 — `StorageLocator` takes an optional root.** It began as a test seam — the class is
  `final`, so there was nothing to subclass — but it is a real capability: it is the same
  relocation `FICTION_DRAFTS_STORAGE_DIR` provides, available to a caller rather than only to
  `wp-config.php`. The container still constructs it with no argument.
- **2026-08-28 — root cause at ingestion (BUILD checkpoint).** The duplicate-`INSERT` hazard does
  not enter at the write; it enters at the *resume*, where the runner hands back a cursor that
  describes fewer bytes than are on disk. Fixing it at the write — say, by making writes atomic —
  would leave three neighbouring bugs alive (a partial `fwrite`, a fatal between write and save, a
  retried Action Scheduler action). Fixing it at the resume, by truncating to the cursor's byte
  length, kills all three at once. That is why `bytes` is in the cursor rather than a checksum
  being in the file.
- **2026-08-28 — show your math: delegation floor unmet, deliberately.** E4's soft floor is two
  delegations; zero were used. Session policy forbids the Agent tool unless the principal asks,
  which rules out Cato (Rule 2a) and Forge. What Cato would have done — a cross-vendor read for
  blind spots an Anthropic-family model and its advisor would share — was substituted by 71 live
  checks against real MySQL 8.0.35, and that substitution earned its keep: the placeholder-escape
  bug in the Changelog below is invisible to every unit test and to any amount of code review,
  because the corrupted string is produced by WordPress, not by this plugin.
- **2026-08-28 — the advisor round, point by point.** One call, eleven ranked findings. Each was
  tested against the code rather than accepted or deferred.
  - *Adopted, two real bugs:* composite/absent primary keys (ISC-226..228) and the session time
    zone (ISC-230..232). Both are "imports cleanly and is still wrong" — the class asked about.
  - *Adopted as honesty, not code:* views, triggers, routines, and events are not exported, and the
    header now names them (ISC-237). Full export of them is v0.2 work, not a Sprint 3 smuggle.
  - *Adopted as fixtures:* the legacy-charset table, the composite key, the zero date, the
    leading-zero VARCHAR, the explicit `0` in an AUTO_INCREMENT column, the 2 MiB longtext, and the
    server-side per-table differential over the whole site (ISC-229, ISC-233..236, ISC-238). Its
    sharpest general point — that a byte-identical dump test compares the writer with itself — is
    correct, and ISC-238 is the answer to it.
  - *Already correct, checked rather than assumed:* the numeric-unquoted heuristic keys off the
    **column type** from `SHOW COLUMNS`, never off `is_numeric` of the value, so a VARCHAR holding
    `0123` was never at risk (ISC-235 proves it); `esc_like()` was already escaping the prefix's
    underscores (ISC-155); `AUTO_INCREMENT=n` is already preserved because `SHOW CREATE TABLE`
    emits it; `_transient_timeout_%` is already covered by the `_transient_%` pattern, so the value
    and its timeout are dropped as a pair; and the header already carried
    `NO_AUTO_VALUE_ON_ZERO` and `FOREIGN_KEY_CHECKS=0`.
  - *Accepted as inherent, and now written down:* a multi-request dump has no consistent snapshot —
    a transaction cannot span PHP requests, so `--single-transaction` has no analogue here. Spec
    §12 now says so plainly (ISC-240) instead of leaving it to be discovered.
  - *Deferred with a reason:* multisite subsite backups omit the network-global tables and would
    restore a site nobody can log into. Sprint 7 owns the multisite decision; the readme already
    declares single-site only. Building network support here would be scope the sprint did not ask
    for.
  - *One correction to the advisor:* it reported the `NO_AUTO_VALUE_ON_ZERO` and
    `FOREIGN_KEY_CHECKS` preamble as missing. It was already present and already verified live.

### 2026-08-28 — Sprint 4, THINK

**Tier.** The classifier returned E3 on the prompt in isolation. In thread context this is Sprint 4,
which the plan's own velocity table rates **L / High** — one notch above Sprint 3, which ran at E4.
Escalated to E4, `effort_source: context-override`.

**refined: the resume boundary is one idea in two units.** `DatabaseStage` rewinds `database.sql`
by byte length. A zip cannot be rewound that way: a byte-truncation back to a previously valid
length makes `ZipArchive::open()` return error 35, because the central directory is written at the
end of the file and every byte cut takes the index with it. Measured, not assumed. `ArchiveStage`
therefore counts **entries** and rewinds with `deleteIndex()`. The principle is unchanged — discard
everything the persisted cursor does not account for — only the unit differs. ISC-287.

**libzip replaces a duplicate entry name; PclZip does not.** Re-adding `a.txt` to an archive that
already holds it leaves `numFiles` at 1 on libzip 1.11.4. That is a safety net, not a mechanism:
older libzip and the PclZip fallback both duplicate. Entry-count truncation stays load-bearing, and
"no entry name appears twice" is tested against both writers. ISC-288, ISC-289, ISC-294.

**The archive cursor carries a byte offset as well as a line number.** Addressing `files.jsonl` by
line alone means re-reading the file from the top on every step — O(n) per step, O(n²) per job, on
the one file guaranteed to be large. The cursor is `{ line, offset, volume, entries }`. Spec §4's
`{ line, volume, bytes }` is corrected: `bytes` was the byte length of a zip, which is exactly the
thing that cannot be resumed from.

**Volume size is projected, never read from the open file.** `ZipArchive` does not flush until
`close()`, so `filesize()` on an open volume is stale. The running figure is the size on disk at
the last close plus the source bytes added since, plus a per-entry header allowance. Compression
only shrinks, so the projection is an upper bound: a volume may roll over slightly early, never
late.

**The rollover decision happens before the add, not after.** Otherwise the entry that triggers the
rollover is already in the old volume while the cursor says it belongs to the new one, and the
boundary test fails by exactly one file — the classic way this is got wrong.

**`CUSTOM` needs scan roots, not more exclusions.** A job with uploads on and core off cannot be
written as an exclusion list, because "every root file" is not enumerable. `FileScanStage`
therefore seeds its directory queue with the roots the job selected: the site root when the job
includes core, `wp-content/uploads` alone when it does not. `appliesTo()` becomes
`includesCore() || includesUploads()`, which also gives the right answer for `DATABASE_ONLY`.

**Show your math — delegation floor.** E4's soft floor is 2; this run uses 0. The session's
operating instruction forbids spawning agents unless the principal asks. What Forge or Cato would
have contributed here is a second read of the volume-boundary and resume logic. That is replaced by
the differential (ISC-313) and the control-bearing descriptor test (ISC-281), both of which are
executable evidence rather than a second opinion. The Advisor call at the commitment boundary
still runs — it is a CLI call, not an agent.

### 2026-08-28 — Sprint 4, VERIFY

**A design premise carried since Sprint 0 was refuted by measurement.** The spec's §6.5 rule —
"ZipArchive holds an open file descriptor for every added file until close(), so past roughly a
thousand files you hit `ulimit -n`" — is not true of libzip 1.11.4. Counting `/dev/fd` across 300
`addFile()` calls showed **zero** extra descriptors, and 5,000 files archived cleanly under
`ulimit -n 24` with reopening disabled. libzip opens each source lazily during `close()`. The rule
still earns its place, for a different reason: measured RSS growth over 60,000 entries was 22.2 MB
with the reopen and 45.0 MB without, and the unbounded figure scales linearly with entry count —
libzip's pending-entry structures live in C memory that `memory_get_usage()` cannot see. Renamed
in the code and in the spec, because the next person to read a comment about descriptors would
correctly conclude the reopen was pointless and remove something that is now load-bearing for
memory. ISC-279, ISC-281.

**refined: the volume-size projection counts the name twice.** A zip stores an entry's name in both
the local header and the central directory. A flat 200-byte allowance underestimates
`wp-content/plugins/woocommerce/packages/woocommerce-blocks/build/...` by more than half, and the
error compounds over tens of thousands of entries. Now `100 + 2 × strlen( name )`, matching what
the writer already used. ISC-334.

**The advisor named a structural gap, and it was the right one.** Every proof in this sprint was
internally consistent — the archive matches `files.jsonl`, `files.jsonl` matches itself across a
resume, the volumes union to the list. None of them could see a file that was never in the list.
Silent under-inclusion is a backup plugin's dominant failure mode, and the suite was blind to it by
construction. The answer is a set built by a different program: `find(1)` over the real site, the
same `ExclusionSet` applied to its output, diffed against the scan. **36,882 expected, 36,882
scanned, zero missing, zero extra.** ISC-331.

**Three bugs the advisor's questions found, each confirmed by measurement before it was fixed.**

1. `json_encode()` returns `false` for a path that is not valid UTF-8, and `(string) false` is an
   empty line. A `wp-content/uploads` filename inherited from a Latin-1 host would have been absent
   from `files.jsonl`, absent from the archive, and invisible to every check — because a file
   missing from the list is missing from both sides of any comparison against it. Paths now carry a
   `b` flag and travel base64; a zip entry name is a byte string, so the original name survives.
   ISC-329.
2. `ftruncate()` to a length *greater* than the file pads with NUL bytes rather than failing —
   measured: a three-byte file truncated to ten reads `61626300000000000000`. If a crash lost
   buffered writes, "truncate back to the persisted length" would have written NULs into the middle
   of `database.sql` and `files.jsonl`. Both stages now refuse a file shorter than their cursor.
   ISC-332.
3. A volume can hold fewer entries than its cursor claims — the row is fsynced by MySQL, the zip is
   page cache. `truncateTo()` would find nothing to delete, report success, and the stage would
   advance past files it never wrote. The cursor now carries `vline`/`voffset`, the line the current
   volume began at, so a short volume is rebuilt rather than skipped. ISC-333.

**And one that Sprint 4 created rather than inherited.** Through Sprint 3 two overlapping workers on
one job were merely wasteful: a repeated batch overwrote itself. Resuming an archive *mutates* it,
so from this sprint two workers both truncate to the same entry count and both add again. WP-Cron
overlapping an admin-ajax tick is routine. `StageRunner` now takes a named lock per step — a
different name from the one `JobManager` takes, so starting a backup does not block on a step of the
backup already running — and a worker that cannot take it returns, because the holder will
re-enqueue when it finishes. ISC-330.

**refined: a volume is sealed at 60,000 entries as well as at its byte cap.** PclZip writes no ZIP64
record, so past 65,535 entries the count wraps and many extractors read `count mod 65536` — a
restore that loses files with no error anywhere. The ceiling applies to both writers so the resume
logic has one behaviour rather than two.

**Not adopted, and why.** The advisor proposed following directory symlinks with a `(dev, ino)`
seen-set, so that `wp-content/uploads → /mnt/data/uploads` is copied rather than silently skipped.
The concern is right and the fix is not: the seen-set would have to live in the cursor, which is the
one place this design keeps free of unbounded data. What ships instead is that skipping is no longer
silent — `fiction_drafts/skipped_symlink` fires for every one, spec §12 says what it costs, and
Sprint 5's manifest is where the count belongs. Likewise file modes and timestamps: a zip does not
carry them, and this plugin does not restore, so §12 records it rather than the code working around
it.

**Show your math — delegation floor, again.** E4's soft floor is 2; this run used 0 agents, under
the session's standing instruction. The Advisor is a CLI call and ran at the commitment boundary,
where it earned its cost: four of the fixes above exist because of it. Cato would have been the
second reader; the set differential over 36,882 real files is what stands in for it, and unlike a
second opinion it can fail.


### Sprint 5 — VERIFY

**refined: `PrepareStage` runs third, not first.** The plan calls it preflight, which reads as
"before everything". The failure worth preventing is filling the disk, and nothing large is written
until `ArchiveStage` opens its first volume — the dump is bounded by the database and `files.jsonl`
is a list of paths. So the last safe moment to refuse is immediately before the archive, and that is
also the *first* moment a real number exists, because the scan has just finished counting bytes.
Running it first would gate on an estimate with a measurement thirty seconds away, and a gate on a
guess fires on the sites that were fine and stays quiet on the one that fills the disk. Pipeline:
`database → files → prepare → archive → finalize`.

**The manifest is written twice, and the copies are not identical.** The copy inside the archive is
added by `ArchiveStage` as entry two, before any volume is sealed, so it cannot carry volume
checksums — a file cannot contain its own hash. The copy beside the volumes is written by
`FinalizeStage` once every volume is closed and hashed. `volumes` is present as a key in both and is
an empty array in the inner one, so a reader that finds it empty knows which copy it is holding
rather than suspecting a broken file. Verified by diffing the two copies out of a real archive:
identical on all eighteen other keys.

**The final volume number is re-derived, not remembered.** `StageRunner::advance()` resets the
cursor at every stage boundary because each stage counts in its own units, so `ArchiveStage`'s
volume number is gone by the time `FinalizeStage` runs. Sprint 4 warned against rebuilding the list
by globbing the storage directory — a glob picks up other jobs' volumes and anything else shaped
like one. The resolution is a third thing: walk the job's *own* naming from sequence 1 to the first
gap. It asks the job, using the formula that produced the files, uuid fragment included.
`VolumeNaming` exists so that formula lives in exactly one place; `ArchiveStage`, `FinalizeStage`,
the retention sweep, and cancellation all call it.

**Cancellation deletes the partial volumes too.** Spec §6 says a cancelled job cleans up after
itself, and until now that meant the working directory. A half-written archive left in the storage
root is indistinguishable from a finished one to a directory listing, to the retention sweep, and to
Sprint 7's download endpoint. It goes.

**refined: the option-boundary test now separates reads from writes.** It required every option name
in `src/` to carry the plugin's prefix, which is the wrong rule stated in the right spirit: the
manifest has to record which theme and which plugins were active, and those live in core options by
definition. Conflating the two would have forced the manifest to either lie or reach around the
option API. Writes still require the prefix; reads are checked against a declared allowlist
(`stylesheet`, `active_plugins`, `home`), so a new foreign option is still a failure — it just has
to be named first.

**Found live, and fixed upstream: the runner reverted a stage's own write.** `FinalizeStage` records
the backup's total size, because sealing is the first moment every volume has been measured. The
runner loads the job at the top of a step and saves it at the bottom, and that stale copy silently
reverted the field — a completed backup reporting zero bytes while its volumes on disk summed to
101,463. Fixed in `StageRunner` rather than in the stage, because the same trap is set for every
stage that ever writes, including third-party ones: after `run()` returns, the store is the truth.
A regression test with a control confirms it fails without the re-read.

**Found by a test: `StorageLocator::ensure()` warned on an unwritable directory.** `writeGuard()`
called `file_put_contents()` unconditionally, so a storage root whose permissions had been tightened
produced a PHP warning from inside `Preflight`, whose entire purpose is to replace exactly that
warning with a sentence an administrator can act on. Fixed at the ingestion point.

**Found by a test: the in-memory job store ordered the opposite way to the real one.**
`JobRepository::all()` returns `ORDER BY id DESC`; the double returned insertion order. The
retention sweep keeps the first N, so the double would have proved the sweep deleted the newest
backups and reported a pass. The ordering is now stated on the `JobStore` interface, where it
belongs — it is part of the contract, not an implementation detail.

**The free-space probe is injectable, and that is the point.** This machine reports 236 GB free, so
no fixture will ever exhaust it and the refusal branch is unreachable without a seam. Every preflight
test pairs the refusal with a control one byte on the other side of the margin.

**What the advisor round changed.** Nine of its findings were real and are now code: containment on
every delete path with two adversarial tests that fail without it; the unchecked `ZipArchive::close()`
return, which is the only place a full disk reports itself; the sidecar read-back before the working
directory goes; the missing-volume check against the scan's file count; the failed-job disk leak; the
honest writability probe; and the retention-setting coercion cases. Four findings were already
addressed and are recorded as such: the `id`-descending total order the sweep depends on, the
`schema` version key, the two-copies diff (which existed live and now exists as a unit test), and the
cancelled-job volume removal. One is deferred with a reason: cancelling *concurrently with a running
step* is a narrow window — `StageRunner` takes a lock per step and refuses a terminal job on its next
tick, so the outcome is correct and only the disk may be freed late. Making cancel a flag the runner
acts on is a Sprint 7 change, and it is written down in §12 rather than half-done here.

**My own probe passed for the wrong reason, and the control caught it.** The first version of the
hostile-row test built a path that traversed but landed on no file, so `is_file()` was false and
nothing was deleted — it would have passed with the guard removed. Rebuilt so the crafted path
resolves to a real file two directories up, it fails without the guard and passes with it. This is
the stored `probes-need-a-control` lesson firing for the third time on this project.

**Show your math — delegation floor.** E4's soft floor is 2; this run used 0 agents, under the
session's standing instruction not to call the Agent tool unless asked. Forge would have written a
second `Preflight` and `RetentionSweeper` to diff against. What stands in for it: the Advisor at the
commitment boundary, and two differentials that can actually fail — every volume hash compared
against `shasum(1)`, and the manifest's file count and byte total compared against an independent
`find(1)` walk of the same tree.

**Tier mismatch, logged.** The classifier returned E3 for "please work on the sprint 5". The project
ISA declares E4 and the sprint touches five namespaces, two new stages, a new repository, the
scheduler, and storage cleanup. Escalated to E4 by conversation-context override.

### 2026-08-28 — Sprint 7

**The store must not be the credential.** This plugin's whole job is to put `wp_options` into a
downloadable archive. A grant stored in plaintext would be copied, still valid, into the very backup
it authorises — every archive would ship with working download links to itself, and so would any
database dump the owner takes by any other means. The store holds `hash('sha256', $token)`.

**An option, not a transient.** A transient has a TTL, which is the appealing part, and no
atomicity, which is the disqualifying one. `get_transient()` then `delete_transient()` is
read-then-write across processes, so two simultaneous replays both read the record before either
deletes it and "single-use" becomes "twice". Expiry is five lines of arithmetic; atomicity cannot be
added afterwards.

**`ResponseEmitter` is an interface so the headers can be asserted at all.** `header()`, `echo`,
`exit` and `ob_end_clean()` write where a test cannot read, and the last takes the test process with
it. Without the seam, every header criterion in this sprint would have been "the code looks right".

**`ByteRange` is its own class because inclusive-endpoint arithmetic fails by exactly one and never
says so.** `bytes=0-1023` is 1024 bytes; `bytes=100-` ends at `size - 1`. Isolated, it is provable
against real slices of a real buffer with no HTTP and no WordPress involved.

**Delegation floor relaxed — show your math.** E3's soft floor is two delegation capabilities and one
was used (the Advisor, via `Inference.ts`). The session system prompt forbids unrequested `Agent`
calls, and it sits above CLAUDE.md in the instruction hierarchy, so the Forge auto-include binding
was not exercised. What Forge would have done — an independent read of the `ByteRange` arithmetic and
the refusal ordering — was covered instead by the Advisor round and by proving both against real
slices and real HTTP.

**refined: the multisite gap became a fix rather than a note.** Spec §10.2 recorded it as known and
accepted. Sprint 7 closed it: `AbstractController::hasCapability()` is the one definition and
requires `manage_network_options` on multisite. Stricter than a single-site-activated network needs,
and the right way to be wrong for a release that does not claim multisite support.

**refined: the compare-and-swap Sprints 5 and 6 deferred is done.** `StageRunner::execute()` re-reads
the job after the stage returns and refuses to advance one whose status left `running`. A cancel is
now a flag honoured at a stage boundary rather than a race the runner usually won.

**Deferred to v0.2.0, each with a reason.** An object-cache concurrency test — no Redis on this
machine; the fix is in and unit-tested, but the test that would prove it needs a cache to be
installed. Binding `blog_id` into a grant — multisite is unsupported and the gate is already
network-admin-only. A per-user rather than global cap on stored grants — every holder of the minting
capability is an administrator of the same site. `X-Accel-Redirect` as a streaming fast path — it
would hand the bytes to nginx entirely, but it needs an `internal` location block the plugin cannot
write, so it is a documented option rather than a default.

**The token travels in the query string, and that is stated rather than hidden.** It lands in access
logs and browser history. Single-use plus five minutes bounds the exposure; an *unclaimed* token in a
log file is claimable for its TTL by anyone who can read logs. A POST body would avoid it and cannot
be used, because the download is a browser navigation.

## Changelog

### 2026-08-28 — validated the shape, not the volume

- **conjectured:** the sidecar manifest was handled correctly. Sprint 7 had already established that a file this plugin wrote is still input, and `BackupsController::project()` reduces it to `Manifest::KEYS` with `active_plugins` capped at 500 strings.
- **refuted by:** an audit sweep asking what an oversized-but-valid sidecar costs. Measured: a 47 MiB manifest peaks at 127 MiB for one `Manifest::read()`, and the list route reads one per backup, up to a hundred per request. The cap existed but ran *after* `json_decode`, which is after the allocation that matters. The result is a fatal on the only screen from which the offending backup can be deleted — the same shape as the WPML failure this site was investigating the same day, where the fatal lands on `plugins.php`.
- **learned:** validating a shape and bounding a volume are two separate obligations, and having done the first convincingly is what stops anyone asking about the second. A trust boundary is only closed when the refusal happens before the allocation.
- **criterion now:** ISC-582 requires `Manifest::read()` to refuse past `MAX_READ_BYTES`, ISC-583 is its control (a real sidecar still reads), and ISC-584 pins the boundary at the byte — the read asks for one byte past the ceiling so a file that grows after a `filesize()` cannot pass it.

### 2026-08-28 — the hook was never fired, only registered

- **conjectured:** `handle()` was covered. Forty-one tests drove it through every gate, `register()` was asserted to attach the callback, and the live harness served real bytes over real nginx — so the download path was verified end to end.
- **refuted by:** the first human click. `admin-post.php` reached `do_action( "admin_post_fiction_drafts_download" )` and PHP threw `TypeError: handle(): Argument #1 ($query) must be of type ?array, string given`. Core substitutes an argument when a hook is fired with none — `if ( empty( $arg ) ) { $arg[] = ''; }` in `wp-includes/plugin.php` — so every zero-argument action callback is handed an empty string. Callbacks that declare no parameters never notice; this one declared `?array`.
- **learned:** registration and invocation were each tested and their composition was not, and the `do_action` double hid the gap by dispatching with no arguments where core dispatches with one. A double that is kinder than the real caller certifies the shape it accepts rather than the shape production sends — the same failure as the `401`/`403` stub above, one layer further out. The live harness did not catch it either, because it called the handler through its own front controller rather than through the hook.
- **criterion now:** ISC-578 requires `handle()` to accept core's calling convention, ISC-579 fires the real action through the real `register()` and requires the archive (the control — a handler that refused everything would fail it), and ISC-580 keeps the injected-array path preferred over `$_GET`. The `do_action` double now reproduces core's empty-string substitution, so the suite would have caught this without the ISCs.

### 2026-08-28 — the lint gate was measuring nothing

- **conjectured:** the sprint's acceptance criterion "`npm run lint:js` passes" was a real gate on the client, so writing lint-clean JavaScript was the whole of the work.
- **refuted by:** running it before writing a line. It exited `2` with "all of the files matching the glob pattern `assets/app` are ignored" — ESLint 10 uses flat config and `@wordpress/scripts` 34 declares no block matching `.jsx`, so the only file in the directory was invisible to it.
- **learned:** tooling reports "no problems found" and "nothing was examined" through the same channel, so a gate stated as "command X passes" can be satisfied by a command that read zero files. The fix was to move the JSX into `.js`, as every `@wordpress/*` package does, which removes a config file rather than adding one.
- **criterion now:** ISC-473 asserts the lint reports a non-zero file count, and ISC-474 is its control — a planted violation must fail the run and its removal must pass it.

### 2026-08-28 — a file this plugin wrote is still input

- **conjectured:** the sidecar `manifest.json` is internal data, because `FinalizeStage` wrote it, so the list route could decode it and put the result in the response.
- **refuted by:** the advisor round asking what a manifest containing a `<script>` in a filename, a string where a number belongs, or ten thousand volumes would do. The answer was: reach the browser unchanged, and make the response's shape depend on a file anyone with write access to the storage directory can edit.
- **learned:** the trust boundary is the write, not the authorship. Between writing a file and reading it back there is a filesystem, a restore, a sync tool, and a year — "we wrote it" is provenance, not current contents. Projecting through a known key list is also better API design, because the response becomes a contract rather than a passthrough.
- **criterion now:** ISC-475 requires the sidecar to be projected through `Manifest::KEYS` with non-scalars dropped, and ISC-475b is the control asserting the legitimate keys still arrive.

### 2026-08-28 — 401 and 403 are different refusals

- **conjectured:** the capability gate answers `403`, as the plan's acceptance criteria say, so a flat `403` in the test stub was faithful.
- **refuted by:** dispatching through a real `WP_REST_Server`, which returned `401` for every anonymous request. `rest_authorization_required_code()` returns `401` without an identity and `403` for an identity that is insufficient.
- **learned:** the stub answered what its author expected rather than what the subject does, which is the failure mode stubs are most prone to — and the only thing that could catch it was a probe that shares no code with them. The plan's `403` was right about a subscriber and silent about an anonymous request; both are now stated.
- **criterion now:** ISC-443 covers the logged-in-but-unprivileged `403`, ISC-471 the anonymous `401`, and ISC-472 the administrator `200` that makes both mean something. The stub mirrors core's rule instead of a constant.


- **2026-08-28 — Sprint 3, "no order" was a hole, not a trade-off**
  - *conjectured:* ordering batched reads by the primary key when there is a single-column one, and
    accepting the server's order otherwise, was a reasonable trade with the gap documented.
  - *refuted by:* the advisor, in one sentence — `term_relationships` has a composite primary key
    and ships in **every** WordPress install. So "otherwise" was not an edge case; it was a core
    table on every site the plugin will ever run on. And the failure is asymmetric: a duplicated
    row in a keyed table errors loudly on import, while a *skipped* row imports in silence.
  - *learned:* the reason the original decision felt defensible is that it was framed as
    "single-column key or nothing", which is a false choice. Ordering by *all* the key's columns is
    total, indexed, and costs nothing — the composite case was never the hard one. The genuinely
    hard case is a table with no key at all, and ordering by every sortable column answers that
    too, because two rows agreeing in every column are interchangeable.
  - *criterion now:* ISC-226 and ISC-227, with ISC-228 keeping text and blob columns out of the
    fallback so the ordering cannot become the most expensive part of the dump.

- **2026-08-28 — Sprint 3, the clock that travels with the copy**
  - *conjectured:* a dump is a sequence of values, so the session's time zone is irrelevant to it.
  - *refuted by:* the advisor, and then by construction. A `timestamp` column is stored in UTC and
    converted to and from the session time zone on **every** read and write. Read in Europe/Madrid,
    imported on a host in UTC, every one of them moves by two hours — and the file imports without
    a single warning. The development instance could never have caught this on its own: it reads
    and writes on the same machine, in the same zone, so the error cancels.
  - *learned:* a round trip on one host cannot see any error that is symmetric across the two ends.
    That is a general property of round-trip tests, not a fact about time zones, and it is the same
    reason the "byte-identical dump" tests prove determinism rather than correctness.
  - *criterion now:* the stage reads every batch with the session zone set to `+00:00` and restores
    it in a `finally`; the header emits `SET TIME_ZONE='+00:00'` so the importing session agrees.
    ISC-230 reads both ends at UTC, which is the only comparison that can see a shift.

- **2026-08-28 — Sprint 3, the escape that WordPress adds and never removes**
  - *conjectured:* `$wpdb->prepare( '%s', $value )` returns the value escaped and quoted, ready to
    be written into a SQL file. Every unit test agreed, because the test double reproduces
    `mysqli_real_escape_string` faithfully.
  - *refuted by:* the live round trip. A fixture value of `100% \ backslash` came back out of the
    scratch database as `100{173be6f05fea…9c7f} \ backslash`. Since WordPress 4.8.3, `prepare()`
    rewrites every `%` in the string it returns into a 64-character random hash so that a second
    `prepare()` cannot reinterpret the sign as a placeholder. `wpdb::query()` reverses it on the
    way to the server — which is why nobody normally sees it. A dump does not go to the server.
  - *learned:* the corrupted string is produced by WordPress, not by this plugin, so no unit test
    with a hand-written `wpdb` double can see it, and no amount of reading the plugin's code can
    either. `wp_options` is full of `%` — URLs with `%20`, CSS widths, encoded serialized data —
    so this would have silently corrupted a large fraction of every real backup.
  - *criterion now:* `WpdbConnection::prepare()` returns
    `remove_placeholder_escape( $this->db->prepare( … ) )`, and ISC-184 asserts a literal `%`
    survives the round trip.

- **2026-08-28 — Sprint 3, a probe that could not fail**
  - *conjectured:* the SAVEQUERIES criterion could be tested by setting `$wpdb->save_queries = true`
    and asserting `$wpdb->queries` is empty after each batch.
  - *refuted by:* PHPStan — `Access to an undefined property wpdb::$save_queries`. This
    WordPress's `wpdb` has no such property; it reads `defined( 'SAVEQUERIES' ) && SAVEQUERIES`
    at every query. Assigning to it created a stray dynamic property, nothing was ever logged, and
    the assertion passed because there was nothing to clear.
  - *learned:* this is the same shape as Sprint 2's vacuous `SHOW CREATE TABLE` probe — an
    assertion that holds when the thing under test is absent. The fix is not just to correct the
    probe but to add a **control**: assert first that an unmanaged query *is* logged, so the
    measurement is known to be live before the real assertion runs.
  - *criterion now:* the harness calls `define( 'SAVEQUERIES', true )`, proves logging is on with
    a control query, and only then asserts ISC-201. `release()` reads the constant, which is the
    only signal `wpdb` actually offers.

- **2026-08-28 — Sprint 3, `DEFAULT_GENERATED` is not a generated column**
  - *conjectured:* generated columns could be found by testing `SHOW COLUMNS`' `Extra` field for
    the substring `GENERATED`.
  - *refuted by:* a probe of this development database, which reported ten of them — every one a
    `DEFAULT_GENERATED`, which is what MySQL writes for `DEFAULT CURRENT_TIMESTAMP`. The database
    has zero real generated columns.
  - *learned:* the substring test would have silently dropped ten ordinary timestamp columns out
    of every backup of this site. The real markers are `VIRTUAL GENERATED` and `STORED GENERATED`.
  - *criterion now:* `TableSchema::isGenerated()` matches only those two, ISC-171 has a unit test
    for each, and the live fixture carries a genuine `STORED GENERATED` column whose value is
    absent from the INSERT and recomputed correctly after import.

- **2026-08-28 — Sprint 3, a memory number that measured WordPress**
  - *conjectured:* `memory_get_peak_usage( true )` after dumping a 200,000-row table answers "does
    the dump hold the table in memory?".
  - *refuted by:* the first run — 113 MB peak, against a 64 MB criterion, for an 18 MB dump. The
    figure is process-wide and includes WordPress, WPML, and WooCommerce, all loaded before the
    measurement began.
  - *learned:* a criterion phrased as an absolute ceiling has to name what the ceiling is measured
    from, or it silently tests the harness. The claim that matters is that the dump's own footprint
    does not scale with the table.
  - *criterion now:* ISC-200 measures growth from a baseline taken immediately before the loop —
    **2 MB of growth to write an 18 MB dump of 262,144 rows**.

- **2026-08-28 — Sprint 2, the invariant**
  - *conjectured:* the spec's resumability invariant was complete as written — a stage checks
    `exhausted()` at the top of each unit of work and returns the moment it is true, and the
    runner persists the cursor and re-enqueues.
  - *refuted by:* the sprint's own headline acceptance criterion. A budget of zero seconds is
    exhausted before the first check, so the stage returns `incomplete` having processed nothing,
    with the cursor it was handed. The runner re-enqueues. The job never advances and the queue
    never empties. Written that way, the test that was supposed to prove resumability instead
    proves a livelock.
  - *learned:* "stop when the budget is exhausted" is only half a rule. The other half is
    "always make progress first". A budget bounds how much work a step does; it must never be
    able to bound it to zero, because the cost of a step is not zero. This is a property of any
    interruptible-work design, not a WordPress detail.
  - *criterion now:* ISC-74 to ISC-82 prove the interrupted and uninterrupted runs are
    byte-identical, and ISC-83 to ISC-85 prove that a stage which does violate the rule fails its
    job with a message naming it, rather than looping. Enforced at two layers because stages
    arrive through a public filter.

- **2026-08-28 — Sprint 2, the schema**
  - *conjectured:* spec §8's column names could be used verbatim, and `dbDelta()` would create
    both tables.
  - *refuted by:* the live integration run. `{prefix}fdrafts_volumes` was created and
    `{prefix}fdrafts_jobs` was not — a silent partial migration, reported by `dbDelta()` as
    success. `CURSOR` is a reserved word in MySQL 8.0 (`INFORMATION_SCHEMA.KEYWORDS`
    `RESERVED=1`) and `dbDelta()` does not quote column names.
  - *learned:* a schema is not verified by reading it. Every unit test in this project could
    have passed forever with the jobs table missing, because nothing in the unit suite touches
    MySQL — and the failure mode was silence, not an exception. The same run also exposed a
    probe of mine that passed vacuously: comparing `SHOW CREATE TABLE` before and after on a
    table that did not exist compared `null` to `null`.
  - *criterion now:* ISC-61 to ISC-67 assert the tables, indexes, and columns against the live
    database, and ISC-67 now fails rather than passes when the table is absent. The column is
    `stage_cursor`; spec §8 is corrected in place with the reason.

- **2026-08-28**
  - *conjectured:* the section 6.2 always-excluded list could be written once, with `**`-prefixed
    patterns covering both root-level and nested occurrences of `node_modules`, `.git`, and `*.log`.
  - *refuted by:* the Sprint 0 `ExclusionSet` compiles `**` to `.*`, which still requires a segment
    to consume — `**/node_modules/**` becomes `.*/node_modules(?:/.*)?` and cannot match a
    root-level `node_modules/lib/a.js`. A single-form list would have silently shipped a
    root-level `node_modules/` in every archive.
  - *learned:* `**` in this glob dialect means "one or more leading segments", not "zero or more".
    Any pattern that can occur at the WordPress root must be listed in both forms, and the
    difference is invisible until a path is actually tested at both depths.
  - *criterion now:* ISC-19 and ISC-21 assert the root-level case and ISC-20 and ISC-22 the nested
    case, as separate criteria, so one form passing can never mask the other failing.

### 2026-08-28 — the descriptor rule guards memory, not descriptors

- **conjectured:** ZipArchive holds an open descriptor per added file until `close()`, so a large
  site exhausts `ulimit -n` unless the archive is closed and reopened every 200 entries. Carried
  from spec §6.5 since Sprint 0, and stated as the single most common way a hand-rolled backup
  plugin fails.
- **refuted by:** counting `/dev/fd` around 300 `addFile()` calls on libzip 1.11.4 — 5 open
  descriptors before, 5 after, 5 after `close()`. And 5,000 files archived successfully with the
  reopen rule *disabled* under `ulimit -n 24`.
- **learned:** libzip opens each source lazily while writing the archive out. What the reopen
  actually bounds is libzip's resident pending state, which lives in C memory `memory_get_usage()`
  cannot see: RSS growth over 60,000 entries was 22.2 MB with the rule and 45.0 MB without, the
  second figure scaling linearly with entry count. A comment claiming a descriptor benefit would
  eventually get the reopen removed as pointless.
- **criterion now:** ISC-279 asserts the rule fires (nothing reaches disk mid-run without it);
  ISC-281 is its control and records the refutation rather than repeating the claim.

### 2026-08-28 — internal consistency is not coverage

- **conjectured:** hashing every archived entry against the file on disk is the differential Sprint
  3 asked Sprint 4 to build, and it proves the archive is right.
- **refuted by:** the advisor pass, then a `find(1)` walk. The hash differential compares
  `files.jsonl` to the archive and the archive to disk — a file that never entered `files.jsonl` is
  absent from every term, so the comparison is structurally incapable of noticing it. The same is
  true of "the union of the volumes equals the scan".
- **learned:** the set and the bytes are two different claims, and only the bytes were being
  checked. Under-inclusion is the failure mode a backup plugin actually has, and it needs a set
  built by a program that shares no code with the one under test.
- **criterion now:** ISC-331 — `find(1)` over the real site, the same `ExclusionSet` applied
  independently, diffed against the scan. 36,882 / 36,882, zero missing, zero extra.

### 2026-08-28 — the resume boundary is one idea in two units

- **conjectured:** Sprint 3's byte-offset resume generalises to the archive — carry the output's
  byte length in the cursor and truncate back to it before appending.
- **refuted by:** writing a two-entry zip, truncating it to the length it had at one entry, and
  reopening it: `ZipArchive::open()` returns error 35. A zip's central directory is written at the
  end of the file, so every byte cut takes the index with it.
- **learned:** the principle is unchanged — discard whatever the persisted cursor does not account
  for — but the unit is whatever the format admits. For an append-only text file that is bytes; for
  an archive it is entries, and the rewind primitive is `deleteIndex()`.
- **criterion now:** ISC-287 keeps the measurement as a test, so a future libzip cannot quietly
  change the answer; ISC-288 and ISC-289 check what it buys.


### 2026-08-28 — a stage's write does not survive the step that ran it

- **conjectured:** a stage that needs to record something on the job can simply call
  `JobStore::save()` — `FinalizeStage` writes the backup's total size, which is the first moment
  every volume has been measured.
- **refuted by:** the live pipeline. A completed backup reported `size_bytes` of zero while its two
  volumes on disk summed to 101,463 bytes. `StageRunner` loads the job at the top of a step and
  saves it again at the bottom, and that copy predates anything the stage wrote.
- **learned:** the runner's in-memory job is a snapshot, and a snapshot held across a call that can
  write is a lost update waiting to happen. The fix belongs in the runner, not the stage: after
  `run()` returns, the store is the truth, and a re-read makes that true for every stage — including
  the third-party ones that arrive through the public filter and cannot be reviewed.
- **criterion now:** ISC-356 checks the size end to end against the volumes on disk, and
  `StageRunnerTest::test_a_stage_that_writes_the_job_row_is_not_reverted` pins the behaviour with a
  control that fails when the re-read is removed.

### 2026-08-28 — a preflight belongs where the number is, not where the name suggests

- **conjectured:** preflight runs first. The plan names the stage `PrepareStage` and lists it ahead
  of the archive, and a check called "preflight" that runs fourth reads like a mistake.
- **refuted by:** working out what the check could actually know at each position. Before the scan,
  the file total is a guess; nothing large is written until `ArchiveStage` opens its first volume;
  and the scan finishes by counting exactly the bytes the gate needs. A gate placed first would fire
  on sites that were fine and stay quiet on the one that fills the disk.
- **learned:** the position of a check is set by where its input becomes true, not by where its name
  puts it in the story. "As early as possible" and "as early as it can be correct" are different
  places, and only the second one is a gate.
- **criterion now:** ISC-366 asserts the pipeline position, ISC-365 asserts the figure is measured
  from the dump and the scan rather than estimated, and ISC-361/363 pair the refusal with a control
  one byte the other side of the margin.

### 2026-08-28 — the double was kinder than the thing it doubled

- **conjectured:** the download URL was correct, because the controller built it with
  `wp_nonce_url()` — core's own helper — and every unit test asserting its shape passed.
- **refuted by:** the first live run through real WordPress. The control returned `403` with "this
  download link is no longer valid". `wp_nonce_url()` ends in `esc_html()`, so every `&` came back as
  `&amp;` and the handler received parameters named `amp;job`, `amp;volume`, `amp;token`. Every
  download, for every user, would have failed. The test double did not escape, so the code had only
  ever been proved correct against the double.
- **learned:** a test double that is kinder than the thing it doubles does not weaken a test, it
  inverts it — the suite then certifies the double. The double was corrected to escape the way core
  does, and the assertion added, so the class of bug is now visible without a live run.
- **criterion now:** ISC-573, ISC-574, ISC-575.

### 2026-08-28 — a harness that agreed with itself about the wrong site

- **conjectured:** the live harness measured this WordPress install, because it booted `wp-load.php`
  from this directory and every fixture it wrote it read back correctly.
- **refuted by:** a request through this site's own nginx that found none of those fixtures.
  `wp-boot.php` picked the *most recently modified* MySQL socket under Local's run directory. On a
  machine running three sites that was a neighbour's database. Every process the harness started made
  the same mistake in the same way, so the run was internally consistent and externally meaningless —
  `home_url()` named a site nobody was testing.
- **learned:** "the fixtures I wrote are the fixtures I read" is a statement about one program, not
  about the system. An identity — the nginx config whose `root` is this install — cannot silently
  pick a neighbour the way a heuristic can. Sprint 6's 71 checks were re-run on the corrected
  database and still pass, so what was wrong was the claim, not the code.
- **criterion now:** ISC-571, ISC-572.

### 2026-08-28 — a lock that refused the requests it existed to protect

- **conjectured:** `GET_LOCK( name, 0 )` was right for the grant store, because it is what the job
  locks use and because failing immediately is better than hanging.
- **refuted by:** the control on the concurrency test. Four simultaneous clients holding four
  *different* tokens produced three `200`s and one `403` — a legitimate download refused because
  another legitimate download was in the same millisecond. The single-token race passed either way,
  which is exactly why the control had to exist: one `200` and three `403`s looks identical whether
  the claim is atomic or whether three requests failed for some other reason.
- **learned:** a lock's wait belongs to the caller, not to the lock. "A backup is already running"
  wants zero and a `409`. "Claim this token" wants a few seconds, because its critical section is
  microseconds and everyone contending for it is legitimate.
- **criterion now:** ISC-557, ISC-558, ISC-559.

## Verification

### Sprint 6 - the admin page and the REST surface

```
composer check                exit=0
PHPUnit                       OK - Tests: 489, Assertions: 2001, Skipped: 2
PHPStan level 6               [OK] No errors
phpcs.xml.dist                unchanged since Sprint 2
bun run build                 index.js 68.1 KiB, index.css 1.65 KiB, index.asset.php emitted
bun run lint:js               exit=0  (exit=2 before this sprint - nothing was linted)
scratchpad/sprint6.php        === 64 passed, 0 failed ===
```

ISC-467: `rest_get_server()->get_routes()` on a real boot - 6 routes under `/fiction-drafts/v1`,
including both uuid-bound routes with the full pattern.

ISC-471 / ISC-443 / ISC-472: anonymous returns `401` on `/backups`, `/settings`, `/jobs`; the same
three with `manage_options` filtered off the current user return `403`; the identical requests as
administrator return `200`. The third line is the control - without it the first six are equally
consistent with routes that do not exist.

ISC-468: `wc -c` over the three real volumes reports 245,760 bytes; `size_bytes` in the payload
reports 245,760. The expected figure comes from a program that shares no code with the subject.

ISC-469: `du -sk` on the storage root, 216 KiB before the delete and 172 KiB after.

ISC-470: after `PUT /settings`, `get_option( 'fiction_drafts_settings' )` read directly returned
`default_profile: files_no_media`, `max_volume_bytes: 10485760`, `retention_count: 7`. The 1,024
sent for `max_volume_bytes` came back as the 10,485,760 floor, in the response as well as in the row.

ISC-411: the five labels read off the registered pipeline -
`Exporting the database / Scanning files / Checking there is room / Building the archive / Finishing up`.

ISC-413: the serialised bootstrap searched for the four table names - zero matches, with a control
asserting the search can find a table name when one is present. The first version of this probe
searched for the bare prefix and failed on `include_wp_config`, which is a probe failing for the
wrong reason rather than a leak.

ISC-473 / ISC-474: `bun run lint:js` exited `2` at OBSERVE with "all of the files matching the glob
pattern assets/app are ignored". After the `.jsx` to `.js` rename it reported 57 real problems, then
`0`. Control: a planted unused constant produced `is assigned a value but never used` and exit `1`;
removing it restored exit `0`.

ISC-441..446 control: replacing one route`s permission callback with the always-true one made
`AdminBoundaryTest` fail with 2 failures; restoring it returned 11 passing tests.

ISC-466: census after teardown - 0 `fdrafts_` tables, 0 `fiction_drafts%` options, 0 storage
directories under `wp-content`, scratch root removed, `fiction-drafts/fiction-drafts.php` absent
from `active_plugins`.

ISC-475: a sidecar carrying `evil: "<script>"`, a nested `site_url`, and a 5,000-entry `volumes`
array was written beside a real backup. The response carried neither `evil` nor `volumes`, reported
`site_url: null`, and still returned `file_count: 11` and `active_plugins: ["x/x.php"]` — the
control that makes the three rejections mean projection rather than a failed read.

ISC-476: `created_at_iso` came back as `2026-08-28T20:27:16+00:00`.

ISC-477: `rest_url( 'fiction-drafts/v1/' )` and `bootstrap.restUrl` compared byte for byte —
`http://atreveteacreer.local/wp-json/fiction-drafts/v1/`. An earlier version of this probe was
written with a trailing `|| true` and passed unconditionally; it was rewritten rather than kept.

ISC-483: a job row with uuid `../../../../../../tmp` deleted through the route left a control file
one directory above the storage root untouched.

Final live run: `=== 71 passed, 0 failed ===`.


- ISC-1..13: PHPUnit `BackupProfileTest` — `profileMatrix` data provider reproduces the section 6.1
  table; three tests assert one column each across all five rows. All pass.
- ISC-14..31: PHPUnit `BackupProfileTest` — 13-path `alwaysExcludedPaths` provider run against all
  five profiles, plus the derived-uploads-rule test. All pass.
- ISC-32..40: PHPUnit `SettingsTest` — defaults, round-trip, and four coercion cases. All pass.
- ISC-41..46: PHPUnit `SettingsRepositoryTest` — asserts against the recorded `add_option` /
  `update_option` argument lists, not just the returned value. All pass.
- ISC-47: `grep -rn "wpdb" src/ | wc -l` → `0`.
- ISC-48: **`[DEFERRED-VERIFY]`** — requires the Local site's MySQL, which is not running
  ("Error establishing a database connection"). **Follow-up: FD-S2-001** — run at the start of
  Sprint 2, when `Migrator::run()` needs a live database regardless. Probe —
  `wp db query "SELECT autoload FROM wp_options WHERE option_name='fiction_drafts_settings'"` —
  run at **three** points, not one: after the first save, after a second save that changes the
  value, and after a delete-then-recreate. The second-save path is where autoload silently
  regresses, and a single probe would miss it. Expected `off` on WP 6.6+, `no` on 6.4/6.5; note
  that 6.6+ may also auto-determine not-autoload above a size threshold, so a pass must be read as
  "not autoloaded", not as "our argument was honoured". Unit coverage (ISC-43, ISC-44) asserts both
  write paths pass `autoload = false`; what is unverified is WordPress honouring it, not the plugin
  requesting it.
- ISC-49: `method_exists( BackupProfile::class, 'includesWpConfig' )` → `bool(false)`. Declared
  methods: `cases, defaultExclusions, from, includesCore, includesDatabase, includesUploads, slug,
  tryFrom`. The one textual match is a doc-block sentence at line 13 recording the decision.
- ISC-50: `shasum phpcs.xml.dist` → `7dea85a220ced7bff050158a3e5e7c4da10c87fe`, byte-identical to
  the pre-sprint baseline.
- ISC-51: `vendor/bin/phpcs --standard=phpcs.xml.dist` → exit 0, no output.
- ISC-52: `vendor/bin/phpstan analyse --level 6` → `[OK] No errors`.
- ISC-53: `composer test` → `OK (117 tests, 262 assertions)`, up from Sprint 0's 51 / 114.
- ISC-54: `php -l` over every file in `src/` and `tests/` → no syntax failures.
- ISC-55: `development-plan.md` Sprint 1 heading carries the delivered marker and scope note.
- Regression: `php tests/Harness/action-scheduler-standalone.php` re-run after `Plugin.php` gained
  a provider → `PASS`, `registered AS versions: 4.1.0`, `Plugin booted: yes`.

### Sprint 2

- **ISC-74..82 — the proof, measured:** `zero-second budget : 1000 steps, 1000 stage runs,
  status=completed, sha=8db91b2ee25d5794` / `20-second budget : 1 steps, 1 stage runs,
  status=completed, sha=8db91b2ee25d5794` / `outputs identical : YES`. PHPUnit
  `StageRunnerTest` asserts each of these separately, including `range( 0, 999 )` exactly.
- ISC-83..85, ISC-83.1: `StageRunnerTest` — a stage returning an unchanged cursor fails its job
  after exactly one step, with the stage id in the message, whether it reports 0 processed or 50.
- ISC-82.1: a zero-unit stage → `status=completed error=NULL`, not failed by the guard.
- ISC-61..73, ISC-100..135: **54 live checks against WordPress 7.1 and MySQL 8.0.35 on this Local
  instance, 0 failures** (`scratchpad/integration.php`). Includes REST status codes taken from
  real `rest_do_request()` calls — 202, 409, 422, 404, and 401 for an anonymous request.
- ISC-67: the idempotency probe was itself corrected mid-run. It compared `SHOW CREATE TABLE`
  before and after, which returns `null` for a missing table — so it passed vacuously while the
  jobs table did not exist. It now fails when the table is absent.
- ISC-141: `(Action Scheduler tables present: 4)` before uninstall; `EVERY actionscheduler_ table
  survived [4/4]` after, with both `fdrafts_` tables dropped in the same run.
- ISC-151: `first acquire: granted` / `other connection while held: refused (correct)` /
  `after release: granted (correct)` — two live MySQL connections.
- ISC-150 (Sprint 0's deferred criterion, discharged): with both plugins active —
  `registered AS versions: 4.1.0, 3.9.3, 4.0.0`, `latest_version(): 4.1.0`,
  `FictionDrafts booted: yes`, `WooCommerce class: yes`, no fatal. The winning copy is ours.
- ISC-144..147: `composer check` exit 0 — `OK (191 tests, 590 assertions)` and PHPStan level 6
  `[OK] No errors`. `phpcs.xml.dist` unchanged at `7dea85a2…`: every suppression added this
  sprint is an inline `phpcs:ignore` carrying its reason, not a config-level exclusion.
- **The site was left as found:** `FD active: no | fdrafts tables: 0 | fd options: 0 |
  AS tables: 4 | storage dirs: 0`, WooCommerce still active.

### Sprint 3

- **ISC-183..189 — the round trip, measured.** A fixture table holding a NULL, a `'`, a newline and
  tab, an emoji plus 汉字, a random 32-byte blob, an empty blob, and raw non-UTF-8 bytes in a text
  column was dumped, piped into a scratch schema on **MySQL 8.0.35**, and read back:
  `every column matches byte for byte` · `a NULL came back as NULL, not an empty string` ·
  `an empty blob came back empty, not NULL` · `the blob SHA-256 matches [25e283d1b3d40b94]` ·
  `the emoji survives as utf8mb4` · `a value containing a literal % survives` ·
  `every column definition is identical after the round trip [[]]`.
- **ISC-187 — the import path.** Piped on stdin, never `mysql -e "SOURCE …"`: that form is broken
  on MySQL client 9.x for every dump regardless of content, which is recorded in
  `MEMORY/KNOWLEDGE/Ideas/wp-cli-db-import-mysql9-source.md` and was found by ContextSearch at
  OBSERVE, before the harness was written.
- **ISC-188 — a whole site.** The 85-table dump of this instance imported with
  `exit 0`, `no warnings`, `85 of 85` tables present, and `posts row count 337 vs 337`.
- **ISC-190..197 — resume, at byte granularity.** `zero-second budget: 11 steps, completed` /
  `20-second budget: 1 step, completed` / `byte-identical, sha 0dc041e40e467ccd`. Then the test
  Sprint 2 could not run: a partial `INSERT INTO … VALUES (99,'half-writ` appended after the last
  persisted cursor. On resume, `the persisted prefix survives` · `the unaccounted-for tail is
  discarded` · **`a dump resumed after a partial write equals a clean one — sha 0dc041e40e467ccd`**.
- **ISC-198..203 — memory.** 262,144 rows, 18 MB written: `baseline 111 MB, peak 113 MB,
  growth 2 MB`. The query log check ran with a control first —
  `with SAVEQUERIES on, an unmanaged query IS logged [1 entries]`, then `cleared after each
  batch [0 / 0]`.
- **ISC-204..208 — transients.** Against the real `wp_options` (58 transient rows present):
  `no _transient_ row in the dumped options table` · `no _site_transient_ row either` ·
  `transients are present when the job keeps them` · `the exclusion did not lose ordinary rows`.
- **ISC-164 — the allow-list boundary, audited by reading.** Six sites in `src/Database/`
  interpolate an identifier into SQL; all six go through `quoteIdentifier()`, and every public
  entry point calls `assertAllowed()` first. Two greps for a variable inside a quoted SQL literal
  return nothing: `(none: no variable is interpolated inside a single-quoted SQL literal)` and the
  same for double-quoted.
- **ISC-209..215 — wiring.** Through the real composition root:
  `booting the plugin registers DatabaseStage on fiction_drafts/stages [database]`, a
  `DATABASE_ONLY` job resolves a one-stage pipeline, a `FILES_ONLY` job resolves none.
- **ISC-216..218 — REST.** `stage_processed 40` · `stage_total 100` · `overall_percent 40` ·
  `processed`/`total` unchanged in meaning · a completed job reads `100` · no filesystem path in
  the payload.
- **ISC-219..224 — gates.** `composer check` exit 0 — `OK (258 tests, 1006 assertions)` and
  PHPStan level 6 `[OK] No errors`. `php -l` clean across `src/` and `tests/`. The Action Scheduler
  standalone harness still reports `PASS: Action Scheduler is available with no other plugin
  loaded.` `phpcs.xml.dist` unchanged at `7dea85a220ced7bff050158a3e5e7c4da10c87fe` — every
  suppression added this sprint is an inline `phpcs:ignore`/`phpcs:disable` carrying its reason.
- **ISC-225 — the site was left as found.** `no fixture tables left` · `no scratch schema left` ·
  `no fdrafts_ tables left` · `no fiction_drafts_ options left` · `no temp directory left` ·
  `the four actionscheduler_ tables are untouched [4]`.
- **Totals: 258 unit tests / 1,006 assertions, and 71 live checks — 71 passed, 0 failed.**

### Sprint 3 — the advisor round

- **ISC-226..228 — ordering.** `batches of a composite-key table are ordered by the whole key
  [SELECT … FROM \`fdfx_composite\` ORDER BY \`object_id\` ASC, \`term_taxonomy_id\` ASC LIMIT 10
  OFFSET 0]` and `a table with no key at all is ordered by every sortable column
  [SELECT \`a\`,\`b\` FROM \`fdfx_nopk\` ORDER BY \`a\` ASC, \`b\` ASC LIMIT 10 OFFSET 0]`.
- **ISC-229 — the legacy-charset case.** A `latin1` table holding `0xC3A9` — UTF-8 bytes stored
  through a latin1 connection, the classic double encoding — came back as
  `C3A9,E9,4CE9616F`, compared with `HEX()` on both sides so that no connection charset could
  launder the difference.
- **ISC-230..232 — time zones.** `a timestamp column does not shift by the host time zone
  [2026-08-28 19:45:00 | 2000-01-01 05:59:59]`, both ends read at UTC — the only comparison that
  can see a shift, since reading both at the local zone would hide one.
- **ISC-233..236 — the value cases.** `an explicit 0 in an AUTO_INCREMENT column is not renumbered
  [0]` · `a zero date survives the import [0000-00-00 00:00:00]` · `a VARCHAR holding 0123 keeps
  its leading zero` · `a VARCHAR holding +44 keeps its sign` · `a 2 MiB longtext survives whole
  [body length 2097152]`.
- **ISC-237 — honesty about what is missing.** With a trigger on a fixture table, the header reads
  `-- NOT included in this export: 1 trigger.`
- **ISC-238 — the differential.** Every table of this site, hashed server-side as an ordered
  `SHA2` over `HEX()` of every column, before and after a full dump-and-restore:
  **`47 tables hashed, 38 empty, differing: none`**. This is the test that does not compare the
  dumper against itself.
- **Final gates after the advisor round:** `composer check` exit 0 — `OK (263 tests, 1013
  assertions)`, PHPStan level 6 `[OK] No errors`, `php -l` clean, Action Scheduler harness
  `PASS`, `phpcs.xml.dist` still `7dea85a220ced7bff050158a3e5e7c4da10c87fe`.
- **Live total: 86 passed, 0 failed.** Site left as found on every census line.

### Sprint 4 — gates and unit suite (2026-08-28)

```
composer check                     CHECK_EXIT=0
                                   OK (341 tests, 1388 assertions, 2 skipped)
php -l over src/ and tests/        LINT_CLEAN
phcs.xml.dist                      7dea85a220ced7bff050158a3e5e7c4da10c87fe  (unchanged since Sprint 2)
Action Scheduler standalone        PASS: available with no other plugin loaded
export-only boundary               no extractTo / extract / DROP DATABASE / restore in the new code
```

The two skips are honest: PclZip needs a WordPress install (covered live), and APFS refuses to
create a filename that is not valid UTF-8, so the encode half of ISC-329 is a Linux case. Its decode
half runs everywhere.

### Sprint 4 — live, against WordPress 7.1 and this site's real 210,768-file tree (2026-08-28)

```
=== 36 passed, 0 failed ===
```

The ones that carry the sprint:

```
ISC-331  the scan set equals an independent find(1) walk — 36882 expected, 36882 scanned,
         missing 0, extra 0
ISC-313  every entry hashes identically to the file on disk — 3080 files compared, differing: none
ISC-307  the union of entries across every volume equals the scan exactly — 3081 entries, 89 volumes
ISC-302  a zero-second budget produces the same listing as a full one — 3082 steps vs 2
ISC-288  a step repeated because its cursor never persisted adds nothing twice
ISC-286  every volume passes unzip -t — 89 volumes
ISC-241  memory stays flat across a 200k-entry tree — RSS growth 4.7 MB
ISC-317  a FULL job runs all three stages to completed — status completed after 4 steps
ISC-249  control: the decoy is real, and no pattern would have excluded it —
         wp-content/fd-store-269bb8ab5999/decoy-part01.zip
```

The descriptor measurement, kept because it refuted the premise it was written to confirm:

```
ulimit=256 reopenEvery=200 files=5000 -> ok
ulimit=256 reopenEvery=0   files=5000 -> ok        <- the control did not fail
ulimit=24  reopenEvery=0   files=3000 -> ok
open fds before 300 adds: 5 | after: 5 | after close: 5

reopenEvery=200 files=60000  RSS growth during adds: 22.2 MB
reopenEvery=0   files=60000  RSS growth during adds: 45.0 MB
```

### Sprint 4 — the site, left as found

```
FD active: no | WooCommerce: inactive | fdrafts tables: 0 | AS tables: 4 | fd options: 0
stray dirs: 0 | wp-content volumes: 0
```

The plugin was never activated this sprint — every run went through a CLI-bootstrapped WordPress
with the storage root pointed at a temp directory. The two decoy directories created under
`wp-content` to test the storage exclusion were removed inside the run that made them.

### Sprint 5 — manifest, checksums, preflight, retention

```
composer check     exit=0
PHPUnit            OK — Tests: 425, Assertions: 1624, Skipped: 2
PHPStan level 6    [OK] No errors
phpcs.xml.dist     7dea85a220ced7bff050158a3e5e7c4da10c87fe   (unchanged since Sprint 2)
Live harness       === 51 passed, 0 failed ===
```

ISC-353: `shasum -a 256` on every volume, compared against the `fdrafts_volumes` rows written by
`FinalizeStage` — *"2 volumes compared, differing: none"*. The hash is checked by a program that
shares no code with the one that wrote it.

ISC-346/347: the manifest's counts against an independent `find(1)` walk of the same tree —
*"find 59, manifest 59"* and *"find 238628, manifest 238628"*. This is Sprint 4's lesson applied
before it could bite: a count compared only against `files.jsonl` cannot see a file missing from
both.

ISC-352: the manifest extracted from inside a real archive, diffed against the sidecar beside it —
*"the two manifests differ by exactly the volumes array"*, identical on the other eighteen keys, and
the inner `volumes` is `[]` rather than absent.

ISC-356: *"the job size is the sum of its volumes — 101756 bytes"*. This failed on the first live
run at 101,463 bytes against a stored zero, which is how the runner's lost update was found.

ISC-357: *"file 384 MB, peak grew 0.0 MB"* — `hash_file()` streams, so a checksum per volume is
affordable inside one PHP-FPM request.

ISC-361/363: *"a job with no room ends failed rather than filling the disk — failed"*, message
*"This backup needs about 279,6 KB of free disk space and only 1,0 KB is available…"*, with the
control being the identical pipeline that completed when the probe was not clamped.

ISC-377/380/381: five completed backups swept to two against real files and real rows — the oldest
volume, its sidecar, and its rows in both tables gone; *"the two kept are the newest two"*; and
*"the running job survived the sweep"*.

ISC-392..403: added after the advisor round, each paired with a control run by removing the guard and
re-running — `test_a_hostile_job_row_cannot_name_a_file_outside_the_storage_root` and
`test_a_symlinked_volume_is_refused_rather_than_followed` both fail without `removeContained()`;
`test_a_stage_that_writes_the_job_row_is_not_reverted` fails without the runner's re-read.

Final: `composer check` exit 0 — **435 tests, 1,648 assertions**, PHPStan level 6 clean. Live harness
**51 passed, 0 failed**.

ISC-390: *"no fdrafts_ tables remain"*, *"0 options"*, *"no storage directory under wp-content"*,
*"the plugin was never activated"*. The tables were created, written to, and dropped inside the run
that needed them.

### Sprint 7 — measured 2026-08-28

```
composer check                exit=0
PHPUnit                       OK — Tests: 619, Assertions: 2420, Skipped: 2
PHPStan level 6               [OK] No errors
bun run lint:js               exit=0
bun run build                 exit=0 — index.js 70 KiB, index.css 1.77 KiB
Live harness (sprint7.php)    === 101 passed, 0 failed ===
Live harness (sprint6.php)    === 71 passed, 0 failed ===   re-run on the corrected database
Census                        0 fdrafts_ tables, 0 fiction_drafts options, 0 storage dirs,
                              0 leftover probe files, plugin never activated
```

ISC-500..505: real HTTP through this site's own nginx on `127.0.0.1:10010`.
`Content-Type: application/zip`, `Content-Disposition: attachment; filename="fiction-drafts-2026-08-28-…-part01.zip"`,
`Content-Length: 8512064`, `X-Content-Type-Options: nosniff`, `Accept-Ranges: bytes`, and
`cmp -s` reporting the streamed body identical to the volume on disk.

ISC-512..518: `Range: bytes=1048576-` → `206` with `Content-Range: bytes 1048576-8512063/8512064`
and `Content-Length: 7463488`; `bytes=0-1023` → the first 1024 bytes; `bytes=-1024` → the last 1024;
`bytes=8512074-` → `416` with `Content-Range: bytes */8512064` and no body; `chapters=1-2` → `200`.

ISC-558/559: `statuses 200,403,403,403` for one token across four simultaneous clients, and
`statuses 200,200,200,200` for four distinct tokens across four simultaneous clients.

ISC-570: 2,147,483,648 bytes downloaded in 2.85 s, `cmp` clean, and `Range: bytes=2147479552-`
returning `206:4096`.

ISC-546: `HTTP 200` for `http://127.0.0.1:10010/wp-content/fiction-drafts-probe-…/probe.zip` with
`Require all denied` in a `.htaccess` beside it. This is the measurement the readme's nginx paragraph
describes.

ISC-492: the option row is 284 bytes, contains `hash('sha256', $token)`, does not contain the token,
and `autoload=off` — read straight out of `wp_options` with `$wpdb`.

ISC-590..603, ISC-607: `bun run package` — 30 checks OK, exit 0. `fiction-drafts-0.1.0.zip`,
0.4 MB across 243 entries, `sha256 e986221b…`. Named checks include "exactly one top-level directory,
named fiction-drafts", "contains vendor/woocommerce/action-scheduler/action-scheduler.php",
"vendor/ holds production packages only", "all 76 src/ PHP files are present", and
"the packaged header declares version 0.1.0".

ISC-597: extracted to a scratch directory outside the repository, then
`php -r 'require .../vendor/autoload.php; class_exists(...)'` → `FictionDrafts\Plugin resolved`,
`FictionDrafts\Download\DownloadHandler resolved`, `FictionDrafts\Backup\StageRunner resolved`,
`FictionDrafts\Rest\BackupsController resolved`, `Psr\Container\ContainerInterface resolved`.

ISC-598: `php -l` across all 79 shipped non-vendor PHP files — no output, no syntax errors.

ISC-604: `printf 'x' > src/leaked-dump.sql` then a build →
`FAIL no database dumps (found src/leaked-dump.sql)` … `The archive … is NOT fit to release`,
exit code 1. File removed; the next clean build exits 0.

ISC-605: `package.json` set to `0.1.1` while the header and constant stayed `0.1.0` →
`Aborted: Version mismatch - refusing to build.` with all three values printed, exit code 1,
and nothing staged.

ISC-606: `composer check` after a full release build — phpcs clean, phpstan `[OK] No errors`,
`Tests: 635, Assertions: 2460, Skipped: 2`, exit code 0.
