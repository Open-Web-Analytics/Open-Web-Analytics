<?php
namespace OWA\Module\Domstream\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A `domstream` tracking event, checked and turned into the two rows it is
 * stored as -- or refused, with the reason.
 *
 * STRICT, because the payload is replayed in an operator's browser: a chunk
 * whose samples are not exactly the shapes the recorder sends is refused
 * whole rather than stored in part. See Recorder.js for the tuples:
 *
 *   [dt, 'm', dx, dy]
 *   [dt, 's', y]
 *   [dt, 'c', x, y, tag, id, name]
 *   [dt, 'k', tag, id, name]        -- which key is never sent
 */
final class Chunk {

    /** Most samples a chunk may carry, and the most characters of JSON. */
    const MAX_SAMPLES = 1000;
    const MAX_CHARS   = 65000;

    /** Bounds on the numbers in a sample. */
    const MAX_DT_MSEC   = 3600000;
    const MAX_COORD     = 1000000;
    const MAX_TEXT      = 255;

    /** @var string why the last fromEvent() refused, '' if it did not */
    private static $refusal = '';

    /** @return string the reason the last fromEvent() returned null */
    public static function refusal() {

        return self::$refusal;
    }

    /**
     * @param  object $event a `domstream` tracking event, after logEvent()'s edge checks
     * @return array|null    [ 'chunk' => row, 'payload' => row ], or null
     */
    public static function fromEvent( $event ) {

        self::$refusal = '';

        $site_id = (string) $event->get( 'site_id' );

        foreach ( array( 'recording_id', 'visitor_id', 'session_id' ) as $key ) {

            if ( ! self::isId( $event->get( $key ) ) ) {

                return self::refuse( "no valid $key" );
            }
        }

        $seq = $event->get( 'seq' );

        if ( ! self::isInt( $seq, 1, 1000000 ) ) {

            return self::refuse( 'no valid seq' );
        }

        if ( $site_id === '' ) {

            return self::refuse( 'no site_id' );
        }

        $samples = self::samples( $event->get( 'samples' ) );

        if ( $samples === null ) {

            return null;
        }

        $clicks = 0;
        $keys   = 0;

        foreach ( $samples as $sample ) {

            $clicks += $sample[1] === 'c' ? 1 : 0;
            $keys   += $sample[1] === 'k' ? 1 : 0;
        }

        $json       = json_encode( $samples );
        $compressed = gzencode( $json, 6 );

        $recording_id = (string) $event->get( 'recording_id' );
        $id           = self::id( $site_id, $recording_id, (int) $seq );
        $yyyymmdd     = (int) \OWA\Module\Base\Classes\TrackingEventHelpers::deriveYyyymmdd( null, $event );

        $page_view_seq = $event->get( 'page_view_seq' );
        $location      = (string) ( $event->get( 'page_location' ) ?: $event->get( 'page_url' ) );

        return array(
            'chunk' => array(
                'id'             => $id,
                'yyyymmdd'       => $yyyymmdd,
                'site_id'        => $site_id,
                'recording_id'   => $recording_id,
                'seq'            => (int) $seq,
                'visitor_id'     => (string) $event->get( 'visitor_id' ),
                'session_id'     => (string) $event->get( 'session_id' ),
                'page_view_seq'  => self::isInt( $page_view_seq, 1, PHP_INT_MAX ) ? (int) $page_view_seq : null,
                'page_location'  => $location === '' ? null : mb_substr( $location, 0, 1024 ),
                'page_path'      => self::path( $location, $site_id ),
                'ts'             => (int) $event->get( 'ts' ),
                'offset_ms'      => self::clamp( $event->get( 'offset_ms' ), 0, 2147483647 ),
                'duration_ms'    => self::clamp( $event->get( 'duration_ms' ), 0, 2147483647 ),
                'sample_count'   => count( $samples ),
                'click_count'    => $clicks,
                'keypress_count' => $keys,
                'viewport_w'     => self::isInt( $event->get( 'viewport_w' ), 0, 100000 ) ? (int) $event->get( 'viewport_w' ) : null,
                'viewport_h'     => self::isInt( $event->get( 'viewport_h' ), 0, 100000 ) ? (int) $event->get( 'viewport_h' ) : null,
                'bytes'          => strlen( $compressed ),
            ),
            'payload' => array(
                'id'       => $id,
                'yyyymmdd' => $yyyymmdd,
                'payload'  => $compressed,
            ),
        );
    }

