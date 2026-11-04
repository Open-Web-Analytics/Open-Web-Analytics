<?php
/**
 * The metric sets a detail report draws as tabs, when a Property has each, and
 * their metrics by label.
 *
 * Read from Core\MetricSets::SETS and SHOWN_WHEN, the sets forProperty()
 * chooses from; the labels from the metric registry.
 */

use OWA\Core\CoreAPI;
use OWA\Core\MetricSets;

return function () {

    $cell    = fn ( $v ) => str_replace( array( '|', "\n" ), array( '\\|', ' ' ), trim( (string) $v ) );
    $metrics = (array) CoreAPI::getAllMetrics();

    $label = function ( $name ) use ( $metrics ) {

        $first = is_array( $metrics[ $name ] ?? null ) ? reset( $metrics[ $name ] ) : array();

        return (string) ( $first['label'] ?? $first['params']['label'] ?? '' ) ?: '`' . $name . '`';
    };

    $out = "| Tab | Shown when | Metrics |\n|---|---|---|\n";

    foreach ( MetricSets::SETS as $key => $set ) {

        $names = array_filter( array_map( 'trim', explode( ',', (string) $set['metrics'] ) ) );

        $out .= sprintf( "| **%s** | %s | %s |\n",
            $cell( $set['label'] ),
            $cell( MetricSets::SHOWN_WHEN[ $key ] ?? '' ) ?: '—',
            $cell( implode( ', ', array_map( $label, $names ) ) ) );
    }

    return $out;
};
