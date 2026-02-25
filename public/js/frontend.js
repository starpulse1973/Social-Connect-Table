/**
 * Advanced WP Tables — Frontend JS (Phase 3)
 *
 * Initializes DataTables on every [adv_wp_table] shortcode instance.
 *
 * Table configurations are passed from PHP via wp_localize_script as:
 *   advtFrontend.tables = {
 *       'advt-table-1': { paging: true, searching: true, ... },
 *       'advt-table-5': { paging: false, responsive: true, ... },
 *   }
 *
 * Dependencies: jquery, datatables (+ extensions as needed).
 */

/* global advtFrontend, jQuery */

(function ($) {
    'use strict';

    var ADVT = window.advtFrontend || {};
    var tables = ADVT.tables || {};

    $(document).ready(function () {

        // Iterate over each table the shortcode rendered on this page.
        $.each(tables, function (tableId, config) {
            var $table = $('#' + tableId);

            if (!$table.length || !$table.is('table')) {
                return; // continue
            }

            // Bail if config is empty (DataTables disabled for this table).
            if (!config || typeof config !== 'object' || $.isEmptyObject(config)) {
                return;
            }

            try {
                // Initialize DataTables with the config from PHP.
                $table.DataTable(config);
            } catch (err) {
                // Log but don't break other tables on the page.
                if (window.console && console.error) {
                    console.error('Advanced WP Tables: Failed to initialize DataTables on #' + tableId, err);
                }
            }
        });

        // Fallback: initialize any advt-table that wasn't covered by the localized config.
        // This handles edge cases like caching plugins that serve stale HTML.
        $('table.advt-table').each(function () {
            var $table = $(this);
            var id = $table.attr('id');

            // Skip if already initialized by the loop above.
            if ($.fn.DataTable.isDataTable($table)) {
                return;
            }

            // Try to parse data attribute config (legacy / fallback).
            var config = {};
            try {
                var raw = $table.attr('data-advt-options');
                if (raw) {
                    config = JSON.parse(raw);
                }
            } catch (e) {
                // Ignore parse errors.
            }

            if ($.isEmptyObject(config)) {
                return;
            }

            try {
                $table.DataTable(config);
            } catch (err) {
                if (window.console && console.error) {
                    console.error('Advanced WP Tables: Fallback init failed on #' + id, err);
                }
            }
        });

    });

})(jQuery);
