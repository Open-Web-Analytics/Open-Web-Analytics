<?php

namespace OWA\Module\Base\Classes;

/**
 * The tracker's defaults: what it starts and the option values it uses when
 * nothing sets them.
 *
 * ONE LIST, modules/Base/src/tracker/defaults.json. The tracker imports it and
 * webpack builds it in; the Profile's tracker_* settings take their defaults
 * from it here. A page overrides any of them with setOption.
 */
class TrackerDefaults {

    const FILE = 'modules/Base/src/tracker/defaults.json';

    /** @var array|null */
    private static $defaults;

    /**
     * @return array features, scrollThresholds, downloadExtensions, siteSearchParams
     */
    public static function all() {

        if ( self::$defaults === null ) {

            $decoded = json_decode( (string) @file_get_contents( OWA_DIR . self::FILE ), true );

            self::$defaults = is_array( $decoded ) ? $decoded : array();
        }

        return self::$defaults;
    }

    /**
     * @param  string $key
     * @return array
     */
    public static function get( $key ) {

        return array_values( (array) ( self::all()[ $key ] ?? array() ) );
    }

    /**
     * A list as a text setting's default: comma-separated.
     *
     * @param  string $key
     * @return string
     */
    public static function text( $key ) {

        return implode( ', ', self::get( $key ) );
    }

    /**
     * Whether the tracker starts this feature by default.
     *
     * @param  string $command e.g. trackScroll
     * @return bool
     */
    public static function starts( $command ) {

        return in_array( $command, self::get( 'features' ), true );
    }
}
