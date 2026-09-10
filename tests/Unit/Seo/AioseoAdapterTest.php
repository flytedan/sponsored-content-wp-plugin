<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Unit\Seo;

use Brain\Monkey\Functions;
use Flytedesk\SponsoredContent\Seo\AioseoAdapter;
use Flytedesk\SponsoredContent\Tests\Unit\BrainMonkeyTestCase;

final class AioseoAdapterTest extends BrainMonkeyTestCase {

	public function test_is_active_reflects_aioseo_version_constant(): void {
		$adapter = new AioseoAdapter();

		Functions\when( 'defined' )->justReturn( false );
		$this->assertFalse( $adapter->is_active() );

		Functions\when( 'defined' )->justReturn( true );
		$this->assertTrue( $adapter->is_active() );
	}

	public function test_write_stores_fields_under_aioseo_meta_keys(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'update_post_meta' )->once()->with( 3, '_aioseo_title', 'Title' );
		Functions\expect( 'update_post_meta' )->once()->with( 3, '_aioseo_description', 'Description' );
		Functions\expect( 'update_post_meta' )->once()->with( 3, '_aioseo_og_title', 'OG Title' );
		Functions\expect( 'update_post_meta' )->once()->with( 3, '_aioseo_og_description', 'OG Description' );
		Functions\expect( 'update_post_meta' )->once()->with( 3, '_aioseo_og_image', 'https://example.com/og.jpg' );

		( new AioseoAdapter() )->write(
			3,
			array(
				'meta_title'       => 'Title',
				'meta_description' => 'Description',
				'meta_keywords'    => 'ignored, aioseo has no focus keyword field',
				'og'               => array(
					'title'       => 'OG Title',
					'description' => 'OG Description',
					'image'       => 'https://example.com/og.jpg',
					'type'        => 'ignored too',
				),
			)
		);
	}

	public function test_read_returns_empty_slug_keywords_and_og_type(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$seo = ( new AioseoAdapter() )->read( 3 );

		$this->assertSame( '', $seo['slug'] );
		$this->assertSame( '', $seo['meta_keywords'] );
		$this->assertSame( '', $seo['og']['type'] );
	}
}
