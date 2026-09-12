<?php
/**
 * AJAX endpoints backing the Registration page's live updates.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Admin;

use Flytedesk\HostedContent\Registration\Client;
use Flytedesk\HostedContent\Registration\Consent;
use Flytedesk\HostedContent\Registration\StatePresenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three `admin-ajax.php` actions, all restricted to `manage_options` and
 * nonce-verified against {@see RegistrationSettingsPage::NONCE_ACTION}:
 *
 * - `flytedesk_registration_status` (read-only): returns the current state,
 *   for the page's 3-second poll and to refresh state right after the
 *   confirmation webhook may have landed elsewhere.
 * - `flytedesk_grant_consent` (state-changing, one-time): records that the
 *   current user explicitly authorized registration (see {@see Consent})
 *   and immediately runs the first {@see Client::register()} - the single
 *   click that takes a site from "just activated" to "registered", with a
 *   real audit trail of who authorized it.
 * - `flytedesk_register` (state-changing): runs {@see Client::register()}
 *   again for an already-consented site, so the "Re-register" button can
 *   show a loading state and then update in place without a full page
 *   reload.
 *
 * All three return the exact same shape as {@see StatePresenter::to_array()}.
 */
class RegistrationAjaxController {

	private Client $client;

	private Consent $consent;

	private StatePresenter $state_presenter;

	public function __construct( Client $client, Consent $consent, StatePresenter $state_presenter ) {
		$this->client          = $client;
		$this->consent         = $consent;
		$this->state_presenter = $state_presenter;
	}

	public function register(): void {
		add_action( 'wp_ajax_flytedesk_registration_status', array( $this, 'handle_status' ) );
		add_action( 'wp_ajax_flytedesk_grant_consent', array( $this, 'handle_grant_consent' ) );
		add_action( 'wp_ajax_flytedesk_register', array( $this, 'handle_register' ) );
	}

	public function handle_status(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		wp_send_json_success( $this->state_presenter->to_array() );
	}

	/**
	 * The one action that may run before consent exists - this IS the
	 * consenting action. Records it against the currently authenticated
	 * user, then immediately sends the registration that consent covers,
	 * so accepting takes exactly one click rather than a "consent" step
	 * followed by a separate "now register" step.
	 */
	public function handle_grant_consent(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		$this->consent->grant( get_current_user_id() );
		$this->client->register();

		wp_send_json_success( $this->state_presenter->to_array() );
	}

	/**
	 * Guarded by {@see Consent::has_been_granted()} as defense in depth -
	 * the "Re-register" button this backs is never rendered before consent
	 * exists (see {@see RegistrationSettingsPage::render()}), but a direct
	 * AJAX call must not be able to trigger a real registration attempt
	 * around that gate regardless.
	 */
	public function handle_register(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		if ( ! $this->consent->has_been_granted() ) {
			wp_send_json_error(
				array( 'message' => __( 'This site has not been authorized to register with flytedesk yet.', 'flytedesk-hosted-content' ) ),
				403
			);

			return;
		}

		$this->client->register();

		wp_send_json_success( $this->state_presenter->to_array() );
	}

	/**
	 * `wp_send_json_error()` calls `wp_die()` internally in normal
	 * WordPress operation, terminating the request - the `return false`
	 * here exists for defensiveness and for unit-testability (where that
	 * termination is mocked away), not because it's expected to be reached
	 * in production.
	 *
	 * `check_ajax_referer()` defaults to killing the request itself
	 * (printing a bare `-1` and exiting) on an invalid nonce - passed
	 * `$stop = false` here so a bad nonce instead falls through to our own
	 * `wp_send_json_error()` call below, keeping every failure path on this
	 * endpoint in the same `{"success":false,"data":{...}}` JSON shape
	 * rather than a plain `-1` for this one case.
	 */
	private function authorize(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do this.', 'flytedesk-hosted-content' ) ),
				403
			);

			return false;
		}

		if ( ! check_ajax_referer( RegistrationSettingsPage::NONCE_ACTION, false, false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session has expired. Please reload the page and try again.', 'flytedesk-hosted-content' ) ),
				403
			);

			return false;
		}

		return true;
	}
}
