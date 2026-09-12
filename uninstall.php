<?php
/**
 * Runs when this plugin is deleted from the Plugins screen (or via
 * `wp plugin uninstall`) - WordPress core includes this file directly,
 * guarded by the `WP_UNINSTALL_PLUGIN` constant it defines, without
 * loading the plugin's own bootstrap file first.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/*
 * Removes everything this plugin itself created: the flytebot user and its
 * Application Password, every `flytedesk_*` option, and the `flytedesk_api`
 * role. Deliberately leaves `fdsc_sponsored_post` content in place -
 * uninstalling the connector to sponsored.flytedesk.com should not silently
 * delete a publisher's already-published articles (see README.md's "Notes
 * on uninstall").
 */
( new Registration\ApiCredential() )->delete_user();
( new Registration\Client() )->delete_all_data();
( new Registration\VerificationTracker() )->delete_all_data();
( new Registration\Consent() )->delete_all_data();
Capabilities::remove_role();
