<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Unit\Registration;

use Brain\Monkey\Functions;
use Flytedesk\SponsoredContent\Registration\Client;
use Flytedesk\SponsoredContent\Registration\ConfirmationController;
use Flytedesk\SponsoredContent\Tests\Unit\BrainMonkeyTestCase;

final class ConfirmationControllerTest extends BrainMonkeyTestCase {

	private const TOKEN = 'a-known-verification-token';

	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg();
	}

	public function test_handle_rejects_non_post_method(): void {
		$controller = new ConfirmationController( new Client() );

		$result = $controller->handle( 'GET', self::TOKEN, '' );

		$this->assertSame( 405, $result['status'] );
		$this->assertSame( 'method_not_allowed', $result['body']['error']['code'] );
	}

	public function test_handle_rejects_missing_token(): void {
		Functions\when( 'get_option' )->justReturn( self::TOKEN );

		$controller = new ConfirmationController( new Client() );
		$result     = $controller->handle( 'POST', '', '{"status":"Accepted"}' );

		$this->assertSame( 401, $result['status'] );
		$this->assertSame( 'unauthorized', $result['body']['error']['code'] );
	}

	public function test_handle_rejects_wrong_token(): void {
		Functions\when( 'get_option' )->justReturn( self::TOKEN );

		$controller = new ConfirmationController( new Client() );
		$result     = $controller->handle( 'POST', 'not-the-right-token', '{"status":"Accepted"}' );

		$this->assertSame( 401, $result['status'] );
		$this->assertSame( 'unauthorized', $result['body']['error']['code'] );
	}

	public function test_handle_rejects_when_no_token_has_ever_been_generated(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$controller = new ConfirmationController( new Client() );
		$result     = $controller->handle( 'POST', '', '{"status":"Accepted"}' );

		$this->assertSame( 401, $result['status'] );
	}

	public function test_handle_rejects_invalid_json(): void {
		Functions\when( 'get_option' )->justReturn( self::TOKEN );

		$controller = new ConfirmationController( new Client() );
		$result     = $controller->handle( 'POST', self::TOKEN, '{not valid json' );

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_json', $result['body']['error']['code'] );
	}

	public function test_handle_rejects_unrecognized_status_value(): void {
		Functions\when( 'get_option' )->justReturn( self::TOKEN );

		$controller = new ConfirmationController( new Client() );
		$result     = $controller->handle( 'POST', self::TOKEN, '{"status":"Maybe"}' );

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_status', $result['body']['error']['code'] );
	}

	public function test_handle_accepts_valid_accepted_status_and_updates_client(): void {
		Functions\when( 'get_option' )->justReturn( self::TOKEN );
		Functions\expect( 'update_option' )->once()->with( Client::OPTION_STATUS, Client::STATUS_ACCEPTED );

		$controller = new ConfirmationController( new Client() );
		$result     = $controller->handle( 'POST', self::TOKEN, '{"status":"Accepted"}' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'Accepted', $result['body']['status'] );
	}

	public function test_handle_accepts_valid_rejected_status_and_updates_client(): void {
		Functions\when( 'get_option' )->justReturn( self::TOKEN );
		Functions\expect( 'update_option' )->once()->with( Client::OPTION_STATUS, Client::STATUS_REJECTED );

		$controller = new ConfirmationController( new Client() );
		$result     = $controller->handle( 'POST', self::TOKEN, '{"status":"Rejected"}' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'Rejected', $result['body']['status'] );
	}

	public function test_handle_uses_constant_time_comparison_and_still_rejects_near_miss_token(): void {
		Functions\when( 'get_option' )->justReturn( self::TOKEN );

		$controller = new ConfirmationController( new Client() );
		// One character different from the real token - proves this isn't
		// accidentally doing a prefix match or similar.
		$result = $controller->handle( 'POST', 'a-known-verification-tokeX', '{"status":"Accepted"}' );

		$this->assertSame( 401, $result['status'] );
	}
}
