<?php
/**
 * Concrete ApplicationPasswordIssuer backed by WP core's own API.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Registration;

use WP_Application_Passwords;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WordPressApplicationPasswordIssuer implements ApplicationPasswordIssuer {

	/**
	 * @param array<string, mixed> $args
	 * @return array{0: string, 1: array{uuid: string}}|WP_Error
	 */
	public function create( int $user_id, array $args ) {
		return WP_Application_Passwords::create_new_application_password( $user_id, $args );
	}

	public function delete( int $user_id, string $uuid ): bool {
		$result = WP_Application_Passwords::delete_application_password( $user_id, $uuid );

		return true === $result;
	}
}
