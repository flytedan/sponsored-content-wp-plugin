<?php
/**
 * Generates and verifies flytedesk's own API key for this site.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The REST API ({@see \Flytedesk\HostedContent\Rest\Controller}) authenticates
 * with a key this plugin generates and owns outright - it is not a WordPress
 * user, not a WordPress Application Password, and {@see \Flytedesk\HostedContent\Rest\Controller::check_permission()}
 * never touches WordPress's own authentication system (cookies, nonces,
 * `is_user_logged_in()`, `current_user_can()`) to check it. That keeps this
 * integration point entirely independent of whatever WordPress accounts,
 * roles, or capabilities exist on the site - there is nothing for a site
 * owner to misconfigure on the WordPress side, and nothing for this plugin
 * to create, reuse, or clean up there either.
 *
 * Only a SHA-256 hash of the key is ever stored - the same property
 * WordPress's own Application Passwords have (plaintext is visible exactly
 * once, at issuance, and never persisted anywhere in recoverable form) - so a
 * compromised database dump does not itself hand over a usable key.
 * `hash_equals()` is used to compare an incoming request's key against that
 * hash, so the comparison doesn't leak timing information about how much of
 * the key matched.
 */
class ApiCredential {

	public const OPTION_KEY_HASH  = 'flytedesk_api_key_hash';
	public const OPTION_ISSUED_AT = 'flytedesk_api_key_issued_at';

	/**
	 * Every option this class owns, for `uninstall.php` (and
	 * {@see \Flytedesk\HostedContent\Plugin::deactivate()}) to remove
	 * completely via {@see delete_all_data()} - single source of truth so
	 * that a future option this class adds can't be forgotten there.
	 */
	private const ALL_OPTIONS = array(
		self::OPTION_KEY_HASH,
		self::OPTION_ISSUED_AT,
	);

	/**
	 * Generates a fresh API key, stores only its hash, and returns the
	 * plaintext value - the only time it will ever be available. Overwrites
	 * (and immediately invalidates) whatever key was previously issued, the
	 * same way issuing a fresh Application Password used to revoke the
	 * previous one: every registration attempt (the initial "Connect to
	 * flytedesk" click or a later "Re-register") should hand flytedesk's
	 * platform a key that supersedes whatever it was given before, not
	 * accumulate indefinitely many valid keys.
	 */
	public function issue(): string {
		$key = $this->generate_key();

		update_option( self::OPTION_KEY_HASH, $this->hash( $key ) );
		update_option( self::OPTION_ISSUED_AT, current_time( 'mysql' ) );

		return $key;
	}

	public function get_issued_at(): string {
		return (string) get_option( self::OPTION_ISSUED_AT, '' );
	}

	/**
	 * Timing-safe comparison against the currently stored key's hash.
	 * Returns false (rather than throwing) for an empty or never-issued key,
	 * so a REST request arriving before any registration attempt has ever
	 * run is simply unauthenticated, not a fatal error.
	 */
	public function verify( string $provided_key ): bool {
		if ( '' === $provided_key ) {
			return false;
		}

		$stored_hash = (string) get_option( self::OPTION_KEY_HASH, '' );

		if ( '' === $stored_hash ) {
			return false;
		}

		return hash_equals( $stored_hash, $this->hash( $provided_key ) );
	}

	/**
	 * Removes every option this class stores. Used by both
	 * {@see \Flytedesk\HostedContent\Plugin::deactivate()} - revoking access
	 * the moment the integration is turned off, the same way any other
	 * integration's access should be pulled - and `uninstall.php`. Unlike
	 * the WordPress-user-based design this replaced, there is no user to
	 * delete: clearing the stored hash alone is enough to make the
	 * previously-issued key permanently unusable, since {@see verify()} has
	 * nothing left to compare it against.
	 */
	public function delete_all_data(): void {
		foreach ( self::ALL_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * 256 bits of CSPRNG entropy, rendered as 64 hex characters - the same
	 * construction {@see Client::generate_token()} uses for the verification
	 * token, since both are machine-to-machine shared secrets rather than
	 * human-typed passwords.
	 */
	private function generate_key(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	private function hash( string $key ): string {
		return hash( 'sha256', $key );
	}
}
