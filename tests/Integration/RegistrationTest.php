<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Integration;

use Flytedesk\SponsoredContent\Registration\Client;
use Flytedesk\SponsoredContent\Registration\ConfirmationController;
use WP_UnitTestCase;

/**
 * Exercises {@see Client} and {@see ConfirmationController} against a real
 * WordPress options table - the unit suite covers their logic against
 * Brain Monkey mocks of get_option()/update_option()/etc., this suite
 * proves those same calls behave correctly against the real thing (default
 * values, persistence across calls, autoload behaviour).
 */
final class RegistrationTest extends WP_UnitTestCase {

	public function test_ensure_initial_state_persists_a_real_token_and_pending_status(): void {
		$client = new Client();

		$client->ensure_initial_state();

		$this->assertSame( Client::STATUS_PENDING, $client->get_status() );
		$this->assertSame( 64, strlen( $client->get_token() ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $client->get_token() );
	}

	public function test_ensure_initial_state_is_idempotent_across_calls(): void {
		$client = new Client();

		$client->ensure_initial_state();
		$first_token = $client->get_token();

		$client->ensure_initial_state();

		$this->assertSame( $first_token, $client->get_token() );
	}

	public function test_get_site_domain_reflects_the_real_home_url(): void {
		$client = new Client();

		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), $client->get_site_domain() );
	}

	public function test_confirmation_controller_accepts_valid_token_and_updates_real_status(): void {
		$client = new Client();
		$client->ensure_initial_state();
		$token = $client->get_token();

		$controller = new ConfirmationController( $client );
		$result     = $controller->handle( 'POST', $token, '{"status":"Accepted"}' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( Client::STATUS_ACCEPTED, $client->get_status() );
	}

	public function test_confirmation_controller_rejects_wrong_token_without_changing_real_status(): void {
		$client = new Client();
		$client->ensure_initial_state();

		$controller = new ConfirmationController( $client );
		$result     = $controller->handle( 'POST', 'a-completely-wrong-token', '{"status":"Accepted"}' );

		$this->assertSame( 401, $result['status'] );
		$this->assertSame( Client::STATUS_PENDING, $client->get_status() );
	}

	/**
	 * `add_rewrite_rule( ..., 'top' )` stores the rule directly into
	 * `WP_Rewrite::$extra_rules_top` immediately - that's what this checks,
	 * rather than the compiled `rewrite_rules` DB option, which only
	 * reflects newly-added rules after an explicit `flush_rewrite_rules()`
	 * (done by `Plugin::activate()` on real activation; not something this
	 * test's `muplugins_loaded`-based bootstrap triggers).
	 */
	public function test_register_rewrite_rule_is_registered_on_init(): void {
		global $wp_rewrite;

		$this->assertArrayHasKey( '^flytedesk-registration-confirmation/?$', $wp_rewrite->extra_rules_top );
		$this->assertSame(
			'index.php?flytedesk_registration_confirmation=1',
			$wp_rewrite->extra_rules_top['^flytedesk-registration-confirmation/?$']
		);
	}
}
