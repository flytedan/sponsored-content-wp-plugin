<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Integration;

use Flytedesk\HostedContent\Plugin;
use Flytedesk\HostedContent\PostType;
use Flytedesk\HostedContent\Registration\ApiCredential;
use Flytedesk\HostedContent\Registration\Client;
use Flytedesk\HostedContent\Registration\ConfirmationController;
use Flytedesk\HostedContent\Registration\Consent;
use Flytedesk\HostedContent\Registration\StatePresenter;
use Flytedesk\HostedContent\Registration\VerificationTracker;
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

	public function test_confirmation_controller_records_a_real_ping_without_resolving_status(): void {
		$client = new Client();
		$client->ensure_initial_state();
		$token = $client->get_token();

		$controller = new ConfirmationController( $client );
		$result     = $controller->handle( 'POST', $token, '{"status":"ping"}' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'Connected', $result['body']['status'] );
		$this->assertNotSame( '', $client->get_ping_received_at() );
		$this->assertSame( Client::STATUS_PENDING, $client->get_status() );
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

	/**
	 * Confirms `StatePresenter::format_timestamp()` produces a real,
	 * site-formatted string via WordPress's own `mysql2date()` - the unit
	 * suite mocks that function entirely, so this is the only place that
	 * proves the site's actual `date_format`/`time_format` options feed
	 * through correctly end to end.
	 */
	public function test_state_presenter_formats_real_timestamps_using_site_date_time_format(): void {
		$client = new Client();
		$client->ensure_initial_state();

		$state = ( new StatePresenter( $client, new ApiCredential(), new VerificationTracker(), new Consent() ) )->to_array();

		$this->assertNotSame( '', $state['timeline']['created_at'] );
		$this->assertSame(
			mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $client->get_created_at(), false ),
			$state['timeline']['created_at']
		);
		$this->assertSame( '', $state['timeline']['sent_at'] );
		$this->assertSame( '', $state['timeline']['ping_received_at'] );
		$this->assertSame( '', $state['timeline']['resolved_at'] );
	}

	/**
	 * Regression test: `wp plugin activate` via WP-CLI was observed not to
	 * reliably fire `init` before running the activation hook, which meant
	 * `Plugin::activate()`'s `flush_rewrite_rules()` call flushed a
	 * compiled ruleset that never included
	 * `/flytedesk-registration-confirmation` at all (a real, reproduced
	 * 404 - see the code comment on `Plugin::activate()`). Fixed by having
	 * `activate()` register the rule itself, directly, rather than relying
	 * on `init` having already run earlier in the same request. This test
	 * calls `activate()` directly and confirms the rule is present
	 * regardless of what `init` has or hasn't already done.
	 */
	public function test_activate_registers_the_confirmation_rewrite_rule_itself(): void {
		global $wp_rewrite;

		$wp_rewrite->extra_rules_top = array();

		Plugin::activate();

		$this->assertArrayHasKey( '^flytedesk-registration-confirmation/?$', $wp_rewrite->extra_rules_top );
	}

	/**
	 * Regression test for the same bug class as the one above, caught by
	 * real-world testing: a real "Upload Plugin" -> "Activate Plugin" install
	 * left every single published post's permalink 404ing, because
	 * `PostType::register()` - hooked to `init` by `Plugin::boot()`, which is
	 * only ever called once this plugin's bootstrap file is `include_once`'d
	 * during the activation request itself - runs too late to have added the
	 * CPT's permalink structure to `$wp_rewrite` before `activate()`'s
	 * `flush_rewrite_rules()` call compiled the rewrite rules. Fixed the same
	 * way as the confirmation webhook's rule: `activate()` now registers the
	 * post type directly, rather than relying on `init` having already done
	 * it. This test unregisters the post type first (simulating "init never
	 * ran for it this request") and confirms `activate()` alone is enough to
	 * produce a compiled rewrite rule for it.
	 *
	 * `WP_Rewrite::rewrite_rules()` only ever compiles a real ruleset - and
	 * therefore only ever persists an array to the `rewrite_rules` option -
	 * when a permalink structure is set; with the default "Plain" permalinks
	 * the test install otherwise boots with, it persists an empty string
	 * instead (WordPress has nothing to rewrite). A publisher hitting this
	 * 404 bug in the wild necessarily has pretty permalinks enabled, so this
	 * test sets a permalink structure to reproduce those real conditions,
	 * restoring the original structure afterwards so it doesn't bleed into
	 * other tests sharing the same `$wp_rewrite` instance.
	 */
	public function test_activate_registers_the_post_types_permalinks_itself(): void {
		global $wp_rewrite;

		$original_structure = $wp_rewrite->permalink_structure;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		try {
			unregister_post_type( PostType::POST_TYPE );
			$this->assertFalse( post_type_exists( PostType::POST_TYPE ) );

			Plugin::activate();

			$this->assertTrue( post_type_exists( PostType::POST_TYPE ) );

			$compiled                = get_option( 'rewrite_rules', array() );
			$has_hosted_content_rule = false;

			foreach ( array_keys( $compiled ) as $pattern ) {
				if ( false !== strpos( $pattern, 'hosted-content' ) ) {
					$has_hosted_content_rule = true;
					break;
				}
			}

			$this->assertTrue( $has_hosted_content_rule, 'Expected activate() to compile a hosted-content permalink rule even when init never registered the post type first.' );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
		}
	}

	/**
	 * The two `wp_ajax_*` hooks are what let the Registration page's
	 * JavaScript poll and (re-)register without a full page reload -
	 * confirms `Plugin::boot()` actually wires
	 * {@see \Flytedesk\HostedContent\Admin\RegistrationAjaxController}
	 * up to them.
	 */
	public function test_ajax_actions_are_registered(): void {
		$this->assertNotFalse( has_action( 'wp_ajax_flytedesk_registration_status' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_flytedesk_grant_consent' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_flytedesk_register' ) );
	}

	public function test_consent_grant_persists_a_real_user_login_and_timestamp(): void {
		$admin_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'site-owner',
			)
		);

		$consent = new Consent();
		$this->assertFalse( $consent->has_been_granted() );

		$consent->grant( $admin_id );

		$this->assertTrue( $consent->has_been_granted() );
		$this->assertSame( 'site-owner', $consent->get_granted_by_login() );
		$this->assertNotSame( '', $consent->get_granted_at() );
	}
}
