/**
 * Advanced WP Tables — Admin JS
 *
 * Phase 1: Basic event handlers (delete, copy).
 * Phase 2 will add the full grid editor.
 */

(function ($) {
    'use strict';

    const ADVT = window.advtAdmin || {};

    /**
     * Handle delete table action.
     */
    $(document).on('click', '.advt-delete-table', function (e) {
        e.preventDefault();

        if (!confirm(ADVT.i18n?.confirmDelete || 'Delete this table?')) {
            return;
        }

        const tableId = $(this).data('table-id');

        $.ajax({
            url: ADVT.ajaxUrl,
            method: 'POST',
            data: {
                action: 'advt_delete_table',
                table_id: tableId,
                _wpnonce: ADVT.nonce,
            },
            success: function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data?.message || ADVT.i18n?.error);
                }
            },
            error: function () {
                alert(ADVT.i18n?.error);
            },
        });
    });

    /**
     * Handle copy (duplicate) table action.
     */
    $(document).on('click', '.advt-copy-table', function (e) {
        e.preventDefault();

        const tableId = $(this).data('table-id');

        $.ajax({
            url: ADVT.ajaxUrl,
            method: 'POST',
            data: {
                action: 'advt_copy_table',
                table_id: tableId,
                _wpnonce: ADVT.nonce,
            },
            success: function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data?.message || ADVT.i18n?.error);
                }
            },
            error: function () {
                alert(ADVT.i18n?.error);
            },
        });
    });

})(jQuery);
