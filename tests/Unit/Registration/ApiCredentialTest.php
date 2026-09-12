<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Unit\Registration;

use Brain\Monkey\Functions;
use Flytedesk\HostedContent\Capabilities;
use Flytedesk\HostedContent\Registration\ApiCredential;
use Flytedesk\HostedContent\Registration\ApplicationPasswordIssuer;
use Flytedesk\HostedContent\Tests\Unit\BrainMonkeyTestCase;
use RuntimeException;

final class ApiCredentialTest extends BrainMonkeyTestCase {

	public function test_get_username_is_flytebot(): void {
		$this->assertSame( 'flytebot', ( new ApiCredential() )->get_username() );
	}

	public function test_issue_creates_the_flytebot_user_when_none_exists(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				return ApiCredential::OPTION_USER_ID === $key ? 0 : '';
			}
		);
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'random-login-password' );
		Functions\when( 'home_url' )->justReturn( 'https://publisher.example.com' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this closure IS the wp_parse_url() stub, so it must call the native parse_url() it stands in for.
		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\expect( 'wp_insert_user' )->once()->with(
			\Mockery::on(
				static function ( array $args ): bool {
					return 'flytebot' === $args['user_login']
						&& Capabilities::ROLE === $args['role']
						&& str_ends_with( $args['user_email'], '.invalid' );
				}
			)
		)->andReturn( 42 );

		Functions\expect( 'update_option' )->once()->with( ApiCredential::OPTION_USER_ID, 42 );

		$issuer = \Mockery::mock( ApplicationPasswordIssuer::class );
		$issuer->shouldReceive( 'create' )
			->once()
			->with( 42, \Mockery::type( 'array' ) )
			->andReturn( array( 'plaintext-password', array( 'uuid' => 'uuid-1' ) ) );

		Functions\expect( 'update_option' )->once()->with( ApiCredential::OPTION_PASSWORD_UUID, 'uuid-1' );

		$password = ( new ApiCredential( $issuer ) )->issue();

		$this->assertSame( 'plaintext-password', $password );
	}

	public function test_issue_reuses_a_previously_tracked_user_id_without_recreating(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				if ( ApiCredential::OPTION_USER_ID === $key ) {
					return 7;
				}

				return '';
			}
		);
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 7 ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'wp_insert_user' )->never();

		$issuer = \Mockery::mock( ApplicationPasswordIssuer::class );
		$issuer->shouldReceive( 'create' )->once()->with( 7, \Mockery::type( 'array' ) )
			->andReturn( array( 'pw', array( 'uuid' => 'uuid-2' ) ) );

		( new ApiCredential( $issuer ) )->issue();
	}

	public function test_issue_finds_existing_user_by_login_when_tracked_id_is_stale(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				if ( ApiCredential::OPTION_USER_ID === $key ) {
					return 999; // Stale/deleted user ID.
				}

				return '';
			}
		);
		Functions\when( 'get_user_by' )->alias(
			static function ( string $field, $value ) {
				if ( 'id' === $field ) {
					return false; // The tracked ID no longer resolves to a real user.
				}

				return 'flytebot' === $value ? (object) array( 'ID' => 15 ) : false;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\expect( 'wp_insert_user' )->never();
		Functions\expect( 'update_option' )->once()->with( ApiCredential::OPTION_USER_ID, 15 );

		$issuer = \Mockery::mock( ApplicationPasswordIssuer::class );
		$issuer->shouldReceive( 'create' )->once()->with( 15, \Mockery::type( 'array' ) )
			->andReturn( array( 'pw', array( 'uuid' => 'uuid-3' ) ) );

		( new ApiCredential( $issuer ) )->issue();
	}

	public function test_issue_revokes_the_previously_issued_password_before_creating_a_new_one(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				if ( ApiCredential::OPTION_USER_ID === $key ) {
					return 7;
				}

				if ( ApiCredential::OPTION_PASSWORD_UUID === $key ) {
					return 'old-uuid';
				}

				return '';
			}
		);
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 7 ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'delete_option' )->once()->with( ApiCredential::OPTION_PASSWORD_UUID );

		$issuer = \Mockery::mock( ApplicationPasswordIssuer::class );
		$issuer->shouldReceive( 'delete' )->once()->with( 7, 'old-uuid' )->andReturn( true );
		$issuer->shouldReceive( 'create' )->once()->andReturn( array( 'pw', array( 'uuid' => 'new-uuid' ) ) );

		( new ApiCredential( $issuer ) )->issue();
	}

	public function test_issue_does_not_attempt_revocation_when_there_is_no_previous_password(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				if ( ApiCredential::OPTION_USER_ID === $key ) {
					return 7;
				}

				return '';
			}
		);
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 7 ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );

		$issuer = \Mockery::mock( ApplicationPasswordIssuer::class );
		$issuer->shouldReceive( 'delete' )->never();
		$issuer->shouldReceive( 'create' )->once()->andReturn( array( 'pw', array( 'uuid' => 'uuid' ) ) );

		( new ApiCredential( $issuer ) )->issue();
	}

	public function test_issue_throws_when_password_creation_returns_a_wp_error(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) {
				if ( ApiCredential::OPTION_USER_ID === $key ) {
					return 7;
				}

				return '';
			}
		);
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 7 ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$wp_error = \Mockery::mock( 'WP_Error' );
		$wp_error->shouldReceive( 'get_error_message' )->once()->andReturn( 'Password creation failed.' );

		$issuer = \Mockery::mock( ApplicationPasswordIssuer::class );
		$issuer->shouldReceive( 'create' )->once()->andReturn( $wp_error );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Password creation failed.' );

		( new ApiCredential( $issuer ) )->issue();
	}

	public function test_issue_throws_when_user_creation_fails(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'x' );
		Functions\when( 'home_url' )->justReturn( 'https://publisher.example.com' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this closure IS the wp_parse_url() stub, so it must call the native parse_url() it stands in for.

		$wp_error = \Mockery::mock( 'WP_Error' );
		$wp_error->shouldReceive( 'get_error_message' )->once()->andReturn( 'Username already exists.' );

		Functions\when( 'wp_insert_user' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$issuer = \Mockery::mock( ApplicationPasswordIssuer::class );
		$issuer->shouldReceive( 'create' )->never();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Username already exists.' );

		( new ApiCredential( $issuer ) )->issue();
	}

	public function test_delete_user_deletes_the_tracked_user_and_forgets_both_options(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key ) => ApiCredential::OPTION_USER_ID === $key ? 7 : ''
		);

		Functions\expect( 'wp_delete_user' )->once()->with( 7 )->andReturn( true );
		Functions\expect( 'delete_option' )->once()->with( ApiCredential::OPTION_USER_ID );
		Functions\expect( 'delete_option' )->once()->with( ApiCredential::OPTION_PASSWORD_UUID );

		( new ApiCredential() )->delete_user();
	}

	public function test_delete_user_does_not_call_wp_delete_user_when_no_user_is_tracked(): void {
		Functions\when( 'get_option' )->justReturn( 0 );

		Functions\expect( 'wp_delete_user' )->never()->andReturn( true );
		Functions\expect( 'delete_option' )->once()->with( ApiCredential::OPTION_USER_ID );
		Functions\expect( 'delete_option' )->once()->with( ApiCredential::OPTION_PASSWORD_UUID );

		( new ApiCredential() )->delete_user();
	}
}
