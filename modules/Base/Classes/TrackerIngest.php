<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The tracker-ingest intake (PLAN 2.30.3, 2.30.4): beacons on their way into
 * ingest, through the IntakeQueue of the configured backend (backend()).
 *
 * log.php's path decides between two modes. QUEUED (queue_tracking_events,
 * and always on a collector) sends every beacon to the intake and ingests
 * nothing in the request. DIRECT ingests in the request, and a beacon whose
 * ingest failed -- a write that rolled back -- is sent to the intake to be
 * retried, in either mode.
 *
 * The drain is drain-tracker-ingest every minute, on the servers whose role
 * runs the scheduler.
 *
 * THE FILE FALLBACK. When the intake is not the file queue -- SQS -- a beacon
 * it refuses (a timeout, a missing queue, an outage) is written to the file
 * queue in async_log_dir instead, and logged as an error. forwardFallback()
 * sends those on to the intake once it answers again; drain-tracker-ingest
 * runs it before each drain, and a collector runs
 * `cmd=drain-tracker-ingest forward_only=1` from cron. A beacon sent twice
 * -- one SQS took after all, then forwarded -- is ingested once: ingest skips
 * an event whose id is already stored (EventRawHandlers::alreadyIngested()).
 */
class TrackerIngest {

    /** The intake's registered name. It was incoming_tracking_events. */
    const QUEUE = 'tracker-ingest';

    /** The envelope's format. */
    const ENVELOPE_VERSION = 1;

    /**
     * Attempts before a beacon goes to the dead-letter queue: the queue's
     * redrive limit (max_receives in its registration; maxReceiveCount on
     * SQS), and the attempt on which a failure is dead-lettered rather than
     * released.
     */
    const MAX_RECEIVES = 6;

    /**
     * Seconds before each retry, by attempt: about 7.3 hours from the first
     * attempt to the last, so a database outage shorter than that loses
     * nothing to the dead-letter queue. No step may pass 12 hours, SQS's
     * longest visibility timeout.
     */
    const BACKOFF = array( 60, 300, 900, 3600, 21600 );

    /** How long a dead letter that is not replayed stays hidden before a replay looks at it again. */
    const REPLAY_RECHECK = 3600;

    /** How many messages one receive() asks for. */
    const BATCH = 100;

    /** How long a received message stays hidden, for a backend that needs to be told. */
    const VISIBILITY = 300;

    /** @var \OWA\Core\IntakeQueue|null a queue to use instead of the configured one. TESTS ONLY. */
    public static $queue = null;

    /** @var \OWA\Core\IntakeQueue|null the file fallback to use with $queue. TESTS ONLY. */
    public static $fallback = null;

    /** @var string|null a queue type to use instead of the configured backend. TESTS ONLY. */
    public static $backend = null;

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    /**
     * Whether beacons are queued rather than ingested in the request:
     * queue_tracking_events (OWA_QUEUE_TRACKING_EVENTS), and always on a
     * collector, which has no database to write them to.
     *
     * @return bool
     */
    public static function isQueued() {

        return \OWA\Core\ServerRole::current()->queuesTrackingEvents()
            || (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'queue_tracking_events' );
    }

    /**
     * The queue type beacons wait in: the file queue, unless a queue module's
     * own constant configures another (ModuleActivation::queueBackends()),
     * such as sqs by OWA_SQS_QUEUE_URL.
     *
     * @return string
     * @throws \Exception when two are configured; boot refuses that first
     */
    public static function backend() {

        if ( self::$backend !== null ) {

            return self::$backend;
        }

        $backends = \OWA\Core\ModuleActivation::queueBackends();

        if ( count( $backends ) > 1 ) {

            throw new \Exception( sprintf( 'Two queue backends are configured (%s): set one.',
                implode( ', ', $backends ) ) );
        }

        return $backends ? (string) array_key_first( $backends ) : 'file';
    }

