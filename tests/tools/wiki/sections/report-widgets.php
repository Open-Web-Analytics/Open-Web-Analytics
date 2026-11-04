<?php
/**
 * The widget types the custom report editor offers, what each may carry, and
 * the widths, heights and widget count a report is held to.
 *
 * Read from Classes\CustomReports, the class that validates a saved report:
 * WIDGET_TYPES and WIDGET_TYPE_HINTS, maxMetricsFor() and maxDimensionsFor(),
 * FIXED_DIMENSIONS, SINGLE_FIELD_TYPES, UNGROUPED_TYPES, FULL_WIDTH_TYPES,
 * NEW_WIDGET_COLSPANS, WIDTHS, HEIGHTS and MAX_WIDGETS.
 */

use OWA\Core\CoreAPI;
use OWA\Core\ReportGrid;
use OWA\Module\Base\Classes\CustomReports as CR;

return function () {

    $cell = fn ( $v ) => str_replace( array( '|', "\n" ), array( '\\|', ' ' ), trim( (string) $v ) );
    $dims = (array) CoreAPI::getAllDimensions();
    $or   = function ( array $words ) {

        $last = array_pop( $words );

        return $words ? implode( ', ', $words ) . ' and ' . $last : (string) $last;
    };

    $out = "| Widget type | What it draws | Metrics | Dimensions | Width when added |\n|---|---|---|---|---|\n";

    foreach ( CR::WIDGET_TYPES as $type => $label ) {

        $max_metrics = CR::maxMetricsFor( $type );
        $metrics     = $max_metrics === 1 ? '1' : 'Up to ' . $max_metrics;

        if ( in_array( $type, CR::UNGROUPED_TYPES, true ) ) {

            $dimensions = 'None';

        } elseif ( isset( CR::FIXED_DIMENSIONS[ $type ] ) ) {

            $fixed      = CR::FIXED_DIMENSIONS[ $type ];
            $extra      = CR::maxDimensionsFor( $type );
            $dimensions = ( $dims[ $fixed ]['label'] ?? $fixed )
                        . ( $extra ? ', plus up to ' . $extra . ' more' : ' only' );

        } elseif ( in_array( $type, CR::SINGLE_FIELD_TYPES, true ) ) {

            $dimensions = '1';

        } else {

            $dimensions = 'Up to ' . CR::maxDimensionsFor( $type );
        }

        $span  = CR::NEW_WIDGET_COLSPANS[ $type ] ?? ReportGrid::COLUMNS;
        $width = CR::WIDTHS[ $span ] ?? $span . ' of ' . ReportGrid::COLUMNS . ' columns';

        if ( in_array( $type, CR::FULL_WIDTH_TYPES, true ) ) {
            $width .= ', always';
        }

        $out .= sprintf( "| **%s** (`%s`) | %s | %s | %s | %s |\n",
            $cell( $label ), $type, $cell( CR::WIDGET_TYPE_HINTS[ $type ] ?? '' ) ?: '—',
            $metrics, $dimensions, $width );
    }

    $out .= "\nA report holds up to " . CR::MAX_WIDGETS . ' widgets. '
          . 'Widths are ' . $or( array_map( fn ( $w ) => '**' . $w . '**', array_values( CR::WIDTHS ) ) ) . '; '
          . 'heights are ' . $or( array_map( fn ( $h ) => '**' . $h . '**', array_values( CR::HEIGHTS ) ) ) . ".\n";

    return $out;
};
