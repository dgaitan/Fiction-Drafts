<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Admin\AdminPage;
use FictionDrafts\Admin\ProfileCatalogue;
use FictionDrafts\Backup\StageRegistry;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Persistence\SettingsRepository;
use FictionDrafts\Rest\AbstractController;
use FictionDrafts\Tests\Support\CountingStage;
use PHPUnit\Framework\TestCase;

/**
 * The admin screen, and the payload it hands the app.
 *
 * The claims worth testing here are all about *what the client is told*, not
 * about markup — because every rule the client renders that it was not told is
 * a rule it invented, and an invented rule is a second answer to a question
 * that already had one.
 */
final class AdminPageTest extends TestCase {

	private AdminPage $page;

	private StageRegistry $stages;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_options();
		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_rest();
		fiction_drafts_test_reset_enqueued();

		$this->stages = new StageRegistry();

		// The real pipeline reaches the registry through the public filter, and
		// BackupServiceProvider::boot() is what adds it in production. A unit
		// test that skipped this would find an empty stage list — and a test
		// that loops over an empty list asserts nothing while reporting a pass.
		fiction_drafts_test_add_filter(
			StageRegistry::FILTER,
			static function ( array $stages ): array {
				$stages[] = new CountingStage( 1, '', 'database' );
				$stages[] = new CountingStage( 1, '', 'files' );
				$stages[] = new CountingStage( 1, '', 'prepare' );

				return $stages;
			}
		);

