<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A dimension whose value is several columns joined, rendered as SQL.
 *
 * WHY THIS IS AN EXPRESSION AND NOT A COLUMN
 * ------------------------------------------
 * The obvious alternative is a cube pass that writes the joined value into a
 * real column, and it was measured against this one. On 262,144 rows grouping
 * by the expression cost 374ms against 340ms for `GROUP BY source, medium` --
 * about ten per cent -- and NEITHER form can use an index for the grouping,
 * because the query ranges on yyyymmdd before it groups, which rules out a
 * loose index scan either way. A functional index on the CONCAT is accepted by
 * MySQL 8.4 and then ignored by the optimiser.
 *
 * So the column buys no speed, and it costs: six more columns against the
 * 65,535-byte row limit, two of them duplicating a 1024-byte page_path and its
 * query string, plus a rebuild every time one is added. The expression costs a
 * tenth of one group-by and nothing else.
 *
 * WHAT THE ALIAS PLACEHOLDER IS FOR
 * ---------------------------------
 * ResultSetManager::lookupDimension() historically built a dimension's column
 * by writing the table alias in front of whatever the declaration said, which
 * turns `CONCAT_WS(' / ', source, medium)` into `event.CONCAT_WS(...)` -- SQL
 * that MySQL reads as a call to a function named CONCAT_WS in a schema named
 * event. The emitted SQL therefore carries `%1$s` wherever the alias belongs
 * and the seam substitutes rather than prefixes.
 */
class DimensionExpression {

    /** Column names are identifiers; anything else is a mistake, not a value. */
    const COLUMN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * SQL for a dimension declared as `parts` plus a `separator`.
     *
     * NULL IS NOT CONTAGIOUS HERE, which is the whole reason this is not one
     * CONCAT call. Four of the six declared dimensions join a nullable column,
     * and plain CONCAT answers NULL when any argument is NULL -- so a page with
     * no query string would leave `/pricing` and arrive as `(not set)`,
     * silently merging every unparameterised page in the report into one row.
     * Each part after the first contributes its separator AND its value, or
     * neither.
     *
     * CONCAT_WS skips NULLs correctly but has only one separator for the whole
     * call, and fullPageUrl needs two different ones -- nothing between host
     * and path, a '?' before the query.
     *
     * An all-absent row answers NULL rather than '', so it groups with the
     * other absences under `(not set)` instead of forming a second empty row
     * beside them.
     *
     * @param string[]        $parts      column names, at least two
     * @param string|string[] $separator  one for every gap, or one per gap
     * @return string  SQL with %1$s where the table alias belongs
     */
    public static function sql( array $parts, $separator ) {

        $parts = array_values( $parts );

        if ( count( $parts ) < 2 ) {

            throw new \InvalidArgumentException(
                'A concatenated dimension needs at least two parts.' );
        }

        foreach ( $parts as $column ) {

            if ( ! preg_match( self::COLUMN, (string) $column ) ) {

                throw new \InvalidArgumentException( sprintf(
                    '"%s" is not a column name.', $column ) );
            }
        }

        // One separator fills every gap; a list gives each gap its own, and
        // must have exactly one fewer entry than there are parts.
        $gaps = count( $parts ) - 1;

        if ( ! is_array( $separator ) ) {

            $separator = array_fill( 0, $gaps, (string) $separator );
        }

        $separator = array_values( $separator );

        if ( count( $separator ) !== $gaps ) {

            throw new \InvalidArgumentException( sprintf(
                '%d parts need %d separators, not %d.',
                count( $parts ), $gaps, count( $separator ) ) );
        }

        foreach ( $separator as $gap ) {

            /*
             * NO PERCENT SIGN, because the emitted SQL is sprintf'd more than
             * once and only the first substitution is ours. The '=@' operator
             * builds its clause with sprintf( 'LOCATE(%s, %s) > 0', value,
             * operand ) -- by which point the alias is already in and any
             * escaping we did has been collapsed -- so a separator carrying a
             * percent would be read there as a third specifier against two
             * arguments, and PHP 8 raises ArgumentCountError. Escaping it here
             * cannot survive that second pass, and a separator is punctuation
             * between two column values, so refusing costs nothing.
             */
            if ( strpos( (string) $gap, '%' ) !== false ) {

                throw new \InvalidArgumentException(
                    'A separator may not contain a percent sign.' );
            }
        }

        $terms = array( sprintf( "COALESCE(%%1\$s.%s,'')", $parts[0] ) );

        for ( $i = 1; $i < count( $parts ); $i++ ) {

            $terms[] = sprintf(
                "IF(%%1\$s.%1\$s IS NULL OR %%1\$s.%1\$s = '', '', CONCAT(%2\$s, %%1\$s.%1\$s))",
                $parts[ $i ], self::literal( $separator[ $i - 1 ] ) );
        }

        return sprintf( "NULLIF(CONCAT(%s), '')", implode( ', ', $terms ) );
    }

    /**
     * A separator as a SQL string literal.
     *
     * Separators come from a config file rather than from a request, so this is
     * not the injection boundary -- it is here so that a declaration cannot
     * produce SQL that fails to parse. Percent signs are refused in sql()
     * rather than escaped here, for the reason given there.
     *
     * @param string $value
     * @return string
     */
    private static function literal( $value ) {

        return "'" . str_replace(
            array( '\\', "'" ), array( '\\\\', "''" ), (string) $value ) . "'";
    }

}

?>
