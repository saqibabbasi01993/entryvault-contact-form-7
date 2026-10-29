<?php
/**
 * REST API endpoints for EntryVault CF7.
 *
 * Namespace : cf7em/v1
 * Endpoints :
 *   GET  /entries           – paginated list
 *   GET  /entries/{id}      – single entry
 *   DELETE /entries/{id}    – delete entry
 *   GET  /analytics         – counts per form
 *
 * @package EVCF7_Plugin
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EVCF7_Rest_API
 */
class EVCF7_Rest_API {

	/** @var EVCF7_Rest_API */
	private static $instance = null;

	const REST_NAMESPACE = 'evcf7/v1';

	/** @return EVCF7_Rest_API */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor. */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /cf7em/v1/entries
		register_rest_route(
			self::REST_NAMESPACE,
			'/entries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_entries' ),
				'permission_callback' => array( $this, 'admin_permissions' ),
				'args'                => array(
					'form_id'   => array( 'type' => 'integer', 'default' => 0 ),
					'date_from' => array( 'type' => 'string',  'default' => '' ),
					'date_to'   => array( 'type' => 'string',  'default' => '' ),
					'search'    => array( 'type' => 'string',  'default' => '' ),
					'per_page'  => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
					'page'      => array( 'type' => 'integer', 'default' => 1,  'minimum' => 1 ),
					'order'     => array( 'type' => 'string',  'default' => 'DESC', 'enum' => array( 'ASC', 'DESC' ) ),
				),
			)
		);

		// GET /cf7em/v1/entries/{id}
		register_rest_route(
			self::REST_NAMESPACE,
			'/entries/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_entry' ),
					'permission_callback' => array( $this, 'admin_permissions' ),
					'args'                => array(
						'id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_entry' ),
					'permission_callback' => array( $this, 'admin_permissions' ),
					'args'                => array(
						'id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
					),
				),
			)
		);

		// GET /cf7em/v1/analytics
		register_rest_route(
			self::REST_NAMESPACE,
			'/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_analytics' ),
				'permission_callback' => array( $this, 'admin_permissions' ),
			)
		);
	}

	/**
	 * Permission callback: only administrators.
	 *
	 * @return bool|WP_Error
	 */
	public function admin_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'evcf7_rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'entryvault-contact-form-7' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /entries
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_entries( WP_REST_Request $request ) {
		$args   = array(
			'form_id'   => $request->get_param( 'form_id' ),
			'date_from' => $request->get_param( 'date_from' ),
			'date_to'   => $request->get_param( 'date_to' ),
			'search'    => $request->get_param( 'search' ),
			'per_page'  => $request->get_param( 'per_page' ),
			'page'      => $request->get_param( 'page' ),
			'order'     => $request->get_param( 'order' ),
		);
		$result = EVCF7_Database::get_entries( $args );

		$response = rest_ensure_response( $result['entries'] );
		$response->header( 'X-EVCF7-Total', $result['total'] );
		$response->header( 'X-EVCF7-TotalPages', (int) ceil( $result['total'] / max( 1, $args['per_page'] ) ) );

		return $response;
	}

	/**
	 * GET /entries/{id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_entry( WP_REST_Request $request ) {
		$entry = EVCF7_Database::get_entry( $request->get_param( 'id' ) );

		if ( ! $entry ) {
			return new WP_Error( 'evcf7_not_found', __( 'Entry not found.', 'entryvault-contact-form-7' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $entry );
	}

	/**
	 * DELETE /entries/{id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_entry( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$entry = EVCF7_Database::get_entry( $id );
		if ( ! $entry ) {
			return new WP_Error( 'evcf7_not_found', __( 'Entry not found.', 'entryvault-contact-form-7' ), array( 'status' => 404 ) );
		}

		EVCF7_Database::delete_entries( $id );

		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * GET /analytics
	 *
	 * @return WP_REST_Response
	 */
	public function get_analytics() {
		$raw  = EVCF7_Database::get_analytics();
		$data = array();

		foreach ( $raw as $row ) {
			$data[] = array(
				'form_id'    => (int) $row->form_id,
				'form_title' => get_the_title( (int) $row->form_id ) ?: '#' . $row->form_id,
				'total'      => (int) $row->total,
			);
		}

		return rest_ensure_response( $data );
	}
}
