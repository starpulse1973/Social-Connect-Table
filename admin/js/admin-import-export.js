/**
 * Advanced WP Tables — Import / Export JS (Phase 4)
 *
 * Handles file upload for import and download link generation for export.
 *
 * Dependencies: jquery, advt-admin (localized data).
 */

/* global advtAdmin, jQuery */

(function ($) {
    'use strict';

    var ADVT = window.advtAdmin || {};

    // =================================================================
    // IMPORT
    // =================================================================

    var $importForm   = $('#advt-import-form');
    var $importBtn    = $('#advt-import-btn');
    var $importStatus = $('#advt-import-status');
    var $importResult = $('#advt-import-result');

    if ($importForm.length) {
        $importForm.on('submit', function (e) {
            e.preventDefault();

            var fileInput = document.getElementById('advt-import-file');
            if (!fileInput || !fileInput.files || !fileInput.files.length) {
                _showImportStatus('error', ADVT.i18n?.error || '파일을 선택해 주세요.');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'advt_import_table');
            formData.append('_wpnonce', ADVT.nonce);
            formData.append('import_file', fileInput.files[0]);

            var nameVal = $('#advt-import-name').val();
            if (nameVal) {
                formData.append('import_name', nameVal);
            }

            var targetVal = $('#advt-import-target').val();
            if (targetVal) {
                formData.append('import_target', targetVal);
            }

            $importBtn.prop('disabled', true);
            _showImportStatus('loading', ADVT.i18n?.saving || '가져오는 중\u2026');
            $importResult.hide();

            $.ajax({
                url: ADVT.ajaxUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,

                success: function (response) {
                    $importBtn.prop('disabled', false);

                    if (response.success && response.data) {
                        var msg = response.data.message || '가져오기에 성공했습니다.';
                        msg += ' (' + (response.data.rows || 0) + '행, ' + (response.data.cols || 0) + '열)';

                        _showImportStatus('success', msg);

                        // Show link to the imported table.
                        var link = '<a href="' + window.location.origin + '/wp-admin/admin.php?page=advt-table-edit&table_id=' + response.data.id + '" class="button">';
                        link += '테이블 #' + response.data.id + ' 편집</a>';

                        $importResult.html(
                            '<div class="notice notice-success"><p>' + msg + ' ' + link + '</p></div>'
                        ).show();

                        // Reset the form.
                        $importForm[0].reset();
                    } else {
                        var errMsg = (response.data && response.data.message) || ADVT.i18n?.error || '가져오기에 실패했습니다.';
                        _showImportStatus('error', errMsg);
                    }
                },

                error: function (xhr) {
                    $importBtn.prop('disabled', false);
                    var msg = '가져오기에 실패했습니다.';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        msg = xhr.responseJSON.data.message;
                    }
                    _showImportStatus('error', msg);
                },
            });
        });
    }

    /**
     * Update the import status indicator.
     */
    function _showImportStatus(type, message) {
        $importStatus.text(message);
        $importStatus.removeClass('advt-ie-status-loading advt-ie-status-success advt-ie-status-error');

        switch (type) {
            case 'loading':
                $importStatus.addClass('advt-ie-status-loading');
                break;
            case 'success':
                $importStatus.addClass('advt-ie-status-success');
                break;
            case 'error':
                $importStatus.addClass('advt-ie-status-error');
                break;
        }
    }

    // =================================================================
    // EXPORT
    // =================================================================

    var $exportForm = $('#advt-export-form');

    if ($exportForm.length) {
        $exportForm.on('submit', function (e) {
            e.preventDefault();

            var tableId = $('#advt-export-table').val();
            var format  = $('#advt-export-format').val();

            if (!tableId) {
                alert(ADVT.i18n?.error || '테이블을 선택해 주세요.');
                return;
            }

            // Build the download URL and trigger it.
            var url = ADVT.ajaxUrl;
            url += '?action=advt_export_table';
            url += '&table_id=' + encodeURIComponent(tableId);
            url += '&format=' + encodeURIComponent(format);
            url += '&_wpnonce=' + encodeURIComponent(ADVT.nonce);

            // Open in a new window/tab to trigger the download.
            window.location.href = url;
        });
    }

})(jQuery);
