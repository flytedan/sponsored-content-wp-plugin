<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Unit\Registration;

use Brain\Monkey\Functions;
use Flytedesk\SponsoredContent\Registration\ApiCredential;
use Flytedesk\SponsoredContent\Registration\Client;
use Flytedesk\SponsoredContent\Registration\StatePresenter;
use Flytedesk\SponsoredContent\Tests\Unit\BrainMonkeyTestCase;

final class StatePresenterTest extends BrainMonkeyTestCase {

	public function test_to_array_assembles_every_field_from_client_and_api_credential(): void {
		$client = \Mockery::mock( Client::class );
		$client->shouldReceive( 'get_status' )->once()->andReturn( Client::STATUS_SENT );
		$client->shouldReceive( 'get_site_domain' )->once()->andReturn( 'publisher.example.com' );
		$client->shouldReceive( 'get_token' )->once()->andReturn( 'the-token' );
		$client->shouldReceive( 'get_created_at' )->once()->andReturn( '2026-09-01 10:00:00' );
		$client->shouldReceive( 'get_sent_at' )->once()->andReturn( '2026-09-02 11:00:00' );
		$client->shouldReceive( 'get_resolved_at' )->once()->andReturn( '' );
		$client->shouldReceive( 'get_last_error' )->once()->andReturn( 'oops' );
		$client->shouldReceive( 'get_last_attempt_at' )->once()->andReturn( '2026-09-02 11:00:00' );
		$client->shouldReceive( 'get_last_http_status' )->once()->andReturn( 500 );
		$client->shouldReceive( 'get_last_request' )->once()->andReturn( array( 'site_domain' => 'publisher.example.com' ) );
		$client->shouldReceive( 'get_last_response_body' )->once()->andReturn( 'Internal Server Error' );

		$api_credential = \Mockery::mock( ApiCredential::class );
		$api_credential->shouldReceive( 'get_username' )->once()->andReturn( 'flytebot' );

		Functions\when( 'get_option' )->justReturn( 'F j, Y g:i a' );
		Functions\when( 'mysql2date' )->alias(
			static fn( string $format, string $date ) => 'formatted(' . $date . ')'
		);

		$state = ( new StatePresenter( $client, $api_credential ) )->to_array();

		$this->assertSame( Client::STATUS_SENT, $state['status'] );
		$this->assertSame( 'publisher.example.com', $state['site_domain'] );
		$this->assertSame( 'the-token', $state['verification_token'] );
		$this->assertSame( 'flytebot', $state['api_username'] );
		$this->assertSame( 'formatted(2026-09-01 10:00:00)', $state['timeline']['created_at'] );
		$this->assertSame( 'formatted(2026-09-02 11:00:00)', $state['timeline']['sent_at'] );
		$this->assertSame( '', $state['timeline']['resolved_at'] );
		$this->assertSame( 'oops', $state['last_error'] );
		$this->assertSame( 'formatted(2026-09-02 11:00:00)', $state['technical']['last_attempt_at'] );
		$this->assertSame( 500, $state['technical']['last_http_status'] );
		$this->assertSame( array( 'site_domain' => 'publisher.example.com' ), $state['technical']['last_request'] );
		$this->assertSame( 'Internal Server Error', $state['technical']['last_response_body'] );
	}

	public function test_empty_timestamps_are_not_passed_through_mysql2date(): void {
		$client = \Mockery::mock( Client::class );
		$client->shouldReceive( 'get_status' )->andReturn( Client::STATUS_PENDING );
		$client->shouldReceive( 'get_site_domain' )->andReturn( 'publisher.example.com' );
		$client->shouldReceive( 'get_token' )->andReturn( 'the-token' );
		$client->shouldReceive( 'get_created_at' )->andReturn( '' );
		$client->shouldReceive( 'get_sent_at' )->andReturn( '' );
		$client->shouldReceive( 'get_resolved_at' )->andReturn( '' );
		$client->shouldReceive( 'get_last_error' )->andReturn( '' );
		$client->shouldReceive( 'get_last_attempt_at' )->andReturn( '' );
		$client->shouldReceive( 'get_last_http_status' )->andReturn( 0 );
		$client->shouldReceive( 'get_last_request' )->andReturn( array() );
		$client->shouldReceive( 'get_last_response_body' )->andReturn( '' );

		$api_credential = \Mockery::mock( ApiCredential::class );
		$api_credential->shouldReceive( 'get_username' )->andReturn( 'flytebot' );

		Functions\expect( 'mysql2date' )->never();

		$state = ( new StatePresenter( $client, $api_credential ) )->to_array();

		$this->assertSame( '', $state['timeline']['created_at'] );
		$this->assertSame( '', $state['timeline']['sent_at'] );
		$this->assertSame( '', $state['timeline']['resolved_at'] );
		$this->assertSame( '', $state['technical']['last_attempt_at'] );
	}
}
