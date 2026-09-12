<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Unit\Seo;

use Brain\Monkey\Functions;
use Flytedesk\HostedContent\Seo\RankMathAdapter;
use Flytedesk\HostedContent\Tests\Unit\BrainMonkeyTestCase;

final class RankMathAdapterTest extends BrainMonkeyTestCase {

	public function test_is_active_reflects_rankmath_class_existence(): void {
		$adapter = new RankMathAdapter();

		Functions\when( 'class_exists' )->justReturn( false );
		$this->assertFalse( $adapter->is_active() );

		Functions\when( 'class_exists' )->justReturn( true );
		$this->assertTrue( $adapter->is_active() );
	}

	public function test_write_stores_fields_under_rank_math_meta_keys(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'update_post_meta' )->once()->with( 7, 'rank_math_title', 'Title' );
		Functions\expect( 'update_post_meta' )->once()->with( 7, 'rank_math_description', 'Description' );
		Functions\expect( 'update_post_meta' )->once()->with( 7, 'rank_math_focus_keyword', 'flytedesk' );
		Functions\expect( 'update_post_meta' )->once()->with( 7, 'rank_math_facebook_title', 'OG Title' );
		Functions\expect( 'update_post_meta' )->once()->with( 7, 'rank_math_facebook_description', 'OG Description' );
		Functions\expect( 'update_post_meta' )->once()->with( 7, 'rank_math_facebook_image', 'https://example.com/og.jpg' );

		( new RankMathAdapter() )->write(
			7,
			array(
				'meta_title'       => 'Title',
				'meta_description' => 'Description',
				'meta_keywords'    => 'flytedesk',
				'og'               => array(
					'title'       => 'OG Title',
					'description' => 'OG Description',
					'image'       => 'https://example.com/og.jpg',
				),
			)
		);
	}

	public function test_write_does_not_map_og_type_rank_math_has_no_equivalent_for(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		// Only `og.type` is supplied, and Rank Math's field map has no entry
		// for it - so nothing should be persisted at all.
		Functions\expect( 'update_post_meta' )->never();

		( new RankMathAdapter() )->write( 7, array( 'og' => array( 'type' => 'article' ) ) );
	}

	public function test_read_returns_empty_slug_and_no_og_type(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$seo = ( new RankMathAdapter() )->read( 7 );

		$this->assertSame( '', $seo['slug'] );
		$this->assertSame( '', $seo['og']['type'] );
	}
}
