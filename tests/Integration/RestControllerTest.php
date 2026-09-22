<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Integration;

use Flytedesk\HostedContent\PostType;
use Flytedesk\HostedContent\Registration\ApiCredential;
use Flytedesk\HostedContent\Registration\VerificationTracker;
use WP_REST_Request;
use WP_Test_REST_TestCase;

/**
 * Exercises all four `flytedesk/v1` routes against a real WordPress
 * install (provided by `wp-env`'s `tests-cli` container - see
 * tests/bootstrap.php), including REST route registration, the
 * flytedesk-API-key permission gate, `wp_insert_post()`, and the
 * `{ "error": {...} }` error envelope end to end.
 *
 * Deliberately never calls `wp_set_current_user()` with a real user, and
 * every test explicitly runs as a logged-out visitor (`wp_set_current_user( 0 )`
 * in {@see set_up()}) - proving the permission gate genuinely does not depend
 * on any WordPress user, session, or capability, only the `Authorization`
 * header checked against {@see ApiCredential}.
 *
 * WP_Test_REST_TestCase (part of WordPress core's own PHPUnit test suite)
 * wires up a fresh WP_REST_Server via the `rest_api_init` action for every
 * test, exposed as `$this->server`.
 */
final class RestControllerTest extends WP_Test_REST_TestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( 0 );
	}

	public function test_create_requires_authentication(): void {
		$request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $this->valid_payload() ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'unauthorized', $response->get_data()['error']['code'] );
	}

	public function test_create_rejects_an_incorrect_api_key(): void {
		$this->issue_api_key();

		$request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'authorization', 'Bearer ' . str_repeat( 'f', 64 ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $this->valid_payload() ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'unauthorized', $response->get_data()['error']['code'] );
	}

	public function test_create_rejects_an_authorization_header_that_is_not_a_bearer_token(): void {
		$key = $this->issue_api_key();

		$request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'authorization', 'Basic ' . base64_encode( 'user:' . $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture building a Basic-auth header on purpose, to prove the plugin's Bearer-only check rejects it.
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $this->valid_payload() ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_create_rejects_missing_required_fields(): void {
		$request = $this->authenticated_request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'seo' => array() ) ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'validation_failed', $data['error']['code'] );
		$this->assertStringContainsString( 'title is required', $data['error']['message'] );
		$this->assertStringContainsString( 'content is required', $data['error']['message'] );
	}

	/**
	 * With a `Content-Type: application/json` header, WordPress core itself
	 * decodes the body (`WP_REST_Request::parse_json_params()`, invoked
	 * automatically during dispatch) and rejects malformed JSON with its own
	 * `rest_invalid_json` error before our route callback ever runs. This
	 * still proves something real: our `normalize_error_response` filter
	 * reshapes errors WordPress core itself raises into our `{ "error":
	 * {...} }` envelope, not just ones this plugin raises directly.
	 */
	public function test_create_rejects_malformed_json_body_with_json_content_type(): void {
		$request = $this->authenticated_request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( '{not valid json' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_json', $response->get_data()['error']['code'] );
	}

	/**
	 * Without a JSON content-type, WordPress core never attempts to parse
	 * the body as JSON (see `WP_REST_Request::is_json_content_type()`), so
	 * this plugin's own `validate_body()` is the only thing that decodes it
	 * - this is what actually exercises our `invalid_json` error path.
	 */
	public function test_create_rejects_malformed_json_body_without_json_content_type(): void {
		$request = $this->authenticated_request( 'POST', '/flytedesk/v1/posts' );
		$request->set_body( '{not valid json' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_json', $response->get_data()['error']['code'] );
	}

	public function test_full_create_read_update_delete_lifecycle(): void {
		$key = $this->issue_api_key();

		$verification_tracker = new VerificationTracker();
		$this->assertFalse( $verification_tracker->is_fully_verified() );

		// Create.
		$create_request = $this->request_with_key( 'POST', '/flytedesk/v1/posts', $key );
		$create_request->set_header( 'content-type', 'application/json' );
		$create_request->set_body( wp_json_encode( $this->valid_payload() ) );

		$create_response = rest_get_server()->dispatch( $create_request );
		$created         = $create_response->get_data();

		$this->assertSame( 201, $create_response->get_status() );
		$this->assertSame( 'Back-to-School Advertising Tips', $created['title'] );
		$this->assertSame( 'publish', $created['status'] );
		$this->assertSame( 'A quick primer.', $created['description'] );
		$this->assertSame( 'Seasonal placement strategy.', $created['seo']['meta_description'] );
		$this->assertSame( 'article', $created['seo']['og']['type'] );

		$post_id = $created['id'];
		$post    = get_post( $post_id );
		$this->assertSame( PostType::POST_TYPE, $post->post_type );
		$this->assertStringContainsString( '<h2>Why timing matters</h2>', $post->post_content );
		$this->assertNotSame( '', $verification_tracker->get_create_verified_at() );

		// Read.
		$read_request  = $this->request_with_key( 'GET', '/flytedesk/v1/posts/' . $post_id, $key );
		$read_response = rest_get_server()->dispatch( $read_request );

		$this->assertSame( 200, $read_response->get_status() );
		$this->assertSame( $created, $read_response->get_data() );

		// Update (full replacement).
		$updated_payload            = $this->valid_payload();
		$updated_payload['title']   = 'Updated Title';
		$updated_payload['content'] = 'Updated body.';

		$update_request = $this->request_with_key( 'PUT', '/flytedesk/v1/posts/' . $post_id, $key );
		$update_request->set_header( 'content-type', 'application/json' );
		$update_request->set_body( wp_json_encode( $updated_payload ) );

		$update_response = rest_get_server()->dispatch( $update_request );
		$updated         = $update_response->get_data();

		$this->assertSame( 200, $update_response->get_status() );
		$this->assertSame( 'Updated Title', $updated['title'] );
		$this->assertStringContainsString( 'Updated body.', get_post( $post_id )->post_content );
		$this->assertNotSame( '', $verification_tracker->get_update_verified_at() );

		// Delete.
		$delete_request  = $this->request_with_key( 'DELETE', '/flytedesk/v1/posts/' . $post_id, $key );
		$delete_response = rest_get_server()->dispatch( $delete_request );

		$this->assertSame( 204, $delete_response->get_status() );
		$this->assertSame( 'trash', get_post( $post_id )->post_status );
		$this->assertNotSame( '', $verification_tracker->get_delete_verified_at() );
		$this->assertTrue( $verification_tracker->is_fully_verified() );
	}

	public function test_get_unknown_id_returns_404(): void {
		$request  = $this->authenticated_request( 'GET', '/flytedesk/v1/posts/999999' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['error']['code'] );
	}

	public function test_get_returns_404_for_a_post_of_a_different_post_type(): void {
		$other_post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$request  = $this->authenticated_request( 'GET', '/flytedesk/v1/posts/' . $other_post_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_content_html_is_sanitized_through_wp_kses_post(): void {
		$payload            = $this->valid_payload();
		$payload['content'] = "Safe text.\n\n<script>alert('xss')</script>";

		$request = $this->authenticated_request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = rest_get_server()->dispatch( $request );
		$post     = get_post( $response->get_data()['id'] );

		$this->assertStringNotContainsString( '<script>', $post->post_content );
	}

	public function test_og_type_defaults_to_article_when_not_supplied(): void {
		$payload = $this->valid_payload();
		unset( $payload['seo']['og']['type'] );

		$request = $this->authenticated_request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 'article', $response->get_data()['seo']['og']['type'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function valid_payload(): array {
		return array(
			'title'       => 'Back-to-School Advertising Tips',
			'description' => 'A quick primer.',
			'content'     => "## Why timing matters\n\nBack-to-school season is a **high-traffic** window.",
			'seo'         => array(
				'meta_title'       => 'Back-to-School Advertising Tips | flytedesk',
				'meta_description' => 'Seasonal placement strategy.',
				'slug'             => 'back-to-school-advertising-tips',
				'meta_keywords'    => 'back to school, advertising',
				'og'               => array(
					'title'       => 'Back-to-School Advertising Tips',
					'description' => 'Seasonal placement strategy.',
					'image'       => 'https://cdn.flytedesk.com/images/back-to-school.jpg',
					'type'        => 'article',
				),
			),
		);
	}

	/**
	 * Issues a real API key via {@see ApiCredential} against the live
	 * options table, exactly as {@see \Flytedesk\HostedContent\Registration\Client::register()}
	 * does - proving the permission gate checks the same real, hashed,
	 * stored credential a real registration would produce, not a mock.
	 */
	private function issue_api_key(): string {
		return ( new ApiCredential() )->issue();
	}

	private function request_with_key( string $method, string $route, string $key ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'authorization', 'Bearer ' . $key );

		return $request;
	}

	private function authenticated_request( string $method, string $route ): WP_REST_Request {
		return $this->request_with_key( $method, $route, $this->issue_api_key() );
	}
}
