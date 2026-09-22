<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Integration;

use Flytedesk\HostedContent\Plugin;
use Flytedesk\HostedContent\Registration\ApiCredential;
use Flytedesk\HostedContent\Registration\Client;
use Flytedesk\HostedContent\Registration\Consent;
use Flytedesk\HostedContent\Registration\VerificationTracker;
use WP_UnitTestCase;

/**
 * Exercises {@see Plugin::deactivate()} and `uninstall.php` against a real
 * WordPress install - proves the flytedesk API key and registration options
 * are actually removed (or, for deactivate(), deliberately left alone)
 * rather than only asserting the right WP functions were called with the
 * right arguments, which is all the unit suite's Brain Monkey equivalents
 * can prove.
 */
final class CleanupTest extends WP_UnitTestCase {

	public function test_deactivate_revokes_the_api_key_but_keeps_registration_state(): void {
		$client = new Client();
		$client->ensure_initial_state();
		$token = $client->get_token();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$consent  = new Consent();
		$consent->grant( $admin_id );

		$api_credential = new ApiCredential();
		$key            = $api_credential->issue();
		$this->assertTrue( $api_credential->verify( $key ) );

		Plugin::deactivate();

		$this->assertFalse( $api_credential->verify( $key ) );
		$this->assertFalse( get_option( ApiCredential::OPTION_KEY_HASH ) );
		$this->assertFalse( get_option( ApiCredential::OPTION_ISSUED_AT ) );

		// Deactivating must not lose registration state - only uninstalling does.
		$this->assertSame( $token, $client->get_token() );
		$this->assertSame( Client::STATUS_PENDING, $client->get_status() );

		// Nor should it revoke consent - re-activating shouldn't re-ask for it.
		$this->assertTrue( $consent->has_been_granted() );
	}

	public function test_uninstall_removes_every_trace_of_plugin_data(): void {
		$client = new Client();
		$client->ensure_initial_state();

		$api_credential = new ApiCredential();
		$key            = $api_credential->issue();
		$this->assertTrue( $api_credential->verify( $key ) );

		$verification_tracker = new VerificationTracker();
		$verification_tracker->mark_create_verified();
		$verification_tracker->mark_update_verified();
		$verification_tracker->mark_delete_verified();
		$this->assertTrue( $verification_tracker->is_fully_verified() );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$consent  = new Consent();
		$consent->grant( $admin_id );
		$this->assertTrue( $consent->has_been_granted() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'flytedesk-hosted-content/flytedesk-hosted-content.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( $api_credential->verify( $key ) );

		foreach (
			array(
				ApiCredential::OPTION_KEY_HASH,
				ApiCredential::OPTION_ISSUED_AT,
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
				VerificationTracker::OPTION_CREATE_VERIFIED_AT,
				VerificationTracker::OPTION_UPDATE_VERIFIED_AT,
				VerificationTracker::OPTION_DELETE_VERIFIED_AT,
				Consent::OPTION_GRANTED_AT,
				Consent::OPTION_GRANTED_BY_USER_ID,
				Consent::OPTION_GRANTED_BY_LOGIN,
				Consent::OPTION_GRANTED_BY_IP,
			) as $option
		) {
			$this->assertFalse( get_option( $option ), "Option {$option} was not removed by uninstall." );
		}

		$this->assertFalse( ( new VerificationTracker() )->is_fully_verified() );
		$this->assertFalse( $consent->has_been_granted() );
	}
}
