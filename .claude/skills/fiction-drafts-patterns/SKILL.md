---
name: fiction-drafts-patterns
description: The code patterns, invariants, and review checklist for the Fiction Drafts WordPress plugin. Use when writing, reviewing, or refactoring any PHP or JavaScript in this repository, and before opening a pull request.
---

# Fiction Drafts — code patterns

Rules with a reason. Each one is here because breaking it produced a real defect in
this codebase, and the failure mode is named so you can recognise it before it ships.

Read `CLAUDE.md` first for what the plugin is and the five invariants. This file is
about *how* the code is written.

---

## 1. One decision, one place

The rule is not "avoid duplication" — it is **anything two callers must agree on gets
exactly one definition**. Duplication is only a symptom; the disease is two answers to
one question, drifting apart silently.

Ask of every constant and every formula: *if these two copies disagreed, would anything
fail loudly?* If the answer is no, it must not be two copies.

**Found in this codebase, all four silent:**

| Duplication | What divergence would have done |
|---|---|
| `ENTRY_OVERHEAD_BYTES = 100` and its arithmetic, in `ZipWriter`, `PclZipWriter`, `ArchiveStage` | Volumes overshoot their configured maximum, or roll over early. Both look like working backups. |
| `UUID_PATTERN` regex in three controllers | One gets relaxed; that route accepts strings the others reject. |
| `new VolumeNaming( $this->storage->baseDir() )` in seven files | Every caller knows how naming is constructed, so changing what it needs is a seven-file edit. |
| `manage_options` in `AdminPage`, `hasCapability()` in `AbstractController` | On multisite: a visible menu whose every request is refused. |

**Do this instead**

```php
// A shared calculation gets a name and a home.
EntryFootprint::of( $sourceBytes, $entryName )

// A shared construction gets a factory, so callers ask rather than assemble.
VolumeNaming::forStorage( $this->storage )

// A shared decision gets an accessor — and one for callers that need the raw name.
AbstractController::hasCapability()   // bool, for gates
AbstractController::capability()      // string, for add_menu_page()
```

**Not duplication:** two literals that happen to match today but answer different
questions. `BackupsController` returns `stage_processed` alongside `processed` on
purpose. Deduplicating those would couple things that are only coincidentally equal.

## 2. Untrusted input has a shape *and* a size

Anything not computed in this request is input: a file this plugin wrote last week, a
database row, a header, a `$_GET` key. Validating its shape while leaving its volume
unbounded is half a trust boundary.

`BackupsController` projected the sidecar manifest through `Manifest::KEYS` and capped
`active_plugins` at 500 strings — correctly. But the cap ran *after* `json_decode`. A
47 MiB sidecar peaks at 127 MiB for one read, and the list route reads one per backup:
a fatal on the only screen from which that backup can be deleted.

```php
// Bound the read before allocating, and ask for one byte past the ceiling so a
// file that grows after a filesize() call cannot get past it.
$raw = file_get_contents( $path, false, null, 0, self::MAX_READ_BYTES + 1 );

if ( false === $raw || strlen( $raw ) > self::MAX_READ_BYTES ) {
    return null;
}
```

Checklist for any read of something you did not just compute: **bounded length**,
**validated type**, **projected shape**, **refusal that is not a fatal**.

## 3. Read once per page, not once per row

Every list endpoint is an N+1 waiting to happen, because the per-item method is the
one that reads well.

```php
// No: one query per backup, and per_page goes to 100.
array_map( fn ( $job ) => $this->present( $job ), $jobs );

// Yes: one read for the page, passed down.
$ledger = $this->volumes->allForMany( $jobs );
array_map( fn ( $job ) => $this->present( $job, $ledger[ $job->uuid ] ?? [] ), $jobs );
```

A batch method must return **every key asked for**, mapped to an empty value when there
is nothing — so callers never distinguish "no rows" from "not asked".

Assert the *count of reads*, not just the result. A batch method that loops internally
satisfies the interface and keeps the N+1; only a counter catches that.

## 4. A test double must not be kinder than the real thing

The most expensive class of bug in this repository. A lenient double makes the suite
certify the double.

- `do_action` dispatched with no arguments where core substitutes `''`
  (`if ( empty( $arg ) ) { $arg[] = ''; }`). `DownloadHandler::handle()` declared
  `?array`, passed 41 tests, and threw a `TypeError` on the first real click.
- A REST stub returned a flat `403` where core returns `401` for anonymous requests.

When you stub a core function, **read core's implementation** and mirror its quirks. If
a behaviour is hard to reach from a test — a stale object-cache read, a lost race —
teach the double to reproduce it rather than leaving that branch unreachable:

```php
// A read that misses a row which does exist: what an object cache does under
// concurrency. Without it, the lost-update branch cannot be tested at all.
$GLOBALS['fiction_drafts_test_option_misses'][ $option ] = 1;
```

## 5. Registration plus behaviour does not equal wiring

Testing that a callback is attached, and testing what it does when you call it, still
leaves the framework calling it unverified. For anything reached through a hook, route,
scheduler, or CLI dispatcher, write one test that goes through the **real registration
and the real dispatcher, with the arguments the framework really supplies**.

