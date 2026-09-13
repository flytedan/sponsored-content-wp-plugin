<?php
/**
 * Plugin Name:       Flytedesk Hosted Content
 * Plugin URI:        https://flytedesk.com
 * Description:       Publishes flytedesk-managed hosted/native articles to this site via a REST API, writing correct SEO metadata regardless of which SEO plugin (Yoast, Rank Math, All in One SEO) is active - or none at all.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Tested up to:      7.1
 * Author:            flytedesk
 * Author URI:        https://flytedesk.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flytedesk-hosted-content
 * Domain Path:       /languages
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

define( 'FLYTEDESK_HOSTED_CONTENT_VERSION', '1.0.0' );
define( 'FLYTEDESK_HOSTED_CONTENT_FILE', __FILE__ );
define( 'FLYTEDESK_HOSTED_CONTENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLYTEDESK_HOSTED_CONTENT_URL', plugin_dir_url( __FILE__ ) );

/*
 * WordPress core has validated this plugin's "Requires at least" and
 * "Requires PHP" headers (above) against the current site before this file
 * is even included - that check has run on every activation since
 * WordPress 5.2 (see `validate_plugin_requirements()`, wired into
 * `activate_plugin()`), and it also blocks the plugin from loading in
 * `plugin_sandbox_scrape()` / on every admin page load thereafter if the
 * environment later regresses below either requirement. Re-implementing
 * that gate here would duplicate logic core already owns, so this bootstrap
 * does not - it relies on the headers being accurate and lets core enforce
 * them.
 */

if ( ! file_exists( FLYTEDESK_HOSTED_CONTENT_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Flytedesk Hosted Content is missing its Composer dependencies. Run "composer install --no-dev" in the plugin directory.', 'flytedesk-hosted-content' )
			);
		}
	);

	return;
}

require_once FLYTEDESK_HOSTED_CONTENT_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

Plugin::instance()->boot();
