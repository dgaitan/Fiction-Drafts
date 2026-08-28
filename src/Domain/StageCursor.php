<?php

declare( strict_types=1 );

namespace FictionDrafts\Domain;

use JsonException;

/**
 * A serializable position inside a stage.
 *
 * This is the whole resumability model in one object: a stage that runs out of
 * time returns the cursor describing exactly where it stopped, the runner
 * persists it, and the next scheduled step hands it straight back.
 */
final class StageCursor {

	/**
	 * @param array<string, scalar|null> $data Stage-defined position data.
	 */
	private function __construct( private readonly array $data ) {
	}

	/**
	 * The position a stage starts from when it has never run.
	 */
	public static function start(): self {
		return new self( [] );
	}

	/**
	 * @param array<string, scalar|null> $data Stage-defined position data.
	 */
	public static function fromArray( array $data ): self {
		return new self( $data );
	}

	/**
	 * Rebuild a cursor from its persisted JSON form.
	 *
	 * An unreadable cursor is treated as the start position rather than a
	 * fatal error: restarting a stage is always safe, and never resuming is not.
	 */
	public static function fromJson( ?string $json ): self {
		if ( null === $json || '' === $json ) {
			return self::start();
		}

		try {
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return self::start();
		}

		return is_array( $decoded ) ? new self( $decoded ) : self::start();
	}

	public function isStart(): bool {
		return [] === $this->data;
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data[ $key ] ?? $fallback;
	}

	public function getInt( string $key, int $fallback = 0 ): int {
		$value = $this->data[ $key ] ?? null;

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	public function getString( string $key, string $fallback = '' ): string {
		$value = $this->data[ $key ] ?? null;

		return is_string( $value ) ? $value : $fallback;
	}

	/**
	 * @return array<string, scalar|null>
	 */
	public function toArray(): array {
		return $this->data;
	}

	public function toJson(): string {
		$encoded = wp_json_encode( $this->data );

		return false === $encoded ? '{}' : $encoded;
	}
}
