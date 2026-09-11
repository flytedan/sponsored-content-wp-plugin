<?php
/**
 * Core bootstrap/orchestrator.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent;

use Flytedesk\SponsoredContent\Admin\RegistrationAjaxController;
use Flytedesk\SponsoredContent\Admin\RegistrationSettingsPage;
use Flytedesk\SponsoredContent\Registration\ApiCredential as RegistrationApiCredential;
use Flytedesk\SponsoredContent\Registration\Client as RegistrationClient;
use Flytedesk\SponsoredContent\Registration\ConfirmationController;
use Flytedesk\SponsoredContent\Registration\StatePresenter;
use Flytedesk\SponsoredContent\Rest\Controller;
use Flytedesk\SponsoredContent\Seo\FallbackAdapter;
use Flytedesk\SponsoredContent\Seo\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the custom post type, the REST controller, the SEO adapter stack,
 * and the sponsored.flytedesk.com registration flow together. All other
 * classes are self-contained and do not reach back into this one.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private PostType $post_type;

	private Resolver $seo_resolver;

	private Controller $controller;

	private RegistrationClient $registration_client;

	private ConfirmationController $registration_confirmation;

	private RegistrationSettingsPage $registration_settings_page;

	private RegistrationAjaxController $registration_ajax;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Runs on plugin activation: seeds the registration option state (token
	 * + initial "pending" status, generated once and left alone on
	 * reactivation), flags that an automatic registration attempt should
	 * run on the next admin page load (deferred - see
	 * {@see \Flytedesk\SponsoredContent\Admin\RegistrationSettingsPage::maybe_run_automatic_registration()}
	 * - rather than making a network request inside this activation hook,
	 * which WordPress expects to run fast and which must not block
	 * activation on sponsored.flytedesk.com being reachable), and flushes
	 * rewrite rules so the `/flytedesk-registration-confirmation` route
	 * {@see ConfirmationController} registers takes effect immediately
	 * rather than only after WordPress's own periodic flush.
	 *
	 * `ConfirmationController::add_rewrite_rule()` is called directly here,
	 * redundantly with its own `init` registration, rather than relying on
	 * `init` having already fired earlier in the same request by the time
	 * this runs. That's true for a real, browser-based plugin activation
	 * (WordPress always fires `init` before dispatching an admin action like
	 * "activate this plugin"), but isn't guaranteed for every activation
	 * path - `wp plugin activate` via WP-CLI was observed not to reliably
	 * fire `init` first, which meant the rule wasn't yet in
	 * `WP_Rewrite::$extra_rules_top` at the moment this flushed, so the
	 * compiled rewrite rules ended up missing it entirely (a confirmed 404
	 * on `/flytedesk-registration-confirmation` until the next unrelated
	 * flush). Calling it explicitly here makes the flush correct regardless
	 * of hook-ordering assumptions.
	 */
	public static function activate(): void {
		Capabilities::register_role();

		$client = new RegistrationClient();
		$client->ensure_initial_state();
		update_option( RegistrationClient::OPTION_NEEDS_REGISTRATION, '1' );

		( new ConfirmationController( $client ) )->add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * Removes the low-privilege "flytebot" user {@see RegistrationApiCredential}
	 * provisioned - deactivating is the natural point to revoke the
	 * Application Password credential flytedesk's platform was given, the
	 * same way any other integration's access should be pulled the moment
	 * it's turned off - and flushes rewrite rules so
	 * `/flytedesk-registration-confirmation` stops resolving rather than
	 * 404ing awkwardly through a stale compiled rule.
	 *
	 * Deliberately does not touch registration status/token/timeline
	 * options ({@see RegistrationClient::delete_all_data()}) - those are
	 * only wiped on a full uninstall (see `uninstall.php`), so a
	 * deactivate/reactivate cycle re-registers using the same verification
	 * token instead of starting the workflow over from scratch.
	 */
	public static function deactivate(): void {
		( new RegistrationApiCredential() )->delete_user();

		flush_rewrite_rules();
	}

	private function __construct() {
		$this->post_type                 = new PostType();
		$this->seo_resolver              = new Resolver();
		$this->controller                = new Controller( $this->seo_resolver );
		$this->registration_client       = new RegistrationClient();
		$this->registration_confirmation = new ConfirmationController( $this->registration_client );

		$registration_api_credential      = new RegistrationApiCredential();
		$registration_state_presenter     = new StatePresenter( $this->registration_client, $registration_api_credential );
		$this->registration_settings_page = new RegistrationSettingsPage( $this->registration_client, $registration_api_credential, $registration_state_presenter );
		$this->registration_ajax          = new RegistrationAjaxController( $this->registration_client, $registration_state_presenter );
	}

	/**
	 * Registers every WordPress hook the plugin needs. Safe to call once;
	 * called by the plugin bootstrap file on every request.
	 *
	 * The error-envelope filter is registered here - once, unconditionally -
	 * rather than inside Controller::register_routes(). `rest_api_init` (and
	 * therefore route registration) only fires once per request in normal
	 * WordPress use, but a single `add_action( 'rest_api_init', ... )`
	 * binding is still the only thing that would re-add a filter if it were
	 * ever wiped independently of route registration - which is exactly
	 * what WordPress core's own PHPUnit test harness does between tests
	 * (`WP_UnitTestCase_Base` snapshots and restores `$wp_filter`, but a
	 * REST server's already-registered routes live in its own object state,
	 * not in the hook system, so they survive that restore while a
	 * route-registration-coupled filter would not). Keeping this filter
	 * registration independent of `rest_api_init` makes its lifetime match
	 * the plugin's own, not the REST server's.
	 */
	public function boot(): void {
		add_action( 'init', array( $this->post_type, 'register' ) );
		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		add_filter( 'rest_request_after_callbacks', array( $this->controller, 'normalize_error_response' ), 10, 3 );
		add_action( 'wp_head', array( $this, 'maybe_output_fallback_head_meta' ) );

		$this->registration_confirmation->register();
		$this->registration_settings_page->register();
		$this->registration_ajax->register();
	}

	/**
	 * When no supported SEO plugin is installed, the Fallback adapter is the
	 * one in play. It owns its own <meta> output on the front end so that
	 * description / Open Graph tags still render for flytedesk-hosted posts
	 * even on a site running zero SEO plugins.
	 */
	public function maybe_output_fallback_head_meta(): void {
		if ( ! is_singular( PostType::POST_TYPE ) ) {
			return;
		}

		$adapter = $this->seo_resolver->resolve();

		if ( $adapter instanceof FallbackAdapter ) {
			$adapter->output_head_meta( get_queried_object_id() );
		}
	}
}
