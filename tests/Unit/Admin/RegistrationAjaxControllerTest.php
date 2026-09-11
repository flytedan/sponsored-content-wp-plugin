<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Flytedesk\SponsoredContent\Admin\RegistrationAjaxController;
use Flytedesk\SponsoredContent\Registration\Client;
use Flytedesk\SponsoredContent\Registration\StatePresenter;
use Flytedesk\SponsoredContent\Tests\Unit\BrainMonkeyTestCase;

final class RegistrationAjaxControllerTest extends BrainMonkeyTestCase {

	public function test_handle_status_returns_current_state_when_authorized(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );

		$client = \Mockery::mock( Client::class );
		$client->shouldReceive( 'register' )->never();

		$state_presenter = \Mockery::mock( StatePresenter::class );
		$state_presenter->shouldReceive( 'to_array' )->once()->andReturn( array( 'status' => 'pending' ) );

		Functions\expect( 'wp_send_json_success' )->once()->with( array( 'status' => 'pending' ) );
		Functions\expect( 'wp_send_json_error' )->never();

		( new RegistrationAjaxController( $client, $state_presenter ) )->handle_status();
	}

	public function test_handle_status_sends_403_and_does_not_return_state_when_not_authorized(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->returnArg();

		$client          = \Mockery::mock( Client::class );
		$state_presenter = \Mockery::mock( StatePresenter::class );
		$state_presenter->shouldReceive( 'to_array' )->never();

		Functions\expect( 'wp_send_json_error' )->once()->with( \Mockery::type( 'array' ), 403 );
		Functions\expect( 'wp_send_json_success' )->never();
		Functions\expect( 'check_ajax_referer' )->never();

		( new RegistrationAjaxController( $client, $state_presenter ) )->handle_status();
	}

	public function test_handle_register_runs_registration_then_returns_updated_state(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );

		$client = \Mockery::mock( Client::class );
		$client->shouldReceive( 'register' )->once();

		$state_presenter = \Mockery::mock( StatePresenter::class );
		$state_presenter->shouldReceive( 'to_array' )->once()->andReturn( array( 'status' => 'sent' ) );

		Functions\expect( 'wp_send_json_success' )->once()->with( array( 'status' => 'sent' ) );

		( new RegistrationAjaxController( $client, $state_presenter ) )->handle_register();
	}

	public function test_handle_status_sends_403_json_error_on_bad_nonce_instead_of_dying(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( false );
		Functions\when( '__' )->returnArg();

		$client          = \Mockery::mock( Client::class );
		$state_presenter = \Mockery::mock( StatePresenter::class );
		$state_presenter->shouldReceive( 'to_array' )->never();

		Functions\expect( 'wp_send_json_error' )->once()->with( \Mockery::type( 'array' ), 403 );
		Functions\expect( 'wp_send_json_success' )->never();

		( new RegistrationAjaxController( $client, $state_presenter ) )->handle_status();
	}

	public function test_handle_register_does_not_run_registration_when_not_authorized(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_send_json_error' )->justReturn( null );

		$client = \Mockery::mock( Client::class );
		$client->shouldReceive( 'register' )->never();

		$state_presenter = \Mockery::mock( StatePresenter::class );
		$state_presenter->shouldReceive( 'to_array' )->never();

		( new RegistrationAjaxController( $client, $state_presenter ) )->handle_register();
	}
}
