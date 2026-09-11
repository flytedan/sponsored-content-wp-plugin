<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Unit\Seo;

use Flytedesk\SponsoredContent\Seo\AdapterInterface;
use Flytedesk\SponsoredContent\Seo\FallbackAdapter;
use Flytedesk\SponsoredContent\Seo\Resolver;
use Flytedesk\SponsoredContent\Tests\Unit\BrainMonkeyTestCase;

final class ResolverTest extends BrainMonkeyTestCase {

	public function test_resolves_first_active_adapter_in_priority_order(): void {
		$inactive      = $this->fake_adapter( false );
		$active        = $this->fake_adapter( true );
		$never_reached = $this->fake_adapter( true );

		$resolver = new Resolver( array( $inactive, $active, $never_reached ) );

		$this->assertSame( $active, $resolver->resolve() );
	}

	public function test_skips_multiple_inactive_adapters(): void {
		$one    = $this->fake_adapter( false );
		$two    = $this->fake_adapter( false );
		$active = $this->fake_adapter( true );

		$resolver = new Resolver( array( $one, $two, $active ) );

		$this->assertSame( $active, $resolver->resolve() );
	}

	public function test_never_writes_to_more_than_one_adapter(): void {
		// Two adapters both report active (e.g. Yoast + Rank Math both
		// installed) - the resolver must still return exactly one, proving
		// the caller can never end up writing SEO fields to both.
		$first  = $this->fake_adapter( true );
		$second = $this->fake_adapter( true );

		$resolver = new Resolver( array( $first, $second ) );

		$resolved = $resolver->resolve();

		$this->assertSame( $first, $resolved );
		$this->assertNotSame( $second, $resolved );
	}

	public function test_throws_when_constructed_with_no_adapters(): void {
		$resolver = new Resolver( array() );

		$this->expectException( \LogicException::class );

		$resolver->resolve();
	}

	public function test_has_recommended_plugin_is_true_when_a_real_adapter_resolves(): void {
		$resolver = new Resolver( array( $this->fake_adapter( true ) ) );

		$this->assertTrue( $resolver->has_recommended_plugin() );
	}

	public function test_has_recommended_plugin_is_false_when_only_the_fallback_adapter_resolves(): void {
		$resolver = new Resolver( array( new FallbackAdapter() ) );

		$this->assertFalse( $resolver->has_recommended_plugin() );
	}

	private function fake_adapter( bool $active ): AdapterInterface {
		return new class( $active ) implements AdapterInterface {
			public function __construct( private readonly bool $active ) {}

			public function is_active(): bool {
				return $this->active;
			}

			public function write( int $post_id, array $seo ): void {}

			public function read( int $post_id ): array {
				return array();
			}
		};
	}
}
