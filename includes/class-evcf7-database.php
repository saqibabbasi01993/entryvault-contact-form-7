<?php
/**
 * Database handler.
 *
 * Tables:
 *   {prefix}cf7_entries    (id, form_id, status, created_at)
 *   {prefix}cf7_entry_meta (id, entry_id, field_name, field_value)
 *
 * SQL safety approach
 * --------------------
 * Every dynamic WHERE fragment that contains a user-supplied value is built
 * with its OWN call to $wpdb->prepare( 'clause = %s', $value ) – e.g.
 * `$wpdb->prepare( 'e.form_id = %d', $form_id )`. Each such call has exactly
 * one literal placeholder and one value, so PHPCS's PreparedSQLPlaceholders
 * sniff can verify it statically with no ambiguity. The resulting fragments
 * are fully-escaped, self-contained SQL strings containing no remaining
 * placeholders.
 *
 * Table names ({$et}, {$mt}) are built from $wpdb->prefix (a WordPress core
 * value) plus fixed plugin constants – never from user input. Interpolating
 * them directly into a SQL string is safe, but the
 * WordPress.DB.PreparedSQL.InterpolatedNotPrepared sniff still flags every
 * line where such interpolation occurs (it cannot distinguish a trusted
 * table-name variable from a user-supplied one). Each such line therefore
 * carries its own inline `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared`
 * comment with a justification.
 *
 * Custom tables are not covered by the WordPress object cache, so
 * DirectDatabaseQuery.NoCaching is also ignored inline with justification.
 *
 * @package EVCF7_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EVCF7_Database
 */
class EVCF7_Database {

	/* -----------------------------------------------------------------------
	 * Schema
	 * -------------------------------------------------------------------- */

	/**
	 * Create or upgrade plugin database tables via dbDelta().
	 * Safe to call repeatedly – dbDelta only applies changes.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$et              = $wpdb->prefix . EVCF7_TABLE_ENTRIES;
		$mt              = $wpdb->prefix . EVCF7_TABLE_META;

		$sql = "
			CREATE TABLE {$et} (
				id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				form_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				status     VARCHAR(20)         NOT NULL DEFAULT 'unread',
				created_at DATETIME            NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY (id),
				KEY idx_form_id    (form_id),
				KEY idx_status     (status),
				KEY idx_created_at (created_at)
			) {$charset_collate};

			CREATE TABLE {$mt} (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				entry_id    BIGINT(20) UNSIGNED NOT NULL,
				field_name  VARCHAR(255)        NOT NULL DEFAULT '',
				field_value LONGTEXT,
				PRIMARY KEY (id),
				KEY idx_entry_id   (entry_id),
				KEY idx_field_name (field_name(191))
			) {$charset_collate};
		";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'evcf7_db_version', EVCF7_VERSION );
	}

	/* -----------------------------------------------------------------------
	 * Write
	 * -------------------------------------------------------------------- */

	/**
	 * Insert one entry row and its associated meta.
	 *
	 * @param int    $form_id CF7 form post ID.
	 * @param array  $fields  Associative array of field_name => value.
	 * @param string $status  'read' or 'unread'.
	 * @param string $date    UTC datetime string (Y-m-d H:i:s). Empty = now.
	 * @return int|false Inserted entry ID, or false on failure.
	 */
	public static function insert_entry( $form_id, array $fields, $status = 'unread', $date = '' ) {
		global $wpdb;

		$et = $wpdb->prefix . EVCF7_TABLE_ENTRIES;
		$mt = $wpdb->prefix . EVCF7_TABLE_META;

		$status = in_array( $status, array( 'read', 'unread' ), true ) ? $status : 'unread';
		$date   = ( $date && preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) )
		          ? $date
		          : current_time( 'mysql', true );

		/*
		 * $wpdb->insert() builds its own parameterised query internally and
		 * is the WordPress-approved API for single-row inserts; no further
		 * escaping is required. Custom table, hence NoCaching ignore.
		 */
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() is the WordPress API for writes; custom table is not covered by the object cache.
			$et,
			array(
				'form_id'    => absint( $form_id ),
				'status'     => $status,
				'created_at' => $date,
			),
			array( '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return false;
		}

