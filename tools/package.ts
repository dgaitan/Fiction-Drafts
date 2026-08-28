#!/usr/bin/env bun
/**
 * Build an installable WordPress plugin zip.
 *
 * ## Why this is not `git archive`
 *
 * `.gitignore` excludes `/vendor/` and `/build/`, because both are reproducible
 * from lockfiles and neither belongs in history.  That is right for the repo and
 * fatal for a release: a zip made from tracked files alone has no Composer
 * autoloader, no bundled Action Scheduler, and no compiled admin bundle.  It
 * installs cleanly, activates, and then does nothing — the worst kind of broken,
 * because the failure surfaces on someone else's site.
 *
 * ## Why this stages a copy instead of building in place
 *
 * The vendor tree a release needs (`--no-dev`) is not the vendor tree the repo
 * needs (phpcs, phpstan, phpunit).  Running `composer install --no-dev` here
 * would silently disarm `composer check` for whoever ran the release, and they
 * would not find out until CI did.  So the plugin is copied to `dist/staging/`
 * and Composer runs *there*; the working tree is never written to.
 *
 * ## Why the checks read the zip rather than the staging directory
 *
 * Verifying the staging directory only proves the copy step agreed with itself.
 * Everything below re-opens the finished archive and compares it against the
 * repository — a source the packer did not write — so a step that silently
 * copied nothing cannot report success.
 *
 * Usage:
 *   bun run package                    # full build
 *   bun run package -- --skip-build    # reuse the existing build/ output
 *   bun run package -- --keep-staging  # leave dist/staging/ for inspection
 *   bun run package -- --out ./release
 */

import { spawnSync } from 'node:child_process';
import {
	cpSync,
	existsSync,
	mkdirSync,
	readdirSync,
	readFileSync,
	rmSync,
	statSync,
} from 'node:fs';
import { join, resolve } from 'node:path';

const ROOT = resolve( import.meta.dir, '..' );

/**
 * The plugin folder name inside the archive.
 *
 * WordPress names the installed directory after the archive's single top-level
 * folder, and the text domain, the `languages/` file prefix, and every
 * `plugin_basename()` comparison assume `fiction-drafts`.  It is not cosmetic.
 */
const SLUG = 'fiction-drafts';

/**
 * Everything that ships, named explicitly.
 *
 * An allow-list rather than an ignore-list on purpose.  An ignore-list fails
 * open: the day someone drops a database dump, a `.env`, or a stray archive in
 * the plugin root, an ignore-list ships it and an allow-list does not.  For a
 * plugin whose entire job is to package the site's secrets into a file, failing
 * open is not an acceptable default.
 */
const SHIPPED = [
	'fiction-drafts.php',
	'uninstall.php',
	'README.md',
	'composer.json',
	'composer.lock',
	'src',
	'build',
	'languages', // Optional — skipped when absent.
];

// ---------------------------------------------------------------- arguments

const argv = process.argv.slice( 2 );
const skipBuild = argv.includes( '--skip-build' );
const keepStaging = argv.includes( '--keep-staging' );
const outIndex = argv.indexOf( '--out' );
const OUT_DIR = -1 === outIndex ? join( ROOT, 'dist' ) : resolve( argv[ outIndex + 1 ] ?? '' );

// ------------------------------------------------------------------ output

const ESC = '\u001b[';
const BOLD = `${ ESC }1m`;
const GREEN = `${ ESC }32m`;
const RED = `${ ESC }31m`;
const OFF = `${ ESC }0m`;

const failures: string[] = [];

const step = ( message: string ) => console.log( `\n${ BOLD }> ${ message }${ OFF }` );
const ok = ( message: string ) => console.log( `  ${ GREEN }OK${ OFF }   ${ message }` );
const note = ( message: string ) => console.log( `       ${ message }` );

/**
 * Record a failed check rather than throwing.
 *
 * A packer that dies on the first problem makes the operator run it five times
 * to learn five things.  Verification collects everything and reports once.
 */
const check = ( condition: boolean, description: string ): boolean => {
	if ( condition ) {
		ok( description );
		return true;
	}

	failures.push( description );
	console.log( `  ${ RED }FAIL${ OFF } ${ description }` );

	return false;
};

const die = ( message: string ): never => {
	console.error( `\n${ RED }Aborted:${ OFF } ${ message }\n` );
	process.exit( 1 );
};

