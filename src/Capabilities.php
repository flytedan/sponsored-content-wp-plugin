<?php
/**
 * The custom capability/role the auto-provisioned "flytebot" user gets.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `Rest\Controller`'s own routes are the only thing this capability gates
 * (see `check_permission()`) - it is deliberately unrelated to WordPress
 * core's `edit_posts` and the rest of the standard capability graph, so a
 * user holding only this capability (plus `read`) cannot do anything else
 * on the site: no wp-admin post editing, no media uploads, nothing beyond
 * this plugin's own REST API. `Registration\ApiCredential` provisions
 * exactly such a user ("flytebot") to hold the Application Password sent
 * during registration.
 *
 * `edit_posts` remains independently accepted by `check_permission()` too,
 * for the documented manual setup path where a human generates their own
 * Application Password from an Author/Editor/Administrator account - this
 * role is an additional, narrower option, not a replacement for that.
 */
final class Capabilities {

	public const MANAGE_SPONSORED_CONTENT = 'flytedesk_manage_sponsored_content';

	public const ROLE = 'flytedesk_api';

	/**
	 * Idempotent - `add_role()` is a no-op if the role already exists.
	 * Called once, from {@see Plugin::activate()}.
	 */
	public static function register_role(): void {
		add_role(
			self::ROLE,
			__( 'Flytedesk API', 'flytedesk-sponsored-content' ),
			array(
				'read'                         => true,
				self::MANAGE_SPONSORED_CONTENT => true,
			)
		);
	}
}
