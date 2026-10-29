<?php
/**
 * Uninstall – runs when the plugin is deleted from the WordPress admin.
 *
 * This file is called by WordPress automatically when a user clicks
 * "Delete" on the Plugins screen; it is NOT called on deactivation.
 *
 * Data preservation
 * ------------------
 * By default, EntryVault for Contact Form 7 PRESERVES all stored entries and database
 * tables on uninstall. This lets a user deactivate/delete the plugin and
 * reinstall it later without losing any submission data.
 *
 * Deletion only happens if the user has explicitly opted in via
 * EntryVault CF7 → Settings → "Delete all EntryVault CF7 data when
 * this plugin is deleted", which stores the option
 * `evcf7_delete_data_on_uninstall = 'yes'`.
 *
 * @package EVCF7_Plugin
 * @since   1.0.0
 */

// WordPress calls this file directly, so we must verify the uninstall constant.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Read the user's preference. Default is 'no' – i.e. KEEP existing data.
 * get_option() / delete_option() are the correct WordPress API for the
 * options table (no direct $wpdb queries needed here).
 */
$evcf7_delete_data = 'yes' === get_option( 'evcf7_delete_data_on_uninstall', 'no' );

if ( ! $evcf7_delete_data ) {
	// User has not opted in to data deletion: leave tables and options intact.
	return;
}

global $wpdb;

// Table names use the plugin prefix to satisfy WPCS global-variable naming rules.
$evcf7_entries_table = $wpdb->prefix . 'cf7_entries';
$evcf7_meta_table    = $wpdb->prefix . 'cf7_entry_meta';

/*
 * Table names are built from $wpdb->prefix (a WordPress core value) plus
 * fixed plugin strings – never from user input. DROP TABLE cannot be
 * parameterised with $wpdb->prepare(), so the table names are interpolated
 * directly; this is safe and the standard pattern for uninstall.php files.
 */
$wpdb->query( "DROP TABLE IF EXISTS `{$evcf7_meta_table}`" );    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $evcf7_meta_table is built from $wpdb->prefix + a fixed plugin string, not user input; DROP TABLE has no $wpdb->prepare() placeholder for identifiers.
$wpdb->query( "DROP TABLE IF EXISTS `{$evcf7_entries_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $evcf7_entries_table is built from $wpdb->prefix + a fixed plugin string, not user input; DROP TABLE has no $wpdb->prepare() placeholder for identifiers.

// Remove all plugin options, including the preference itself.
delete_option( 'evcf7_db_version' );
delete_option( 'evcf7_delete_data_on_uninstall' );
