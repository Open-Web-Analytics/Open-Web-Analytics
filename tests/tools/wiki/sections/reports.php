<?php
/**
 * The shipped reports, grouped as the report navigation groups them.
 *
 * Read from the report registry (CoreAPI::getReportRegistry) and the merged
 * report navigation (CoreAPI::getGroupNavigation), so the page lists what a
 * reader of the nav sees, in the nav's order. A registered report that no nav
 * entry points at is listed after, with the reports that link to it.
 *
 * Which modules contribute is decided by owa_wiki_boot(): every shipped module
 * but Hello.
 */

use OWA\Core\CoreAPI;

return function () {

    $registry = CoreAPI::getReportRegistry();
    ksort( $registry );

    $code = fn ( $v ) => '`' . $v . '`';
    $list = fn ( array $v ) => $v ? implode( ', ', array_map( $code, $v ) ) : '—';
    $csv  = fn ( $v ) => array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ) ) );

    $definitions = array();

    foreach ( $registry as $id => $entry ) {

        $definitions[ $id ] = isset( $entry['json'] )
            ? json_decode( (string) file_get_contents( $entry['json'] ), true )
            : null;
    }

    // Which reports link to which: a widget's link template, its "more" link,
    // and report-links widgets all name a reportId.
    $linked_from = array();

    foreach ( $definitions as $id => $d ) {

        if ( ! $d ) {
            continue;
        }

        array_walk_recursive( $d, function ( $v, $k ) use ( $id, &$linked_from ) {

            if ( $k === 'reportId' && is_string( $v ) && $v !== $id ) {
                $linked_from[ $v ][ $id ] = true;
            }
        } );
    }

    $describe = function ( $id ) use ( $registry, $definitions, $csv, $list, $code ) {

        $d    = $definitions[ $id ];
        $what = (string) ( $d ? ( $d['description'] ?? '' ) : ( $registry[ $id ]['description'] ?? '' ) );
        $what = $what === '' ? '—' : $what;

        if ( ! $d ) {

            return array( $code( $id ), $what, 'Rendered by ' . $code( $registry[ $id ]['controller'] ), '—', '—' );
        }

        $dims    = array();
        $metrics = $csv( $d['metrics'] ?? '' );

        foreach ( (array) ( $d['widgets'] ?? array() ) as $w ) {

            foreach ( $csv( $w['query']['dimensions'] ?? '' ) as $dim ) {

                if ( $dim !== 'date' ) {
                    $dims[ $dim ] = true;
                }
            }

            $metrics = array_merge( $metrics, $csv( $w['query']['metrics'] ?? '' ) );
        }

        $metrics = array_values( array_unique( $metrics ) );

        if ( ! $metrics ) {

            $metrics = isset( $d['metricSets'] ) && is_array( $d['metricSets'] )
                ? array_map( fn ( $k, $v ) => is_string( $v ) ? $v : $k, array_keys( $d['metricSets'] ), $d['metricSets'] )
                : array();
        }

        $by = $dims ? $list( array_keys( $dims ) ) : 'date';

        $constrained = array();

        $c = $d['settings']['constraints'] ?? '';

        if ( is_string( $c ) && $c !== '' ) {

            $constrained[] = $code( $c );

        } elseif ( is_array( $c ) ) {

            foreach ( $c as $part ) {

                $op = $part['operator'] ?? '==';

                if ( isset( \OWA\Core\ConfiguredReport::EMPTY_TESTS[ $op ] ) ) {

                    $constrained[] = $code( $part['dimension'] . \OWA\Core\ConfiguredReport::EMPTY_TESTS[ $op ] );

                } elseif ( isset( $part['fromParam'] ) ) {

                    $constrained[] = $code( $part['dimension'] . $op ) . ' the ' . $code( $part['fromParam'] ) . ' URL parameter';

                } else {

                    $constrained[] = $code( $part['dimension'] . $op . ( $part['value'] ?? '' ) );
                }
            }
        }

        // A declared parameter the report-wide constraint does not read is
        // still required: a widget's constraint reads it.
        foreach ( array_keys( (array) ( $d['params'] ?? array() ) ) as $p ) {

            if ( ! in_array( $p, is_array( $c ) ? array_column( $c, 'fromParam' ) : array(), true ) ) {
                $constrained[] = 'the ' . $code( $p ) . ' URL parameter';
            }
        }

        return array(
            $code( $id ),
            $what,
            $by,
            $metrics ? $list( $metrics ) : "The site's metric sets",
            $constrained ? implode( '; ', $constrained ) : '—',
        );
    };

    $row = fn ( array $cells ) => '| ' . implode( ' | ', array_map(
        fn ( $c ) => str_replace( array( '|', "\n" ), array( '\\|', ' ' ), (string) $c ), $cells ) ) . " |\n";

    $head = "| Report | Id | What it shows | Groups by | Metrics | Constrained to |\n|---|---|---|---|---|---|\n";

    $out    = '';
    $in_nav = array();
    $nav    = (array) CoreAPI::getGroupNavigation( 'Reports' );

    foreach ( $nav as $group => $entry ) {

        $links = array_merge( array( $entry ), (array) ( $entry['subgroup'] ?? array() ) );
        $rows  = '';

        foreach ( $links as $link ) {

            $id = $link['ref']['reportId'] ?? null;

            if ( $id === null || ! isset( $registry[ $id ] ) || isset( $in_nav[ $id ] ) ) {
                continue;
            }

            $in_nav[ $id ] = true;
            $rows .= $row( array_merge( array( $link['anchortext'] ), $describe( $id ) ) );
        }

        if ( $rows === '' ) {
            continue;
        }

        $out .= "### $group\n\n";

        $cap = $entry['priviledge'] ?? '';

        if ( $cap && $cap !== 'view_reports' ) {
            $out .= 'Requires the ' . $code( $cap ) . " capability.\n\n";
        }

        $out .= $head . $rows . "\n";
    }

    $rest = array_diff( array_keys( $registry ), array_keys( $in_nav ) );

    if ( $rest ) {

        $out .= "### Reached from other reports\n\n"
              . "These reports have no navigation entry. They open from a row or link in another report.\n\n"
              . "| Report | Id | What it shows | Groups by | Metrics | Constrained to | Linked from |\n|---|---|---|---|---|---|---|\n";

        foreach ( $rest as $id ) {

            $title = $definitions[ $id ]['title'] ?? $id;
            $from  = array_keys( $linked_from[ $id ] ?? array() );
            sort( $from );

            $out .= $row( array_merge( array( rtrim( $title, ': ' ) ), $describe( $id ), array( $list( $from ) ) ) );
        }
    }

    return $out;
};
