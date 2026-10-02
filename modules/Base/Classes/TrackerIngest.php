<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The tracker-ingest intake (PLAN 2.30.3, 2.30.4): beacons on their way into
 * ingest, through whichever IntakeQueue tracker_ingest_queue_type names.
 *
 * log.php's path decides between two modes. QUEUED (queue_tracker_ingest)
 * sends every beacon to the intake and ingests nothing in the request. DIRECT
 * ingests in the request, and a beacon whose ingest failed -- a write that
 * rolled back -- is sent to the intake to be retried, in either mode.
 *
 * The drain is generic over the contract: drain-tracker-ingest every minute,
 * or, with tracker_ingest_drain = external, a module's own consumer calling
 * ingestMessage() per message.
 */
class TrackerIngest {

    /** The intake's registered name. It was incoming_tracking_events. */
    const QUEUE = 'tracker-ingest';

    /** The envelope's format. */
    const ENVELOPE_VERSION = 1;

    /** Received this many times without being ingested, a beacon is dead-lettered. */
    const RECEIVE_LIMIT = 5;

    /** Seconds before each retry, by receive count; the last step is reused. */
    const BACKOFF = array( 60, 300, 900, 3600 );

    /** How many messages one receive() asks for. */
    const BATCH = 100;

    /** How long a received message stays hidden, for a backend that needs to be told. */
    const VISIBILITY = 300;

    /** @var \OWA\Core\IntakeQueue|null a queue to use instead of the configured one. TESTS ONLY. */
    public static $queue = null;

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    /**
     * Whether beacons are queued rather than ingested in the request.
     *
     * queue_tracker_ingest, and when that is unset the 1.x names, which some
     * installs set in owa-config.php: queue_incoming_tracking_events, and
     * queue_events (OWA_QUEUE_EVENTS).
     *
     * @return bool
     */
    public static function isQueued() {

        $value = \OWA\Core\CoreAPI::getSetting( 'base', 'queue_tracker_ingest' );

        if ( $value !== null && $value !== '' ) {

            return (bool) $value;
        }

        return (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'queue_incoming_tracking_events' )
            || (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'queue_events' );
    }

    /** Whether the scheduler drains the intake, or something outside OWA does. */
    public static function isDrainedExternally() {

        return \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_ingest_drain' ) === 'external';
    }

