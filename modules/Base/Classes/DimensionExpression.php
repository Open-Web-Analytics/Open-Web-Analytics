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
 * TWO KINDS SO FAR
 * ----------------
 * `sql()` joins several columns into one value; `datePart()` reads a component
 * out of one. They have nothing in common except the thing below, which is why
 * they live together: both produce SQL rather than a column name, and the
 * reporting seam has to be told that once rather than once per kind.
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

    /**
     * The date parts, and how each is read out of a yyyymmdd INT.
     *
     * READ FROM yyyymmdd, NOT FROM ts, and that is a correctness decision
     * rather than a convenience -- see the note on OWA_SQL_DATE_FROM_YYYYMMDD.
     * The short version: `ts` is epoch microseconds and a SQL date function
     * applied to it answers in the DATABASE's timezone, while `yyyymmdd` was
     * written by PHP in the installation's configured one. On this
     * installation those are seven hours apart. yyyymmdd already has the
     * decision baked in, so a part read from it cannot disagree with the
     * `date` dimension or with the partition the row lives in.
     *
     * THE PLAN SAID `ts` FOR ALL OF THESE. It was written before the timezone
     * question was asked, and nothing that follows from `ts` can answer it
     * without either the server's timezone tables or a stored offset.
     *
     * Four of the seven are integer arithmetic on the INT itself, so no date
     * value is built and nothing dialect-specific is used. Only the three that
     * need a calendar -- which day of the week a date fell on, and the two
     * counts within a year -- go through the dialect.
     *
     * `hour` is the exception and is handled separately below: there is no
     * hour in yyyymmdd, so it has to come from `ts` and therefore has to be
     * converted.
     */
    const PARTS = array(
        'year'       => 'FLOOR(%1$s.%2$s / 10000)',
        'month'      => 'FLOOR(MOD(%1$s.%2$s, 10000) / 100)',
        'day'        => 'MOD(%1$s.%2$s, 100)',
        'yearMonth'  => 'FLOOR(%1$s.%2$s / 100)',
        'dayOfWeek'  => null,
        'dayOfYear'  => null,
        'weekOfYear' => null,
    );

    /** Parts that read a timestamp and therefore need the timezone. */
    const CLOCK_PARTS = array( 'hour', 'minute', 'dateHour' );

    /**
     * SQL for one component of a date.
     *
     * @param string $column  the yyyymmdd column
     * @param string $part    a key of PARTS
     * @return string  SQL with %1$s where the table alias belongs
     */
    public static function datePart( $column, $part ) {

        if ( ! preg_match( self::COLUMN, (string) $column ) ) {

            throw new \InvalidArgumentException( sprintf(
                '"%s" is not a column name.', $column ) );
        }

        if ( in_array( (string) $part, self::CLOCK_PARTS, true ) ) {

            return self::clockPart( $column, $part );
        }

        if ( ! array_key_exists( (string) $part, self::PARTS ) ) {

            throw new \InvalidArgumentException( sprintf(
                '"%s" is not a date part. Known: %s.',
                $part, implode( ', ', array_merge(
                    array_keys( self::PARTS ), self::CLOCK_PARTS ) ) ) );
        }

        $template = self::PARTS[ $part ];

        if ( $template !== null ) {

            return sprintf( $template, '%1$s', $column );
        }

        $calendar = array(
            'dayOfWeek'  => OWA_SQL_DAY_OF_WEEK,
            'dayOfYear'  => OWA_SQL_DAY_OF_YEAR,
            'weekOfYear' => OWA_SQL_WEEK_OF_YEAR,
        );

        /*
         * THE EMITTED SQL IS sprintf'd AGAIN, by the seam, to put the alias in.
         * STR_TO_DATE's format is '%Y%m%d', so handing that straight back would
         * have the second pass read %Y as a specifier and raise ValueError --
         * the same trap that makes a percent illegal in a join separator, which
         * is why that one is refused outright rather than escaped.
         *
         * Here the percents are not the caller's to give up, so they are
         * escaped instead: build with a placeholder the format cannot contain,
         * double every literal percent, then put the real placeholder in.
         */
        $marker = "\x00alias\x00";

        $sql = sprintf( $calendar[ $part ],
            sprintf( OWA_SQL_DATE_FROM_YYYYMMDD, $marker . '.' . $column ) );

        return str_replace( $marker, '%1$s', str_replace( '%', '%%', $sql ) );
    }

    /**
     * A part of the CLOCK, which only a timestamp carries.
     *
     * THE ZONE NAME IS PASSED, NOT AN OFFSET, and the difference is not
     * cosmetic. An offset computed in PHP is a single number, correct only for
     * the moment it was computed: measured against six instants spanning the
     * 2026 US transitions, a `-07:00` fixed at one point in the year answered
     * three of them wrong -- including every winter timestamp. The zone name
     * was right on all six, both transitions included, because the SERVER does
     * the lookup per row.
     *
     * THE ZONE IS A SECOND PLACEHOLDER, filled where the alias is, rather than
     * written in at registration. Baking it in made the emitted SQL depend on
     * the machine that generated it -- the catalog recording held
     * 'America/Los_Angeles' and would not have matched on any server configured
     * differently -- and it meant an installation that changed its timezone
     * kept querying with the old one until something re-registered. Both go
     * away when the zone arrives at the same moment the table alias does.
     *
     * KNOWN DEPENDENCY, and it is the reason the other seven parts do not come
     * through here: CONVERT_TZ resolves a zone NAME out of MySQL's timezone
     * tables, which are populated by a separate step at server setup. Where
     * they are missing it returns NULL for every row, which renders as
     * `(not set)` rather than as an error -- indistinguishable, from SQL, from
     * a zone name that does not exist. An installation without those tables
     * loses these dimensions and keeps the seven day-level ones.
     *
     * @param string $column    a timestamp column, in MICROseconds
     * @param string $part
     * @param string $timezone  an IANA zone name
     * @return string  SQL with %1$s where the table alias belongs
     */
    private static function clockPart( $column, $part ) {

        /*
         * Built against markers, not against '%1$s' directly, because the last
         * step doubles every literal percent -- dateHour's DATE_FORMAT carries
         * '%Y%m%d%H' and the seam sprintf's this string again to insert the
         * alias. Doubling the placeholder too would leave it in the SQL.
         */
        $alias = "\x00alias\x00";
        $local = "\x00local\x00";

        $zone = "\x00zone\x00";

        $converted = sprintf( OWA_SQL_LOCAL_DATETIME, $alias . '.' . $column, $zone );

        $readers = array(
            'hour'   => OWA_SQL_HOUR,
            'minute' => OWA_SQL_MINUTE,
            // The day and the hour together, as GA ships it: 2026092518.
            'dateHour' => OWA_SQL_DATE_HOUR,
        );

        $sql = sprintf( $readers[ $part ], $local );

        $sql = str_replace( $local, $converted, $sql );
        $sql = str_replace( '%', '%%', $sql );

        return str_replace( array( $alias, $zone ),
            array( '%1$s', '%2$s' ), $sql );
    }

    /**
     * The timezone the clock parts are read in, validated.
     *
     * PHP's default, which Settings sets from `base.timezone` during boot --
     * so this is the same zone `yyyymmdd` was written in at ingest, and the
     * two bases cannot drift apart.
     *
     * Validated because it is interpolated into SQL as a string literal. It
     * cannot come from a request, but an empty value would quietly become
     * UTC and put every clock reading hours out with nothing to show for it.
     *
     * @return string
     * @throws \RuntimeException
     */
    public static function timezone() {

        $zone = trim( (string) date_default_timezone_get() );

        if ( ! preg_match( '#^[A-Za-z][A-Za-z0-9_+/-]*$#', $zone ) ) {

            throw new \RuntimeException( sprintf(
                '"%s" is not usable as a timezone name.', $zone ) );
        }

        return $zone;
    }

}

?>
