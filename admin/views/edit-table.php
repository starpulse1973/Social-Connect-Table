<?php
/**
 * Admin view: Add / Edit Table.
 *
 * Phase 2: Full jspreadsheet-ce grid editor with table options panel.
 *
 * @var int         $table_id
 * @var object|null $table
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_new      = ! $table;
$table_name  = $is_new ? '' : $table->name;
$table_desc  = $is_new ? '' : $table->description;
$table_data  = $is_new ? wp_json_encode( Advt_Table_Model::default_data() ) : $table->data;
$table_opts  = $is_new ? wp_json_encode( Advt_Table_Model::default_options() ) : $table->options;
$options_arr = json_decode( $is_new ? $table_opts : $table->options, true ) ?: Advt_Table_Model::default_options();
?>
<div class="wrap advt-wrap">
	<h1 class="wp-heading-inline">
        <?php echo $is_new ? esc_html__( '새 테이블 추가', 'advanced-wp-tables' ) : esc_html__( '테이블 편집', 'advanced-wp-tables' ); ?>
	</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=advt-tables' ) ); ?>" class="page-title-action">
        <?php esc_html_e( '&larr; 모든 테이블', 'advanced-wp-tables' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( ! $is_new ) : ?>
		<p class="advt-shortcode-hint">
            <?php esc_html_e( '쇼트코드:', 'advanced-wp-tables' ); ?>
			<code>[adv_wp_table id="<?php echo esc_attr( (string) $table_id ); ?>" /]</code>
		</p>
	<?php endif; ?>

	<!-- Editor app root: data attributes pass initial state to admin-editor.js -->
	<div id="advt-editor-app"
	     data-table-id="<?php echo esc_attr( (string) $table_id ); ?>"
	     data-table-name="<?php echo esc_attr( $table_name ); ?>"
	     data-table-description="<?php echo esc_attr( $table_desc ); ?>"
	     data-table-data="<?php echo esc_attr( $table_data ); ?>"
	     data-table-options="<?php echo esc_attr( $table_opts ); ?>">

		<!-- Table meta: name + description -->
		<div class="advt-editor-meta">
			<div class="advt-editor-meta-row">
				<label for="advt-table-name">
                    <?php esc_html_e( '테이블 이름', 'advanced-wp-tables' ); ?>
					<span class="required">*</span>
				</label>
				<input
					type="text"
					id="advt-table-name"
					class="regular-text"
					value="<?php echo esc_attr( $table_name ); ?>"
                    placeholder="<?php esc_attr_e( '내 테이블', 'advanced-wp-tables' ); ?>"
				/>
			</div>
			<div class="advt-editor-meta-row">
				<label for="advt-table-description">
                    <?php esc_html_e( '설명', 'advanced-wp-tables' ); ?>
				</label>
				<textarea
					id="advt-table-description"
					class="large-text"
					rows="2"
                    placeholder="<?php esc_attr_e( '선택 사항 설명', 'advanced-wp-tables' ); ?>"
				><?php echo esc_textarea( $table_desc ); ?></textarea>
			</div>
		</div>

		<!-- Editor toolbar -->
		<div class="advt-editor-toolbar">
			<div class="advt-toolbar-group">
				<button type="button" class="button" id="advt-btn-add-row" title="<?php esc_attr_e( 'Insert a new row below the current selection', 'advanced-wp-tables' ); ?>">
                    <?php esc_html_e( '+ 행', 'advanced-wp-tables' ); ?>
				</button>
				<button type="button" class="button" id="advt-btn-add-col" title="<?php esc_attr_e( 'Insert a new column after the current selection', 'advanced-wp-tables' ); ?>">
                    <?php esc_html_e( '+ 열', 'advanced-wp-tables' ); ?>
				</button>
				<button type="button" class="button" id="advt-btn-del-row" title="<?php esc_attr_e( 'Delete selected row(s)', 'advanced-wp-tables' ); ?>">
                    <?php esc_html_e( '행 삭제', 'advanced-wp-tables' ); ?>
				</button>
				<button type="button" class="button" id="advt-btn-del-col" title="<?php esc_attr_e( 'Delete selected column(s)', 'advanced-wp-tables' ); ?>">
                    <?php esc_html_e( '열 삭제', 'advanced-wp-tables' ); ?>
				</button>
				<span class="advt-toolbar-separator"></span>
				<button type="button" class="button" id="advt-btn-merge" title="<?php esc_attr_e( 'Merge selected cells', 'advanced-wp-tables' ); ?>">
                    <?php esc_html_e( '병합', 'advanced-wp-tables' ); ?>
				</button>
				<button type="button" class="button" id="advt-btn-unmerge" title="<?php esc_attr_e( 'Unmerge selected cells', 'advanced-wp-tables' ); ?>">
                    <?php esc_html_e( '병합 해제', 'advanced-wp-tables' ); ?>
				</button>
			</div>

			<div class="advt-toolbar-actions">
				<span id="advt-save-status" class="advt-save-status" aria-live="polite"></span>
				<button type="button" class="button button-primary" id="advt-btn-save">
                    <?php esc_html_e( '테이블 저장', 'advanced-wp-tables' ); ?>
				</button>
			</div>
		</div>

		<!-- Formula bar — shows raw formula for the selected cell -->
		<div id="advt-formula-bar" class="advt-formula-bar">
			<span class="advt-formula-bar-label" title="<?php esc_attr_e( 'Formula Bar', 'advanced-wp-tables' ); ?>">
				<em>f</em><sub>x</sub>
			</span>
			<span id="advt-formula-cell-ref" class="advt-formula-cell-ref"></span>
			<input type="text"
			       id="advt-formula-input"
			       class="advt-formula-input"
                   placeholder="<?php esc_attr_e( '셀을 선택하세요. 수식은 = 로 시작합니다 (예: =SUM(A1:A5))', 'advanced-wp-tables' ); ?>"
			       spellcheck="false"
			       autocomplete="off" />
		</div>

		<!-- jspreadsheet-ce mount point -->
		<div id="advt-grid-container"></div>

		<!-- Tip shown below the editor -->
		<p class="advt-editor-tip">
            <?php esc_html_e( '팁: 셀에서 마우스 오른쪽 버튼을 클릭하면 추가 옵션이 열립니다. Ctrl+Z / Ctrl+Y로 실행 취소/다시 실행, Ctrl+S로 저장할 수 있습니다.', 'advanced-wp-tables' ); ?>
		</p>

		<!-- Formula help reference (collapsible) -->
		<details class="advt-formula-help">
            <summary><?php esc_html_e( '수식 참고', 'advanced-wp-tables' ); ?></summary>
			<div class="advt-formula-help-content">
				<p><?php esc_html_e( 'Formulas are evaluated on the frontend when the table is displayed. Start a cell value with = to create a formula.', 'advanced-wp-tables' ); ?></p>

				<h4><?php esc_html_e( 'Cell References', 'advanced-wp-tables' ); ?></h4>
				<ul>
					<li><code>=A1</code> — <?php esc_html_e( 'Reference a single cell (column A, row 1)', 'advanced-wp-tables' ); ?></li>
					<li><code>=A1+B1</code> — <?php esc_html_e( 'Arithmetic with cell references', 'advanced-wp-tables' ); ?></li>
					<li><code>=A1:A5</code> — <?php esc_html_e( 'Cell range (used inside functions)', 'advanced-wp-tables' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Math Functions', 'advanced-wp-tables' ); ?></h4>
				<ul>
					<li><code>=SUM(A1:A10)</code> — <?php esc_html_e( 'Sum of range', 'advanced-wp-tables' ); ?></li>
					<li><code>=AVERAGE(B1:B5)</code> — <?php esc_html_e( 'Mean average', 'advanced-wp-tables' ); ?></li>
					<li><code>=MIN(A1:A5)</code> / <code>=MAX(A1:A5)</code> — <?php esc_html_e( 'Minimum / Maximum', 'advanced-wp-tables' ); ?></li>
					<li><code>=COUNT(A1:A5)</code> — <?php esc_html_e( 'Count of numeric values', 'advanced-wp-tables' ); ?></li>
					<li><code>=MEDIAN(A1:A10)</code> — <?php esc_html_e( 'Median value', 'advanced-wp-tables' ); ?></li>
					<li><code>=PRODUCT(A1:A5)</code> — <?php esc_html_e( 'Product of all values', 'advanced-wp-tables' ); ?></li>
					<li><code>=ROUND(A1, 2)</code> — <?php esc_html_e( 'Round to N decimal places', 'advanced-wp-tables' ); ?></li>
					<li><code>=ABS(A1)</code> — <?php esc_html_e( 'Absolute value', 'advanced-wp-tables' ); ?></li>
					<li><code>=MOD(A1, B1)</code> — <?php esc_html_e( 'Modulo (remainder)', 'advanced-wp-tables' ); ?></li>
					<li><code>=POWER(A1, 2)</code> — <?php esc_html_e( 'Raise to power', 'advanced-wp-tables' ); ?></li>
					<li><code>=SQRT(A1)</code> — <?php esc_html_e( 'Square root', 'advanced-wp-tables' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Logic & Text Functions', 'advanced-wp-tables' ); ?></h4>
				<ul>
					<li><code>=IF(A1>10, "Yes", "No")</code> — <?php esc_html_e( 'Conditional value', 'advanced-wp-tables' ); ?></li>
					<li><code>=CONCAT(A1, " ", B1)</code> — <?php esc_html_e( 'Join text values', 'advanced-wp-tables' ); ?></li>
					<li><code>=UPPER(A1)</code> / <code>=LOWER(A1)</code> — <?php esc_html_e( 'Change case', 'advanced-wp-tables' ); ?></li>
					<li><code>=LEN(A1)</code> — <?php esc_html_e( 'Length of text', 'advanced-wp-tables' ); ?></li>
					<li><code>=LEFT(A1, 3)</code> / <code>=RIGHT(A1, 3)</code> — <?php esc_html_e( 'Extract characters', 'advanced-wp-tables' ); ?></li>
					<li><code>=MID(A1, 2, 3)</code> — <?php esc_html_e( 'Extract substring', 'advanced-wp-tables' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Operators', 'advanced-wp-tables' ); ?></h4>
				<ul>
					<li><code>+ - * / %</code> — <?php esc_html_e( 'Arithmetic', 'advanced-wp-tables' ); ?></li>
					<li><code>^</code> — <?php esc_html_e( 'Exponent', 'advanced-wp-tables' ); ?></li>
					<li><code>&</code> — <?php esc_html_e( 'Text concatenation', 'advanced-wp-tables' ); ?></li>
					<li><code>= &lt;&gt; &lt; &gt; &lt;= &gt;=</code> — <?php esc_html_e( 'Comparison (inside IF)', 'advanced-wp-tables' ); ?></li>
				</ul>
			</div>
		</details>

	</div><!-- #advt-editor-app -->

	<!-- ================================================================
	     Table Options Panel (collapsible)
	     ================================================================ -->
	<div class="advt-options-wrapper">
		<button type="button"
		        class="advt-options-toggle"
		        aria-expanded="false"
		        aria-controls="advt-options-panel"
		        onclick="this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true'); document.getElementById('advt-options-panel').classList.toggle('advt-panel-open');">
			<span class="dashicons dashicons-arrow-right-alt2"></span>
			<?php esc_html_e( '테이블 옵션', 'advanced-wp-tables' ); ?>
		</button>

		<div id="advt-options-panel" role="region">

			<!-- DataTables Features -->
			<div class="advt-options-section">
				<h3><?php esc_html_e( 'DataTables 기능', 'advanced-wp-tables' ); ?></h3>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="use_datatables" value="1"
							<?php checked( ! empty( $options_arr['use_datatables'] ) ); ?> />
						<?php esc_html_e( 'DataTables 사용', 'advanced-wp-tables' ); ?>
					</label>
					<span class="advt-option-desc"><?php esc_html_e( '프론트엔드 테이블에 정렬, 페이지네이션, 검색 기능을 추가합니다.', 'advanced-wp-tables' ); ?></span>
				</div>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="pagination" value="1"
							<?php checked( ! empty( $options_arr['pagination'] ) ); ?> />
						<?php esc_html_e( '페이지네이션', 'advanced-wp-tables' ); ?>
					</label>
				</div>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="searching" value="1"
							<?php checked( ! empty( $options_arr['searching'] ) ); ?> />
						<?php esc_html_e( '검색 / 필터', 'advanced-wp-tables' ); ?>
					</label>
				</div>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="ordering" value="1"
							<?php checked( ! empty( $options_arr['ordering'] ) ); ?> />
						<?php esc_html_e( '열 정렬', 'advanced-wp-tables' ); ?>
					</label>
				</div>

				<div class="advt-option-row">
					<label for="advt-opt-page-length">
						<?php esc_html_e( '페이지당 행 수', 'advanced-wp-tables' ); ?>
					</label>
					<input type="number"
					       id="advt-opt-page-length"
					       name="page_length"
					       min="1"
					       max="1000"
					       value="<?php echo esc_attr( (string) ( $options_arr['page_length'] ?? 25 ) ); ?>" />
				</div>
			</div>

			<!-- Header / Layout -->
			<div class="advt-options-section">
				<h3><?php esc_html_e( '헤더 및 레이아웃', 'advanced-wp-tables' ); ?></h3>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="first_row_header" value="1"
							<?php checked( ! empty( $options_arr['first_row_header'] ) ); ?> />
						<?php esc_html_e( '첫 번째 행을 테이블 헤더로 사용', 'advanced-wp-tables' ); ?>
					</label>
					<span class="advt-option-desc"><?php esc_html_e( '첫 번째 데이터 행을 <thead> 안에 렌더링합니다.', 'advanced-wp-tables' ); ?></span>
				</div>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="responsive" value="1"
							<?php checked( ! empty( $options_arr['responsive'] ) ); ?> />
						<?php esc_html_e( '반응형 모드', 'advanced-wp-tables' ); ?>
					</label>
					<span class="advt-option-desc"><?php esc_html_e( '작은 화면에서 열을 접어 표시합니다.', 'advanced-wp-tables' ); ?></span>
				</div>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="scroll_x" value="1"
							<?php checked( ! empty( $options_arr['scroll_x'] ) ); ?> />
						<?php esc_html_e( '가로 스크롤', 'advanced-wp-tables' ); ?>
					</label>
				</div>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="fixed_header" value="1"
							<?php checked( ! empty( $options_arr['fixed_header'] ) ); ?> />
						<?php esc_html_e( '고정 헤더 (sticky)', 'advanced-wp-tables' ); ?>
					</label>
				</div>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="fixed_columns" value="1"
							<?php checked( ! empty( $options_arr['fixed_columns'] ) ); ?> />
						<?php esc_html_e( '첫 번째 열 고정', 'advanced-wp-tables' ); ?>
					</label>
				</div>
			</div>

			<!-- Styling -->
			<div class="advt-options-section">
				<h3><?php esc_html_e( '스타일', 'advanced-wp-tables' ); ?></h3>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="alternating_colors" value="1"
							<?php checked( ! empty( $options_arr['alternating_colors'] ) ); ?> />
						<?php esc_html_e( '교차 행 색상', 'advanced-wp-tables' ); ?>
					</label>
				</div>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="hover_highlight" value="1"
							<?php checked( ! empty( $options_arr['hover_highlight'] ) ); ?> />
						<?php esc_html_e( '마우스 오버 시 행 강조', 'advanced-wp-tables' ); ?>
					</label>
				</div>

				<div class="advt-option-row">
					<label for="advt-opt-inner-border-color">
						<?php esc_html_e( '내부 테두리 색상', 'advanced-wp-tables' ); ?>
					</label>
					<input type="color"
					       id="advt-opt-inner-border-color"
					       name="inner_border_color"
					       value="<?php echo esc_attr( $options_arr['inner_border_color'] ?? '#e0e0e0' ); ?>" />
				</div>

				<div class="advt-option-row">
					<label for="advt-opt-cell-padding">
						<?php esc_html_e( '셀 내부 패딩', 'advanced-wp-tables' ); ?>
					</label>
					<input type="text"
					       id="advt-opt-cell-padding"
					       name="cell_padding"
					       class="regular-text"
					       value="<?php echo esc_attr( $options_arr['cell_padding'] ?? '10px 14px' ); ?>"
					       placeholder="<?php esc_attr_e( '예: 10px 14px 또는 8px', 'advanced-wp-tables' ); ?>" />
				</div>

				<div class="advt-option-row">
					<label for="advt-opt-extra-css">
						<?php esc_html_e( '추가 CSS 클래스', 'advanced-wp-tables' ); ?>
					</label>
					<input type="text"
					       id="advt-opt-extra-css"
					       name="extra_css_classes"
					       class="regular-text"
					       value="<?php echo esc_attr( $options_arr['extra_css_classes'] ?? '' ); ?>"
					       placeholder="<?php esc_attr_e( '예: my-class another-class', 'advanced-wp-tables' ); ?>" />
				</div>

				<div class="advt-option-row advt-option-full-row">
					<label for="advt-opt-custom-css">
						<?php esc_html_e( '사용자 정의 CSS', 'advanced-wp-tables' ); ?>
					</label>
					<textarea
						id="advt-opt-custom-css"
						name="custom_css"
						rows="4"
						placeholder="<?php esc_attr_e( '#advt-table-1 th { background: #333; color: #fff; }', 'advanced-wp-tables' ); ?>"
					><?php echo esc_textarea( $options_arr['custom_css'] ?? '.advt-table-wrap{margin:0 0;}' ); ?></textarea>
				</div>
			</div>

			<!-- Pro Features -->
			<div class="advt-options-section">
				<h3><?php esc_html_e( '고급 기능', 'advanced-wp-tables' ); ?></h3>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="buttons" value="1"
							<?php checked( ! empty( $options_arr['buttons'] ) ); ?> />
						<?php esc_html_e( '내보내기 버튼 (CSV/Excel/PDF)', 'advanced-wp-tables' ); ?>
					</label>
					<span class="advt-option-desc"><?php esc_html_e( '프론트엔드 테이블에 다운로드 버튼을 표시합니다.', 'advanced-wp-tables' ); ?></span>
				</div>

				<div class="advt-option-row">
					<label>
						<input type="checkbox" name="column_visibility" value="1"
							<?php checked( ! empty( $options_arr['column_visibility'] ) ); ?> />
						<?php esc_html_e( '열 표시/숨김 전환', 'advanced-wp-tables' ); ?>
					</label>
					<span class="advt-option-desc"><?php esc_html_e( '방문자가 열을 표시/숨김할 수 있습니다.', 'advanced-wp-tables' ); ?></span>
				</div>
			</div>

		</div><!-- #advt-options-panel -->
	</div><!-- .advt-options-wrapper -->

</div><!-- .wrap -->
