<?php
/**
 * Admin view: Import / Export.
 *
 * Phase 4: Full file import (CSV, JSON, HTML, XLSX/XLS) and export (CSV, JSON, XLSX).
 *
 * @var object[] $tables  All existing tables (passed from the admin controller).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tables = Advt_Table_Model::get_all( 999 );
?>
<div class="wrap advt-wrap">
	<h1><?php esc_html_e( '가져오기 / 내보내기', 'advanced-wp-tables' ); ?></h1>
	<hr class="wp-header-end">

	<div class="advt-ie-container">

		<!-- ============================================================
		     IMPORT
		     ============================================================ -->
		<div class="advt-ie-section">
			<h2><?php esc_html_e( '가져오기', 'advanced-wp-tables' ); ?></h2>
			<p class="advt-ie-desc">
				<?php esc_html_e( '파일을 업로드해 새 테이블을 만들거나 기존 테이블을 덮어쓸 수 있습니다. 지원 형식: CSV, JSON, HTML, Excel (XLSX/XLS)', 'advanced-wp-tables' ); ?>
			</p>

			<form id="advt-import-form" enctype="multipart/form-data">
				<!-- Nonce is sent via JS from the localized advtAdmin.nonce -->

				<table class="form-table" role="presentation">
					<tbody>
						<!-- File upload -->
						<tr>
							<th scope="row">
								<label for="advt-import-file">
									<?php esc_html_e( '파일', 'advanced-wp-tables' ); ?>
									<span class="required">*</span>
								</label>
							</th>
							<td>
								<input type="file"
								       id="advt-import-file"
								       name="import_file"
								       accept=".csv,.json,.html,.htm,.xlsx,.xls"
								       required />
								<p class="description">
									<?php esc_html_e( '지원 형식: .csv, .json, .html, .xlsx, .xls', 'advanced-wp-tables' ); ?>
								</p>
							</td>
						</tr>

						<!-- Table name -->
						<tr>
							<th scope="row">
								<label for="advt-import-name">
									<?php esc_html_e( '테이블 이름', 'advanced-wp-tables' ); ?>
								</label>
							</th>
							<td>
								<input type="text"
								       id="advt-import-name"
								       name="import_name"
								       class="regular-text"
								       placeholder="<?php esc_attr_e( '비워두면 파일명을 사용합니다', 'advanced-wp-tables' ); ?>" />
							</td>
						</tr>

						<!-- Target table -->
						<tr>
							<th scope="row">
								<label for="advt-import-target">
									<?php esc_html_e( '대상', 'advanced-wp-tables' ); ?>
								</label>
							</th>
							<td>
								<select id="advt-import-target" name="import_target">
									<option value="0"><?php esc_html_e( '— 새 테이블 만들기 —', 'advanced-wp-tables' ); ?></option>
									<?php foreach ( $tables as $tbl ) : ?>
										<option value="<?php echo esc_attr( $tbl->id ); ?>">
											<?php
											printf(
												/* translators: 1: table ID, 2: table name */
												esc_html__( '#%1$d — %2$s', 'advanced-wp-tables' ),
												(int) $tbl->id,
												esc_html( $tbl->name )
											);
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( '새 테이블 생성 또는 기존 테이블 선택(데이터 교체)을 선택하세요.', 'advanced-wp-tables' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" id="advt-import-btn">
						<?php esc_html_e( '가져오기', 'advanced-wp-tables' ); ?>
					</button>
					<span id="advt-import-status" class="advt-ie-status" aria-live="polite"></span>
				</p>
			</form>

			<!-- Import result message -->
			<div id="advt-import-result" class="advt-ie-result" style="display:none;"></div>
		</div>

		<!-- ============================================================
		     EXPORT
		     ============================================================ -->
		<div class="advt-ie-section">
			<h2><?php esc_html_e( '내보내기', 'advanced-wp-tables' ); ?></h2>
			<p class="advt-ie-desc">
				<?php esc_html_e( '테이블과 형식을 선택해 파일로 다운로드하세요.', 'advanced-wp-tables' ); ?>
			</p>

			<form id="advt-export-form">
				<!-- Nonce is sent via JS from the localized advtAdmin.nonce -->

				<table class="form-table" role="presentation">
					<tbody>
						<!-- Table selector -->
						<tr>
							<th scope="row">
								<label for="advt-export-table">
									<?php esc_html_e( '테이블', 'advanced-wp-tables' ); ?>
									<span class="required">*</span>
								</label>
							</th>
							<td>
								<?php if ( empty( $tables ) ) : ?>
									<p class="description"><?php esc_html_e( '아직 테이블이 없습니다. 먼저 테이블을 생성하세요.', 'advanced-wp-tables' ); ?></p>
								<?php else : ?>
									<select id="advt-export-table" name="export_table" required>
										<option value=""><?php esc_html_e( '— 테이블 선택 —', 'advanced-wp-tables' ); ?></option>
										<?php foreach ( $tables as $tbl ) : ?>
											<option value="<?php echo esc_attr( $tbl->id ); ?>">
												<?php
												printf(
													/* translators: 1: table ID, 2: table name */
													esc_html__( '#%1$d — %2$s', 'advanced-wp-tables' ),
													(int) $tbl->id,
													esc_html( $tbl->name )
												);
												?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
							</td>
						</tr>

						<!-- Format selector -->
						<tr>
							<th scope="row">
								<label for="advt-export-format">
									<?php esc_html_e( '형식', 'advanced-wp-tables' ); ?>
									<span class="required">*</span>
								</label>
							</th>
							<td>
								<select id="advt-export-format" name="export_format" required>
									<option value="csv"><?php esc_html_e( 'CSV (.csv)', 'advanced-wp-tables' ); ?></option>
									<option value="json"><?php esc_html_e( 'JSON (.json)', 'advanced-wp-tables' ); ?></option>
									<option value="xlsx"><?php esc_html_e( 'Excel (.xlsx)', 'advanced-wp-tables' ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" id="advt-export-btn" <?php echo empty( $tables ) ? 'disabled' : ''; ?>>
						<?php esc_html_e( '내보내기 다운로드', 'advanced-wp-tables' ); ?>
					</button>
				</p>
			</form>
		</div>

	</div><!-- .advt-ie-container -->
</div><!-- .wrap -->