		$this->page = new AdminPage(
			$this->stages,
			new ProfileCatalogue(),
			new SettingsRepository(),
			dirname( __DIR__, 2 ) . '/fiction-drafts.php'
		);
	}

	public function testTheMenuRegistersUnderManageOptions(): void {
		$this->page->addMenu();

		$menus = fiction_drafts_test_enqueued()['menus'];

		$this->assertCount( 1, $menus );
		$this->assertSame( AdminPage::MENU_SLUG, $menus[0]['slug'] );
		$this->assertSame( AbstractController::CAPABILITY, $menus[0]['capability'] );
	}

	public function testAUserWithoutTheCapabilityGetsNoMenu(): void {
		fiction_drafts_test_set_capability( 'manage_options', false );

		$this->page->addMenu();

		$this->assertSame( [], fiction_drafts_test_enqueued()['menus'] );
	}

	public function testThePageIsOneDivAndNothingElse(): void {
		ob_start();
		$this->page->render();
		$markup = (string) ob_get_clean();

		$this->assertSame( '<div id="fiction-drafts-root" class="fd-root"></div>', $markup );
	}

	public function testAssetsLoadOnThisScreenOnly(): void {
		$this->page->addMenu();

		$this->page->enqueue( 'index.php' );
		$this->assertSame( [], fiction_drafts_test_enqueued()['scripts'] );

		$this->page->enqueue( 'toplevel_page_' . AdminPage::MENU_SLUG );
		$this->assertCount( 1, fiction_drafts_test_enqueued()['scripts'] );
	}

	public function testAUserWithoutTheCapabilityNeverReachesTheEnqueue(): void {
		fiction_drafts_test_set_capability( 'manage_options', false );

		$this->page->addMenu();
		$this->page->enqueue( 'toplevel_page_' . AdminPage::MENU_SLUG );

		$this->assertSame(
			[],
			fiction_drafts_test_enqueued()['scripts'],
			'the enqueue inherits the menu gate, so a false hook suffix must match nothing'
		);
	}

	public function testTheScriptTakesItsVersionAndDependenciesFromTheBuild(): void {
		$asset = include dirname( __DIR__, 2 ) . '/build/index.asset.php';

		$this->assertIsArray( $asset, 'the bundle must be built before this test means anything' );

		$this->page->addMenu();
		$this->page->enqueue( 'toplevel_page_' . AdminPage::MENU_SLUG );

		$script = fiction_drafts_test_enqueued()['scripts'][0];

		$this->assertSame( $asset['version'], $script['version'] );
		$this->assertSame( $asset['dependencies'], $script['deps'] );
		$this->assertContains( 'wp-element', $script['deps'] );
	}

	public function testTheBootstrapIsInlinedBeforeTheBundle(): void {
		$this->page->addMenu();
		$this->page->enqueue( 'toplevel_page_' . AdminPage::MENU_SLUG );

		$inline = fiction_drafts_test_enqueued()['inline'];

		$this->assertCount( 1, $inline );
		$this->assertSame( AdminPage::SCRIPT_HANDLE, $inline[0]['handle'] );
		$this->assertSame( 'before', $inline[0]['position'], 'the bundle reads the global at module scope' );
		$this->assertStringStartsWith( 'window.' . AdminPage::BOOTSTRAP_GLOBAL . ' = {', $inline[0]['data'] );
	}

	public function testTheScriptIsRegisteredForTranslation(): void {
		$this->page->addMenu();
		$this->page->enqueue( 'toplevel_page_' . AdminPage::MENU_SLUG );

		$this->assertSame( [ AdminPage::SCRIPT_HANDLE ], fiction_drafts_test_enqueued()['translations'] );
	}

	public function testTheStylesheetIsEnqueuedOnlyWhenItExists(): void {
		$this->page->addMenu();
		$this->page->enqueue( 'toplevel_page_' . AdminPage::MENU_SLUG );

		$exists = is_file( dirname( __DIR__, 2 ) . '/build/index.css' );

		$this->assertSame(
			$exists ? 1 : 0,
			count( fiction_drafts_test_enqueued()['styles'] )
		);
	}

	public function testTheBootstrapCarriesEveryProfileFromTheEnum(): void {
		$profiles = $this->page->bootstrap()['profiles'];

		$this->assertCount( count( BackupProfile::cases() ), $profiles );

		$slugs = array_column( $profiles, 'slug' );

		foreach ( BackupProfile::cases() as $case ) {
			$this->assertContains( $case->value, $slugs );
		}
	}

	public function testExactlyOneProfileIsMarkedCustom(): void {
		$custom = array_filter(
			$this->page->bootstrap()['profiles'],
			static fn ( array $profile ): bool => $profile['custom']
		);

		$this->assertCount( 1, $custom );
		$this->assertSame( BackupProfile::Custom->value, array_values( $custom )[0]['slug'] );
	}

	public function testTheBootstrapCarriesTheRegisteredPipelineNotAHardcodedList(): void {
		$before = count( $this->page->bootstrap()['stages'] );

		fiction_drafts_test_add_filter(
			StageRegistry::FILTER,
			static function ( array $stages ): array {
				$stages[] = new CountingStage( 1, '', 'third_party_stage' );

				return $stages;
			}
		);

		$after = $this->page->bootstrap()['stages'];

		$this->assertCount( $before + 1, $after );
		$this->assertContains( 'third_party_stage', array_column( $after, 'id' ) );
	}

	public function testEveryStageInTheBootstrapCarriesALabel(): void {
		$stages = $this->page->bootstrap()['stages'];

		// The control. A foreach over an empty array asserts nothing and
		// reports a pass, which is how this test first ran green while the
		// pipeline was not registered at all.
		$this->assertNotEmpty( $stages, 'no stages registered — the loop below would prove nothing' );

		foreach ( $stages as $stage ) {
			$this->assertNotSame( '', $stage['label'] );
			$this->assertNotSame( $stage['id'], $stage['label'] );
		}
	}

	public function testTheWpConfigWarningComesFromTheServer(): void {
		$wpConfig = $this->page->bootstrap()['wpConfig'];

		$this->assertStringContainsString( 'database password', $wpConfig['warning'] );
		$this->assertStringContainsString( 'authentication salts', $wpConfig['warning'] );
	}

	public function testTheWpConfigDefaultIsFalse(): void {
		$this->assertFalse(
			$this->page->bootstrap()['defaults'][ BackupJob::OPTION_INCLUDE_WP_CONFIG ]
		);
	}

	public function testTheCustomAreasAreKeyedByTheOptionNamesTheRouteExpects(): void {
		$keys = array_column( $this->page->bootstrap()['areas'], 'key' );

		$this->assertSame(
			[
				BackupJob::OPTION_INCLUDE_DATABASE,
				BackupJob::OPTION_INCLUDE_CORE,
				BackupJob::OPTION_INCLUDE_UPLOADS,
			],
			$keys
		);
	}

	/**
	 * Spec §10.2: the client asks by uuid and by sequence, and never learns a
	 * path.  Asserted against the serialised payload rather than field by
	 * field, so a path added to a new key is caught too.
	 */
	public function testTheBootstrapLeaksNoPathPrefixOrStorageSlug(): void {
		$encoded = wp_json_encode( $this->page->bootstrap() );

		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( dirname( __DIR__, 2 ), $encoded );
		$this->assertStringNotContainsString( 'wp-content/fiction-drafts-', $encoded );
		$this->assertStringNotContainsString( ABSPATH ?? '/nowhere', $encoded );
	}
}