const run = ( command: string, args: string[], cwd: string ): string => {
	const result = spawnSync( command, args, { cwd, encoding: 'utf8' } );

	if ( result.error ) {
		die( `${ command } could not be run - ${ result.error.message }` );
	}

	if ( 0 !== result.status ) {
		console.error( result.stdout ?? '' );
		console.error( result.stderr ?? '' );
		die( `${ command } ${ args.join( ' ' ) } exited ${ result.status }` );
	}

	return result.stdout ?? '';
};

const has = ( command: string ): boolean =>
	0 === spawnSync( '/bin/sh', [ '-c', `command -v ${ command }` ] ).status;

// -------------------------------------------------------------- 1. preflight

step( 'Preflight' );

for ( const tool of [ 'composer', 'zip', 'unzip' ] ) {
	if ( ! has( tool ) ) {
		die( `\`${ tool }\` is not on PATH; it is required to build a release.` );
	}
}
ok( 'composer, zip and unzip are available' );

const mainFile = join( ROOT, `${ SLUG }.php` );
if ( ! existsSync( mainFile ) ) {
	die( `${ mainFile } not found - run this from the plugin repository.` );
}
ok( `plugin root is ${ ROOT }` );

// --------------------------------------------------------------- 2. version

step( 'Version' );

const mainSource = readFileSync( mainFile, 'utf8' );
const composerJson = JSON.parse( readFileSync( join( ROOT, 'composer.json' ), 'utf8' ) );
const packageJson = JSON.parse( readFileSync( join( ROOT, 'package.json' ), 'utf8' ) );

const headerVersion = /^\s*\*\s*Version:\s*(\S+)\s*$/m.exec( mainSource )?.[ 1 ];
const constantVersion = /define\(\s*'FICTION_DRAFTS_VERSION',\s*'([^']+)'\s*\)/.exec(
	mainSource
)?.[ 1 ];
const packageVersion: string | undefined = packageJson.version;

if ( ! headerVersion || ! constantVersion || ! packageVersion ) {
	die( 'Could not read the version from the plugin header, the constant, and package.json.' );
}

/*
 * Three files state the version and nothing reconciles them at runtime. The
 * header is what WordPress shows and what an updater compares; the constant is
 * what the code branches on. A release built while they disagree is a release
 * that reports one version and behaves like another, and the mismatch stays
 * invisible until a migration guarded by the constant fails to run.
 */
if ( headerVersion !== constantVersion || headerVersion !== packageVersion ) {
	die(
		'Version mismatch - refusing to build.\n' +
			`  plugin header ............ ${ headerVersion }\n` +
			`  FICTION_DRAFTS_VERSION ... ${ constantVersion }\n` +
			`  package.json ............. ${ packageVersion }`
	);
}

const VERSION = headerVersion;
ok( `version ${ VERSION } agrees across the header, the constant and package.json` );

// ----------------------------------------------------------------- 3. assets

step( 'Admin bundle' );

if ( skipBuild ) {
	note( '--skip-build: reusing the existing build/ output' );
} else {
	run( 'bun', [ 'run', 'build' ], ROOT );
	ok( 'bun run build completed' );
}

for ( const artefact of [ 'index.js', 'index.asset.php' ] ) {
	if ( ! existsSync( join( ROOT, 'build', artefact ) ) ) {
		die( `build/${ artefact } is missing - the admin page cannot load without it.` );
	}
}
ok( 'build/index.js and build/index.asset.php are present' );

// ---------------------------------------------------------------- 4. staging

step( 'Staging' );

const STAGING = join( OUT_DIR, 'staging' );
const PLUGIN_DIR = join( STAGING, SLUG );

rmSync( STAGING, { recursive: true, force: true } );
mkdirSync( PLUGIN_DIR, { recursive: true } );

const staged: string[] = [];

for ( const entry of SHIPPED ) {
	const source = join( ROOT, entry );

	if ( ! existsSync( source ) ) {
		if ( 'languages' === entry ) {
			note( 'languages/ absent - nothing to ship' );
			continue;
		}

		die( `${ entry } is listed in SHIPPED but does not exist.` );
	}

	cpSync( source, join( PLUGIN_DIR, entry ), {
		recursive: true,
		// Finder metadata is not source. It rides along in a recursive copy and
		// then shows up in the archive as junk on every non-macOS install.
		filter: ( from ) => ! /(^|\/)(\.DS_Store|\._[^/]*)$/.test( from ),
	} );

	staged.push( entry );
}
ok( `staged ${ staged.join( ', ' ) }` );

