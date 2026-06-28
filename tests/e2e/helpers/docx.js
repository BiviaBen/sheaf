// Build a minimal valid .docx in memory for import tests — a zip of
// WordprocessingML with one character style (w:rStyle) and one paragraph style
// (w:pStyle), which is all Docx_Reader needs to detect named styles.

const JSZip = require( 'jszip' );

const CONTENT_TYPES =
	'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
	'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
	'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
	'<Default Extension="xml" ContentType="application/xml"/>' +
	'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' +
	'</Types>';

const RELS =
	'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
	'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
	'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' +
	'</Relationships>';

const DOCUMENT =
	'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
	'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' +
	// Paragraph with a character-styled run in the middle.
	'<w:p>' +
	'<w:r><w:t xml:space="preserve">Plain text and a </w:t></w:r>' +
	'<w:r><w:rPr><w:rStyle w:val="ComputerVoice"/></w:rPr><w:t>computer voice</w:t></w:r>' +
	'<w:r><w:t xml:space="preserve"> phrase.</w:t></w:r>' +
	'</w:p>' +
	// Paragraph carrying a paragraph style.
	'<w:p><w:pPr><w:pStyle w:val="Verse"/></w:pPr><w:r><w:t>A whole verse paragraph here.</w:t></w:r></w:p>' +
	'</w:body></w:document>';

async function makeDocx() {
	const zip = new JSZip();
	zip.file( '[Content_Types].xml', CONTENT_TYPES );
	zip.file( '_rels/.rels', RELS );
	zip.file( 'word/document.xml', DOCUMENT );
	return zip.generateAsync( { type: 'nodebuffer' } );
}

module.exports = { makeDocx };
