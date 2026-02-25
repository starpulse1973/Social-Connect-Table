<?php
/**
 * Frontend controller — registers shortcodes and enqueues public assets.
 *
 * Phase 3: Full DataTables integration with extensions.
 * Phase 5: Formula evaluation via Advt_Formula_Parser before rendering.
 *
 * Asset loading strategy:
 *  1. During `wp_enqueue_scripts`, detect shortcodes in post content → enqueue CSS in <head>.
 *  2. During shortcode rendering, collect per-table configs in $this->table_configs.
 *  3. During `wp_footer`, enqueue JS and pass configs via wp_localize_script.
 *
 * @package AdvancedWPTables
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Advt_Frontend {

    /**
     * Track whether we need to enqueue DataTables assets on this page.
     */
    private bool $needs_assets = false;

    /**
     * Collected per-table DataTables configurations, keyed by unique table HTML ID.
     *
     * @var array<string, array>
     */
    private array $table_configs = [];

    /**
     * Track which DataTables extensions are needed across all tables on the page.
     *
     * @var array<string, bool>
     */
    private array $extensions_needed = [
        'responsive'    => false,
        'fixedheader'   => false,
        'fixedcolumns'  => false,
        'buttons'       => false,
    ];

    /**
     * Register public-facing hooks.
     */
    public function register_hooks(): void {
        add_shortcode( 'adv_wp_table', [ $this, 'render_shortcode' ] );

        // Enqueue CSS early (in <head>) when we can detect shortcodes in post content.
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_preload_styles' ] );

        // Enqueue JS in footer after all shortcodes have rendered and configs are collected.
        add_action( 'wp_footer', [ $this, 'enqueue_footer_assets' ], 5 );
    }

    /**
     * Pre-detect shortcodes in the current post content and enqueue CSS in <head>.
     *
     * This prevents the Flash of Unstyled Content (FOUC) that occurs when CSS
     * is enqueued in the footer.
     */
    public function maybe_preload_styles(): void {
        global $post;

        if ( ! is_singular() || ! $post ) {
            return;
        }

        if ( ! has_shortcode( $post->post_content, 'adv_wp_table' ) ) {
            return;
        }

        $this->enqueue_styles();
    }

    /**
     * Shortcode handler: [adv_wp_table id="1"]
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_shortcode( array|string $atts ): string {
        $atts = shortcode_atts(
            [
                'id'     => 0,
                'margin' => '',
            ],
            $atts,
            'adv_wp_table'
        );

        $table_id = absint( $atts['id'] );
        if ( ! $table_id ) {
            return '<!-- Advanced WP Tables: No table ID provided. -->';
        }

        $table = Advt_Table_Model::get( $table_id );
        if ( ! $table ) {
            return '<!-- Advanced WP Tables: Table not found. -->';
        }

        $this->needs_assets = true;

        // Decode stored JSON.
        $data    = json_decode( $table->data, true ) ?: [];
        $options = json_decode( $table->options, true ) ?: Advt_Table_Model::default_options();

        // Evaluate formulas (cells starting with "=") before rendering.
        if ( ! empty( $data ) ) {
            $parser = new Advt_Formula_Parser();
            $data   = $parser->process( $data );
        }

        // Collect DataTables config for this table.
        $unique_id = 'advt-table-' . $table_id;
        $dt_config = $this->get_datatables_config( $options );

        if ( ! empty( $dt_config ) ) {
            $this->table_configs[ $unique_id ] = $dt_config;
        }

        // Track which extensions are needed.
        $this->detect_extensions( $options );

        return $this->build_table_html( $table_id, $data, $options, $atts['margin'] );
    }

    /**
     * Generate the HTML <table> markup for a given dataset.
     *
     * @param int    $table_id Table ID.
     * @param array  $data     2-D cell data.
     * @param array  $options  Table options.
     * @param string $margin   Optional margin override from shortcode attribute.
     */
    private function build_table_html( int $table_id, array $data, array $options, string $margin = '' ): string {
        if ( empty( $data ) ) {
            return '<p>' . esc_html__( '이 테이블에는 데이터가 없습니다.', 'advanced-wp-tables' ) . '</p>';
        }

        // Parse merge_cells from options → build a lookup map.
        // Stored format: { "A1": [colspan, rowspan], ... }
        // We convert to a [row][col] → [colspan, rowspan] map + a skip set.
        $merge_map  = []; // [row][col] => [colspan, rowspan]
        $skip_cells = []; // [row][col] => true (cells hidden by a merge)
        $has_merges = false;

        if ( ! empty( $options['merge_cells'] ) && is_array( (array) $options['merge_cells'] ) ) {
            $raw_merges = (array) $options['merge_cells'];
            foreach ( $raw_merges as $cell_name => $span ) {
                if ( ! is_array( $span ) || count( $span ) < 2 ) {
                    continue;
                }
                $parsed = $this->parse_cell_name( (string) $cell_name );
                if ( $parsed === null ) {
                    continue;
                }
                [ $mc, $mr ] = $parsed; // 0-based col, row
                $colspan = max( 1, (int) $span[0] );
                $rowspan = max( 1, (int) $span[1] );

                if ( $colspan < 2 && $rowspan < 2 ) {
                    continue;
                }

                $merge_map[ $mr ][ $mc ] = [ $colspan, $rowspan ];
                $has_merges = true;

                // Mark cells that should be skipped.
                for ( $dr = 0; $dr < $rowspan; $dr++ ) {
                    for ( $dc = 0; $dc < $colspan; $dc++ ) {
                        if ( $dr === 0 && $dc === 0 ) {
                            continue; // The merge anchor cell itself.
                        }
                        $skip_cells[ $mr + $dr ][ $mc + $dc ] = true;
                    }
                }
            }
        }

        // Parse cell_styles from options.
        $cell_styles = [];
        if ( ! empty( $options['cell_styles'] ) && is_array( (array) $options['cell_styles'] ) ) {
            $cell_styles = (array) $options['cell_styles'];
        }

        // If merges exist, force DataTables off (tbody colspan/rowspan not supported).
        $dt_config = $has_merges ? [] : $this->get_datatables_config( $options );

        // If merges exist and DT was requested, remove the config we already collected.
        if ( $has_merges ) {
            $unique_id_check = 'advt-table-' . $table_id;
            unset( $this->table_configs[ $unique_id_check ] );
        }

        $use_header   = ! empty( $options['first_row_header'] );
        $css_classes   = 'advt-table display nowrap';
        $css_classes  .= ! empty( $options['alternating_colors'] ) ? ' stripe' : '';
        $css_classes  .= ! empty( $options['hover_highlight'] ) ? ' hover' : '';
        $css_classes  .= ! empty( $options['extra_css_classes'] ) ? ' ' . esc_attr( $options['extra_css_classes'] ) : '';

        $unique_id = 'advt-table-' . $table_id;

        // Determine column count.
        $col_count = ! empty( $data[0] ) ? count( $data[0] ) : 0;

        // Wrap style — optional margin override.
        $wrap_style = '';
        if ( $margin !== '' ) {
            $wrap_style = ' style="margin:' . esc_attr( $margin ) . '"';
        }

        ob_start();
        ?>
        <div class="advt-table-wrap" id="<?php echo esc_attr( $unique_id . '-wrap' ); ?>"<?php echo $wrap_style; ?>>
            <?php
            $inner_border_color = ! empty( $options['inner_border_color'] ) ? sanitize_hex_color( (string) $options['inner_border_color'] ) : '';
            $cell_padding       = ! empty( $options['cell_padding'] ) ? sanitize_text_field( (string) $options['cell_padding'] ) : '';
            if ( $inner_border_color || $cell_padding ) :
                $cell_rule_parts = [];
                if ( $inner_border_color ) {
                    $cell_rule_parts[] = 'border-color:' . $inner_border_color;
                }
                if ( $cell_padding ) {
                    $cell_rule_parts[] = 'padding:' . $cell_padding;
                }
                ?>
                <style>
                    #<?php echo esc_html( $unique_id ); ?> th,
                    #<?php echo esc_html( $unique_id ); ?> td { <?php echo esc_html( implode( ';', $cell_rule_parts ) ); ?>; }
                </style>
            <?php endif; ?>
            <?php if ( ! empty( $options['custom_css'] ) ) : ?>
                <style><?php echo wp_strip_all_tags( $options['custom_css'] ); ?></style>
            <?php endif; ?>

            <table id="<?php echo esc_attr( $unique_id ); ?>"
                   class="<?php echo esc_attr( trim( $css_classes ) ); ?>"
                   data-advt-options='<?php echo esc_attr( wp_json_encode( $dt_config ) ); ?>'
                   style="width:100%">
                <?php if ( $use_header && ! empty( $data[0] ) ) : ?>
                    <thead>
                        <tr>
                            <?php for ( $c = 0; $c < $col_count; $c++ ) :
                                if ( ! empty( $skip_cells[0][ $c ] ) ) {
                                    continue;
                                }
                                $attrs = 'class="advt-cell-0-' . $c . '"';
                                if ( isset( $merge_map[0][ $c ] ) ) {
                                    $attrs .= ' colspan="' . $merge_map[0][ $c ][0] . '" rowspan="' . $merge_map[0][ $c ][1] . '"';
                                }
                                $cs_name = $this->col_index_to_label( $c ) . '1';
                                if ( ! empty( $cell_styles[ $cs_name ] ) ) {
                                    $attrs .= ' style="' . esc_attr( $cell_styles[ $cs_name ] ) . '"';
                                }
                            ?>
                                <th <?php echo $attrs; ?>><?php echo wp_kses_post( (string) ( $data[0][ $c ] ?? '' ) ); ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ( $i = 1, $count = count( $data ); $i < $count; $i++ ) : ?>
                            <tr>
                                <?php
                                $row = $data[ $i ];
                                for ( $c = 0; $c < $col_count; $c++ ) :
                                    if ( ! empty( $skip_cells[ $i ][ $c ] ) ) {
                                        continue;
                                    }
                                    $attrs = 'class="advt-cell-' . $i . '-' . $c . '"';
                                    if ( isset( $merge_map[ $i ][ $c ] ) ) {
                                        $attrs .= ' colspan="' . $merge_map[ $i ][ $c ][0] . '" rowspan="' . $merge_map[ $i ][ $c ][1] . '"';
                                    }
                                    $cs_name = $this->col_index_to_label( $c ) . ( $i + 1 );
                                    if ( ! empty( $cell_styles[ $cs_name ] ) ) {
                                        $attrs .= ' style="' . esc_attr( $cell_styles[ $cs_name ] ) . '"';
                                    }
                                ?>
                                    <td <?php echo $attrs; ?>><?php echo wp_kses_post( (string) ( $row[ $c ] ?? '' ) ); ?></td>
                                <?php endfor; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                <?php else : ?>
                    <tbody>
                        <?php foreach ( $data as $r => $row ) : ?>
                            <tr>
                                <?php for ( $c = 0; $c < ( is_array( $row ) ? count( $row ) : 0 ); $c++ ) :
                                    if ( ! empty( $skip_cells[ $r ][ $c ] ) ) {
                                        continue;
                                    }
                                    $attrs = 'class="advt-cell-' . $r . '-' . $c . '"';
                                    if ( isset( $merge_map[ $r ][ $c ] ) ) {
                                        $attrs .= ' colspan="' . $merge_map[ $r ][ $c ][0] . '" rowspan="' . $merge_map[ $r ][ $c ][1] . '"';
                                    }
                                    $cs_name = $this->col_index_to_label( $c ) . ( $r + 1 );
                                    if ( ! empty( $cell_styles[ $cs_name ] ) ) {
                                        $attrs .= ' style="' . esc_attr( $cell_styles[ $cs_name ] ) . '"';
                                    }
                                ?>
                                    <td <?php echo $attrs; ?>><?php echo wp_kses_post( (string) ( $row[ $c ] ?? '' ) ); ?></td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Parse a cell name like "A1" into [col, row] (0-based).
     *
     * @return array{int,int}|null [col, row] or null if invalid.
     */
    private function parse_cell_name( string $name ): ?array {
        if ( ! preg_match( '/^([A-Z]+)(\d+)$/', $name, $m ) ) {
            return null;
        }
        $col = 0;
        $letters = $m[1];
        for ( $i = 0, $len = strlen( $letters ); $i < $len; $i++ ) {
            $col = $col * 26 + ( ord( $letters[ $i ] ) - ord( 'A' ) + 1 );
        }
        $col -= 1; // 0-based
        $row = (int) $m[2] - 1; // 0-based

        return [ $col, $row ];
    }

    /**
     * Convert a 0-based column index to an Excel-style column label (A, B, …, Z, AA, …).
     */
    private function col_index_to_label( int $index ): string {
        $label = '';
        $n     = $index;
        while ( $n >= 0 ) {
            $label = chr( ( $n % 26 ) + 65 ) . $label;
            $n     = intdiv( $n, 26 ) - 1;
        }
        return $label;
    }

    /**
     * Convert stored options to a DataTables configuration object.
     *
     * @return array<string, mixed> DataTables init config. Empty if DataTables is disabled.
     */
    private function get_datatables_config( array $options ): array {
        if ( empty( $options['use_datatables'] ) ) {
            return [];
        }

        $use_responsive    = ! empty( $options['responsive'] );
        $use_fixed_columns = ! empty( $options['fixed_columns'] );
        $use_scroll_x      = ! empty( $options['scroll_x'] );

        // FixedColumns requires scrollX — auto-enable it.
        if ( $use_fixed_columns ) {
            $use_scroll_x = true;
        }

        // Responsive and FixedColumns conflict — FixedColumns takes priority.
        if ( $use_fixed_columns && $use_responsive ) {
            $use_responsive = false;
        }

        $config = [
            'paging'     => ! empty( $options['pagination'] ),
            'searching'  => ! empty( $options['searching'] ),
            'ordering'   => ! empty( $options['ordering'] ),
            'pageLength' => (int) ( $options['page_length'] ?? 25 ),
            'responsive' => $use_responsive,
            'scrollX'    => $use_scroll_x,
        ];

        // Auto-adjust: disable responsive in config if scrollX is active
        // (they don't combine well).
        if ( $use_scroll_x && $use_responsive ) {
            $config['responsive'] = false;
        }

        // FixedHeader extension.
        if ( ! empty( $options['fixed_header'] ) ) {
            $config['fixedHeader'] = true;
        }

        // FixedColumns extension.
        if ( $use_fixed_columns ) {
            $config['fixedColumns'] = [
                'left' => 1,
            ];
        }

        // Buttons extension (frontend export).
        if ( ! empty( $options['buttons'] ) || ! empty( $options['column_visibility'] ) ) {
            $buttons = [];

            if ( ! empty( $options['buttons'] ) ) {
                $buttons[] = [
                    'extend' => 'copyHtml5',
                    'text'   => esc_html__( 'Copy', 'advanced-wp-tables' ),
                ];
                $buttons[] = [
                    'extend' => 'csvHtml5',
                    'text'   => esc_html__( 'CSV', 'advanced-wp-tables' ),
                ];
                $buttons[] = [
                    'extend' => 'excelHtml5',
                    'text'   => esc_html__( 'Excel', 'advanced-wp-tables' ),
                ];
                $buttons[] = [
                    'extend' => 'print',
                    'text'   => esc_html__( 'Print', 'advanced-wp-tables' ),
                ];
            }

            if ( ! empty( $options['column_visibility'] ) ) {
                $buttons[] = [
                    'extend' => 'colvis',
                    'text'   => esc_html__( 'Columns', 'advanced-wp-tables' ),
                ];
            }

            $config['buttons'] = $buttons;

            // DataTables needs dom option to position the buttons.
            $config['dom'] = 'Bfrtip';
        }

        // Language strings.
        $config['language'] = [
            'search'        => esc_html__( 'Search:', 'advanced-wp-tables' ),
            'lengthMenu'    => sprintf(
                /* translators: %s: number select dropdown */
                esc_html__( 'Show %s entries', 'advanced-wp-tables' ),
                '_MENU_'
            ),
            'info'          => sprintf(
                /* translators: 1: start entry, 2: end entry, 3: total entries */
                esc_html__( 'Showing %1$s to %2$s of %3$s entries', 'advanced-wp-tables' ),
                '_START_',
                '_END_',
                '_TOTAL_'
            ),
            'infoEmpty'     => esc_html__( 'Showing 0 to 0 of 0 entries', 'advanced-wp-tables' ),
            'infoFiltered'  => sprintf(
                /* translators: %s: max entries */
                esc_html__( '(filtered from %s total entries)', 'advanced-wp-tables' ),
                '_MAX_'
            ),
            'zeroRecords'   => esc_html__( 'No matching records found', 'advanced-wp-tables' ),
            'emptyTable'    => esc_html__( 'No data available in table', 'advanced-wp-tables' ),
            'paginate'      => [
                'first'    => esc_html__( 'First', 'advanced-wp-tables' ),
                'last'     => esc_html__( 'Last', 'advanced-wp-tables' ),
                'next'     => esc_html__( 'Next', 'advanced-wp-tables' ),
                'previous' => esc_html__( 'Previous', 'advanced-wp-tables' ),
            ],
        ];

        return $config;
    }

    /**
     * Detect which DataTables extensions a table needs and record them.
     */
    private function detect_extensions( array $options ): void {
        if ( empty( $options['use_datatables'] ) ) {
            return;
        }

        if ( ! empty( $options['responsive'] ) ) {
            $this->extensions_needed['responsive'] = true;
        }
        if ( ! empty( $options['fixed_header'] ) ) {
            $this->extensions_needed['fixedheader'] = true;
        }
        if ( ! empty( $options['fixed_columns'] ) ) {
            $this->extensions_needed['fixedcolumns'] = true;
        }
        if ( ! empty( $options['buttons'] ) || ! empty( $options['column_visibility'] ) ) {
            $this->extensions_needed['buttons'] = true;
        }
    }

    // -------------------------------------------------------------------------
    // Asset Enqueuing
    // -------------------------------------------------------------------------

    /**
     * Enqueue DataTables CSS (called early for <head> placement).
     */
    private function enqueue_styles(): void {
        // DataTables core CSS.
        wp_enqueue_style(
            'datatables',
            'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css',
            [],
            '1.13.7'
        );

        // Responsive extension CSS — always loaded as it's lightweight.
        wp_enqueue_style(
            'datatables-responsive',
            'https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css',
            [ 'datatables' ],
            '2.5.0'
        );

        // FixedHeader CSS.
        wp_enqueue_style(
            'datatables-fixedheader',
            'https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css',
            [ 'datatables' ],
            '3.4.0'
        );

        // FixedColumns CSS.
        wp_enqueue_style(
            'datatables-fixedcolumns',
            'https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css',
            [ 'datatables' ],
            '4.3.0'
        );

        // Buttons CSS.
        wp_enqueue_style(
            'datatables-buttons',
            'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css',
            [ 'datatables' ],
            '2.4.2'
        );

        // Plugin frontend CSS.
        wp_enqueue_style(
            'advt-frontend',
            ADVT_PLUGIN_URL . 'public/css/frontend.css',
            [ 'datatables', 'datatables-responsive' ],
            ADVT_VERSION
        );
    }

    /**
     * Enqueue DataTables JS and extension scripts in the footer.
     *
     * Called from `wp_footer` at priority 5 so scripts are ready before
     * WordPress prints the footer scripts at priority 20.
     */
    public function enqueue_footer_assets(): void {
        if ( ! $this->needs_assets ) {
            return;
        }

        // Make sure styles are loaded (fallback for widgets, template tags, etc.).
        $this->enqueue_styles();

        // DataTables core JS.
        wp_enqueue_script(
            'datatables',
            'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
            [ 'jquery' ],
            '1.13.7',
            true
        );

        $js_deps = [ 'datatables' ];

        // Responsive extension JS.
        if ( $this->extensions_needed['responsive'] ) {
            wp_enqueue_script(
                'datatables-responsive',
                'https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js',
                [ 'datatables' ],
                '2.5.0',
                true
            );
            $js_deps[] = 'datatables-responsive';
        }

        // FixedHeader extension JS.
        if ( $this->extensions_needed['fixedheader'] ) {
            wp_enqueue_script(
                'datatables-fixedheader',
                'https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js',
                [ 'datatables' ],
                '3.4.0',
                true
            );
            $js_deps[] = 'datatables-fixedheader';
        }

        // FixedColumns extension JS.
        if ( $this->extensions_needed['fixedcolumns'] ) {
            wp_enqueue_script(
                'datatables-fixedcolumns',
                'https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js',
                [ 'datatables' ],
                '4.3.0',
                true
            );
            $js_deps[] = 'datatables-fixedcolumns';
        }

        // Buttons extension JS (includes HTML5 export + Print + ColVis).
        if ( $this->extensions_needed['buttons'] ) {
            wp_enqueue_script(
                'datatables-buttons',
                'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js',
                [ 'datatables' ],
                '2.4.2',
                true
            );

            // HTML5 export buttons (requires JSZip for Excel).
            wp_enqueue_script(
                'jszip',
                'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
                [],
                '3.10.1',
                true
            );

            wp_enqueue_script(
                'datatables-buttons-html5',
                'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js',
                [ 'datatables-buttons', 'jszip' ],
                '2.4.2',
                true
            );

            wp_enqueue_script(
                'datatables-buttons-print',
                'https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js',
                [ 'datatables-buttons' ],
                '2.4.2',
                true
            );

            wp_enqueue_script(
                'datatables-buttons-colvis',
                'https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js',
                [ 'datatables-buttons' ],
                '2.4.2',
                true
            );

            $js_deps[] = 'datatables-buttons';
            $js_deps[] = 'datatables-buttons-html5';
            $js_deps[] = 'datatables-buttons-print';
            $js_deps[] = 'datatables-buttons-colvis';
        }

        // Plugin frontend JS.
        wp_enqueue_script(
            'advt-frontend',
            ADVT_PLUGIN_URL . 'public/js/frontend.js',
            $js_deps,
            ADVT_VERSION,
            true
        );

        // Pass all collected table configs to JS via wp_localize_script.
        wp_localize_script( 'advt-frontend', 'advtFrontend', [
            'tables' => $this->table_configs,
        ] );
    }
}