		$entry_id = (int) $wpdb->insert_id;

		foreach ( $fields as $name => $value ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() is the WordPress API for writes; custom table is not covered by the object cache.
				$mt,
				array(
					'entry_id'    => $entry_id,
					'field_name'  => sanitize_text_field( $name ),
					'field_value' => is_array( $value )
					                 ? implode( ', ', array_map( 'sanitize_textarea_field', $value ) )
					                 : sanitize_textarea_field( (string) $value ),
				),
				array( '%d', '%s', '%s' )
			);
		}

		return $entry_id;
	}

	/**
	 * Insert a single meta row for an existing entry.
	 *
	 * Used internally to store extra data alongside an entry — e.g. the
	 * mail-delivery status ('_mail_status' => 'mail_sent'|'mail_failed').
	 * Keys that begin with an underscore are treated as private/internal.
	 *
	 * @param int    $entry_id    Entry ID.
	 * @param string $field_name  Meta key (e.g. '_mail_status').
	 * @param string $field_value Meta value.
	 * @return bool True on success, false on failure.
	 */
	public static function insert_entry_meta( $entry_id, $field_name, $field_value ) {
		global $wpdb;

		$mt = $wpdb->prefix . EVCF7_TABLE_META;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() is the WordPress API for writes; custom table is not covered by the object cache.
			$mt,
			array(
				'entry_id'    => absint( $entry_id ),
				'field_name'  => sanitize_text_field( $field_name ),
				'field_value' => sanitize_textarea_field( (string) $field_value ),
			),
			array( '%d', '%s', '%s' )
		);

		return (bool) $ok;
	}

	/**
	 * Update the status of a single entry.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $status   'read' or 'unread'.
	 * @return bool
	 */
	public static function update_status( $entry_id, $status ) {
		global $wpdb;

		$status = in_array( $status, array( 'read', 'unread' ), true ) ? $status : 'unread';

		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() is the WordPress API for writes; custom table is not covered by the object cache.
			$wpdb->prefix . EVCF7_TABLE_ENTRIES,
			array( 'status' => $status ),
			array( 'id'     => absint( $entry_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete entries and their meta rows.
	 *
	 * @param int|int[] $entry_ids One or more entry IDs.
	 * @return int Number of entry rows deleted.
	 */
	public static function delete_entries( $entry_ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', (array) $entry_ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$et = $wpdb->prefix . EVCF7_TABLE_ENTRIES;
		$mt = $wpdb->prefix . EVCF7_TABLE_META;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		/*
		 * Each prepare() call here has exactly N '%d' placeholders (from
		 * $placeholders) and an N-element $ids array, so PHPCS can verify
		 * the count statically. {$mt}/{$et} are trusted table names
		 * (wpdb prefix + plugin constant), flagged inline below.
		 */
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, not covered by object cache.
			$wpdb->prepare(
				"DELETE FROM `{$mt}` WHERE entry_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$mt} is a trusted table name (wpdb prefix + plugin constant), not user input; {$placeholders} contains '%d' placeholders (one per element of $ids); PHPCS cannot statically expand this variable to see them, hence the UnfinishedPrepare ignore.
				$ids
			)
		);

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, not covered by object cache.
			$wpdb->prepare(
				"DELETE FROM `{$et}` WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$et} is a trusted table name (wpdb prefix + plugin constant), not user input; {$placeholders} contains '%d' placeholders (one per element of $ids); PHPCS cannot statically expand this variable to see them, hence the UnfinishedPrepare ignore.
				$ids
			)
		);

		return (int) $deleted;
	}

	/* -----------------------------------------------------------------------
	 * Read – paged list
	 * -------------------------------------------------------------------- */

	/**
	 * Build a fully-escaped WHERE clause (including the "WHERE" keyword)
	 * from filter args.
	 *
	 * Every condition is added via its own single-placeholder
	 * $wpdb->prepare() call, so all user-supplied values are safely
	 * quoted/escaped. The final returned string contains no remaining
	 * %s/%d placeholders – it is ready to be appended to a query as-is.
	 *
	 * @param array  $args Same filter keys as get_entries().
	 * @param string $mt   Meta table name (prefix + constant; trusted).
	 * @return string A " WHERE ..." clause, e.g. " WHERE 1=1 AND e.form_id = 5".
	 */
	private static function build_where_clause( array $args, $mt ) {
		global $wpdb;

		$where = '1=1';

		if ( ! empty( $args['form_id'] ) ) {
			$where .= ' AND ' . $wpdb->prepare( 'e.form_id = %d', absint( $args['form_id'] ) );
		}

		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( 'read', 'unread' ), true ) ) {
			$where .= ' AND ' . $wpdb->prepare( 'e.status = %s', $args['status'] );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where .= ' AND ' . $wpdb->prepare( 'DATE(e.created_at) >= %s', sanitize_text_field( $args['date_from'] ) );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where .= ' AND ' . $wpdb->prepare( 'DATE(e.created_at) <= %s', sanitize_text_field( $args['date_to'] ) );
		}

		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';

			$where .= ' AND ' . $wpdb->prepare(
				"e.id IN ( SELECT entry_id FROM `{$mt}` WHERE field_value LIKE %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$mt} is a trusted table name (wpdb prefix + plugin constant), not user input; the only user-controlled value uses the %s placeholder.
				$like
			);
		}

		return ' WHERE ' . $where;
	}

	/**
	 * Return a paged list of entries (shallow rows, no meta).
	 *
	 * @param array $args {
	 *   @type int    $form_id   Filter by CF7 form post ID. Default 0 (all).
	 *   @type string $status    'read', 'unread', or '' (all). Default ''.
	 *   @type string $date_from MySQL date string Y-m-d. Default ''.
	 *   @type string $date_to   MySQL date string Y-m-d. Default ''.
	 *   @type string $search    Keyword matched against meta field values.
	 *   @type int    $per_page  Rows per page. Default 55.
	 *   @type int    $page      1-based page number. Default 1.
	 *   @type string $orderby   Column name. Default 'created_at'.
	 *   @type string $order     'ASC' or 'DESC'. Default 'DESC'.
	 * }
	 * @return array { entries: object[], total: int }
	 */
	public static function get_entries( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'form_id'   => 0,
				'status'    => '',
				'date_from' => '',
				'date_to'   => '',
				'search'    => '',
				'per_page'  => 55,
				'page'      => 1,
				'orderby'   => 'created_at',
				'order'     => 'DESC',
			)
		);

		$et = $wpdb->prefix . EVCF7_TABLE_ENTRIES;
		$mt = $wpdb->prefix . EVCF7_TABLE_META;

		// Whitelist orderby and order to prevent SQL injection.
		$allowed_orderby = array( 'id', 'form_id', 'status', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		// Integers only – safe to interpolate directly.
		$pp     = max( 1, (int) $args['per_page'] );
		$offset = ( max( 1, (int) $args['page'] ) - 1 ) * $pp;

		/*
		 * Build the WHERE clause ONCE as a complete, fully-escaped string
		 * (including the "WHERE" keyword). Every value inside it has already
		 * been through $wpdb->prepare( 'clause = %s/%d', $value ) inside
		 * build_where_clause() – the returned string contains no remaining
		 * placeholders.
		 */
		$where_clause = self::build_where_clause( $args, $mt );

		/*
		 * Count query.
		 * The complete query string is assembled in its own statement and
		 * the resulting bare variable ($count_query) is passed directly to
		 * get_var() – no function calls or concatenation happen inside the
		 * get_var() argument itself.
		 * {$et} is a trusted table name (wpdb prefix + plugin constant).
		 */
		$count_query = "SELECT COUNT(DISTINCT e.id) FROM `{$et}` e" . $where_clause; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- {$et} is a trusted table name (wpdb prefix + plugin constant), not user input; $where_clause is fully pre-escaped via $wpdb->prepare() in build_where_clause() and contains no remaining placeholders or raw user input.

		$total = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table not covered by object cache; $count_query is a complete pre-escaped string built above (see comment there) and passed here as a bare variable with no further concatenation.

		/*
		 * List query – same approach: assemble the complete string first,
		 * then pass the bare variable to get_results().
		 * {$et}      – trusted table name.
		 * {$orderby} – whitelisted against $allowed_orderby above.
		 * {$order}   – hardcoded to 'ASC' or 'DESC' above.
		 * $pp / $offset are cast to (int) and concatenated as literals.
		 */
		$list_query = 'SELECT DISTINCT e.id, e.form_id, e.status, e.created_at'
			. " FROM `{$et}` e" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$et} is a trusted table name (wpdb prefix + plugin constant), not user input.
			. $where_clause // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_clause is fully pre-escaped via $wpdb->prepare() in build_where_clause() and contains no remaining placeholders or raw user input.
			. " ORDER BY e.`{$orderby}` {$order}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$orderby} is whitelisted against a fixed allow-list ($allowed_orderby) above; {$order} is hardcoded to 'ASC' or 'DESC' above. Neither contains user input.
			. ' LIMIT ' . (int) $pp . ' OFFSET ' . (int) $offset; // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $list_query is assembled entirely from: a trusted table name, $where_clause (pre-escaped via $wpdb->prepare() in build_where_clause()), $orderby/$order (whitelisted/hardcoded above), and (int)-cast integers. No raw user input is interpolated.

		$entries = $wpdb->get_results( $list_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table not covered by object cache; $list_query is a complete pre-escaped string built above (see comments there) and passed here as a bare variable with no further concatenation.

		return array(
			'entries' => $entries ? $entries : array(),
			'total'   => $total,
		);
	}

	/* -----------------------------------------------------------------------
	 * Read – list with meta (batch-loaded for the list table)
	 * -------------------------------------------------------------------- */

	/**
	 * Like get_entries() but also attaches meta fields to every entry row.
	 * Loads all meta for the current page in a single query (no N+1).
	 *
	 * @param array $args Same as get_entries().
	 * @return array { entries: object[], total: int, field_order: string[] }
	 */
	public static function get_entries_with_meta( array $args = array() ) {
		global $wpdb;

		$result  = self::get_entries( $args );
		$entries = $result['entries'];

		if ( empty( $entries ) ) {
			return array_merge( $result, array( 'field_order' => array() ) );
		}

		$ids = array_values( array_map( 'intval', wp_list_pluck( $entries, 'id' ) ) );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$mt  = $wpdb->prefix . EVCF7_TABLE_META;

		$meta_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, not covered by object cache.
			$wpdb->prepare(
				"SELECT entry_id, field_name, field_value FROM `{$mt}` WHERE entry_id IN ({$ph}) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$mt} is a trusted table name (wpdb prefix + plugin constant), not user input; {$ph} contains '%d' placeholders (one per element of $ids); PHPCS cannot statically expand this variable to see them, hence the UnfinishedPrepare ignore.
				$ids
			)
		);

		// Index meta by entry_id and collect unique visible field names.
		$meta_map    = array();
		$seen_fields = array();

		foreach ( $meta_rows as $row ) {
			$eid = (int) $row->entry_id;
			if ( ! isset( $meta_map[ $eid ] ) ) {
				$meta_map[ $eid ] = array();
			}
			$meta_map[ $eid ][ $row->field_name ] = $row->field_value;

			// Skip internal (underscore-prefixed) fields from column detection.
			if ( '_' !== substr( $row->field_name, 0, 1 ) ) {
				$seen_fields[ $row->field_name ] = true;
			}
		}

		foreach ( $entries as $entry ) {
			$entry->fields = isset( $meta_map[ (int) $entry->id ] ) ? $meta_map[ (int) $entry->id ] : array();
		}

		// Order: standard CF7 fields first, extras in discovery order.
		$standard = array( 'your-name', 'name', 'your-email', 'email', 'your-subject', 'subject', 'your-message', 'message' );
		$ordered  = array();

		foreach ( $standard as $key ) {
			if ( isset( $seen_fields[ $key ] ) ) {
				$ordered[] = $key;
				unset( $seen_fields[ $key ] );
			}
		}
		foreach ( array_keys( $seen_fields ) as $key ) {
			$ordered[] = $key;
		}

		return array_merge( $result, array( 'field_order' => $ordered ) );
	}

	/* -----------------------------------------------------------------------
	 * Read – single entry with all meta
	 * -------------------------------------------------------------------- */

	/**
	 * Retrieve one entry row with all its meta fields attached as ->fields.
	 *
	 * @param int $entry_id Entry ID.
	 * @return object|null Entry object with ->fields array, or null if not found.
	 */
	public static function get_entry( $entry_id ) {
		global $wpdb;

		$et = $wpdb->prefix . EVCF7_TABLE_ENTRIES;
		$mt = $wpdb->prefix . EVCF7_TABLE_META;

		$entry = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, not covered by object cache.
			$wpdb->prepare(
				"SELECT * FROM `{$et}` WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$et} is a trusted table name (wpdb prefix + plugin constant), not user input.
				absint( $entry_id )
			)
		);

		if ( ! $entry ) {
			return null;
		}

		$meta = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, not covered by object cache.
			$wpdb->prepare(
				"SELECT field_name, field_value FROM `{$mt}` WHERE entry_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$mt} is a trusted table name (wpdb prefix + plugin constant), not user input.
				absint( $entry_id )
			)
		);

		$entry->fields = array();
		foreach ( $meta as $row ) {
			$entry->fields[ $row->field_name ] = $row->field_value;
		}

		return $entry;
	}

	/* -----------------------------------------------------------------------
	 * Read – all entries for export (no pagination)
	 * -------------------------------------------------------------------- */

	/**
	 * Return all matching entries with meta attached (used for CSV/JSON export).
	 *
	 * @param array $args Same filter keys as get_entries(). Pagination ignored.
	 * @return object[] Entries with ->fields populated.
	 */
	public static function get_entries_for_export( array $args = array() ) {
		$args['per_page'] = 9999999;
		$args['page']     = 1;
		$result           = self::get_entries_with_meta( $args );
		return $result['entries'];
	}

	/* -----------------------------------------------------------------------
	 * Analytics
	 * -------------------------------------------------------------------- */

	/**
	 * Return per-form submission counts including unread totals.
	 *
	 * Uses CASE/WHEN instead of the MySQL-only SUM(col='value') shorthand
	 * for full SQL-mode compatibility.
	 *
	 * @return object[] Objects with form_id, total, unread.
	 */
	public static function get_analytics() {
		global $wpdb;

		$et = $wpdb->prefix . EVCF7_TABLE_ENTRIES;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom table not covered by object cache; query contains no user-supplied values (see inline note below).
			'SELECT form_id,'
			. ' COUNT(*) AS total,'
			. " SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) AS unread"
			. " FROM `{$et}`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$et} is a trusted table name (wpdb prefix + plugin constant), not user input.
			. ' GROUP BY form_id'
			. ' ORDER BY total DESC'
		);
	}

	/**
	 * Return all distinct form IDs that have at least one entry.
	 *
	 * @return int[]
	 */
	public static function get_form_ids_with_entries() {
		global $wpdb;

		$et = $wpdb->prefix . EVCF7_TABLE_ENTRIES;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom table not covered by object cache; query contains no user-supplied values (see inline note below).
			"SELECT DISTINCT form_id FROM `{$et}` ORDER BY form_id ASC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$et} is a trusted table name (wpdb prefix + plugin constant), not user input.
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Check whether a CFDB7 original Id has already been imported.
	 * Prevents duplicate imports when the same CSV is uploaded twice.
	 *
	 * @param int $cfdb7_id Original CFDB7 submission Id.
	 * @return bool
	 */
	public static function cfdb7_id_exists( $cfdb7_id ) {
		global $wpdb;

		$mt = $wpdb->prefix . EVCF7_TABLE_META;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, not covered by object cache.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$mt}` WHERE field_name = '_cfdb7_id' AND field_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$mt} is a trusted table name (wpdb prefix + plugin constant), not user input.
				(string) absint( $cfdb7_id )
			)
		);
	}
}
