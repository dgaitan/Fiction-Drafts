<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Rest\AbstractController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The invariants that hold across the whole REST surface and the whole client.
 *
 * Every one of these is a rule that a *future* controller or component could
 * break, which is why they are stated as a sweep over the directory rather than
 * as an assertion inside one class's test.  A per-class test proves that class;
 * a sweep proves the boundary.
 */
final class AdminBoundaryTest extends TestCase {

	/**
	 * The one place the capability string may appear in `src/Rest/`.
	 */
	private const CAPABILITY_HOME = 'AbstractController.php';

	/**
	 * @return array<int, string>
	 */
	private function restFiles(): array {
		$files = glob( dirname( __DIR__, 2 ) . '/src/Rest/*.php' );

		return false === $files ? [] : $files;
	}

	/**
	 * @return array<int, string>
	 */
	private function clientFiles(): array {
		$files = glob( dirname( __DIR__, 2 ) . '/assets/app/{,*/}*.js', GLOB_BRACE );

		return false === $files ? [] : $files;
	}

	public function testTheSweepHasSomethingToSweep(): void {
		// The control for every test below. A glob that matches nothing makes
		// each of them a foreach over an empty array — which passes, loudly,
		// while proving nothing at all.
		$this->assertGreaterThanOrEqual( 4, count( $this->restFiles() ) );
		$this->assertGreaterThanOrEqual( 5, count( $this->clientFiles() ) );
	}

	/**
	 * Route registration may only happen inside the capability gate.
	 *
	 * Stated as "whatever calls register_rest_route() must be a controller"
	 * rather than "every class in src/Rest/ is a controller", because the
	 * directory legitimately also holds the service provider. The rule that
	 * matters is about the act, not about the address.
	 */
	public function testOnlyAControllerRegistersRoutes(): void {
		$registrars = 0;

		foreach ( $this->restFiles() as $file ) {
			if ( ! str_contains( (string) file_get_contents( $file ), 'register_rest_route(' ) ) {
				continue;
			}

			++$registrars;

			$class      = 'FictionDrafts\\Rest\\' . basename( $file, '.php' );
			$reflection = new ReflectionClass( $class );

			$this->assertTrue(
				$reflection->isSubclassOf( AbstractController::class ),
				$class . ' registers routes without extending the capability gate.'
			);
		}

		$this->assertGreaterThanOrEqual( 3, $registrars, 'no route registrations found — this test would prove nothing' );
	}

	/**
	 * A controller nobody boots is a route that exists only in the source.
	 */
	public function testEveryControllerIsBootedByTheProvider(): void {
		$provider = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rest/RestServiceProvider.php' );

		foreach ( $this->restFiles() as $file ) {
			$name = basename( $file, '.php' );

			if ( ! str_contains( (string) file_get_contents( $file ), 'register_rest_route(' ) ) {
				continue;
			}

			$this->assertStringContainsString(
				$name . '::class,',
				$provider,
				$name . ' registers routes but is missing from RestServiceProvider::CONTROLLERS.'
			);
		}
	}

	public function testEveryRegisteredRouteUsesThePermissionCheck(): void {
		foreach ( $this->restFiles() as $file ) {
			$source = (string) file_get_contents( $file );

			$registrations = substr_count( $source, 'register_rest_route(' );

			if ( 0 === $registrations ) {
				continue;
			}

			$this->assertSame(
				substr_count( $source, "'permission_callback' => [ \$this, 'permissionCheck' ]" ),
				substr_count( $source, "'permission_callback'" ),
				basename( $file ) . ' has a permission_callback that is not permissionCheck.'
			);
		}
	}

	public function testNoRouteIsRegisteredWithoutAPermissionCallback(): void {
		foreach ( $this->restFiles() as $file ) {
			$source = (string) file_get_contents( $file );

			$this->assertStringNotContainsString( '__return_true', $source, basename( $file ) );
		}
	}

	public function testTheCapabilityStringAppearsInExactlyOnePlace(): void {
		foreach ( $this->restFiles() as $file ) {
			if ( self::CAPABILITY_HOME === basename( $file ) ) {
				continue;
			}

			$this->assertStringNotContainsString(
				"'manage_options'",
				(string) file_get_contents( $file ),
				basename( $file ) . ' restates the capability instead of using AbstractController::CAPABILITY.'
			);
		}
	}

	/**
	 * Spec §11: SCSS only, no inline styles.
	 *
	 * An inline style is not a cosmetic complaint — it is a value that escaped
	 * the token file, so the next person changing a colour changes it in one of
	 * two places and the interface disagrees with itself.
	 */
	public function testNoComponentCarriesAnInlineStyle(): void {
		foreach ( $this->clientFiles() as $file ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\bstyle=\{/',
				(string) file_get_contents( $file ),
				basename( $file ) . ' sets an inline style.'
			);
		}
	}

