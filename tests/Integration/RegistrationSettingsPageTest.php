<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Integration;

use Flytedesk\SponsoredContent\Admin\RegistrationSettingsPage;
use Flytedesk\SponsoredContent\Registration\Client;
use Flytedesk\SponsoredContent\Registration\Consent;
use Flytedesk\SponsoredContent\Seo\AdapterInterface;
use Flytedesk\SponsoredContent\Seo\Resolver;
use WP_UnitTestCase;

/**
 * Exercises {@see RegistrationSettingsPage::render()} against a real
 * WordPress install - the consent gate that replaces the dashboard until
 * {@see Consent::has_been_granted()} is true, and the SEO-plugin warning
 * banner added on top of {@see Resolver::has_recommended_plugin()} - since
 * both are real HTML output the unit suite's Brain Monkey mocks can't
 * usefully verify.
 */
final class RegistrationSettingsPageTest extends WP_UnitTestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_render_shows_the_consent_gate_before_consent_is_granted(): void {
		$page = new RegistrationSettingsPage( $this->client(), new Resolver(), new Consent() );

		$output = $this->render( $page );

		$this->assertStringContainsString( 'flytedesk-consent-card', $output );
		$this->assertStringContainsString( 'Connect to flytedesk', $output );
		// Nothing from the dashboard should be present yet.
		$this->assertStringNotContainsString( 'flytedesk-timeline', $output );
	}

	public function test_render_shows_the_full_dashboard_once_consent_is_granted(): void {
		$consent = new Consent();
		$consent->grant( $this->admin_id );

		$page = new RegistrationSettingsPage( $this->client(), new Resolver(), $consent );

		$output = $this->render( $page );

		$this->assertStringNotContainsString( 'flytedesk-consent-card', $output );
		$this->assertStringContainsString( 'flytedesk-timeline', $output );
	}

	public function test_render_shows_the_seo_warning_when_no_supported_plugin_is_active(): void {
		// The bare test WordPress install has none of Yoast/Rank Math/AIOSEO
		// installed, so the real, production adapter stack resolves to the
		// fallback adapter - exactly the case this banner exists for.
		$consent = new Consent();
		$consent->grant( $this->admin_id );

		$page = new RegistrationSettingsPage( $this->client(), new Resolver(), $consent );

		$output = $this->render( $page );

		$this->assertStringContainsString( 'flytedesk-seo-notice', $output );
		$this->assertStringContainsString( 'No supported SEO plugin detected.', $output );
	}

	public function test_render_hides_the_seo_warning_when_a_supported_plugin_is_active(): void {
		$consent = new Consent();
		$consent->grant( $this->admin_id );

		$resolver = new Resolver( array( $this->fake_active_adapter() ) );
		$page     = new RegistrationSettingsPage( $this->client(), $resolver, $consent );

		$output = $this->render( $page );

		$this->assertStringNotContainsString( 'flytedesk-seo-notice', $output );
	}

	private function client(): Client {
		$client = new Client();
		$client->ensure_initial_state();

		return $client;
	}

	private function render( RegistrationSettingsPage $page ): string {
		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	private function fake_active_adapter(): AdapterInterface {
		return new class() implements AdapterInterface {
			public function is_active(): bool {
				return true;
			}

			public function write( int $post_id, array $seo ): void {}

			public function read( int $post_id ): array {
				return array();
			}
		};
	}
}
