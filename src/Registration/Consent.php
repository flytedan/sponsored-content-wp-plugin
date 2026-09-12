<?php
/**
 * Records explicit opt-in consent before this site registers with
 * sponsored.flytedesk.com.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress.org's Plugin Directory guidelines (#7, "Plugins may not track
 * users without their consent") require an explicit opt-in - a button a
 * human deliberately clicks, with the consequences plainly explained first -
 * before a plugin may contact an external server; merely installing or
 * activating a plugin is not itself consent for that. {@see
 * \Flytedesk\SponsoredContent\Admin\RegistrationSettingsPage} shows that
 * plain-language explanation and gates the "Connect" action behind it; this
 * class is only responsible for recording that a human explicitly granted
 * it, and who/when/from-where, once they do.
 *
 * {@see Client::register()} is never called before {@see has_been_granted()}
 * is true - see {@see \Flytedesk\SponsoredContent\Admin\RegistrationAjaxController::handle_grant_consent()}.
 */
class Consent {

	public const OPTION_GRANTED_AT         = 'flytedesk_consent_granted_at';
	public const OPTION_GRANTED_BY_USER_ID = 'flytedesk_consent_user_id';
	public const OPTION_GRANTED_BY_LOGIN   = 'flytedesk_consent_user_login';
	public const OPTION_GRANTED_BY_IP      = 'flytedesk_consent_ip';

	/**
	 * Every option this class owns, for `uninstall.php` to remove
	 * completely via {@see delete_all_data()}.
	 */
	private const ALL_OPTIONS = array(
		self::OPTION_GRANTED_AT,
		self::OPTION_GRANTED_BY_USER_ID,
		self::OPTION_GRANTED_BY_LOGIN,
		self::OPTION_GRANTED_BY_IP,
	);

	public function has_been_granted(): bool {
		return '' !== $this->get_granted_at();
	}

	/**
	 * Records that the given user explicitly authorized registration, right
	 * now, from their current request's IP - the who/when/where audit trail
	 * a real consent record needs, not just a bare boolean flag. Once
	 * granted this is never asked again (deactivating/reactivating the
	 * plugin doesn't revoke it - only a full uninstall does, the same as
	 * every other registration option this plugin retains across a
	 * deactivate cycle).
	 */
	public function grant( int $user_id ): void {
		$user = get_userdata( $user_id );

		update_option( self::OPTION_GRANTED_AT, current_time( 'mysql' ) );
		update_option( self::OPTION_GRANTED_BY_USER_ID, $user_id );
		update_option( self::OPTION_GRANTED_BY_LOGIN, false !== $user ? $user->user_login : '' );
		update_option( self::OPTION_GRANTED_BY_IP, $this->request_ip() );
	}

	public function get_granted_at(): string {
		return (string) get_option( self::OPTION_GRANTED_AT, '' );
	}

	public function get_granted_by_login(): string {
		return (string) get_option( self::OPTION_GRANTED_BY_LOGIN, '' );
	}

	public function get_granted_by_ip(): string {
		return (string) get_option( self::OPTION_GRANTED_BY_IP, '' );
	}

	/**
	 * Used only by `uninstall.php`.
	 */
	public function delete_all_data(): void {
		foreach ( self::ALL_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * `$_SERVER['REMOTE_ADDR']` is the actual TCP peer address WordPress
	 * itself was connected from - unlike `X-Forwarded-For` and similar
	 * proxy headers, it isn't something the client can simply set to
	 * whatever it wants, which matters here since this value's whole job is
	 * to be a trustworthy audit record, not just a display convenience.
	 */
	private function request_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
