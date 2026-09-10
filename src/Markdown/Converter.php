<?php
/**
 * Minimal, dependency-free Markdown-to-HTML converter.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This is intentionally NOT a full CommonMark implementation - no vendored
 * third-party library ships with this plugin (see README.md for the
 * rationale). It supports the subset of Markdown flytedesk actually sends
 * for article bodies:
 *
 *   - ATX headings (# through ######)
 *   - paragraphs
 *   - bold (**text** / __text__), italic (*text* / _text_), strikethrough (~~text~~)
 *   - inline code (`code`) and fenced code blocks (```)
 *   - links ([text](url)) and images (![alt](url))
 *   - blockquotes (>)
 *   - unordered lists (-, *, +) and ordered lists (1.)
 *   - horizontal rules (---, ***, ___)
 *
 * Output is always re-sanitized with wp_kses_post() by Rest\Controller
 * before it is stored, so this class is a formatting layer only - it is not
 * relied on as the sole line of defense against malicious markup.
 */
class Converter {

	/**
	 * Convert a Markdown string to HTML.
	 */
	public static function to_html( string $markdown ): string {
		if ( '' === trim( $markdown ) ) {
			return '';
		}

		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );

		// Pull fenced code blocks out first so nothing inside them is ever
		// touched by inline/list/heading processing.
		$blocks   = array();
		$markdown = preg_replace_callback(
			'/```([a-zA-Z0-9_-]*)\n(.*?)\n```/s',
			static function ( array $matches ) use ( &$blocks ): string {
				$placeholder = "\x02FDBLOCK" . count( $blocks ) . "\x03";
				$lang        = trim( $matches[1] );
				$code        = htmlspecialchars( $matches[2], ENT_NOQUOTES, 'UTF-8' );
				$class       = '' !== $lang ? ' class="language-' . esc_attr( $lang ) . '"' : '';

				$blocks[ $placeholder ] = '<pre><code' . $class . '>' . $code . '</code></pre>';

				return $placeholder;
			},
			$markdown
		);

		$html = self::block_html( (string) $markdown );

		if ( ! empty( $blocks ) ) {
			$html = strtr( $html, $blocks );
		}

