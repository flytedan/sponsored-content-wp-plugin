<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Unit\Registration;

use Brain\Monkey\Functions;
use Flytedesk\HostedContent\Registration\ApiCredential;
use Flytedesk\HostedContent\Tests\Unit\BrainMonkeyTestCase;

final class ApiCredentialTest extends BrainMonkeyTestCase {

	public function test_issue_stores_a_hash_of_a_64_character_hex_key_and_returns_the_plaintext_key(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-22 12:00:00' );

		$stored = array();
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$stored ): bool {
				$stored[ $key ] = $value;

				return true;
			}
		);

		$key = ( new ApiCredential() )->issue();

		$this->assertSame( 64, strlen( $key ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $key );
		$this->assertSame( hash( 'sha256', $key ), $stored[ ApiCredential::OPTION_KEY_HASH ] );
		$this->assertSame( '2026-09-22 12:00:00', $stored[ ApiCredential::OPTION_ISSUED_AT ] );
	}

	public function test_issue_generates_a_different_key_on_each_call(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-22 12:00:00' );
		Functions\when( 'update_option' )->justReturn( true );

		$credential = new ApiCredential();

		$this->assertNotSame( $credential->issue(), $credential->issue() );
	}

	public function test_get_issued_at_defaults_to_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( '', ( new ApiCredential() )->get_issued_at() );
	}

	public function test_verify_returns_true_for_the_exact_key_that_was_issued(): void {
		$stored_hash = hash( 'sha256', 'the-real-key' );
		Functions\when( 'get_option' )->alias(
			static fn ( string $option ) => ApiCredential::OPTION_KEY_HASH === $option ? $stored_hash : ''
		);

		$this->assertTrue( ( new ApiCredential() )->verify( 'the-real-key' ) );
	}

	public function test_verify_returns_false_for_the_wrong_key(): void {
		$stored_hash = hash( 'sha256', 'the-real-key' );
		Functions\when( 'get_option' )->alias(
			static fn ( string $option ) => ApiCredential::OPTION_KEY_HASH === $option ? $stored_hash : ''
		);

		$this->assertFalse( ( new ApiCredential() )->verify( 'a-wrong-guess' ) );
	}

	public function test_verify_returns_false_for_an_empty_provided_key(): void {
		Functions\when( 'get_option' )->justReturn( hash( 'sha256', 'the-real-key' ) );

		$this->assertFalse( ( new ApiCredential() )->verify( '' ) );
	}

	public function test_verify_returns_false_when_no_key_has_ever_been_issued(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertFalse( ( new ApiCredential() )->verify( 'anything' ) );
	}

	public function test_delete_all_data_deletes_every_option_this_class_owns(): void {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( string $option ) use ( &$deleted ): bool {
				$deleted[] = $option;

				return true;
			}
		);

		( new ApiCredential() )->delete_all_data();

		$expected = array( ApiCredential::OPTION_KEY_HASH, ApiCredential::OPTION_ISSUED_AT );

		sort( $expected );
		sort( $deleted );

		$this->assertSame( $expected, $deleted );
	}
}
