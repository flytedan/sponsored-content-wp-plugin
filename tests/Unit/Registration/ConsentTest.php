<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Unit\Registration;

use Brain\Monkey\Functions;
use Flytedesk\HostedContent\Registration\Consent;
use Flytedesk\HostedContent\Tests\Unit\BrainMonkeyTestCase;

final class ConsentTest extends BrainMonkeyTestCase {

	public function test_has_been_granted_is_false_by_default(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertFalse( ( new Consent() )->has_been_granted() );
	}

	public function test_grant_records_the_timestamp_user_id_login_and_ip(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-11 22:00:00' );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_login' => 'admin' ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';

		Functions\expect( 'update_option' )->once()->with( Consent::OPTION_GRANTED_AT, '2026-09-11 22:00:00' );
		Functions\expect( 'update_option' )->once()->with( Consent::OPTION_GRANTED_BY_USER_ID, 7 );
		Functions\expect( 'update_option' )->once()->with( Consent::OPTION_GRANTED_BY_LOGIN, 'admin' );
		Functions\expect( 'update_option' )->once()->with( Consent::OPTION_GRANTED_BY_IP, '203.0.113.42' );

		( new Consent() )->grant( 7 );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_grant_records_an_empty_login_when_the_user_no_longer_exists(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-11 22:00:00' );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		Functions\expect( 'update_option' )->once()->with( Consent::OPTION_GRANTED_AT, '2026-09-11 22:00:00' );
		Functions\expect( 'update_option' )->once()->with( Consent::OPTION_GRANTED_BY_USER_ID, 999 );
		Functions\expect( 'update_option' )->once()->with( Consent::OPTION_GRANTED_BY_LOGIN, '' );
		Functions\expect( 'update_option' )->once()->with( Consent::OPTION_GRANTED_BY_IP, '' );

		( new Consent() )->grant( 999 );
	}

	public function test_has_been_granted_is_true_once_granted_at_is_set(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key ) => Consent::OPTION_GRANTED_AT === $key ? '2026-09-11 22:00:00' : ''
		);

		$this->assertTrue( ( new Consent() )->has_been_granted() );
	}

	public function test_getters_read_back_what_was_recorded(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				$values = array(
					Consent::OPTION_GRANTED_AT       => '2026-09-11 22:00:00',
					Consent::OPTION_GRANTED_BY_LOGIN => 'admin',
					Consent::OPTION_GRANTED_BY_IP    => '203.0.113.42',
				);

				return $values[ $key ] ?? '';
			}
		);

		$consent = new Consent();

		$this->assertSame( '2026-09-11 22:00:00', $consent->get_granted_at() );
		$this->assertSame( 'admin', $consent->get_granted_by_login() );
		$this->assertSame( '203.0.113.42', $consent->get_granted_by_ip() );
	}

	public function test_delete_all_data_deletes_every_option_this_class_owns(): void {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( string $option ) use ( &$deleted ): bool {
				$deleted[] = $option;

				return true;
			}
		);

		( new Consent() )->delete_all_data();

		$expected = array(
			Consent::OPTION_GRANTED_AT,
			Consent::OPTION_GRANTED_BY_USER_ID,
			Consent::OPTION_GRANTED_BY_LOGIN,
			Consent::OPTION_GRANTED_BY_IP,
		);

		sort( $expected );
		sort( $deleted );

		$this->assertSame( $expected, $deleted );
	}
}
