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
 * Written to the strictest likely backend, AWS SQS, and every implementation
 * mirrors it: delivery is at least once, a received message is hidden from
 * other consumers rather than removed, there is no ordering guarantee, and a
 * queue has a DEAD-LETTER QUEUE of its own.
 *
 * THE QUEUE OWNS ITS RECEIVE LIMIT, as an SQS redrive policy does: a message
 * about to be received more than max_receives times is moved to the
 * dead-letter queue instead of being handed out. That is what stops a message
 * that kills its consumer, which no consumer code survives to dead-letter.
 *
 * A consumer acks what it ingested and releases what it did not; anything it
 * neither acks nor releases is delivered again. Ingest makes a redelivery
 * harmless (EventRawHandlers::alreadyIngested()).
 *
 * A message is an envelope, a JSON-encodable array -- {v, type, properties,
 * queued_at} -- never a PHP object (Classes\TrackerIngest builds and reads it).
 *
 * An implementation is registered under event_queue_types and chosen by the
 * tracker_ingest_queue_type setting. Its constructor takes the queue's
 * registration map; max_receives is in it.
 */
interface IntakeQueue {

    /**
     * Create what the queue needs -- its own storage and its dead-letter
     * queue's -- or find it already there. Idempotent.
     *
     * @return bool
     */
    public function provision();

    /**
     * Queue one envelope.
     *
     * @param  array $envelope
     * @param  int   $delay     seconds before it may be received
     * @param  bool  $replayed  sent back from the dead-letter queue, which the daily replay does once
     * @return bool  whether it was accepted
     */
    public function send( array $envelope, $delay = 0, $replayed = false );

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

    /** Given up on: moved to the dead-letter queue with $reason. */
    public function deadLetter( IntakeMessage $message, $reason );

    /**
     * This queue's dead-letter queue, itself an IntakeQueue; null for a
     * dead-letter queue, which has none.
     *
     * @return IntakeQueue|null
     */
    public function deadLetterQueue();

    /**
     * A cheap check that nothing is waiting, for a quiet minute: a stat() for
     * files, one attribute call for SQS. May answer false when the queue is in
     * fact empty; must not answer true when a message is due.
     *
     * @return bool
     */
    public function isProbablyEmpty();

    /**
     * For schedule-status: roughly how many messages it holds, and how old the
     * oldest is in seconds. Either may be null when the backend cannot say.
     *
     * @return array messages, oldest_age
     */
    public function stats();
}

?>
