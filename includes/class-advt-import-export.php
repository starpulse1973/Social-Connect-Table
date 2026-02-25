<?php
/**
 * Import / Export handler — parses uploaded files into our JSON structure
 * and exports stored table data to CSV, JSON, HTML, and Excel (XLSX) formats.
 *
 * Supported import formats: CSV, JSON, HTML (<table>), Excel (XLSX/XLS).
 * Supported export formats: CSV, JSON, Excel (XLSX).
 *
 * @package AdvancedWPTables
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class Advt_Import_Export {

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		// AJAX handlers (admin only — no nopriv).
		add_action( 'wp_ajax_advt_import_table', [ $this, 'ajax_import' ] );
		add_action( 'wp_ajax_advt_export_table', [ $this, 'ajax_export' ] );
	}

	// =========================================================================
	// IMPORT
	// =========================================================================

	/**
	 * AJAX: Import a file and create a new table (or replace an existing one).
	 *
	 * Expected POST fields:
	 *   _wpnonce       (string) — must verify against 'advt_admin_nonce'.
	 *   import_file     (FILE)  — the uploaded file.
	 *   import_name     (string, optional) — table name override.
	 *   import_target   (int, optional) — existing table ID to replace. 0 = create new.
	 */
	public function ajax_import(): void {
		check_ajax_referer( 'advt_admin_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'advanced-wp-tables' ) ], 403 );
		}

		if ( empty( $_FILES['import_file'] ) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( [ 'message' => __( 'No file uploaded or upload error.', 'advanced-wp-tables' ) ], 400 );
		}

		$file     = $_FILES['import_file'];
		$tmp_path = $file['tmp_name'];
		$filename = sanitize_file_name( $file['name'] );
		$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		// Validate file size (max 10 MB).
		$max_size = 10 * 1024 * 1024;
		if ( $file['size'] > $max_size ) {
			wp_send_json_error( [
				'message' => __( 'File is too large. Maximum size is 10 MB.', 'advanced-wp-tables' ),
			], 400 );
		}

		// Verify temp file is actually an uploaded file.
		if ( ! is_uploaded_file( $tmp_path ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid file upload.', 'advanced-wp-tables' ) ], 400 );
		}

		// Parse the file into a 2D array.
		try {
			$data = match ( $ext ) {
				'csv'                    => $this->parse_csv( $tmp_path ),
				'json'                   => $this->parse_json( $tmp_path ),
				'html', 'htm'            => $this->parse_html( $tmp_path ),
				'xlsx', 'xls'            => $this->parse_excel( $tmp_path ),
				default                  => throw new \RuntimeException(
					sprintf(
						/* translators: %s: file extension */
						__( 'Unsupported file format: .%s', 'advanced-wp-tables' ),
						$ext
					)
				),
			};
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
			return; // Unreachable, but explicit for clarity.
		}

		if ( empty( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'The file contains no usable data.', 'advanced-wp-tables' ) ], 400 );
		}

		// Determine table name.
		$name = ! empty( $_POST['import_name'] )
			? sanitize_text_field( wp_unslash( $_POST['import_name'] ) )
			: pathinfo( $filename, PATHINFO_FILENAME );

		// Create new or replace existing?
		$target_id = ! empty( $_POST['import_target'] ) ? absint( $_POST['import_target'] ) : 0;

		if ( $target_id && Advt_Table_Model::get( $target_id ) ) {
			// Replace existing table data.
			$success = Advt_Table_Model::update( $target_id, [
				'name' => $name,
				'data' => $data,
			] );

			if ( ! $success ) {
				wp_send_json_error( [ 'message' => __( 'Failed to update table.', 'advanced-wp-tables' ) ], 500 );
			}

			wp_send_json_success( [
				'id'      => $target_id,
				'rows'    => count( $data ),
				'cols'    => ! empty( $data[0] ) ? count( $data[0] ) : 0,
				'message' => __( 'Table updated successfully.', 'advanced-wp-tables' ),
			] );
		} else {
			// Create new table.
			$new_id = Advt_Table_Model::insert( [
				'name' => $name,
				'data' => $data,
			] );

			if ( ! $new_id ) {
				wp_send_json_error( [ 'message' => __( 'Failed to create table.', 'advanced-wp-tables' ) ], 500 );
			}

			wp_send_json_success( [
				'id'      => $new_id,
				'rows'    => count( $data ),
				'cols'    => ! empty( $data[0] ) ? count( $data[0] ) : 0,
				'message' => __( 'Table imported successfully.', 'advanced-wp-tables' ),
			] );
		}
	}

	// =========================================================================
	// EXPORT
	// =========================================================================

	/**
	 * AJAX: Export a table to the requested format and stream the file download.
	 *
	 * Expected GET fields:
	 *   _wpnonce      (string)
	 *   table_id      (int)
	 *   format        (string) — csv, json, or xlsx.
	 */
	public function ajax_export(): void {
		// Use GET nonce check since this is a download link.
		check_ajax_referer( 'advt_admin_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'advanced-wp-tables' ), 403 );
		}

		$table_id = isset( $_GET['table_id'] ) ? absint( $_GET['table_id'] ) : 0;
		$format   = isset( $_GET['format'] ) ? sanitize_text_field( $_GET['format'] ) : '';

		if ( ! $table_id ) {
			wp_die( esc_html__( 'Invalid table ID.', 'advanced-wp-tables' ), 400 );
		}

		$table = Advt_Table_Model::get( $table_id );
		if ( ! $table ) {
			wp_die( esc_html__( 'Table not found.', 'advanced-wp-tables' ), 404 );
		}

		$data = json_decode( $table->data, true ) ?: [];
		$name = sanitize_file_name( $table->name ?: 'table-' . $table_id );

		// Evaluate formulas so exports contain computed values, not raw formulas.
		if ( ! empty( $data ) ) {
			$parser = new Advt_Formula_Parser();
			$data   = $parser->process( $data );
		}

		try {
			match ( $format ) {
				'csv'   => $this->export_csv( $data, $name ),
				'json'  => $this->export_json( $data, $name ),
				'xlsx'  => $this->export_xlsx( $data, $name ),
				default => wp_die( esc_html__( 'Unsupported export format.', 'advanced-wp-tables' ), 400 ),
			};
		} catch ( \Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ), 500 );
		}

		exit; // Ensure no extra output after streaming.
	}

	// =========================================================================
	// IMPORT PARSERS
	// =========================================================================

	/**
	 * Parse a CSV file into a 2D array.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function parse_csv( string $path ): array {
		$data   = [];
		$handle = fopen( $path, 'r' );

		if ( ! $handle ) {
			throw new \RuntimeException( __( 'Could not read CSV file.', 'advanced-wp-tables' ) );
		}

		// Detect delimiter by examining the first line.
		$first_line = fgets( $handle );
		rewind( $handle );

		$delimiter = ',';
		if ( $first_line !== false ) {
			$semicolons = substr_count( $first_line, ';' );
			$commas     = substr_count( $first_line, ',' );
			$tabs       = substr_count( $first_line, "\t" );

			if ( $semicolons > $commas && $semicolons > $tabs ) {
				$delimiter = ';';
			} elseif ( $tabs > $commas ) {
				$delimiter = "\t";
			}
		}

		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			// Convert nulls to empty strings.
			$data[] = array_map( function ( $cell ) {
				return $cell !== null ? (string) $cell : '';
			}, $row );
		}

		fclose( $handle );

		return $data;
	}

	/**
	 * Parse a JSON file.
	 *
	 * Accepts either a 2D array (our format) or an array of objects.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function parse_json( string $path ): array {
		$contents = file_get_contents( $path );
		if ( $contents === false ) {
			throw new \RuntimeException( __( 'Could not read JSON file.', 'advanced-wp-tables' ) );
		}

		$parsed = json_decode( $contents, true );

		if ( ! is_array( $parsed ) || empty( $parsed ) ) {
			throw new \RuntimeException( __( 'Invalid or empty JSON data.', 'advanced-wp-tables' ) );
		}

		// Check if it's a 2D array already.
		if ( isset( $parsed[0] ) && is_array( $parsed[0] ) && ! $this->is_assoc( $parsed[0] ) ) {
			// Already in our format — sanitize and return.
			return array_map( function ( $row ) {
				return is_array( $row )
					? array_map( 'strval', array_values( $row ) )
					: [ (string) $row ];
			}, $parsed );
		}

		// Array of associative objects → convert to 2D array with header row.
		if ( isset( $parsed[0] ) && is_array( $parsed[0] ) && $this->is_assoc( $parsed[0] ) ) {
			$headers = array_keys( $parsed[0] );
			$data    = [ $headers ];

			foreach ( $parsed as $obj ) {
				$row = [];
				foreach ( $headers as $key ) {
					$row[] = isset( $obj[ $key ] ) ? (string) $obj[ $key ] : '';
				}
				$data[] = $row;
			}

			return $data;
		}

		throw new \RuntimeException( __( 'Unrecognized JSON structure.', 'advanced-wp-tables' ) );
	}

	/**
	 * Parse an HTML file, extracting the first <table> found.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function parse_html( string $path ): array {
		$contents = file_get_contents( $path );
		if ( $contents === false ) {
			throw new \RuntimeException( __( 'Could not read HTML file.', 'advanced-wp-tables' ) );
		}

		$dom = new \DOMDocument();
		// Suppress warnings from malformed HTML.
		@$dom->loadHTML( '<?xml encoding="UTF-8">' . $contents, LIBXML_NOERROR | LIBXML_NOWARNING );

		$tables = $dom->getElementsByTagName( 'table' );
		if ( $tables->length === 0 ) {
			throw new \RuntimeException( __( 'No <table> element found in the HTML file.', 'advanced-wp-tables' ) );
		}

		$data = [];
		$tbl  = $tables->item( 0 );

		// Process <thead> rows.
		$theads = $tbl->getElementsByTagName( 'thead' );
		if ( $theads->length > 0 ) {
			foreach ( $theads->item( 0 )->getElementsByTagName( 'tr' ) as $tr ) {
				$row = [];
				foreach ( $tr->childNodes as $cell ) {
					if ( $cell->nodeName === 'th' || $cell->nodeName === 'td' ) {
						$row[] = trim( $cell->textContent );
					}
				}
				if ( ! empty( $row ) ) {
					$data[] = $row;
				}
			}
		}

		// Process <tbody> rows (or direct <tr> children if no <tbody>).
		$tbodies = $tbl->getElementsByTagName( 'tbody' );
		$body    = $tbodies->length > 0 ? $tbodies->item( 0 ) : $tbl;

		foreach ( $body->getElementsByTagName( 'tr' ) as $tr ) {
			// Skip rows already captured from <thead>.
			if ( $tr->parentNode->nodeName === 'thead' || $tr->parentNode->nodeName === 'tfoot' ) {
				continue;
			}

			$row = [];
			foreach ( $tr->childNodes as $cell ) {
				if ( $cell->nodeName === 'td' || $cell->nodeName === 'th' ) {
					$row[] = trim( $cell->textContent );
				}
			}
			if ( ! empty( $row ) ) {
				$data[] = $row;
			}
		}

		return $data;
	}

	/**
	 * Parse an Excel file (XLSX/XLS) using PhpSpreadsheet.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function parse_excel( string $path ): array {
		if ( ! class_exists( \PhpOffice\PhpSpreadsheet\IOFactory::class ) ) {
			throw new \RuntimeException(
				__( 'PhpSpreadsheet is not installed. Excel import is unavailable.', 'advanced-wp-tables' )
			);
		}

		$spreadsheet = IOFactory::load( $path );
		$sheet       = $spreadsheet->getActiveSheet();
		$rows        = $sheet->toArray( '', true, true, false );

		// Normalize: ensure all values are strings.
		$data = [];
		foreach ( $rows as $row ) {
			$data[] = array_map( function ( $cell ) {
				return $cell !== null ? (string) $cell : '';
			}, $row );
		}

		return $data;
	}

	// =========================================================================
	// EXPORT WRITERS
	// =========================================================================

	/**
	 * Stream a CSV download.
	 *
	 * @param array<int, array<int, string>> $data
	 */
	private function export_csv( array $data, string $filename ): void {
		$filename = $filename . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		$this->send_download_headers( $filename );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM for Excel compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );

		foreach ( $data as $row ) {
			fputcsv( $output, $row );
		}

		fclose( $output );
	}

	/**
	 * Stream a JSON download.
	 *
	 * @param array<int, array<int, string>> $data
	 */
	private function export_json( array $data, string $filename ): void {
		$filename = $filename . '.json';

		header( 'Content-Type: application/json; charset=UTF-8' );
		$this->send_download_headers( $filename );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Stream an XLSX download using PhpSpreadsheet.
	 *
	 * @param array<int, array<int, string>> $data
	 */
	private function export_xlsx( array $data, string $filename ): void {
		if ( ! class_exists( Spreadsheet::class ) ) {
			throw new \RuntimeException(
				__( 'PhpSpreadsheet is not installed. Excel export is unavailable.', 'advanced-wp-tables' )
			);
		}

		$filename = $filename . '.xlsx';

		$spreadsheet = new Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();

		// Write data to cells.
		foreach ( $data as $rowIdx => $row ) {
			foreach ( $row as $colIdx => $value ) {
				// PhpSpreadsheet is 1-indexed.
				$sheet->setCellValue( [ $colIdx + 1, $rowIdx + 1 ], $value );
			}
		}

		// Auto-size columns for readability.
		$colCount = ! empty( $data[0] ) ? count( $data[0] ) : 0;
		for ( $c = 1; $c <= $colCount; $c++ ) {
			$sheet->getColumnDimensionByColumn( $c )->setAutoSize( true );
		}

		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		$this->send_download_headers( $filename );

		$writer = new XlsxWriter( $spreadsheet );
		$writer->save( 'php://output' );

		$spreadsheet->disconnectWorksheets();
		unset( $spreadsheet );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Send download headers with proper filename encoding (RFC 5987).
	 *
	 * Handles special characters in filenames across all browsers.
	 */
	private function send_download_headers( string $filename ): void {
		// ASCII-safe filename for older clients.
		$ascii_name = preg_replace( '/[^\x20-\x7E]/', '_', $filename );

		// UTF-8 encoded filename (RFC 5987) for modern browsers.
		$utf8_name = rawurlencode( $filename );

		header( 'Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8\'\'' . $utf8_name );
		header( 'Cache-Control: max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
	}

	/**
	 * Check if an array is associative (has string keys).
	 */
	private function is_assoc( array $arr ): bool {
		if ( empty( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
