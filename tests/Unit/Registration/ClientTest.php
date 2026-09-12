<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Unit\Registration;

use Brain\Monkey\Functions;
use Flytedesk\HostedContent\Registration\ApiCredential;
use Flytedesk\HostedContent\Registration\Client;
use Flytedesk\HostedContent\Registration\VerificationTracker;
use Flytedesk\HostedContent\Tests\Unit\BrainMonkeyTestCase;
use RuntimeException;

final class ClientTest extends BrainMonkeyTestCase {

	public function test_ensure_initial_state_seeds_token_status_and_created_at_when_absent(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-10 12:00:00' );
		Functions\expect( 'get_option' )->once()->with( Client::OPTION_TOKEN, false )->andReturn( false );
		Functions\expect( 'add_option' )->once()->with(
			Client::OPTION_TOKEN,
			\Mockery::on(
				static function ( $token ): bool {
					return is_string( $token ) && 64 === strlen( $token ) && (bool) preg_match( '/^[0-9a-f]+$/', $token );
				}
			)
		);
		Functions\expect( 'get_option' )->once()->with( Client::OPTION_STATUS, false )->andReturn( false );
		Functions\expect( 'add_option' )->once()->with( Client::OPTION_STATUS, Client::STATUS_PENDING );
		Functions\expect( 'add_option' )->once()->with( Client::OPTION_CREATED_AT, '2026-09-10 12:00:00' );

		( new Client() )->ensure_initial_state();
	}

	public function test_ensure_initial_state_does_not_overwrite_existing_values(): void {
		Functions\expect( 'get_option' )->once()->with( Client::OPTION_TOKEN, false )->andReturn( 'existing-token' );
		Functions\expect( 'get_option' )->once()->with( Client::OPTION_STATUS, false )->andReturn( Client::STATUS_ACCEPTED );
		Functions\expect( 'add_option' )->never();

		( new Client() )->ensure_initial_state();
	}

	public function test_get_status_defaults_to_pending(): void {
		// A real get_option() returns its $default argument when the option
		// doesn't exist - simulate that, rather than a blanket stub value,
		// since it's exactly the behaviour this test means to verify.
		Functions\when( 'get_option' )->alias( static fn( string $key, $fallback = false ) => $fallback );

		$this->assertSame( Client::STATUS_PENDING, ( new Client() )->get_status() );
	}

	public function test_mark_ping_received_records_a_timestamp_without_touching_status(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-11 09:00:00' );
		Functions\expect( 'update_option' )->once()->with( Client::OPTION_PING_RECEIVED_AT, '2026-09-11 09:00:00' );
		Functions\expect( 'update_option' )->with( Client::OPTION_STATUS, \Mockery::any() )->never();

		( new Client() )->mark_ping_received();
	}

