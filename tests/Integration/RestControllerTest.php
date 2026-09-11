<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Integration;

use Flytedesk\SponsoredContent\PostType;
use Flytedesk\SponsoredContent\Registration\VerificationTracker;
use WP_REST_Request;
use WP_Test_REST_TestCase;

/**
 * Exercises all four `flytedesk/v1` routes against a real WordPress
 * install (provided by `wp-env`'s `tests-cli` container - see
 * tests/bootstrap.php), including REST route registration, the
 * Application Passwords-backed permission gate, `wp_insert_post()`, and
 * the `{ "error": {...} }` error envelope end to end.
 *
 * WP_Test_REST_TestCase (part of WordPress core's own PHPUnit test suite)
 * wires up a fresh WP_REST_Server via the `rest_api_init` action for every
 * test, exposed as `$this->server`.
 */
final class RestControllerTest extends WP_Test_REST_TestCase {

	private int $author_id;

	private int $subscriber_id;

	public function set_up(): void {
		parent::set_up();

		$this->author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_create_requires_authentication(): void {
		$request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $this->valid_payload() ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'unauthorized', $response->get_data()['error']['code'] );
	}

	public function test_create_requires_edit_posts_capability(): void {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $this->valid_payload() ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'unauthorized', $response->get_data()['error']['code'] );
	}

	public function test_create_rejects_missing_required_fields(): void {
		wp_set_current_user( $this->author_id );

		$request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
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
		wp_set_current_user( $this->author_id );

		$request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
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
		wp_set_current_user( $this->author_id );

		$request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
		$request->set_body( '{not valid json' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_json', $response->get_data()['error']['code'] );
	}

	public function test_full_create_read_update_delete_lifecycle(): void {
		wp_set_current_user( $this->author_id );

		$verification_tracker = new VerificationTracker();
		$this->assertFalse( $verification_tracker->is_fully_verified() );

		// Create.
		$create_request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
		$create_request->set_header( 'content-type', 'application/json' );
		$create_request->set_body( wp_json_encode( $this->valid_payload() ) );

		$create_response = rest_get_server()->dispatch( $create_request );
		$created         = $create_response->get_data();

		$this->assertSame( 201, $create_response->get_status() );
		$this->assertSame( 'Back-to-School Advertising Tips', $created['title'] );
		$this->assertSame( 'publish', $created['status'] );
		$this->assertSame( 'A quick primer.', $created['description'] );
		$this->assertSame( 'Seasonal placement strategy.', $created['seo']['meta_description'] );

		$post_id = $created['id'];
		$post    = get_post( $post_id );
		$this->assertSame( PostType::POST_TYPE, $post->post_type );
		$this->assertStringContainsString( '<h2>Why timing matters</h2>', $post->post_content );
		$this->assertNotSame( '', $verification_tracker->get_create_verified_at() );

		// Read.
		$read_request  = new WP_REST_Request( 'GET', '/flytedesk/v1/posts/' . $post_id );
		$read_response = rest_get_server()->dispatch( $read_request );

		$this->assertSame( 200, $read_response->get_status() );
		$this->assertSame( $created, $read_response->get_data() );

		// Update (full replacement).
		$updated_payload            = $this->valid_payload();
		$updated_payload['title']   = 'Updated Title';
		$updated_payload['content'] = 'Updated body.';

		$update_request = new WP_REST_Request( 'PUT', '/flytedesk/v1/posts/' . $post_id );
		$update_request->set_header( 'content-type', 'application/json' );
		$update_request->set_body( wp_json_encode( $updated_payload ) );

		$update_response = rest_get_server()->dispatch( $update_request );
		$updated         = $update_response->get_data();

		$this->assertSame( 200, $update_response->get_status() );
		$this->assertSame( 'Updated Title', $updated['title'] );
		$this->assertStringContainsString( 'Updated body.', get_post( $post_id )->post_content );
		$this->assertNotSame( '', $verification_tracker->get_update_verified_at() );

		// Delete.
		$delete_request  = new WP_REST_Request( 'DELETE', '/flytedesk/v1/posts/' . $post_id );
		$delete_response = rest_get_server()->dispatch( $delete_request );

		$this->assertSame( 204, $delete_response->get_status() );
		$this->assertSame( 'trash', get_post( $post_id )->post_status );
		$this->assertNotSame( '', $verification_tracker->get_delete_verified_at() );
		$this->assertTrue( $verification_tracker->is_fully_verified() );
	}

	public function test_get_unknown_id_returns_404(): void {
		wp_set_current_user( $this->author_id );

		$request  = new WP_REST_Request( 'GET', '/flytedesk/v1/posts/999999' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['error']['code'] );
	}

	public function test_get_returns_404_for_a_post_of_a_different_post_type(): void {
		wp_set_current_user( $this->author_id );

		$other_post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$request  = new WP_REST_Request( 'GET', '/flytedesk/v1/posts/' . $other_post_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_content_html_is_sanitized_through_wp_kses_post(): void {
		wp_set_current_user( $this->author_id );

		$payload            = $this->valid_payload();
		$payload['content'] = "Safe text.\n\n<script>alert('xss')</script>";

		$request = new WP_REST_Request( 'POST', '/flytedesk/v1/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = rest_get_server()->dispatch( $request );
		$post     = get_post( $response->get_data()['id'] );

		$this->assertStringNotContainsString( '<script>', $post->post_content );
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
}