// ----------------------------------------------------------------- 5. vendor

step( 'Production dependencies' );

run(
	'composer',
	[
		'install',
		'--no-dev',
		'--optimize-autoloader',
		'--no-interaction',
		'--no-progress',
		'--no-scripts',
	],
	PLUGIN_DIR
);
ok( 'composer install --no-dev --optimize-autoloader' );

// --------------------------------------------------------------- 6. archive

step( 'Archive' );

const zipName = `${ SLUG }-${ VERSION }.zip`;
const zipPath = join( OUT_DIR, zipName );

rmSync( zipPath, { force: true } );

/*
 * `-X` drops the extra file attributes (uid/gid, macOS finder info) that make an
 * archive machine-specific for no benefit. The `-x` patterns are belt to the
 * copy filter's braces: a `.DS_Store` created between the copy and the zip would
 * otherwise still make it in.
 */
run( 'zip', [ '-r', '-q', '-X', '-9', zipPath, SLUG, '-x', '*/.DS_Store', '*/._*' ], STAGING );

const bytes = statSync( zipPath ).size;
ok( `${ zipName } - ${ ( bytes / 1024 / 1024 ).toFixed( 1 ) } MB` );

// ---------------------------------------------------------- 7. verification

step( 'Verifying the archive' );

run( 'unzip', [ '-tqq', zipPath ], OUT_DIR );
ok( 'archive passes unzip -t' );

const entries = run( 'unzip', [ '-Z1', zipPath ], OUT_DIR )
	.split( '\n' )
	.map( ( line ) => line.trim() )
	.filter( ( line ) => '' !== line );

check( entries.length > 100, `archive holds ${ entries.length } entries` );

// WordPress derives the installed folder name from the archive's single
// top-level directory. Two of them, or files at the root, and the install either
// fails or lands somewhere nothing expects.
const roots = new Set( entries.map( ( entry ) => entry.split( '/' )[ 0 ] ) );
check( 1 === roots.size && roots.has( SLUG ), `exactly one top-level directory, named ${ SLUG }` );

const inPlugin = entries
	.filter( ( entry ) => entry.startsWith( `${ SLUG }/` ) )
	.map( ( entry ) => entry.slice( SLUG.length + 1 ) )
	.filter( ( entry ) => '' !== entry );

check(
	! inPlugin.some( ( entry ) => entry.includes( '../' ) || entry.startsWith( '/' ) ),
	'no absolute or traversing paths'
);

for ( const required of [
	`${ SLUG }.php`,
	'uninstall.php',
	'vendor/autoload.php',
	'vendor/woocommerce/action-scheduler/action-scheduler.php',
	'build/index.js',
	'build/index.asset.php',
] ) {
	check( inPlugin.includes( required ), `contains ${ required }` );
}

// The staged top level must be exactly what SHIPPED named. Anything else in
// there arrived by accident, and this is where an accident is caught.
const expectedTop = new Set(
	SHIPPED.filter( ( entry ) => existsSync( join( ROOT, entry ) ) ).concat( 'vendor' )
);
const actualTop = new Set( inPlugin.map( ( entry ) => entry.split( '/' )[ 0 ] ) );
const unexpected = [ ...actualTop ].filter( ( entry ) => ! expectedTop.has( entry ) );
check(
	0 === unexpected.length,
	`no unexpected top-level entries${ unexpected.length ? ` (found ${ unexpected.join( ', ' ) })` : '' }`
);

/*
 * A named refusal list on top of the allow-list. Redundant by construction and
 * kept anyway: these are the entries whose presence would be a security or
 * support incident rather than a tidiness problem, and a reader should be able
 * to see them asserted rather than infer them from the copy step.
 */
