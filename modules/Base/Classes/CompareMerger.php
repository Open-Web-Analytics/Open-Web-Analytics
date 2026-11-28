<?php
namespace OWA\Module\Base\Classes;

/**
 * Puts a comparison period's numbers beside a result set's.
 *
 * Every metric cell, in the aggregates and in each row, gains:
 *
 * - compare_value / compare_formatted_value: the comparison period's number,
 *   or null when the comparison has no row to match;
 * - change: (value - compare_value) / compare_value, or null when either is
 *   missing or the comparison is zero.
 *
 * Rows are matched on their dimension values. A time-axis dimension (date,
 * dateHour, yearMonth, year) is matched by its place in the period instead of
 * its value: the 3rd day of the period against the 3rd day of the
 * comparison. Every other dimension, the time parts like dayOfWeek included,
 * is matched by value.
 *
 * The result set also carries `compare`: which comparison, its dates and its
 * aggregates as the comparison's own result set had them.
 *
 * A row is name => cell, a cell an array (value, formatted_value,
 * result_type, data_type and more); a value that is not an array is left as
 * it is.
 *
 * @phpstan-type ResultRow array<string, mixed>
 * @phpstan-type Period array{0: string, 1: string}
 */
class CompareMerger {

    /** The dimensions whose values are a position on the time axis, and their unit. */
    const TIME_AXIS = [
        'date'      => 'day',
        'dateHour'  => 'hour',
        'yearMonth' => 'month',
        'year'      => 'year',
    ];

    /**
     * The dimensions a comparison query can restrict to the rows on the page.
     *
     * @param  string[] $dimensions
     * @return list<string>
     */
    public static function restrictable( array $dimensions ) {

        return array_values( array_filter( $dimensions, function ( $d ) {
            return ! isset( self::TIME_AXIS[ $d ] );
        } ) );
    }

    /**
     * @param object $rs      the period's result set (paginatedResultSet), changed in place
     * @param object $crs     the comparison period's result set
     * @param string[] $dims  the dimensions both were grouped by
     * @param Period $main    [ start, end ] yyyymmdd of the period
     * @param Period $cmp     [ start, end ] yyyymmdd of the comparison
     * @param string $compare the `compare` key it was asked as
     * @return object $rs
     */
    public static function merge( $rs, $crs, array $dims, array $main, array $cmp, $compare ) {

        $rs->compare = [
            'compare'    => (string) $compare,
            'startDate'  => (string) $cmp[0],
            'endDate'    => (string) $cmp[1],
            'aggregates' => (array) ( $crs->aggregates ?? [] ),
        ];

        $rs->aggregates = self::mergeCells( (array) $rs->aggregates, (array) ( $crs->aggregates ?? [] ) );

        /*
         * A TREND -- grouped by a time axis, alone or broken out by another
         * dimension -- also gets the comparison's own rows, its dates moved
         * onto the period's. A chart draws the comparison as a line of its own,
         * and a day the period has no row for (nothing happened) still has a
         * comparison value; matched onto the period's rows alone, that day's
         * would be lost.
         */
        if ( array_intersect( $dims, array_keys( self::TIME_AXIS ) ) ) {

            $rs->compare['rows'] = [];

            foreach ( (array) ( $crs->resultsRows ?? [] ) as $row ) {

                foreach ( $dims as $d ) {

                    if ( isset( self::TIME_AXIS[ $d ], $row[ $d ] ) && is_array( $row[ $d ] ) && $row[ $d ]['value'] !== null && $row[ $d ]['value'] !== '' ) {

                        $row[ $d ]['compare_period_value'] = $row[ $d ]['value'];
                        $row[ $d ]['value'] = self::shift( self::TIME_AXIS[ $d ], (string) $row[ $d ]['value'], $cmp[0], $main[0] );
                    }
                }

                $rs->compare['rows'][] = $row;
            }
        }

        if ( empty( $rs->resultsRows ) ) {

            return $rs;
        }

        $index = [];

        foreach ( (array) ( $crs->resultsRows ?? [] ) as $row ) {

            $index[ self::key( $row, $dims, $cmp, $main ) ] = $row;
        }

        foreach ( $rs->resultsRows as $i => $row ) {

            $match = $index[ self::key( $row, $dims, $main, $main ) ] ?? [];

            $rs->resultsRows[ $i ] = self::mergeCells( $row, $match );
        }

        return $rs;
    }

