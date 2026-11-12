<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Clear form field values from stored clicks and recordings.
 *
 *   php cli.php cmd=clear-field-values --dry-run
 *   php cli.php cmd=clear-field-values
 *   php cli.php cmd=clear-field-values site_id=<site id> batch=500
 *
 * Before 1.14.7 the tracker sent the value of a clicked <input> as the clicked
 * element's text, sent an element's value attribute with every click, and a
 * recording stored each key pressed in a field with the field's value. Those
 * are what a visitor typed or chose. 1.14.7 stops sending them; this removes
 * the ones already stored.
 *
 * WHAT IS CLEARED
 * - owa_click: dom_element_text on a click on an <input>, and dom_element_value
 *   on a click on anything but a <button>.
 * - owa_domstream: the same two properties on each click in a recording, and
 *   key_value, key_code and dom_element_value on each key press. The key press
 *   itself stays, so a recording still shows that a key was pressed and where.
 *
 * A click row does not say which type of <input> was clicked, so the label of an
 * <input type="submit"> is cleared along with what was typed into a text field.
 *
 * IT IS SAFE TO RUN AGAIN, AND SAFE TO STOP
 * A cleared row no longer matches, and a recording is only written when
 * something in it changed. Each table is walked by id, so the walk ends however
 * the data looks.
 */
class ClearFieldValuesCli extends \OWA\Core\Controller\Cli {

    /** Click rows per statement. */
    const BATCH = 1000;

    /** Recordings per read: each holds a whole stream of events. */
    const STREAM_BATCH = 100;