const forbidden: Array< [ string, RegExp ] > = [
	[ 'no test suite', /^tests\// ],
	[ 'no node_modules', /(^|\/)node_modules\// ],
	[ 'no VCS metadata', /(^|\/)\.git(\/|$)/ ],
	[ 'no agent or editor config', /^\.(agents|claude|vscode|idea)\// ],
	[ 'no repository-only docs', /^(ISA|CLAUDE)\.md$/ ],
	[ 'no tool configuration', /^php(cs\.xml|unit\.xml|stan\.neon)/ ],
	[ 'no uncompiled sources', /^assets\// ],
	[ 'no JS toolchain lockfiles', /^(bun\.lock|package(-lock)?\.json)$/ ],
	[ 'no database dumps', /\.sql(\.gz)?$/ ],
	[ 'no environment files', /(^|\/)\.env/ ],
	[ 'no macOS metadata', /(^|\/)(\.DS_Store|\._)/ ],
];

for ( const [ description, pattern ] of forbidden ) {
	const hits = inPlugin.filter( ( entry ) => pattern.test( entry ) );
	check( 0 === hits.length, `${ description }${ hits.length ? ` (found ${ hits[ 0 ] })` : '' }` );
}

/*
 * Dependency checks derived from composer.json, so they cannot go stale. Both
 * directions are asserted: that dev packages are gone is only half the claim,
 * because an install that produced no vendor tree at all would satisfy it. The
 * production packages must also be there.
 */
const shippedVendors = new Set(
	inPlugin
		.filter( ( entry ) => entry.startsWith( 'vendor/' ) )
		.map( ( entry ) => entry.slice( 'vendor/'.length ).split( '/' )[ 0 ] )
		.filter( ( entry ) => '' !== entry && ! entry.endsWith( '.php' ) )
);

const prodPackages = Object.keys( composerJson.require ?? {} ).filter( ( name ) =>
	name.includes( '/' )
);
const prodVendors = new Set( prodPackages.map( ( name ) => name.split( '/' )[ 0 ] ) );

for ( const pkg of prodPackages ) {
	check( inPlugin.some( ( entry ) => entry.startsWith( `vendor/${ pkg }/` ) ), `ships ${ pkg }` );
}

const strays = [ ...shippedVendors ].filter(
	( vendor ) => 'composer' !== vendor && ! prodVendors.has( vendor )
);
check(
	0 === strays.length,
	`vendor/ holds production packages only${ strays.length ? ` (found ${ strays.join( ', ' ) })` : '' }`
);

/*
 * Source parity against the repository. Every other check would pass on an
 * archive whose src/ tree was half copied; this one compares a count the packer
 * did not produce against one it did.
 */
const countPhp = ( dir: string ): number =>
	readdirSync( dir, { withFileTypes: true } ).reduce( ( total, item ) => {
		if ( item.isDirectory() ) {
			return total + countPhp( join( dir, item.name ) );
		}

		return total + ( item.name.endsWith( '.php' ) ? 1 : 0 );
	}, 0 );

const repoPhp = countPhp( join( ROOT, 'src' ) );
const zipPhp = inPlugin.filter(
	( entry ) => entry.startsWith( 'src/' ) && entry.endsWith( '.php' )
).length;
check( repoPhp > 0 && repoPhp === zipPhp, `all ${ repoPhp } src/ PHP files are present` );

// The header inside the archive, not the one on disk, is what a site will read.
const stagedHeader = readFileSync( join( PLUGIN_DIR, `${ SLUG }.php` ), 'utf8' );
check(
	new RegExp( `^\\s*\\*\\s*Version:\\s*${ VERSION.replace( /\./g, '\\.' ) }\\s*$`, 'm' ).test(
		stagedHeader
	),
	`the packaged header declares version ${ VERSION }`
);

// ----------------------------------------------------------------- 8. finish

if ( ! keepStaging ) {
	rmSync( STAGING, { recursive: true, force: true } );
}

const sha = new Bun.CryptoHasher( 'sha256' ).update( readFileSync( zipPath ) ).digest( 'hex' );

if ( failures.length > 0 ) {
	console.error( `\n${ RED }${ failures.length } check(s) failed:${ OFF }` );
	failures.forEach( ( failure ) => console.error( `  - ${ failure }` ) );
	console.error( `\nThe archive at ${ zipPath } is NOT fit to release.\n` );
	process.exit( 1 );
}

console.log( `
${ BOLD }${ GREEN }Release archive ready.${ OFF }

  file      ${ zipPath }
  installs  as ${ SLUG }/ via Plugins > Add New > Upload Plugin
  version   ${ VERSION }
  size      ${ ( bytes / 1024 / 1024 ).toFixed( 1 ) } MB across ${ entries.length } entries
  sha256    ${ sha }
` );
