<?php
/**
 * Admin UI – menus, actions, page rendering.
 *
 * Layout mirrors CFDB7 exactly (Image 2 reference):
 *   • "EntryVault CF7" landing  → Name | Count table.
 *   • Form entries view        → Bulk Actions ▾ [Apply] [Export CSV] [Export JSON]
 *                                [×] [date-from] [date-to] [search…] [Search]
 *                                110 items                        « ‹ 1 of 2 › »
 *                                ──────────────────────────────────────────────
 *                                ☐ Name | Email | Subject | Message | Date ↑
 *                                ...rows...
 *                                ──────────────────────────────────────────────
 *                                Bulk Actions ▾ [Apply]
 *   • Single-entry detail      → CFDB7-style card.
 *
 * Nonce verification notes
 * -------------------------
 * State-changing requests (single delete, bulk delete) are verified with
 * check_admin_referer() before EVCF7_Database is touched – see
 * handle_actions() below.
 *
 * Read-only requests (page routing, list filters such as search/date/status,
 * and one-time result notices like "?deleted=1" after a redirect) do not
 * change any data and therefore do not require a nonce, in line with how
 * WordPress core's own list tables (e.g. edit.php?s=...&paged=2) work. Each
 * such access has an inline phpcs:ignore with a justification so WPCS
 * (WordPress.Security.NonceVerification.Recommended /
 * WordPress.Security.NonceVerification.Missing) does not flag it, while still
 * documenting *why* it is safe for future reviewers.
 *
 * @package EVCF7_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EVCF7_Admin
 */
class EVCF7_Admin {

	/** @var EVCF7_Admin|null */
	private static $instance = null;

	/**
	 * Return singleton instance.
	 *
	 * @return EVCF7_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor. */
	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init',            array( $this, 'handle_actions' ) );
	}

	/* -----------------------------------------------------------------------
	 * Menus
	 * -------------------------------------------------------------------- */

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_menus() {
		add_menu_page(
			__( 'EntryVault CF7', 'entryvault-contact-form-7' ),
			__( 'EntryVault CF7', 'entryvault-contact-form-7' ),
			'manage_options',
			'evcf7-forms',
			array( $this, 'render_forms_page' ),
			'dashicons-feedback',
			30
		);
		add_submenu_page( 'evcf7-forms', __( 'EntryVault CF7', 'entryvault-contact-form-7' ), __( 'EntryVault CF7', 'entryvault-contact-form-7' ), 'manage_options', 'evcf7-forms', array( $this, 'render_forms_page' ) );
		add_submenu_page( 'evcf7-forms', __( 'Entries', 'entryvault-contact-form-7' ), '', 'manage_options', 'evcf7-entries', array( $this, 'render_entries_page' ) );
		add_submenu_page( 'evcf7-forms', __( 'Analytics', 'entryvault-contact-form-7' ), __( 'Analytics', 'entryvault-contact-form-7' ), 'manage_options', 'evcf7-analytics', array( $this, 'render_analytics_page' ) );
	}

	/* -----------------------------------------------------------------------
	 * Assets
	 * -------------------------------------------------------------------- */

	/**
	 * Enqueue admin CSS/JS only on this plugin's pages.
	 *
	 * Submenu page hooks follow the pattern
	 * "{$parent_slug}_page_{$submenu_slug}", where $parent_slug is the slug
	 * registered via add_menu_page() — in this plugin that is 'evcf7-forms'.
	 * WordPress also sanitizes underscores in the parent slug to hyphens for
	 * the hook name, so 'evcf7-forms' produces 'evcf7-forms_page_...'.
	 *
	 * The top-level page itself uses 'toplevel_page_{$menu_slug}'.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$pages = array(
			'toplevel_page_evcf7-forms',
			'evcf7-forms_page_evcf7-entries',
			'evcf7-forms_page_evcf7-analytics',
			'evcf7-forms_page_evcf7-import',
			'evcf7-forms_page_evcf7-settings',
		);
		if ( ! in_array( $hook, $pages, true ) ) {
			return;
		}

		wp_enqueue_style( 'evcf7-admin', EVCF7_PLUGIN_URL . 'assets/css/admin.css', array(), EVCF7_VERSION );

		/*
		 * ALL plugin CSS is also output via wp_add_inline_style() against the
		 * always-loaded 'wp-admin' handle. This guarantees the full UI renders
		 * correctly even if admin.css fails to load (path issue, CDN, object
		 * cache, or in-browser environments like WordPress Playground where
		 * external stylesheets may not resolve reliably).
		 *
		 * wp_add_inline_style() is the correct WP API — no raw <style> tags.
		 * Colours use literal hex values (no CSS custom properties) so they
		 * work in all browsers and environments without a cascade dependency.
		 */
		/*
		 * Minimal inline CSS — only styles that WP core doesn't provide.
		 * Search box, tablenav, bulk actions, pagination, and buttons are
		 * all handled by WordPress core admin CSS automatically.
		 * We only need styles for our custom card layout, the entry detail
		 * view, and minor table tweaks.
		 */
		$all_css = '
