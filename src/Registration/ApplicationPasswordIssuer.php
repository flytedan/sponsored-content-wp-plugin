<?php
/**
 * Narrow interface over WP core's static Application Passwords API.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Registration;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@see ApiCredential} depends on this narrow interface rather than calling
 * `WP_Application_Passwords`'s static methods directly, so its
 * create-then-revoke sequencing logic can be unit-tested against a fake
 * implementation instead of fighting static-method mocking.
 */
interface ApplicationPasswordIssuer {

	/**
	 * @param array<string, mixed> $args
	 * @return array{0: string, 1: array{uuid: string}}|WP_Error
	 */
	public function create( int $user_id, array $args );

	public function delete( int $user_id, string $uuid ): bool;
}
