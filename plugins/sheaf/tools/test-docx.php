<?php
/**
 * Unit tests for the .docx reader's direct-formatting detection: run-level
 * (Sheaf\Docx_Reader::parse_direct, surfaced as run['direct']) and
 * paragraph-level (parse_direct_paragraph, surfaced as block['direct']).
 * CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-docx.php
 *
 * Builds a tiny real .docx in a temp file, reads it, and inspects the IR.
 *
 * @package Sheaf
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$pass  = 0;
$fail  = 0;
$check = function ( bool $cond, string $label ) use ( &$pass, &$fail ) {
	if ( $cond ) {
		++$pass;
		WP_CLI::log( "  ok   $label" );
	} else {
		++$fail;
		WP_CLI::log( "  FAIL $label" );
	}
};

$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
	. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
	. '<Default Extension="xml" ContentType="application/xml"/>'
	. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
	. '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
	. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
	. '</Relationships>';

$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
	. '<w:p>'
	. '<w:r><w:t xml:space="preserve">plain </w:t></w:r>'
	. '<w:r><w:rPr><w:rFonts w:ascii="Courier New" w:hAnsi="Courier New"/><w:sz w:val="20"/><w:color w:val="00B050"/></w:rPr><w:t>green mono</w:t></w:r>'
	. '</w:p>'
	. '<w:p><w:r><w:rPr><w:highlight w:val="yellow"/></w:rPr><w:t>marked</w:t></w:r></w:p>'
	// A bibliography-style paragraph: justified, hanging indent, tight spacing.
	. '<w:p>'
	. '<w:pPr>'
	. '<w:jc w:val="both"/>'
	. '<w:ind w:left="720" w:hanging="360"/>'
	. '<w:spacing w:before="0" w:after="240" w:line="480" w:lineRule="auto"/>'
	. '</w:pPr>'
	. '<w:r><w:t>biblio entry</w:t></w:r>'
	. '</w:p>'
	. '</w:body></w:document>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
	// A character style that carries a font, size and colour.
	. '<w:style w:type="character" w:styleId="ComputerVoice">'
	. '<w:name w:val="Computer Voice"/>'
	. '<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="20"/><w:color w:val="0070C0"/></w:rPr>'
	. '</w:style>'
	// A paragraph style that carries both layout (pPr) and a font (rPr).
	. '<w:style w:type="paragraph" w:styleId="Bibliography">'
	. '<w:name w:val="Bibliography"/>'
	. '<w:pPr><w:jc w:val="both"/><w:ind w:left="720" w:hanging="360"/></w:pPr>'
	. '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
	. '</w:style>'
	// A table style we should ignore.
	. '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/></w:style>'
	. '</w:styles>';

$tmp = tempnam( sys_get_temp_dir(), 'sheafdocx' );
$zip = new ZipArchive();
$zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( '[Content_Types].xml', $content_types );
$zip->addFromString( '_rels/.rels', $rels );
$zip->addFromString( 'word/document.xml', $document );
$zip->addFromString( 'word/styles.xml', $styles );
$zip->close();

try {
	$ir     = \Sheaf\Docx_Reader::read( $tmp );
	$blocks = $ir['blocks'];

	// Collect runs by text across all blocks.
	$direct = [];
	foreach ( $blocks as $block ) {
		foreach ( (array) ( $block['runs'] ?? [] ) as $run ) {
			$direct[ trim( (string) $run['text'] ) ] = (array) ( $run['direct'] ?? [] );
		}
	}

	$check( 'Courier New' === ( $direct['green mono']['font-family'] ?? '' ), 'reads direct font-family' );
	$check( '10pt' === ( $direct['green mono']['font-size'] ?? '' ), 'reads direct font-size (sz 20 -> 10pt)' );
	$check( '#00b050' === ( $direct['green mono']['color'] ?? '' ), 'reads direct color' );
	$check( [] === ( $direct['plain'] ?? [ 'x' ] ), 'plain run has no direct formatting' );
	$check( 'yellow' === ( $direct['marked']['background-color'] ?? '' ), 'reads highlight as background-color' );

	// Collect paragraph-level direct formatting by text.
	$pdirect = [];
	foreach ( $blocks as $block ) {
		$key = trim( (string) ( $block['runs'][0]['text'] ?? '' ) );
		if ( '' !== $key ) {
			$pdirect[ $key ] = (array) ( $block['direct'] ?? [] );
		}
	}

	$biblio = $pdirect['biblio entry'] ?? [];
	$check( 'justify' === ( $biblio['text-align'] ?? '' ), 'reads paragraph alignment (jc both -> justify)' );
	$check( '36pt' === ( $biblio['margin-left'] ?? '' ), 'reads left indent (720 twips -> 36pt)' );
	$check( '-18pt' === ( $biblio['text-indent'] ?? '' ), 'reads hanging indent (360 twips -> -18pt)' );
	$check( '0pt' === ( $biblio['margin-top'] ?? 'x' ), 'keeps spacing before 0 (margin-top: 0pt)' );
	$check( '12pt' === ( $biblio['margin-bottom'] ?? '' ), 'reads spacing after (240 twips -> 12pt)' );
	$check( '2' === ( $biblio['line-height'] ?? '' ), 'reads line spacing (480/240 auto -> 2)' );
	$check( [] === ( $pdirect['plain'] ?? [ 'x' ] ), 'plain paragraph has no direct formatting' );

	// Style definitions from styles.xml.
	$styles_ir = (array) ( $ir['styles'] ?? [] );
	$cv        = (array) ( $styles_ir['ComputerVoice'] ?? [] );
	$check( 'Computer Voice' === ( $cv['name'] ?? '' ), 'styles: reads character style human name' );
	$check( 'character' === ( $cv['type'] ?? '' ), 'styles: records the character type' );
	$check( 'Consolas' === ( $cv['props']['font-family'] ?? '' ), 'styles: character style carries its font' );
	$check( '10pt' === ( $cv['props']['font-size'] ?? '' ), 'styles: character style carries its size' );
	$check( '#0070c0' === ( $cv['props']['color'] ?? '' ), 'styles: character style carries its colour' );

	$bib = (array) ( $styles_ir['Bibliography'] ?? [] );
	$check( 'Bibliography' === ( $bib['name'] ?? '' ), 'styles: reads paragraph style name' );
	$check( 'justify' === ( $bib['props']['text-align'] ?? '' ), 'styles: paragraph style carries alignment' );
	$check( '36pt' === ( $bib['props']['margin-left'] ?? '' ), 'styles: paragraph style carries indent' );
	$check( 'Calibri' === ( $bib['props']['font-family'] ?? '' ), 'styles: paragraph style carries its font (rPr)' );
	$check( ! isset( $styles_ir['TableGrid'] ), 'styles: ignores table styles' );
} finally {
	@unlink( $tmp );
}

WP_CLI::log( '' );
WP_CLI::log( "Passed: $pass   Failed: $fail" );
if ( $fail > 0 ) {
	WP_CLI::error( "$fail docx-reader check(s) failed." );
}
WP_CLI::success( 'Docx-reader direct-formatting checks passed.' );
