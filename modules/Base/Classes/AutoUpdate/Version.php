<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * A release version: MAJOR.MINOR.PATCH and nothing else, as the Release
 * Packager tags them (1.14.10, 2.0.0). A tag of any other shape is not a
 * release automatic updates consider.
 */
class Version {

    const PATCH = 'patch';
    const MINOR = 'minor';
    const MAJOR = 'major';

    /**
     * @param  string $version
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function parse( $version ) {

        if ( ! preg_match( '/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/', (string) $version, $m ) ) {

            return null;
        }

        return [ (int) $m[1], (int) $m[2], (int) $m[3] ];
    }

    /**
     * -1, 0 or 1, as $a is lower than, equal to or higher than $b.
     *
     * @param  string $a
     * @param  string $b
     * @return int
     * @throws \InvalidArgumentException for a version parse() refuses
     */
    public static function compare( $a, $b ) {

        $pa = self::parse( $a );
        $pb = self::parse( $b );

        if ( $pa === null || $pb === null ) {

            throw new \InvalidArgumentException( sprintf( 'Not a release version: %s', $pa === null ? $a : $b ) );
        }

        return $pa <=> $pb;
    }

    /**
     * What kind of step $to is up from $from: PATCH, MINOR or MAJOR, or null
     * when it is not higher.
     *
     * @param  string $from
     * @param  string $to
     * @return string|null
     */
    public static function step( $from, $to ) {

        if ( self::compare( $to, $from ) <= 0 ) {

            return null;
        }

        [ $f, $t ] = [ self::parse( $from ), self::parse( $to ) ];

        if ( $f[0] !== $t[0] ) {

            return self::MAJOR;
        }

        return $f[1] !== $t[1] ? self::MINOR : self::PATCH;
    }

    /**
     * Whether automatic updates at $level (PATCH or MINOR) go from $from to
     * $to: a higher release, never another major, a minor one only at MINOR.
     *
     * @param  string $level
     * @param  string $from
     * @param  string $to
     * @return bool
     */
    public static function allows( $level, $from, $to ) {

        $step = self::step( $from, $to );

        return $step === self::PATCH || ( $step === self::MINOR && $level === self::MINOR );
    }
}
