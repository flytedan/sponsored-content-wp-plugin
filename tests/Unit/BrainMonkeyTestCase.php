<?php
/**
 * Base test case for the Brain Monkey-backed unit suite.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Wires Brain Monkey's setUp()/tearDown() into every unit test. WordPress
 * itself is never loaded in this suite (see tests/bootstrap.php) - Brain
 * Monkey intercepts calls to WP functions (`update_post_meta()`,
 * `sanitize_text_field()`, etc.) and lets each test stub/assert on them
 * individually via `Monkey\Functions\when()` / `expect()`.
 *
 * `Functions\expect(...)->once()->with(...)` is verified by Mockery, not by
 * a PHPUnit assert*() call, so without MockeryPHPUnitIntegration those tests
 * would be flagged "risky: did not perform any assertions" even though
 * Mockery genuinely checked the expectation. The trait's `assertPostConditions`
 * hook (runs before tearDown) folds Mockery's verified expectation count into
 * PHPUnit's own assertion count and closes the Mockery container; Brain
 * Monkey's `Monkey\tearDown()` below also calls `Mockery::close()`, which is
 * a safe no-op on an already-closed container.
 */
abstract class BrainMonkeyTestCase extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
