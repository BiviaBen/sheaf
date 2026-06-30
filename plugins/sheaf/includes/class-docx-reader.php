<?php
/**
 * Read a .docx file into a neutral intermediate representation (IR).
 *
 * A .docx is a ZIP of WordprocessingML XML, which is semantic and free of the
 * mso-* inline-style clutter that Word's HTML export produces — so we choose
 * exactly what to keep. The reader walks word/document.xml into an array of
 * block nodes (paragraph / heading / list / quote / separator), each holding
 * inline "runs" with marks (bold/italic/underline/link). It also records the
 * originating Word *style name* on blocks and runs: unused today, but the hook
 * a future "style → semantic span" mapping needs (e.g. a "Telepathy" character
 * style becoming <span class="voice_telepathy">). See Import_Serializer.
 *
 * IR shape:
 *   block = [
 *     'type'    => 'paragraph'|'heading'|'list'|'quote'|'separator',
 *     'level'   => int,    // heading only (1-6)
 *     'ordered' => bool,   // list only
 *     'style'   => string, // originating Word paragraph-style name
 *     'direct'  => array,  // ad-hoc paragraph formatting (align/indent/spacing)
 *     'runs'    => run[],   // paragraph/heading/quote
 *     'items'   => run[][], // list: one run-array per item
 *   ]
 *   run = [ 'text'=>string, 'bold'=>bool, 'italic'=>bool, 'underline'=>bool,
 *           'href'=>string, 'style'=>string, 'direct'=>array ]
 *     'direct' holds ad-hoc character formatting (font/size/colour/highlight)
 *     applied inline rather than via a named style — the basis for clustering
 *     and mapping "unnamed" styles on import.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Docx_Reader {

	private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/** Guard against zip bombs: refuse documents with absurd entry counts. */
	private const MAX_ENTRIES = 5000;

	/** Hyperlink relationship id => target URL. */
	private array $relationships = [];

	/** numId => true when that list is a bullet (unordered) list. */
	private array $bullet_lists = [];

	private int $image_count = 0;
	private int $table_count = 0;
	private string $title     = '';

	/**
	 * Read a .docx file at $path into the IR.
	 *
	 * @param string $path Absolute path to the .docx file.
	 * @return array{title:string,blocks:array,images:int,tables:int}
	 * @throws \RuntimeException When the file cannot be read or parsed.
	 */
	public static function read( string $path ): array {
		return ( new self() )->parse( $path );
	}

	private function parse( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( __( 'PHP ZipArchive is not available, so .docx files cannot be read on this server.', 'sheaf' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new \RuntimeException( __( 'The file could not be opened. Is it a valid .docx Word document?', 'sheaf' ) );
		}
		if ( $zip->numFiles > self::MAX_ENTRIES ) {
			$zip->close();
			throw new \RuntimeException( __( 'The Word document has too many internal parts to import safely.', 'sheaf' ) );
		}

		$document = $zip->getFromName( 'word/document.xml' );
		$rels     = $zip->getFromName( 'word/_rels/document.xml.rels' );
		$numbering = $zip->getFromName( 'word/numbering.xml' );
		$zip->close();

		if ( false === $document ) {
			throw new \RuntimeException( __( 'The file is missing its document body. Is it a valid .docx Word document?', 'sheaf' ) );
		}

		$this->relationships = $this->parse_relationships( (string) $rels );
		$this->bullet_lists  = $this->parse_numbering( (string) $numbering );

		$blocks = $this->parse_document( $document );

		return [
			'title'  => $this->title,
			'blocks' => $blocks,
			'images' => $this->image_count,
			'tables' => $this->table_count,
		];
	}

	/**
	 * Load an XML string into a DOMDocument with network access disabled.
	 */
	private function load_xml( string $xml ): ?\DOMDocument {
		if ( '' === trim( $xml ) ) {
			return null;
		}
		$dom = new \DOMDocument();
		$ok  = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		return $ok ? $dom : null;
	}

	/**
	 * Map hyperlink relationship ids to their external target URLs.
	 *
	 * @return array<string,string>
	 */
	private function parse_relationships( string $xml ): array {
		$dom = $this->load_xml( $xml );
		if ( ! $dom ) {
			return [];
		}
		$map = [];
		foreach ( $dom->getElementsByTagName( 'Relationship' ) as $rel ) {
			if ( 'hyperlink' === substr( (string) $rel->getAttribute( 'Type' ), -9 ) ) {
				$map[ $rel->getAttribute( 'Id' ) ] = (string) $rel->getAttribute( 'Target' );
			}
		}
		return $map;
	}

	/**
	 * Determine which numbering definitions are bullet (unordered) lists.
	 *
	 * numId -> num -> abstractNumId -> abstractNum -> lvl[0]/numFmt. A numFmt of
	 * "bullet" means an unordered list; anything else we treat as ordered.
	 *
	 * @return array<string,bool> numId => is-bullet
	 */
	private function parse_numbering( string $xml ): array {
		$dom = $this->load_xml( $xml );
		if ( ! $dom ) {
			return [];
		}
		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'w', self::NS_W );

		// abstractNumId => is-bullet (look at level 0's numFmt).
		$abstract = [];
		foreach ( $xpath->query( '//w:abstractNum' ) as $node ) {
			$id = $this->attr( $xpath, $node, '@w:abstractNumId' );
			$fmt = $this->attr( $xpath, $node, 'w:lvl[@w:ilvl="0"]/w:numFmt/@w:val' );
			if ( '' === $fmt ) {
				$fmt = $this->attr( $xpath, $node, 'w:lvl/w:numFmt/@w:val' );
			}
			$abstract[ $id ] = ( 'bullet' === $fmt );
		}

		// numId => is-bullet, via its abstractNumId.
		$map = [];
		foreach ( $xpath->query( '//w:num' ) as $node ) {
			$num_id      = $this->attr( $xpath, $node, '@w:numId' );
			$abstract_id = $this->attr( $xpath, $node, 'w:abstractNumId/@w:val' );
			$map[ $num_id ] = $abstract[ $abstract_id ] ?? false;
		}
		return $map;
	}

	/**
	 * Walk the document body into IR blocks.
	 */
	private function parse_document( string $xml ): array {
		$dom = $this->load_xml( $xml );
		if ( ! $dom ) {
			throw new \RuntimeException( __( 'The Word document could not be parsed.', 'sheaf' ) );
		}
		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'w', self::NS_W );
		$xpath->registerNamespace( 'r', self::NS_R );

		$body = $xpath->query( '/w:document/w:body' )->item( 0 );
		if ( ! $body ) {
			return [];
		}

		$blocks      = [];
		$list_buffer = null; // Accumulates consecutive list items into one block.

		foreach ( $body->childNodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$local = $node->localName;

			if ( 'tbl' === $local ) {
				++$this->table_count;
				$blocks      = $this->flush_list( $blocks, $list_buffer );
				$list_buffer = null;
				continue;
			}
			if ( 'p' !== $local ) {
				continue;
			}

			$this->count_media( $xpath, $node );
			$runs = $this->parse_runs( $xpath, $node );

			// A list item: buffer it so consecutive items become one list block.
			$num_id = $this->attr( $xpath, $node, 'w:pPr/w:numPr/w:numId/@w:val' );
			if ( '' !== $num_id ) {
				$ordered = ! ( $this->bullet_lists[ $num_id ] ?? false );
				if ( null === $list_buffer || $list_buffer['ordered'] !== $ordered ) {
					$blocks      = $this->flush_list( $blocks, $list_buffer );
					$list_buffer = [
						'type'    => 'list',
						'ordered' => $ordered,
						'style'   => '',
						'items'   => [],
					];
				}
				$list_buffer['items'][] = $runs;
				continue;
			}

			// Any non-list paragraph ends a run of list items.
			$blocks      = $this->flush_list( $blocks, $list_buffer );
			$list_buffer = null;

			$block = $this->paragraph_block( $xpath, $node, $runs );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}

		$blocks = $this->flush_list( $blocks, $list_buffer );

		return $this->extract_title( $blocks );
	}

	/**
	 * Append a buffered list block to the output, if any.
	 */
	private function flush_list( array $blocks, ?array $list_buffer ): array {
		if ( null !== $list_buffer && ! empty( $list_buffer['items'] ) ) {
			$blocks[] = $list_buffer;
		}
		return $blocks;
	}

	/**
	 * Turn a non-list paragraph into a heading / quote / separator / paragraph
	 * block, or null if it is empty and not a separator.
	 */
	private function paragraph_block( \DOMXPath $xpath, \DOMElement $p, array $runs ): ?array {
		$style = $this->attr( $xpath, $p, 'w:pPr/w:pStyle/@w:val' );
		$text  = trim( $this->runs_text( $runs ) );

		// A separator paragraph: only scene-break glyphs (e.g. "* * *", "#").
		if ( '' !== $text && preg_match( '/^[\s*#~·•—–\-]{1,40}$/u', $text ) && ! preg_match( '/[\p{L}\p{N}]/u', $text ) ) {
			return [ 'type' => 'separator' ];
		}

		if ( '' === $text ) {
			return null; // Drop empty paragraphs (Word emits many).
		}

		$direct = $this->parse_direct_paragraph( $xpath, $p );

		$level = $this->heading_level( $style );
		if ( $level > 0 ) {
			return [
				'type'   => 'heading',
				'level'  => $level,
				'style'  => $style,
				'direct' => $direct,
				'runs'   => $runs,
			];
		}

		if ( $this->is_quote_style( $style ) ) {
			return [
				'type'   => 'quote',
				'style'  => $style,
				'direct' => $direct,
				'runs'   => $runs,
			];
		}

		return [
			'type'   => 'paragraph',
			'style'  => $style,
			'direct' => $direct,
			'runs'   => $runs,
		];
	}

	/**
	 * Heading level (HTML hN) for a Word paragraph style, or 0 if not a heading.
	 * Word "Heading 1" maps to <h2>, since <h1> is the chapter title; "Title"
	 * is treated as a heading too (and becomes the chapter title downstream).
	 */
	private function heading_level( string $style ): int {
		$key = strtolower( str_replace( ' ', '', $style ) );
		if ( 'title' === $key ) {
			return 2;
		}
		if ( preg_match( '/^heading([1-6])$/', $key, $m ) ) {
			return min( 6, (int) $m[1] + 1 );
		}
		return 0;
	}

	private function is_quote_style( string $style ): bool {
		$key = strtolower( str_replace( ' ', '', $style ) );
		return in_array( $key, [ 'quote', 'intensequote', 'blockquote' ], true );
	}

	/**
	 * Use the first heading/title block as the chapter title and remove it from
	 * the body, so the title is not repeated under the post heading.
	 */
	private function extract_title( array $blocks ): array {
		foreach ( $blocks as $i => $block ) {
			if ( 'heading' === $block['type'] ) {
				$this->title = trim( $this->runs_text( $block['runs'] ) );
				unset( $blocks[ $i ] );
				return array_values( $blocks );
			}
			// Only a leading heading counts as the title; stop at real content.
			if ( in_array( $block['type'], [ 'paragraph', 'quote', 'list' ], true ) ) {
				break;
			}
		}
		return $blocks;
	}

	/**
	 * Count images and embedded objects in a paragraph (we skip them, but warn).
	 */
	private function count_media( \DOMXPath $xpath, \DOMElement $p ): void {
		$this->image_count += $xpath->query( './/w:drawing | .//w:pict | .//w:object', $p )->length;
	}

	/**
	 * Extract inline runs from a paragraph, descending into hyperlinks so their
	 * runs carry the link target.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_runs( \DOMXPath $xpath, \DOMElement $p ): array {
		$runs = [];
		foreach ( $p->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			if ( 'hyperlink' === $child->localName ) {
				$rid  = $child->getAttributeNS( self::NS_R, 'id' );
				$href = $this->relationships[ $rid ] ?? '';
				foreach ( $xpath->query( 'w:r', $child ) as $r ) {
					$run = $this->parse_run( $xpath, $r );
					if ( '' !== $run['text'] ) {
						$run['href'] = $href;
						$runs[]      = $run;
					}
				}
			} elseif ( 'r' === $child->localName ) {
				$run = $this->parse_run( $xpath, $child );
				if ( '' !== $run['text'] ) {
					$runs[] = $run;
				}
			}
		}
		return $runs;
	}

	/**
	 * Parse a single run: its text and bold/italic/underline marks + char style.
	 *
	 * @return array<string,mixed>
	 */
	private function parse_run( \DOMXPath $xpath, \DOMElement $r ): array {
		$text = '';
		foreach ( $r->childNodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			switch ( $node->localName ) {
				case 't':
					$text .= $node->textContent;
					break;
				case 'tab':
					$text .= ' ';
					break;
				case 'br':
				case 'cr':
					$text .= "\n";
					break;
			}
		}

		return [
			'text'      => $text,
			'bold'      => $this->has_toggle( $xpath, $r, 'w:rPr/w:b' ),
			'italic'    => $this->has_toggle( $xpath, $r, 'w:rPr/w:i' ),
			'underline' => '' !== $this->attr( $xpath, $r, 'w:rPr/w:u/@w:val' ) && 'none' !== $this->attr( $xpath, $r, 'w:rPr/w:u/@w:val' ),
			'href'      => '',
			'style'     => $this->attr( $xpath, $r, 'w:rPr/w:rStyle/@w:val' ),
			'direct'    => $this->parse_direct( $xpath, $r ),
		];
	}

	/**
	 * Direct (ad-hoc) character formatting on a run — font, size, colour and
	 * highlight applied inline rather than through a named character style. These
	 * are what a "unnamed styles" import clusters and maps. Bold/italic/underline
	 * are left out: they are emphasis, handled separately.
	 *
	 * @return array<string,string> CSS-style props (empty when the run is plain).
	 */
	private function parse_direct( \DOMXPath $xpath, \DOMElement $r ): array {
		$out = [];

		$font = $this->attr( $xpath, $r, 'w:rPr/w:rFonts/@w:ascii' );
		if ( '' === $font ) {
			$font = $this->attr( $xpath, $r, 'w:rPr/w:rFonts/@w:hAnsi' );
		}
		if ( '' !== $font ) {
			$out['font-family'] = $font;
		}

		$sz = $this->attr( $xpath, $r, 'w:rPr/w:sz/@w:val' );
		if ( '' !== $sz && is_numeric( $sz ) ) {
			// Word stores size in half-points.
			$out['font-size'] = rtrim( rtrim( number_format( (float) $sz / 2, 1 ), '0' ), '.' ) . 'pt';
		}

		$color = $this->attr( $xpath, $r, 'w:rPr/w:color/@w:val' );
		if ( '' !== $color && 'auto' !== $color && preg_match( '/^[0-9A-Fa-f]{6}$/', $color ) ) {
			$out['color'] = '#' . strtolower( $color );
		}

		$highlight = $this->attr( $xpath, $r, 'w:rPr/w:highlight/@w:val' );
		if ( '' !== $highlight && 'none' !== $highlight ) {
			$out['background-color'] = $highlight; // A named colour (e.g. "yellow").
		} else {
			$shade = $this->attr( $xpath, $r, 'w:rPr/w:shd/@w:fill' );
			if ( '' !== $shade && 'auto' !== $shade && preg_match( '/^[0-9A-Fa-f]{6}$/', $shade ) ) {
				$out['background-color'] = '#' . strtolower( $shade );
			}
		}

		return $out;
	}

	/**
	 * Direct (ad-hoc) paragraph formatting from w:pPr — alignment, indentation
	 * and spacing applied directly to a paragraph rather than through a named
	 * paragraph style. The block-level counterpart to parse_direct: the basis
	 * for clustering "unnamed" paragraph styles on import (e.g. an academic
	 * bibliography's hanging indent). Word measures are twips (1/20 point).
	 *
	 * @return array<string,string> CSS-style props (empty when the paragraph is plain).
	 */
	private function parse_direct_paragraph( \DOMXPath $xpath, \DOMElement $p ): array {
		$out = [];

		// Alignment: w:jc. Word "both" is full justification; "start"/"end" are
		// the writing-direction-relative forms of left/right.
		$jc  = $this->attr( $xpath, $p, 'w:pPr/w:jc/@w:val' );
		$map = [ 'both' => 'justify', 'center' => 'center', 'right' => 'right', 'end' => 'right', 'left' => 'left', 'start' => 'left' ];
		if ( isset( $map[ $jc ] ) ) {
			$out['text-align'] = $map[ $jc ];
		}

		// Indentation: w:ind. left/start -> margin-left, right/end -> margin-right.
		// A hanging indent is margin-left plus a negative text-indent; firstLine
		// is a positive text-indent.
		$ind = $xpath->query( 'w:pPr/w:ind', $p )->item( 0 );
		if ( $ind instanceof \DOMElement ) {
			$left = $ind->getAttributeNS( self::NS_W, 'left' );
			if ( '' === $left ) {
				$left = $ind->getAttributeNS( self::NS_W, 'start' );
			}
			if ( is_numeric( $left ) ) {
				$out['margin-left'] = $this->twips_pt( $left );
			}

			$right = $ind->getAttributeNS( self::NS_W, 'right' );
			if ( '' === $right ) {
				$right = $ind->getAttributeNS( self::NS_W, 'end' );
			}
			if ( is_numeric( $right ) ) {
				$out['margin-right'] = $this->twips_pt( $right );
			}

			$hanging = $ind->getAttributeNS( self::NS_W, 'hanging' );
			$first   = $ind->getAttributeNS( self::NS_W, 'firstLine' );
			if ( is_numeric( $hanging ) && 0.0 !== (float) $hanging ) {
				$out['text-indent'] = '-' . $this->twips_pt( $hanging );
			} elseif ( is_numeric( $first ) && 0.0 !== (float) $first ) {
				$out['text-indent'] = $this->twips_pt( $first );
			}
		}

		// Spacing: w:spacing. before/after -> margin-top/bottom (kept even at 0,
		// since "0" is a deliberate tight setting). line -> line-height: in "auto"
		// mode it is 240ths of a line (a unitless multiplier); exact/atLeast store
		// twips.
		$spacing = $xpath->query( 'w:pPr/w:spacing', $p )->item( 0 );
		if ( $spacing instanceof \DOMElement ) {
			$before = $spacing->getAttributeNS( self::NS_W, 'before' );
			if ( is_numeric( $before ) ) {
				$out['margin-top'] = $this->twips_pt( $before );
			}
			$after = $spacing->getAttributeNS( self::NS_W, 'after' );
			if ( is_numeric( $after ) ) {
				$out['margin-bottom'] = $this->twips_pt( $after );
			}
			$line = $spacing->getAttributeNS( self::NS_W, 'line' );
			$rule = $spacing->getAttributeNS( self::NS_W, 'lineRule' );
			if ( is_numeric( $line ) && 0.0 !== (float) $line ) {
				if ( 'exact' === $rule || 'atLeast' === $rule ) {
					$out['line-height'] = $this->twips_pt( $line );
				} else {
					$out['line-height'] = rtrim( rtrim( number_format( (float) $line / 240, 2 ), '0' ), '.' );
				}
			}
		}

		return $out;
	}

	/**
	 * Convert a twips measurement (1/20 point) to a CSS "pt" string, trimming
	 * trailing zeros: "720" -> "36pt", "360" -> "18pt", "210" -> "10.5pt".
	 */
	private function twips_pt( string $twips ): string {
		return rtrim( rtrim( number_format( (float) $twips / 20, 1 ), '0' ), '.' ) . 'pt';
	}

	/**
	 * Whether a boolean run property (e.g. <w:b/>) is on. Present-but-no-value
	 * means true; an explicit w:val of 0/false/off means off.
	 */
	private function has_toggle( \DOMXPath $xpath, \DOMElement $r, string $query ): bool {
		$node = $xpath->query( $query, $r )->item( 0 );
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}
		$val = $node->getAttributeNS( self::NS_W, 'val' );
		if ( '' === $val ) {
			return true;
		}
		return ! in_array( strtolower( $val ), [ '0', 'false', 'off' ], true );
	}

	/**
	 * Concatenate the text of a set of runs.
	 *
	 * @param array<int,array<string,mixed>> $runs
	 */
	private function runs_text( array $runs ): string {
		$text = '';
		foreach ( $runs as $run ) {
			$text .= $run['text'];
		}
		return $text;
	}

	/**
	 * First string value of an XPath expression relative to a context node.
	 */
	private function attr( \DOMXPath $xpath, \DOMNode $context, string $query ): string {
		$node = $xpath->query( $query, $context )->item( 0 );
		return $node ? trim( (string) $node->nodeValue ) : '';
	}
}
