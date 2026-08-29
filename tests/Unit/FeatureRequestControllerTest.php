<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Rest\FeatureRequestController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Feature Request tab's route: validate, then mail the developer.
 */
final class FeatureRequestControllerTest extends TestCase {

	private FeatureRequestController $controller;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_options();
		fiction_drafts_test_reset_rest();
		fiction_drafts_test_reset_mail();

		update_option( 'admin_email', 'owner@example.com' );

		$this->controller = new FeatureRequestController();
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function send( array $params ): WP_REST_Response|WP_Error {
		return $this->controller->send( new WP_REST_Request( $params, 'POST' ) );
	}

	public function testAValidMessageIsMailedToTheSiteAdmin(): void {
		$result = $this->send(
			[
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'type'    => 'feature_request',
				'message' => 'Please add a way to schedule recurring backups.',
			]
		);

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( [ 'sent' => true ], $result->get_data() );

		$sent = fiction_drafts_test_sent_mail();

		$this->assertCount( 1, $sent );
		$this->assertSame( 'owner@example.com', $sent[0]['to'] );
		$this->assertStringContainsString( 'Feature request', $sent[0]['subject'] );
		$this->assertStringContainsString( 'Ada Lovelace', $sent[0]['message'] );
		$this->assertStringContainsString( 'Please add a way to schedule recurring backups.', $sent[0]['message'] );
		$this->assertSame( [ 'Reply-To: Ada Lovelace <ada@example.com>' ], $sent[0]['headers'] );
	}

	public function testAnInvalidEmailIsRefusedBeforeAnyMailIsSent(): void {
		$result = $this->send(
			[
				'email'   => 'not-an-email',
				'message' => 'Hello',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_status() );
		$this->assertSame( 'fiction_drafts_invalid_email', $result->get_error_code() );
		$this->assertCount( 0, fiction_drafts_test_sent_mail() );
	}

	public function testAnEmptyMessageIsRefused(): void {
		$result = $this->send(
			[
				'email'   => 'ada@example.com',
				'message' => '   ',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_status() );
		$this->assertSame( 'fiction_drafts_empty_message', $result->get_error_code() );
		$this->assertCount( 0, fiction_drafts_test_sent_mail() );
	}

	public function testAnUnknownRequestTypeIsRefused(): void {
		$result = $this->send(
			[
				'email'   => 'ada@example.com',
				'type'    => 'complaint',
				'message' => 'Hello',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_status() );
		$this->assertSame( 'fiction_drafts_unknown_type', $result->get_error_code() );
		$this->assertCount( 0, fiction_drafts_test_sent_mail() );
	}

	public function testAnOmittedTypeDefaultsToOther(): void {
		$this->send(
			[
				'email'   => 'ada@example.com',
				'message' => 'Hello',
			]
		);

		$sent = fiction_drafts_test_sent_mail();

		$this->assertStringContainsString( 'Type: Message', $sent[0]['message'] );
	}

	public function testAnOmittedNameLeavesTheReplyToAsTheBareEmail(): void {
		$this->send(
			[
				'email'   => 'ada@example.com',
				'message' => 'Hello',
			]
		);

		$sent = fiction_drafts_test_sent_mail();

		$this->assertSame( [ 'Reply-To: ada@example.com' ], $sent[0]['headers'] );
		$this->assertStringContainsString( '(no name given)', $sent[0]['message'] );
	}

	public function testNoAdminEmailConfiguredIsA500RatherThanASilentFailure(): void {
		update_option( 'admin_email', '' );

		$result = $this->send(
			[
				'email'   => 'ada@example.com',
				'message' => 'Hello',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 500, $result->get_error_status() );
		$this->assertSame( 'fiction_drafts_no_admin_email', $result->get_error_code() );
		$this->assertCount( 0, fiction_drafts_test_sent_mail() );
	}

	public function testAFailedSendIsReportedRatherThanClaimedAsSuccess(): void {
		fiction_drafts_test_set_mail_result( false );

		$result = $this->send(
			[
				'email'   => 'ada@example.com',
				'message' => 'Hello',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 500, $result->get_error_status() );
		$this->assertSame( 'fiction_drafts_not_sent', $result->get_error_code() );
	}
}
