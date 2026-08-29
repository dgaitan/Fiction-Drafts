<?php

declare( strict_types=1 );

namespace FictionDrafts\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Feature Request tab's one action: mail the developer.
 *
 * There is nothing to store. A message that fails to send is not retried and
 * leaves no row behind — the visitor is told immediately, and can resend the
 * way they would resend any email that bounced.
 */
final class FeatureRequestController extends AbstractController {

	/**
	 * The request types the form offers, and nothing else.
	 *
	 * @var array<int, string>
	 */
	private const TYPES = [ 'feature_request', 'bug_report', 'question', 'other' ];

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/feature-request',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'send' ],
					'permission_callback' => [ $this, 'permissionCheck' ],
					'args'                => [
						'name'    => [
							'type'     => 'string',
							'required' => false,
						],
						'email'   => [
							'type'     => 'string',
							'required' => true,
						],
						'type'    => [
							'type'     => 'string',
							'required' => false,
						],
						'message' => [
							'type'     => 'string',
							'required' => true,
						],
					],
				],
			]
		);
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send( WP_REST_Request $request ) {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( '' === $email || false === is_email( $email ) ) {
			return $this->error(
				'fiction_drafts_invalid_email',
				__( 'Enter a valid email address so the developer can reply.', 'fiction-drafts' ),
				400
			);
		}

		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );

		if ( '' === $message ) {
			return $this->error(
				'fiction_drafts_empty_message',
				__( 'Write a message before sending.', 'fiction-drafts' ),
				400
			);
		}

		$typeParam = $request->get_param( 'type' );
		$type      = sanitize_key( is_string( $typeParam ) && '' !== $typeParam ? $typeParam : 'other' );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return $this->error(
				'fiction_drafts_unknown_type',
				__( 'That request type does not exist.', 'fiction-drafts' ),
				400
			);
		}

		$name = trim( sanitize_text_field( (string) ( $request->get_param( 'name' ) ?? '' ) ) );

		// The site's own contact address, not a literal — the developer's inbox
		// is not this plugin's business to hardcode, and the admin is free to
		// point it anywhere.
		$to = sanitize_email( (string) get_option( 'admin_email', '' ) );

		if ( '' === $to ) {
			return $this->error(
				'fiction_drafts_no_admin_email',
				__( 'No site admin email is configured to receive this.', 'fiction-drafts' ),
				500
			);
		}

		$sent = wp_mail(
			$to,
			self::subject( $type ),
			self::body( $name, $email, $type, $message ),
			[ 'Reply-To: ' . self::replyTo( $name, $email ) ]
		);

		if ( ! $sent ) {
			return $this->error(
				'fiction_drafts_not_sent',
				__( 'The message could not be sent. Try again in a moment.', 'fiction-drafts' ),
				500
			);
		}

		return $this->respond( [ 'sent' => true ] );
	}

	private static function replyTo( string $name, string $email ): string {
		return '' !== $name ? "{$name} <{$email}>" : $email;
	}

	private static function subject( string $type ): string {
		return sprintf(
			/* translators: %s: the kind of message — feature request, bug report, question, or other. */
			__( '[Fiction Drafts] %s', 'fiction-drafts' ),
			self::typeLabel( $type )
		);
	}

	private static function typeLabel( string $type ): string {
		return match ( $type ) {
			'feature_request' => __( 'Feature request', 'fiction-drafts' ),
			'bug_report'       => __( 'Bug report', 'fiction-drafts' ),
			'question'         => __( 'Question', 'fiction-drafts' ),
			default            => __( 'Message', 'fiction-drafts' ),
		};
	}

	private static function body( string $name, string $email, string $type, string $message ): string {
		return implode(
			"\n",
			[
				sprintf(
					/* translators: 1: sender name, or a placeholder when none was given. 2: sender email. */
					__( 'From: %1$s <%2$s>', 'fiction-drafts' ),
					'' !== $name ? $name : __( '(no name given)', 'fiction-drafts' ),
					$email
				),
				sprintf(
					/* translators: %s: the request type label. */
					__( 'Type: %s', 'fiction-drafts' ),
					self::typeLabel( $type )
				),
				sprintf(
					/* translators: %s: the site URL the message came from. */
					__( 'Site: %s', 'fiction-drafts' ),
					get_site_url()
				),
				'',
				$message,
			]
		);
	}
}