    /**
     * A row the comparison period has and the period does not, as a row of
     * the period: its dimension cells as they are, and every metric 0, a
     * ratio too, as in a trend's empty interval. Each has the comparison's
     * number as its compare_value and the change from it.
     *
     * @param ResultRow $row   a row of the comparison's result set
     * @param callable(mixed, int): mixed $format ( data_type, value ) => formatted value
     * @return ResultRow
     */
    public static function compareOnlyRow( array $row, callable $format ) {

        foreach ( $row as $name => $cell ) {

            if ( ! is_array( $cell ) || ( $cell['result_type'] ?? '' ) !== 'metric' ) {

                continue;
            }

            $value = 0;

            $row[ $name ]['value']                   = $value;
            $row[ $name ]['formatted_value']         = $format( $cell['data_type'] ?? null, $value );
            $row[ $name ]['compare_value']           = $cell['value'] ?? null;
            $row[ $name ]['compare_formatted_value'] = $cell['formatted_value'] ?? ( $cell['value'] ?? null );
            $row[ $name ]['change']                  = self::change( $value, $cell['value'] ?? null );
        }

        return $row;
    }

    /**
     * Each metric cell of $cells, given the matching cell of $compare.
     *
     * @param  ResultRow $cells
     * @param  ResultRow $compare
     * @return ResultRow
     */
    private static function mergeCells( array $cells, array $compare ) {

        foreach ( $cells as $name => $cell ) {

            if ( ! is_array( $cell ) || ( $cell['result_type'] ?? '' ) !== 'metric' ) {

                continue;
            }

            $c = isset( $compare[ $name ] ) && is_array( $compare[ $name ] ) ? $compare[ $name ] : null;

            $cells[ $name ]['compare_value']           = $c ? $c['value'] : null;
            $cells[ $name ]['compare_formatted_value'] = $c ? ( $c['formatted_value'] ?? $c['value'] ) : null;
            $cells[ $name ]['change']                  = self::change( $cell['value'] ?? null, $c ? $c['value'] : null );
        }

        return $cells;
    }

    /**
     * (value - compare) / |compare|, or null when there is nothing to divide by.
     *
     * Divided by the earlier value's magnitude, so a rise is positive whatever
     * the sign of the earlier value: -100 to -50 is +50%.
     *
     * @param  mixed $value   a cell's value, numeric or not
     * @param  mixed $compare a cell's value, numeric or not
     * @return float|null
     */
    public static function change( $value, $compare ) {

        if ( ! is_numeric( $value ) || ! is_numeric( $compare ) || (float) $compare == 0.0 ) {

            return null;
        }

        return ( (float) $value - (float) $compare ) / abs( (float) $compare );
    }

    /**
     * A row's identity: its dimension values, with each time-axis value
     * moved from its own period ($from) to the same place in $to. NULL and ''
     * are different values, as they are different groups in SQL.
     *
     * @param  ResultRow $row
     * @param  string[]  $dims
     * @param  Period    $from
     * @param  Period    $to
     * @return string
     */
    private static function key( array $row, array $dims, array $from, array $to ) {

        $parts = [];

        foreach ( $dims as $d ) {

            $v = isset( $row[ $d ] ) && is_array( $row[ $d ] ) ? $row[ $d ]['value'] : null;

            if ( isset( self::TIME_AXIS[ $d ] ) && $v !== null && $v !== '' ) {

                $v = self::shift( self::TIME_AXIS[ $d ], (string) $v, $from[0], $to[0] );
            }

            $parts[] = $v === null ? 'n' : 'v' . (string) $v;
        }

        return implode( "\x1f", $parts );
    }

    /**
     * A time-axis value moved by the distance from $fromStart to $toStart.
     *
     * @param string $unit      day, hour, month or year
     * @param string $value     yyyymmdd, yyyymmddhh, yyyymm or yyyy
     * @param string $fromStart yyyymmdd
     * @param string $toStart   yyyymmdd
     * @return string
     */
    public static function shift( $unit, $value, $fromStart, $toStart ) {

        $utc  = new \DateTimeZone( 'UTC' );
        $from = \DateTimeImmutable::createFromFormat( '!Ymd', (string) $fromStart, $utc );
        $to   = \DateTimeImmutable::createFromFormat( '!Ymd', (string) $toStart, $utc );

        if ( ! $from || ! $to ) {

            return $value;
        }

        switch ( $unit ) {

            case 'day':
            case 'hour':
                $d = \DateTimeImmutable::createFromFormat( '!Ymd', substr( $value, 0, 8 ), $utc );

                if ( ! $d ) {

                    return $value;
                }

                $days = (int) $from->diff( $to )->format( '%r%a' );

                return $d->modify( "$days days" )->format( 'Ymd' ) . substr( $value, 8 );

            case 'month':
                $months = ( (int) $to->format( 'Y' ) * 12 + (int) $to->format( 'n' ) )
                        - ( (int) $from->format( 'Y' ) * 12 + (int) $from->format( 'n' ) );
                $m = (int) substr( $value, 0, 4 ) * 12 + (int) substr( $value, 4, 2 ) - 1 + $months;

                return sprintf( '%04d%02d', intdiv( $m, 12 ), $m % 12 + 1 );

            case 'year':
                return (string) ( (int) $value + (int) $to->format( 'Y' ) - (int) $from->format( 'Y' ) );
        }

        return $value;
    }
}
