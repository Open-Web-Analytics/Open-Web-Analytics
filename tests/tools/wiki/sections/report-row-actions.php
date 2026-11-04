<?php
/**
 * The actions a report table's rows can offer, and the shipped widgets that
 * offer each.
 *
 * Read from ConfiguredReport::rowActions(), which is ROW_ACTIONS plus what
 * modules add through the report_row_actions filter. An action a module adds is
 * attributed to that module by asking each of the filter's listeners on its own.
 * "Offered on" is every shipped report definition's widgets that name the
 * action in `rowActions`.
 *
 * Every shipped module is active when the generator runs (generate.php), so a
 * module's actions and reports are present.
 */

use OWA\Core\ConfiguredReport;
use OWA\Core\CoreAPI;

return function () {

    $cell = fn ( $v ) => str_replace( array( '|', "\n" ), array( '\\|', ' ' ), trim( (string) $v ) );

    $actions = ConfiguredReport::rowActions();

    // Which module added each action that ROW_ACTIONS does not declare.
    $added_by = array();
    $ed       = CoreAPI::getEventDispatch();

    foreach ( (array) ( $ed->listenersByFilterType['report_row_actions'] ?? array() ) as $ids ) {

        foreach ( (array) $ids as $id ) {

            $listener = $ed->listeners[ $id ] ?? null;

            if ( ! is_array( $listener ) || ! $listener[0] instanceof \OWA\Core\Module ) {
                continue;
            }

            foreach ( array_keys( (array) call_user_func( $listener, array() ) ) as $name ) {
                $added_by[ $name ] = $listener[0]->display_name ?: $listener[0]->name;
            }
        }
    }

    // Which shipped widgets name each action.
    $offered = array();

    $registry = array_filter( CoreAPI::getReportRegistry(),
        fn ( $entry ) => ! in_array( $entry['module'] ?? '', OWA_WIKI_EXCLUDED_MODULES, true ) );
    ksort( $registry );

    foreach ( $registry as $id => $entry ) {

        if ( ! isset( $entry['json'] ) ) {
            continue;
        }

        $d     = json_decode( (string) file_get_contents( $entry['json'] ), true );
        $title = rtrim( (string) ( $d['title'] ?? $id ), ': ' );

        foreach ( (array) ( $d['widgets'] ?? array() ) as $widget ) {

            foreach ( (array) ( $widget['rowActions'] ?? array() ) as $name ) {

                $where = '**' . $title . '**';

                if ( ! empty( $widget['title'] ) ) {
                    $where .= ': the **' . $widget['title'] . '** '
                            . ( ( $widget['type'] ?? '' ) === 'grid-card' ? 'card' : 'table' );
                }

                $offered[ $name ][] = $where;
            }
        }
    }

    $out = "| Action | Opens | Needs first dimension | Offered on |\n|---|---|---|---|\n";

    foreach ( $actions as $name => $a ) {

        $on = isset( $offered[ $name ] ) ? implode( ', ', $offered[ $name ] ) : 'No shipped report';

        if ( isset( $added_by[ $name ] ) && ! isset( ConfiguredReport::ROW_ACTIONS[ $name ] ) ) {
            $on .= '. Needs the ' . $added_by[ $name ] . ' module';
        }

        $out .= sprintf( "| **%s** (`%s`) | %s | %s | %s |\n",
            $cell( $a['label'] ?? $name ),
            $name,
            $cell( $a['description'] ?? '' ) ?: '—',
            isset( $a['first'] ) && $a['first'] !== '' ? '`' . $a['first'] . '`' : '—',
            $cell( $on ) );
    }

    return $out;
};
