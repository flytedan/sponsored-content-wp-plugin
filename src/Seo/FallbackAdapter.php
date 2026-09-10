<?php
/**
 * Last-resort SEO adapter used when no supported SEO plugin is active.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores SEO fields on dedicated post meta keys (since there is no SEO
 * plugin meta box to piggyback on), and is responsible for rendering the
 * description / Open Graph tags into wp_head() itself - otherwise a site
 * with zero SEO plugins installed would receive flytedesk content with no
 * SEO metadata output at all.
 */
class FallbackAdapter extends AbstractMetaAdapter {

	private const META_TITLE       = '_flytedesk_seo_meta_title';
	private const META_DESCRIPTION = '_flytedesk_seo_meta_description';
	private const META_KEYWORDS    = '_flytedesk_seo_meta_keywords';
	private const META_OG_TITLE    = '_flytedesk_seo_og_title';
	private const META_OG_DESC     = '_flytedesk_seo_og_description';
	private const META_OG_IMAGE    = '_flytedesk_seo_og_image';
	private const META_OG_TYPE     = '_flytedesk_seo_og_type';

	/**
	 * Always active - this is the last-resort adapter used when no
	 * supported SEO plugin is detected on the site.
	 */
	public function is_active(): bool {
		return true;
	}

	protected function field_map(): array {
		return array(
			'meta_title'       => array(
				'key'      => self::META_TITLE,
				'sanitize' => 'text',
			),
			'meta_description' => array(
				'key'      => self::META_DESCRIPTION,
				'sanitize' => 'text',
			),
			'meta_keywords'    => array(
				'key'      => self::META_KEYWORDS,
				'sanitize' => 'text',
			),
			'og.title'         => array(
				'key'      => self::META_OG_TITLE,
				'sanitize' => 'text',
			),
			'og.description'   => array(
				'key'      => self::META_OG_DESC,
				'sanitize' => 'text',
			),
			'og.image'         => array(
				'key'      => self::META_OG_IMAGE,
				'sanitize' => 'url',
			),
			'og.type'          => array(
				'key'      => self::META_OG_TYPE,
				'sanitize' => 'text',
			),
		);
	}

	/**
	 * Emit <meta name="description"> and basic Open Graph tags on the front
	 * end. Hooked to wp_head by Plugin::maybe_output_fallback_head_meta(),
	 * and only invoked when this adapter is the one currently resolved as
	 * active.
	 */
	public function output_head_meta( int $post_id ): void {
		$description = (string) get_post_meta( $post_id, self::META_DESCRIPTION, true );

		if ( '' === $description ) {
			// Nothing supplied via the API - fall back to the post excerpt,
			// which is the closest built-in WordPress analog.
			$description = get_the_excerpt( $post_id );
		}

		$og_title = (string) get_post_meta( $post_id, self::META_OG_TITLE, true );
		$og_desc  = (string) get_post_meta( $post_id, self::META_OG_DESC, true );
		$og_image = (string) get_post_meta( $post_id, self::META_OG_IMAGE, true );
		$og_type  = (string) get_post_meta( $post_id, self::META_OG_TYPE, true );

		if ( '' === $og_title ) {
			$og_title = get_the_title( $post_id );
		}

		if ( '' === $og_desc ) {
			$og_desc = $description;
		}

		if ( '' === $og_type ) {
			$og_type = 'article';
		}

		if ( '' !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		if ( '' !== $og_title ) {
			printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $og_title ) );
		}

		if ( '' !== $og_desc ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $og_desc ) );
		}

		if ( '' !== $og_image ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $og_image ) );
		}

		printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( $og_type ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( (string) get_permalink( $post_id ) ) );
	}
}
