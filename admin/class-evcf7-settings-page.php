<?php
/**
 * Settings page – controls plugin-wide options.
 *
 * Currently provides a single option:
 *   evcf7_delete_data_on_uninstall ('yes'|'no', default 'no')
 *
 * When set to 'no' (the default), uninstall.php leaves the custom database
 * tables (`{prefix}cf7_entries`, `{prefix}cf7_entry_meta`) and all stored
 * entries untouched if the plugin is deleted from the Plugins screen. The
 * user must explicitly opt in to data deletion.
 *
 * @package EVCF7_Plugin
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EVCF7_Settings_Page
 */
class EVCF7_Settings_Page {

	/** @var EVCF7_Settings_Page|null */
	private static $instance = null;

	/** Option name storing the uninstall-data-deletion preference. */
	const OPTION_DELETE_ON_UNINSTALL = 'evcf7_delete_data_on_uninstall';

	/**
	 * Return singleton instance.
	 *
	 * @return EVCF7_Settings_Page
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor. */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Register the Settings sub-page under the EntryVault CF7 menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'evcf7-forms',
			__( 'Settings', 'entryvault-contact-form-7' ),
			__( 'Settings', 'entryvault-contact-form-7' ),
			'manage_options',
			'evcf7-settings',
			array( $this, 'render' )
		);
	}

	/**
	 * Return whether stored entries should be deleted when the plugin is
	 * uninstalled. Defaults to false (data is preserved).
	 *
	 * @return bool
	 */
	public static function delete_data_on_uninstall() {
		return 'yes' === get_option( self::OPTION_DELETE_ON_UNINSTALL, 'no' );
	}

	/**
	 * Handle the settings form submission (POST).
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_POST['evcf7_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'entryvault-contact-form-7' ) );
		}

		// Verifies $_POST['_wpnonce'] against 'evcf7_settings'. Dies on failure.
		check_admin_referer( 'evcf7_settings' );

		// Checkbox: present in $_POST only when checked.
		$delete_on_uninstall = isset( $_POST['evcf7_delete_data_on_uninstall'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().

		update_option( self::OPTION_DELETE_ON_UNINSTALL, $delete_on_uninstall );

		wp_safe_redirect( add_query_arg( array( 'page' => 'evcf7-settings', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only one-time notice appended by wp_safe_redirect() after a nonce-verified save; nothing is changed here.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'entryvault-contact-form-7' ) . '</p></div>';
		}

		$delete_on_uninstall = self::delete_data_on_uninstall();
		?>
		<div class="wrap evcf7-wrap">
			<h1><?php esc_html_e( 'EntryVault CF7 – Settings', 'entryvault-contact-form-7' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evcf7-settings' ) ); ?>">
				<?php wp_nonce_field( 'evcf7_settings' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Data on Uninstall', 'entryvault-contact-form-7' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">
									<?php esc_html_e( 'Data on Uninstall', 'entryvault-contact-form-7' ); ?>
								</legend>
								<label for="evcf7_delete_data_on_uninstall">
									<input
										type="checkbox"
										id="evcf7_delete_data_on_uninstall"
										name="evcf7_delete_data_on_uninstall"
										value="1"
										<?php checked( $delete_on_uninstall ); ?>
									>
									<?php esc_html_e( 'Delete all EntryVault CF7 data when this plugin is deleted', 'entryvault-contact-form-7' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'By default, this is unchecked: your saved entries and database tables are kept even if you deactivate and delete this plugin, so reinstalling it later restores access to your existing data.', 'entryvault-contact-form-7' ); ?>
									<br>
									<strong><?php esc_html_e( 'If you check this box and delete the plugin, all submitted entries will be permanently removed and cannot be recovered.', 'entryvault-contact-form-7' ); ?></strong>
								</p>
							</fieldset>
						</td>
					</tr>
					</tbody>
				</table>

				<p class="submit">
					<input
						type="submit"
						name="evcf7_settings_submit"
						class="button button-primary"
						value="<?php esc_attr_e( 'Save Changes', 'entryvault-contact-form-7' ); ?>"
					>
				</p>
			</form>
		</div>
		<?php
	}
}
