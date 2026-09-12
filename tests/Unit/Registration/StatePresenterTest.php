<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Unit\Registration;

use Brain\Monkey\Functions;
use Flytedesk\SponsoredContent\Registration\ApiCredential;
use Flytedesk\SponsoredContent\Registration\Client;
use Flytedesk\SponsoredContent\Registration\Consent;
use Flytedesk\SponsoredContent\Registration\StatePresenter;
use Flytedesk\SponsoredContent\Registration\VerificationTracker;
use Flytedesk\SponsoredContent\Tests\Unit\BrainMonkeyTestCase;

final class StatePresenterTest extends BrainMonkeyTestCase {

	public function test_to_array_assembles_every_field_from_client_and_api_credential(): void {
		$client = \Mockery::mock( Client::class );
		$client->shouldReceive( 'get_status' )->once()->andReturn( Client::STATUS_SENT );
		$client->shouldReceive( 'get_site_domain' )->once()->andReturn( 'publisher.example.com' );
		$client->shouldReceive( 'get_token' )->once()->andReturn( 'the-token' );
		$client->shouldReceive( 'get_created_at' )->once()->andReturn( '2026-09-01 10:00:00' );
		$client->shouldReceive( 'get_sent_at' )->once()->andReturn( '2026-09-02 11:00:00' );
		$client->shouldReceive( 'get_ping_received_at' )->once()->andReturn( '2026-09-02 11:00:05' );
		$client->shouldReceive( 'get_resolved_at' )->once()->andReturn( '' );
		$client->shouldReceive( 'get_last_error' )->once()->andReturn( 'oops' );
		$client->shouldReceive( 'get_last_attempt_at' )->once()->andReturn( '2026-09-02 11:00:00' );
		$client->shouldReceive( 'get_last_http_status' )->once()->andReturn( 500 );
		$client->shouldReceive( 'get_last_request' )->once()->andReturn( array( 'site_domain' => 'publisher.example.com' ) );
		$client->shouldReceive( 'get_last_response_body' )->once()->andReturn( 'Internal Server Error' );

		$api_credential = \Mockery::mock( ApiCredential::class );
		$api_credential->shouldReceive( 'get_username' )->once()->andReturn( 'flytebot' );

		$verification_tracker = \Mockery::mock( VerificationTracker::class );
		$verification_tracker->shouldReceive( 'get_create_verified_at' )->once()->andReturn( '2026-09-02 11:05:00' );
		$verification_tracker->shouldReceive( 'get_update_verified_at' )->once()->andReturn( '' );
		$verification_tracker->shouldReceive( 'get_delete_verified_at' )->once()->andReturn( '' );
		$verification_tracker->shouldReceive( 'get_verified_at' )->once()->andReturn( '' );
		$verification_tracker->shouldReceive( 'is_fully_verified' )->once()->andReturn( false );

		$consent = \Mockery::mock( Consent::class );
		$consent->shouldReceive( 'has_been_granted' )->once()->andReturn( true );
		$consent->shouldReceive( 'get_granted_at' )->once()->andReturn( '2026-09-02 10:00:00' );
		$consent->shouldReceive( 'get_granted_by_login' )->once()->andReturn( 'admin' );

		Functions\when( 'get_option' )->justReturn( 'F j, Y g:i a' );
		Functions\when( 'mysql2date' )->alias(
			static fn( string $format, string $date ) => 'formatted(' . $date . ')'
		);

		$state = ( new StatePresenter( $client, $api_credential, $verification_tracker, $consent ) )->to_array();

		$this->assertSame( Client::STATUS_SENT, $state['status'] );
		$this->assertSame( 'publisher.example.com', $state['site_domain'] );
		$this->assertSame( 'the-token', $state['verification_token'] );
		$this->assertSame( 'flytebot', $state['api_username'] );
		$this->assertSame( 'formatted(2026-09-01 10:00:00)', $state['timeline']['created_at'] );
		$this->assertSame( 'formatted(2026-09-02 11:00:00)', $state['timeline']['sent_at'] );
		$this->assertSame( 'formatted(2026-09-02 11:00:05)', $state['timeline']['ping_received_at'] );
		$this->assertSame( '', $state['timeline']['resolved_at'] );
		$this->assertSame( 'oops', $state['last_error'] );
		$this->assertSame( 'formatted(2026-09-02 11:00:00)', $state['technical']['last_attempt_at'] );
		$this->assertSame( 500, $state['technical']['last_http_status'] );
		$this->assertSame( array( 'site_domain' => 'publisher.example.com' ), $state['technical']['last_request'] );
		$this->assertSame( 'Internal Server Error', $state['technical']['last_response_body'] );
		$this->assertSame( 'formatted(2026-09-02 11:05:00)', $state['verification']['create_at'] );
		$this->assertSame( '', $state['verification']['update_at'] );
		$this->assertSame( '', $state['verification']['delete_at'] );
		$this->assertSame( '', $state['verification']['verified_at'] );
		$this->assertFalse( $state['verification']['all_verified'] );
		$this->assertTrue( $state['consent']['granted'] );
		$this->assertSame( 'formatted(2026-09-02 10:00:00)', $state['consent']['granted_at'] );
		$this->assertSame( 'admin', $state['consent']['granted_by'] );
	}

