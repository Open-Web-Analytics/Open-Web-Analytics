<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

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
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 */
class CompareMerger {

    /** The dimensions whose values are a position on the time axis, and their unit. */
    const TIME_AXIS = array(
        'date'      => 'day',
        'dateHour'  => 'hour',
        'yearMonth' => 'month',
        'year'      => 'year',
    );

    /** The dimensions a comparison query can restrict to the rows on the page. */
    public static function restrictable( array $dimensions ) {

        return array_values( array_filter( $dimensions, function ( $d ) {
            return ! isset( self::TIME_AXIS[ $d ] );
        } ) );
    }

    /**
     * @param object $rs      the period's result set (paginatedResultSet), changed in place
     * @param object $crs     the comparison period's result set
     * @param array  $dims    the dimensions both were grouped by
     * @param array  $main    [ start, end ] yyyymmdd of the period
     * @param array  $cmp     [ start, end ] yyyymmdd of the comparison
     * @param string $compare the `compare` key it was asked as
     * @return object $rs
     */
    public static function merge( $rs, $crs, array $dims, array $main, array $cmp, $compare ) {

        $rs->compare = array(
            'compare'    => (string) $compare,
            'startDate'  => (string) $cmp[0],
            'endDate'    => (string) $cmp[1],
            'aggregates' => (array) ( $crs->aggregates ?? array() ),
        );

        $rs->aggregates = self::mergeCells( (array) $rs->aggregates, (array) ( $crs->aggregates ?? array() ) );

        /*
         * A TREND -- every dimension a time-axis one -- also gets the
         * comparison's own rows, its dates moved onto the period's. A chart
         * draws the comparison as a line of its own, and a day the period has
         * no row for (nothing happened) still has a comparison value; matched
         * onto the period's rows alone, that day's would be lost.
         */
        if ( $dims && ! self::restrictable( $dims ) ) {

            $rs->compare['rows'] = array();

            foreach ( (array) ( $crs->resultsRows ?? array() ) as $row ) {

                foreach ( $dims as $d ) {

                    if ( isset( $row[ $d ] ) && is_array( $row[ $d ] ) && $row[ $d ]['value'] !== null && $row[ $d ]['value'] !== '' ) {

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

        $index = array();

        foreach ( (array) ( $crs->resultsRows ?? array() ) as $row ) {

            $index[ self::key( $row, $dims, $cmp, $main ) ] = $row;
        }

        foreach ( $rs->resultsRows as $i => $row ) {

            $match = $index[ self::key( $row, $dims, $main, $main ) ] ?? array();

            $rs->resultsRows[ $i ] = self::mergeCells( $row, $match );
        }

        return $rs;
    }

    /** Each metric cell of $cells, given the matching cell of $compare. */
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

    /** (value - compare) / compare, or null when there is nothing to divide by. */
    public static function change( $value, $compare ) {

        if ( ! is_numeric( $value ) || ! is_numeric( $compare ) || (float) $compare == 0.0 ) {

            return null;
        }

        return ( (float) $value - (float) $compare ) / (float) $compare;
    }

    /**
     * A row's identity: its dimension values, with each time-axis value
     * moved from its own period ($from) to the same place in $to.
     */
    private static function key( array $row, array $dims, array $from, array $to ) {

        $parts = array();

        foreach ( $dims as $d ) {

            $v = isset( $row[ $d ] ) && is_array( $row[ $d ] ) ? $row[ $d ]['value'] : null;

            if ( isset( self::TIME_AXIS[ $d ] ) && $v !== null && $v !== '' ) {

                $v = self::shift( self::TIME_AXIS[ $d ], (string) $v, $from[0], $to[0] );
            }

            $parts[] = (string) $v;
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
