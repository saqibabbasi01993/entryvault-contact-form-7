<?php
/**
 * Entries list table.
 *
 * Mirrors CFDB7 exactly:
 *   [cb] | Name | Email | Subject | Message | Date ↓
 *
 * Performance: meta for the entire current page is loaded in ONE query
 * inside prepare_items(), not once per cell.
 *
 * @package EVCF7_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class EVCF7_List_Table extends WP_List_Table {

	/** @var int  Form ID filter (0 = all). */
	private $fid = 0;

	/** @var int  Total rows matching current filters. */
	private $total_items = 0;

	/** @var int  Rows per page. */
	private $per_page = 55;

	/**
	 * Ordered list of field names to display as columns.
	 * Standard CF7 fields first, extras appended.
	 *
	 * @var string[]
	 */
	private $field_order = array();

	/**
	 * Cache: entry_id => field_name => value.
	 * Populated once in prepare_items() to avoid N+1 queries.
	 *
	 * @var array
	 */
	private $meta_cache = array();

	public function __construct( $fid = 0 ) {
		parent::__construct( array( 'singular' => 'entry', 'plural' => 'entries', 'ajax' => false ) );
		$this->fid = absint( $fid );
	}

	/** Total rows (after filters). */
	public function get_total() { return $this->total_items; }

	/** Rows per page. */
	public function get_per_page() { return $this->per_page; }

	// ── Column definitions ────────────────────────────────────────────────────

	public function get_columns() {
		$standard_labels = array(
			'your-name'    => __( 'Name',    'entryvault-contact-form-7' ),
			'name'         => __( 'Name',    'entryvault-contact-form-7' ),
			'your-email'   => __( 'Email',   'entryvault-contact-form-7' ),
			'email'        => __( 'Email',   'entryvault-contact-form-7' ),
			'your-subject' => __( 'Subject', 'entryvault-contact-form-7' ),
			'subject'      => __( 'Subject', 'entryvault-contact-form-7' ),
			'your-message' => __( 'Message', 'entryvault-contact-form-7' ),
			'message'      => __( 'Message', 'entryvault-contact-form-7' ),
		);

		$cols = array( 'cb' => '<input type="checkbox">' );
		foreach ( $this->field_order as $field ) {
			$label = isset( $standard_labels[ $field ] )
			         ? $standard_labels[ $field ]
			         : ucwords( str_replace( array( '-', '_' ), ' ', $field ) );
			$cols[ 'field_' . $field ] = $label;
		}
		$cols['created_at'] = __( 'Date', 'entryvault-contact-form-7' );
		return $cols;
	}

	protected function get_sortable_columns() {
		return array( 'created_at' => array( 'created_at', true ) );
	}

	protected function get_bulk_actions() {
		return array( 'bulk_delete' => __( 'Delete', 'entryvault-contact-form-7' ) );
	}

	// ── Data loading ──────────────────────────────────────────────────────────

	/**
	 * Build the entries query from the current request and load this page's data.
	 *
	 * All $_GET reads below are read-only list-table filter/sort parameters
	 * (search, date range, status, orderby, order, pagination) supplied by the
	 * filter form in EVCF7_Admin::render_entries_page(). They do not change any
	 * data, matching how WordPress core's own list tables read $_GET without a
	 * nonce (e.g. edit.php?orderby=title&order=asc&paged=2).
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->per_page = $this->get_items_per_page( 'evcf7_entries_per_page', 55 );

		$args = array(
			'form_id'   => $this->fid,
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter; no data is changed.
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter; no data is changed.
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter; no data is changed.
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter; no data is changed.
			'orderby'   => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort parameter; no data is changed.
			'order'     => ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort parameter; no data is changed.
			'per_page'  => $this->per_page,
			'page'      => $this->get_pagenum(),
		);

		// Validate status.
		if ( ! in_array( $args['status'], array( 'read', 'unread' ), true ) ) {
			$args['status'] = '';
		}

		// One query for entries + meta.
		$result            = EVCF7_Database::get_entries_with_meta( $args );
		$this->items       = $result['entries'];
		$this->total_items = (int) $result['total'];
		$this->field_order = $result['field_order'];

		// Build meta cache for column renderers.
		$this->meta_cache = array();
		foreach ( $this->items as $entry ) {
			$this->meta_cache[ (int) $entry->id ] = isset( $entry->fields ) ? $entry->fields : array();
		}

		// If no entries exist yet, fall back to standard column order.
		if ( empty( $this->field_order ) ) {
			$this->field_order = array( 'your-name', 'your-email', 'your-subject', 'your-message' );
		}

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args( array(
			'total_items' => $this->total_items,
			'per_page'    => $this->per_page,
			'total_pages' => (int) ceil( $this->total_items / $this->per_page ),
		) );
	}

	// ── Column renderers ──────────────────────────────────────────────────────

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="entry_ids[]" value="%d">', absint( $item->id ) );
	}

	protected function column_created_at( $item ) {
		$url = $this->view_url( (int) $item->id );
		return '<a href="' . esc_url( $url ) . '" class="evcf7-date-link">' . esc_html( $item->created_at ) . '</a>';
	}

	protected function column_default( $item, $col ) {
		if ( 0 !== strpos( $col, 'field_' ) ) {
			return isset( $item->$col ) ? esc_html( $item->$col ) : '—';
		}

		$field = substr( $col, 6 ); // strip 'field_'
		$value = isset( $this->meta_cache[ (int) $item->id ][ $field ] )
		         ? $this->meta_cache[ (int) $item->id ][ $field ]
		         : '';

		// Name cell = clickable link (CFDB7 style).
		if ( in_array( $field, array( 'your-name', 'name' ), true ) ) {
			return '<a href="' . esc_url( $this->view_url( (int) $item->id ) ) . '" class="evcf7-row-link">'
			       . esc_html( $value )
			       . '</a>';
		}

		$display = wp_trim_words( $value, 12, '…' );
		return '<span title="' . esc_attr( $value ) . '">' . esc_html( $display ) . '</span>';
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function view_url( $entry_id ) {
		return add_query_arg( array(
			'page'     => 'evcf7-entries',
			'fid'      => $this->fid,
			'action'   => 'view',
			'entry_id' => $entry_id,
		), admin_url( 'admin.php' ) );
	}

	// ── Display ───────────────────────────────────────────────────────────────

	/**
	 * Render the complete list table using WP_List_Table's own display()
	 * which outputs: top tablenav (bulk actions + pagination), the table
	 * itself with thead/tbody/tfoot, and the bottom tablenav.
	 * No manual bulk-action or pagination calls needed from the template.
	 */
	public function display() {
		wp_nonce_field( 'bulk-entries' );
		parent::display();
	}
}
