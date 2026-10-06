<?php
/**
 * The first-class event types, from modules/Base/config/tracking_events.php:
 * each one's `description`, and the properties it carries beyond those every
 * event carries.
 *
 * Properties are read through TrackingEventHelpers, so a materialized event
 * leaves out the properties declared `"materialize": false` exactly as ingest
 * does.
 */

use OWA\Module\Base\Classes\TrackingEventHelpers;

return function () {

    $declared = include OWA_DIR . 'modules/Base/config/tracking_events.php';
    $names    = TrackingEventHelpers::eventNames();

    if ( ! $names ) {
        throw new RuntimeException( 'No first-class events are declared.' );
    }

    // What every event carries: the intersection, so it is stated once.
    $every = null;

    foreach ( $names as $name ) {
        $props = TrackingEventHelpers::propertiesForEvent( $name );
        $every = $every === null ? $props : array_values( array_intersect( $every, $props ) );
    }

    $out = "| Event | Description | Properties beyond those on every event |\n"
         . "|---|---|---|\n";

    foreach ( $names as $name ) {

        $own = array_diff( TrackingEventHelpers::propertiesForEvent( $name ), $every );

        $out .= sprintf( "| `%s` | %s | %s |\n", $name,
            str_replace( '|', '\\|', trim( (string) ( $declared[ $name ]['description'] ?? '' ) ) ) ?: '—',
            $own ? '`' . implode( '`, `', $own ) . '`' : '—' );
    }

    sort( $every );

    $out .= "\nEvery event carries: `" . implode( '`, `', $every ) . "`.\n";

    return $out;
};
