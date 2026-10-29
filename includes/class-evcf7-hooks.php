<?php
/**
 * CF7 submission hooks – captures every valid form submission.
 *
 * CFDB7-compatible capture strategy
 * ──────────────────────────────────
 * We hook into BOTH wpcf7_mail_sent AND wpcf7_mail_failed so that entries
 * are never lost due to mail delivery problems (SMTP misconfiguration, spam
 * filters, server restrictions, etc.).
 *
 *  Hook                  When it fires
 *  ─────────────────────────────────────────────────────────────────────────
 *  wpcf7_mail_sent       Validation passed + email delivered successfully.
 *  wpcf7_mail_failed     Validation passed + email delivery FAILED.
 *                        The front-end shows an error, but the data is valid
 *                        and should be saved — this is the important one.
 *
 * Hooks we intentionally skip:
 *  wpcf7_spam    — submission blocked as spam; do not persist.
 *  wpcf7_invalid — form validation failed; data is incomplete, do not persist.
 *
 * A '_mail_status' meta key ('mail_sent' | 'mail_failed') is stored alongside
 * each entry so admins can see which submissions had mail issues.
 *
 * @package EVCF7_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EVCF7_Hooks
 */
class EVCF7_Hooks {

	/** @var EVCF7_Hooks|null */
	private static $instance = null;

	/** @return EVCF7_Hooks */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor – register hooks. */
	private function __construct() {
		/*
		 * Priority 10 (default). Both actions receive the same
		 * $contact_form argument, so a single handler covers both.
		 */
		add_action( 'wpcf7_mail_sent',   array( $this, 'capture_mail_sent' ) );
		add_action( 'wpcf7_mail_failed', array( $this, 'capture_mail_failed' ) );
	}

	// ── Public action callbacks ─────────────────────────────────────────────

	/**
	 * Entry point for successful mail delivery.
	 *
	 * @param WPCF7_ContactForm $contact_form CF7 form object.
	 * @return void
	 */
	public function capture_mail_sent( $contact_form ) {
		$this->save_submission( $contact_form, 'mail_sent' );
	}

	/**
	 * Entry point for failed mail delivery.
	 *
	 * The front-end shows the user an error message, but the form data is
	 * valid and we must not lose it. This mirrors CFDB7's behaviour.
	 *
	 * @param WPCF7_ContactForm $contact_form CF7 form object.
	 * @return void
	 */
	public function capture_mail_failed( $contact_form ) {
		$this->save_submission( $contact_form, 'mail_failed' );
	}

	// ── Private helpers ─────────────────────────────────────────────────────

	/**
	 * Validate, sanitize, and persist a CF7 submission.
	 *
	 * @param WPCF7_ContactForm $contact_form CF7 form object.
	 * @param string            $mail_status  'mail_sent' or 'mail_failed'.
	 * @return void
	 */
	private function save_submission( $contact_form, $mail_status ) {
		if ( ! $contact_form instanceof WPCF7_ContactForm ) {
			return;
		}

		$submission = WPCF7_Submission::get_instance();

		if ( ! $submission ) {
			return;
		}

		/*
		 * Guard: only persist statuses we explicitly handle.
		 * CF7 could theoretically call these hooks with unexpected statuses
		 * in the future; bail if the submission's own status doesn't match.
		 */
		$submission_status = $submission->get_status();
		if ( ! in_array( $submission_status, array( 'mail_sent', 'mail_failed' ), true ) ) {
			return;
		}

		$form_id  = absint( $contact_form->id() );
		$raw_data = $submission->get_posted_data();

		if ( empty( $raw_data ) || ! is_array( $raw_data ) ) {
			return;
		}

		// Remove internal CF7 fields (those prefixed with an underscore).
		$fields = array_filter(
			$raw_data,
			static function ( $key ) {
				return strpos( $key, '_' ) !== 0;
			},
			ARRAY_FILTER_USE_KEY
		);

		// Flatten array values (e.g. checkbox groups) to comma-separated strings.
		$fields = array_map(
			static function ( $value ) {
				return is_array( $value ) ? implode( ', ', $value ) : $value;
			},
			$fields
		);

		/**
		 * Filter the entry data before it is saved.
		 *
		 * @since 1.0.0
		 * @param array             $fields       Field name => value pairs.
		 * @param int               $form_id      CF7 form post ID.
		 * @param WPCF7_ContactForm $contact_form CF7 form object.
		 */
		$fields = apply_filters( 'evcf7_entry_data', $fields, $form_id, $contact_form );

		if ( empty( $fields ) ) {
			return;
		}

		// Insert the entry row (status = 'unread' by default).
		$entry_id = EVCF7_Database::insert_entry( $form_id, $fields );

		if ( ! $entry_id ) {
			return;
		}

		/*
		 * Store the mail delivery status as a meta field so admins can
		 * identify which entries had mail failures.
		 * Key: _mail_status   Values: 'mail_sent' | 'mail_failed'
		 */
		EVCF7_Database::insert_entry_meta( $entry_id, '_mail_status', $mail_status );

		/**
		 * Fires after an entry has been saved.
		 *
		 * @since 1.0.0
		 * @param int               $entry_id     Newly inserted entry ID.
		 * @param int               $form_id      CF7 form post ID.
		 * @param array             $fields       Saved field data.
		 * @param WPCF7_ContactForm $contact_form CF7 form object.
		 */
		do_action( 'evcf7_after_entry_saved', $entry_id, $form_id, $fields, $contact_form );
	}
}