```php
$this->handler()->register();
do_action( 'admin_post_' . DownloadHandler::ACTION );   // no args, exactly as core
```

## 6. A probe needs a control

A check that also passes when the feature is absent is not a check.

Before believing a new test: **revert the fix and watch it fail.** Before believing a
gate: plant a violation and watch it fire. A run of "0 errors" and "0 files examined"
are reported through the same channel.

Every refusal test needs a success test beside it, or a handler that refuses everything
passes the lot.

## 7. Lost updates: re-read after calling out

Any read-decide-write across processes is a lost update unless the write is atomic and
its refusal is honoured.

```php
// add_option() refuses when the row exists — that is the atomic half.
// Honouring the refusal is the half that gets forgotten.
if ( add_option( self::OPTION_SLUG, $slug, '', false ) ) {
    return $slug;
}

// Past the object cache: this worker's `notoptions` may predate the winner's write.
wp_cache_delete( self::OPTION_SLUG, 'options' );
wp_cache_delete( 'notoptions', 'options' );

$winner = get_option( self::OPTION_SLUG, null );

return ( is_string( $winner ) && 32 === strlen( $winner ) ) ? $winner : $slug;
```

Non-autoloaded does not mean uncached. Inside a lock, delete the cache entry **and**
`notoptions` before reading, or the lock serialises a critical section whose read is
stale.

## 8. SQL: identifiers from an allow-list, values from `prepare()`

- A table or column name may only reach a query if it came from `TableEnumerator` and
  passed `assertAllowed()`. Backtick-quoting is belt and braces, never the defence.
- Everything else goes through `$wpdb->prepare()`. Build `IN (…)` from generated `%d`
  placeholders, never by concatenating values — even ids from your own rows.
- **`prepare()` output that is not going to the server must have its placeholder escape
  removed.** Since WP 4.8.3 every `%` becomes a 64-char hash that only `wpdb::query()`
  reverses. A dump written to a file keeps the hash: silent corruption of every URL
  containing `%20`. See `WpdbConnection::prepare()`.
- `LIMIT n OFFSET m` without `ORDER BY` has no defined order. Batches read minutes
  apart on a live site lose rows or repeat them, and the import raises no error.

## 9. Wiring lives in service providers

New a class in its provider, not at a call site. One provider per namespace, registered
in `src/Plugin.php`. A constructor that reaches for a global or a singleton is a
constructor that cannot be tested.

Interfaces exist to make the untestable testable — `ResponseEmitter` wraps `header()`,
`echo`, and `exit` for exactly that reason. Introduce one when the alternative is a
behaviour that no test can observe; do not introduce one speculatively.

## 10. Comments carry the why

This codebase runs about 35% comments, and that is deliberate. The bar: **would the next
reader recover this reasoning from the code?** If yes, do not write it.

Worth writing: why a decision happens *before* an add rather than after; why `%` is
unescaped here and not there; what a divergence would silently cost; what was measured
and on what. Record numbers you measured — "127 MiB for one read" outlives the sentence
that motivated it.

Every `phpcs:ignore` carries its reason on the same line. No exceptions.

## 11. Naming

`camelCase` methods and properties, `PascalCase` classes — WordPress naming applies to
hooks, options, and tables, not to this plugin's own object graph.

- Options: `fiction_drafts_*`. Tables: `{prefix}fdrafts_*`. Hooks: `fiction_drafts/*`.
- `declare( strict_types=1 )` in every file. Constructor property promotion, `readonly`
  where the value does not change. Domain objects immutable; `with()` returns a copy.
- Yoda conditions, per WordPress standards, enforced by PHPCS.

---

## Before you commit

```bash
composer check      # phpcs → phpstan level 6 → phpunit. All three, exit 0.
bun run lint:js
bun run build
```

Then walk this list:

- [ ] Any constant or formula I added — is there a second copy anywhere?
- [ ] Anything I read that I did not compute — is its **size** bounded, not just its shape?
- [ ] Any list endpoint I touched — does it read once per page?
- [ ] Any core function I stubbed — did I read core, including its quirks?
- [ ] Anything reached through a hook or route — is there a test firing the real dispatcher?
- [ ] Every new test — did I revert the fix and watch it fail?
- [ ] Every refusal test — is there a success test beside it?
- [ ] Any read-decide-write across processes — is the write atomic and its refusal honoured?
- [ ] `ExportOnlyBoundaryTest` — if it failed on my file, **that failure is the review**.
- [ ] `ISA.md` — did I change what "done" means, and if so did I say so there?

## Do not

- Loosen `phpcs.xml.dist`, `phpstan.neon.dist`, or `phpunit.xml.dist` to make a change
  pass. The local un-suffixed variants are gitignored so a per-machine override cannot
  become everyone's gate.
- Add a restore path, or anything that writes to site content. See `CLAUDE.md`.
- Use `npm` or `npx`. This project uses `bun`.
- Add an entry to `ExportOnlyBoundaryTest`'s allow-list without a written reason in the
  test itself.
