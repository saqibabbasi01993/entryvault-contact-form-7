/**
 * CF7 Entries Manager – Admin JavaScript
 *
 * Handles:
 *  1. Select-all / deselect-all checkbox (header ↔ row sync)
 *  2. Delete confirmation
 *  3. Auto-dismiss success notices
 *
 * @package EVCF7_Plugin
 */
( function ( $ ) {
	'use strict';

	$( function () {

		/* ===================================================================
		 * 1. SELECT-ALL CHECKBOX (header ↔ rows sync)
		 *
		 * Fully self-contained implementation — does NOT rely on
		 * wp-admin/js/common.js's built-in select-all binding, since that
		 * binding did not reliably reach the row checkboxes on this custom
		 * admin page (table structure differs from core list tables: our
		 * print_column_headers() output produces separate <tbody> groups for
		 * the header row, #the-list, and footer row).
		 *
		 * Both handlers below use event delegation on `document`, so they
		 * work regardless of when the table HTML is present in the DOM and
		 * regardless of what other scripts do.
		 *
		 * Rules:
		 *  • Header checkbox clicked   → check/uncheck every row checkbox
		 *  • Any row checkbox changed  → update header checkbox
		 *                                 (checked / unchecked / indeterminate)
		 * =================================================================== */

		var rowCheckboxSelector  = '#the-list input[name="entry_ids[]"]';
		var headCheckboxSelector = '#cb-select-all-1, #cb-select-all-2';

		/**
		 * Sync the header checkboxes to reflect whether all rows are checked.
		 */
		function cf7emSyncHeader() {
			var $rows   = $( rowCheckboxSelector );
			var total   = $rows.length;
			var checked = $rows.filter( ':checked' ).length;
			var $heads  = $( headCheckboxSelector );

			if ( total === 0 || checked === 0 ) {
				$heads.prop( 'checked', false ).prop( 'indeterminate', false );
			} else if ( checked === total ) {
				$heads.prop( 'checked', true ).prop( 'indeterminate', false );
			} else {
				// Partial selection: indeterminate state.
				$heads.prop( 'checked', false ).prop( 'indeterminate', true );
			}
		}

		/**
		 * Header checkbox clicked → toggle every row checkbox to match.
		 */
		$( document ).on( 'click', headCheckboxSelector, function () {
			var isChecked = $( this ).is( ':checked' );

			// Keep both header checkboxes (thead + tfoot) visually in sync.
			$( headCheckboxSelector ).prop( 'checked', isChecked ).prop( 'indeterminate', false );

			// Apply to every row checkbox.
			$( rowCheckboxSelector ).prop( 'checked', isChecked );
		} );

		/**
		 * Individual row checkbox changed → update header checkbox state.
		 */
		$( document ).on( 'change', rowCheckboxSelector, cf7emSyncHeader );

		// Run once on page load in case the browser restored checked state.
		cf7emSyncHeader();


		/* ===================================================================
		 * 2. DELETE CONFIRMATION
		 * =================================================================== */

		$( document ).on( 'click', '.evcf7-delete-confirm', function ( e ) {
			var msg = ( window.evcf7 && window.evcf7.confirm_delete )
				? window.evcf7.confirm_delete
				: 'Are you sure you want to delete this entry? This cannot be undone.';
			if ( ! window.confirm( msg ) ) {
				e.preventDefault();
			}
		} );


		/* ===================================================================
		 * 3. AUTO-DISMISS SUCCESS NOTICES
		 * =================================================================== */

		setTimeout( function () {
			$( '.notice.is-dismissible.notice-success' ).fadeOut( 600 );
		}, 5000 );

	} );

}( jQuery ) );