    /**
     * The chunk's id, from its own content.
     *
     * @return int
     */
    public static function id( $site_id, $recording_id, $seq ) {

        return \OWA\Core\Lib::wideStringGuid( $site_id . '|' . $recording_id . '|' . (int) $seq );
    }

    /**
     * The samples, decoded and checked, or null with the refusal set.
     *
     * @param  mixed $raw the JSON the tracker sent
     * @return array|null
     */
    public static function samples( $raw ) {

        if ( ! is_string( $raw ) || $raw === '' ) {

            return self::refuse( 'no samples' );
        }

        // The endpoint's input filter entity-encodes what it passes through.
        $raw = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        if ( strlen( $raw ) > self::MAX_CHARS ) {

            return self::refuse( 'samples too large' );
        }

        $samples = json_decode( $raw, true );

        if ( ! is_array( $samples ) || ! $samples || ! array_is_list( $samples ) ) {

            return self::refuse( 'samples are not a list' );
        }

        if ( count( $samples ) > self::MAX_SAMPLES ) {

            return self::refuse( 'too many samples' );
        }

        foreach ( $samples as $i => $sample ) {

            if ( ! self::isSample( $sample ) ) {

                return self::refuse( "sample $i is not a recorder tuple" );
            }
        }

        return $samples;
    }

    private static function isSample( $s ) {

        if ( ! is_array( $s ) || ! array_is_list( $s ) || count( $s ) < 2 ) {

            return false;
        }

        if ( ! self::isInt( $s[0], 0, self::MAX_DT_MSEC ) ) {

            return false;
        }

        switch ( $s[1] ) {

            case 'm':
                return count( $s ) === 4
                    && self::isInt( $s[2], -self::MAX_COORD, self::MAX_COORD )
                    && self::isInt( $s[3], -self::MAX_COORD, self::MAX_COORD );

            case 's':
                return count( $s ) === 3 && self::isInt( $s[2], 0, self::MAX_COORD );

            case 'c':
                return count( $s ) === 7
                    && self::isInt( $s[2], 0, self::MAX_COORD )
                    && self::isInt( $s[3], 0, self::MAX_COORD )
                    && self::isText( $s[4] ) && self::isText( $s[5] ) && self::isText( $s[6] );

            case 'k':
                return count( $s ) === 5
                    && self::isText( $s[2] ) && self::isText( $s[3] ) && self::isText( $s[4] );
        }

        return false;
    }

    /**
     * The page's path, canonicalised exactly as a raw event's page_path is, so
     * Page Detail's pagePath finds the same page here.
     */
    private static function path( $location, $site_id ) {

        if ( $location === '' ) {

            return null;
        }

        $parts = \OWA\Module\Base\Classes\V2Event::parseUrl( $location );

        return mb_substr( (string) \OWA\Module\Base\Classes\V2Event::canonicalPath(
            $parts['path'], (string) \OWA\Core\CoreAPI::getSetting(
                'base', 'default_page', 'profile', $site_id ) ), 0, 1024 );
    }

    private static function isInt( $v, $min, $max ) {

        if ( is_int( $v ) ) {

            return $v >= $min && $v <= $max;
        }

        if ( is_string( $v ) && preg_match( '/^-?[0-9]{1,19}$/', $v ) ) {

            return (int) $v >= $min && (int) $v <= $max;
        }

        return false;
    }

    private static function isId( $v ) {

        return ( is_int( $v ) && $v > 0 )
            || ( is_string( $v ) && preg_match( '/^[1-9][0-9]{0,18}$/', $v ) );
    }

    private static function isText( $v ) {

        return is_string( $v ) && mb_strlen( $v ) <= self::MAX_TEXT;
    }

    private static function clamp( $v, $min, $max ) {

        return self::isInt( $v, PHP_INT_MIN, PHP_INT_MAX ) ? max( $min, min( $max, (int) $v ) ) : $min;
    }

    private static function refuse( $reason ) {

        self::$refusal = $reason;

        return null;
    }
}

?>
