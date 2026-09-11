<?php
/**
 * Registers this site with sponsored.flytedesk.com and tracks status.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Registration;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns every option this plugin stores about its registration with
 * sponsored.flytedesk.com: the site's status in that workflow, the
 * shared-secret verification token, per-stage timestamps for the settings
 * page's timeline, and a redacted record of the last request/response for
 * its "Technical Details" view.
 *
 * The verification token is generated once, on first activation, and never
 * regenerated - sponsored.flytedesk.com needs a stable identifier across
 * repeated registration attempts (e.g. after a rejection, or a manual
 * "Register Now" retry) for the same site, and that same token is what lets
 * {@see ConfirmationController} tell a genuine confirmation callback apart
 * from a forged one.
 */
class Client {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_SENT     = 'sent';
	public const STATUS_ACCEPTED = 'accepted';
	public const STATUS_REJECTED = 'rejected';

	public const OPTION_STATUS             = 'flytedesk_registration_status';
	public const OPTION_TOKEN              = 'flytedesk_verification_token';
	public const OPTION_LAST_ERROR         = 'flytedesk_registration_last_error';
	public const OPTION_CREATED_AT         = 'flytedesk_registration_created_at';
	public const OPTION_SENT_AT            = 'flytedesk_registration_sent_at';
	public const OPTION_PING_RECEIVED_AT   = 'flytedesk_registration_ping_received_at';
	public const OPTION_RESOLVED_AT        = 'flytedesk_registration_resolved_at';
	public const OPTION_LAST_ATTEMPT_AT    = 'flytedesk_registration_last_attempt_at';
	public const OPTION_LAST_HTTP_STATUS   = 'flytedesk_registration_last_http_status';
	public const OPTION_LAST_REQUEST       = 'flytedesk_registration_last_request';
	public const OPTION_LAST_RESPONSE_BODY = 'flytedesk_registration_last_response_body';
	public const OPTION_NEEDS_REGISTRATION = 'flytedesk_needs_registration';

	/**
	 * Every option this class owns, for `uninstall.php` to remove
	 * completely via {@see delete_all_data()} - single source of truth so
	 * that a future option this class adds can't be forgotten there.
	 */
	private const ALL_OPTIONS = array(
		self::OPTION_STATUS,
		self::OPTION_TOKEN,
		self::OPTION_LAST_ERROR,
		self::OPTION_CREATED_AT,
		self::OPTION_SENT_AT,
		self::OPTION_PING_RECEIVED_AT,
		self::OPTION_RESOLVED_AT,
		self::OPTION_LAST_ATTEMPT_AT,
		self::OPTION_LAST_HTTP_STATUS,
		self::OPTION_LAST_REQUEST,
		self::OPTION_LAST_RESPONSE_BODY,
		self::OPTION_NEEDS_REGISTRATION,
	);

	/**
	 * The documented contract names this endpoint as
	 * `sponsored.flytedesk.com/functions/v1/wp-plugin-register`, but that
	 * host only serves the marketing site (a static Netlify-hosted SPA) -
	 * confirmed by curl (a 404 from Netlify's own function router, not the
	 * SPA's catch-all) and by grepping the deployed frontend bundle, which
	 * calls every one of its own backend functions against
	 * `porvdidjoviqtbsmvhfo.supabase.co/functions/v1/*`, a Supabase project
	 * on a completely different domain. Verified live via curl on
	 * 2026-09-11 (`{"status":"ok"}`, HTTP 200, no auth header required).
	 *
	 * A `api.sponsored.flytedesk.com` custom domain (CloudFront in front of
	 * this same Supabase origin, avoiding Supabase's paid custom-domain
	 * add-on) exists and is fully configured - ACM cert, CloudFront
	 * distribution, Route53 record - but is not usable yet: Supabase's
	 * origin is fronted by Cloudflare, which appears to block or reset
	 * connections from AWS's IP ranges at the network level (CloudFront
	 * gets its own connection-failure 502, not a passed-through origin
	 * response, and this persists with a browser-realistic User-Agent, so
	 * it isn't simple header-based bot detection). Fixing that needs
	 * someone with access to the Supabase/Cloudflare dashboard to
	 * allow-list CloudFront's ranges. Until then, this raw Supabase URL is
	 * the one actually in use - point back at the custom domain once it's
	 * confirmed working (see git history around 2026-09-11 for the full
	 * diagnostic trail).
	 */
	private const REGISTER_ENDPOINT = 'https://porvdidjoviqtbsmvhfo.supabase.co/functions/v1/wp-plugin-register';

