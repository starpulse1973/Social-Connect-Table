<?php
/**
 * Formula Parser — evaluates Excel-like formulas in table cell data.
 *
 * Supports:
 *  - Cell references:  =A1, =B2, =Z99
 *  - Ranges:           =SUM(A1:A5), =AVERAGE(B2:B10)
 *  - Arithmetic:       =A1+B2, =A1*2+B3/4
 *  - Parentheses:      =(A1+A2)*A3
 *  - Functions:        SUM, AVERAGE, AVG, MIN, MAX, COUNT, ROUND, ABS, IF,
 *                      CONCAT, UPPER, LOWER, LEN, COUNTA, PRODUCT, MEDIAN,
 *                      INT, MOD, POWER, SQRT
 *  - Nested formulas:  =SUM(A1:A3)+MAX(B1:B3)
 *  - Comparison ops:   =IF(A1>B1,"Yes","No")
 *  - String literals:  ="Hello " & A1  (concatenation with &)
 *
 * Formulas are evaluated at render time (shortcode output). The raw formula
 * strings (e.g. "=SUM(A1:A5)") are stored as-is in the database; only the
 * computed results appear in the HTML sent to the browser.
 *
 * Circular-reference protection: a cell that is currently being resolved
 * is tracked; if a cycle is detected the cell returns "#REF!".
 *
 * @package AdvancedWPTables
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Advt_Formula_Parser {

    /**
     * The full 2-D data grid (0-indexed rows & columns).
     *
     * @var array<int, array<int, string>>
     */
    private array $grid = [];

    /**
     * Cache of resolved cell values to avoid re-computation.
     *
     * @var array<string, string|float|int>
     */
    private array $cache = [];

    /**
     * Set of cell keys currently being resolved (cycle detection).
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * Error string returned when a formula cannot be evaluated.
     */
    private const ERROR   = '#ERROR!';
    private const REF_ERR = '#REF!';
    private const DIV_ERR = '#DIV/0!';
    private const VAL_ERR = '#VALUE!';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Process an entire 2-D data grid, evaluating every cell that starts
     * with "=".
     *
     * @param array<int, array<int, string>> $data The raw table data.
     * @return array<int, array<int, string>> Data with formulas replaced by computed values.
     */
    public function process( array $data ): array {
        $this->grid      = $data;
        $this->cache     = [];
        $this->resolving = [];

        $result = [];

        foreach ( $data as $row_idx => $row ) {
            $result_row = [];
            foreach ( $row as $col_idx => $cell ) {
                $result_row[] = $this->resolve_cell( $row_idx, $col_idx );
            }
            $result[] = $result_row;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Cell Resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve a single cell value. If the cell contains a formula (starts
     * with "="), evaluate it; otherwise return the raw value.
     */
    private function resolve_cell( int $row, int $col ): string {
        $key = $row . ':' . $col;

        // Return cached result.
        if ( array_key_exists( $key, $this->cache ) ) {
            return (string) $this->cache[ $key ];
        }

        // Circular reference guard.
        if ( isset( $this->resolving[ $key ] ) ) {
            return self::REF_ERR;
        }

        $raw = trim( (string) ( $this->grid[ $row ][ $col ] ?? '' ) );

        // Not a formula — return as-is.
        if ( $raw === '' || $raw[0] !== '=' ) {
            $this->cache[ $key ] = $raw;
            return $raw;
        }

        // Mark as resolving.
        $this->resolving[ $key ] = true;

        $expression = substr( $raw, 1 ); // Strip leading "=".

        try {
            $value = $this->evaluate( $expression );
        } catch ( \Throwable $e ) {
            $value = self::ERROR;
        }

        unset( $this->resolving[ $key ] );

        // Format: if numeric float with no meaningful decimals, show as int.
        if ( is_float( $value ) && $value == (int) $value && abs( $value ) < 1e15 ) {
            $value = (string) (int) $value;
        } elseif ( is_float( $value ) ) {
            $value = rtrim( rtrim( number_format( $value, 10, '.', '' ), '0' ), '.' );
        } elseif ( is_int( $value ) ) {
            $value = (string) $value;
        } else {
            $value = (string) $value;
        }

        $this->cache[ $key ] = $value;
        return $value;
    }

    // -------------------------------------------------------------------------
    // Tokenizer
    // -------------------------------------------------------------------------

    /**
     * Tokenize a formula expression into an array of tokens.
     *
     * Token types: NUMBER, STRING, CELL_REF, RANGE, FUNC, OP, LPAREN, RPAREN,
     *              COMMA, COMPARE, AMPERSAND.
     *
     * @return array<int, array{type: string, value: string|float}>
     */
    private function tokenize( string $expr ): array {
        $tokens = [];
        $len    = strlen( $expr );
        $i      = 0;

        while ( $i < $len ) {
            $ch = $expr[ $i ];

            // Whitespace — skip.
            if ( $ch === ' ' || $ch === "\t" ) {
                $i++;
                continue;
            }

            // String literal: "…"
            if ( $ch === '"' ) {
                $i++;
                $str = '';
                while ( $i < $len && $expr[ $i ] !== '"' ) {
                    if ( $expr[ $i ] === '\\' && $i + 1 < $len ) {
                        $str .= $expr[ ++$i ];
                    } else {
                        $str .= $expr[ $i ];
                    }
                    $i++;
                }
                $i++; // Skip closing quote.
                $tokens[] = [ 'type' => 'STRING', 'value' => $str ];
                continue;
            }

            // Comparison operators: >=, <=, <>, !=, =, >, <
            if ( $ch === '>' || $ch === '<' || $ch === '!' ) {
                $next = ( $i + 1 < $len ) ? $expr[ $i + 1 ] : '';
                if ( $ch === '>' && $next === '=' ) {
                    $tokens[] = [ 'type' => 'COMPARE', 'value' => '>=' ];
                    $i += 2;
                } elseif ( $ch === '<' && $next === '=' ) {
                    $tokens[] = [ 'type' => 'COMPARE', 'value' => '<=' ];
                    $i += 2;
                } elseif ( $ch === '<' && $next === '>' ) {
                    $tokens[] = [ 'type' => 'COMPARE', 'value' => '<>' ];
                    $i += 2;
                } elseif ( $ch === '!' && $next === '=' ) {
                    $tokens[] = [ 'type' => 'COMPARE', 'value' => '!=' ];
                    $i += 2;
                } elseif ( $ch === '>' ) {
                    $tokens[] = [ 'type' => 'COMPARE', 'value' => '>' ];
                    $i++;
                } elseif ( $ch === '<' ) {
                    $tokens[] = [ 'type' => 'COMPARE', 'value' => '<' ];
                    $i++;
                } else {
                    $i++;
                }
                continue;
            }

            // "=" as comparison (only when not at start — the leading = is already stripped).
            if ( $ch === '=' ) {
                $tokens[] = [ 'type' => 'COMPARE', 'value' => '=' ];
                $i++;
                continue;
            }

            // Ampersand — string concatenation.
            if ( $ch === '&' ) {
                $tokens[] = [ 'type' => 'AMPERSAND', 'value' => '&' ];
                $i++;
                continue;
            }

            // Arithmetic operators.
            if ( in_array( $ch, [ '+', '-', '*', '/', '%', '^' ], true ) ) {
                $tokens[] = [ 'type' => 'OP', 'value' => $ch ];
                $i++;
                continue;
            }

            // Parentheses.
            if ( $ch === '(' ) {
                $tokens[] = [ 'type' => 'LPAREN', 'value' => '(' ];
                $i++;
                continue;
            }
            if ( $ch === ')' ) {
                $tokens[] = [ 'type' => 'RPAREN', 'value' => ')' ];
                $i++;
                continue;
            }

            // Comma.
            if ( $ch === ',' || $ch === ';' ) {
                $tokens[] = [ 'type' => 'COMMA', 'value' => ',' ];
                $i++;
                continue;
            }

            // Number (including decimals and negative sign handled during parsing).
            if ( ctype_digit( $ch ) || ( $ch === '.' && $i + 1 < $len && ctype_digit( $expr[ $i + 1 ] ) ) ) {
                $num = '';
                while ( $i < $len && ( ctype_digit( $expr[ $i ] ) || $expr[ $i ] === '.' ) ) {
                    $num .= $expr[ $i ];
                    $i++;
                }
                $tokens[] = [ 'type' => 'NUMBER', 'value' => (float) $num ];
                continue;
            }

            // Identifier: cell reference (e.g. A1, AB23), range (A1:B5), or function name.
            if ( ctype_alpha( $ch ) || $ch === '_' || $ch === '$' ) {
                $ident = '';
                while ( $i < $len && ( ctype_alnum( $expr[ $i ] ) || $expr[ $i ] === '_' || $expr[ $i ] === '$' ) ) {
                    $ident .= $expr[ $i ];
                    $i++;
                }

                // Check for range: IDENT:IDENT (e.g. A1:A5).
                if ( $i < $len && $expr[ $i ] === ':' && $this->is_cell_ref( $ident ) ) {
                    $i++; // Skip colon.
                    $ident2 = '';
                    while ( $i < $len && ( ctype_alnum( $expr[ $i ] ) || $expr[ $i ] === '$' ) ) {
                        $ident2 .= $expr[ $i ];
                        $i++;
                    }
                    $tokens[] = [ 'type' => 'RANGE', 'value' => strtoupper( $ident ) . ':' . strtoupper( $ident2 ) ];
                    continue;
                }

                // Check for function: IDENT(
                if ( $i < $len && $expr[ $i ] === '(' && ! $this->is_cell_ref( $ident ) ) {
                    $tokens[] = [ 'type' => 'FUNC', 'value' => strtoupper( $ident ) ];
                    // Don't consume the '(' — it will be picked up as LPAREN.
                    continue;
                }

                // Cell reference.
                if ( $this->is_cell_ref( $ident ) ) {
                    $tokens[] = [ 'type' => 'CELL_REF', 'value' => strtoupper( $ident ) ];
                    continue;
                }

                // Boolean TRUE/FALSE.
                $upper = strtoupper( $ident );
                if ( $upper === 'TRUE' ) {
                    $tokens[] = [ 'type' => 'NUMBER', 'value' => 1.0 ];
                    continue;
                }
                if ( $upper === 'FALSE' ) {
                    $tokens[] = [ 'type' => 'NUMBER', 'value' => 0.0 ];
                    continue;
                }

                // Unknown identifier — treat as string.
                $tokens[] = [ 'type' => 'STRING', 'value' => $ident ];
                continue;
            }

            // Skip unknown characters.
            $i++;
        }

        return $tokens;
    }

    // -------------------------------------------------------------------------
    // Recursive-Descent Parser / Evaluator
    // -------------------------------------------------------------------------

    /** @var array Token stream for the parser. */
    private array $tokens = [];

    /** @var int Current position in the token stream. */
    private int $pos = 0;

    /**
     * Evaluate a formula expression string and return the computed value.
     *
     * @return float|int|string
     */
    private function evaluate( string $expression ): float|int|string {
        $this->tokens = $this->tokenize( $expression );
        $this->pos    = 0;

        if ( empty( $this->tokens ) ) {
            return '';
        }

        $result = $this->parse_concat();

        return $result;
    }

    /**
     * String concatenation (&) — lowest precedence.
     *
     * @return float|int|string
     */
    private function parse_concat(): float|int|string {
        $left = $this->parse_comparison();

        while ( $this->current_type() === 'AMPERSAND' ) {
            $this->advance();
            $right = $this->parse_comparison();
            $left  = $this->to_string( $left ) . $this->to_string( $right );
        }

        return $left;
    }

    /**
     * Comparison operators: =, <>, !=, <, >, <=, >=
     *
     * @return float|int|string
     */
    private function parse_comparison(): float|int|string {
        $left = $this->parse_addition();

        while ( $this->current_type() === 'COMPARE' ) {
            $op = $this->current_value();
            $this->advance();
            $right = $this->parse_addition();

            $result = match ( $op ) {
                '=', '=='  => $this->compare_values( $left, $right ) === 0,
                '<>', '!=' => $this->compare_values( $left, $right ) !== 0,
                '<'        => $this->compare_values( $left, $right ) < 0,
                '>'        => $this->compare_values( $left, $right ) > 0,
                '<='       => $this->compare_values( $left, $right ) <= 0,
                '>='       => $this->compare_values( $left, $right ) >= 0,
                default    => false,
            };

            $left = $result ? 1.0 : 0.0;
        }

        return $left;
    }

    /**
     * Addition / subtraction.
     *
     * @return float|int|string
     */
    private function parse_addition(): float|int|string {
        $left = $this->parse_multiplication();

        while ( $this->current_type() === 'OP' && in_array( $this->current_value(), [ '+', '-' ], true ) ) {
            $op = $this->current_value();
            $this->advance();
            $right = $this->parse_multiplication();

            $l = $this->to_number( $left );
            $r = $this->to_number( $right );

            $left = match ( $op ) {
                '+' => $l + $r,
                '-' => $l - $r,
            };
        }

        return $left;
    }

    /**
     * Multiplication / division / modulo.
     *
     * @return float|int|string
     */
    private function parse_multiplication(): float|int|string {
        $left = $this->parse_exponent();

        while ( $this->current_type() === 'OP' && in_array( $this->current_value(), [ '*', '/', '%' ], true ) ) {
            $op = $this->current_value();
            $this->advance();
            $right = $this->parse_exponent();

            $l = $this->to_number( $left );
            $r = $this->to_number( $right );

            $left = match ( $op ) {
                '*' => $l * $r,
                '/' => ( $r != 0 ) ? $l / $r : self::DIV_ERR,
                '%' => ( $r != 0 ) ? fmod( $l, $r ) : self::DIV_ERR,
            };
        }

        return $left;
    }

    /**
     * Exponentiation (^).
     *
     * @return float|int|string
     */
    private function parse_exponent(): float|int|string {
        $base = $this->parse_unary();

        while ( $this->current_type() === 'OP' && $this->current_value() === '^' ) {
            $this->advance();
            $exp  = $this->parse_unary(); // Right-associative via recursion.
            $base = pow( $this->to_number( $base ), $this->to_number( $exp ) );
        }

        return $base;
    }

    /**
     * Unary plus / minus.
     *
     * @return float|int|string
     */
    private function parse_unary(): float|int|string {
        if ( $this->current_type() === 'OP' && $this->current_value() === '-' ) {
            $this->advance();
            $val = $this->parse_primary();
            return -$this->to_number( $val );
        }

        if ( $this->current_type() === 'OP' && $this->current_value() === '+' ) {
            $this->advance();
            return $this->parse_primary();
        }

        return $this->parse_primary();
    }

    /**
     * Primary: numbers, strings, cell refs, function calls, parenthesized expressions.
     *
     * @return float|int|string
     */
    private function parse_primary(): float|int|string {
        $token = $this->current();

        if ( ! $token ) {
            return 0;
        }

        // Number literal.
        if ( $token['type'] === 'NUMBER' ) {
            $this->advance();
            return $token['value'];
        }

        // String literal.
        if ( $token['type'] === 'STRING' ) {
            $this->advance();
            return (string) $token['value'];
        }

        // Cell reference (e.g. A1, $B$2).
        if ( $token['type'] === 'CELL_REF' ) {
            $this->advance();
            return $this->resolve_ref( (string) $token['value'] );
        }

        // Range (e.g. A1:A5) — only valid inside function args; standalone returns first cell.
        if ( $token['type'] === 'RANGE' ) {
            $this->advance();
            $values = $this->expand_range( (string) $token['value'] );
            return ! empty( $values ) ? $values[0] : 0;
        }

        // Function call: FUNC_NAME(args...)
        if ( $token['type'] === 'FUNC' ) {
            $func_name = (string) $token['value'];
            $this->advance(); // Consume FUNC token.

            // Expect LPAREN.
            if ( $this->current_type() === 'LPAREN' ) {
                $this->advance();
            }

            $args = $this->parse_func_args();

            // Expect RPAREN.
            if ( $this->current_type() === 'RPAREN' ) {
                $this->advance();
            }

            return $this->call_function( $func_name, $args );
        }

        // Parenthesized expression.
        if ( $token['type'] === 'LPAREN' ) {
            $this->advance();
            $val = $this->parse_concat();
            if ( $this->current_type() === 'RPAREN' ) {
                $this->advance();
            }
            return $val;
        }

        // Fallback — advance to avoid infinite loop.
        $this->advance();
        return 0;
    }

    /**
     * Parse function arguments (comma-separated). Ranges are expanded into
     * flat numeric arrays; scalar values are wrapped in single-element arrays.
     *
     * @return array<int, array<int, float|int|string>> Each argument is an array of values.
     */
    private function parse_func_args(): array {
        $args = [];

        if ( $this->current_type() === 'RPAREN' || ! $this->current() ) {
            return $args;
        }

        $args[] = $this->parse_single_arg();

        while ( $this->current_type() === 'COMMA' ) {
            $this->advance();
            $args[] = $this->parse_single_arg();
        }

        return $args;
    }

    /**
     * Parse a single function argument. If the token is a RANGE, expand it;
     * otherwise evaluate the expression and wrap in an array.
     *
     * @return array<int, float|int|string>
     */
    private function parse_single_arg(): array {
        // If it's a range like A1:B5, expand directly.
        if ( $this->current_type() === 'RANGE' ) {
            $range = (string) $this->current_value();
            $this->advance();
            return $this->expand_range( $range );
        }

        // Otherwise, evaluate expression and wrap.
        $val = $this->parse_concat();
        return [ $val ];
    }

    // -------------------------------------------------------------------------
    // Token Helpers
    // -------------------------------------------------------------------------

    private function current(): ?array {
        return $this->tokens[ $this->pos ] ?? null;
    }

    private function current_type(): ?string {
        return $this->tokens[ $this->pos ]['type'] ?? null;
    }

    /**
     * @return string|float|null
     */
    private function current_value(): string|float|null {
        return $this->tokens[ $this->pos ]['value'] ?? null;
    }

    private function advance(): void {
        $this->pos++;
    }

    // -------------------------------------------------------------------------
    // Cell Reference Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if a string looks like an Excel-style cell reference (A1, AB99, $A$1).
     */
    private function is_cell_ref( string $ident ): bool {
        // Strip $ signs used for absolute refs.
        $clean = str_replace( '$', '', $ident );
        return (bool) preg_match( '/^[A-Za-z]{1,3}[0-9]{1,5}$/', $clean );
    }

    /**
     * Convert an Excel-style column label to a 0-based column index.
     * A=0, B=1, … Z=25, AA=26, AB=27, …
     */
    private function col_label_to_index( string $label ): int {
        $label = strtoupper( $label );
        $index = 0;
        $len   = strlen( $label );

        for ( $i = 0; $i < $len; $i++ ) {
            $index = $index * 26 + ( ord( $label[ $i ] ) - ord( 'A' ) + 1 );
        }

        return $index - 1; // 0-based.
    }

    /**
     * Parse an Excel-style cell reference into [row, col] (0-based).
     *
     * @return array{0: int, 1: int}
     */
    private function parse_cell_ref( string $ref ): array {
        $ref = strtoupper( str_replace( '$', '', $ref ) );

        if ( ! preg_match( '/^([A-Z]+)(\d+)$/', $ref, $m ) ) {
            // Invalid cell reference — return out-of-bounds coords that will
            // safely resolve to 0 via the bounds check in resolve_ref().
            return [ -1, -1 ];
        }

        $col = $this->col_label_to_index( $m[1] );
        $row = (int) $m[2] - 1; // 1-based to 0-based.

        return [ $row, $col ];
    }

    /**
     * Resolve a cell reference to its computed value.
     *
     * @return float|int|string
     */
    private function resolve_ref( string $ref ): float|int|string {
        [ $row, $col ] = $this->parse_cell_ref( $ref );

        // Out-of-bounds check.
        if ( ! isset( $this->grid[ $row ][ $col ] ) ) {
            return 0;
        }

        $value = $this->resolve_cell( $row, $col );

        // If the resolved value is numeric, return as float for arithmetic.
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * Expand a range like "A1:C3" into a flat array of resolved cell values.
     *
     * @return array<int, float|int|string>
     */
    private function expand_range( string $range ): array {
        $parts = explode( ':', $range, 2 );
        if ( count( $parts ) !== 2 ) {
            return [];
        }

        [ $row1, $col1 ] = $this->parse_cell_ref( $parts[0] );
        [ $row2, $col2 ] = $this->parse_cell_ref( $parts[1] );

        // Normalize so start <= end.
        $r_start = min( $row1, $row2 );
        $r_end   = max( $row1, $row2 );
        $c_start = min( $col1, $col2 );
        $c_end   = max( $col1, $col2 );

        $values = [];

        for ( $r = $r_start; $r <= $r_end; $r++ ) {
            for ( $c = $c_start; $c <= $c_end; $c++ ) {
                if ( isset( $this->grid[ $r ][ $c ] ) ) {
                    $val = $this->resolve_cell( $r, $c );
                    $values[] = is_numeric( $val ) ? (float) $val : $val;
                }
            }
        }

        return $values;
    }

    // -------------------------------------------------------------------------
    // Built-in Functions
    // -------------------------------------------------------------------------

    /**
     * Dispatch a function call.
     *
     * @param string $name Function name (uppercased).
     * @param array  $args Array of argument arrays (each arg is an array of values).
     * @return float|int|string
     */
    private function call_function( string $name, array $args ): float|int|string {
        // Flatten all arguments into a single list for aggregate functions.
        $flat = [];
        foreach ( $args as $arg ) {
            foreach ( $arg as $v ) {
                $flat[] = $v;
            }
        }

        // Extract only numeric values from the flat list.
        $nums = array_filter( $flat, 'is_numeric' );
        $nums = array_map( 'floatval', $nums );
        $nums = array_values( $nums );

        return match ( $name ) {
            'SUM'     => array_sum( $nums ),
            'AVERAGE', 'AVG' => count( $nums ) > 0 ? array_sum( $nums ) / count( $nums ) : 0,
            'MIN'     => count( $nums ) > 0 ? min( $nums ) : 0,
            'MAX'     => count( $nums ) > 0 ? max( $nums ) : 0,
            'COUNT'   => (float) count( $nums ),
            'COUNTA'  => (float) count( array_filter( $flat, fn( $v ) => $v !== '' && $v !== null ) ),
            'PRODUCT' => count( $nums ) > 0 ? (float) array_product( $nums ) : 0,
            'MEDIAN'  => $this->fn_median( $nums ),

            'ROUND'   => $this->fn_round( $args ),
            'ABS'     => count( $nums ) > 0 ? abs( $nums[0] ) : 0,
            'INT'     => count( $nums ) > 0 ? (float) (int) $nums[0] : 0,
            'MOD'     => $this->fn_mod( $args ),
            'POWER'   => $this->fn_power( $args ),
            'SQRT'    => count( $nums ) > 0 ? sqrt( max( 0.0, $nums[0] ) ) : 0,

            'IF'      => $this->fn_if( $args ),
            'CONCAT', 'CONCATENATE' => $this->fn_concat( $flat ),
            'UPPER'   => strtoupper( $this->to_string( $flat[0] ?? '' ) ),
            'LOWER'   => strtolower( $this->to_string( $flat[0] ?? '' ) ),
            'LEN'     => (float) mb_strlen( $this->to_string( $flat[0] ?? '' ) ),
            'TRIM'    => trim( $this->to_string( $flat[0] ?? '' ) ),
            'LEFT'    => $this->fn_left( $args ),
            'RIGHT'   => $this->fn_right( $args ),
            'MID'     => $this->fn_mid( $args ),

            default   => self::ERROR,
        };
    }

    // ---- Individual function implementations --------------------------------

    private function fn_round( array $args ): float {
        $value  = $this->first_number( $args[0] ?? [] );
        $digits = (int) $this->first_number( $args[1] ?? [ 0 ] );
        return round( $value, $digits );
    }

    private function fn_mod( array $args ): float|string {
        $num     = $this->first_number( $args[0] ?? [] );
        $divisor = $this->first_number( $args[1] ?? [ 1 ] );
        return $divisor != 0 ? fmod( $num, $divisor ) : self::DIV_ERR;
    }

    private function fn_power( array $args ): float {
        $base = $this->first_number( $args[0] ?? [] );
        $exp  = $this->first_number( $args[1] ?? [ 1 ] );
        return pow( $base, $exp );
    }

    /**
     * IF(condition, value_if_true, value_if_false)
     *
     * @return float|int|string
     */
    private function fn_if( array $args ): float|int|string {
        $condition  = $args[0][0] ?? 0;
        $val_true   = $args[1][0] ?? '';
        $val_false  = $args[2][0] ?? '';

        $test = is_numeric( $condition ) ? (float) $condition : ( $condition ? 1 : 0 );

        return $test != 0 ? $val_true : $val_false;
    }

    /**
     * CONCAT / CONCATENATE — join all arguments as strings.
     */
    private function fn_concat( array $values ): string {
        $parts = array_map( [ $this, 'to_string' ], $values );
        return implode( '', $parts );
    }

    /**
     * LEFT(text, num_chars)
     */
    private function fn_left( array $args ): string {
        $text = $this->to_string( $args[0][0] ?? '' );
        $n    = (int) $this->first_number( $args[1] ?? [ 1 ] );
        return mb_substr( $text, 0, max( 0, $n ) );
    }

    /**
     * RIGHT(text, num_chars)
     */
    private function fn_right( array $args ): string {
        $text = $this->to_string( $args[0][0] ?? '' );
        $n    = (int) $this->first_number( $args[1] ?? [ 1 ] );
        return mb_substr( $text, -max( 1, $n ) );
    }

    /**
     * MID(text, start, num_chars)
     */
    private function fn_mid( array $args ): string {
        $text  = $this->to_string( $args[0][0] ?? '' );
        $start = (int) $this->first_number( $args[1] ?? [ 1 ] );
        $n     = (int) $this->first_number( $args[2] ?? [ 1 ] );
        return mb_substr( $text, max( 0, $start - 1 ), max( 0, $n ) );
    }

    /**
     * MEDIAN — return the median of a numeric array.
     */
    private function fn_median( array $nums ): float {
        if ( empty( $nums ) ) {
            return 0.0;
        }

        sort( $nums );
        $count = count( $nums );
        $mid   = (int) floor( $count / 2 );

        if ( $count % 2 === 0 ) {
            return ( $nums[ $mid - 1 ] + $nums[ $mid ] ) / 2;
        }

        return $nums[ $mid ];
    }

    // -------------------------------------------------------------------------
    // Type Conversion Helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a value to float for arithmetic.
     */
    private function to_number( mixed $val ): float {
        if ( is_numeric( $val ) ) {
            return (float) $val;
        }
        return 0.0;
    }

    /**
     * Convert a value to string.
     */
    private function to_string( mixed $val ): string {
        if ( is_float( $val ) && $val == (int) $val && abs( $val ) < 1e15 ) {
            return (string) (int) $val;
        }
        return (string) $val;
    }

    /**
     * Get the first numeric value from an argument array, defaulting to 0.
     */
    private function first_number( array $arg ): float {
        foreach ( $arg as $v ) {
            if ( is_numeric( $v ) ) {
                return (float) $v;
            }
        }
        return 0.0;
    }

    /**
     * Compare two values: numeric comparison if both are numeric, otherwise string comparison.
     */
    private function compare_values( mixed $a, mixed $b ): int {
        if ( is_numeric( $a ) && is_numeric( $b ) ) {
            return (float) $a <=> (float) $b;
        }
        return strcasecmp( (string) $a, (string) $b );
    }
}
