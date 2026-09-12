<?php
/**
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Tests\Unit\Seo;

use Brain\Monkey\Functions;
use Flytedesk\HostedContent\Seo\FallbackAdapter;
use Flytedesk\HostedContent\Tests\Unit\BrainMonkeyTestCase;

final class FallbackAdapterTest extends BrainMonkeyTestCase {

	public function test_is_always_active(): void {
		$this->assertTrue( ( new FallbackAdapter() )->is_active() );
	}

	public function test_write_stores_fields_under_its_own_meta_keys(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'update_post_meta' )->once()->with( 9, '_flytedesk_seo_meta_title', 'Title' );
		Functions\expect( 'update_post_meta' )->once()->with( 9, '_flytedesk_seo_meta_description', 'Description' );
		Functions\expect( 'update_post_meta' )->once()->with( 9, '_flytedesk_seo_meta_keywords', 'a, b' );
		Functions\expect( 'update_post_meta' )->once()->with( 9, '_flytedesk_seo_og_title', 'OG Title' );
		Functions\expect( 'update_post_meta' )->once()->with( 9, '_flytedesk_seo_og_description', 'OG Description' );
		Functions\expect( 'update_post_meta' )->once()->with( 9, '_flytedesk_seo_og_image', 'https://example.com/og.jpg' );
		Functions\expect( 'update_post_meta' )->once()->with( 9, '_flytedesk_seo_og_type', 'article' );

		( new FallbackAdapter() )->write(
			9,
			array(
				'meta_title'       => 'Title',
				'meta_description' => 'Description',
				'meta_keywords'    => 'a, b',
				'og'               => array(
					'title'       => 'OG Title',
					'description' => 'OG Description',
					'image'       => 'https://example.com/og.jpg',
					'type'        => 'article',
				),
			)
		);
	}

	public function test_output_head_meta_uses_stored_description(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key ) {
				$values = array(
					'_flytedesk_seo_meta_description' => 'Stored description.',
					'_flytedesk_seo_og_title'         => '',
					'_flytedesk_seo_og_description'   => '',
					'_flytedesk_seo_og_image'         => 'https://example.com/og.jpg',
					'_flytedesk_seo_og_type'          => '',
				);

				return $values[ $key ] ?? '';
			}
		);
		Functions\when( 'get_the_title' )->justReturn( 'The Title' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/hosted-content/post/' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		$output = $this->capture_output( static fn() => ( new FallbackAdapter() )->output_head_meta( 9 ) );

		$this->assertStringContainsString( '<meta name="description" content="Stored description." />', $output );
		// og:title falls back to the post title when none was supplied via the API.
		$this->assertStringContainsString( '<meta property="og:title" content="The Title" />', $output );
		// og:description falls back to the resolved description.
		$this->assertStringContainsString( '<meta property="og:description" content="Stored description." />', $output );
		$this->assertStringContainsString( '<meta property="og:image" content="https://example.com/og.jpg" />', $output );
		// og:type falls back to "article" when none was supplied.
		$this->assertStringContainsString( '<meta property="og:type" content="article" />', $output );
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.com/hosted-content/post/" />', $output );
	}

	public function test_output_head_meta_falls_back_to_post_excerpt_when_no_description_stored(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_excerpt' )->justReturn( 'Excerpt-derived description.' );
		Functions\when( 'get_the_title' )->justReturn( 'The Title' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/hosted-content/post/' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		$output = $this->capture_output( static fn() => ( new FallbackAdapter() )->output_head_meta( 9 ) );

		$this->assertStringContainsString( '<meta name="description" content="Excerpt-derived description." />', $output );
	}

	private function capture_output( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}
}
