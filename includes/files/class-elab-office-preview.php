<?php
/**
 * Word (.docx), Excel (.xlsx) and PowerPoint (.pptx) files turned into plain, escaped HTML on
 * the server, so the browser never receives the original file.
 *
 * These formats are ZIP archives of XML. Only text, tables and PNG/JPEG/GIF pictures are read;
 * macros, embedded objects, links, fields and every other active part are ignored. The archive
 * and its XML are read with hard limits (entries, sizes, no DOCTYPE), and files that carry
 * macros are refused at upload.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Office_Preview {
	const NS = array(
		'w'   => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
		'r'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
		'a'   => 'http://schemas.openxmlformats.org/drawingml/2006/main',
		'p'   => 'http://schemas.openxmlformats.org/presentationml/2006/main',
		's'   => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
		'rel' => 'http://schemas.openxmlformats.org/package/2006/relationships',
	);
	/** The part every file of that type must have. */
	const MAIN = array( 'docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml' );
	const MAX_ENTRIES = 3000;
	const MAX_ENTRY = 40 * MB_IN_BYTES;
	const MAX_TOTAL = 150 * MB_IN_BYTES;
	const MAX_IMAGE = 5 * MB_IN_BYTES;
	const MAX_IMAGES = 25 * MB_IN_BYTES;
	const MAX_ROWS = 500;
	const MAX_COLUMNS = 50;

	/** Bytes of pictures already placed in the current preview. */
	private static $image_bytes = 0;

	/**
	 * Upload check: a real file of that type, within the limits, with no macros or embedded
	 * programs.
	 *
	 * @return true|WP_Error
	 */
	public static function inspect( $path, $extension ) {
		$zip = self::open( $path );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		try {
			if ( ! isset( self::MAIN[ $extension ] ) || false === $zip->locateName( self::MAIN[ $extension ] ) ) {
				return self::refused();
			}
			$types = (string) self::read( $zip, '[Content_Types].xml' );
			if ( false !== stripos( $types, 'macroEnabled' ) || false !== stripos( $types, 'vbaProject' ) ) {
				return new WP_Error( 'file_has_macros', __( 'Files with macros are not accepted. Save the document without macros (or as PDF) and attach it again.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = strtolower( (string) $zip->getNameIndex( $i ) );
				if ( preg_match( '#(^|/)vbaproject\.bin$|(^|/)vbadata\.xml$|\.(exe|dll|bat|cmd|js|vbs|ps1|jar|scr|com|msi)$#', $name ) ) {
					return new WP_Error( 'file_has_macros', __( 'Files with macros are not accepted. Save the document without macros (or as PDF) and attach it again.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
				}
			}
			return true;
		} finally {
			$zip->close();
		}
	}

	/**
	 * The document as escaped HTML (body content only).
	 *
	 * @return string|WP_Error
	 */
	public static function to_html( $path, $extension ) {
		$zip = self::open( $path );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		self::$image_bytes = 0;
		try {
			switch ( $extension ) {
				case 'docx':
					$html = self::word( $zip );
					break;
				case 'xlsx':
					$html = self::excel( $zip );
					break;
				case 'pptx':
					$html = self::slides( $zip );
					break;
				default:
					$html = null;
			}
			return null === $html ? self::refused() : $html;
		} finally {
			$zip->close();
		}
	}

	private static function refused() {
		return new WP_Error( 'invalid_file_type', __( 'This file could not be read as a Word, Excel or PowerPoint document.', 'elaltillo-bookacall' ), array( 'status' => 400 ) );
	}

	/* ---------- Archive and XML, with limits ---------- */

	/** @return ZipArchive|WP_Error */
	private static function open( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_unavailable', __( 'This server cannot read Office documents (the PHP zip extension is missing).', 'elaltillo-bookacall' ), array( 'status' => 503 ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			return self::refused();
		}
		$total = 0;
		$count = $zip->numFiles;
		$ok = $count > 0 && $count <= self::MAX_ENTRIES;
		for ( $i = 0; $ok && $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = is_array( $stat ) ? (string) $stat['name'] : '';
			$total += is_array( $stat ) ? (int) $stat['size'] : 0;
			// Absolute or parent paths never belong in a document; huge expansion is a zip bomb.
			if ( ! is_array( $stat ) || $stat['size'] > self::MAX_ENTRY || $total > self::MAX_TOTAL || '' === $name || '/' === $name[0] || false !== strpos( $name, '..' ) ) {
				$ok = false;
			}
		}
		if ( ! $ok ) {
			$zip->close();
			return self::refused();
		}
		return $zip;
	}

	private static function read( ZipArchive $zip, $name, $max = self::MAX_ENTRY ) {
		$stat = $zip->statName( $name );
		if ( ! is_array( $stat ) || $stat['size'] > $max ) {
			return null;
		}
		$data = $zip->getFromName( $name );
		return is_string( $data ) ? $data : null;
	}

	/** Parse XML without a DOCTYPE (no entities), network or external files. */
	private static function xml( ZipArchive $zip, $name ) {
		$data = self::read( $zip, $name );
		if ( null === $data || false !== stripos( $data, '<!DOCTYPE' ) || false !== stripos( $data, '<!ENTITY' ) ) {
			return null;
		}
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$ok = $doc->loadXML( $data, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $ok ) {
			return null;
		}
		$xpath = new DOMXPath( $doc );
		foreach ( self::NS as $prefix => $uri ) {
			$xpath->registerNamespace( $prefix, $uri );
		}
		return $xpath;
	}

	/** Relationship ID => path inside the archive, for one part. */
	private static function rels( ZipArchive $zip, $part ) {
		$dir = dirname( $part );
		$xpath = self::xml( $zip, ( '.' === $dir ? '' : $dir . '/' ) . '_rels/' . basename( $part ) . '.rels' );
		$map = array();
		if ( ! $xpath ) {
			return $map;
		}
		foreach ( $xpath->query( '/rel:Relationships/rel:Relationship' ) as $rel ) {
			if ( 'External' === $rel->getAttribute( 'TargetMode' ) ) {
				continue; // Links and remote pictures are never followed.
			}
			$map[ $rel->getAttribute( 'Id' ) ] = self::resolve( $dir, $rel->getAttribute( 'Target' ) );
		}
		return $map;
	}

	private static function resolve( $dir, $target ) {
		$path = 0 === strpos( $target, '/' ) ? substr( $target, 1 ) : ( '.' === $dir ? '' : $dir . '/' ) . $target;
		$parts = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $parts );
			} elseif ( '' !== $segment && '.' !== $segment ) {
				$parts[] = $segment;
			}
		}
		return implode( '/', $parts );
	}

	/** A picture as a data: URI, only PNG, JPEG or GIF recognised by their bytes; '' otherwise. */
	private static function image( ZipArchive $zip, $name ) {
		$data = self::read( $zip, $name, self::MAX_IMAGE );
		if ( null === $data || self::$image_bytes + strlen( $data ) > self::MAX_IMAGES ) {
			return '';
		}
		if ( 0 === strpos( $data, "\x89PNG\r\n\x1a\n" ) ) {
			$type = 'image/png';
		} elseif ( 0 === strpos( $data, "\xFF\xD8\xFF" ) ) {
			$type = 'image/jpeg';
		} elseif ( 0 === strpos( $data, 'GIF87a' ) || 0 === strpos( $data, 'GIF89a' ) ) {
			$type = 'image/gif';
		} else {
			return ''; // SVG, EMF, WMF and anything else are not shown.
		}
		self::$image_bytes += strlen( $data );
		return '<img alt="" src="data:' . $type . ';base64,' . base64_encode( $data ) . '">';
	}

	private static function text( $value ) {
		return esc_html( (string) $value );
	}

	/* ---------- Word ---------- */

	private static function word( ZipArchive $zip ) {
		$xpath = self::xml( $zip, 'word/document.xml' );
		if ( ! $xpath ) {
			return null;
		}
		$rels = self::rels( $zip, 'word/document.xml' );
		$out = '';
		foreach ( $xpath->query( '/w:document/w:body/*' ) as $node ) {
			if ( 'p' === $node->localName ) {
				$out .= self::word_paragraph( $zip, $xpath, $rels, $node );
			} elseif ( 'tbl' === $node->localName ) {
				$out .= self::word_table( $zip, $xpath, $rels, $node );
			}
		}
		return '<article class="doc">' . $out . '</article>';
	}

	private static function word_runs( ZipArchive $zip, DOMXPath $xpath, array $rels, DOMNode $paragraph ) {
		$html = '';
		// Runs anywhere in the paragraph (links, insertions, fields) but never deleted text.
		foreach ( $xpath->query( './/w:r[not(ancestor::w:del)]', $paragraph ) as $run ) {
			$piece = '';
			foreach ( $run->childNodes as $child ) {
				if ( 't' === $child->localName ) {
					$piece .= self::text( $child->textContent );
				} elseif ( 'tab' === $child->localName ) {
					$piece .= '&emsp;';
				} elseif ( 'br' === $child->localName || 'cr' === $child->localName ) {
					$piece .= '<br>';
				} elseif ( 'drawing' === $child->localName || 'pict' === $child->localName ) {
					foreach ( $xpath->query( './/a:blip/@r:embed', $child ) as $embed ) {
						$piece .= isset( $rels[ $embed->value ] ) ? self::image( $zip, $rels[ $embed->value ] ) : '';
					}
				}
			}
			if ( '' === $piece ) {
				continue;
			}
			$on = static function ( $tag ) use ( $xpath, $run ) {
				$flag = $xpath->query( 'w:rPr/w:' . $tag, $run )->item( 0 );
				return $flag && ! in_array( $flag->getAttributeNS( self::NS['w'], 'val' ), array( '0', 'false', 'none' ), true );
			};
			if ( $on( 'b' ) ) {
				$piece = '<strong>' . $piece . '</strong>';
			}
			if ( $on( 'i' ) ) {
				$piece = '<em>' . $piece . '</em>';
			}
			if ( $on( 'u' ) ) {
				$piece = '<u>' . $piece . '</u>';
			}
			$html .= $piece;
		}
		return $html;
	}

	private static function word_paragraph( ZipArchive $zip, DOMXPath $xpath, array $rels, DOMNode $paragraph ) {
		$content = self::word_runs( $zip, $xpath, $rels, $paragraph );
		$style = strtolower( (string) $xpath->evaluate( 'string(w:pPr/w:pStyle/@w:val)', $paragraph ) );
		if ( '' === trim( wp_strip_all_tags( $content ) ) && false === strpos( $content, '<img' ) ) {
			return '<p class="gap"></p>';
		}
		if ( 'title' === $style || 'titulo' === $style || 'título' === $style ) {
			return '<h1>' . $content . '</h1>';
		}
		if ( preg_match( '/(heading|ttulo|titulo|título)\s*([1-6])$/u', $style, $match ) ) {
			$level = min( 6, (int) $match[2] + 1 );
			return '<h' . $level . '>' . $content . '</h' . $level . '>';
		}
		if ( $xpath->query( 'w:pPr/w:numPr', $paragraph )->length ) {
			return '<p class="item">• ' . $content . '</p>';
		}
		return '<p>' . $content . '</p>';
	}

	private static function word_table( ZipArchive $zip, DOMXPath $xpath, array $rels, DOMNode $table ) {
		$rows = '';
		foreach ( $xpath->query( 'w:tr', $table ) as $row ) {
			$cells = '';
			foreach ( $xpath->query( 'w:tc', $row ) as $cell ) {
				$span = max( 1, (int) $xpath->evaluate( 'string(w:tcPr/w:gridSpan/@w:val)', $cell ) );
				$parts = array();
				foreach ( $xpath->query( 'w:p', $cell ) as $paragraph ) {
					$parts[] = self::word_runs( $zip, $xpath, $rels, $paragraph );
				}
				$cells .= '<td' . ( $span > 1 ? ' colspan="' . (int) $span . '"' : '' ) . '>' . implode( '<br>', array_filter( $parts, 'strlen' ) ) . '</td>';
			}
			$rows .= '<tr>' . $cells . '</tr>';
		}
		return '<div class="scroll"><table>' . $rows . '</table></div>';
	}

	/* ---------- Excel ---------- */

	private static function excel( ZipArchive $zip ) {
		$book = self::xml( $zip, 'xl/workbook.xml' );
		if ( ! $book ) {
			return null;
		}
		$rels = self::rels( $zip, 'xl/workbook.xml' );
		$shared = array();
		$strings = self::xml( $zip, 'xl/sharedStrings.xml' );
		if ( $strings ) {
			foreach ( $strings->query( '/s:sst/s:si' ) as $item ) {
				$text = '';
				foreach ( $strings->query( './/s:t[not(ancestor::s:rPh)]', $item ) as $t ) {
					$text .= $t->textContent;
				}
				$shared[] = $text;
			}
		}
		$dates = self::excel_date_styles( $zip );
		// Dates count from 1899-12-30, or from 1904-01-01 in workbooks made with that setting.
		$epoch = in_array( strtolower( (string) $book->evaluate( 'string(/s:workbook/s:workbookPr/@date1904)' ) ), array( '1', 'true' ), true ) ? 24107 : 25569;
		$out = '';
		foreach ( $book->query( '/s:workbook/s:sheets/s:sheet' ) as $sheet ) {
			$name = $sheet->getAttribute( 'name' );
			$part = $rels[ $sheet->getAttributeNS( self::NS['r'], 'id' ) ] ?? '';
			$data = $part ? self::xml( $zip, $part ) : null;
			$out .= '<section class="sheet"><h2>' . self::text( $name ) . '</h2>' . ( $data ? self::excel_sheet( $data, $shared, $dates, $epoch ) : '' ) . '</section>';
		}
		return '<article class="book">' . $out . '</article>';
	}

	private static function column_index( $reference ) {
		$letters = preg_replace( '/[^A-Z]/', '', strtoupper( (string) $reference ) );
		$index = 0;
		foreach ( str_split( $letters ) as $letter ) {
			$index = $index * 26 + ( ord( $letter ) - 64 );
		}
		return max( 0, $index - 1 );
	}

	private static function column_name( $index ) {
		$name = '';
		for ( $n = $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$name = chr( 65 + ( $n - 1 ) % 26 ) . $name;
		}
		return $name;
	}

	/**
	 * Cell styles (by index) that show a number as a date or time, from xl/styles.xml.
	 *
	 * @return array<int, string> Style index => 'date', 'datetime' or 'time'.
	 */
	private static function excel_date_styles( ZipArchive $zip ) {
		$xpath = self::xml( $zip, 'xl/styles.xml' );
		if ( ! $xpath ) {
			return array();
		}
		// Excel's built-in date and time formats.
		$kinds = array( 14 => 'date', 15 => 'date', 16 => 'date', 17 => 'date', 18 => 'time', 19 => 'time', 20 => 'time', 21 => 'time', 22 => 'datetime', 45 => 'time', 46 => 'time', 47 => 'time' );
		foreach ( $xpath->query( '/s:styleSheet/s:numFmts/s:numFmt' ) as $format ) {
			// Ignore quoted text, colours and the like, then look for date and time letters.
			$code = strtolower( preg_replace( '/"[^"]*"|\\\\.|\[[^\]]*\]/', '', $format->getAttribute( 'formatCode' ) ) );
			$date = (bool) preg_match( '/[dy]|m{3,}/', $code ) || ( false !== strpos( $code, 'm' ) && false === strpos( $code, 'h' ) && false === strpos( $code, 's' ) && preg_match( '/[dy]/', $code ) );
			$time = (bool) preg_match( '/h|s/', $code );
			if ( $date || $time ) {
				$kinds[ (int) $format->getAttribute( 'numFmtId' ) ] = $date && $time ? 'datetime' : ( $date ? 'date' : 'time' );
			}
		}
		$styles = array();
		foreach ( $xpath->query( '/s:styleSheet/s:cellXfs/s:xf' ) as $index => $style ) {
			$id = (int) $style->getAttribute( 'numFmtId' );
			if ( isset( $kinds[ $id ] ) ) {
				$styles[ $index ] = $kinds[ $id ];
			}
		}
		return $styles;
	}

	/** A date serial as 2026-11-24, 2026-11-24 15:30 or 15:30. */
	private static function excel_date( $value, $kind, $epoch ) {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}
		$seconds = (int) round( ( (float) $value - $epoch ) * DAY_IN_SECONDS );
		$format = array( 'date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i', 'time' => 'H:i' )[ $kind ];
		return gmdate( $format, 'time' === $kind ? (int) round( fmod( (float) $value, 1 ) * DAY_IN_SECONDS ) : $seconds );
	}

	private static function excel_sheet( DOMXPath $xpath, array $shared, array $dates = array(), $epoch = 25569 ) {
		$grid = array();
		$width = 0;
		$cut = false;
		foreach ( $xpath->query( '/s:worksheet/s:sheetData/s:row' ) as $position => $row ) {
			$number = (int) $row->getAttribute( 'r' ) ?: $position + 1;
			if ( count( $grid ) >= self::MAX_ROWS ) {
				$cut = true;
				break;
			}
			$cells = array();
			$column = 0;
			foreach ( $xpath->query( 's:c', $row ) as $cell ) {
				$column = $cell->getAttribute( 'r' ) ? self::column_index( $cell->getAttribute( 'r' ) ) : $column;
				if ( $column >= self::MAX_COLUMNS ) {
					$cut = true;
					continue;
				}
				$type = $cell->getAttribute( 't' );
				$value = $xpath->evaluate( 'string(s:v)', $cell );
				if ( 's' === $type ) {
					$value = $shared[ (int) $value ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$value = $xpath->evaluate( 'string(s:is)', $cell );
				} elseif ( 'b' === $type ) {
					$value = '1' === $value ? 'TRUE' : 'FALSE';
				} elseif ( '' !== $value && isset( $dates[ (int) $cell->getAttribute( 's' ) ] ) ) {
					$value = self::excel_date( $value, $dates[ (int) $cell->getAttribute( 's' ) ], $epoch );
				}
				if ( '' !== $value ) {
					$cells[ $column ] = $value;
					$width = max( $width, $column + 1 );
				}
				$column++;
			}
			if ( $cells ) {
				$grid[ $number ] = $cells;
			}
		}
		if ( ! $grid ) {
			return '<p class="note">' . esc_html__( 'This sheet is empty.', 'elaltillo-bookacall' ) . '</p>';
		}
		$head = '<tr><th></th>';
		for ( $c = 0; $c < $width; $c++ ) {
			$head .= '<th>' . self::column_name( $c ) . '</th>';
		}
		$body = '';
		foreach ( $grid as $number => $cells ) {
			$body .= '<tr><th>' . (int) $number . '</th>';
			for ( $c = 0; $c < $width; $c++ ) {
				$body .= '<td>' . self::text( $cells[ $c ] ?? '' ) . '</td>';
			}
			$body .= '</tr>';
		}
		$note = $cut ? '<p class="note">' . esc_html( sprintf( __( 'Only the first %1$d rows and %2$d columns are shown.', 'elaltillo-bookacall' ), self::MAX_ROWS, self::MAX_COLUMNS ) ) . '</p>' : '';
		return $note . '<div class="scroll"><table class="cells"><thead>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
	}

	/* ---------- PowerPoint ---------- */

	private static function slides( ZipArchive $zip ) {
		$deck = self::xml( $zip, 'ppt/presentation.xml' );
		if ( ! $deck ) {
			return null;
		}
		$rels = self::rels( $zip, 'ppt/presentation.xml' );
		$out = '';
		$number = 0;
		foreach ( $deck->query( '/p:presentation/p:sldIdLst/p:sldId' ) as $slide ) {
			$part = $rels[ $slide->getAttributeNS( self::NS['r'], 'id' ) ] ?? '';
			$xpath = $part ? self::xml( $zip, $part ) : null;
			if ( ! $xpath ) {
				continue;
			}
			$number++;
			$slide_rels = self::rels( $zip, $part );
			$tree = $xpath->query( '/p:sld/p:cSld/p:spTree' )->item( 0 );
			$out .= '<section class="slide"><p class="slide-number">' . esc_html( sprintf( __( 'Slide %d', 'elaltillo-bookacall' ), $number ) ) . '</p>' . ( $tree ? self::slide_shapes( $zip, $xpath, $slide_rels, $tree ) : '' ) . '</section>';
		}
		return '<article class="deck">' . $out . '</article>';
	}

	private static function slide_shapes( ZipArchive $zip, DOMXPath $xpath, array $rels, DOMNode $tree ) {
		$out = '';
		foreach ( $tree->childNodes as $shape ) {
			if ( ! $shape instanceof DOMElement ) {
				continue;
			}
			if ( 'grpSp' === $shape->localName ) {
				$out .= self::slide_shapes( $zip, $xpath, $rels, $shape );
			} elseif ( 'sp' === $shape->localName ) {
				$type = (string) $xpath->evaluate( 'string(p:nvSpPr/p:nvPr/p:ph/@type)', $shape );
				$title = in_array( $type, array( 'title', 'ctrTitle' ), true );
				foreach ( $xpath->query( 'p:txBody/a:p', $shape ) as $paragraph ) {
					$text = self::slide_text( $xpath, $paragraph );
					if ( '' !== trim( wp_strip_all_tags( $text ) ) ) {
						$out .= $title ? '<h2>' . $text . '</h2>' : '<p>' . $text . '</p>';
					}
				}
			} elseif ( 'pic' === $shape->localName ) {
				foreach ( $xpath->query( './/a:blip/@r:embed', $shape ) as $embed ) {
					$out .= isset( $rels[ $embed->value ] ) ? self::image( $zip, $rels[ $embed->value ] ) : '';
				}
			} elseif ( 'graphicFrame' === $shape->localName ) {
				foreach ( $xpath->query( './/a:tbl', $shape ) as $table ) {
					$rows = '';
					foreach ( $xpath->query( 'a:tr', $table ) as $row ) {
						$cells = '';
						foreach ( $xpath->query( 'a:tc', $row ) as $cell ) {
							$parts = array();
							foreach ( $xpath->query( './/a:p', $cell ) as $paragraph ) {
								$parts[] = self::slide_text( $xpath, $paragraph );
							}
							$cells .= '<td>' . implode( '<br>', array_filter( $parts, 'strlen' ) ) . '</td>';
						}
						$rows .= '<tr>' . $cells . '</tr>';
					}
					$out .= '<div class="scroll"><table>' . $rows . '</table></div>';
				}
			}
		}
		return $out;
	}

	private static function slide_text( DOMXPath $xpath, DOMNode $paragraph ) {
		$html = '';
		foreach ( $paragraph->childNodes as $child ) {
			if ( 'r' === $child->localName || 'fld' === $child->localName ) {
				$html .= self::text( $xpath->evaluate( 'string(a:t)', $child ) );
			} elseif ( 'br' === $child->localName ) {
				$html .= '<br>';
			}
		}
		return $html;
	}
}
