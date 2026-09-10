<?php
/**
 * AJAX endpoints backing the Registration page's live updates.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Admin;

use Flytedesk\SponsoredContent\Registration\Client;
use Flytedesk\SponsoredContent\Registration\StatePresenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two `admin-ajax.php` actions, both restricted to `manage_options` and
 * nonce-verified against {@see RegistrationSettingsPage::NONCE_ACTION}:
 *
 * - `flytedesk_registration_status` (read-only): returns the current state,
 *   for the page's 3-second poll and to refresh state right after the
 *   confirmation webhook may have landed elsewhere.
 * - `flytedesk_register` (state-changing): runs {@see Client::register()}
 *   synchronously and returns the resulting state, so the "Register Now" /
 *   "Re-register" button can show a loading state and then update in place
 *   without a full page reload.
 *
 * Both return the exact same shape as {@see StatePresenter::to_array()}.
 */
class RegistrationAjaxController {

	private Client $client;

	private StatePresenter $state_presenter;

	public function __construct( Client $client, StatePresenter $state_presenter ) {
		$this->client          = $client;
		$this->state_presenter = $state_presenter;
	}

	public function register(): void {
		add_action( 'wp_ajax_flytedesk_registration_status', array( $this, 'handle_status' ) );
		add_action( 'wp_ajax_flytedesk_register', array( $this, 'handle_register' ) );
	}

	public function handle_status(): void {
		if ( ! $this->authorize() ) {
			return;
		}

		wp_send_json_success( $this->state_presenter->to_array() );
	}

	public function handle_register(): void {
		if ( ! $this->authorize() ) {
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
	 */
	private function authorize(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do this.', 'flytedesk-sponsored-content' ) ),
				403
			);

			return false;
		}

		check_ajax_referer( RegistrationSettingsPage::NONCE_ACTION );

		return true;
	}
}
