<?php
/**
 * The wire prefixes a site's own properties travel under, from the constants
 * ingest reads them by (Handler\EventRawHandlers) and the gate that admits them
 * (TrackingEventHelpers::CUSTOM_PREFIXES, CUSTOM_NAME_PATTERN).
 *
 * Scope and type are read off each constant's NAME -- EVENT_ or USER_, and
 * NUMBER_ for the numeric half -- so a prefix added without following that
 * naming fails here rather than being documented wrongly.
 */

use OWA\Module\Base\Classes\TrackingEventHelpers;
use OWA\Module\Base\Handler\EventRawHandlers;

return function () {

    $constants = ( new ReflectionClass( EventRawHandlers::class ) )->getConstants();
    $rows      = array();

    foreach ( $constants as $name => $prefix ) {

        if ( ! preg_match( '/^(EVENT|USER)_PROPERTY_(NUMBER_)?PREFIX$/', $name, $m ) ) {
            continue;
        }

        $rows[ $prefix ] = sprintf( "| `%s` | %s | %s | `%s` |\n", $prefix,
            $m[1] === 'EVENT' ? 'this event' : 'the visitor',
            ! empty( $m[2] ) ? 'number' : 'text', // an unmatched last group is absent, not ''
            $m[1] === 'EVENT' ? 'setEventProperty' : 'setUserProperty' );
    }

    $gate = TrackingEventHelpers::CUSTOM_PREFIXES;
    sort( $gate );
    $documented = array_keys( $rows );
    sort( $documented );

    if ( $gate !== $documented ) {
        throw new RuntimeException( 'The admitted custom prefixes (' . implode( ', ', $gate )
            . ') differ from the ones ingest reads (' . implode( ', ', $documented ) . ').' );
    }

    ksort( $rows );

    return "| Prefix | Describes | Value | Tracker command |\n|---|---|---|---|\n"
         . implode( '', $rows )
         . sprintf( "\nA name after the prefix must match `%s`. Up to %d custom properties are kept per event, in each scope.\n",
               TrackingEventHelpers::CUSTOM_NAME_PATTERN, EventRawHandlers::MAX_CUSTOM_PROPERTIES );
};
