<?php
/**
 * Shared read/write plumbing for the post-meta-backed SEO adapters.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast, Rank Math, AIOSEO, and the Fallback adapter all do the same thing:
 * map flytedesk's fixed SEO field set onto a handful of `post_meta` keys
 * owned by whichever SEO plugin (or, for Fallback, the plugin itself) is in
 * play, sanitizing each value on the way in. Only the meta key names (and,
 * for Yoast's focus keyword, a value transform) differ between plugins.
 *
 * Concrete adapters implement {@see field_map()} to declare that mapping;
 * this class handles the actual read()/write() traversal so the mapping
 * logic is never duplicated per adapter.
 */
abstract class AbstractMetaAdapter implements AdapterInterface {

	/**
	 * Sanitizer callbacks keyed by the short name used in field_map().
	 *
	 * @var array<string, callable(string): string>
	 */
	private const SANITIZERS = array(
		'text' => 'sanitize_text_field',
		'url'  => 'esc_url_raw',
	);

	/**
	 * Declares which SEO fields this adapter persists and where.
	 *
	 * Keys are dot-paths into the SEO field set: `meta_title`,
	 * `meta_description`, `meta_keywords`, `og.title`, `og.description`,
	 * `og.image`, `og.type`. A field the target plugin has no equivalent
	 * for is simply omitted - {@see read()} still returns it (as an empty
	 * string) so every adapter's read() shape matches
	 * {@see AdapterInterface::write()}'s documented input shape.
	 *
	 * @return array<string, array{key: string, sanitize: string, transform?: callable(string): string}>
	 */
	abstract protected function field_map(): array;

	public function write( int $post_id, array $seo ): void {
		$flat = self::flatten( $seo );

		foreach ( $this->field_map() as $field => $mapping ) {
			$value = $flat[ $field ] ?? '';

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			if ( isset( $mapping['transform'] ) ) {
				$value = ( $mapping['transform'] )( $value );
			}

			$sanitizer = self::SANITIZERS[ $mapping['sanitize'] ];
			update_post_meta( $post_id, $mapping['key'], $sanitizer( $value ) );
		}
	}

	public function read( int $post_id ): array {
		$flat = array(
			'meta_title'       => '',
			'meta_description' => '',
			'meta_keywords'    => '',
			'og.title'         => '',
			'og.description'   => '',
			'og.image'         => '',
			'og.type'          => '',
		);

		foreach ( $this->field_map() as $field => $mapping ) {
			$flat[ $field ] = (string) get_post_meta( $post_id, $mapping['key'], true );
		}

		return array(
			'meta_title'       => $flat['meta_title'],
			'meta_description' => $flat['meta_description'],
			'slug'             => '',
			'meta_keywords'    => $flat['meta_keywords'],
			'og'               => array(
				'title'       => $flat['og.title'],
				'description' => $flat['og.description'],
				'image'       => $flat['og.image'],
				'type'        => $flat['og.type'],
			),
		);
	}

	/**
	 * Flatten the nested SEO field shape (with its `og` sub-array) into a
	 * dot-keyed array matching {@see field_map()}'s key space.
	 *
	 * @param array $seo SEO fields in the shape documented on
	 *                   {@see AdapterInterface::write()}.
	 * @return array<string, string>
	 */
	private static function flatten( array $seo ): array {
		$og = isset( $seo['og'] ) && is_array( $seo['og'] ) ? $seo['og'] : array();

		return array(
			'meta_title'       => isset( $seo['meta_title'] ) ? (string) $seo['meta_title'] : '',
			'meta_description' => isset( $seo['meta_description'] ) ? (string) $seo['meta_description'] : '',
			'meta_keywords'    => isset( $seo['meta_keywords'] ) ? (string) $seo['meta_keywords'] : '',
			'og.title'         => isset( $og['title'] ) ? (string) $og['title'] : '',
			'og.description'   => isset( $og['description'] ) ? (string) $og['description'] : '',
			'og.image'         => isset( $og['image'] ) ? (string) $og['image'] : '',
			'og.type'          => isset( $og['type'] ) ? (string) $og['type'] : '',
		);
	}
}
