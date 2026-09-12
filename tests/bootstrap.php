<?php
/**
 * PHPUnit bootstrap - dual mode.
 *
 * The `unit` testsuite (tests/Unit) never loads WordPress: it uses Brain
 * Monkey to mock the handful of WP functions each class under test calls,
 * so those tests run in milliseconds with no database or WP install
 * required. The `integration` testsuite (tests/Integration) needs the real
 * thing - a running WordPress install with WP_UnitTestCase available - to
 * exercise REST route registration, wp_insert_post(), and the Application
 * Passwords auth flow end to end.
 *
 * Which mode this file boots into is decided by the WP_TESTS_DIR
 * environment variable: `wp-env run tests-cli` (see composer.json's
 * `test:integration` script) sets it automatically to point at the
 * WordPress core PHPUnit test suite bundled into the `tests-cli`
 * container; when it's absent (a plain `phpunit --testsuite unit` run) we
 * boot the lightweight Brain Monkey path instead. This mirrors the
 * approach documented at
 * https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/ and used
 * by `wp scaffold plugin-tests`.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $wp_tests_dir || '' === $wp_tests_dir ) {
	$wp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	// Integration suite: boot the real WordPress core PHPUnit test suite.
	// WP core's bootstrap needs the PHPUnit Polyfills autoloaded first so
	// its own test-case classes work across the range of PHPUnit versions
	// WordPress supports - see
	// https://github.com/Yoast/PHPUnit-Polyfills#wordpress-plugintheme-installation.
	if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
	}

	require_once $wp_tests_dir . '/includes/functions.php';

	/**
	 * Load this plugin into the test WordPress install the same way a real
	 * site would - as a regular active plugin - rather than requiring its
	 * classes directly, so route registration, hook wiring, and activation
	 * behaviour are all exercised for real.
	 */
	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			require_once dirname( __DIR__ ) . '/hosted-content-wp-plugin.php';
		}
	);

	require_once $wp_tests_dir . '/includes/bootstrap.php';

	return;
}

// Unit suite: WordPress core is never loaded here - Brain Monkey mocks WP
// functions per test case instead (see Tests\Unit\BrainMonkeyTestCase).
// Every class under src/ starts with an `if ( ! defined( 'ABSPATH' ) ) exit;`
// direct-access guard, so a placeholder ABSPATH must exist before any of
// them are autoloaded; its value is otherwise meaningless in this mode.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
