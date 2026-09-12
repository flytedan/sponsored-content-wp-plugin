<?php
/**
 * Inbound webhook: sponsored.flytedesk.com confirms Accepted/Rejected.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles `POST /flytedesk-registration-confirmation` - a bare top-level
 * URL (not under `/wp-json/`), per the integration contract with
 * sponsored.flytedesk.com. WordPress's REST API framework always prefixes
 * routes with `/wp-json/{namespace}/`, so this exact URL shape needs the
 * lower-level rewrite-rule + query-var + `template_redirect` mechanism
 * instead of `register_rest_route()`.
 *
 * The request body contract is exactly `{ "status": "Accepted"|"Rejected" }`
 * with no token field, so authentication travels out-of-band as an
 * `Authorization: Bearer <verification_token>` header instead - the same
 * token this site sent during registration (see {@see Client::register()}).
 * Without this, any request to this public, unauthenticated-by-design URL
 * could flip a site's registration status; sponsored.flytedesk.com already
 * has the token from the registration payload, so echoing it back costs
 * nothing on that side.
 *
 * sponsored.flytedesk.com also sends a `{ "status": "ping" }` (or
 * `"Connected"` - accepted case-insensitively, since the exact literal
 * value used by their implementation wasn't confirmed at the time this was
 * written) call to this same endpoint immediately after registration, to
 * report reachability back before a human has reviewed anything. Unlike
 * Accepted/Rejected, this does not change the registration's overall
 * status - it only records a timestamp ({@see Client::mark_ping_received()})
 * that drives the settings page timeline's "Connected" step.
 *
 * {@see handle()} is a pure function of its inputs (no superglobals, no
 * output, no exit) so it can be unit-tested directly; {@see maybe_dispatch()}
 * is the thin I/O wrapper that reads the real request and prints the result.
 */
class ConfirmationController {

	private const QUERY_VAR = 'flytedesk_registration_confirmation';

	private const REWRITE_PATTERN = '^flytedesk-registration-confirmation/?$';

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_dispatch' ) );
	}

	public function add_rewrite_rule(): void {
		add_rewrite_rule( self::REWRITE_PATTERN, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public function maybe_dispatch(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$result = $this->handle(
			isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			$this->bearer_token_from_request(),
			(string) file_get_contents( 'php://input' )
		);

		status_header( $result['status'] );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $result['body'] );
		exit;
	}

	/**
	 * Core logic, deliberately free of superglobals/IO so it can be
	 * exercised directly in tests.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	public function handle( string $method, string $token, string $raw_body ): array {
		if ( 'POST' !== strtoupper( $method ) ) {
			return $this->error_result( 405, 'method_not_allowed', __( 'Only POST is accepted.', 'flytedesk-hosted-content' ) );
		}

		$expected_token = $this->client->get_token();

		if ( '' === $expected_token || '' === $token || ! hash_equals( $expected_token, $token ) ) {
			return $this->error_result( 401, 'unauthorized', __( 'Invalid or missing verification token.', 'flytedesk-hosted-content' ) );
		}

		$data = json_decode( $raw_body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return $this->error_result( 400, 'invalid_json', __( 'Request body must be a valid JSON object.', 'flytedesk-hosted-content' ) );
		}

		$requested_status = isset( $data['status'] ) ? (string) $data['status'] : '';

		if ( in_array( strtolower( $requested_status ), array( 'ping', 'connected' ), true ) ) {
			$this->client->mark_ping_received();

			return array(
				'status' => 200,
				'body'   => array( 'status' => 'Connected' ),
			);
		}

		$status_map = array(
			'Accepted' => Client::STATUS_ACCEPTED,
			'Rejected' => Client::STATUS_REJECTED,
		);

		if ( ! isset( $status_map[ $requested_status ] ) ) {
			return $this->error_result( 400, 'invalid_status', __( 'status must be "Accepted", "Rejected", or "ping".', 'flytedesk-hosted-content' ) );
		}

		$this->client->mark_resolved( $status_map[ $requested_status ] );

		return array(
			'status' => 200,
			'body'   => array( 'status' => $requested_status ),
		);
	}

	/**
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function error_result( int $status, string $code, string $message ): array {
		return array(
			'status' => $status,
			'body'   => array(
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
		);
	}

	private function bearer_token_from_request(): string {
		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';

		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			$header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
		}

		if ( 0 === stripos( $header, 'Bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}

		return '';
	}
}
