<?php
/**
 * Contract every SEO plugin adapter must implement.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each adapter knows how to detect whether its target SEO plugin is active,
 * and how to write/read the small set of SEO fields flytedesk cares about
 * using that plugin's own meta keys. {@see Resolver} picks exactly one
 * active adapter per request; no adapter is ever expected to know about any
 * other adapter.
 */
interface AdapterInterface {

	/**
	 * Whether this adapter's target SEO plugin is active on this site.
	 */
	public function is_active(): bool;

	/**
	 * Write flytedesk-supplied SEO fields onto the given post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $seo     {
	 *     @type string $meta_title       SEO title.
	 *     @type string $meta_description SEO meta description.
	 *     @type string $slug             Requested slug (informational only - the
	 *                                    REST controller owns actually assigning it).
	 *     @type string $meta_keywords    Comma-separated focus keyword(s).
	 *     @type array  $og               {
	 *         @type string $title       Open Graph title.
	 *         @type string $description Open Graph description.
	 *         @type string $image       Open Graph image URL.
	 *         @type string $type        Open Graph type (e.g. "article").
	 *     }
	 * }
	 */
	public function write( int $post_id, array $seo ): void;

	/**
	 * Read the currently stored SEO fields for the given post, in the same
	 * shape accepted by write().
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function read( int $post_id ): array;
}
