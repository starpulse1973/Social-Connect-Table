<?php
/**
 * Admin controller — registers admin menus, enqueues admin assets, and handles admin pages.
 *
 * Features:
 *  - AJAX handlers for delete and copy table actions.
 *  - jspreadsheet-ce (MIT) grid editor on the Add/Edit table pages.
 *  - REST nonce added to localized script data.
 *  - Table options panel for configuring display settings.
 *
 * @package AdvancedWPTables
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Advt_Admin {

	/**
	 * Register all admin hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX handlers — both logged-in variants are needed; no nopriv (admin only).
		add_action( 'wp_ajax_advt_delete_table', [ $this, 'ajax_delete_table' ] );
		add_action( 'wp_ajax_advt_copy_table', [ $this, 'ajax_copy_table' ] );
	}

	/**
	 * Register the admin menu and sub-menu pages.
	 */
	public function add_menu_pages(): void {
		// Top-level menu.
		add_menu_page(
            __( '소셜 커넥트 테이블', 'advanced-wp-tables' ),
            __( '소셜 커넥트 테이블', 'advanced-wp-tables' ),
			'manage_options',
			'advt-tables',
			[ $this, 'render_list_page' ],
			'dashicons-grid-view',
			30
		);

		// Sub-menus.
		add_submenu_page(
			'advt-tables',
			__( '모든 테이블', 'advanced-wp-tables' ),
			__( '모든 테이블', 'advanced-wp-tables' ),
			'manage_options',
			'advt-tables',
			[ $this, 'render_list_page' ]
		);

		add_submenu_page(
			'advt-tables',
			__( '새 테이블 추가', 'advanced-wp-tables' ),
			__( '새로 추가', 'advanced-wp-tables' ),
			'manage_options',
			'advt-table-add',
			[ $this, 'render_edit_page' ]
		);

		add_submenu_page(
			'advt-tables',
			__( '가져오기 / 내보내기', 'advanced-wp-tables' ),
			__( '가져오기 / 내보내기', 'advanced-wp-tables' ),
			'manage_options',
			'advt-import-export',
			[ $this, 'render_import_export_page' ]
		);

		add_submenu_page(
			'advt-tables',
			__( '설정', 'advanced-wp-tables' ),
			__( '설정', 'advanced-wp-tables' ),
			'manage_options',
			'advt-settings',
			[ $this, 'render_settings_page' ]
		);

		// Hidden page for editing an existing table (accessed via query param).
		add_submenu_page(
			null, // hidden
			__( '테이블 편집', 'advanced-wp-tables' ),
			'',
			'manage_options',
			'advt-table-edit',
			[ $this, 'render_edit_page' ]
		);
	}

	/**
	 * Enqueue admin CSS and JS only on our plugin pages.
	 */
	public function enqueue_assets( string $hook ): void {
		// Only load on our pages.
		if ( strpos( $hook, 'advt-' ) === false && strpos( $hook, 'advt_' ) === false ) {
			return;
		}

		// Admin CSS.
		wp_enqueue_style(
			'advt-admin',
			ADVT_PLUGIN_URL . 'admin/css/admin.css',
			[],
			ADVT_VERSION
		);

		// Admin JS (delete / copy list-page actions).
		wp_enqueue_script(
			'advt-admin',
			ADVT_PLUGIN_URL . 'admin/js/admin.js',
			[ 'jquery' ],
			ADVT_VERSION,
			true
		);

		wp_localize_script( 'advt-admin', 'advtAdmin', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'restUrl'  => rest_url( 'advt/v1/' ),
			'nonce'    => wp_create_nonce( 'advt_admin_nonce' ),
			'restNonce'=> wp_create_nonce( 'wp_rest' ),
			'i18n'     => [
				'confirmDelete' => __( '이 테이블을 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.', 'advanced-wp-tables' ),
				'saved'         => __( '저장됨', 'advanced-wp-tables' ),
				'saving'        => __( '저장 중…', 'advanced-wp-tables' ),
				'unsaved'       => __( '저장되지 않은 변경사항', 'advanced-wp-tables' ),
				'error'         => __( '오류가 발생했습니다. 다시 시도해 주세요.', 'advanced-wp-tables' ),
				'confirmDelRow' => __( '선택한 행을 삭제할까요?', 'advanced-wp-tables' ),
				'confirmDelCol' => __( '선택한 열을 삭제할까요?', 'advanced-wp-tables' ),
				'addRow'        => __( '행 추가', 'advanced-wp-tables' ),
				'addCol'        => __( '열 추가', 'advanced-wp-tables' ),
				'delRow'        => __( '행 삭제', 'advanced-wp-tables' ),
				'delCol'        => __( '열 삭제', 'advanced-wp-tables' ),
				'saveTable'     => __( '테이블 저장', 'advanced-wp-tables' ),
				'insertRowBelow'=> __( '아래에 행 삽입', 'advanced-wp-tables' ),
				'insertColAfter'=> __( '오른쪽에 열 삽입', 'advanced-wp-tables' ),
				'cellFormatting'=> __( '셀 서식…', 'advanced-wp-tables' ),
			],
		] );

		// On the Add/Edit table pages, load the spreadsheet editor.
		$is_editor_page = (
			strpos( $hook, 'advt-table-add' ) !== false ||
			strpos( $hook, 'advt-table-edit' ) !== false
		);

		if ( $is_editor_page ) {
			$this->enqueue_editor_assets();
		}

		// On the Import/Export page, load the import/export script.
		if ( strpos( $hook, 'advt-import-export' ) !== false ) {
			wp_enqueue_script(
				'advt-import-export',
				ADVT_PLUGIN_URL . 'admin/js/admin-import-export.js',
				[ 'jquery', 'advt-admin' ],
				ADVT_VERSION,
				true
			);
		}
	}

	/**
	 * Enqueue jspreadsheet-ce (MIT) and the grid editor script + styles.
	 *
	 * jspreadsheet-ce v4 is a fully open-source spreadsheet library (MIT license).
	 * It depends on jsuites (also MIT) for context menus, toolbars, etc.
	 */
	private function enqueue_editor_assets(): void {
		// jsuites CSS — required by jspreadsheet-ce.
		// IMPORTANT: jspreadsheet-ce v4 requires jsuites v4 (NOT v5).
		wp_enqueue_style(
			'jsuites',
			'https://cdn.jsdelivr.net/npm/jsuites@4/dist/jsuites.min.css',
			[],
			'4'
		);

		// jspreadsheet-ce CSS.
		wp_enqueue_style(
			'jspreadsheet',
			'https://cdn.jsdelivr.net/npm/jspreadsheet-ce@4/dist/jspreadsheet.min.css',
			[ 'jsuites' ],
			'4'
		);

		// Editor CSS.
		wp_enqueue_style(
			'advt-editor',
			ADVT_PLUGIN_URL . 'admin/css/admin-editor.css',
			[ 'advt-admin', 'jspreadsheet' ],
			ADVT_VERSION
		);

		// jsuites JS — must load before jspreadsheet.
		// IMPORTANT: jspreadsheet-ce v4 requires jsuites v4 (NOT v5).
		wp_enqueue_script(
			'jsuites',
			'https://cdn.jsdelivr.net/npm/jsuites@4/dist/jsuites.min.js',
			[],
			'4',
			true
		);

		// jspreadsheet-ce JS.
		// NOTE: v4 main file is dist/index.js (not dist/jspreadsheet.min.js which 404s).
		wp_enqueue_script(
			'jspreadsheet',
			'https://cdn.jsdelivr.net/npm/jspreadsheet-ce@4/dist/index.js',
			[ 'jsuites' ],
			'4',
			true
		);

		// Grid editor JS — depends on jQuery (for AJAX) and jspreadsheet.
		wp_enqueue_script(
			'advt-editor',
			ADVT_PLUGIN_URL . 'admin/js/admin-editor.js',
			[ 'jquery', 'jspreadsheet', 'advt-admin' ],
			ADVT_VERSION,
			true
		);
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the "All Tables" list page.
	 */
	public function render_list_page(): void {
		$tables = Advt_Table_Model::get_all();
		$total  = Advt_Table_Model::count();
		include ADVT_PLUGIN_DIR . 'admin/views/list-tables.php';
	}

	/**
	 * Render the Add/Edit table page.
	 */
	public function render_edit_page(): void {
		$table_id = isset( $_GET['table_id'] ) ? absint( $_GET['table_id'] ) : 0;
		$table    = $table_id ? Advt_Table_Model::get( $table_id ) : null;
		include ADVT_PLUGIN_DIR . 'admin/views/edit-table.php';
	}

	/**
	 * Render the Import/Export page.
	 */
	public function render_import_export_page(): void {
		include ADVT_PLUGIN_DIR . 'admin/views/import-export.php';
	}

	/**
	 * Render the Settings page.
	 */
	public function render_settings_page(): void {
		include ADVT_PLUGIN_DIR . 'admin/views/settings.php';
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: delete a table.
	 *
	 * Expected POST fields: table_id (int), _wpnonce (string).
	 */
	public function ajax_delete_table(): void {
		check_ajax_referer( 'advt_admin_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '권한이 없습니다.', 'advanced-wp-tables' ) ], 403 );
		}

		$id = isset( $_POST['table_id'] ) ? absint( $_POST['table_id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( '잘못된 테이블 ID입니다.', 'advanced-wp-tables' ) ], 400 );
		}

		if ( ! Advt_Table_Model::get( $id ) ) {
			wp_send_json_error( [ 'message' => __( '테이블을 찾을 수 없습니다.', 'advanced-wp-tables' ) ], 404 );
		}

		if ( Advt_Table_Model::delete( $id ) ) {
			wp_send_json_success( [ 'message' => __( '테이블을 삭제했습니다.', 'advanced-wp-tables' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( '테이블 삭제에 실패했습니다.', 'advanced-wp-tables' ) ], 500 );
		}
	}

	/**
	 * AJAX: duplicate a table.
	 *
	 * Expected POST fields: table_id (int), _wpnonce (string).
	 */
	public function ajax_copy_table(): void {
		check_ajax_referer( 'advt_admin_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '권한이 없습니다.', 'advanced-wp-tables' ) ], 403 );
		}

		$id = isset( $_POST['table_id'] ) ? absint( $_POST['table_id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( '잘못된 테이블 ID입니다.', 'advanced-wp-tables' ) ], 400 );
		}

		$new_id = Advt_Table_Model::copy( $id );

		if ( $new_id ) {
			wp_send_json_success( [
				'id'      => $new_id,
				'message' => __( '테이블을 복제했습니다.', 'advanced-wp-tables' ),
			] );
		} else {
			wp_send_json_error( [ 'message' => __( '테이블 복제에 실패했습니다.', 'advanced-wp-tables' ) ], 500 );
		}
	}
}