    /** The click rows that still hold a field value. */
    const CLICK_MATCH = "( ( LOWER( dom_element_tag ) = 'input'
                            AND dom_element_text IS NOT NULL AND dom_element_text <> '' )
                       OR ( LOWER( COALESCE( dom_element_tag, '' ) ) <> 'button'
                            AND dom_element_value IS NOT NULL AND dom_element_value <> '' ) )";

    /**
     * The recordings that may hold one. Every click in a stream names
     * dom_element_value, so this narrows the read to streams with clicks or
     * key presses; decoding the stream decides.
     */
    const STREAM_MATCH = "( events LIKE '%key_value%' OR events LIKE '%key_code%'
                         OR events LIKE '%dom_element_value%' OR events LIKE '%dom_element_text%' )";

    /** Rewrites stored analytics data in bulk, like repair-geo-encoding. */
    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_settings' );

        parent::__construct( $params );
    }

    function action() {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $dry_run = (bool) $this->getParam( 'dry-run' );
        $site_id = (string) $this->getParam( 'site_id' );
        $batch   = (int) $this->getParam( 'batch' );

        $scope  = $site_id !== '' ? ' AND site_id = ?' : '';
        $params = $site_id !== '' ? array( $site_id ) : array();

        $clicks = $db->get_row( 'SELECT COUNT(*) AS n FROM owa_click WHERE ' . self::CLICK_MATCH . $scope, $params );
        $streams = $db->get_row( 'SELECT COUNT(*) AS n FROM owa_domstream WHERE ' . self::STREAM_MATCH . $scope, $params );

        if ( $clicks === false || $streams === false ) {

            \OWA\Core\CoreAPI::notice( 'Could not read owa_click or owa_domstream.' );

            return;
        }

        $clicks  = (int) $clicks['n'];
        $streams = (int) $streams['n'];

        \OWA\Core\CoreAPI::notice( sprintf(
            '%d click(s) with a field value; %d recording(s) to check.%s',
            $clicks, $streams, $dry_run ? ' DRY RUN -- nothing will be written.' : '' ) );

        $this->set( 'clicks_matched', $clicks );
        $this->set( 'recordings_matched', $streams );

        if ( $dry_run ) {

            return;
        }

        $cleared = $this->clearClicks( $db, $scope, $params, $batch > 0 ? $batch : self::BATCH );

        if ( $cleared === false ) {

            return;
        }

        $result = $this->clearRecordings( $db, $scope, $params, $batch > 0 ? $batch : self::STREAM_BATCH );

        if ( $result === false ) {

            return;
        }

        list( $rewritten, $unreadable ) = $result;

        $this->set( 'clicks_cleared', $cleared );
        $this->set( 'recordings_rewritten', $rewritten );
        $this->set( 'recordings_unreadable', $unreadable );

        \OWA\Core\CoreAPI::notice( sprintf(
            'Cleared %d click(s) and rewrote %d recording(s).%s', $cleared, $rewritten,
            $unreadable ? sprintf( ' %d recording(s) could not be read as JSON and were left as they are.', $unreadable ) : '' ) );
    }

    /**
     * @return int|false rows cleared, or false on error
     */
    private function clearClicks( $db, $scope, array $params, $batch ) {

        $done   = 0;
        // Ids are signed and many are negative (the crc32 era), so start below all of them.
        $cursor = PHP_INT_MIN;

        while ( true ) {

            $rows = $db->get_results(
                'SELECT id FROM owa_click WHERE ' . self::CLICK_MATCH . $scope . ' AND id > ? ORDER BY id LIMIT ' . (int) $batch,
                array_merge( $params, array( $cursor ) ) );

            if ( $rows === false ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Stopped after %d click(s): could not read the next batch.', $done ) );

                return false;
            }

            if ( ! $rows ) {

                return $done;
            }

            $ids = array();

            foreach ( $rows as $row ) {

                $ids[] = (int) ( is_array( $row ) ? $row['id'] : $row->id );
            }

            $cursor = end( $ids );
            $in     = implode( ',', array_fill( 0, count( $ids ), '?' ) );

            $ok = $db->query(
                "UPDATE owa_click
                    SET dom_element_text = CASE WHEN LOWER( dom_element_tag ) = 'input' THEN NULL ELSE dom_element_text END,
                        dom_element_value = CASE WHEN LOWER( COALESCE( dom_element_tag, '' ) ) <> 'button' THEN NULL ELSE dom_element_value END
                  WHERE id IN ( $in )", $ids );

            if ( $ok === false ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Stopped after %d click(s): the update failed.', $done ) );

                return false;
            }

            $done += count( $ids );

            \OWA\Core\CoreAPI::notice( sprintf( '  clicks: %d', $done ) );
        }
    }

    /**
     * @return array|false [ rewritten, unreadable ], or false on error
     */
    private function clearRecordings( $db, $scope, array $params, $batch ) {

        $rewritten  = 0;
        $unreadable = 0;
        $cursor     = PHP_INT_MIN;

        while ( true ) {

            $rows = $db->get_results(
                'SELECT id, events FROM owa_domstream WHERE ' . self::STREAM_MATCH . $scope . ' AND id > ? ORDER BY id LIMIT ' . (int) $batch,
                array_merge( $params, array( $cursor ) ) );

            if ( $rows === false ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Stopped after %d recording(s): could not read the next batch.', $rewritten ) );

                return false;
            }

            if ( ! $rows ) {

                return array( $rewritten, $unreadable );
            }

            foreach ( $rows as $row ) {

                $row    = (array) $row;
                $cursor = (int) $row['id'];
                $events = self::clearStream( (string) $row['events'] );

                if ( $events === false ) {

                    $unreadable++;

                    continue;
                }

                if ( $events === null ) {

                    continue;
                }

                if ( $db->query( 'UPDATE owa_domstream SET events = ? WHERE id = ?', array( $events, $cursor ) ) === false ) {

                    \OWA\Core\CoreAPI::notice( sprintf( 'Stopped after %d recording(s): the update failed.', $rewritten ) );

                    return false;
                }

                $rewritten++;
            }

            \OWA\Core\CoreAPI::notice( sprintf( '  recordings rewritten: %d', $rewritten ) );
        }
    }

    /**
     * A recording's events with the field values taken out.
     *
     * @param string $json the stored stream: a JSON array of event objects
     * @return string|null|false the new JSON; null when nothing changed; false
     *                           when the stream is not a JSON array
     */
    public static function clearStream( $json ) {

        $events = json_decode( $json );

        if ( ! is_array( $events ) ) {

            return false;
        }

        $changed = false;

        foreach ( $events as $event ) {

            if ( ! is_object( $event ) ) {

                continue;
            }

            $type = isset( $event->event_type ) ? (string) $event->event_type : '';
            $tag  = isset( $event->dom_element_tag ) ? strtolower( (string) $event->dom_element_tag ) : '';

            if ( $type === 'dom.keypress' ) {

                $drop = array( 'key_value', 'key_code', 'dom_element_value' );

            } elseif ( $tag === 'input' ) {

                $drop = array( 'dom_element_text', 'dom_element_value' );

            } elseif ( $tag !== 'button' ) {

                $drop = array( 'dom_element_value' );

            } else {

                $drop = array();
            }

            foreach ( $drop as $key ) {

                if ( property_exists( $event, $key ) ) {

                    unset( $event->$key );
                    $changed = true;
                }
            }
        }

        if ( ! $changed ) {

            return null;
        }

        return json_encode( $events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }
}
