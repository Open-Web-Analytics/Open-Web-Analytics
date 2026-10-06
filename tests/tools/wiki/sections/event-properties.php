<?php
/**
 * Every tracking property, from modules/Base/config/tracking_properties.json.
 *
 * One table per `set_by`, because `from` means a different thing in each: the
 * wire key the tracker sends it under, the $_SERVER keys tried in order, or the
 * properties it is derived from. The registry's `description` is the user-facing
 * text; its `note` is for whoever edits the file and is not published. Older
 * names come from the `legacy` renames in conf/beacon_compat.php.
 *
 *   event-properties          every property
 *   event-properties-<event>  only those <event> carries, from propertiesForEvent()
 */

use OWA\Module\Base\Classes\TrackingEventHelpers;

return function ( $event = null ) {

    $path   = OWA_DIR . 'modules/Base/config/tracking_properties.json';
    $config = json_decode( file_get_contents( $path ), true );

    if ( ! is_array( $config ) ) {
        throw new RuntimeException( "Could not read $path" );
    }

    if ( $event !== null ) {

        if ( ! in_array( $event, TrackingEventHelpers::eventNames(), true ) ) {
            throw new RuntimeException( "'$event' is not a first-class event." );
        }

        $config = array_intersect_key( $config,
            array_flip( TrackingEventHelpers::propertiesForEvent( $event ) ) );
    }

    $compat = (array) \OWA\Core\CoreAPI::loadConf( 'beacon_compat.php', 'beacon.compat' );
    $legacy = array();

    foreach ( (array) ( $compat['renames'] ?? array() ) as $r ) {
        if ( ( $r['role'] ?? '' ) === 'legacy' ) {
            $legacy[ $r['to'] ][] = $r['from'];
        }
    }

    $cell = fn ( $v ) => str_replace( array( '|', "\r", "\n" ), array( '\\|', '', ' ' ), trim( (string) $v ) );
    $code = fn ( array $l ) => $l ? '`' . implode( '`, `', $l ) . '`' : '—';

    $stored = function ( array $p ) {
        if ( ! empty( $p['column'] ) ) {
            return 'column `' . $p['column'] . '`';
        }
        if ( ! empty( $p['param'] ) ) {
            return '`params.' . $p['param'] . '`';
        }
        return '—';
    };

    $events = function ( array $p ) {
        if ( ! array_key_exists( 'events', $p ) ) {
            return 'every event';
        }
        $e = (array) $p['events'];
        if ( in_array( TrackingEventHelpers::EVERY_EVENT, $e, true ) ) {
            return 'every event';
        }
        return $e ? '`' . implode( '`, `', $e ) . '`' : 'none';
    };

    $groups = array(
        'client'  => array( 'Sent by the tracker', 'Wire key' ),
        'request' => array( 'Read from the request', 'Request keys, in order' ),
        'event'   => array( 'Derived at ingest', 'Derived from' ),
    );

    $out = '';

    foreach ( $groups as $set_by => list( $heading, $from_label ) ) {

        $rows = array_filter( $config, fn ( $p ) => ( $p['set_by'] ?? '' ) === $set_by );

        if ( ! $rows ) {
            continue;
        }

        ksort( $rows );

        $out .= "### $heading\n\n"
              . "| Property | $from_label | Stored in | Events | Type | Required | Description |\n"
              . "|---|---|---|---|---|---|---|\n";

        foreach ( $rows as $name => $p ) {

            $from = $code( (array) ( $p['from'] ?? array() ) );

            if ( isset( $legacy[ $name ] ) ) {
                $from .= ' (older trackers: ' . $code( $legacy[ $name ] ) . ')';
            }

            $out .= sprintf( "| `%s` | %s | %s | %s | %s | %s | %s |\n",
                $name, $from, $stored( $p ), $events( $p ),
                $cell( $p['data_type'] ?? '—' ),
                empty( $p['required'] ) ? 'no' : 'yes',
                $cell( $p['description'] ?? '' ) ?: '—' );
        }

        $out .= "\n";
    }

    return $out;
};
