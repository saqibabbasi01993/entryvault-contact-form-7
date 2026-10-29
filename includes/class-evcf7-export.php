<?php
/**
 * Export handler – CFDB7-compatible CSV + JSON download.
 *
 * CSV column order: Id, Date, Status, Name, Email, Subject, Message [, extras…]
 * This matches CFDB7's export format exactly for round-trip compatibility.
 *
 * fwrite() / fclose() notes
 * -------------------------
 * WPCS flags fwrite() and fclose() as "file system operations" even when the
 * stream is php://output (an HTTP response stream, not a disk file). WP_Filesystem
 * is the correct alternative for reading and writing files on disk, but it has no
 * equivalent for streaming data directly to the browser. The phpcs:ignore comments
 * below are therefore intentional and correct; they are scoped to the minimum
 * number of lines and include the rule name and a plain-English justification.
 *
 * @package EVCF7_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EVCF7_Export
 */
class EVCF7_Export {

	/** @var EVCF7_Export|null */
	private static $instance = null;

	/**
	 * Return singleton instance.
	 *
	 * @return EVCF7_Export
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor. */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'handle' ) );
	}

	/**
	 * Handle an export request (fires on admin_init).
	 *
	 * @return void
	 */
	public function handle() {
		// Only act when our trigger parameter is present.
		if ( ! isset( $_GET['evcf7_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below before any action
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'entryvault-contact-form-7' ) );
		}

		// Verify nonce before reading any other parameters.
		if (
			! isset( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'evcf7_export' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'entryvault-contact-form-7' ) );
		}

		// All reads below are safe: nonce verified, capability checked.
		$format = sanitize_key( $_GET['evcf7_export'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
		$fid    = isset( $_GET['fid'] ) ? absint( $_GET['fid'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above

		$args = array(
			'form_id'   => $fid,
			'status'    => isset( $_GET['status'] )    ? sanitize_key( $_GET['status'] )                         : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
			'date_to'   => isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
			'search'    => isset( $_GET['s'] )          ? sanitize_text_field( wp_unslash( $_GET['s'] ) )         : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
		);

		$entries = EVCF7_Database::get_entries_for_export( $args );

		if ( 'csv' === $format ) {
			$this->stream_csv( $entries );
		} elseif ( 'json' === $format ) {
			$this->stream_json( $entries );
		} else {
			wp_die( esc_html__( 'Unknown export format.', 'entryvault-contact-form-7' ) );
		}
	}

	/* -----------------------------------------------------------------------
	 * CSV output
	 * -------------------------------------------------------------------- */

	/**
	 * Stream a CFDB7-compatible CSV file to the browser.
	 *
	 * We open php://output (an HTTP response stream) and write to it directly
	 * using fopen/fwrite/fputcsv/fclose. WP_Filesystem is not used because it
	 * is designed for disk I/O and provides no method for writing to an HTTP
	 * response stream. The phpcs:ignore comments are intentional; the WPCS rule
	 * WordPress.WP.AlternativeFunctions.file_system_operations_fwrite and _fclose
	 * does not apply to php://output streams.
	 *
	 * @param object[] $entries Entries with ->fields populated.
	 * @return void
	 */
	private function stream_csv( array $entries ) {
		// Build ordered list of field names (standard CF7 fields first).
		$standard    = array( 'your-name', 'name', 'your-email', 'email', 'your-subject', 'subject', 'your-message', 'message' );
		$all_fields  = array();

		foreach ( $entries as $entry ) {
			if ( empty( $entry->fields ) ) {
				continue;
			}
			foreach ( array_keys( $entry->fields ) as $field ) {
				// Skip internal underscore-prefixed fields.
				if ( '_' !== substr( $field, 0, 1 ) ) {
					$all_fields[ $field ] = true;
				}
			}
		}

		uksort(
			$all_fields,
			function ( $a, $b ) use ( $standard ) {
				$ai = array_search( $a, $standard, true );
				$bi = array_search( $b, $standard, true );
				if ( false !== $ai && false !== $bi ) { return $ai - $bi; }
				if ( false !== $ai ) { return -1; }
				if ( false !== $bi ) { return 1; }
				return strcmp( $a, $b );
			}
		);

		$field_names = array_keys( $all_fields );

		// CFDB7-compatible human labels for the header row.
		$label_map = array(
			'your-name'    => 'Name',
			'your-email'   => 'Email',
			'your-subject' => 'Subject',
			'your-message' => 'Message',
			'name'         => 'Name',
			'email'        => 'Email',
			'subject'      => 'Subject',
			'message'      => 'Message',
		);

		$header = array( 'Id', 'Date', 'Status' );
		foreach ( $field_names as $field ) {
			$header[] = isset( $label_map[ $field ] ) ? $label_map[ $field ] : ucwords( str_replace( '-', ' ', $field ) );
		}

		// Send HTTP headers before any output.
		$filename = 'cf7-entries-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		/*
		 * Open php://output for writing.
		 * phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		 * Reason: php://output is an HTTP response stream, not a disk file.
		 * WP_Filesystem has no method for streaming data to the browser.
		 */
		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		// Write UTF-8 BOM so Excel opens the file with correct encoding.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		// Reason: Writing to php://output (HTTP stream), not a filesystem file.
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		fputcsv( $handle, $header );

		foreach ( $entries as $entry ) {
			$row = array(
				isset( $entry->fields['_cfdb7_id'] ) ? $entry->fields['_cfdb7_id'] : $entry->id,
				$entry->created_at,
				$entry->status,
			);
			foreach ( $field_names as $field ) {
				$row[] = isset( $entry->fields[ $field ] ) ? $entry->fields[ $field ] : '';
			}
			fputcsv( $handle, $row );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		// Reason: Closing the php://output HTTP stream, not a filesystem file.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}

	/* -----------------------------------------------------------------------
	 * JSON output
	 * -------------------------------------------------------------------- */

	/**
	 * Stream a JSON file to the browser.
	 *
	 * @param object[] $entries Entries with ->fields populated.
	 * @return void
	 */
	private function stream_json( array $entries ) {
		$data = array();

		foreach ( $entries as $entry ) {
			$fields = isset( $entry->fields ) ? $entry->fields : array();

			// Remove internal underscore-prefixed fields from the output.
			$public_fields = array();
			foreach ( $fields as $key => $value ) {
				if ( '_' !== substr( $key, 0, 1 ) ) {
					$public_fields[ $key ] = $value;
				}
			}

			$data[] = array(
				'id'           => (int) $entry->id,
				'cfdb7_id'     => isset( $fields['_cfdb7_id'] ) ? (int) $fields['_cfdb7_id'] : null,
				'form_id'      => (int) $entry->form_id,
				'form_title'   => get_the_title( (int) $entry->form_id ) ?: null,
				'status'       => $entry->status,
				'submitted_on' => $entry->created_at,
				'fields'       => (object) $public_fields,
			);
		}

		$filename = 'cf7-entries-' . gmdate( 'Y-m-d' ) . '.json';
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
