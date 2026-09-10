<?php
/**
 * Assembles the full registration state for display - used by both the
 * initial page render and the AJAX endpoints that back its live updates.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Registration;

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

	public function __construct( Client $client, ApiCredential $api_credential ) {
		$this->client         = $client;
		$this->api_credential = $api_credential;
	}

	/**
	 * @return array{
	 *     status: string,
	 *     site_domain: string,
	 *     verification_token: string,
	 *     api_username: string,
	 *     timeline: array{
	 *         created_at: string,
	 *         sent_at: string,
	 *         resolved_at: string
	 *     },
	 *     last_error: string,
	 *     technical: array{
	 *         last_attempt_at: string,
	 *         last_http_status: int,
	 *         last_request: array<string, mixed>,
	 *         last_response_body: string
	 *     }
	 * }
	 */
	public function to_array(): array {
		return array(
			'status'             => $this->client->get_status(),
			'site_domain'        => $this->client->get_site_domain(),
			'verification_token' => $this->client->get_token(),
			'api_username'       => $this->api_credential->get_username(),
			'timeline'           => array(
				'created_at'  => $this->format_timestamp( $this->client->get_created_at() ),
				'sent_at'     => $this->format_timestamp( $this->client->get_sent_at() ),
				'resolved_at' => $this->format_timestamp( $this->client->get_resolved_at() ),
			),
			'last_error'         => $this->client->get_last_error(),
			'technical'          => array(
				'last_attempt_at'    => $this->format_timestamp( $this->client->get_last_attempt_at() ),
				'last_http_status'   => $this->client->get_last_http_status(),
				'last_request'       => $this->client->get_last_request(),
				'last_response_body' => $this->client->get_last_response_body(),
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
