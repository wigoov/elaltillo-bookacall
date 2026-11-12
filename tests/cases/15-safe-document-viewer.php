<?php
/**
 * Attachments are only ever viewed, never downloaded, and nothing inside them runs: Office
 * files become escaped HTML on the server; PDFs are drawn by the bundled PDF.js.
 */

use ELAB_Test as T;

const ELAB_TEST_W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';
const ELAB_TEST_P = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';
const ELAB_TEST_S = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
const ELAB_TEST_REL = 'xmlns="http://schemas.openxmlformats.org/package/2006/relationships"';

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
$types = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>';

/** A real Office file (a ZIP of XML parts) written to a temporary path. */
$office = static function ( $extension, array $parts ) {
	$path = wp_tempnam( 'elab-test' ) . '.' . $extension;
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $parts as $name => $content ) {
		$zip->addFromString( $name, $content );
	}
	$zip->close();
	return $path;
};

$docx = static function ( $body = null ) use ( $office, $types, $png ) {
	$body = $body ?? '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Plan &lt;script&gt;alert(1)&lt;/script&gt;</w:t></w:r></w:p>'
		. '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Negrita</w:t></w:r></w:p>'
		. '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Celda uno</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
		. '<w:p><w:r><w:drawing><a:graphic><a:graphicData><a:blip r:embed="rId5"/><a:blip r:embed="rId6"/></a:graphicData></a:graphic></w:drawing></w:r></w:p>'
		. '<w:p><w:hyperlink r:id="rId9"><w:r><w:t>Texto del enlace</w:t></w:r></w:hyperlink></w:p>'
		. '<w:p><w:del><w:r><w:delText>Borrado</w:delText></w:r></w:del></w:p>';
	return $office( 'docx', array(
		'[Content_Types].xml' => $types,
		'word/document.xml' => '<?xml version="1.0"?><w:document ' . ELAB_TEST_W . '><w:body>' . $body . '</w:body></w:document>',
		'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships ' . ELAB_TEST_REL . '><Relationship Id="rId5" Target="media/image1.png"/><Relationship Id="rId6" Target="media/evil.svg"/><Relationship Id="rId9" Target="https://evil.example/x" TargetMode="External"/></Relationships>',
		'word/media/image1.png' => $png,
		'word/media/evil.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
	) );
};

$stored = static function ( $source, $extension, $name ) {
	$dir = T::call( 'ELAB_Private_Attachments', 'safe_dir' );
	$file = wp_generate_password( 20, false ) . '.' . $extension;
	copy( $source, trailingslashit( $dir ) . $file );
	$booking = T::booking( T::advisor(), array( '_elab_files' => array( array( 'name' => $name, 'stored' => $file ) ) ) );
	return array( $booking, $file );
};