    /**
     * The intake: the backend's implementation, built with the intake's own
     * registration.
     *
     * @return \OWA\Core\IntakeQueue
     * @throws \Exception when the type is unknown or does not meet the contract
     */
    public static function queue() {

        static $built = array();

        if ( self::$queue ) {

            return self::$queue;
        }

        $type = self::backend();

        if ( isset( $built[ $type ] ) ) {

            return $built[ $type ];
        }

        $s              = \OWA\Core\CoreAPI::serviceSingleton();
        $map            = (array) $s->getMapValue( 'event_queues', self::QUEUE );
        $implementation = $s->getMapValue( 'event_queue_types', $type );

        if ( ! $implementation ) {

            throw new \Exception( sprintf( 'The queue backend is "%s", and no module registers that queue type.', $type ) );
        }

        $map['queue_type'] = $type;
        $queue = \OWA\Core\Lib::simpleFactory( $implementation[0], $implementation[1], $map );

        if ( ! $queue instanceof \OWA\Core\IntakeQueue ) {

            throw new \Exception( sprintf( 'Queue type "%s" (%s) does not implement the tracking intake contract.',
                $type, $implementation[0] ) );
        }

        return $built[ $type ] = $queue;
    }

    /**
     * Where a beacon the intake refuses is kept: the file queue in
     * async_log_dir, built with the intake's registration. Null when the
     * intake is the file queue itself.
     *
     * @return \OWA\Core\IntakeQueue|null
     */
    public static function fallback() {

        static $built = null;

        if ( self::$queue ) {

            return self::$fallback;
        }

        if ( self::queue() instanceof FileEventQueue ) {

            return null;
        }

        if ( ! $built ) {

            $map = (array) \OWA\Core\CoreAPI::serviceSingleton()->getMapValue( 'event_queues', self::QUEUE );
            $map['queue_type'] = 'file';
            $built = new FileEventQueue( $map );
        }

        return $built;
    }

    /**
     * Why the queue's last call failed, when it says; null otherwise.
     *
     * @return string|null
     */
    public static function queueError( \OWA\Core\IntakeQueue $queue ) {

        return method_exists( $queue, 'lastError' ) ? $queue->lastError() : null;
    }

    /**
     * Whether an envelope fits the queue: a queue may declare the largest
     * message it takes (maxMessageBytes()), and one over it is refused there.
     *
     * @return bool
     */
    public static function fits( \OWA\Core\IntakeQueue $queue, array $envelope ) {

        if ( ! method_exists( $queue, 'maxMessageBytes' ) ) {

            return true;
        }

        $body = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );

        return $body !== false && strlen( $body ) <= (int) $queue->maxMessageBytes();
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

