<?php
namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The tracking intake's contract (PLAN 2.30.3): what the tracker-ingest queue
 * must do, whatever it runs on.
 *
 * Written to the strictest likely backend, AWS SQS: delivery is at least once,
 * a received message is hidden from other consumers rather than removed, and
 * there is no ordering guarantee. A consumer acks what it ingested and
 * releases what it did not; anything it neither acks nor releases is delivered
 * again. Ingest makes a redelivery harmless (EventRawHandlers::alreadyIngested()).
 *
 * A message is an envelope, a JSON-encodable array -- {v, type, properties,
 * queued_at} -- never a PHP object (Classes\TrackerIngest builds and reads it).
 *
 * An implementation is registered under event_queue_types and chosen by the
 * tracker_ingest_queue_type setting.
 */
interface IntakeQueue {

    /**
     * Queue one envelope.
     *
     * @param  array $envelope
     * @param  int   $delay  seconds before it may be received
     * @return bool  whether it was accepted
     */
    public function send( array $envelope, $delay = 0 );

    /**
     * Up to $max messages, each hidden from other consumers for $visibility
     * seconds or until acked or released.
     *
     * @param  int $max
     * @param  int $visibility
     * @return IntakeMessage[]  empty when nothing is due
     */
    public function receive( $max, $visibility );

    /** Ingested: remove it. */
    public function ack( IntakeMessage $message );

    /** Not ingested: deliver it again after $delay seconds, its receive count kept. */
    public function release( IntakeMessage $message, $delay );

    /** Given up on: out of the queue, kept for inspection with $reason. */
    public function deadLetter( IntakeMessage $message, $reason );

    /**
     * A cheap check that nothing is waiting, for a quiet minute: a stat() for
     * files, one attribute call for SQS. May answer false when the queue is in
     * fact empty; must not answer true when a message is due.
     *
     * @return bool
     */
    public function isProbablyEmpty();
}

?>
