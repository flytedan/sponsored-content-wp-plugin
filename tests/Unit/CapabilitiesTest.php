<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Unit;

use Brain\Monkey\Functions;
use Flytedesk\HostedContent\Capabilities;

final class CapabilitiesTest extends BrainMonkeyTestCase {

	public function test_register_role_adds_the_flytedesk_api_role_with_only_read_and_manage_capabilities(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_role' )->once()->with(
			Capabilities::ROLE,
			\Mockery::type( 'string' ),
			array(
				'read'                              => true,
				Capabilities::MANAGE_HOSTED_CONTENT => true,
			)
		);

		Capabilities::register_role();
	}

	public function test_remove_role_removes_the_flytedesk_api_role(): void {
		Functions\expect( 'remove_role' )->once()->with( Capabilities::ROLE );

		Capabilities::remove_role();
	}
}