		return $html;
	}

	/**
	 * Parse block-level structure (headings, paragraphs, lists, blockquotes,
	 * horizontal rules) out of a chunk of markdown/placeholder text.
	 */
	private static function block_html( string $markdown ): string {
		$lines = explode( "\n", $markdown );

		$html        = array();
		$paragraph   = array();
		$list_type   = null; // Tracks whether the open list is unordered or ordered.
		$list_items  = array();
		$quote_lines = array();

		$flush_paragraph = static function () use ( &$paragraph, &$html ): void {
			if ( empty( $paragraph ) ) {
				return;
			}

			$text = trim( implode( ' ', $paragraph ) );

			if ( '' !== $text ) {
				$html[] = '<p>' . self::inline( $text ) . '</p>';
			}

			$paragraph = array();
		};

		$flush_list = static function () use ( &$list_type, &$list_items, &$html ): void {
			if ( null === $list_type ) {
				return;
			}

			$items = array_map(
				static function ( string $item ): string {
					return '<li>' . self::inline( $item ) . '</li>';
				},
				$list_items
			);

			$html[] = '<' . $list_type . '>' . implode( '', $items ) . '</' . $list_type . '>';

			$list_type  = null;
			$list_items = array();
		};

		$flush_quote = static function () use ( &$quote_lines, &$html ): void {
			if ( empty( $quote_lines ) ) {
				return;
			}

			$html[]      = '<blockquote>' . self::block_html( implode( "\n", $quote_lines ) ) . '</blockquote>';
			$quote_lines = array();
		};

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// Fenced-code-block placeholder: pass through untouched.
			if ( preg_match( '/^\x02FDBLOCK\d+\x03$/', $trimmed ) ) {
				$flush_paragraph();
				$flush_list();
				$flush_quote();
				$html[] = $trimmed;
				continue;
			}

			if ( '' === $trimmed ) {
				$flush_paragraph();
				$flush_list();
				$flush_quote();
				continue;
			}

			// Horizontal rule: three or more of the same -, *, or _ (optionally spaced).
			if ( preg_match( '/^(?:([-*_])\s*){3,}$/', $trimmed ) ) {
				$flush_paragraph();
				$flush_list();
				$flush_quote();
				$html[] = '<hr />';
				continue;
			}

			// ATX heading.
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $m ) ) {
				$flush_paragraph();
				$flush_list();
				$flush_quote();
				$level  = strlen( $m[1] );
				$html[] = '<h' . $level . '>' . self::inline( trim( $m[2] ) ) . '</h' . $level . '>';
				continue;
			}

			// Blockquote line.
			if ( preg_match( '/^>\s?(.*)$/', $line, $m ) ) {
				$flush_paragraph();
				$flush_list();
				$quote_lines[] = $m[1];
				continue;
			}

			if ( ! empty( $quote_lines ) ) {
				$flush_quote();
			}

			// Unordered list item.
			if ( preg_match( '/^[-*+]\s+(.*)$/', $line, $m ) ) {
				$flush_paragraph();
				if ( 'ul' !== $list_type ) {
					$flush_list();
					$list_type = 'ul';
				}
				$list_items[] = $m[1];
				continue;
			}

			// Ordered list item.
			if ( preg_match( '/^\d+\.\s+(.*)$/', $line, $m ) ) {
				$flush_paragraph();
				if ( 'ol' !== $list_type ) {
					$flush_list();
					$list_type = 'ol';
				}
				$list_items[] = $m[1];
				continue;
			}

			if ( null !== $list_type ) {
				$flush_list();
			}

			$paragraph[] = $trimmed;
		}

		$flush_paragraph();
		$flush_list();
		$flush_quote();

		return implode( "\n", $html );
	}

	/**
	 * Apply inline formatting (bold, italic, strikethrough, code, links,
	 * images) within a single block of text.
	 */
	private static function inline( string $text ): string {
		// Escape raw HTML first so arbitrary markup in the source markdown
		// cannot ride through untouched; the tags we add back in below are
		// all ones we construct ourselves. wp_kses_post() re-sanitizes the
		// final output regardless.
		$text = htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' );

		// Inline code.
		$text = preg_replace_callback(
			'/`([^`]+)`/',
			static function ( array $m ): string {
				return '<code>' . $m[1] . '</code>';
			},
			$text
		);

		// Markdown image syntax: exclamation point, bracketed alt text, parenthesized URL with an optional quoted title.
		$text = preg_replace_callback(
			'/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
			static function ( array $m ): string {
				$url = self::resolve_url( $m[2] );
				if ( null === $url ) {
					return '';
				}
				$title = isset( $m[3] ) ? ' title="' . esc_attr( $m[3] ) . '"' : '';
				return '<img src="' . esc_attr( $url ) . '" alt="' . esc_attr( $m[1] ) . '"' . $title . ' />';
			},
			$text
		);

		// Markdown link syntax: bracketed link text, parenthesized URL with an optional quoted title.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
			static function ( array $m ): string {
				$url = self::resolve_url( $m[2] );
				if ( null === $url ) {
					return $m[1];
				}
				$title = isset( $m[3] ) ? ' title="' . esc_attr( $m[3] ) . '"' : '';
				return '<a href="' . esc_attr( $url ) . '"' . $title . ' rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$text
		);

		// Bold.
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__(.+?)__/s', '<strong>$1</strong>', $text );

		// Italic (avoid matching inside words for the underscore form).
		$text = preg_replace( '/\*(.+?)\*/s', '<em>$1</em>', $text );
		$text = preg_replace( '/(?<![a-zA-Z0-9])_(.+?)_(?![a-zA-Z0-9])/s', '<em>$1</em>', $text );

		// Strikethrough.
		$text = preg_replace( '/~~(.+?)~~/s', '<del>$1</del>', $text );

		return $text;
	}

	/**
	 * Validate and normalize a URL captured from markdown link/image syntax.
	 * Returns null for anything that isn't a safe http(s)/mailto/relative URL.
	 */
	private static function resolve_url( string $raw ): ?string {
		$url = esc_url_raw( html_entity_decode( $raw, ENT_QUOTES ) );

		if ( '' === $url ) {
			return null;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		// No scheme: treat as a relative URL, which esc_url_raw() already sanitized.
		if ( empty( $scheme ) ) {
			return $url;
		}

		if ( ! in_array( strtolower( $scheme ), array( 'http', 'https', 'mailto' ), true ) ) {
			return null;
		}

		return $url;
	}
}
