<?php
/**
 * Provisions the dedicated "flytebot" user and its Application Password.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Registration;

use Flytedesk\HostedContent\Capabilities;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automates what the plugin's README otherwise documents as a manual step:
 * a human creating a WordPress user and generating an Application Password
 * for flytedesk to use. Instead, a single low-privilege "flytebot" user
 * (holding only {@see Capabilities::MANAGE_HOSTED_CONTENT}, nothing
 * else) is created once and reused; a fresh Application Password is issued
 * for it every time {@see issue()} runs (i.e. every registration attempt,
 * automatic or manual), with the previously-issued one revoked first.
 *
 * Application Passwords are only ever visible in plaintext at the moment
 * WordPress creates them - there is no way to retrieve a previously-issued
 * one later, which is exactly why this reissues rather than trying to cache
 * and resend the same value.
 */
class ApiCredential {

	private const USERNAME = 'flytebot';

	public const OPTION_USER_ID       = 'flytedesk_api_user_id';
	public const OPTION_PASSWORD_UUID = 'flytedesk_api_password_uuid';

	private ApplicationPasswordIssuer $issuer;

	public function __construct( ?ApplicationPasswordIssuer $issuer = null ) {
		$this->issuer = $issuer ?? new WordPressApplicationPasswordIssuer();
	}

	public function get_username(): string {
		return self::USERNAME;
	}

	/**
	 * Ensures the flytebot user exists, revokes whatever Application
	 * Password was previously issued for it (if any), issues a new one, and
	 * returns it in plaintext - the only time it will ever be available.
	 *
	 * @throws RuntimeException If user creation or password issuance fails.
	 */
	public function issue(): string {
		$user_id = $this->ensure_user();

		$this->revoke_previous( $user_id );

		$result = $this->issuer->create( $user_id, array( 'name' => 'flytedesk-registration' ) );

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not page output; caught by Client::register(), stored, and esc_html()'d at display time in RegistrationSettingsPage::render().
			throw new RuntimeException( $result->get_error_message() );
		}

		list( $password, $item ) = $result;

		update_option( self::OPTION_PASSWORD_UUID, $item['uuid'] );

		return $password;
	}

	/**
	 * @throws RuntimeException If the user does not exist and cannot be created.
	 */
	private function ensure_user(): int {
		$tracked_id = (int) get_option( self::OPTION_USER_ID, 0 );

		if ( $tracked_id > 0 && false !== get_user_by( 'id', $tracked_id ) ) {
			return $tracked_id;
		}

		$existing = get_user_by( 'login', self::USERNAME );

		if ( false !== $existing ) {
			update_option( self::OPTION_USER_ID, $existing->ID );

			return $existing->ID;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::USERNAME,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => self::USERNAME . '@' . $this->site_domain() . '.invalid',
				'display_name' => 'Flytebot (Flytedesk API)',
				'role'         => Capabilities::ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not page output; caught by Client::register(), stored, and esc_html()'d at display time in RegistrationSettingsPage::render().
			throw new RuntimeException( $user_id->get_error_message() );
		}

		update_option( self::OPTION_USER_ID, $user_id );

		return $user_id;
	}

	private function revoke_previous( int $user_id ): void {
		$uuid = (string) get_option( self::OPTION_PASSWORD_UUID, '' );

		if ( '' === $uuid ) {
			return;
		}

		$this->issuer->delete( $user_id, $uuid );
		delete_option( self::OPTION_PASSWORD_UUID );
	}

	/**
	 * Removes the flytebot user this class provisioned (its Application
	 * Passwords go with it - they live as user meta, not a separate table -
	 * so there is nothing left to revoke independently) and forgets the
	 * tracked user/password-uuid options, so a later {@see issue()} call
	 * (e.g. after reactivation) provisions a fresh user rather than
	 * resolving back to one that no longer exists.
	 *
	 * Used by {@see \Flytedesk\HostedContent\Plugin::deactivate()} and by
	 * `uninstall.php`. `wp_delete_user()` lives in an admin-only file that
	 * isn't loaded on every request (e.g. WP-CLI activation/deactivation
	 * doesn't guarantee it), hence the conditional require.
	 */
	public function delete_user(): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$user_id = (int) get_option( self::OPTION_USER_ID, 0 );

		if ( $user_id > 0 ) {
			wp_delete_user( $user_id );
		}

		delete_option( self::OPTION_USER_ID );
		delete_option( self::OPTION_PASSWORD_UUID );
	}

	/**
	 * `.invalid` is the IANA-reserved TLD (RFC 2606) for addresses that are
	 * guaranteed not to resolve - used here so WordPress never has reason to
	 * try emailing this synthetic account, on this domain or any other.
	 */
	private function site_domain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? $host : 'example';
	}
}
