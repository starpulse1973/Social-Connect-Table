/**
 * Advanced WP Tables — Grid Editor (Phase 2)
 *
 * Initializes a jspreadsheet-ce (MIT) spreadsheet editor inside #advt-editor-app
 * and connects toolbar buttons + keyboard shortcuts to the REST API for saving.
 *
 * Dependencies (declared in PHP): jquery, jsuites, jspreadsheet, advt-admin (localized data).
 */

/* global jspreadsheet, advtAdmin, jQuery */

(function ($) {
    'use strict';

    // -----------------------------------------------------------------
    // Guard: only run on pages that have the editor container.
    // -----------------------------------------------------------------
    const appEl = document.getElementById('advt-editor-app');
    if (!appEl || typeof jspreadsheet === 'undefined') {
        return;
    }

    const ADVT = window.advtAdmin || {};

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------
    let tableId  = parseInt(appEl.dataset.tableId, 10) || 0;
    let isDirty  = false;
    let isSaving = false;

    // Selection tracking — jspreadsheet-ce v4 onselection callback
    // provides reliable coordinates regardless of getSelected() format.
    var selX1 = null, selY1 = null, selX2 = null, selY2 = null;

    // -----------------------------------------------------------------
    // Parse initial data supplied by PHP via data-* attributes.
    // -----------------------------------------------------------------
    let tableData;
    try {
        tableData = JSON.parse(appEl.dataset.tableData || '[]');
    } catch (_) {
        tableData = _defaultGrid(5, 5);
    }
    if (!Array.isArray(tableData) || tableData.length === 0) {
        tableData = _defaultGrid(5, 5);
    }

    let tableOptions;
    try {
        tableOptions = JSON.parse(appEl.dataset.tableOptions || '{}');
    } catch (_) {
        tableOptions = {};
    }

    // -----------------------------------------------------------------
    // DOM references
    // -----------------------------------------------------------------
    const nameInput  = document.getElementById('advt-table-name');
    const descInput  = document.getElementById('advt-table-description');
    const gridEl     = document.getElementById('advt-grid-container');
    const saveBtn    = document.getElementById('advt-btn-save');
    const statusEl   = document.getElementById('advt-save-status');
    const addRowBtn  = document.getElementById('advt-btn-add-row');
    const addColBtn  = document.getElementById('advt-btn-add-col');
    const delRowBtn  = document.getElementById('advt-btn-del-row');
    const delColBtn  = document.getElementById('advt-btn-del-col');
    const mergeBtn   = document.getElementById('advt-btn-merge');
    const unmergeBtn = document.getElementById('advt-btn-unmerge');

    // -----------------------------------------------------------------
    // Determine column count from data
    // -----------------------------------------------------------------
    const colCount = (tableData[0] && tableData[0].length) ? tableData[0].length : 5;

    // Build column config — all text columns, width auto.
    const columns = [];
    for (let c = 0; c < colCount; c++) {
        columns.push({
            type: 'text',
            width: 120,
        });
    }

    // -----------------------------------------------------------------
    // Parse stored merge_cells from options → jspreadsheet mergeCells format.
    // Stored: { "A1": [colspan, rowspan], ... }
    // jspreadsheet expects the same format.
    // -----------------------------------------------------------------
    var initialMergeCells = {};
    if (tableOptions.merge_cells && typeof tableOptions.merge_cells === 'object') {
        initialMergeCells = tableOptions.merge_cells;
    }

    // -----------------------------------------------------------------
    // Parse stored cell_styles → jspreadsheet style format.
    // Stored: { "A1": "text-align:center;color:#ff0000", ... }
    // -----------------------------------------------------------------
    var initialStyles = {};
    if (tableOptions.cell_styles && typeof tableOptions.cell_styles === 'object') {
        initialStyles = tableOptions.cell_styles;
    }
    initialStyles = _normalizeStyleMap(initialStyles);
    var cellStyleMap = Object.assign({}, initialStyles);

    // -----------------------------------------------------------------
    // jspreadsheet-ce configuration
    // -----------------------------------------------------------------
    const spreadsheet = jspreadsheet(gridEl, {
        data: tableData,
        columns: columns,
        mergeCells: initialMergeCells,
        style: initialStyles,

        // Row/column headers
        rowDrag: true,
        columnDrag: true,

        // Enable right-click context menu
        contextMenu: _buildContextMenu,

        // Allow column resizing
        columnResize: true,
        rowResize: true,

        // Default dimensions
        defaultColWidth: 120,
        defaultRowHeight: 28,

        // Min dimensions
        minDimensions: [1, 1],

        // Allow new rows/cols via tab/enter
        allowInsertRow: true,
        allowInsertColumn: true,
        allowDeleteRow: true,
        allowDeleteColumn: true,
        allowManualInsertRow: true,
        allowManualInsertColumn: true,

        // Enable copy/paste
        allowCopy: true,

        // CSV delimiter
        csvDelimiter: ',',

        // Styling
        tableOverflow: true,
        tableWidth: '100%',
        tableHeight: '460px',

        // Events — mark dirty on any change
        onchange: function () {
            _setDirty(true);
            // Refresh formula cell indicators after content changes.
            setTimeout(_highlightFormulaCells, 50);
        },
        oninsertrow: function () {
            _setDirty(true);
        },
        oninsertcolumn: function () {
            _setDirty(true);
        },
        ondeleterow: function () {
            _setDirty(true);
        },
        ondeletecolumn: function () {
            _setDirty(true);
        },
        onmoverow: function () {
            _setDirty(true);
        },
        onmovecolumn: function () {
            _setDirty(true);
        },
        onresizecolumn: function () {
            _setDirty(true);
        },
        onresizerow: function () {
            _setDirty(true);
        },
        onpaste: function () {
            _setDirty(true);
        },
        onundo: function () {
            _setDirty(true);
        },
        onredo: function () {
            _setDirty(true);
        },
        onselection: function (instance, x1, y1, x2, y2) {
            selX1 = x1;
            selY1 = y1;
            selX2 = x2;
            selY2 = y2;
        },
        onmerge: function () {
            _setDirty(true);
        },
    });

    // -----------------------------------------------------------------
    // Toolbar — Add Row
    // -----------------------------------------------------------------
    addRowBtn.addEventListener('click', function () {
        try {
            if (selY1 !== null && selY2 !== null) {
                var afterRow = Math.max(selY1, selY2);
                spreadsheet.insertRow(1, afterRow, false);
            } else {
                // No selection — append at end.
                spreadsheet.insertRow(1);
            }
        } catch (e) {
            // Fallback: append at end.
            try { spreadsheet.insertRow(1); } catch (e2) { /* noop */ }
        }
        _setDirty(true);
        setTimeout(_highlightFormulaCells, 50);
    });

    // -----------------------------------------------------------------
    // Toolbar — Add Column
    // -----------------------------------------------------------------
    addColBtn.addEventListener('click', function () {
        try {
            if (selX1 !== null && selX2 !== null) {
                var afterCol = Math.max(selX1, selX2);
                spreadsheet.insertColumn(1, afterCol, false);
            } else {
                spreadsheet.insertColumn(1);
            }
        } catch (e) {
            try { spreadsheet.insertColumn(1); } catch (e2) { /* noop */ }
        }
        _setDirty(true);
        setTimeout(_highlightFormulaCells, 50);
    });

    // -----------------------------------------------------------------
    // Toolbar — Delete Row(s)
    // -----------------------------------------------------------------
    delRowBtn.addEventListener('click', function () {
        if (selY1 === null || selY2 === null) {
            return;
        }

        var r1 = Math.min(selY1, selY2);
        var r2 = Math.max(selY1, selY2);

        // Delete from bottom to top to preserve indices.
        // Always keep at least 1 row.
        try {
            for (var r = r2; r >= r1; r--) {
                if (spreadsheet.getData().length > 1) {
                    spreadsheet.deleteRow(r, 1);
                }
            }
        } catch (e) { /* noop */ }

        _setDirty(true);
        selY1 = null;
        selY2 = null;
        setTimeout(_highlightFormulaCells, 50);
    });

    // -----------------------------------------------------------------
    // Toolbar — Delete Column(s)
    // -----------------------------------------------------------------
    delColBtn.addEventListener('click', function () {
        if (selX1 === null || selX2 === null) {
            return;
        }

        var c1 = Math.min(selX1, selX2);
        var c2 = Math.max(selX1, selX2);

        // Delete from right to left to preserve indices.
        // Always keep at least 1 column.
        try {
            for (var c = c2; c >= c1; c--) {
                var colCount = spreadsheet.getData()[0] ? spreadsheet.getData()[0].length : 1;
                if (colCount > 1) {
                    spreadsheet.deleteColumn(c, 1);
                }
            }
        } catch (e) { /* noop */ }

        _setDirty(true);
        selX1 = null;
        selX2 = null;
        setTimeout(_highlightFormulaCells, 50);
    });

    // -----------------------------------------------------------------
    // Toolbar — Merge Cells
    // -----------------------------------------------------------------
    if (mergeBtn) {
        mergeBtn.addEventListener('click', function () {
            if (selX1 === null || selY1 === null || selX2 === null || selY2 === null) {
                return;
            }
            var c1 = Math.min(selX1, selX2);
            var r1 = Math.min(selY1, selY2);
            var c2 = Math.max(selX1, selX2);
            var r2 = Math.max(selY1, selY2);
            var colspan = c2 - c1 + 1;
            var rowspan = r2 - r1 + 1;
            if (colspan < 2 && rowspan < 2) {
                return; // Nothing to merge.
            }
            var cellName = _colIndexToLabel(c1) + (r1 + 1);
            try {
                spreadsheet.setMerge(cellName, colspan, rowspan);
            } catch (e) { /* noop */ }
            _setDirty(true);
        });
    }

    // -----------------------------------------------------------------
    // Toolbar — Unmerge Cells
    // -----------------------------------------------------------------
    if (unmergeBtn) {
        unmergeBtn.addEventListener('click', function () {
            if (selX1 === null || selY1 === null) {
                return;
            }
            var cellName = _colIndexToLabel(selX1) + (selY1 + 1);
            try {
                spreadsheet.removeMerge(cellName);
            } catch (e) { /* noop */ }
            _setDirty(true);
        });
    }

    // -----------------------------------------------------------------
    // Save — button click
    // -----------------------------------------------------------------
    saveBtn.addEventListener('click', _saveTable);

    // Save — keyboard shortcut Ctrl+S / Cmd+S
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            _saveTable();
        }
    });

    // -----------------------------------------------------------------
    // Mark dirty on meta field changes
    // -----------------------------------------------------------------
    if (nameInput) {
        nameInput.addEventListener('input', function () { _setDirty(true); });
    }
    if (descInput) {
        descInput.addEventListener('input', function () { _setDirty(true); });
    }

    // -----------------------------------------------------------------
    // Warn about unsaved changes when navigating away
    // -----------------------------------------------------------------
    window.addEventListener('beforeunload', function (e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // -----------------------------------------------------------------
    // Options panel — toggle listeners
    // -----------------------------------------------------------------
    _initOptionsPanel();

    // -----------------------------------------------------------------
    // Formula bar — shows raw cell value, allows editing formulas
    // -----------------------------------------------------------------
    _initFormulaBar();
    _enableCtrlEnterLineBreaks();

    // =================================================================
    // Private helpers
    // =================================================================

    /**
     * Build the context menu for the spreadsheet.
     *
     * @param {HTMLElement} obj - The spreadsheet instance element.
     * @param {number} x - Column index (or null).
     * @param {number} y - Row index (or null).
     * @param {Event} e - The contextmenu event.
     * @returns {Array} Menu items.
     */
    function _buildContextMenu(obj, x, y, e) {
        var items = [];

        // Row operations.
        if (y !== null) {
            items.push({
                title: ADVT.i18n?.addRow || 'Insert row above',
                onclick: function () {
                    spreadsheet.insertRow(1, y, true);
                    _setDirty(true);
                }
            });
            items.push({
                title: ADVT.i18n?.insertRowBelow || '아래에 행 삽입',
                onclick: function () {
                    spreadsheet.insertRow(1, y, false);
                    _setDirty(true);
                }
            });
            items.push({
                title: ADVT.i18n?.delRow || 'Delete row',
                onclick: function () {
                    if (spreadsheet.getData().length > 1) {
                        spreadsheet.deleteRow(y, 1);
                        _setDirty(true);
                    }
                }
            });
        }

        // Separator.
        if (y !== null && x !== null) {
            items.push({ type: 'line' });
        }

        // Column operations.
        if (x !== null) {
            items.push({
                title: ADVT.i18n?.addCol || 'Insert column before',
                onclick: function () {
                    spreadsheet.insertColumn(1, x, true);
                    _setDirty(true);
                }
            });
            items.push({
                title: ADVT.i18n?.insertColAfter || '오른쪽에 열 삽입',
                onclick: function () {
                    spreadsheet.insertColumn(1, x, false);
                    _setDirty(true);
                }
            });
            items.push({
                title: ADVT.i18n?.delCol || 'Delete column',
                onclick: function () {
                    if (spreadsheet.getHeaders().split(',').length > 1) {
                        spreadsheet.deleteColumn(x, 1);
                        _setDirty(true);
                    }
                }
            });
        }

        // Cell formatting option — only when a cell is selected.
        if (x !== null && y !== null) {
            items.push({ type: 'line' });
            items.push({
                title: ADVT.i18n?.cellFormatting || '셀 서식\u2026',
                onclick: function () {
                    _showFormatPopup(e.clientX, e.clientY);
                }
            });
        }

        return items;
    }

    /**
     * Persist the current editor state via the REST API.
     */
    function _saveTable() {
        if (isSaving) {
            return;
        }

        var name        = (nameInput ? nameInput.value.trim() : '') || '제목 없는 표';
        var description = descInput  ? descInput.value.trim() : '';
        var data        = spreadsheet.getData();
        var options     = _gatherOptions();

        var method = tableId ? 'PUT' : 'POST';
        var url    = tableId
            ? (ADVT.restUrl + 'tables/' + tableId)
            : (ADVT.restUrl + 'tables');

        isSaving = true;
        saveBtn.disabled = true;
        _showStatus('saving');

        $.ajax({
            url:         url,
            method:      method,
            contentType: 'application/json',
            headers:     { 'X-WP-Nonce': ADVT.restNonce },
            data:        JSON.stringify({
                name:        name,
                description: description,
                data:        data,
                options:     options,
            }),

            success: function (response) {
                isSaving = false;
                saveBtn.disabled = false;
                isDirty = false;

                // If we just created a new table, update state + URL.
                if (!tableId && response && response.id) {
                    tableId = response.id;
                    appEl.dataset.tableId = String(tableId);

                    // Update the browser URL without reloading.
                    var newUrl = new URL(window.location.href);
                    newUrl.searchParams.set('page', 'advt-table-edit');
                    newUrl.searchParams.set('table_id', String(tableId));
                    window.history.replaceState({}, '', newUrl.toString());

                    // Insert / update the shortcode hint.
                    _upsertShortcodeHint(tableId);

                    // Update page title.
                    var h1 = document.querySelector('.wp-heading-inline');
                    if (h1) {
                        h1.textContent = '테이블 편집';
                    }
                }

                _showStatus('saved');
            },

            error: function (xhr) {
                isSaving = false;
                saveBtn.disabled = false;

                var msg = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : (ADVT.i18n && ADVT.i18n.error ? ADVT.i18n.error : '저장 중 오류가 발생했습니다.');

                _showStatus('error', msg);
            },
        });
    }

    /**
     * Gather table display options from the options panel checkboxes/inputs.
     *
     * @returns {Object} Options object matching Advt_Table_Model::default_options().
     */
    function _gatherOptions() {
        var opts = {};

        // Read checkboxes.
        var checkboxes = document.querySelectorAll('#advt-options-panel input[type="checkbox"]');
        checkboxes.forEach(function (cb) {
            opts[cb.name] = cb.checked;
        });

        // Read number/text inputs.
        var inputs = document.querySelectorAll('#advt-options-panel input[type="number"], #advt-options-panel input[type="text"], #advt-options-panel input[type="color"]');
        inputs.forEach(function (inp) {
            if (inp.type === 'number') {
                opts[inp.name] = parseInt(inp.value, 10) || 0;
            } else {
                opts[inp.name] = inp.value;
            }
        });

        // Read textareas.
        var textareas = document.querySelectorAll('#advt-options-panel textarea');
        textareas.forEach(function (ta) {
            opts[ta.name] = ta.value;
        });

        // Include merge cells data from the spreadsheet.
        try {
            var merges = spreadsheet.getMerge();
            opts.merge_cells = (merges && typeof merges === 'object') ? merges : {};
        } catch (e) {
            opts.merge_cells = {};
        }

        // Include cell styles from the spreadsheet (normalized for v4 API variations).
        opts.cell_styles = _captureSpreadsheetStyles();

        return opts;
    }

    /**
     * Initialize the options panel — populate values from stored options.
     */
    function _initOptionsPanel() {
        var panel = document.getElementById('advt-options-panel');
        if (!panel) {
            return;
        }

        // Set checkbox values.
        var checkboxes = panel.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function (cb) {
            if (tableOptions.hasOwnProperty(cb.name)) {
                cb.checked = !!tableOptions[cb.name];
            }
        });

        // Set number/text input values.
        var inputs = panel.querySelectorAll('input[type="number"], input[type="text"], input[type="color"]');
        inputs.forEach(function (inp) {
            if (tableOptions.hasOwnProperty(inp.name)) {
                inp.value = tableOptions[inp.name];
            }
        });

        // Set textarea values.
        var textareas = panel.querySelectorAll('textarea');
        textareas.forEach(function (ta) {
            if (tableOptions.hasOwnProperty(ta.name)) {
                ta.value = tableOptions[ta.name];
            }
        });

        // Mark dirty on any option change.
        panel.addEventListener('change', function () {
            _setDirty(true);
        });
        panel.addEventListener('input', function (e) {
            if (e.target.tagName === 'TEXTAREA' || e.target.type === 'text' || e.target.type === 'number' || e.target.type === 'color') {
                _setDirty(true);
            }
        });
    }

    function _enableCtrlEnterLineBreaks() {
        gridEl.addEventListener('keydown', function (e) {
            if (!(e.ctrlKey || e.metaKey) || e.key !== 'Enter') {
                return;
            }

            var active = document.activeElement;
            if (!active || !gridEl.contains(active)) {
                return;
            }

            var isInput = active.tagName === 'TEXTAREA' || active.tagName === 'INPUT';
            var isEditable = active.isContentEditable === true;
            if (!isInput && !isEditable) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            if (typeof active.selectionStart === 'number' && typeof active.selectionEnd === 'number') {
                var start = active.selectionStart;
                var end = active.selectionEnd;
                var current = active.value || '';
                active.value = current.slice(0, start) + '\n' + current.slice(end);
                active.selectionStart = start + 1;
                active.selectionEnd = start + 1;
                active.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }

            if (isEditable && typeof document.execCommand === 'function') {
                document.execCommand('insertLineBreak');
            }
        }, true);
    }

    /**
     * Update the "dirty" state and status indicator.
     *
     * @param {boolean} dirty
     */
    function _setDirty(dirty) {
        isDirty = dirty;
        if (dirty) {
            var label = ADVT.i18n && ADVT.i18n.unsaved ? ADVT.i18n.unsaved : '저장되지 않은 변경사항';
            statusEl.textContent = label;
            statusEl.className   = 'advt-save-status advt-status-unsaved';
        }
    }

    /**
     * Update the status indicator.
     *
     * @param {'saving'|'saved'|'error'} type
     * @param {string} [message]
     */
    function _showStatus(type, message) {
        var i18n = ADVT.i18n || {};
        switch (type) {
            case 'saving':
                statusEl.textContent = i18n.saving || '저장 중\u2026';
                statusEl.className   = 'advt-save-status advt-status-saving';
                break;
            case 'saved':
                statusEl.textContent = message || i18n.saved || '저장됨';
                statusEl.className   = 'advt-save-status advt-status-saved';
                // Fade out after 3 s.
                setTimeout(function () {
                    if (!isDirty) {
                        statusEl.textContent = '';
                        statusEl.className   = 'advt-save-status';
                    }
                }, 3000);
                break;
            case 'error':
                statusEl.textContent = message || i18n.error || '저장 중 오류가 발생했습니다.';
                statusEl.className   = 'advt-save-status advt-status-error';
                break;
        }
    }

    /**
     * Insert or update the shortcode hint block after the page header.
     *
     * @param {number} id
     */
    function _upsertShortcodeHint(id) {
        var shortcode = '[adv_wp_table id="' + id + '" /]';
        var hint = document.querySelector('.advt-shortcode-hint');

        if (hint) {
            var code = hint.querySelector('code');
            if (code) {
                code.textContent = shortcode;
            }
        } else {
            var hr = document.querySelector('.wp-header-end');
            if (hr) {
                hint = document.createElement('p');
                hint.className = 'advt-shortcode-hint';
                hint.innerHTML = '쇼트코드: <code>' + shortcode + '</code>';
                hr.insertAdjacentElement('afterend', hint);
            }
        }
    }

    /**
     * Build an empty M x N grid.
     *
     * @param {number} rows
     * @param {number} cols
     * @returns {string[][]}
     */
    function _defaultGrid(rows, cols) {
        var grid = [];
        for (var r = 0; r < rows; r++) {
            var row = [];
            for (var c = 0; c < cols; c++) {
                row.push('');
            }
            grid.push(row);
        }
        return grid;
    }

    // =================================================================
    // Formula Bar
    // =================================================================

    /**
     * Initialize the formula bar — syncs with cell selection and allows
     * editing cell values (especially formulas) from the bar.
     */
    function _initFormulaBar() {
        var formulaInput = document.getElementById('advt-formula-input');
        var cellRefEl    = document.getElementById('advt-formula-cell-ref');

        if (!formulaInput || !cellRefEl) {
            return;
        }

        var currentCol = null;
        var currentRow = null;
        var isEditing  = false;

        // Listen for cell selection changes via jspreadsheet's onselection event.
        // jspreadsheet-ce v4 fires `onselection` on the DOM element.
        gridEl.addEventListener('onselection', function () {
            _syncFormulaBar();
        });

        // Also attach via the spreadsheet instance if available.
        // We use a MutationObserver as a fallback to detect selection class changes.
        var selectionObserver = null;

        // Use a polling approach on click/keydown as a reliable cross-version fallback.
        gridEl.addEventListener('mouseup', function () {
            setTimeout(_syncFormulaBar, 10);
        });
        gridEl.addEventListener('keyup', function (e) {
            // Arrow keys, Tab, Enter.
            if ([37, 38, 39, 40, 9, 13].indexOf(e.keyCode) !== -1) {
                setTimeout(_syncFormulaBar, 10);
            }
        });

        // When the user focuses the formula input and presses Enter, write
        // the value back to the selected cell.
        formulaInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                _writeFormulaToCell();
                // Re-focus the grid so the user can continue navigating.
                gridEl.focus();
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                _syncFormulaBar(); // Revert to current cell value.
                gridEl.focus();
            }
        });

        // On blur, also commit the value.
        formulaInput.addEventListener('blur', function () {
            if (isEditing && currentCol !== null && currentRow !== null) {
                _writeFormulaToCell();
            }
        });

        // Track when user starts typing in the formula bar.
        formulaInput.addEventListener('input', function () {
            isEditing = true;
        });

        /**
         * Read the currently selected cell and display its value in the formula bar.
         * Uses the tracked selX1/selY1 from the onselection callback.
         */
        function _syncFormulaBar() {
            isEditing = false;

            if (selX1 === null || selY1 === null) {
                cellRefEl.textContent    = '';
                formulaInput.value       = '';
                formulaInput.placeholder = ADVT.i18n?.formulaPlaceholder || 'Select a cell — formulas start with = (e.g. =SUM(A1:A5))';
                currentCol = null;
                currentRow = null;
                return;
            }

            var x = selX1;
            var y = selY1;

            currentCol = x;
            currentRow = y;

            var label = _colIndexToLabel(x) + (y + 1);
            cellRefEl.textContent = label;

            try {
                var val = spreadsheet.getValueFromCoords(x, y);
                formulaInput.value = (val !== null && val !== undefined) ? val : '';
            } catch (e) {
                formulaInput.value = '';
            }
            formulaInput.placeholder = '';
        }

        /**
         * Write the formula bar value back to the selected cell.
         */
        function _writeFormulaToCell() {
            if (currentCol === null || currentRow === null) {
                return;
            }

            var newValue = formulaInput.value;
            var oldValue = spreadsheet.getValueFromCoords(currentCol, currentRow);

            if (newValue !== oldValue) {
                spreadsheet.setValueFromCoords(currentCol, currentRow, newValue);
                _setDirty(true);
            }

            isEditing = false;
        }
    }

    /**
     * Convert a 0-based column index to an Excel-style column label.
     * 0 = A, 1 = B, … 25 = Z, 26 = AA, 27 = AB, …
     *
     * @param {number} index
     * @returns {string}
     */
    function _colIndexToLabel(index) {
        var label = '';
        var n = index;
        while (n >= 0) {
            label = String.fromCharCode((n % 26) + 65) + label;
            n = Math.floor(n / 26) - 1;
        }
        return label;
    }

    // =================================================================
    // Cell Formatting Popup
    // =================================================================

    var _formatPopup = null;

    /**
     * Create the cell formatting popup element.
     */
    function _createFormatPopup() {
        var popup = document.createElement('div');
        popup.className = 'advt-cell-format-popup';
        popup.style.display = 'none';

        popup.innerHTML =
            '<div class="advt-format-section">' +
                '<div class="advt-format-label">가로 정렬</div>' +
                '<div class="advt-format-btn-group">' +
                    '<button type="button" class="advt-fmt-btn" data-prop="text-align" data-val="left">왼쪽</button>' +
                    '<button type="button" class="advt-fmt-btn" data-prop="text-align" data-val="center">가운데</button>' +
                    '<button type="button" class="advt-fmt-btn" data-prop="text-align" data-val="right">오른쪽</button>' +
                '</div>' +
            '</div>' +
            '<div class="advt-format-section">' +
                '<div class="advt-format-label">세로 정렬</div>' +
                '<div class="advt-format-btn-group">' +
                    '<button type="button" class="advt-fmt-btn" data-prop="vertical-align" data-val="top">위</button>' +
                    '<button type="button" class="advt-fmt-btn" data-prop="vertical-align" data-val="middle">가운데</button>' +
                    '<button type="button" class="advt-fmt-btn" data-prop="vertical-align" data-val="bottom">아래</button>' +
                '</div>' +
            '</div>' +
            '<div class="advt-format-section">' +
                '<div class="advt-format-label">폰트 두께</div>' +
                '<div class="advt-format-btn-group">' +
                    '<button type="button" class="advt-fmt-btn" data-prop="font-weight" data-val="400">보통</button>' +
                    '<button type="button" class="advt-fmt-btn" data-prop="font-weight" data-val="700">굵게</button>' +
                '</div>' +
            '</div>' +
            '<div class="advt-format-section">' +
                '<div class="advt-format-label">폰트 크기 (px)</div>' +
                '<input type="number" class="advt-fmt-size" data-prop="font-size" min="8" max="96" step="1" value="14">' +
            '</div>' +
            '<div class="advt-format-section">' +
                '<div class="advt-format-label">글자색</div>' +
                '<input type="color" class="advt-fmt-color" data-prop="color" value="#000000">' +
            '</div>' +
            '<div class="advt-format-section">' +
                '<div class="advt-format-label">배경색</div>' +
                '<input type="color" class="advt-fmt-color" data-prop="background-color" value="#ffffff">' +
            '</div>';

        document.body.appendChild(popup);

        popup.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.advt-fmt-btn');
            if (btn) {
                _applyStyleToSelection(btn.dataset.prop, btn.dataset.val);
                _updateFormatPopupState();
            }
            ev.stopPropagation();
        });

        var colorInputs = popup.querySelectorAll('.advt-fmt-color');
        for (var i = 0; i < colorInputs.length; i++) {
            (function (input) {
                input.addEventListener('input', function () {
                    _applyStyleToSelection(input.dataset.prop, input.value);
                });
                input.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                });
            })(colorInputs[i]);
        }

        var sizeInput = popup.querySelector('.advt-fmt-size');
        if (sizeInput) {
            sizeInput.addEventListener('input', function () {
                var size = parseInt(sizeInput.value, 10);
                if (!size) {
                    return;
                }
                size = Math.max(8, Math.min(96, size));
                _applyStyleToSelection('font-size', size + 'px');
                _updateFormatPopupState();
            });
            sizeInput.addEventListener('click', function (ev) {
                ev.stopPropagation();
            });
        }

        popup.addEventListener('mousedown', function (ev) {
            ev.stopPropagation();
        });

        return popup;
    }

    /**
     * Show the formatting popup at the given screen position.
     */
    function _showFormatPopup(px, py) {
        if (!_formatPopup) {
            _formatPopup = _createFormatPopup();
        }

        _formatPopup.style.display = 'block';

        // Ensure the popup stays within the viewport.
        var popW = _formatPopup.offsetWidth || 220;
        var popH = _formatPopup.offsetHeight || 300;
        var vpW  = window.innerWidth;
        var vpH  = window.innerHeight;
        var left = (px + popW + 10 > vpW) ? Math.max(0, px - popW - 5) : px + 5;
        var top  = (py + popH + 10 > vpH) ? Math.max(0, vpH - popH - 10) : py + 5;

        _formatPopup.style.left = left + 'px';
        _formatPopup.style.top  = top + 'px';

        _updateFormatPopupState();

        // Close when clicking outside.
        setTimeout(function () {
            document.addEventListener('mousedown', _onDocMousedownClosePopup);
        }, 0);
    }

    function _onDocMousedownClosePopup(ev) {
        if (_formatPopup && !_formatPopup.contains(ev.target)) {
            _formatPopup.style.display = 'none';
            document.removeEventListener('mousedown', _onDocMousedownClosePopup);
        }
    }

    /**
     * Update the formatting popup button states and color values to reflect
     * the currently selected cell.
     */
    function _updateFormatPopupState() {
        if (!_formatPopup || selX1 === null || selY1 === null) {
            return;
        }
        var cellName = _colIndexToLabel(selX1) + (selY1 + 1);

        // Update button active states.
        var buttons = _formatPopup.querySelectorAll('.advt-fmt-btn');
        for (var i = 0; i < buttons.length; i++) {
            var btn = buttons[i];
            var currentVal = _getCellStyleProp(cellName, btn.dataset.prop);
            if (btn.dataset.prop === 'font-weight') {
                if (currentVal === 'bold') currentVal = '700';
                if (currentVal === 'normal') currentVal = '400';
            }
            if (currentVal === btn.dataset.val) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        }

        // Update color inputs.
        var colorInputs = _formatPopup.querySelectorAll('.advt-fmt-color');
        for (var j = 0; j < colorInputs.length; j++) {
            var input = colorInputs[j];
            var prop  = input.dataset.prop;
            var currentColor = _getCellStyleProp(cellName, prop);
            if (currentColor) {
                input.value = _normalizeColor(currentColor);
            } else {
                input.value = (prop === 'color') ? '#000000' : '#ffffff';
            }
        }

        var sizeInput = _formatPopup.querySelector('.advt-fmt-size');
        if (sizeInput) {
            var currentSize = _getCellStyleProp(cellName, 'font-size');
            var px = parseInt(currentSize, 10);
            sizeInput.value = px > 0 ? String(px) : '14';
        }
    }

    /**
     * Apply a CSS style property to all cells in the current selection.
     */
    function _applyStyleToSelection(prop, value) {
        if (selX1 === null || selY1 === null || selX2 === null || selY2 === null) {
            return;
        }
        var r1 = Math.min(selY1, selY2);
        var r2 = Math.max(selY1, selY2);
        var c1 = Math.min(selX1, selX2);
        var c2 = Math.max(selX1, selX2);

        for (var r = r1; r <= r2; r++) {
            for (var c = c1; c <= c2; c++) {
                var cellName = _colIndexToLabel(c) + (r + 1);
                _setCellStyle(cellName, prop, value);
            }
        }
        _setDirty(true);
    }

    /**
     * Clear all formatting from the current selection.
     */
    function _clearFormattingSelection() {
        if (selX1 === null || selY1 === null || selX2 === null || selY2 === null) {
            return;
        }
        var r1 = Math.min(selY1, selY2);
        var r2 = Math.max(selY1, selY2);
        var c1 = Math.min(selX1, selX2);
        var c2 = Math.max(selX1, selX2);

        for (var r = r1; r <= r2; r++) {
            for (var c = c1; c <= c2; c++) {
                var cellName = _colIndexToLabel(c) + (r + 1);
                _setCellStyle(cellName, 'text-align', '');
                _setCellStyle(cellName, 'vertical-align', '');
                _setCellStyle(cellName, 'color', '');
                _setCellStyle(cellName, 'background-color', '');
                _setCellStyle(cellName, 'font-size', '');
                _setCellStyle(cellName, 'font-weight', '');
            }
        }
        _setDirty(true);
    }

    /**
     * Get a specific CSS property value from a cell's stored style string.
     */
    function _getCellStyleProp(cellName, prop) {
        if (cellStyleMap[cellName]) {
            var localStyle = cellStyleMap[cellName];
            var localRegex = new RegExp('(?:^|;)\\s*' + prop.replace(/[-]/g, '\\-') + '\\s*:\\s*([^;]+)', 'i');
            var localMatch = String(localStyle).match(localRegex);
            if (localMatch) {
                return localMatch[1].trim();
            }
        }
        try {
            var style = spreadsheet.getStyle(cellName);
            if (!style) {
                return '';
            }
            var regex = new RegExp('(?:^|;)\\s*' + prop.replace(/[-]/g, '\\-') + '\\s*:\\s*([^;]+)', 'i');
            var match = style.match(regex);
            return match ? match[1].trim() : '';
        } catch (e) {
            return '';
        }
    }

    /**
     * Normalize a CSS color value to #rrggbb hex format for use with <input type="color">.
     */
    function _normalizeColor(color) {
        if (!color) {
            return '#000000';
        }
        color = color.trim();
        // Already 6-digit hex.
        if (/^#[0-9a-f]{6}$/i.test(color)) {
            return color;
        }
        // 3-digit hex shorthand → expand.
        if (/^#[0-9a-f]{3}$/i.test(color)) {
            return '#' + color[1] + color[1] + color[2] + color[2] + color[3] + color[3];
        }
        // rgb(r, g, b) → hex.
        var match = color.match(/^rgb\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i);
        if (match) {
            var rr = parseInt(match[1], 10).toString(16);
            var gg = parseInt(match[2], 10).toString(16);
            var bb = parseInt(match[3], 10).toString(16);
            return '#' + (rr.length < 2 ? '0' + rr : rr) + (gg.length < 2 ? '0' + gg : gg) + (bb.length < 2 ? '0' + bb : bb);
        }
        return color;
    }

    function _setCellStyle(cellName, prop, value) {
        _setCellStyleMapProp(cellName, prop, value);
        try {
            spreadsheet.setStyle(cellName, prop, value);
            return;
        } catch (e) {
            try {
                if (value) {
                    spreadsheet.setStyle(cellName, prop + ':' + value + ';');
                } else {
                    spreadsheet.setStyle(cellName, prop + ':;');
                }
            } catch (e2) { /* noop */ }
        }
    }

    function _setCellStyleMapProp(cellName, prop, value) {
        var styleObj = _parseStyleString(cellStyleMap[cellName] || '');
        if (value) {
            styleObj[prop] = value;
        } else {
            delete styleObj[prop];
        }
        var styleText = _styleObjToString(styleObj);
        if (styleText) {
            cellStyleMap[cellName] = styleText;
        } else {
            delete cellStyleMap[cellName];
        }
    }

    function _captureSpreadsheetStyles() {
        try {
            var styles = spreadsheet.getStyle();
            var normalized = _normalizeStyleMap(styles);
            if (Object.keys(normalized).length > 0) {
                cellStyleMap = normalized;
            }
        } catch (e) { /* noop */ }

        return Object.assign({}, cellStyleMap);
    }

    function _normalizeStyleMap(rawStyles) {
        var out = {};
        if (!rawStyles || typeof rawStyles !== 'object') {
            return out;
        }

        var allowed = {
            'text-align': true,
            'vertical-align': true,
            'color': true,
            'background-color': true,
            'font-size': true,
            'font-weight': true
        };

        Object.keys(rawStyles).forEach(function (cellName) {
            if (!/^[A-Z]+\d+$/.test(cellName)) {
                return;
            }

            var raw = rawStyles[cellName];
            var styleObj = {};

            if (typeof raw === 'string') {
                styleObj = _parseStyleString(raw);
            } else if (raw && typeof raw === 'object') {
                Object.keys(raw).forEach(function (key) {
                    var prop = String(key).toLowerCase().trim();
                    if (!allowed[prop]) {
                        return;
                    }
                    var value = String(raw[key] == null ? '' : raw[key]).trim();
                    if (value) {
                        styleObj[prop] = value;
                    }
                });
            }

            Object.keys(styleObj).forEach(function (prop) {
                if (!allowed[prop]) {
                    delete styleObj[prop];
                }
            });

            var styleText = _styleObjToString(styleObj);
            if (styleText) {
                out[cellName] = styleText;
            }
        });

        return out;
    }

    function _parseStyleString(styleText) {
        var out = {};
        String(styleText || '').split(';').forEach(function (part) {
            var chunk = part.trim();
            if (!chunk) {
                return;
            }
            var idx = chunk.indexOf(':');
            if (idx < 0) {
                return;
            }
            var prop = chunk.slice(0, idx).trim().toLowerCase();
            var val  = chunk.slice(idx + 1).trim();
            if (prop && val) {
                out[prop] = val;
            }
        });
        return out;
    }

    function _styleObjToString(styleObj) {
        var parts = [];
        Object.keys(styleObj).forEach(function (prop) {
            var value = String(styleObj[prop] == null ? '' : styleObj[prop]).trim();
            if (value) {
                parts.push(prop + ':' + value);
            }
        });
        return parts.join(';');
    }

    /**
     * Mark cells containing formulas with a CSS class for visual indication.
     * Called after data load and after save.
     */
    function _highlightFormulaCells() {
        var data = spreadsheet.getData();
        if (!data) return;

        var table = gridEl.querySelector('.jexcel tbody');
        if (!table) return;

        var rows = table.querySelectorAll('tr');
        for (var r = 0; r < data.length; r++) {
            if (!rows[r]) continue;
            // Skip the first cell (row header).
            var cells = rows[r].querySelectorAll('td');
            for (var c = 0; c < data[r].length; c++) {
                var td = cells[c + 1]; // +1 because first td is row number.
                if (!td) continue;
                var val = String(data[r][c] || '');
                if (val.charAt(0) === '=') {
                    td.classList.add('advt-formula-cell');
                } else {
                    td.classList.remove('advt-formula-cell');
                }
            }
        }
    }

    // Run formula highlighting after initial render.
    setTimeout(_highlightFormulaCells, 200);

})(jQuery);
