<?php
/**
 * Tracks whether flytedesk has ever successfully exercised each CRUD
 * operation against this site's REST API.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backs the Registration page's "Verified" timeline step. Registration
 * being Accepted only proves a human reviewed the site - it says nothing
 * about whether flytedesk's platform can actually create, update, and
 * delete content here. {@see \Flytedesk\SponsoredContent\Rest\Controller}
 * calls the relevant mark_*_verified() the moment each operation first
 * succeeds (real or test content - the specific post doesn't matter, only
 * that the operation worked at least once), and this class remembers only
 * the first time each one did, the same "set once, never overwritten"
 * pattern {@see Client::ensure_initial_state()} uses for created_at.
 */
class VerificationTracker {

	public const OPTION_CREATE_VERIFIED_AT = 'flytedesk_verified_create_at';
	public const OPTION_UPDATE_VERIFIED_AT = 'flytedesk_verified_update_at';
	public const OPTION_DELETE_VERIFIED_AT = 'flytedesk_verified_delete_at';

	/**
	 * Every option this class owns, for `uninstall.php` to remove
	 * completely via {@see delete_all_data()}.
	 */
	private const ALL_OPTIONS = array(
		self::OPTION_CREATE_VERIFIED_AT,
		self::OPTION_UPDATE_VERIFIED_AT,
		self::OPTION_DELETE_VERIFIED_AT,
	);

	public function mark_create_verified(): void {
		$this->mark_once( self::OPTION_CREATE_VERIFIED_AT );
	}

	public function mark_update_verified(): void {
		$this->mark_once( self::OPTION_UPDATE_VERIFIED_AT );
	}

	public function mark_delete_verified(): void {
		$this->mark_once( self::OPTION_DELETE_VERIFIED_AT );
	}

	public function get_create_verified_at(): string {
		return (string) get_option( self::OPTION_CREATE_VERIFIED_AT, '' );
	}

	public function get_update_verified_at(): string {
		return (string) get_option( self::OPTION_UPDATE_VERIFIED_AT, '' );
	}

	public function get_delete_verified_at(): string {
		return (string) get_option( self::OPTION_DELETE_VERIFIED_AT, '' );
	}

	public function is_fully_verified(): bool {
		return '' !== $this->get_create_verified_at()
			&& '' !== $this->get_update_verified_at()
			&& '' !== $this->get_delete_verified_at();
	}

	/**
	 * The moment full verification was reached: the latest of the three
	 * timestamps, since that's the one that completed it. Empty until
	 * {@see is_fully_verified()} is true - `max()` on `current_time( 'mysql' )`
	 * strings works correctly here because that format sorts lexicographically
	 * in chronological order.
	 */
	public function get_verified_at(): string {
		if ( ! $this->is_fully_verified() ) {
			return '';
		}

		return max( $this->get_create_verified_at(), $this->get_update_verified_at(), $this->get_delete_verified_at() );
	}

	/**
	 * Used only by `uninstall.php`.
	 */
	public function delete_all_data(): void {
		foreach ( self::ALL_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	private function mark_once( string $option ): void {
		if ( '' === (string) get_option( $option, '' ) ) {
			update_option( $option, current_time( 'mysql' ) );
		}
	}
}
