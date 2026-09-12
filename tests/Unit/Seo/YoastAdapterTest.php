<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Unit\Seo;

use Brain\Monkey\Functions;
use Flytedesk\HostedContent\Seo\YoastAdapter;
use Flytedesk\HostedContent\Tests\Unit\BrainMonkeyTestCase;

final class YoastAdapterTest extends BrainMonkeyTestCase {

	public function test_is_active_reflects_wpseo_version_constant(): void {
		$adapter = new YoastAdapter();

		Functions\when( 'defined' )->justReturn( false );
		$this->assertFalse( $adapter->is_active() );

		Functions\when( 'defined' )->justReturn( true );
		$this->assertTrue( $adapter->is_active() );
	}

	public function test_write_stores_every_field_under_yoast_meta_keys(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_yoast_wpseo_title', 'My Title' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_yoast_wpseo_metadesc', 'My description' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_yoast_wpseo_focuskw', 'first keyword' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_yoast_wpseo_opengraph-title', 'OG Title' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_yoast_wpseo_opengraph-description', 'OG Description' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_yoast_wpseo_opengraph-image', 'https://example.com/og.jpg' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_yoast_wpseo_opengraph-type', 'article' );

		( new YoastAdapter() )->write(
			42,
			array(
				'meta_title'       => 'My Title',
				'meta_description' => 'My description',
				'slug'             => 'ignored-by-adapters',
				'meta_keywords'    => 'first keyword, second keyword',
				'og'               => array(
					'title'       => 'OG Title',
					'description' => 'OG Description',
					'image'       => 'https://example.com/og.jpg',
					'type'        => 'article',
				),
			)
		);
	}

	public function test_write_only_takes_first_comma_separated_keyword(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 1, '_yoast_wpseo_focuskw', 'alpha' );

		( new YoastAdapter() )->write( 1, array( 'meta_keywords' => 'alpha, beta, gamma' ) );
	}

	public function test_write_skips_empty_fields(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'update_post_meta' )->never();

		( new YoastAdapter() )->write( 1, array() );
	}

	public function test_read_maps_yoast_meta_keys_back_to_the_shared_shape(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key ) {
				$values = array(
					'_yoast_wpseo_title'                 => 'Stored Title',
					'_yoast_wpseo_metadesc'              => 'Stored Description',
					'_yoast_wpseo_focuskw'               => 'stored keyword',
					'_yoast_wpseo_opengraph-title'       => 'Stored OG Title',
					'_yoast_wpseo_opengraph-description' => 'Stored OG Description',
					'_yoast_wpseo_opengraph-image'       => 'https://example.com/stored.jpg',
					'_yoast_wpseo_opengraph-type'        => 'article',
				);

				return $values[ $key ] ?? '';
			}
		);

		$seo = ( new YoastAdapter() )->read( 42 );

		$this->assertSame(
			array(
				'meta_title'       => 'Stored Title',
				'meta_description' => 'Stored Description',
				'slug'             => '',
				'meta_keywords'    => 'stored keyword',
				'og'               => array(
					'title'       => 'Stored OG Title',
					'description' => 'Stored OG Description',
					'image'       => 'https://example.com/stored.jpg',
					'type'        => 'article',
				),
			),
			$seo
		);
	}
}
