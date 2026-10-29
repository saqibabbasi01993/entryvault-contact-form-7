<?php
/**
 * Plugin Name:       EntryVault for Contact Form 7
 * Plugin URI:        https://skfreelancers.com/
 * Description:       Capture, manage, view, and export Contact Form 7 submissions. Includes CFDB7-compatible import/export, full-text search, date filters, pagination, bulk delete, REST API, and analytics dashboard. EntryVault is an independent third-party plugin and is not affiliated with or endorsed by the Contact Form 7 project.
 * Version:           1.4.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  contact-form-7
 * Author:            Saqib K.
 * Author URI:        https://skfreelancers.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       entryvault-contact-form-7
 *
 * @package EntryVault_CF7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EVCF7_VERSION',       '1.4.2' );
define( 'EVCF7_PLUGIN_DIR',    plugin_dir_path( __FILE__ ) );
define( 'EVCF7_PLUGIN_URL',    plugin_dir_url( __FILE__ ) );
define( 'EVCF7_PLUGIN_FILE',   __FILE__ );
define( 'EVCF7_TABLE_ENTRIES', 'cf7_entries' );
define( 'EVCF7_TABLE_META',    'cf7_entry_meta' );

/*
 * BACKWARD-COMPATIBILITY NOTE
 * ============================
 * The following internal identifiers have intentionally been left unchanged
 * from the previous "CF7 Entries Manager & Export" release so that existing
 * installations continue to work without any database migration:
 *
 *  • Database tables  : {prefix}cf7_entries, {prefix}cf7_entry_meta
 *  • Option names     : evcf7_db_version, evcf7_delete_data_on_uninstall
 *  • PHP class names  : EVCF7_*, EVCF7_Plugin
 *  • File names       : class-evcf7-*.php
 *  • Hook names       : evcf7_after_entry_saved, evcf7_entry_data,
 *                       evcf7_after_cfdb7_import
 *  • REST namespace   : cf7em/v1
 *  • Admin page slugs : evcf7-forms, evcf7-entries, evcf7-analytics,
 *                       evcf7-import, evcf7-settings
 *
 * Only user-visible strings (plugin name, admin menu labels, readme.txt)
 * and WordPress.org submission metadata (Text Domain, plugin name header)
 * have been updated to the new "EntryVault for Contact Form 7" branding.
 */

/**
 * Main plugin bootstrap class (singleton).
 *
 * @since 1.0.0
 */
final class EVCF7_Plugin {

	/** @var EVCF7_Plugin|null */
	private static $instance = null;

	/**
	 * Return the singleton instance.
	 *
	 * @return EVCF7_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor – load files and register hooks. */
	private function __construct() {
		$this->load_files();
		add_action( 'init', array( $this, 'boot' ) );
	}

	/** Require all class files. */
	private function load_files() {
		require_once EVCF7_PLUGIN_DIR . 'includes/class-evcf7-database.php';
		require_once EVCF7_PLUGIN_DIR . 'includes/class-evcf7-hooks.php';
		require_once EVCF7_PLUGIN_DIR . 'includes/class-evcf7-export.php';
		require_once EVCF7_PLUGIN_DIR . 'includes/class-evcf7-importer.php';
		require_once EVCF7_PLUGIN_DIR . 'includes/class-evcf7-rest-api.php';
		require_once EVCF7_PLUGIN_DIR . 'admin/class-evcf7-admin.php';
		require_once EVCF7_PLUGIN_DIR . 'admin/class-evcf7-list-table.php';
		require_once EVCF7_PLUGIN_DIR . 'admin/class-evcf7-import-page.php';
		require_once EVCF7_PLUGIN_DIR . 'admin/class-evcf7-settings-page.php';
	}

	/** Instantiate all components after WordPress is fully loaded. */
	public function boot() {
		EVCF7_Hooks::instance();
		EVCF7_Export::instance();
		EVCF7_Importer::instance();
		EVCF7_Rest_API::instance();

		if ( is_admin() ) {
			EVCF7_Admin::instance();
			EVCF7_Import_Page::instance();
			EVCF7_Settings_Page::instance();
		}
	}
}

// ---------------------------------------------------------------------------
// Activation – create / upgrade database tables.
// ---------------------------------------------------------------------------
register_activation_hook(
	__FILE__,
	function () {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-evcf7-database.php';
		EVCF7_Database::create_tables();
		flush_rewrite_rules();
	}
);

// ---------------------------------------------------------------------------
// Deactivation.
// ---------------------------------------------------------------------------
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// ---------------------------------------------------------------------------
// Bootstrap.
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', array( 'EVCF7_Plugin', 'instance' ) );