	public function test_empty_timestamps_are_not_passed_through_mysql2date(): void {
		$client = \Mockery::mock( Client::class );
		$client->shouldReceive( 'get_status' )->andReturn( Client::STATUS_PENDING );
		$client->shouldReceive( 'get_site_domain' )->andReturn( 'publisher.example.com' );
		$client->shouldReceive( 'get_token' )->andReturn( 'the-token' );
		$client->shouldReceive( 'get_created_at' )->andReturn( '' );
		$client->shouldReceive( 'get_sent_at' )->andReturn( '' );
		$client->shouldReceive( 'get_ping_received_at' )->andReturn( '' );
		$client->shouldReceive( 'get_resolved_at' )->andReturn( '' );
		$client->shouldReceive( 'get_last_error' )->andReturn( '' );
		$client->shouldReceive( 'get_last_attempt_at' )->andReturn( '' );
		$client->shouldReceive( 'get_last_http_status' )->andReturn( 0 );
		$client->shouldReceive( 'get_last_request' )->andReturn( array() );
		$client->shouldReceive( 'get_last_response_body' )->andReturn( '' );

		$api_credential = \Mockery::mock( ApiCredential::class );
		$api_credential->shouldReceive( 'get_username' )->andReturn( 'flytebot' );

		$verification_tracker = \Mockery::mock( VerificationTracker::class );
		$verification_tracker->shouldReceive( 'get_create_verified_at' )->andReturn( '' );
		$verification_tracker->shouldReceive( 'get_update_verified_at' )->andReturn( '' );
		$verification_tracker->shouldReceive( 'get_delete_verified_at' )->andReturn( '' );
		$verification_tracker->shouldReceive( 'get_verified_at' )->andReturn( '' );
		$verification_tracker->shouldReceive( 'is_fully_verified' )->andReturn( false );

		$consent = \Mockery::mock( Consent::class );
		$consent->shouldReceive( 'has_been_granted' )->andReturn( false );
		$consent->shouldReceive( 'get_granted_at' )->andReturn( '' );
		$consent->shouldReceive( 'get_granted_by_login' )->andReturn( '' );

		Functions\expect( 'mysql2date' )->never();

		$state = ( new StatePresenter( $client, $api_credential, $verification_tracker, $consent ) )->to_array();

		$this->assertSame( '', $state['timeline']['created_at'] );
		$this->assertSame( '', $state['timeline']['sent_at'] );
		$this->assertSame( '', $state['timeline']['ping_received_at'] );
		$this->assertSame( '', $state['timeline']['resolved_at'] );
		$this->assertSame( '', $state['technical']['last_attempt_at'] );
		$this->assertSame( '', $state['verification']['create_at'] );
		$this->assertSame( '', $state['verification']['verified_at'] );
		$this->assertFalse( $state['verification']['all_verified'] );
	}

	public function test_all_verified_is_true_and_verified_at_is_formatted_once_all_three_operations_have_succeeded(): void {
		$client = \Mockery::mock( Client::class );
		$client->shouldReceive( 'get_status' )->andReturn( Client::STATUS_ACCEPTED );
		$client->shouldReceive( 'get_site_domain' )->andReturn( 'publisher.example.com' );
		$client->shouldReceive( 'get_token' )->andReturn( 'the-token' );
		$client->shouldReceive( 'get_created_at' )->andReturn( '' );
		$client->shouldReceive( 'get_sent_at' )->andReturn( '' );
		$client->shouldReceive( 'get_ping_received_at' )->andReturn( '' );
		$client->shouldReceive( 'get_resolved_at' )->andReturn( '' );
		$client->shouldReceive( 'get_last_error' )->andReturn( '' );
		$client->shouldReceive( 'get_last_attempt_at' )->andReturn( '' );
		$client->shouldReceive( 'get_last_http_status' )->andReturn( 0 );
		$client->shouldReceive( 'get_last_request' )->andReturn( array() );
		$client->shouldReceive( 'get_last_response_body' )->andReturn( '' );

		$api_credential = \Mockery::mock( ApiCredential::class );
		$api_credential->shouldReceive( 'get_username' )->andReturn( 'flytebot' );

		$verification_tracker = \Mockery::mock( VerificationTracker::class );
		$verification_tracker->shouldReceive( 'get_create_verified_at' )->andReturn( '2026-09-11 09:00:00' );
		$verification_tracker->shouldReceive( 'get_update_verified_at' )->andReturn( '2026-09-11 09:05:00' );
		$verification_tracker->shouldReceive( 'get_delete_verified_at' )->andReturn( '2026-09-11 09:10:00' );
		$verification_tracker->shouldReceive( 'get_verified_at' )->andReturn( '2026-09-11 09:10:00' );
		$verification_tracker->shouldReceive( 'is_fully_verified' )->andReturn( true );

		$consent = \Mockery::mock( Consent::class );
		$consent->shouldReceive( 'has_been_granted' )->andReturn( true );
		$consent->shouldReceive( 'get_granted_at' )->andReturn( '2026-09-11 08:00:00' );
		$consent->shouldReceive( 'get_granted_by_login' )->andReturn( 'admin' );

		Functions\when( 'get_option' )->justReturn( 'F j, Y g:i a' );
		Functions\when( 'mysql2date' )->alias(
			static fn( string $format, string $date ) => 'formatted(' . $date . ')'
		);

		$state = ( new StatePresenter( $client, $api_credential, $verification_tracker, $consent ) )->to_array();

		$this->assertSame( 'formatted(2026-09-11 09:00:00)', $state['verification']['create_at'] );
		$this->assertSame( 'formatted(2026-09-11 09:05:00)', $state['verification']['update_at'] );
		$this->assertSame( 'formatted(2026-09-11 09:10:00)', $state['verification']['delete_at'] );
		$this->assertSame( 'formatted(2026-09-11 09:10:00)', $state['verification']['verified_at'] );
		$this->assertTrue( $state['verification']['all_verified'] );
	}
}
