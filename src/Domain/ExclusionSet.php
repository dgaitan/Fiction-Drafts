<?php

declare( strict_types=1 );

namespace FictionDrafts\Domain;

/**
 * Glob patterns describing paths a backup must not contain.
 *
 * Paths are matched relative to the WordPress root, with forward slashes, and
 * never with a leading slash — `wp-content/uploads/2024/01/x.jpg`.
 *
 * Two wildcards are supported:
 *   *   matches within one path segment
 *   **  matches across segments
 *
 * A pattern ending in `/**` also matches the bare directory itself, so
 * `wp-content/cache/**` excludes `wp-content/cache` as well as its contents.
 */
final class ExclusionSet {

	/**
	 * @var array<int, string>
	 */
	private readonly array $patterns;

	/**
	 * @param array<int, string> $patterns Glob patterns, root-relative.
	 */
	public function __construct( array $patterns = [] ) {
		$this->patterns = array_values( array_unique( array_filter( array_map( 'strval', $patterns ) ) ) );
	}

	/**
	 * @return array<int, string>
	 */
	public function patterns(): array {
		return $this->patterns;
	}

	/**
	 * @param string ...$patterns Additional patterns to exclude.
	 */
	public function with( string ...$patterns ): self {
		return new self( array_merge( $this->patterns, $patterns ) );
	}

	/**
	 * Lift a single pattern — how the `wp-config.php` opt-in is expressed.
	 */
	public function without( string $pattern ): self {
		return new self(
			array_values(
				array_filter(
					$this->patterns,
					static fn ( string $candidate ): bool => $candidate !== $pattern
				)
			)
		);
	}

	public function isEmpty(): bool {
		return [] === $this->patterns;
	}

	/**
	 * Is this root-relative path excluded?
	 */
	public function matches( string $relativePath ): bool {
		$path = ltrim( str_replace( '\\', '/', $relativePath ), '/' );

		foreach ( $this->patterns as $pattern ) {
			if ( 1 === preg_match( self::toRegex( $pattern ), $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compile a glob pattern to an anchored regular expression.
	 *
	 * Escaping happens first, so a literal dot in `wp-config.php` stays
	 * literal and only the wildcards we put back are special.
	 */
	private static function toRegex( string $pattern ): string {
		$normalized = ltrim( str_replace( '\\', '/', $pattern ), '/' );
		$quoted     = preg_quote( $normalized, '#' );

		// Order matters: ** must be consumed before the single-* rule sees it.
		$expression = str_replace(
			[ '\*\*', '\*', '\?' ],
			[ '.*', '[^/]*', '.' ],
			$quoted
		);

		// `foo/**` should also match the bare directory `foo`.
		if ( str_ends_with( $expression, '/.*' ) ) {
			$expression = substr( $expression, 0, -3 ) . '(?:/.*)?';
		}

		return '#^' . $expression . '$#';
	}
}
