<?php
/**
 * Picks the single SEO adapter to use for a given site.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checked in priority order (Yoast, Rank Math, AIOSEO, Fallback); the first
 * one whose is_active() returns true wins. Running two SEO plugins active
 * at once (e.g. Yoast + Rank Math) is itself a broken, unsupported
 * WordPress configuration, so this deliberately never writes to more than
 * one adapter - there is no "correct" way to reconcile two competing SEO
 * plugins on the publisher's site.
 */
class Resolver {

	/**
	 * @var AdapterInterface[]
	 */
	private array $adapters;

	/**
	 * @param AdapterInterface[]|null $adapters Adapter list, checked in
	 *                                          priority order. Defaults to
	 *                                          the production Yoast / Rank
	 *                                          Math / AIOSEO / Fallback
	 *                                          stack; overridable for tests.
	 */
	public function __construct( ?array $adapters = null ) {
		$this->adapters = $adapters ?? array(
			new YoastAdapter(),
			new RankMathAdapter(),
			new AioseoAdapter(),
			new FallbackAdapter(),
		);
	}

	public function resolve(): AdapterInterface {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}

		// Unreachable in practice: FallbackAdapter::is_active() always
		// returns true, so the loop above always returns before this - but
		// a caller-supplied adapter list (e.g. in tests) might omit it.
		$last = end( $this->adapters );

		if ( false === $last ) {
			throw new \LogicException( 'Resolver was constructed with an empty adapter list.' );
		}

		return $last;
	}

	/**
	 * True when a real, supported SEO plugin (Yoast, Rank Math, or AIOSEO)
	 * is active - false when {@see resolve()} would fall back to
	 * {@see FallbackAdapter}, meaning nothing else generates a sitemap entry
	 * or structured data for this content, only this plugin's own basic
	 * meta tags. Used by the Registration page to warn publishers running
	 * no supported SEO plugin.
	 */
	public function has_recommended_plugin(): bool {
		return ! ( $this->resolve() instanceof FallbackAdapter );
	}
}
