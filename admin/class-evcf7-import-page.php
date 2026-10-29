<?php
/**
 * Import page – admin UI for the CFDB7 CSV importer.
 *
 * @package EVCF7_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class EVCF7_Import_Page {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'evcf7-forms',
			__( 'Import CSV', 'entryvault-contact-form-7' ),
			__( 'Import CSV', 'entryvault-contact-form-7' ),
			'manage_options',
			'evcf7-import',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the Import CSV admin page.
	 *
	 * The $_GET reads below are one-time result notices (?evcf7_imported=…,
	 * ?evcf7_err=…) appended by wp_safe_redirect() in EVCF7_Importer AFTER its
	 * own nonce-protected upload handler has already run. By the time this
	 * page renders, the action is complete; these values are only used to
	 * display its outcome and never trigger a data change.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// ── Result / error notices ────────────────────────────────────────────
		if ( isset( $_GET['evcf7_imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only one-time notice appended by wp_safe_redirect() after a nonce-verified import; nothing is changed here.
			$imp  = absint( $_GET['evcf7_imported'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see note above.
			$skip = isset( $_GET['evcf7_skipped'] ) ? absint( $_GET['evcf7_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see note above.
			$err  = isset( $_GET['evcf7_errors'] ) ? absint( $_GET['evcf7_errors'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see note above.

			if ( $imp > 0 ) {
				$type = 'notice-success';
				$icon = '✓';
			} elseif ( $skip > 0 && 0 === $err ) {
				$type = 'notice-warning';
				$icon = '⚠';
			} else {
				$type = 'notice-error';
				$icon = '✗';
			}

			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p><strong>' . esc_html( $icon ) . ' ';
			echo esc_html(
				sprintf(
					/* translators: 1: number imported, 2: number skipped, 3: number of errors */
					__( 'Import complete: %1$d entries imported, %2$d skipped (duplicates), %3$d errors.', 'entryvault-contact-form-7' ),
					$imp,
					$skip,
					$err
				)
			);
			echo '</strong></p></div>';
		}

		$err_labels = array(
			'no_file'  => __( 'No file was uploaded. Please select a .csv file and try again.', 'entryvault-contact-form-7' ),
			'bad_type' => __( 'Invalid file type. Only .csv files exported from CFDB7 are accepted.', 'entryvault-contact-form-7' ),
		);
		if ( isset( $_GET['evcf7_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only one-time notice appended by wp_safe_redirect() after a nonce-verified import; nothing is changed here.
			$key = sanitize_key( wp_unslash( $_GET['evcf7_err'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see note above.
			$msg = isset( $err_labels[ $key ] ) ? $err_labels[ $key ] : __( 'An unknown error occurred.', 'entryvault-contact-form-7' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// CF7 forms for the assignment dropdown.
		$cf7_forms = get_posts( array(
			'post_type'      => 'wpcf7_contact_form',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		?>
		<div class="wrap evcf7-wrap">
			<h1><?php esc_html_e( 'Import CFDB7 CSV', 'entryvault-contact-form-7' ); ?></h1>

			<p>
				<?php esc_html_e( 'Upload a CSV file previously exported from the CFDB7 plugin. Entries already in the database (matched by the original Id column) are automatically skipped to prevent duplicates.', 'entryvault-contact-form-7' ); ?>
			</p>

			<!-- ── Expected format reference ─────────────────────────────── -->
			<div class="evcf7-import-info">
				<h3 style="margin-top:0"><?php esc_html_e( 'Expected CSV format', 'entryvault-contact-form-7' ); ?></h3>
				<table class="widefat fixed" style="max-width:780px">
					<thead>
						<tr>
							<th style="width:120px"><?php esc_html_e( 'CSV Column', 'entryvault-contact-form-7' ); ?></th>
							<th style="width:180px"><?php esc_html_e( 'Stored as', 'entryvault-contact-form-7' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'entryvault-contact-form-7' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td><code>Id</code></td>      <td><code>_cfdb7_id</code></td>         <td><?php esc_html_e( 'Hidden meta – used for duplicate detection', 'entryvault-contact-form-7' ); ?></td></tr>
						<tr><td><code>Date</code></td>    <td><?php esc_html_e( 'created_at', 'entryvault-contact-form-7' ); ?></td> <td><?php esc_html_e( 'UTC datetime, preserved exactly', 'entryvault-contact-form-7' ); ?></td></tr>
						<tr><td><code>Status</code></td>  <td><code>read</code> / <code>unread</code></td> <td><?php esc_html_e( 'Read/unread state preserved from original', 'entryvault-contact-form-7' ); ?></td></tr>
						<tr><td><code>Name</code></td>    <td><code>your-name</code></td>         <td><?php esc_html_e( 'Standard CF7 field – shown in Name column', 'entryvault-contact-form-7' ); ?></td></tr>
						<tr><td><code>Email</code></td>   <td><code>your-email</code></td>        <td><?php esc_html_e( 'Standard CF7 field', 'entryvault-contact-form-7' ); ?></td></tr>
						<tr><td><code>Subject</code></td> <td><code>your-subject</code></td>      <td><?php esc_html_e( 'Standard CF7 field', 'entryvault-contact-form-7' ); ?></td></tr>
						<tr><td><code>Message</code></td> <td><code>your-message</code></td>      <td><?php esc_html_e( 'Standard CF7 field', 'entryvault-contact-form-7' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Any extra columns', 'entryvault-contact-form-7' ); ?></td><td><?php esc_html_e( 'lowercase-hyphenated name', 'entryvault-contact-form-7' ); ?></td><td><?php esc_html_e( 'Custom CF7 fields are fully supported', 'entryvault-contact-form-7' ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<!-- ── Upload form ───────────────────────────────────────────── -->
			<!--
				IMPORTANT: enctype="multipart/form-data" is required for file uploads.
				The form posts to the same page; the handler fires on admin_init.
			-->
			<form method="post"
			      enctype="multipart/form-data"
			      action="<?php echo esc_url( admin_url( 'admin.php?page=evcf7-import' ) ); ?>"
			      class="evcf7-import-form">

				<?php wp_nonce_field( 'evcf7_import' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="evcf7_csv">
									<?php esc_html_e( 'CSV File', 'entryvault-contact-form-7' ); ?>
									<span class="description">(<?php esc_html_e( 'required', 'entryvault-contact-form-7' ); ?>)</span>
								</label>
							</th>
							<td>
								<input type="file"
								       id="evcf7_csv"
								       name="evcf7_csv"
								       accept=".csv,text/csv"
								       required>
								<p class="description">
									<?php esc_html_e( 'Select the .csv file exported from CFDB7.', 'entryvault-contact-form-7' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="evcf7_form_id">
									<?php esc_html_e( 'Assign to Form', 'entryvault-contact-form-7' ); ?>
								</label>
							</th>
							<td>
								<select id="evcf7_form_id" name="evcf7_form_id">
									<option value="0">
										<?php esc_html_e( '— No specific form —', 'entryvault-contact-form-7' ); ?>
									</option>
									<?php foreach ( $cf7_forms as $form ) : ?>
										<option value="<?php echo esc_attr( $form->ID ); ?>">
											<?php echo esc_html( $form->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Optionally link all imported entries to a specific CF7 form so they appear under that form in the entries list.', 'entryvault-contact-form-7' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<!--
						FIX: Use value="1" on the submit button so $_POST['evcf7_import_submit']
						is the string "1" (truthy) instead of "" (falsy).
						The old code used empty() which treated "" as empty and never ran the import.
					-->
					<input type="submit"
					       name="evcf7_import_submit"
					       value="<?php esc_attr_e( 'Import CSV', 'entryvault-contact-form-7' ); ?>"
					       class="button button-primary button-large">
				</p>
			</form>

			<!-- ── Current entry counts ──────────────────────────────────── -->
			<h2><?php esc_html_e( 'Current Entry Counts', 'entryvault-contact-form-7' ); ?></h2>
			<?php $stats = EVCF7_Database::get_analytics(); ?>
			<?php if ( empty( $stats ) ) : ?>
				<p><?php esc_html_e( 'No entries in the database yet. Import your CSV to get started.', 'entryvault-contact-form-7' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="max-width:480px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Form', 'entryvault-contact-form-7' ); ?></th>
							<th class="num"><?php esc_html_e( 'Total', 'entryvault-contact-form-7' ); ?></th>
							<th class="num"><?php esc_html_e( 'Unread', 'entryvault-contact-form-7' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stats as $row ) :
							$fid   = absint( $row->form_id );
							$label = get_the_title( $fid ) ?: ( $fid ? '#' . $fid : __( '(No form)', 'entryvault-contact-form-7' ) );
							$eurl  = admin_url( 'admin.php?page=evcf7-entries&fid=' . $fid );
						?>
						<tr>
							<td><a href="<?php echo esc_url( $eurl ); ?>"><?php echo esc_html( $label ); ?></a></td>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $row->unread ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