	/**
	 * Response bodies are stored for display only, never parsed - capped so
	 * a misbehaving endpoint returning something huge (or, commonly, a
	 * full HTML error page for a non-existent route) can't bloat the
	 * options table or overwhelm the settings page's Technical Details tab.
	 * The display panel additionally scroll-clips instead of growing
	 * unbounded, but a small cap here keeps what's actually stored
	 * reasonable regardless.
	 */
	private const MAX_STORED_RESPONSE_BODY = 500;

	private ApiCredential $api_credential;

	private VerificationTracker $verification_tracker;

	public function __construct( ?ApiCredential $api_credential = null, ?VerificationTracker $verification_tracker = null ) {
		$this->api_credential       = $api_credential ?? new ApiCredential();
		$this->verification_tracker = $verification_tracker ?? new VerificationTracker();
	}

	/**
	 * Ensures the option state a fresh install needs exists, without
	 * clobbering anything already set (safe to call on every activation,
	 * including a reactivation after deactivating rather than deleting).
	 */
	public function ensure_initial_state(): void {
		if ( false === get_option( self::OPTION_TOKEN, false ) ) {
			add_option( self::OPTION_TOKEN, $this->generate_token() );
		}

		if ( false === get_option( self::OPTION_STATUS, false ) ) {
			add_option( self::OPTION_STATUS, self::STATUS_PENDING );
			add_option( self::OPTION_CREATED_AT, current_time( 'mysql' ) );
		}
	}

	public function get_status(): string {
		return (string) get_option( self::OPTION_STATUS, self::STATUS_PENDING );
	}

	public function set_status( string $status ): void {
		update_option( self::OPTION_STATUS, $status );
	}

	/**
	 * Used by {@see ConfirmationController} when a genuine Accepted/Rejected
	 * callback arrives - records both the final status and when it was
	 * resolved, for the settings page's timeline.
	 */
	public function mark_resolved( string $status ): void {
		$this->set_status( $status );
		update_option( self::OPTION_RESOLVED_AT, current_time( 'mysql' ) );
	}

	/**
	 * Used by {@see ConfirmationController} when sponsored.flytedesk.com's
	 * immediate post-registration "ping" callback arrives, confirming this
	 * site is actually reachable from their side - independent of whether a
	 * human has reviewed the registration yet. Deliberately does not touch
	 * `status`/`set_status()`: a ping is a connectivity check, not a
	 * decision, so the registration stays STATUS_SENT (awaiting human
	 * review) regardless. Drives the settings page timeline's "Connected"
	 * step, shown between "Pending" and "Awaiting Response".
	 */
	public function mark_ping_received(): void {
		update_option( self::OPTION_PING_RECEIVED_AT, current_time( 'mysql' ) );
	}

	public function get_token(): string {
		return (string) get_option( self::OPTION_TOKEN, '' );
	}

	public function get_last_error(): string {
		return (string) get_option( self::OPTION_LAST_ERROR, '' );
	}

	public function get_created_at(): string {
		return (string) get_option( self::OPTION_CREATED_AT, '' );
	}

	public function get_sent_at(): string {
		return (string) get_option( self::OPTION_SENT_AT, '' );
	}

	public function get_resolved_at(): string {
		return (string) get_option( self::OPTION_RESOLVED_AT, '' );
	}

	public function get_ping_received_at(): string {
		return (string) get_option( self::OPTION_PING_RECEIVED_AT, '' );
	}

	public function get_last_attempt_at(): string {
		return (string) get_option( self::OPTION_LAST_ATTEMPT_AT, '' );
	}

	public function get_last_http_status(): int {
		return (int) get_option( self::OPTION_LAST_HTTP_STATUS, 0 );
	}

	/**
	 * @return array<string, mixed> The last registration request body sent,
	 *                               with `api_key` omitted (never retained -
	 *                               see {@see ApiCredential}).
	 */
	public function get_last_request(): array {
		$raw = get_option( self::OPTION_LAST_REQUEST, array() );

		return is_array( $raw ) ? $raw : array();
	}

	public function get_last_response_body(): string {
		return (string) get_option( self::OPTION_LAST_RESPONSE_BODY, '' );
	}

