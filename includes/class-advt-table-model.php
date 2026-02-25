<?php
/**
 * Table Model — handles all CRUD operations for the advt_tables database table.
 *
 * @package AdvancedWPTables
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Advt_Table_Model {

    /**
     * Get the DB table name.
     */
    private static function table(): string {
        return Advt_Installer::get_table_name();
    }

    /**
     * Get a single table by ID.
     *
     * @return object|null Database row or null.
     */
    public static function get( int $id ): ?object {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE id = %d",
                self::table(),
                $id
            )
        );

        return $row ?: null;
    }

    /**
     * Get all tables, optionally paginated.
     *
     * @return object[] Array of table rows.
     */
    public static function get_all( int $per_page = 20, int $page = 1, string $orderby = 'updated_at', string $order = 'DESC' ): array {
        global $wpdb;

        $allowed_orderby = [ 'id', 'name', 'author_id', 'created_at', 'updated_at' ];
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'updated_at';
        }
        $order = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

        $offset = ( $page - 1 ) * $per_page;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                self::table(),
                $per_page,
                $offset
            )
        );

        return $results ?: [];
    }

    /**
     * Count total tables.
     */
    public static function count(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM %i", self::table() )
        );
    }

    /**
     * Insert a new table.
     *
     * @param array{name: string, description?: string, data?: array, options?: array, author_id?: int} $args
     * @return int|false Inserted ID or false on failure.
     */
    public static function insert( array $args ): int|false {
        global $wpdb;

        $defaults = [
            'name'        => __( 'Untitled Table', 'advanced-wp-tables' ),
            'description' => '',
            'data'        => self::default_data(),
            'options'     => self::default_options(),
            'author_id'   => get_current_user_id(),
        ];

        $args = wp_parse_args( $args, $defaults );

        $result = $wpdb->insert(
            self::table(),
            [
                'name'        => sanitize_text_field( $args['name'] ),
                'description' => sanitize_textarea_field( $args['description'] ),
                'data'        => is_string( $args['data'] ) ? $args['data'] : wp_json_encode( $args['data'] ),
                'options'     => is_string( $args['options'] ) ? $args['options'] : wp_json_encode( $args['options'] ),
                'author_id'   => absint( $args['author_id'] ),
            ],
            [ '%s', '%s', '%s', '%s', '%d' ]
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing table.
     *
     * @param int   $id   Table ID.
     * @param array $args Fields to update.
     * @return bool True on success.
     */
    public static function update( int $id, array $args ): bool {
        global $wpdb;

        $data   = [];
        $format = [];

        if ( isset( $args['name'] ) ) {
            $data['name'] = sanitize_text_field( $args['name'] );
            $format[]     = '%s';
        }

        if ( isset( $args['description'] ) ) {
            $data['description'] = sanitize_textarea_field( $args['description'] );
            $format[]            = '%s';
        }

        if ( isset( $args['data'] ) ) {
            $data['data'] = is_string( $args['data'] ) ? $args['data'] : wp_json_encode( $args['data'] );
            $format[]     = '%s';
        }

        if ( isset( $args['options'] ) ) {
            $data['options'] = is_string( $args['options'] ) ? $args['options'] : wp_json_encode( $args['options'] );
            $format[]        = '%s';
        }

        if ( empty( $data ) ) {
            return false;
        }

        $result = $wpdb->update(
            self::table(),
            $data,
            [ 'id' => $id ],
            $format,
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Delete a table by ID.
     */
    public static function delete( int $id ): bool {
        global $wpdb;

        $result = $wpdb->delete(
            self::table(),
            [ 'id' => $id ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Copy (duplicate) a table.
     */
    public static function copy( int $id ): int|false {
        $source = self::get( $id );
        if ( ! $source ) {
            return false;
        }

        return self::insert( [
            'name'        => sprintf(
                /* translators: %s: original table name */
                __( '%s (Copy)', 'advanced-wp-tables' ),
                $source->name
            ),
            'description' => $source->description,
            'data'        => $source->data,
            'options'     => $source->options,
        ] );
    }

    /**
     * Default table data — a 5×5 empty grid.
     *
     * Format: 2D array where [row][column] = cell value.
     *
     * @return array<int, array<int, string>>
     */
    public static function default_data(): array {
        $rows = 5;
        $cols = 5;
        $data = [];

        for ( $r = 0; $r < $rows; $r++ ) {
            $row = [];
            for ( $c = 0; $c < $cols; $c++ ) {
                $row[] = '';
            }
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Default table display/rendering options.
     *
     * @return array<string, mixed>
     */
    public static function default_options(): array {
        return [
            // DataTables features.
            'use_datatables'     => false,
            'pagination'         => false,
            'searching'          => false,
            'ordering'           => false,
            'page_length'        => 25,

            // Header / first row treatment.
            'first_row_header'   => true,

            // Responsive & layout.
            'responsive'         => true,
            'scroll_x'           => false,
            'fixed_header'       => false,
            'fixed_columns'      => false,

            // Styling.
            'alternating_colors' => true,
            'hover_highlight'    => true,
            'custom_css'         => '.advt-table-wrap{margin:0 0;}',
            'extra_css_classes'  => '',
            'inner_border_color' => '#e0e0e0',
            'cell_padding'       => '10px 14px',

            // Cell merge data — { "A1": [colspan, rowspan], ... }
            'merge_cells'        => new \stdClass(),

            // Cell styles — { "A1": "text-align:center;color:#ff0000", ... }
            'cell_styles'        => new \stdClass(),

            // Pro features.
            'buttons'            => false,  // CSV/Excel/PDF export buttons on frontend.
            'column_visibility'  => false,
        ];
    }
}
