=== Fiction Drafts ===
Contributors: davidgaitan
Tags: backup, export, database, migration, download
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creates complete, downloadable copies of a WordPress site as resumable background jobs that never time out.

== Description ==

Fiction Drafts copies your site — database, files, or both — into one or more `.zip` volumes you can download and verify.

It is an export-only tool. It does not restore, migrate, or rewrite URLs.

= Security =

A backup archive is the most sensitive file on your server. Even with `wp-config.php` left out,
it contains every user's password hash, every session token, and any API key or credential a
plugin has stored in your options table. Treat a downloaded archive the way you would treat a
database dump, because that is what it is.

**wp-config.php is excluded by default**, including from the "Everything" profile. That file
holds your database password and all eight authentication salts; with it, an archive is enough
to connect to your database directly and to forge a login cookie for any account. Including it
is a per-job choice you make each time, and the choice never carries over to the next backup.
The Backups screen shows which archives contain it.

= What protects your backups =

Four things, and it is worth knowing which of them is doing the work on your server.

1. **The download endpoint is the only way an archive is served.** Every download goes through
   PHP and passes four checks in order: you must be logged in, you must have the capability,
   the request must carry a valid nonce, and it must carry a download link that has not been
   used.
2. **Download links are single-use and last five minutes.** Asking to download mints a link
   bound to your user account, to one backup, and to one volume. Using it spends it. A link
   left in your browser history, captured in a proxy log, or caught in a screen share is dead
   long before anyone reads it. The five minutes bounds when a download may *start*; a transfer
   already under way is not interrupted, so a multi-hour download of a large archive is fine.
   Links are stored on your server as hashes, never as the links themselves — so a copy of your
   database, including one this plugin made, contains no usable download links.
3. **The storage directory has 32 random characters in its name.** That is the only thing
   standing between a stranger and your archives if your server serves the directory directly.
4. **`.htaccess`, on Apache only.** The plugin writes one, and it does nothing at all on nginx.

**On nginx, `.htaccess` does nothing — this was measured, not assumed.** nginx never reads
`.htaccess` files. A file placed in `wp-content` with `Require all denied` beside it was
requested over HTTP while logged out during development, and the server returned it with
`200 OK`. So on nginx, protections 1 and 3 are what you actually have.

If you want your archives physically unreachable by URL, define `FICTION_DRAFTS_STORAGE_DIR` in
`wp-config.php` and point it at a directory outside your document root:

`define( 'FICTION_DRAFTS_STORAGE_DIR', '/home/you/private/fiction-drafts' );`

That is the strongest option available, because a path the web server does not serve cannot be
requested at all. The download endpoint honours the constant, so downloads keep working.

**Multisite.** This release does not support multisite. `manage_options` is a per-site
capability, so on a network install it belongs to every subsite administrator while the archives
belong to one site. Rather than leave that open, Fiction Drafts requires `manage_network_options`
whenever `is_multisite()` is true — network administrators only. On a network where you activated
the plugin for a single site, that will refuse a site administrator who ought to be allowed. That
is deliberate: this release is not tested on multisite, and a refused download is a better
failure than another site's password hashes.

= Verifying and resuming a download =

Every volume's SHA-256 is shown on the Backups screen, computed when the archive was sealed.
Check it after downloading. This matters more than it does for most files: a `.zip` keeps its
index at the *end*, so an archive whose download was cut short is not a partial backup — it is a
file that will not open at all.

Interrupted downloads resume rather than restart. The endpoint supports HTTP range requests, so
any download manager, `curl -C -`, or `wget -c` picks up where it stopped.

= Background processing =

Fiction Drafts bundles its own copy of Action Scheduler, so background work does not depend on WooCommerce or any other plugin being installed.

On a very low-traffic site the queue can idle. Running `wp action-scheduler run` forces it along.

Backups run one bounded step at a time. A step stops when its time budget runs out and records exactly where to resume, so a backup on a large site never depends on a single long request finishing. Every stage must complete at least one unit of work per step; a stage that returns without advancing its resume position fails its job with a message naming the stage, rather than being re-queued forever.

== Uninstall ==

Deleting the plugin removes everything it created: its two `fdrafts_` tables, its
`fiction_drafts_` options, the storage directory and every archive in it, and the
`fiction-drafts` Action Scheduler group.

It deliberately does **not** drop the `actionscheduler_*` tables. Action Scheduler is a
shared library — WooCommerce and any other plugin that bundles it use the same tables —
and dropping them here would break every one of those plugins at the moment this one is
removed.

**Single site only.** Uninstall cleans the site it runs on. On a multisite network it does
not loop over the other sites, so their tables, options, and archives are left in place and
must be removed by hand. Fiction Drafts is not tested on multisite in this release.

== Changelog ==

= 0.1.0 =
* Initial release: manual backup and secure download.

