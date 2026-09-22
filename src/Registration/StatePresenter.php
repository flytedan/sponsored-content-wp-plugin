<?php
/**
 * Assembles the full registration state for display - used by both the
 * initial page render and the AJAX endpoints that back its live updates.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `Admin\RegistrationSettingsPage` renders this shape server-side for the
 * first paint, and `Admin\RegistrationAjaxController` returns the exact same
 * shape as JSON for the polling/register-now JavaScript to re-render from -
 * one source of truth for what "the current state" means, so the two can
 * never drift apart.
 */
class StatePresenter {

	private Client $client;

	private ApiCredential $api_credential;

	private VerificationTracker $verification_tracker;

	private Consent $consent;

	public function __construct( Client $client, ApiCredential $api_credential, VerificationTracker $verification_tracker, Consent $consent ) {
		$this->client               = $client;
		$this->api_credential       = $api_credential;
		$this->verification_tracker = $verification_tracker;
		$this->consent              = $consent;
	}

	/**
	 * @return array{
	 *     status: string,
	 *     site_domain: string,
	 *     verification_token: string,
	 *     api_key_issued_at: string,
	 *     timeline: array{
	 *         created_at: string,
	 *         sent_at: string,
	 *         ping_received_at: string,
	 *         resolved_at: string
	 *     },
	 *     last_error: string,
	 *     technical: array{
	 *         last_attempt_at: string,
	 *         last_http_status: int,
	 *         last_request: array<string, mixed>,
	 *         last_response_body: string
	 *     },
	 *     verification: array{
	 *         create_at: string,
	 *         update_at: string,
	 *         delete_at: string,
	 *         verified_at: string,
	 *         all_verified: bool
	 *     },
	 *     consent: array{
	 *         granted: bool,
	 *         granted_at: string,
	 *         granted_by: string
	 *     }
	 * }
	 */
	public function to_array(): array {
		return array(
			'status'             => $this->client->get_status(),
			'site_domain'        => $this->client->get_site_domain(),
			'verification_token' => $this->client->get_token(),
			'api_key_issued_at'  => $this->format_timestamp( $this->api_credential->get_issued_at() ),
			'timeline'           => array(
				'created_at'       => $this->format_timestamp( $this->client->get_created_at() ),
				'sent_at'          => $this->format_timestamp( $this->client->get_sent_at() ),
				'ping_received_at' => $this->format_timestamp( $this->client->get_ping_received_at() ),
				'resolved_at'      => $this->format_timestamp( $this->client->get_resolved_at() ),
			),
			'last_error'         => $this->client->get_last_error(),
			'technical'          => array(
				'last_attempt_at'    => $this->format_timestamp( $this->client->get_last_attempt_at() ),
				'last_http_status'   => $this->client->get_last_http_status(),
				'last_request'       => $this->client->get_last_request(),
				'last_response_body' => $this->client->get_last_response_body(),
			),
			'verification'       => array(
				'create_at'    => $this->format_timestamp( $this->verification_tracker->get_create_verified_at() ),
				'update_at'    => $this->format_timestamp( $this->verification_tracker->get_update_verified_at() ),
				'delete_at'    => $this->format_timestamp( $this->verification_tracker->get_delete_verified_at() ),
				'verified_at'  => $this->format_timestamp( $this->verification_tracker->get_verified_at() ),
				'all_verified' => $this->verification_tracker->is_fully_verified(),
			),
			'consent'            => array(
				'granted'    => $this->consent->has_been_granted(),
				'granted_at' => $this->format_timestamp( $this->consent->get_granted_at() ),
				'granted_by' => $this->consent->get_granted_by_login(),
			),
		);
	}

	/**
	 * Formats a stored `current_time( 'mysql' )` value (already in the
	 * site's configured timezone) using the site's own date/time format
	 * settings, via `mysql2date()` with its `$translate` argument false so
	 * it does not attempt a second timezone conversion on an already-local
	 * value. Formatting happens here, server-side, specifically so neither
	 * the initial page render nor the polling JavaScript ever needs to do
	 * timezone-sensitive date math - both just display this string as-is.
	 */
	private function format_timestamp( string $mysql_datetime ): string {
		if ( '' === $mysql_datetime ) {
			return '';
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return mysql2date( $format, $mysql_datetime, false );
	}
}