	public function test_get_ping_received_at_defaults_to_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( '', ( new Client() )->get_ping_received_at() );
	}

	public function test_get_site_domain_parses_host_from_home_url(): void {
		Functions\when( 'home_url' )->justReturn( 'https://publisher.example.com/blog' );
		Functions\when( 'wp_parse_url' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this closure IS the wp_parse_url() stub, so it must call the native parse_url() it stands in for.
			static fn( string $url, int $component ) => parse_url( $url, $component )
		);

		$this->assertSame( 'publisher.example.com', ( new Client() )->get_site_domain() );
	}

	public function test_register_sends_the_issued_credential_and_sets_sent_status_on_http_200(): void {
		Functions\when( 'get_option' )->justReturn( 'the-token' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Publisher Site' );
		Functions\when( 'home_url' )->justReturn( 'https://publisher.example.com' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this closure IS the wp_parse_url() stub, so it must call the native parse_url() it stands in for.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"received":true}' );
		Functions\when( 'current_time' )->justReturn( '2026-09-10 12:00:00' );

		Functions\expect( 'wp_json_encode' )->once()->with(
			\Mockery::on(
				static function ( $body ): bool {
					return 'flytebot' === $body['api_username'] && 'freshly-issued-app-password' === $body['api_key'];
				}
			)
		)->andReturn( '{}' );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array( 'response' => array( 'code' => 200 ) ) );

		$options = array();
		$this->capture_update_option( $options );
		Functions\expect( 'delete_option' )->once()->with( Client::OPTION_LAST_ERROR );
		Functions\expect( 'delete_option' )->once()->with( Client::OPTION_RESOLVED_AT );
		Functions\expect( 'delete_option' )->once()->with( Client::OPTION_PING_RECEIVED_AT );

		$verification_tracker = \Mockery::mock( VerificationTracker::class );
		$verification_tracker->shouldReceive( 'delete_all_data' )->once();

		( new Client( $this->api_credential_that_issues( 'flytebot', 'freshly-issued-app-password' ), $verification_tracker ) )->register();

		$this->assertSame( '2026-09-10 12:00:00', $options[ Client::OPTION_LAST_ATTEMPT_AT ] );
		$this->assertSame( Client::STATUS_SENT, $options[ Client::OPTION_STATUS ] );
		$this->assertSame( '2026-09-10 12:00:00', $options[ Client::OPTION_SENT_AT ] );
		$this->assertSame( 200, $options[ Client::OPTION_LAST_HTTP_STATUS ] );
		$this->assertSame( '{"received":true}', $options[ Client::OPTION_LAST_RESPONSE_BODY ] );
		$this->assertArrayNotHasKey( 'api_key', $options[ Client::OPTION_LAST_REQUEST ] );
		$this->assertSame( 'flytebot', $options[ Client::OPTION_LAST_REQUEST ]['api_username'] );
	}

	public function test_register_records_error_and_leaves_status_unchanged_on_non_200(): void {
		Functions\when( 'get_option' )->justReturn( 'the-token' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Publisher Site' );
		Functions\when( 'home_url' )->justReturn( 'https://publisher.example.com' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this closure IS the wp_parse_url() stub, so it must call the native parse_url() it stands in for.
		Functions\when( 'wp_json_encode' )->justReturn( '{}' );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 500 ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'Internal Server Error' );
		Functions\when( 'current_time' )->justReturn( '2026-09-10 12:00:00' );
		Functions\when( '__' )->returnArg();

		$options = array();
		$this->capture_update_option( $options );

		( new Client( $this->api_credential_that_issues( 'flytebot', 'some-password' ) ) )->register();

		$this->assertArrayNotHasKey( Client::OPTION_STATUS, $options );
		$this->assertSame( 500, $options[ Client::OPTION_LAST_HTTP_STATUS ] );
		$this->assertStringContainsString( '500', $options[ Client::OPTION_LAST_ERROR ] );
	}

	/**
	 * A failed re-registration attempt must not wipe out verification
	 * evidence from an already-successful earlier cycle - only a *new*
	 * successfully-sent registration should start a fresh verification
	 * window (see {@see VerificationTracker::delete_all_data()}).
	 */
	public function test_register_does_not_reset_verification_data_on_a_failed_attempt(): void {
		Functions\when( 'get_option' )->justReturn( 'the-token' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Publisher Site' );
		Functions\when( 'home_url' )->justReturn( 'https://publisher.example.com' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this closure IS the wp_parse_url() stub, so it must call the native parse_url() it stands in for.
		Functions\when( 'wp_json_encode' )->justReturn( '{}' );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 500 ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'Internal Server Error' );
		Functions\when( 'current_time' )->justReturn( '2026-09-10 12:00:00' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'update_option' )->justReturn( true );

		$verification_tracker = \Mockery::mock( VerificationTracker::class );
		$verification_tracker->shouldReceive( 'delete_all_data' )->never();

		( new Client( $this->api_credential_that_issues( 'flytebot', 'some-password' ), $verification_tracker ) )->register();
	}

	public function test_register_records_wp_error_message_on_network_failure(): void {
		Functions\when( 'get_option' )->justReturn( 'the-token' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Publisher Site' );
		Functions\when( 'home_url' )->justReturn( 'https://publisher.example.com' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this closure IS the wp_parse_url() stub, so it must call the native parse_url() it stands in for.
		Functions\when( 'wp_json_encode' )->justReturn( '{}' );
		Functions\when( 'current_time' )->justReturn( '2026-09-10 12:00:00' );

		$wp_error = \Mockery::mock( 'WP_Error' );
		$wp_error->shouldReceive( 'get_error_message' )->once()->andReturn( 'Connection timed out' );

		Functions\when( 'wp_remote_post' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$options = array();
		$this->capture_update_option( $options );
		Functions\expect( 'wp_remote_retrieve_response_code' )->never();
		Functions\expect( 'wp_remote_retrieve_body' )->never();

		( new Client( $this->api_credential_that_issues( 'flytebot', 'some-password' ) ) )->register();

		$this->assertSame( 'Connection timed out', $options[ Client::OPTION_LAST_ERROR ] );
		$this->assertSame( 0, $options[ Client::OPTION_LAST_HTTP_STATUS ] );
		$this->assertSame( '', $options[ Client::OPTION_LAST_RESPONSE_BODY ] );
	}

	public function test_register_records_error_and_never_calls_the_endpoint_when_credential_issuance_fails(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-10 12:00:00' );

		$api_credential = \Mockery::mock( ApiCredential::class );
		$api_credential->shouldReceive( 'get_username' )->once()->andReturn( 'flytebot' );
		$api_credential->shouldReceive( 'issue' )->once()->andThrow( new RuntimeException( 'Could not create the flytebot user.' ) );

		$options = array();
		$this->capture_update_option( $options );
		Functions\expect( 'wp_remote_post' )->never();

		( new Client( $api_credential ) )->register();

		$this->assertSame( 'Could not create the flytebot user.', $options[ Client::OPTION_LAST_ERROR ] );
		$this->assertSame( 0, $options[ Client::OPTION_LAST_HTTP_STATUS ] );
	}

	public function test_delete_all_data_deletes_every_option_this_class_owns(): void {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( string $option ) use ( &$deleted ): bool {
				$deleted[] = $option;

				return true;
			}
		);

		( new Client() )->delete_all_data();

		$expected = array(
			Client::OPTION_STATUS,
			Client::OPTION_TOKEN,
			Client::OPTION_LAST_ERROR,
			Client::OPTION_CREATED_AT,
			Client::OPTION_SENT_AT,
			Client::OPTION_PING_RECEIVED_AT,
			Client::OPTION_RESOLVED_AT,
			Client::OPTION_LAST_ATTEMPT_AT,
			Client::OPTION_LAST_HTTP_STATUS,
			Client::OPTION_LAST_REQUEST,
			Client::OPTION_LAST_RESPONSE_BODY,
		);

		sort( $expected );
		sort( $deleted );

		$this->assertSame( $expected, $deleted );
	}

	private function api_credential_that_issues( string $username, string $password ): ApiCredential {
		$api_credential = \Mockery::mock( ApiCredential::class );
		$api_credential->shouldReceive( 'get_username' )->once()->andReturn( $username );
		$api_credential->shouldReceive( 'issue' )->once()->andReturn( $password );

		return $api_credential;
	}

	/**
	 * Stubs update_option() to capture every (key => value) it's called
	 * with into `$captured` (passed by reference), instead of asserting on
	 * each call individually - register() now makes several such calls per
	 * attempt, and this reads far more clearly than a long chain of
	 * `Functions\expect(...)->once()->with(...)`.
	 *
	 * @param array<string, mixed> $captured
	 */
	private function capture_update_option( array &$captured ): void {
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$captured ): bool {
				$captured[ $key ] = $value;

				return true;
			}
		);
	}
}
