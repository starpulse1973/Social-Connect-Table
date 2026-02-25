<?php
/**
 * REST API — registers /advt/v1/ endpoints for table CRUD operations.
 *
 * Authentication: cookie-based via X-WP-Nonce header (wp_rest nonce).
 * All endpoints require the manage_options capability.
 *
 * Routes:
 *   GET    /advt/v1/tables/{id}  → get_table()
 *   POST   /advt/v1/tables       → create_table()
 *   PUT    /advt/v1/tables/{id}  → update_table()
 *
 * @package AdvancedWPTables
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Advt_Rest_Api {

	const REST_NAMESPACE = 'advt/v1';

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		// GET single table.
		register_rest_route(
			self::REST_NAMESPACE,
			'/tables/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_table' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'minimum'           => 1,
					],
				],
			]
		);

		// PUT / PATCH — update existing table.
		register_rest_route(
			self::REST_NAMESPACE,
			'/tables/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_table' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => array_merge(
					[ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ],
					$this->shared_args()
				),
			]
		);

		// POST — create new table.
		register_rest_route(
			self::REST_NAMESPACE,
			'/tables',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_table' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->shared_args(),
			]
		);
	}

	/**
	 * Permission callback.
	 *
	 * WordPress REST API automatically verifies the X-WP-Nonce header for
	 * cookie-authenticated requests, so we only need the capability check here.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /advt/v1/tables/{id}
	 */
	public function get_table( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request->get_param( 'id' );
		$table = Advt_Table_Model::get( $id );

		if ( ! $table ) {
			return $this->not_found();
		}

		return rest_ensure_response( $this->prepare( $table ) );
	}

	/**
	 * POST /advt/v1/tables
	 */
	public function create_table( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$args = [
			'name'        => (string) ( $request->get_param( 'name' ) ?? __( 'Untitled Table', 'advanced-wp-tables' ) ),
			'description' => (string) ( $request->get_param( 'description' ) ?? '' ),
		];

		$data    = $request->get_param( 'data' );
		$options = $request->get_param( 'options' );

		if ( $data !== null ) {
			$args['data'] = $this->sanitize_grid_data( $data );
		}
		if ( $options !== null ) {
			$args['options'] = $this->sanitize_options( $options );
		}

		$id = Advt_Table_Model::insert( $args );

		if ( ! $id ) {
			return new WP_Error(
				'advt_create_failed',
				__( 'Failed to create table.', 'advanced-wp-tables' ),
				[ 'status' => 500 ]
			);
		}

		$table    = Advt_Table_Model::get( $id );
		$response = rest_ensure_response( $this->prepare( $table ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * PUT/PATCH /advt/v1/tables/{id}
	 */
	public function update_table( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request->get_param( 'id' );
		$table = Advt_Table_Model::get( $id );

		if ( ! $table ) {
			return $this->not_found();
		}

		$args = [];

		if ( $request->has_param( 'name' ) ) {
			$args['name'] = (string) $request->get_param( 'name' );
		}
		if ( $request->has_param( 'description' ) ) {
			$args['description'] = (string) $request->get_param( 'description' );
		}
		if ( $request->has_param( 'data' ) ) {
			$args['data'] = $this->sanitize_grid_data( $request->get_param( 'data' ) );
		}
		if ( $request->has_param( 'options' ) ) {
			$args['options'] = $this->sanitize_options( $request->get_param( 'options' ) );
		}

		$success = Advt_Table_Model::update( $id, $args );

		if ( ! $success && ! empty( $args ) ) {
			return new WP_Error(
				'advt_update_failed',
				__( 'Failed to update table.', 'advanced-wp-tables' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( $this->prepare( Advt_Table_Model::get( $id ) ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Prepare a DB row for a REST response.
	 */
	private function prepare( object $table ): array {
		return [
			'id'          => (int) $table->id,
			'name'        => $table->name,
			'description' => $table->description,
			'data'        => json_decode( $table->data, true ),
			'options'     => json_decode( $table->options, true ),
			'author_id'   => (int) $table->author_id,
			'created_at'  => $table->created_at,
			'updated_at'  => $table->updated_at,
		];
	}

	/**
	 * Sanitize incoming 2-D cell data from the editor.
	 * Each cell is run through wp_kses_post() to allow basic formatting HTML
	 * while stripping dangerous tags.
	 *
	 * @param mixed $raw
	 * @return array<int, array<int, string>>
	 */
	private function sanitize_grid_data( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return Advt_Table_Model::default_data();
		}

		$clean = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				$clean[] = [];
				continue;
			}
			$clean_row = [];
			foreach ( $row as $cell ) {
				$clean_row[] = wp_kses_post( (string) $cell );
			}
			$clean[] = $clean_row;
		}

		return $clean;
	}

	/**
	 * Sanitize table display options.
	 *
	 * Only allows known option keys and casts values to their expected types.
	 * Unknown keys are silently discarded to prevent injection of arbitrary data.
	 *
	 * @param mixed $raw
	 * @return array<string, mixed>
	 */
	private function sanitize_options( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return Advt_Table_Model::default_options();
		}

		$defaults = Advt_Table_Model::default_options();
		$clean    = [];

		// Boolean options.
		$bool_keys = [
			'use_datatables', 'pagination', 'searching', 'ordering',
			'first_row_header', 'responsive', 'scroll_x', 'fixed_header',
			'fixed_columns', 'alternating_colors', 'hover_highlight',
			'buttons', 'column_visibility',
		];
		foreach ( $bool_keys as $key ) {
			$clean[ $key ] = isset( $raw[ $key ] ) ? (bool) $raw[ $key ] : ( $defaults[ $key ] ?? false );
		}

		// Integer options.
		$clean['page_length'] = isset( $raw['page_length'] )
			? max( 1, min( 1000, absint( $raw['page_length'] ) ) )
			: ( $defaults['page_length'] ?? 25 );

		// String options — sanitized.
		$clean['custom_css']         = isset( $raw['custom_css'] ) ? wp_strip_all_tags( (string) $raw['custom_css'] ) : (string) ( $defaults['custom_css'] ?? '' );
		$clean['extra_css_classes']  = isset( $raw['extra_css_classes'] ) ? sanitize_text_field( (string) $raw['extra_css_classes'] ) : '';
		$clean['inner_border_color'] = isset( $raw['inner_border_color'] ) ? sanitize_hex_color( (string) $raw['inner_border_color'] ) : ( $defaults['inner_border_color'] ?? '#e0e0e0' );
		if ( empty( $clean['inner_border_color'] ) ) {
			$clean['inner_border_color'] = (string) ( $defaults['inner_border_color'] ?? '#e0e0e0' );
		}
		$clean['cell_padding'] = isset( $raw['cell_padding'] ) ? sanitize_text_field( (string) $raw['cell_padding'] ) : (string) ( $defaults['cell_padding'] ?? '10px 14px' );

		// Merge cells — object keyed by cell name (e.g. "A1") with [colspan, rowspan] arrays.
		if ( isset( $raw['merge_cells'] ) && is_array( $raw['merge_cells'] ) ) {
			$merge = [];
			foreach ( $raw['merge_cells'] as $key => $val ) {
				$key = sanitize_text_field( (string) $key );
				if ( preg_match( '/^[A-Z]+\d+$/', $key ) && is_array( $val ) && count( $val ) === 2 ) {
					$merge[ $key ] = [ absint( $val[0] ), absint( $val[1] ) ];
				}
			}
			$clean['merge_cells'] = (object) $merge;
		} else {
			$clean['merge_cells'] = new \stdClass();
		}

		// Cell styles — object keyed by cell name with CSS style strings.
		// Only allows safe CSS properties: text-align, vertical-align, color, background-color.
		$allowed_style_props = [ 'text-align', 'vertical-align', 'color', 'background-color', 'font-size', 'font-weight' ];
		if ( isset( $raw['cell_styles'] ) && is_array( $raw['cell_styles'] ) ) {
			$styles = [];
			foreach ( $raw['cell_styles'] as $key => $val ) {
				$key = sanitize_text_field( (string) $key );
				if ( ! preg_match( '/^[A-Z]+\d+$/', $key ) ) {
					continue;
				}
				// Parse and whitelist CSS properties.
				$parts       = explode( ';', (string) $val );
				$clean_parts = [];
				foreach ( $parts as $part ) {
					$part = trim( $part );
					if ( empty( $part ) ) {
						continue;
					}
					$kv = explode( ':', $part, 2 );
					if ( count( $kv ) !== 2 ) {
						continue;
					}
					$prop  = strtolower( trim( $kv[0] ) );
					$value = trim( $kv[1] );
					if ( in_array( $prop, $allowed_style_props, true ) ) {
						$clean_parts[] = $prop . ':' . sanitize_text_field( $value );
					}
				}
				if ( ! empty( $clean_parts ) ) {
					$styles[ $key ] = implode( ';', $clean_parts );
				}
			}
			$clean['cell_styles'] = (object) $styles;
		} else {
			$clean['cell_styles'] = new \stdClass();
		}

		return $clean;
	}

	/**
	 * Shared argument definitions used for both create and update endpoints.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function shared_args(): array {
		return [
			'name'        => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'description' => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			// `data` is a 2-D array; custom sanitization runs in the callback.
			'data'        => [
				'required'          => false,
				'validate_callback' => function ( $value ) {
					return is_array( $value ) || is_null( $value );
				},
			],
			// `options` is an associative object; sanitized by the model.
			'options'     => [
				'required'          => false,
				'validate_callback' => function ( $value ) {
					return is_array( $value ) || is_null( $value );
				},
			],
		];
	}

	/**
	 * Return a standard 404 WP_Error.
	 */
	private function not_found(): WP_Error {
		return new WP_Error(
			'advt_not_found',
			__( 'Table not found.', 'advanced-wp-tables' ),
			[ 'status' => 404 ]
		);
	}
}
