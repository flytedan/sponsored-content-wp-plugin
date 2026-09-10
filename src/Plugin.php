<?php
/**
 * Core bootstrap/orchestrator.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent;

use Flytedesk\SponsoredContent\Rest\Controller;
use Flytedesk\SponsoredContent\Seo\FallbackAdapter;
use Flytedesk\SponsoredContent\Seo\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the custom post type, the REST controller, and the SEO adapter
 * stack together. All other classes are self-contained and do not reach
 * back into this one.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private PostType $post_type;

	private Resolver $seo_resolver;

	private Controller $controller;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->post_type    = new PostType();
		$this->seo_resolver = new Resolver();
		$this->controller   = new Controller( $this->seo_resolver );
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