	public function testEveryClassNameCarriesThePluginPrefix(): void {
		foreach ( $this->clientFiles() as $file ) {
			$source = (string) file_get_contents( $file );

			preg_match_all( '/className="([^"]+)"/', $source, $matches );

			foreach ( $matches[1] as $value ) {
				$names = preg_split( '/\s+/', trim( $value ) );

				foreach ( false === $names ? [] : $names as $className ) {
					if ( '' === $className ) {
						continue;
					}

					$this->assertStringStartsWith(
						'fd-',
						$className,
						basename( $file ) . ' uses an unprefixed class "' . $className . '".'
					);
				}
			}
		}
	}

	/**
	 * The rules the server owns must not be restated in the client.
	 *
	 * The profile slugs and the stage ids are handed down at boot precisely so
	 * that there is one answer to "which profiles exist" and one to "what are
	 * the stages called".  A literal in the client is a second answer, and it
	 * is the one on screen.
	 */
	public function testTheClientRestatesNoProfileSlugOrStageId(): void {
		$owned = [
			'full',
			'database_only',
			'files_only',
			'files_no_media',
			'Everything',
			'Exporting the database',
			'Scanning files',
			'Checking there is room',
			'Building the archive',
			'Finishing up',
		];

		foreach ( $this->clientFiles() as $file ) {
			$source = (string) file_get_contents( $file );

			foreach ( $owned as $literal ) {
				$this->assertStringNotContainsString(
					"'" . $literal . "'",
					$source,
					basename( $file ) . ' hardcodes "' . $literal . '", which the bootstrap already supplies.'
				);
			}
		}
	}

	/**
	 * Spec §6.3: the opt-in resets on every job.
	 *
	 * Enforced by absence rather than by discipline — the value has nowhere to
	 * persist to, so there is no code path that could restore it.
	 */
	public function testTheWpConfigOptInIsNeverPersistedClientSide(): void {
		foreach ( $this->clientFiles() as $file ) {
			$source = (string) file_get_contents( $file );

			$this->assertStringNotContainsString( 'localStorage', $source, basename( $file ) );
			$this->assertStringNotContainsString( 'sessionStorage', $source, basename( $file ) );
		}
	}

	/**
	 * The gate refuses, and refuses with the code core prescribes.
	 *
	 * 403 for a logged-in user without the capability; 401 for a request with
	 * no identity at all.  The distinction is core's, and it matters because a
	 * client that treats 401 as "forbidden" never prompts for a login.
	 */
	public function testTheCapabilityGateRefusesWithTheRightCode(): void {
		$controller = new class() extends AbstractController {
			public function registerRoutes(): void {}
		};

		$request = new \WP_REST_Request();

		// The control: with the capability, the check passes. Without it, the
		// two refusals below would be equally consistent with a check that
		// always refuses.
		$this->assertTrue( $controller->permissionCheck( $request ) );

		fiction_drafts_test_set_capability( 'manage_options', false );

		$forbidden = $controller->permissionCheck( $request );

		$this->assertInstanceOf( \WP_Error::class, $forbidden );
		$this->assertSame( 403, $forbidden->get_error_status() );

		fiction_drafts_test_set_logged_in( false );

		$unauthorized = $controller->permissionCheck( $request );

		$this->assertInstanceOf( \WP_Error::class, $unauthorized );
		$this->assertSame( 401, $unauthorized->get_error_status() );

		fiction_drafts_test_reset_rest();
	}

	/**
	 * The client must not compose the namespace itself.
	 */
	public function testOnlyTheApiModuleKnowsTheRestRoot(): void {
		foreach ( $this->clientFiles() as $file ) {
			$source = (string) file_get_contents( $file );

			// `/wp-json/` is not the REST root everywhere. With permalinks set
			// to Plain — a real setting on real sites — WordPress serves REST
			// at `/?rest_route=`, and rest_url() is what knows the difference.
			// A hardcoded `/wp-json/` anywhere makes the whole screen dead on
			// those installs.
			$this->assertStringNotContainsString( '/wp-json', $source, basename( $file ) );

			if ( 'api.js' === basename( $file ) || 'bootstrap.js' === basename( $file ) ) {
				continue;
			}

			$this->assertStringNotContainsString(
				'fiction-drafts/v1',
				$source,
				basename( $file ) . ' builds its own REST URL.'
			);
		}
	}

	/**
	 * A list request must never hash a file.
	 *
	 * Every checksum in the payload was computed once, by FinalizeStage, and
	 * stored. Hashing on the fly would make rendering a page of ten multi-
	 * gigabyte backups a minutes-long request — behind a screen that polls
	 * every two seconds.
	 */
	public function testTheListRouteNeverHashesAFile(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rest/BackupsController.php' );

		$this->assertStringNotContainsString( 'hash_file', $source );
		$this->assertStringNotContainsString( 'file_get_contents', $source );

		// The control: the checksum genuinely is in the payload, so the two
		// assertions above are about *where* it came from rather than about a
		// field that does not exist.
		$this->assertStringContainsString( "'sha256'", $source );
	}
}
