<?php
/**
 * Handles plugin activation, deactivation, and database schema management.
 *
 * @package AdvancedWPTables
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Advt_Installer {

    /**
     * Custom table name (without prefix).
     */
    private const TABLE_SUFFIX = 'advt_tables';

    /**
     * Run on plugin activation.
     */
    public function activate(): void {
        $this->create_tables();
        $this->set_default_options();

        // Store the DB version so we can run migrations later.
        update_option( 'advt_db_version', ADVT_DB_VERSION );

        // Flush rewrite rules (in case we register CPTs or endpoints later).
        flush_rewrite_rules();
    }

    /**
     * Run on plugin deactivation.
     */
    public function deactivate(): void {
        // Clean up transients.
        delete_transient( 'advt_activation_redirect' );

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Create the custom database tables using dbDelta.
     */
    private function create_tables(): void {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset_collate = $wpdb->get_charset_collate();

        /**
         * Schema:
         * - id            : Auto-increment primary key (table ID).
         * - name          : Human-readable table name.
         * - description   : Optional description.
         * - data          : Raw table cell data stored as JSON (LONGTEXT for large tables).
         * - options        : Table-level settings/options stored as JSON.
         * - author_id     : The WP user who created the table.
         * - created_at    : Creation timestamp.
         * - updated_at    : Last modification timestamp.
         */
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT NOT NULL,
            data LONGTEXT NOT NULL,
            options LONGTEXT NOT NULL,
            author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY author_id (author_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Set default plugin options on first activation.
     */
    private function set_default_options(): void {
        $defaults = [
            'advt_default_options' => wp_json_encode( [
                'use_datatables'    => false,
                'pagination'        => false,
                'searching'         => false,
                'ordering'          => false,
                'responsive'        => true,
                'page_length'       => 25,
                'fixed_header'      => false,
                'fixed_columns'     => false,
                'alternating_colors'=> true,
                'hover_highlight'   => true,
                'custom_css'        => '.advt-table-wrap{margin:0 0;}',
                'inner_border_color'=> '#e0e0e0',
                'cell_padding'      => '10px 14px',
            ] ),
        ];

        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }
    }

    /**
     * Get the full table name (with WP prefix).
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }
}
