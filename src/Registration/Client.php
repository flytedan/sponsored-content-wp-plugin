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
	public const OPTION_RESOLVED_AT        = 'flytedesk_registration_resolved_at';
	public const OPTION_LAST_ATTEMPT_AT    = 'flytedesk_registration_last_attempt_at';
	public const OPTION_LAST_HTTP_STATUS   = 'flytedesk_registration_last_http_status';
	public const OPTION_LAST_REQUEST       = 'flytedesk_registration_last_request';
	public const OPTION_LAST_RESPONSE_BODY = 'flytedesk_registration_last_response_body';
	public const OPTION_NEEDS_REGISTRATION = 'flytedesk_needs_registration';

	private const REGISTER_ENDPOINT = 'https://sponsored.flytedesk.com/wp-plugin-register';

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

	public function __construct( ?ApiCredential $api_credential = null ) {
		$this->api_credential = $api_credential ?? new ApiCredential();
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
	 * and clears any stale STATUS_REJECTED resolution from a prior cycle.
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