        return self::put( self::envelope( $event ), $delay, 'a beacon' );
    }

    /**
     * A beacon whose ingest failed in the request: queue it, to retry a minute later.
     *
     * @param  Event $event
     * @param  array $envelope  the beacon as it arrived, before processing rewrote it
     * @return bool
     */
    public static function retryLater( $event, array $envelope ) {

        \OWA\Core\CoreAPI::notice( sprintf( 'Tracker ingest: %s %s was not ingested; queued to retry.',
            $event->getEventType(), $event->getGuid() ) );

        return self::put( $envelope, self::BACKOFF[0], 'a retry' );
    }

    /**
     * Send an envelope to the intake, and to the file fallback when the
     * intake refuses it.
     *
     * An envelope over the intake's message size limit is refused and
     * logged, not kept: the fallback could never forward it.
     *
     * @param  array  $envelope
     * @param  int    $delay
     * @param  string $what  for the log
     * @return bool
     */
    private static function put( array $envelope, $delay, $what ) {

        $queue = null;
        $error = null;

        try {

            $queue = self::queue();

            if ( ! self::fits( $queue, $envelope ) ) {

                \OWA\Core\CoreAPI::error( sprintf( 'Tracker ingest: %s %s is over the intake\'s message size limit '
                    . 'of %d bytes; not queued.', (string) ( $envelope['type'] ?? '' ), $what, $queue->maxMessageBytes() ) );

                return false;
            }

            if ( $queue->send( $envelope, $delay ) ) {

                return true;
            }

            $error = self::queueError( $queue ) ?: 'refused';

        } catch ( \Throwable $t ) {

            $error = $t->getMessage();
        }

        $fallback = $queue && ! $queue instanceof FileEventQueue ? self::fallback() : null;

        if ( $fallback && $fallback->send( $envelope, $delay ) ) {

            \OWA\Core\CoreAPI::error( sprintf( 'Tracker ingest: the intake did not take %s (%s); kept in the file '
                . 'queue until it can be forwarded.', $what, $error ) );

            return true;
        }

        \OWA\Core\CoreAPI::error( sprintf( 'Tracker ingest: could not queue %s: %s', $what, $error ) );

        return false;
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

        /*
         * The site is checked here as well as at the edge: a beacon queued while
         * the database could not answer was let through unchecked, and a site
         * archived since it was queued no longer observes.
         */
        $registered = \OWA\Core\CoreAPI::siteRegistration( $event->getSiteId() );

        if ( $registered === null ) {

            return false;
        }

        if ( $registered === false ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Tracker ingest: site id "%s" is not registered; event dropped.',
                (string) $event->getSiteId() ) );

            return true;
        }

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
     * or dead-letter it. The queue itself moves a message past its receive
     * limit to the dead-letter queue before a consumer sees it -- the case of
     * a message that kills its consumer, which no code here survives.
     *
     * What an external consumer calls per message, with the same queue.
     *
     * @return string ingested, released, dead
     */
    public static function ingestMessage( \OWA\Core\IntakeQueue $queue, \OWA\Core\IntakeMessage $message ) {

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

        // The last attempt: waiting out another step would only delay the dead letter.
        if ( $message->receive_count >= self::MAX_RECEIVES ) {

            $queue->deadLetter( $message, sprintf( 'Not ingested in %d attempts.', $message->receive_count ) );

            return 'dead';
        }

        $steps = self::BACKOFF;
        $queue->release( $message, $steps[ min( $message->receive_count, count( $steps ) ) - 1 ] );

        return 'released';
    }

    /**
     * Send what the file fallback holds on to the intake, until it is empty,
     * the deadline passes, or the intake refuses one.
     *
     * Nothing is received from the fallback until the intake answers, so an
     * outage does not spend the fallback's receive limit. A message refused
     * on the way is released to wait a minute; one over the intake's size
     * limit, or not an envelope, goes to the fallback's dead-letter queue
     * (dead-letter/ in async_log_dir).
     *
     * @param  int $deadline unix time
     * @return array counts: forwarded, waiting (still in the fallback), dead
     */
    public static function forwardFallback( $deadline ) {

        $counts   = array( 'forwarded' => 0, 'waiting' => 0, 'dead' => 0 );
        $fallback = self::fallback();

        if ( ! $fallback || $fallback->isProbablyEmpty() ) {

            return $counts;
        }

        $main = self::queue();

        if ( ( $main->stats()['messages'] ?? null ) === null ) {

            $counts['waiting'] = (int) ( $fallback->stats()['messages'] ?? 0 );

            return $counts;
        }

        while ( time() < $deadline ) {

            $messages = $fallback->receive( self::BATCH, self::VISIBILITY );

            if ( ! $messages ) {

                break;
            }

            foreach ( $messages as $i => $message ) {

                if ( $message->envelope === null || ! self::fits( $main, $message->envelope ) ) {

                    $fallback->deadLetter( $message, $message->envelope === null
                        ? 'Not a tracker-ingest envelope this version reads.'
                        : 'Over the intake\'s message size limit.' );
                    $counts['dead']++;

                } elseif ( $main->send( $message->envelope, 0, $message->replayed ) ) {

                    $fallback->ack( $message );
                    $counts['forwarded']++;

                } else {

                    // The intake stopped answering: this and the rest of the batch wait.
                    foreach ( array_slice( $messages, $i ) as $rest ) {

                        $fallback->release( $rest, self::BACKOFF[0] );
                        $counts['waiting']++;
                    }

                    return $counts;
                }
            }
        }

        return $counts;
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

        // So queueError() afterwards speaks for this drain.
        if ( method_exists( $queue, 'clearLastError' ) ) {

            $queue->clearLastError();
        }

        if ( $queue->isProbablyEmpty() ) {

            return $counts;
        }

        // Blocked beacons are counted in memory and written once per key at the end (Spam\Blocks).
        $batching = ! \OWA\Module\Base\Classes\Spam\Blocks::batching();

        if ( $batching ) {

            \OWA\Module\Base\Classes\Spam\Blocks::begin();
        }

        try {

            while ( time() < $deadline ) {

                $messages = $queue->receive( self::BATCH, self::VISIBILITY );

                if ( ! $messages ) {

                    break;
                }

                foreach ( $messages as $message ) {

                    $counts[ self::ingestMessage( $queue, $message ) ]++;
                }
            }

        } finally {

            if ( $batching ) {

                \OWA\Module\Base\Classes\Spam\Blocks::flush();
            }
        }

        return $counts;
    }

    /**
     * Send dead letters back to the main queue, with their attempts reset.
     *
     * $once_only is the daily replay-tracker-ingest job: a beacon that died
     * in an outage longer than the back-off gets one more round, and one that
     * has been replayed already stays, so a beacon that fails for good is not
     * sent round forever. By hand (cmd=tracker-ingest-replay) everything is
     * replayed, once its cause is fixed. On SQS a dead letter not replayed
     * within the queue's retention, fourteen days, is deleted by SQS; the
     * file queue keeps one until it is replayed.
     *
     * A dead letter that never decoded is never replayed: it would only come
     * back. What is not replayed is released for REPLAY_RECHECK seconds, so
     * this run moves past it.
     *
     * A dead letter whose event is older than raw_retention_months is deleted,
     * either way: it holds an event the window says is not kept. With the
     * setting unset every dead letter stays.
     *
     * @param  bool $once_only
     * @param  int  $deadline  unix time
     * @return array counts: replayed, kept (replayed before), unreadable, expired
     */
    public static function replay( $once_only, $deadline ) {

        $counts    = array( 'replayed' => 0, 'kept' => 0, 'unreadable' => 0, 'expired' => 0 );
        $main      = self::queue();
        $dlq       = $main->deadLetterQueue();

        foreach ( array( $main, $dlq ) as $q ) {

            if ( $q && method_exists( $q, 'clearLastError' ) ) {

                $q->clearLastError();
            }
        }

        if ( ! $dlq || $dlq->isProbablyEmpty() ) {

            return $counts;
        }

        $keep_from = Retention::rawCutoffTimestamp();

        while ( time() < $deadline ) {

            $messages = $dlq->receive( self::BATCH, self::VISIBILITY );

            if ( ! $messages ) {

                break;
            }

            foreach ( $messages as $message ) {

                $at = self::eventTime( $message );

                if ( $keep_from !== null && $at !== null && $at < $keep_from ) {

                    $dlq->ack( $message );
                    $counts['expired']++;

                } elseif ( $message->envelope === null ) {

                    $dlq->release( $message, self::REPLAY_RECHECK );
                    $counts['unreadable']++;

                } elseif ( $once_only && $message->replayed ) {

                    $dlq->release( $message, self::REPLAY_RECHECK );
                    $counts['kept']++;

                } elseif ( $main->send( $message->envelope, 0, true ) ) {

                    $dlq->ack( $message );
                    $counts['replayed']++;
                }
            }
        }

        return $counts;
    }

    /**
     * When a message's event happened, in unix seconds: its edge receipt (ts,
     * microseconds), else when it was queued, else when it was dead-lettered;
     * null when none is known.
     *
     * @return int|null
     */
    public static function eventTime( \OWA\Core\IntakeMessage $message ) {

        $envelope = (array) $message->envelope;
        $ts       = $envelope['properties']['ts'] ?? null;

        if ( is_numeric( $ts ) && $ts > 0 ) {

            return (int) floor( $ts / 1000000 );
        }

        if ( isset( $envelope['queued_at'] ) && is_numeric( $envelope['queued_at'] ) && $envelope['queued_at'] > 0 ) {

            return (int) $envelope['queued_at'];
        }

        return $message->dead_at;
    }
}

?>
