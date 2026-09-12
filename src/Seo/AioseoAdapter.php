<?php
/**
 * SEO adapter for All in One SEO (AIOSEO).
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AioseoAdapter extends AbstractMetaAdapter {

	public function is_active(): bool {
		return defined( 'AIOSEO_VERSION' );
	}

	protected function field_map(): array {
		return array(
			'meta_title'       => array(
				'key'      => '_aioseo_title',
				'sanitize' => 'text',
			),
			'meta_description' => array(
				'key'      => '_aioseo_description',
				'sanitize' => 'text',
			),
			'og.title'         => array(
				'key'      => '_aioseo_og_title',
				'sanitize' => 'text',
			),
			'og.description'   => array(
				'key'      => '_aioseo_og_description',
				'sanitize' => 'text',
			),
			'og.image'         => array(
				'key'      => '_aioseo_og_image',
				'sanitize' => 'url',
			),
		);
	}
}