	public function get_site_domain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}

	/**
	 * Removes every option this class stores. Used only by `uninstall.php`
	 * - never by {@see \Flytedesk\SponsoredContent\Plugin::deactivate()},
	 * which must leave registration state intact so a deactivate/reactivate
	 * cycle doesn't lose it.
	 */
	public function delete_all_data(): void {
		foreach ( self::ALL_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * POSTs this site's registration to sponsored.flytedesk.com, including a
	 * freshly-issued Application Password for the dedicated "flytebot" user
	 * (see {@see ApiCredential}) so that, once a human accepts the
	 * registration on the sponsored.flytedesk.com side, flytedesk's
	 * platform can start pushing content to this site immediately - no
	 * separate manual credential handoff.
	 *
	 * Records the outcome of every attempt (timestamp, HTTP status, a
	 * redacted copy of the request, and the raw response body) regardless of
	 * success, for the settings page's "Technical Details" view. Sets status
	 * to STATUS_SENT only on an HTTP 200 response, per the documented
	 * contract ("Registration Sent is after receiving a 200 OK response")
	 * and clears any stale resolution/ping/verification state from a prior
	 * cycle, so the timeline doesn't show a leftover "Connected", "Accepted",
	 * or "Verified" step from a previous registration attempt against a
	 * fresh one - a CRUD success from before this attempt was sent proves
	 * nothing about whether *this* connection actually works.
	 * Any other outcome (network failure, non-200 response, or failing to
	 * issue the credential itself) leaves status unchanged and records the
	 * failure reason - it does not revert an already-`accepted`/`rejected`
	 * site back to a lesser state just because a later re-registration
	 * attempt failed.
	 */
	public function register(): void {
		update_option( self::OPTION_LAST_ATTEMPT_AT, current_time( 'mysql' ) );

		try {
			$api_username = $this->api_credential->get_username();
			$api_key      = $this->api_credential->issue();
		} catch ( RuntimeException $e ) {
			update_option( self::OPTION_LAST_HTTP_STATUS, 0 );
			update_option( self::OPTION_LAST_RESPONSE_BODY, '' );
			update_option( self::OPTION_LAST_ERROR, $e->getMessage() );
			return;
		}

		$request_body = array(
			'site_title'         => get_bloginfo( 'name' ),
			'site_domain'        => $this->get_site_domain(),
			'verification_token' => $this->get_token(),
			'api_username'       => $api_username,
			'api_key'            => $api_key,
		);

		update_option( self::OPTION_LAST_REQUEST, $this->without_api_key( $request_body ) );

		$response = wp_remote_post(
			self::REGISTER_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			update_option( self::OPTION_LAST_HTTP_STATUS, 0 );
			update_option( self::OPTION_LAST_RESPONSE_BODY, '' );
			update_option( self::OPTION_LAST_ERROR, $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		update_option( self::OPTION_LAST_HTTP_STATUS, $code );
		update_option( self::OPTION_LAST_RESPONSE_BODY, mb_substr( $body, 0, self::MAX_STORED_RESPONSE_BODY ) );

		if ( 200 === $code ) {
			$this->set_status( self::STATUS_SENT );
			update_option( self::OPTION_SENT_AT, current_time( 'mysql' ) );
			delete_option( self::OPTION_LAST_ERROR );
			delete_option( self::OPTION_RESOLVED_AT );
			delete_option( self::OPTION_PING_RECEIVED_AT );
			$this->verification_tracker->delete_all_data();
			return;
		}

		update_option(
			self::OPTION_LAST_ERROR,
			sprintf(
				/* translators: %d: HTTP status code returned by the registration endpoint. */
				__( 'Registration endpoint returned HTTP %d.', 'flytedesk-sponsored-content' ),
				$code
			)
		);
	}

	/**
	 * @param array<string, mixed> $request_body
	 * @return array<string, mixed>
	 */
	private function without_api_key( array $request_body ): array {
		unset( $request_body['api_key'] );

		return $request_body;
	}

	/**
	 * A verification token is a machine-to-machine shared secret, not a
	 * human-managed password, so it uses PHP's CSPRNG directly
	 * (`random_bytes()`) rather than `wp_generate_password()` (built for
	 * human-typeable passwords) - 32 bytes / 256 bits of entropy, rendered
	 * as 64 hex characters.
	 */
	private function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
