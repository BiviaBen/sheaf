<?php
/**
 * Serialize the import IR into Gutenberg block markup, under a settings object.
 *
 * The cleaning rules live here as data, not branching code: each block type is
 * kept, downgraded, or transformed according to the settings the author chose
 * at upload. This is what makes the allowlist configurable today and a future
 * "style name => semantic span" mapping (settings['style_map']) a drop-in —
 * the IR already carries the originating Word style names from Docx_Reader.
 *
 * Output is always block-delimited (<!-- wp:paragraph --> …) so imported
 * chapters are natively editable in the block editor, and every fragment is
 * passed through wp_kses with a narrow allowlist.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Import_Serializer {

	/**
	 * The default import settings (the conservative allowlist, all on).
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings(): array {
		return [
			'keep_headings'   => true,
			'keep_lists'      => true,
			'keep_emphasis'   => true,
			'keep_blockquote' => true,
			'keep_links'      => true,
			'scene_breaks'    => true,
			// Whether to apply the named Word-style mappings below at all. When
			// off, style_map / block_style_map are ignored (mapping is opt-in).
			'keep_named_styles' => true,
			// Whether to apply the ad-hoc/unnamed (direct-formatting) mapping.
			'keep_unnamed_styles' => true,
			// Word character-style name => CSS class for an inline <span>
			// (an inline style-set style). Applied per run in render_runs().
			'style_map'       => [],
			// Word paragraph-style name => CSS class for a paragraph block
			// (a block style-set style). Applied per block in render_block().
			'block_style_map' => [],
			// Direct-formatting signature => CSS class for an inline <span>.
			// Applied per run (see Import_Serializer::direct_signature()).
			'direct_style_map' => [],
			// Paragraph direct-formatting signature => CSS class for a paragraph
			// block. Applied per block in render_block().
			'direct_block_map' => [],
		];
	}

	/**
	 * Coerce a raw settings array (e.g. from a form) into a full, typed set.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( array $raw ): array {
		$defaults = self::default_settings();
		$out      = [];
		foreach ( $defaults as $key => $default ) {
			if ( 'style_map' === $key || 'block_style_map' === $key || 'direct_style_map' === $key || 'direct_block_map' === $key ) {
				$out[ $key ] = is_array( $raw[ $key ] ?? null ) ? array_map( 'sanitize_html_class', $raw[ $key ] ) : [];
				continue;
			}
			$out[ $key ] = ! empty( $raw[ $key ] );
		}
		return $out;
	}

	/**
	 * Render IR blocks to block-delimited HTML.
	 *
	 * @param array<int,array<string,mixed>> $blocks   IR from Docx_Reader.
	 * @param array<string,mixed>            $settings Sanitized settings.
	 */
	public static function to_blocks( array $blocks, array $settings ): string {
		$settings = self::sanitize_settings( $settings );
		$out      = [];

		foreach ( $blocks as $block ) {
			$markup = self::render_block( $block, $settings );
			if ( '' !== $markup ) {
				$out[] = $markup;
			}
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Plain-text excerpt of IR blocks, for previews and titles.
	 */
	public static function to_text( array $blocks, int $word_limit = 0 ): string {
		$parts = [];
		foreach ( $blocks as $block ) {
			if ( isset( $block['runs'] ) ) {
				$parts[] = self::runs_text( $block['runs'] );
			} elseif ( isset( $block['items'] ) ) {
				foreach ( $block['items'] as $item ) {
					$parts[] = self::runs_text( $item );
				}
			}
		}
		$text = trim( preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );

		if ( $word_limit > 0 ) {
			$text = wp_trim_words( $text, $word_limit, '…' );
		}
		return $text;
	}

	private static function render_block( array $block, array $settings ): string {
		switch ( $block['type'] ) {
			case 'separator':
				return $settings['scene_breaks']
					? "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->"
					: '';

			case 'heading':
				$inline = self::render_runs( $block['runs'], $settings );
				if ( '' === trim( wp_strip_all_tags( $inline ) ) ) {
					return '';
				}
				if ( ! $settings['keep_headings'] ) {
					return self::wrap_paragraph( $inline );
				}
				$level = max( 2, min( 6, (int) ( $block['level'] ?? 2 ) ) );
				return sprintf(
					"<!-- wp:heading {\"level\":%1\$d} -->\n<h%1\$d class=\"wp-block-heading\">%2\$s</h%1\$d>\n<!-- /wp:heading -->",
					$level,
					$inline
				);

			case 'quote':
				$inline = self::render_runs( $block['runs'], $settings );
				if ( '' === trim( wp_strip_all_tags( $inline ) ) ) {
					return '';
				}
				if ( ! $settings['keep_blockquote'] ) {
					return self::wrap_paragraph( $inline );
				}
				return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>"
					. $inline
					. "</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->";

			case 'list':
				return self::render_list( $block, $settings );

			case 'paragraph':
			default:
				$inline = self::render_runs( $block['runs'] ?? [], $settings );
				if ( '' === trim( wp_strip_all_tags( $inline ) ) ) {
					return '';
				}
				// A mapped Word paragraph style becomes a paragraph block-style
				// class (e.g. "is-style-sheaf-…"), when named-style mapping is on.
				// Failing that, a mapped paragraph direct-formatting signature does
				// (unnamed/ad-hoc mapping). Named takes precedence.
				$class = ! empty( $settings['keep_named_styles'] )
					? (string) ( $settings['block_style_map'][ $block['style'] ?? '' ] ?? '' )
					: '';
				if ( '' === $class && ! empty( $settings['keep_unnamed_styles'] ) ) {
					$signature = self::direct_signature( (array) ( $block['direct'] ?? [] ) );
					if ( '' !== $signature ) {
						$class = (string) ( $settings['direct_block_map'][ $signature ] ?? '' );
					}
				}
				return self::wrap_paragraph( $inline, $class );
		}
	}

	private static function wrap_paragraph( string $inline, string $class = '' ): string {
		if ( '' === $class ) {
			return "<!-- wp:paragraph -->\n<p>" . $inline . "</p>\n<!-- /wp:paragraph -->";
		}
		$attrs = (string) wp_json_encode( [ 'className' => $class ] );
		return "<!-- wp:paragraph {$attrs} -->\n<p class=\"" . esc_attr( $class ) . "\">" . $inline . "</p>\n<!-- /wp:paragraph -->";
	}

	private static function render_list( array $block, array $settings ): string {
		$items = [];
		foreach ( $block['items'] as $item_runs ) {
			$inline = self::render_runs( $item_runs, $settings );
			if ( '' !== trim( wp_strip_all_tags( $inline ) ) ) {
				$items[] = $inline;
			}
		}
		if ( ! $items ) {
			return '';
		}

		// Lists off: flatten each item into its own paragraph.
		if ( ! $settings['keep_lists'] ) {
			return implode( "\n\n", array_map( [ self::class, 'wrap_paragraph' ], $items ) );
		}

		$ordered = ! empty( $block['ordered'] );
		$tag     = $ordered ? 'ol' : 'ul';
		$li      = '';
		foreach ( $items as $inline ) {
			$li .= "<!-- wp:list-item -->\n<li>" . $inline . "</li>\n<!-- /wp:list-item -->\n";
		}
		$attr = $ordered ? ' {"ordered":true}' : '';
		return "<!-- wp:list{$attr} -->\n<{$tag} class=\"wp-block-list\">\n" . $li . "</{$tag}>\n<!-- /wp:list -->";
	}

	/**
	 * A stable, canonical signature for a run's direct (unnamed) formatting, so
	 * runs with the same ad-hoc font/size/colour cluster together. Empty for a
	 * plain run.
	 *
	 * @param array<string,string> $direct
	 */
	public static function direct_signature( array $direct ): string {
		ksort( $direct );
		$parts = [];
		foreach ( $direct as $prop => $value ) {
			if ( '' !== (string) $value ) {
				$parts[] = $prop . ':' . $value;
			}
		}
		return implode( ';', $parts );
	}

	/**
	 * Render a set of runs to safe inline HTML, applying emphasis/links/spans
	 * per the settings. Adjacent runs are emitted independently; the editor
	 * tidies redundant tags on first edit.
	 *
	 * @param array<int,array<string,mixed>> $runs
	 */
	private static function render_runs( array $runs, array $settings ): string {
		$html = '';
		foreach ( $runs as $run ) {
			$text = (string) $run['text'];
			if ( '' === $text ) {
				continue;
			}

			// Preserve intentional line breaks inside a run as <br>.
			$piece = implode( '<br>', array_map( 'esc_html', explode( "\n", $text ) ) );

			// A mapped Word character style becomes a semantic span (named-style
			// mapping). Failing that, a mapped direct-formatting signature does
			// (unnamed/ad-hoc mapping). Named takes precedence.
			$class = ! empty( $settings['keep_named_styles'] ) ? ( $settings['style_map'][ $run['style'] ] ?? '' ) : '';
			if ( '' === $class && ! empty( $settings['keep_unnamed_styles'] ) ) {
				$signature = self::direct_signature( (array) ( $run['direct'] ?? [] ) );
				if ( '' !== $signature ) {
					$class = (string) ( $settings['direct_style_map'][ $signature ] ?? '' );
				}
			}
			if ( '' !== $class ) {
				$piece = '<span class="' . esc_attr( $class ) . '">' . $piece . '</span>';
			}

			if ( $settings['keep_emphasis'] ) {
				if ( ! empty( $run['bold'] ) ) {
					$piece = '<strong>' . $piece . '</strong>';
				}
				if ( ! empty( $run['italic'] ) ) {
					$piece = '<em>' . $piece . '</em>';
				}
				if ( ! empty( $run['underline'] ) && empty( $run['href'] ) ) {
					$piece = '<u>' . $piece . '</u>';
				}
			}

			if ( $settings['keep_links'] && ! empty( $run['href'] ) ) {
				$href = esc_url( $run['href'] );
				if ( '' !== $href ) {
					$piece = '<a href="' . $href . '">' . $piece . '</a>';
				}
			}

			$html .= $piece;
		}

		return self::kses( $html );
	}

	/**
	 * Restrict inline markup to the small set the importer can emit.
	 */
	private static function kses( string $html ): string {
		return wp_kses(
			$html,
			[
				'strong' => [],
				'em'     => [],
				'u'      => [],
				'br'     => [],
				'a'      => [ 'href' => true ],
				'span'   => [ 'class' => true ],
			]
		);
	}

	private static function runs_text( array $runs ): string {
		$text = '';
		foreach ( $runs as $run ) {
			$text .= str_replace( "\n", ' ', (string) $run['text'] );
		}
		return $text;
	}
}