return array(
	'Only PDF, DOCX, XLSX and PPTX are accepted, and the form says so' => function () {
		T::same( array( 'pdf', 'docx', 'xlsx', 'pptx' ), array_keys( ELAB_Private_Attachments::allowed_types() ), 'Allowed types' );
		T::advisor(); // The form is drawn only when someone can be booked.
		$html = T::render( static function () { echo do_shortcode( '[elaltillo_bookacall]' ); } );
		T::contains( 'accept=".pdf,.docx,.xlsx,.pptx"', $html, 'File picker' );
		$old = wp_tempnam( 'elab-test' );
		file_put_contents( $old, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat( "\0", 512 ) );
		$_FILES = array( 'attachments' => array( 'name' => array( 'plan.doc' ), 'tmp_name' => array( $old ), 'error' => array( 0 ), 'size' => array( 520 ) ) );
		T::error( 'invalid_file_type', ( new ELAB_Private_Attachments() )->upload(), 'Old .doc refused' );
		unlink( $old );
	},

	'A Word file with macros is refused at upload' => function () use ( $office, $types ) {
		$path = $office( 'docx', array(
			'[Content_Types].xml' => str_replace( '</Types>', '<Override PartName="/word/vbaProject.bin" ContentType="application/vnd.ms-office.vbaProject"/></Types>', $types ),
			'word/document.xml' => '<?xml version="1.0"?><w:document ' . ELAB_TEST_W . '><w:body/></w:document>',
			'word/vbaProject.bin' => 'macro',
		) );
		T::error( 'file_has_macros', ELAB_Office_Preview::inspect( $path, 'docx' ), 'Macros refused' );
		$_FILES = array( 'attachments' => array( 'name' => array( 'plan.docx' ), 'tmp_name' => array( $path ), 'error' => array( 0 ), 'size' => array( filesize( $path ) ) ) );
		$result = ( new ELAB_Private_Attachments() )->upload();
		T::check( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'file_has_macros', 'invalid_file_type' ), true ), 'Upload refused' );
		unlink( $path );
	},

	'A file named .docx that is not a Word document, or an archive bomb, is refused' => function () use ( $office, $types ) {
		$excel_as_word = $office( 'docx', array( '[Content_Types].xml' => $types, 'xl/workbook.xml' => '<x/>' ) );
		T::error( 'invalid_file_type', ELAB_Office_Preview::inspect( $excel_as_word, 'docx' ), 'Main part missing' );
		$parts = array( '[Content_Types].xml' => $types, 'word/document.xml' => '<x/>' );
		for ( $i = 0; $i <= ELAB_Office_Preview::MAX_ENTRIES; $i++ ) {
			$parts[ 'filler/' . $i . '.xml' ] = '<x/>';
		}
		$bomb = $office( 'docx', $parts );
		T::error( 'invalid_file_type', ELAB_Office_Preview::inspect( $bomb, 'docx' ), 'Too many entries' );
		T::error( 'invalid_file_type', ELAB_Office_Preview::inspect( __FILE__, 'docx' ), 'Not a ZIP at all' );
		unlink( $excel_as_word );
		unlink( $bomb );
	},

	'Word becomes escaped HTML: text, styles, tables and PNG pictures; no links, SVG or deleted text' => function () use ( $docx ) {
		$path = $docx();
		$html = ELAB_Office_Preview::to_html( $path, 'docx' );
		unlink( $path );
		T::check( is_string( $html ), 'Converted' );
		T::contains( '<h2>Plan &lt;script&gt;alert(1)&lt;/script&gt;</h2>', $html, 'Heading, script shown as text' );
		T::lacks( '<script', $html, 'No script element' );
		T::contains( '<strong>Negrita</strong>', $html, 'Bold' );
		T::contains( '<td>Celda uno</td>', $html, 'Table' );
		T::contains( 'src="data:image/png;base64,', $html, 'PNG picture inline' );
		T::lacks( 'svg', $html, 'SVG never shown' );
		T::contains( 'Texto del enlace', $html, 'Link text kept' );
		T::lacks( 'evil.example', $html, 'Link target dropped' );
		T::lacks( '<a ', $html, 'No links at all' );
		T::lacks( 'Borrado', $html, 'Deleted text hidden' );
	},

	'XML with a DOCTYPE (entity tricks) is never parsed' => function () use ( $docx ) {
		$path = $docx( '' );
		$zip = new ZipArchive();
		$zip->open( $path );
		$zip->addFromString( 'word/document.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaaaaaaaa">]><w:document ' . ELAB_TEST_W . '><w:body><w:p><w:r><w:t>&a;</w:t></w:r></w:p></w:body></w:document>' );
		$zip->close();
		T::error( 'invalid_file_type', ELAB_Office_Preview::to_html( $path, 'docx' ), 'Refused' );
		unlink( $path );
	},

	'Excel becomes a table per sheet, values escaped' => function () use ( $office, $types ) {
		$path = $office( 'xlsx', array(
			'[Content_Types].xml' => $types,
			'xl/workbook.xml' => '<?xml version="1.0"?><workbook ' . ELAB_TEST_S . '><sheets><sheet name="Ventas" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships ' . ELAB_TEST_REL . '><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
			'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst ' . ELAB_TEST_S . '><si><t>Producto</t></si><si><t>&lt;img src=x onerror=alert(1)&gt;</t></si></sst>',
			'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet ' . ELAB_TEST_S . '><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1"><v>42</v></c></row><row r="3"><c r="A3" t="s"><v>1</v></c><c r="B3" t="inlineStr"><is><t>Texto</t></is></c><c r="C3" t="b"><v>1</v></c></row></sheetData></worksheet>',
		) );
		$html = ELAB_Office_Preview::to_html( $path, 'xlsx' );
		unlink( $path );
		T::contains( '<h2>Ventas</h2>', $html, 'Sheet name' );
		T::contains( '<td>Producto</td><td></td><td>42</td>', $html, 'Cells in their columns' );
		T::contains( '<th>3</th>', $html, 'Row numbers kept' );
		T::contains( '&lt;img src=x onerror=alert(1)&gt;', $html, 'Markup shown as text' );
		T::lacks( '<img src=x', $html, 'Never as an element' );
		T::contains( '<td>TRUE</td>', $html, 'Booleans' );
	},

	'PowerPoint becomes one card per slide, in order, with titles, text and pictures' => function () use ( $office, $types, $png ) {
		$slide = static function ( $title, $text, $picture ) {
			return '<?xml version="1.0"?><p:sld ' . ELAB_TEST_P . '><p:cSld><p:spTree>'
				. '<p:sp><p:nvSpPr><p:cNvPr id="1" name="t"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>' . $title . '</a:t></a:r></a:p></p:txBody></p:sp>'
				. '<p:sp><p:nvSpPr><p:cNvPr id="2" name="b"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:txBody><a:p><a:r><a:t>' . $text . '</a:t></a:r></a:p></p:txBody></p:sp>'
				. ( $picture ? '<p:pic><p:blipFill><a:blip r:embed="rId2"/></p:blipFill></p:pic>' : '' )
				. '</p:spTree></p:cSld></p:sld>';
		};
		$path = $office( 'pptx', array(
			'[Content_Types].xml' => $types,
			'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation ' . ELAB_TEST_P . '><p:sldIdLst><p:sldId id="256" r:id="rId8"/><p:sldId id="257" r:id="rId7"/></p:sldIdLst></p:presentation>',
			'ppt/_rels/presentation.xml.rels' => '<?xml version="1.0"?><Relationships ' . ELAB_TEST_REL . '><Relationship Id="rId7" Target="slides/slide2.xml"/><Relationship Id="rId8" Target="slides/slide1.xml"/></Relationships>',
			'ppt/slides/slide1.xml' => $slide( 'Portada', 'Punto uno', true ),
			'ppt/slides/_rels/slide1.xml.rels' => '<?xml version="1.0"?><Relationships ' . ELAB_TEST_REL . '><Relationship Id="rId2" Target="../media/image1.png"/></Relationships>',
			'ppt/slides/slide2.xml' => $slide( 'Cierre', '&lt;script&gt;x&lt;/script&gt;', false ),
			'ppt/media/image1.png' => $png,
		) );
		$html = ELAB_Office_Preview::to_html( $path, 'pptx' );
		unlink( $path );
		T::check( strpos( $html, 'Portada' ) < strpos( $html, 'Cierre' ), 'Presentation order, not file order' );
		T::contains( '<h2>Portada</h2>', $html, 'Title' );
		T::contains( '<p>Punto uno</p>', $html, 'Text' );
		T::contains( 'data:image/png;base64,', $html, 'Picture' );
		T::contains( 'Slide 2', $html, 'Numbered' );
		T::lacks( '<script', $html, 'Escaped' );
	},

	'The Office viewer page runs no script and is sandboxed; nothing is downloaded' => function () use ( $docx, $stored ) {
		$path = $docx();
		list( $booking, $file ) = $stored( $path, 'docx', 'Propuesta.docx' );
		unlink( $path );
		$page = ELAB_Document_Viewer::page( $booking, $file );
		T::check( is_array( $page ), 'Page built' );
		$policy = $page['headers']['Content-Security-Policy'];
		T::contains( 'sandbox', $policy, 'Sandboxed' );
		T::contains( "script-src 'none'", $policy, 'No script may run' );
		T::contains( "form-action 'none'", $policy, 'No form may submit' );
		T::same( 'DENY', $page['headers']['X-Frame-Options'], 'Cannot be framed' );
		T::check( ! isset( $page['headers']['Content-Disposition'] ), 'Never a download' );
		T::lacks( '<script', $page['html'], 'No script in the page' );
		T::contains( '<title>Propuesta.docx</title>', $page['html'], 'Named after the file' );
		T::contains( 'nothing in it runs', $page['html'], 'Says so' );
	},

	'The PDF viewer page embeds the file as data, loads only the bundled PDF.js and connects nowhere' => function () use ( $stored ) {
		$pdf = wp_tempnam( 'elab-test' );
		file_put_contents( $pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/OpenAction<</S/JavaScript/JS(app.alert(1))>>>>endobj\n%%EOF" );
		list( $booking, $file ) = $stored( $pdf, 'pdf', 'Contrato.pdf' );
		unlink( $pdf );
		$page = ELAB_Document_Viewer::page( $booking, $file );
		$policy = $page['headers']['Content-Security-Policy'];
		T::contains( "script-src 'self'", $policy, 'Only this site’s scripts' );
		T::contains( "connect-src 'none'", $policy, 'No network' );
		T::lacks( 'unsafe-eval', $policy, 'No eval' );
		T::check( ! isset( $page['headers']['Content-Disposition'] ), 'Never a download' );
		T::contains( '<script type="application/octet-stream" id="elab-pdf-data">', $page['html'], 'File as inert data' );
		T::contains( 'assets/js/pdf-viewer.js', $page['html'], 'Bundled viewer' );
		T::lacks( 'cdn', strtolower( $page['html'] ), 'No CDN' );
		T::lacks( 'app.alert', $page['html'], 'The PDF’s script never appears as code' );
		$viewer = (string) file_get_contents( ELAB_DIR . 'assets/js/pdf-viewer.js' );
		T::contains( 'AnnotationMode.DISABLE', $viewer, 'Forms and links not drawn' );
		T::contains( 'useWasm: false', $viewer, 'No WebAssembly' );
		T::check( is_readable( ELAB_DIR . 'assets/js/vendor/pdfjs/pdf.worker.min.js' ), 'Worker bundled' );
	},

	'Only that booking’s own files can be viewed' => function () use ( $docx, $stored ) {
		$path = $docx();
		list( $booking, $file ) = $stored( $path, 'docx', 'Propuesta.docx' );
		unlink( $path );
		$other = T::booking( T::advisor() );
		T::error( 'attachment_unknown', ELAB_Private_Attachments::find( $other, $file ), 'Another booking' );
		T::error( 'attachment_unknown', ELAB_Private_Attachments::find( $booking, '../' . $file ), 'Path trick' );
		T::error( 'attachment_unknown', ELAB_Private_Attachments::find( $booking, '' ), 'Empty' );
	},

	'Only administrators open the viewer; the old download link no longer exists' => function () use ( $docx, $stored ) {
		$path = $docx();
		list( $booking, $file ) = $stored( $path, 'docx', 'Propuesta.docx' );
		unlink( $path );
		T::check( false === has_action( 'admin_post_elab_download' ), 'No download route' );
		wp_set_current_user( T::user( 'editor' ) );
		$_GET = array( 'booking' => (string) $booking, 'file' => $file, '_wpnonce' => wp_create_nonce( ELAB_Document_Viewer::ACTION . '_' . $booking . '_' . $file ) );
		$die = static function () { return static function () { throw new ELAB_Test_Failure( 'die' ); }; };
		add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
		try {
			ob_start();
			( new ELAB_Document_Viewer() )->view();
			ob_end_clean();
			$stopped = false;
		} catch ( ELAB_Test_Failure $failure ) {
			ob_end_clean();
			$stopped = true;
		} finally {
			remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
		}
		T::check( $stopped, 'Editor refused' );
		T::as_admin();
		$html = T::render( static function () use ( $booking ) { ( new ELAB_Booking_Admin() )->render( get_post( $booking ) ); } );
		T::contains( 'action=elab_view', $html, 'Record links the viewer' );
		T::lacks( 'elab_download', $html, 'No download link' );
	},
);
