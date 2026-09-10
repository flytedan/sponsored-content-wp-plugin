<?php
/**
 * SEO adapter for Yoast SEO.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoastAdapter extends AbstractMetaAdapter {

	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	protected function field_map(): array {
		return array(
			'meta_title'       => array(
				'key'      => '_yoast_wpseo_title',
				'sanitize' => 'text',
			),
			'meta_description' => array(
				'key'      => '_yoast_wpseo_metadesc',
				'sanitize' => 'text',
			),
			// Yoast's free-tier focus keyword field holds a single phrase;
			// take the first comma-separated term flytedesk sent.
			'meta_keywords'    => array(
				'key'       => '_yoast_wpseo_focuskw',
				'sanitize'  => 'text',
				'transform' => array( self::class, 'first_term' ),
			),
			'og.title'         => array(
				'key'      => '_yoast_wpseo_opengraph-title',
				'sanitize' => 'text',
			),
			'og.description'   => array(
				'key'      => '_yoast_wpseo_opengraph-description',
				'sanitize' => 'text',
			),
			'og.image'         => array(
				'key'      => '_yoast_wpseo_opengraph-image',
				'sanitize' => 'url',
			),
			'og.type'          => array(
				'key'      => '_yoast_wpseo_opengraph-type',
				'sanitize' => 'text',
			),
		);
	}

	public static function first_term( string $keywords ): string {
		$parts = array_map( 'trim', explode( ',', $keywords ) );

		return $parts[0] ?? '';
	}
}