    /**
     * The intake: the implementation tracker_ingest_queue_type names, built
     * with the intake's own registration.
     *
     * @return \OWA\Core\IntakeQueue
     * @throws \Exception when the type is unknown or does not meet the contract
     */
    public static function queue() {

        static $built = array();

        if ( self::$queue ) {

            return self::$queue;
        }

        $type = (string) ( \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_ingest_queue_type' ) ?: 'file' );

        if ( isset( $built[ $type ] ) ) {

            return $built[ $type ];
        }

        $s              = \OWA\Core\CoreAPI::serviceSingleton();
        $map            = (array) $s->getMapValue( 'event_queues', self::QUEUE );
        $implementation = $s->getMapValue( 'event_queue_types', $type );

        if ( ! $implementation ) {

            throw new \Exception( sprintf( 'tracker_ingest_queue_type is "%s", and no module registers that queue type.', $type ) );
        }

        $map['queue_type'] = $type;
        $queue = \OWA\Core\Lib::simpleFactory( $implementation[0], $implementation[1], $map );

        if ( ! $queue instanceof \OWA\Core\IntakeQueue ) {

            throw new \Exception( sprintf( 'Queue type "%s" (%s) does not implement the tracking intake contract.',
                $type, $implementation[0] ) );
        }

        return $built[ $type ] = $queue;
    }

    // ---------------------------------------------------------------------
    // The envelope
    // ---------------------------------------------------------------------

    /**
     * An event as an envelope: {v, type, properties, queued_at}.
     *
     * The guid and the event's time travel in its properties, as they always
     * have.
     *
     * @return array
     */
    public static function envelope( $event ) {

        return array(
            'v'          => self::ENVELOPE_VERSION,
            'type'       => (string) $event->getEventType(),
            'properties' => (array) $event->getProperties(),
            'queued_at'  => time(),
        );
    }

    /**
     * An envelope as a tracking event, ready for ingest; null when it is not
     * one this version reads.
     *
     * @return Event|null
     */
    public static function event( array $envelope ) {

        if ( (int) ( $envelope['v'] ?? 0 ) !== self::ENVELOPE_VERSION
             || ! isset( $envelope['type'] ) || ! is_string( $envelope['type'] ) || $envelope['type'] === ''
             || ! isset( $envelope['properties'] ) || ! is_array( $envelope['properties'] ) ) {

            return null;
        }

        $event      = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $properties = $envelope['properties'];

        $event->setEventType( $envelope['type'] );
        $event->replaceProperties( $properties );
        // Validated: numeric only (Event::loadFromArray()).
        $event->loadFromArray( array_intersect_key( $properties, array( 'guid' => 1, 'timestamp' => 1 ) ) );
        $event->setDispatchName( \OWA\Core\CoreAPI::trackingDispatchName( $envelope['type'] ) );

        return $event;
    }

    // ---------------------------------------------------------------------
    // Producing
    // ---------------------------------------------------------------------

    /**
     * Queue a beacon for the drain.
     *
     * @param  Event $event
     * @param  int   $delay
     * @return bool
     */
    public static function send( $event, $delay = 0 ) {

        try {

            return (bool) self::queue()->send( self::envelope( $event ), $delay );

        } catch ( \Throwable $t ) {

            \OWA\Core\CoreAPI::error( 'Tracker ingest: could not queue a beacon: ' . $t->getMessage() );

            return false;
        }
    }

    /**
     * A beacon whose ingest failed in the request: retry it from the intake.
     *
     * @param  Event $event
     * @param  array $envelope  the beacon as it arrived, before processing rewrote it
     * @return bool
     */
    public static function retryLater( $event, array $envelope ) {

        \OWA\Core\CoreAPI::notice( sprintf( 'Tracker ingest: %s %s was not ingested; queued to retry.',
            $event->getEventType(), $event->getGuid() ) );

        try {

            return (bool) self::queue()->send( $envelope, self::BACKOFF[0] );

        } catch ( \Throwable $t ) {

            \OWA\Core\CoreAPI::error( 'Tracker ingest: could not queue a retry: ' . $t->getMessage() );

            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Consuming
    // ---------------------------------------------------------------------

    /**
     * Ingest one event the way log.php's direct mode does.
     *
     * @return bool whether it was ingested: false when a handler failed or it threw
     */
    public static function ingest( $event ) {

        \OWA\Module\Base\Classes\Beacon\Compat::applyToQueued( $event );

        $processor = \OWA\Core\CoreAPI::getEventProcessor( $event );

        if ( ! $processor ) {

            // Nothing on this install processes it: done, as it would be in the request.
            return true;
        }

        \OWA\Core\CoreAPI::performAction( $processor, array( 'event' => $event ) );

        return $event->getStatus() !== Event::failed;
    }

    /**
     * Deal with one received message: ingest and ack it, release it to retry,
     * or dead-letter it.
     *
     * What an external consumer calls per message, with the same queue.
     *
     * @return string ingested, released, dead
     */
    public static function ingestMessage( \OWA\Core\IntakeQueue $queue, \OWA\Core\IntakeMessage $message ) {

        if ( $message->receive_count > self::RECEIVE_LIMIT ) {

            $queue->deadLetter( $message, sprintf( 'Received %d times without being ingested.', $message->receive_count - 1 ) );

            return 'dead';
        }

        $event = $message->envelope !== null ? self::event( $message->envelope ) : null;

        if ( ! $event ) {

            $queue->deadLetter( $message, 'Not a tracker-ingest envelope this version reads.' );

            return 'dead';
        }

        try {

            $ok = self::ingest( $event );

        } catch ( \Throwable $t ) {

            \OWA\Core\CoreAPI::notice( 'Tracker ingest: ingest threw: ' . get_class( $t ) . ': ' . $t->getMessage() );

            $ok = false;
        }

        if ( $ok ) {

            $queue->ack( $message );

            return 'ingested';
        }

        $steps = self::BACKOFF;
        $queue->release( $message, $steps[ min( $message->receive_count, count( $steps ) ) - 1 ] );

        return 'released';
    }

    /**
     * Receive and ingest until the intake is empty or the deadline passes.
     *
     * @param  int $deadline unix time
     * @return array counts: ingested, released, dead
     */
    public static function drain( $deadline ) {

        $counts = array( 'ingested' => 0, 'released' => 0, 'dead' => 0 );
        $queue  = self::queue();

        if ( $queue->isProbablyEmpty() ) {

            return $counts;
        }

        while ( time() < $deadline ) {

            $messages = $queue->receive( self::BATCH, self::VISIBILITY );

            if ( ! $messages ) {

                break;
            }

            foreach ( $messages as $message ) {

                $counts[ self::ingestMessage( $queue, $message ) ]++;
            }
        }

        return $counts;
    }
}

?>
