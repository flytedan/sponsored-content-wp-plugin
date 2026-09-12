<?php
/**
 * SEO adapter for Rank Math.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RankMathAdapter extends AbstractMetaAdapter {

	public function is_active(): bool {
		return class_exists( 'RankMath' );
	}

	protected function field_map(): array {
		return array(
			'meta_title'       => array(
				'key'      => 'rank_math_title',
				'sanitize' => 'text',
			),
			'meta_description' => array(
				'key'      => 'rank_math_description',
				'sanitize' => 'text',
			),
			'meta_keywords'    => array(
				'key'      => 'rank_math_focus_keyword',
				'sanitize' => 'text',
			),
			'og.title'         => array(
				'key'      => 'rank_math_facebook_title',
				'sanitize' => 'text',
			),
			'og.description'   => array(
				'key'      => 'rank_math_facebook_description',
				'sanitize' => 'text',
			),
			'og.image'         => array(
				'key'      => 'rank_math_facebook_image',
				'sanitize' => 'url',
			),
		);
	}
}