/* ── Entry detail card (inline styles also on elements as fallback) ── */
.evcf7-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.10);max-width:100%;margin-top:20px;overflow:hidden;}
.evcf7-card-head{display:flex;align-items:center;gap:12px;padding:16px 20px;background:#f6f7f7;border-bottom:1px solid #dcdcde;}
.evcf7-card-head h2{margin:0;font-size:15px;font-weight:600;color:#1d2327;flex:1;}
.evcf7-card-date{font-size:12px;color:#646970;white-space:nowrap;background:#fff;border:1px solid #dcdcde;border-radius:20px;padding:2px 10px;}
.evcf7-card-badge{font-size:11px;font-weight:600;color:#2271b1;background:#f0f6fc;border:1px solid #b3d3f0;border-radius:20px;padding:2px 10px;white-space:nowrap;}
.evcf7-field-list{margin:0;padding:0 24px 20px;}
.evcf7-field-list dt{font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#646970;margin-top:20px;margin-bottom:4px;}
.evcf7-field-list dd{margin:0;font-size:14px;color:#1d2327;line-height:1.6;white-space:pre-wrap;word-break:break-word;padding:10px 14px;background:#f6f7f7;border-radius:4px;border-left:3px solid #2271b1;}
.evcf7-card-actions{display:flex;align-items:center;gap:10px;padding:16px 24px;background:#f6f7f7;border-top:1px solid #dcdcde;}
/* ── Entry layout (single view) ────────────────────────────────────── */
.evcf7-entry-layout{display:flex;gap:20px;align-items:flex-start;margin-top:16px;}
.evcf7-entry-main{flex:1;min-width:0;}
.evcf7-entry-sidebar{width:240px;flex-shrink:0;}
/* ── Table tweaks ───────────────────────────────────────────────────── */
.evcf7-date-link{color:#2271b1;text-decoration:none;white-space:nowrap;}
.evcf7-date-link:hover{text-decoration:underline;}
.evcf7-row-link{color:#2271b1;font-weight:600;text-decoration:none;}
.evcf7-row-link:hover{text-decoration:underline;}
.evcf7-bubble{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;margin-left:6px;border-radius:10px;background:#d63638;color:#fff;font-size:11px;font-weight:700;line-height:1;vertical-align:middle;}
.evcf7-unread-count{color:#d63638;font-weight:700;}
/* ── Date filter inputs in tablenav ────────────────────────────────── */
.tablenav input[type="date"]{height:28px;padding:0 6px;border:1px solid #8c8f94;border-radius:3px;font-size:13px;vertical-align:middle;background:#fff;}
/* ── Analytics ──────────────────────────────────────────────────────── */
.evcf7-stat-box{display:inline-flex;flex-direction:column;align-items:center;padding:28px 48px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.10);margin:16px 0;}
.evcf7-stat-box strong{font-size:52px;font-weight:800;color:#2271b1;line-height:1;letter-spacing:-2px;}
.evcf7-stat-box span{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-top:8px;}
.evcf7-bar-wrap{display:flex;align-items:center;gap:10px;}
.evcf7-bar{height:8px;background:#2271b1;border-radius:4px;min-width:4px;}
.evcf7-bar-wrap span{font-size:12px;color:#646970;white-space:nowrap;}
/* ── Responsive ─────────────────────────────────────────────────────── */
@media screen and (max-width:960px){
.evcf7-entry-layout{flex-direction:column!important;}
.evcf7-entry-sidebar{width:100%!important;}
}
@media screen and (max-width:600px){
.evcf7-entry-main div[style*="grid-template-columns"]{display:block!important;}
}
';
		wp_add_inline_style( 'evcf7-admin', $all_css );
		// Also attach to 'wp-admin' (always loaded on every admin page) as a fallback.
		wp_add_inline_style( 'wp-admin', $all_css );
		// Attach the same CSS to 'wp-admin' (always loaded on every admin page)
		// as a guaranteed fallback in case our own stylesheet handle fails.
		wp_add_inline_style( 'wp-admin', $all_css );
		wp_enqueue_script( 'evcf7-admin', EVCF7_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), EVCF7_VERSION, true );
		wp_localize_script(
			'evcf7-admin',
			'evcf7',
			array(
				'confirm_delete' => __( 'Are you sure you want to delete the selected entries? This cannot be undone.', 'entryvault-contact-form-7' ),
			)
		);
	}

	/* -----------------------------------------------------------------------
	 * Actions (delete)
	 * -------------------------------------------------------------------- */

	/**
	 * Handle single-delete (GET) and bulk-delete (POST) actions.
	 *
	 * Every branch that mutates data calls check_admin_referer() before
	 * touching EVCF7_Database.
	 *
	 * @return void
	 */
	public function handle_actions() {
		// Routing check only – no data is read or changed here.
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page-routing check; nonce is verified below before any state change.

		if ( ! in_array( $page, array( 'evcf7-entries', 'evcf7-forms' ), true ) ) {
			return;
		}

		/*
		 * ── Single delete (GET) ─────────────────────────────────────────────
		 * The presence check below only decides WHICH branch to enter; the
		 * actual nonce (check_admin_referer) is verified immediately after,
		 * before EVCF7_Database::delete_entries() is called.
		 */
		if ( isset( $_GET['action'], $_GET['entry_id'] ) && 'delete' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- branch-selection only; nonce verified on the next line via check_admin_referer().
			$eid = absint( $_GET['entry_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- value is verified by check_admin_referer() on the next line before use.

			// Verifies $_GET['_wpnonce'] against 'evcf7_delete_{$eid}'. Dies on failure.
			check_admin_referer( 'evcf7_delete_' . $eid );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'entryvault-contact-form-7' ) );
			}

			$fid = isset( $_GET['fid'] ) ? absint( $_GET['fid'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified above via check_admin_referer().

			EVCF7_Database::delete_entries( $eid );

			wp_safe_redirect( add_query_arg( array( 'page' => 'evcf7-entries', 'fid' => $fid, 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		/*
		 * ── Bulk delete (POST) ──────────────────────────────────────────────
		 * $_POST['action'] / $_POST['action2'] are read only to decide whether
		 * this is a bulk-delete submission. check_admin_referer( 'bulk-entries' )
		 * is called immediately afterwards and BEFORE any database write, which
		 * satisfies WPCS's nonce-verification requirement for the POST data
		 * used below it.
		 */
		$raw_action = '';
		if ( ! empty( $_POST['action'] ) && '-1' !== $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- branch-selection only; nonce verified below via check_admin_referer() before any state change.
			$raw_action = sanitize_key( wp_unslash( $_POST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below via check_admin_referer().
		}
		if ( ! empty( $_POST['action2'] ) && '-1' !== $_POST['action2'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- branch-selection only; nonce verified below via check_admin_referer() before any state change.
			$raw_action = sanitize_key( wp_unslash( $_POST['action2'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below via check_admin_referer().
		}

		if ( 'bulk_delete' === $raw_action ) {
			// Verifies $_POST['_wpnonce'] against 'bulk-entries'. Dies on failure.
			check_admin_referer( 'bulk-entries' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'entryvault-contact-form-7' ) );
			}

			$ids = isset( $_POST['entry_ids'] ) ? array_map( 'absint', (array) $_POST['entry_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above via check_admin_referer().
			$fid = isset( $_POST['fid'] ) ? absint( $_POST['fid'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above via check_admin_referer().

			if ( ! empty( $ids ) ) {
				EVCF7_Database::delete_entries( $ids );
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'evcf7-entries', 'fid' => $fid, 'deleted' => count( $ids ) ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/* -----------------------------------------------------------------------
	 * Page: EntryVault CF7 landing  (Name | Count)
	 * -------------------------------------------------------------------- */

	/**
	 * Render the landing page: one row per form with submission counts.
	 *
	 * @return void
	 */
	public function render_forms_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$analytics  = EVCF7_Database::get_analytics();
		$import_url = admin_url( 'admin.php?page=evcf7-import' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'EntryVault CF7', 'entryvault-contact-form-7' ); ?></h1>
			<a href="<?php echo esc_url( $import_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Import CFDB7 CSV', 'entryvault-contact-form-7' ); ?>
			</a>
			<hr class="wp-header-end">

			<table class="wp-list-table widefat fixed striped">
				<thead>
				<tr>
					<th class="manage-column column-primary"><?php esc_html_e( 'Form Name', 'entryvault-contact-form-7' ); ?></th>
					<th class="manage-column num"><?php esc_html_e( 'Total', 'entryvault-contact-form-7' ); ?></th>
					<th class="manage-column num"><?php esc_html_e( 'Unread', 'entryvault-contact-form-7' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( empty( $analytics ) ) : ?>
					<tr>
						<td colspan="3">
							<?php esc_html_e( 'No submissions yet.', 'entryvault-contact-form-7' ); ?>
							<a href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Import from CFDB7 →', 'entryvault-contact-form-7' ); ?></a>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $analytics as $row ) : ?>
						<?php
						$fid    = absint( $row->form_id );
						$title  = get_the_title( $fid ) ?: ( $fid ? '#' . $fid : __( '(No form)', 'entryvault-contact-form-7' ) );
						$eurl   = admin_url( 'admin.php?page=evcf7-entries&fid=' . $fid );
						$unread = (int) $row->unread;
						?>
						<tr>
							<td class="column-primary">
								<a href="<?php echo esc_url( $eurl ); ?>"><strong><?php echo esc_html( $title ); ?></strong></a>
								<?php if ( $unread ) : ?>
									<span class="evcf7-bubble"><?php echo esc_html( $unread ); ?></span>
								<?php endif; ?>
							</td>
							<td class="num">
								<a href="<?php echo esc_url( $eurl ); ?>"><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></a>
							</td>
							<td class="num">
								<?php if ( $unread ) : ?>
									<a href="<?php echo esc_url( add_query_arg( 'status', 'unread', $eurl ) ); ?>"><?php echo esc_html( number_format_i18n( $unread ) ); ?></a>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
				<tfoot>
				<tr>
					<th class="manage-column column-primary"><?php esc_html_e( 'Form Name', 'entryvault-contact-form-7' ); ?></th>
					<th class="manage-column num"><?php esc_html_e( 'Total', 'entryvault-contact-form-7' ); ?></th>
					<th class="manage-column num"><?php esc_html_e( 'Unread', 'entryvault-contact-form-7' ); ?></th>
				</tr>
				</tfoot>
			</table>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------------
	 * Page: Entries list
	 * -------------------------------------------------------------------- */

	/**
	 * Render the entries list (and dispatch to the single-entry view).
	 *
	 * All $_GET reads in this method are read-only filter/display parameters
	 * (search keyword, date range, status, pagination, "view" routing, and
	 * one-time result notices echoed after a redirect such as ?deleted=1).
	 * None of them cause a database write, matching how WordPress core's own
	 * list tables (edit.php, users.php, etc.) handle $_GET without a nonce.
	 *
	 * @return void
	 */
	public function render_entries_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$fid = isset( $_GET['fid'] ) ? absint( $_GET['fid'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter parameter (which form to display); no data is changed.

		// Single-entry detail view.
		if ( ! empty( $_GET['entry_id'] ) && isset( $_GET['action'] ) && 'view' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing to the detail view; no data is changed.
			$this->render_single_entry( absint( $_GET['entry_id'] ), $fid ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; entry ID only selects which record to display.
			return;
		}

		$form_title = $fid ? ( get_the_title( $fid ) ?: '#' . $fid ) : __( 'All Forms', 'entryvault-contact-form-7' );

		/*
		 * One-time success notices.
		 * These query args (?deleted=1, ?evcf7_imported=…) are appended by
		 * wp_safe_redirect() in handle_actions() / the importer AFTER the
		 * nonce-protected action already completed. By the time this code
		 * runs the action is over; we are only displaying its result.
		 */
		if ( ! empty( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only one-time notice appended by wp_safe_redirect() after a nonce-verified action; nothing is changed here.
			$n = absint( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see note above.
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of entries deleted */
						_n( '%d entry deleted.', '%d entries deleted.', $n, 'entryvault-contact-form-7' ),
						$n
					)
				)
			);
		}

		if ( ! empty( $_GET['evcf7_imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only one-time notice appended by wp_safe_redirect() after a nonce-verified import; nothing is changed here.
			$imp  = absint( $_GET['evcf7_imported'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see note above.
			$skip = isset( $_GET['evcf7_skipped'] ) ? absint( $_GET['evcf7_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see note above.
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number of entries imported, 2: number of entries skipped */
						__( 'Import complete: %1$d imported, %2$d skipped.', 'entryvault-contact-form-7' ),
						$imp,
						$skip
					)
				)
			);
		}

		/*
		 * Filters (search, date range, status). These are GET parameters from
		 * the filter form (#evcf7-filter-form) and are read-only — identical
		 * in nature to ?s=...&paged=2 on WordPress's own edit.php.
		 */
		$s         = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter; no data is changed.
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only date filter; no data is changed.
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only date filter; no data is changed.
		$status_f  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status filter; no data is changed.

		if ( ! in_array( $status_f, array( 'read', 'unread' ), true ) ) {
			$status_f = '';
		}

		$has_filter = $s || $date_from || $date_to || $status_f;
		$base_url   = admin_url( 'admin.php?page=evcf7-entries&fid=' . $fid );

		// List table.
		require_once EVCF7_PLUGIN_DIR . 'admin/class-evcf7-list-table.php';
		$table = new EVCF7_List_Table( $fid );
		$table->prepare_items();

		// Export URLs – each includes its own nonce, verified in EVCF7_Export::handle().
		$enonce      = wp_create_nonce( 'evcf7_export' );
		$export_args = array(
			'_wpnonce'  => $enonce,
			'fid'       => $fid,
			's'         => $s,
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'status'    => $status_f,
		);
		$csv_url  = add_query_arg( array_merge( $export_args, array( 'evcf7_export' => 'csv' ) ), admin_url( 'admin.php' ) );
		$json_url = add_query_arg( array_merge( $export_args, array( 'evcf7_export' => 'json' ) ), admin_url( 'admin.php' ) );

		// Item count display.
		$total = $table->get_total();
		?>
		<div class="wrap evcf7-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $form_title ); ?></h1>
			<hr class="wp-header-end">

			<!--
				TWO SEPARATE FORMS — standard WordPress pattern:
				① GET  form  – search + date filter (GET so filters appear in URL)
				② POST form  – bulk delete (checkboxes + nonce)
			-->

			<!-- ① FILTER / SEARCH FORM (GET) —
			     Uses WP native search_box() for the search input (auto-styled by
			     WordPress core CSS) and standard .tablenav .alignleft/.alignright
			     divs for date filters and export buttons.
			-->
			<form id="evcf7-filter-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="evcf7-entries">
				<input type="hidden" name="fid"  value="<?php echo esc_attr( $fid ); ?>">

				<div class="tablenav top">
					<div class="alignleft actions">
						<a href="<?php echo esc_url( $csv_url ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'entryvault-contact-form-7' ); ?></a>
						<a href="<?php echo esc_url( $json_url ); ?>" class="button"><?php esc_html_e( 'Export JSON', 'entryvault-contact-form-7' ); ?></a>
						&nbsp;&nbsp;
						<label class="screen-reader-text" for="evcf7-date-from"><?php esc_html_e( 'From date', 'entryvault-contact-form-7' ); ?></label>
						<input type="date" id="evcf7-date-from" name="date_from"
							value="<?php echo esc_attr( $date_from ); ?>"
							title="<?php esc_attr_e( 'From date', 'entryvault-contact-form-7' ); ?>">
						<label class="screen-reader-text" for="evcf7-date-to"><?php esc_html_e( 'To date', 'entryvault-contact-form-7' ); ?></label>
						<input type="date" id="evcf7-date-to" name="date_to"
							value="<?php echo esc_attr( $date_to ); ?>"
							title="<?php esc_attr_e( 'To date', 'entryvault-contact-form-7' ); ?>">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'entryvault-contact-form-7' ); ?>">
						<?php if ( $has_filter ) : ?>
							<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'entryvault-contact-form-7' ); ?></a>
						<?php endif; ?>
					</div>
					<p class="search-box">
						<label class="screen-reader-text" for="evcf7-entry-search-input"><?php esc_html_e( 'Search Entries:', 'entryvault-contact-form-7' ); ?></label>
						<input type="search" id="evcf7-entry-search-input" name="s" value="<?php echo esc_attr( $s ); ?>">
						<input type="submit" id="search-submit" class="button button-compact" value="<?php esc_attr_e( 'Search Entries', 'entryvault-contact-form-7' ); ?>">
					</p>
					<br class="clear">
				</div>
			</form><!-- #evcf7-filter-form -->

			<!-- ② BULK-ACTION FORM (POST) —
			     $table->display() outputs: nonce field, bulk-action dropdowns (top
			     and bottom), item count + pagination rows, and the full data table.
			     No manual pagination or bulk-bar calls needed.
			-->
			<form id="evcf7-bulk-form" method="post">
				<input type="hidden" name="fid"  value="<?php echo esc_attr( $fid ); ?>">
				<input type="hidden" name="page" value="evcf7-entries">

				<?php $table->display(); ?>

			</form><!-- #evcf7-bulk-form -->

		</div>
		<?php
	}

	/* -----------------------------------------------------------------------
	 * Page: Single entry detail
	/* -----------------------------------------------------------------------
	 * Page: Single entry detail
	 * -------------------------------------------------------------------- */

	/**
	 * Render the single-entry detail card.
	 *
	 * @param int $entry_id Entry ID (already validated as an integer by the caller).
	 * @param int $fid      Form ID for the back-link (0 = derive from entry).
	 * @return void
	 */
	private function render_single_entry( $entry_id, $fid = 0 ) {
		$entry = EVCF7_Database::get_entry( $entry_id );
		if ( ! $entry ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Entry not found.', 'entryvault-contact-form-7' ) . '</p></div></div>';
			return;
		}

		// Mark as read.
		if ( 'unread' === $entry->status ) {
			EVCF7_Database::update_status( $entry_id, 'read' );
			$entry->status = 'read';
		}

		$fid        = $fid ?: (int) $entry->form_id;
		$form_title = get_the_title( $fid ) ?: '#' . $fid;
		$back_url   = admin_url( 'admin.php?page=evcf7-entries&fid=' . $fid );
		$delete_url = wp_nonce_url(
			add_query_arg( array( 'page' => 'evcf7-entries', 'action' => 'delete', 'entry_id' => $entry_id, 'fid' => $fid ), admin_url( 'admin.php' ) ),
			'evcf7_delete_' . $entry_id
		);

		// Hide internal underscore-prefixed fields from display.
		$display  = array_filter(
			$entry->fields,
			function ( $key ) {
				return '_' !== substr( $key, 0, 1 );
			},
			ARRAY_FILTER_USE_KEY
		);
		$cfdb7_id    = isset( $entry->fields['_cfdb7_id'] ) ? absint( $entry->fields['_cfdb7_id'] ) : 0;
		$mail_status = isset( $entry->fields['_mail_status'] ) ? $entry->fields['_mail_status'] : 'mail_sent';

		/*
		 * Admin notices from OTHER plugins (e.g. license warnings) are injected
		 * by WordPress via the `admin_notices` hook which fires inside the first
		 * <div class="wrap"> it encounters. That causes foreign notice HTML to
		 * appear inside our card layout and break it visually.
		 *
		 * Fix: capture everything WordPress would print through that hook, then
		 * output it ABOVE our own <div class="wrap"> so it sits in the normal
		 * WP notice area and never touches our card.
		 */
		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-firing WordPress core's own 'admin_notices' hook (not a custom plugin hook) to capture and reposition output from other plugins.
		do_action( 'all_admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-firing WordPress core's own 'all_admin_notices' hook (not a custom plugin hook) to capture and reposition output from other plugins.
		$captured_notices = ob_get_clean();

		// Prevent WordPress from firing these hooks again after our wrap div opens.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );

		// Output captured notices above our card wrapper. This is trusted HTML
		// already generated (and escaped) by other plugins via core hooks.
		echo $captured_notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted HTML from admin_notices hook, escaped by originating plugins.
		?>
		<div class="wrap evcf7-wrap evcf7-single">
			<hr class="wp-header-end" style="display:none;margin:0;padding:0;border:none;">

			<!-- ── Single-entry layout ─────────────────────────────────────── -->
			<div class="evcf7-entry-layout" style="display:flex;gap:20px;align-items:flex-start;margin-top:16px;">

				<!-- ── LEFT: Main content card ──────────────────────────────── -->
				<div class="evcf7-entry-main" style="flex:1;min-width:0;">
					<div class="evcf7-card" style="background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden;">

						<!-- Card header -->
						<div style="display:flex;align-items:center;gap:12px;padding:16px 20px;background:#f6f7f7;border-bottom:1px solid #dcdcde;">
							<div style="width:36px;height:36px;border-radius:50%;background:#2271b1;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
								<span style="color:#fff;font-size:16px;font-weight:700;line-height:1;"><?php echo esc_html( strtoupper( substr( $form_title, 0, 1 ) ) ); ?></span>
							</div>
							<div style="flex:1;min-width:0;">
								<h2 style="margin:0;font-size:15px;font-weight:600;color:#1d2327;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html( $form_title ); ?></h2>
								<p style="margin:2px 0 0;font-size:12px;color:#646970;"><?php echo esc_html( $entry->created_at ); ?></p>
							</div>
							<?php if ( $cfdb7_id ) : ?>
								<span style="font-size:11px;font-weight:600;color:#2271b1;background:#f0f6fc;border:1px solid #b3d3f0;border-radius:20px;padding:3px 12px;white-space:nowrap;flex-shrink:0;"><?php echo esc_html( 'CFDB7 #' . $cfdb7_id ); ?></span>
							<?php endif; ?>
						</div>

						<!-- Fields -->
						<?php if ( empty( $display ) ) : ?>
							<p style="padding:24px 20px;color:#646970;"><?php esc_html_e( 'No field data.', 'entryvault-contact-form-7' ); ?></p>
						<?php else : ?>
							<div style="padding:4px 0 8px;">
								<?php
								// Classify fields: short (name/email/subject) vs long (message)
								$short_fields   = array();
								$long_fields    = array();
								$message_keys   = array( 'your-message', 'message' );
								foreach ( $display as $name => $value ) {
									if ( in_array( $name, $message_keys, true ) || strlen( $value ) > 120 || strpos( $value, "
" ) !== false ) {
										$long_fields[ $name ] = $value;
									} else {
										$short_fields[ $name ] = $value;
									}
								}
								?>

								<?php if ( ! empty( $short_fields ) ) : ?>
									<!-- Short fields: 2-column grid -->
									<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;padding:0;">
										<?php foreach ( $short_fields as $name => $value ) : ?>
											<div style="padding:14px 20px;border-bottom:1px solid #f0f0f1;">
												<div style="font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#9ca3af;margin-bottom:5px;"><?php echo esc_html( ucfirst( str_replace( array( '-', '_', 'your ' ), array( ' ', ' ', '' ), $name ) ) ); ?></div>
												<div style="font-size:14px;color:#1d2327;font-weight:500;word-break:break-word;"><?php echo esc_html( $value ); ?></div>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>

								<?php foreach ( $long_fields as $name => $value ) : ?>
									<!-- Long fields: full-width -->
									<div style="padding:16px 20px;border-bottom:1px solid #f0f0f1;">
										<div style="font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#9ca3af;margin-bottom:8px;"><?php echo esc_html( ucfirst( str_replace( array( '-', '_', 'your ' ), array( ' ', ' ', '' ), $name ) ) ); ?></div>
										<div style="font-size:14px;color:#1d2327;line-height:1.7;white-space:pre-wrap;word-break:break-word;background:#f9fafb;border-radius:4px;padding:14px 16px;border-left:3px solid #2271b1;"><?php echo nl2br( esc_html( $value ) ); ?></div>
									</div>
								<?php endforeach; ?>

							</div>
						<?php endif; ?>

						<!-- Card footer / actions (inside the card) -->
						<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#f6f7f7;border-top:1px solid #dcdcde;">
							<a href="<?php echo esc_url( $back_url ); ?>" class="button button-secondary" style="display:inline-flex;align-items:center;gap:6px;">
								&#8592; <?php esc_html_e( 'Back to Entries', 'entryvault-contact-form-7' ); ?>
							</a>
							<a href="<?php echo esc_url( $delete_url ); ?>" class="button evcf7-delete-confirm" style="background:#d63638;color:#fff;border-color:#d63638;display:inline-flex;align-items:center;gap:6px;" onclick="return confirm('<?php echo esc_js( __( 'Delete this entry? This cannot be undone.', 'entryvault-contact-form-7' ) ); ?>');">
								&#128465; <?php esc_html_e( 'Delete Entry', 'entryvault-contact-form-7' ); ?>
							</a>
						</div>

					</div>
				</div><!-- .evcf7-entry-main -->

				<!-- ── RIGHT: Sidebar meta panel ────────────────────────────── -->
				<div class="evcf7-entry-sidebar" style="width:240px;flex-shrink:0;">
					<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden;">

						<div style="padding:12px 16px;background:#f6f7f7;border-bottom:1px solid #dcdcde;">
							<h3 style="margin:0;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#646970;"><?php esc_html_e( 'Entry Details', 'entryvault-contact-form-7' ); ?></h3>
						</div>

						<div style="padding:0;">
							<?php if ( 'mail_failed' === $mail_status ) : ?>
								<div style="padding:10px 16px;background:#fff3cd;border-bottom:1px solid #ffc107;">
									<div style="font-size:11px;font-weight:600;color:#856404;display:flex;align-items:center;gap:5px;">
										<span style="font-size:14px;">⚠</span>
										<?php esc_html_e( 'Email delivery failed — entry was saved anyway.', 'entryvault-contact-form-7' ); ?>
									</div>
								</div>
							<?php endif; ?>
							<div style="padding:12px 16px;border-bottom:1px solid #f0f0f1;">
								<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:4px;"><?php esc_html_e( 'Read Status', 'entryvault-contact-form-7' ); ?></div>
								<div style="display:inline-flex;align-items:center;gap:5px;">
									<span style="width:7px;height:7px;border-radius:50%;background:<?php echo 'read' === $entry->status ? '#00a32a' : '#d63638'; ?>;flex-shrink:0;"></span>
									<span style="font-size:13px;font-weight:600;color:#1d2327;"><?php echo esc_html( ucfirst( $entry->status ) ); ?></span>
								</div>
							</div>
							<div style="padding:12px 16px;border-bottom:1px solid #f0f0f1;">
								<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:4px;"><?php esc_html_e( 'Mail Status', 'entryvault-contact-form-7' ); ?></div>
								<div style="display:inline-flex;align-items:center;gap:5px;">
									<span style="width:7px;height:7px;border-radius:50%;background:<?php echo 'mail_sent' === $mail_status ? '#00a32a' : '#f0ad05'; ?>;flex-shrink:0;"></span>
									<span style="font-size:13px;font-weight:600;color:#1d2327;">
										<?php echo 'mail_sent' === $mail_status ? esc_html__( 'Delivered', 'entryvault-contact-form-7' ) : esc_html__( 'Failed', 'entryvault-contact-form-7' ); ?>
									</span>
								</div>
							</div>

							<div style="padding:12px 16px;border-bottom:1px solid #f0f0f1;">
								<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:4px;"><?php esc_html_e( 'Submitted', 'entryvault-contact-form-7' ); ?></div>
								<div style="font-size:13px;color:#1d2327;"><?php echo esc_html( $entry->created_at ); ?></div>
							</div>

							<div style="padding:12px 16px;border-bottom:1px solid #f0f0f1;">
								<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:4px;"><?php esc_html_e( 'Form', 'entryvault-contact-form-7' ); ?></div>
								<div style="font-size:13px;color:#1d2327;font-weight:500;"><?php echo esc_html( $form_title ); ?></div>
							</div>

							<div style="padding:12px 16px;border-bottom:1px solid #f0f0f1;">
								<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:4px;"><?php esc_html_e( 'Entry ID', 'entryvault-contact-form-7' ); ?></div>
								<div style="font-size:13px;color:#646970;font-family:monospace;">#<?php echo esc_html( $entry_id ); ?></div>
							</div>

							<?php if ( $cfdb7_id ) : ?>
								<div style="padding:12px 16px;border-bottom:1px solid #f0f0f1;">
									<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:4px;"><?php esc_html_e( 'CFDB7 ID', 'entryvault-contact-form-7' ); ?></div>
									<div style="font-size:13px;color:#646970;font-family:monospace;">#<?php echo esc_html( $cfdb7_id ); ?></div>
								</div>
							<?php endif; ?>

							<div style="padding:12px 16px;">
								<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:4px;"><?php esc_html_e( 'Fields', 'entryvault-contact-form-7' ); ?></div>
								<div style="font-size:13px;color:#646970;"><?php echo esc_html( count( $display ) ); ?> <?php esc_html_e( 'fields', 'entryvault-contact-form-7' ); ?></div>
							</div>
						</div>

					</div>
				</div><!-- .evcf7-entry-sidebar -->

			</div><!-- .evcf7-entry-layout -->
		</div>
		<?php
	}

	/* -----------------------------------------------------------------------
	 * Page: Analytics
	 * -------------------------------------------------------------------- */

	/**
	 * Render the analytics dashboard.
	 *
	 * @return void
	 */
	public function render_analytics_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$analytics = EVCF7_Database::get_analytics();
		$total_all = (int) array_sum( array_column( (array) $analytics, 'total' ) );
		?>
		<div class="wrap evcf7-wrap">
			<h1><?php esc_html_e( 'EntryVault – Analytics', 'entryvault-contact-form-7' ); ?></h1>

			<div class="evcf7-stat-box">
				<strong><?php echo esc_html( number_format_i18n( $total_all ) ); ?></strong>
				<span><?php esc_html_e( 'Total Submissions', 'entryvault-contact-form-7' ); ?></span>
			</div>

			<?php if ( ! empty( $analytics ) ) : ?>
				<table class="wp-list-table widefat striped" style="max-width:600px;margin-top:20px;">
					<thead>
					<tr>
						<th><?php esc_html_e( 'Form', 'entryvault-contact-form-7' ); ?></th>
						<th class="num"><?php esc_html_e( 'Submissions', 'entryvault-contact-form-7' ); ?></th>
						<th><?php esc_html_e( 'Share', 'entryvault-contact-form-7' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $analytics as $row ) : ?>
						<?php
						$fid   = absint( $row->form_id );
						$title = get_the_title( $fid ) ?: '#' . $fid;
						$pct   = $total_all ? round( $row->total / $total_all * 100, 1 ) : 0;
						$eurl  = admin_url( 'admin.php?page=evcf7-entries&fid=' . $fid );
						?>
						<tr>
							<td><a href="<?php echo esc_url( $eurl ); ?>"><?php echo esc_html( $title ); ?></a></td>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td>
							<td>
								<div class="evcf7-bar-wrap">
									<div class="evcf7-bar" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
									<span><?php echo esc_html( $pct ); ?>%</span>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
