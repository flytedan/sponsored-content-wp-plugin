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
 * shared-secret verification token, and bookkeeping (last attempt time,
 * last error) for the settings page to display.
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
	public const OPTION_SENT_AT            = 'flytedesk_registration_sent_at';
	public const OPTION_NEEDS_REGISTRATION = 'flytedesk_needs_registration';

	private const REGISTER_ENDPOINT = 'https://sponsored.flytedesk.com/wp-plugin-register';

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
		}
	}

	public function get_status(): string {
		return (string) get_option( self::OPTION_STATUS, self::STATUS_PENDING );
	}

	public function set_status( string $status ): void {
		update_option( self::OPTION_STATUS, $status );
	}

	public function get_token(): string {
		return (string) get_option( self::OPTION_TOKEN, '' );
	}

	public function get_last_error(): string {
		return (string) get_option( self::OPTION_LAST_ERROR, '' );
	}

	public function get_sent_at(): string {
		return (string) get_option( self::OPTION_SENT_AT, '' );
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
	 * separate manual credential handoff. Sets status to STATUS_SENT only
	 * on an HTTP 200 response, per the documented contract ("Registration
	 * Sent is after receiving a 200 OK response"). Any other outcome
	 * (network failure, non-200 response, or failing to issue the
	 * credential itself) leaves status unchanged and records the failure
	 * reason for the settings page to surface - it does not revert an
	 * already-`accepted`/`rejected` site back to a lesser state just
	 * because a later re-registration attempt failed.
	 */
	public function register(): void {
		try {
			$api_username = $this->api_credential->get_username();
			$api_key      = $this->api_credential->issue();
		} catch ( RuntimeException $e ) {
			update_option( self::OPTION_LAST_ERROR, $e->getMessage() );
			return;
		}

		$response = wp_remote_post(
			self::REGISTER_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'site_title'         => get_bloginfo( 'name' ),
						'site_domain'        => $this->get_site_domain(),
						'verification_token' => $this->get_token(),
						'api_username'       => $api_username,
						'api_key'            => $api_key,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			update_option( self::OPTION_LAST_ERROR, $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			$this->set_status( self::STATUS_SENT );
			update_option( self::OPTION_SENT_AT, current_time( 'mysql' ) );
			delete_option( self::OPTION_LAST_ERROR );
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
