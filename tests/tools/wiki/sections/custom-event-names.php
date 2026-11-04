<?php
/**
 * The custom event names the tracker refuses, read from the tracker's source
 * (modules/Base/src/tracker/Tracker.js) and the modules' build manifests:
 *
 *   - the pattern isLegalCustomEventName() tests, which must be the server's
 *     TrackingEventHelpers::CUSTOM_NAME_PATTERN (an error otherwise)
 *   - OWATracker.RESERVED_EVENT_NAMES, the first-class names
 *     (TrackerReservedEventNamesTest holds them to the server's)
 *   - the reservedEventNames of each module's lazy tracker plugin, from its
 *     build.manifest.json `contributes_lazy`, which the tracker reserves
 *     before the plugin loads
 *   - OWATracker.RESERVED_EVENT_PREFIX
 *
 * Read as text, as TrackerReservedEventNamesTest reads it: the source is an ES
 * module webpack builds, not something node runs as it is.
 */

use OWA\Module\Base\Classes\TrackingEventHelpers;

return function () {

    $source = (string) file_get_contents( OWA_DIR . 'modules/Base/src/tracker/Tracker.js' );

    $body = function ( $marker ) use ( $source ) {

        $at = strpos( $source, $marker );

        if ( $at === false ) {
            throw new RuntimeException( "Tracker.js has no `$marker`; the custom-event-names section is stale." );
        }

        return substr( $source, $at, 4000 );
    };

    // The pattern: the first regex literal isLegalCustomEventName() tests.
    if ( ! preg_match( '#(/\^[^/\n]+\$/)\.test\(#', $body( 'static isLegalCustomEventName(' ), $m ) ) {
        throw new RuntimeException( 'No name pattern found in OWATracker.isLegalCustomEventName().' );
    }

    $pattern = $m[1];

    if ( $pattern !== TrackingEventHelpers::CUSTOM_NAME_PATTERN ) {
        throw new RuntimeException( "The tracker's custom event name pattern $pattern differs from the server's "
            . TrackingEventHelpers::CUSTOM_NAME_PATTERN . '.' );
    }

    // The first-class names: the quoted names in the getter's own list.
    $list = $body( 'static get RESERVED_EVENT_NAMES()' );
    $list = substr( $list, 0, (int) strpos( $list, '];' ) );

    preg_match_all( "/'([a-z_]+)'/", $list, $found );

    $reserved = (array) $found[1];

    if ( ! $reserved ) {
        throw new RuntimeException( 'OWATracker.RESERVED_EVENT_NAMES parsed empty.' );
    }

    sort( $reserved );

    // What each module's lazy plugin sends.
    $modules = array();

    foreach ( glob( OWA_MODULES_DIR . '*/build.manifest.json' ) as $path ) {

        $dir = basename( dirname( $path ) );

        if ( in_array( strtolower( $dir ), OWA_WIKI_EXCLUDED_MODULES, true ) ) {
            continue;
        }

        $manifest = json_decode( (string) file_get_contents( $path ), true );

        foreach ( (array) ( $manifest['contributes_lazy'] ?? array() ) as $plugins ) {
            foreach ( (array) $plugins as $plugin ) {
                foreach ( (array) ( $plugin['reservedEventNames'] ?? array() ) as $name ) {
                    $modules[ (string) $name ] = $dir;
                }
            }
        }
    }

    ksort( $modules );

    if ( ! preg_match( "/static get RESERVED_EVENT_PREFIX\(\)\s*\{\s*return '([^']+)';/", $source, $p ) ) {
        throw new RuntimeException( 'OWATracker.RESERVED_EVENT_PREFIX not found.' );
    }

    $code = fn ( array $names ) => '`' . implode( '`, `', $names ) . '`';

    $moduleCells = array();

    foreach ( $modules as $name => $dir ) {
        $moduleCells[] = "`$name` ($dir module)";
    }

    return "| Refused when the name | Value |\n|---|---|\n"
         . sprintf( "| Does not match | `%s` |\n", str_replace( '|', '\\|', $pattern ) )
         . sprintf( "| Is a first-class event | %s |\n", $code( $reserved ) )
         . ( $moduleCells ? sprintf( "| Is an event a tracker module sends | %s |\n", implode( ', ', $moduleCells ) ) : '' )
         . sprintf( "| Starts with | `%s` |\n", $p[1] );
};
