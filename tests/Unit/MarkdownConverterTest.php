<?php
/**
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Tests\Unit;

use Brain\Monkey\Functions;
use Flytedesk\SponsoredContent\Markdown\Converter;

/**
 * The converter calls a handful of WordPress escaping/URL helpers
 * (esc_attr(), esc_url_raw(), wp_parse_url()); Brain Monkey stubs each one
 * with a behaviourally-equivalent closure so the tests exercise real
 * Markdown parsing logic without needing WordPress loaded.
 */
final class MarkdownConverterTest extends BrainMonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'esc_attr' )->alias(
			static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'esc_url_raw' )->alias( static fn( string $url ): string => trim( $url ) );
		// This closure IS the wp_parse_url() stub, so each branch below must call the native parse_url() it stands in for.
		Functions\when( 'wp_parse_url' )->alias(
			/**
			 * @return string|int|array|null|false
			 */
			static function ( string $url, ?int $component = -1 ) {
				if ( -1 === $component ) {
					return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				}

				return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			}
		);
	}

	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', Converter::to_html( '' ) );
		$this->assertSame( '', Converter::to_html( "   \n\t" ) );
	}

	public function test_converts_atx_headings(): void {
		$html = Converter::to_html( "# Heading 1\n\n## Heading 2" );

		$this->assertSame( "<h1>Heading 1</h1>\n<h2>Heading 2</h2>", $html );
	}

	public function test_converts_paragraphs(): void {
		$html = Converter::to_html( "First paragraph.\n\nSecond paragraph." );

		$this->assertSame( "<p>First paragraph.</p>\n<p>Second paragraph.</p>", $html );
	}

	public function test_converts_bold_italic_and_strikethrough(): void {
		$html = Converter::to_html( '**bold** and _italic_ and ~~gone~~' );

		$this->assertStringContainsString( '<strong>bold</strong>', $html );
		$this->assertStringContainsString( '<em>italic</em>', $html );
		$this->assertStringContainsString( '<del>gone</del>', $html );
	}

	public function test_converts_inline_code_and_fenced_code_block(): void {
		$html = Converter::to_html( "Use `wp_kses_post()`.\n\n```php\necho 'hi';\n```" );

		$this->assertStringContainsString( '<code>wp_kses_post()</code>', $html );
		$this->assertStringContainsString( '<pre><code class="language-php">', $html );
		// Fenced code blocks are escaped with ENT_NOQUOTES (only <, >, & are
		// converted) so source quoting is preserved verbatim for readability.
		$this->assertStringContainsString( "echo 'hi';", $html );
	}

	public function test_converts_links_with_safe_scheme(): void {
		$html = Converter::to_html( '[flytedesk](https://flytedesk.com "Homepage")' );

		$this->assertStringContainsString( '<a href="https://flytedesk.com" title="Homepage" rel="noopener noreferrer">flytedesk</a>', $html );
	}

	public function test_strips_unsafe_link_scheme(): void {
		$html = Converter::to_html( '[click me](javascript:alert(1))' );

		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( 'click me', $html );
	}

	public function test_converts_images(): void {
		$html = Converter::to_html( '![alt text](https://cdn.example.com/img.jpg)' );

		$this->assertStringContainsString( '<img src="https://cdn.example.com/img.jpg" alt="alt text" />', $html );
	}

	public function test_converts_unordered_and_ordered_lists(): void {
		$ul = Converter::to_html( "- one\n- two\n- three" );
		$ol = Converter::to_html( "1. one\n2. two" );

		$this->assertSame( '<ul><li>one</li><li>two</li><li>three</li></ul>', $ul );
		$this->assertSame( '<ol><li>one</li><li>two</li></ol>', $ol );
	}

	public function test_converts_blockquote(): void {
		$html = Converter::to_html( '> quoted text' );

		$this->assertSame( '<blockquote><p>quoted text</p></blockquote>', $html );
	}

	public function test_converts_horizontal_rule(): void {
		$this->assertSame( '<hr />', Converter::to_html( '---' ) );
		$this->assertSame( '<hr />', Converter::to_html( '***' ) );
	}

	public function test_escapes_raw_html_in_source(): void {
		$html = Converter::to_html( '<script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
