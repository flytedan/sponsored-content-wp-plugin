<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Unit\Registration;

use Brain\Monkey\Functions;
use Flytedesk\HostedContent\Registration\VerificationTracker;
use Flytedesk\HostedContent\Tests\Unit\BrainMonkeyTestCase;

final class VerificationTrackerTest extends BrainMonkeyTestCase {

	public function test_getters_default_to_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$tracker = new VerificationTracker();

		$this->assertSame( '', $tracker->get_create_verified_at() );
		$this->assertSame( '', $tracker->get_update_verified_at() );
		$this->assertSame( '', $tracker->get_delete_verified_at() );
	}

	public function test_mark_create_verified_records_a_timestamp_when_not_previously_set(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'current_time' )->justReturn( '2026-09-11 09:00:00' );

		Functions\expect( 'update_option' )->once()->with( VerificationTracker::OPTION_CREATE_VERIFIED_AT, '2026-09-11 09:00:00' );

		( new VerificationTracker() )->mark_create_verified();
	}

	public function test_mark_update_verified_records_a_timestamp_when_not_previously_set(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'current_time' )->justReturn( '2026-09-11 09:00:00' );

		Functions\expect( 'update_option' )->once()->with( VerificationTracker::OPTION_UPDATE_VERIFIED_AT, '2026-09-11 09:00:00' );

		( new VerificationTracker() )->mark_update_verified();
	}

	public function test_mark_delete_verified_records_a_timestamp_when_not_previously_set(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'current_time' )->justReturn( '2026-09-11 09:00:00' );

		Functions\expect( 'update_option' )->once()->with( VerificationTracker::OPTION_DELETE_VERIFIED_AT, '2026-09-11 09:00:00' );

		( new VerificationTracker() )->mark_delete_verified();
	}

	/**
	 * "At least 1 successful response" means the first success is what
	 * counts - a second, third, or hundredth successful create must not
	 * keep bumping the recorded timestamp.
	 */
	public function test_mark_create_verified_does_not_overwrite_an_existing_timestamp(): void {
		Functions\when( 'get_option' )->justReturn( '2026-09-11 08:00:00' );

		Functions\expect( 'update_option' )->never();

		( new VerificationTracker() )->mark_create_verified();
	}

	public function test_is_fully_verified_is_false_until_all_three_are_set(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				return VerificationTracker::OPTION_CREATE_VERIFIED_AT === $key ? '2026-09-11 09:00:00' : '';
			}
		);

		$this->assertFalse( ( new VerificationTracker() )->is_fully_verified() );
	}

	public function test_is_fully_verified_is_true_once_all_three_are_set(): void {
		Functions\when( 'get_option' )->justReturn( '2026-09-11 09:00:00' );

		$this->assertTrue( ( new VerificationTracker() )->is_fully_verified() );
	}

	public function test_get_verified_at_is_empty_until_fully_verified(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertSame( '', ( new VerificationTracker() )->get_verified_at() );
	}

	public function test_get_verified_at_is_the_latest_of_the_three_timestamps(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				$values = array(
					VerificationTracker::OPTION_CREATE_VERIFIED_AT => '2026-09-11 09:00:00',
					VerificationTracker::OPTION_UPDATE_VERIFIED_AT => '2026-09-11 09:10:00',
					VerificationTracker::OPTION_DELETE_VERIFIED_AT => '2026-09-11 09:05:00',
				);

				return $values[ $key ];
			}
		);

		$this->assertSame( '2026-09-11 09:10:00', ( new VerificationTracker() )->get_verified_at() );
	}

	public function test_delete_all_data_deletes_every_option_this_class_owns(): void {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( string $option ) use ( &$deleted ): bool {
				$deleted[] = $option;

				return true;
			}
		);

		( new VerificationTracker() )->delete_all_data();

		$expected = array(
			VerificationTracker::OPTION_CREATE_VERIFIED_AT,
			VerificationTracker::OPTION_UPDATE_VERIFIED_AT,
			VerificationTracker::OPTION_DELETE_VERIFIED_AT,
		);

		sort( $expected );
		sort( $deleted );

		$this->assertSame( $expected, $deleted );
	}
}
