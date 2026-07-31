<?php

use PHPUnit\Framework\TestCase;

/**
 * The remote event-queue ingress: what a sender may set, and what a queue blob
 * may reconstruct.
 *
 * WHY THIS EXISTS
 * ---------------
 * queue.php is fed straight from an unauthenticated HTTP request and is
 * loadFromArray()'s ONLY caller. Two separate concerns meet there:
 *
 *   1. WHICH PROPERTIES a sender may set. Previously every property was
 *      settable, including the queue bookkeeping the receiving instance owns.
 *      sendMessage() uses the event guid as the queue item's PRIMARY KEY, and
 *      the retry machinery reads receive_count and
 *      do_not_receive_before_timestamp -- so a sender could collide with an
 *      existing queue item, park an event arbitrarily far in the future, or
 *      arrive pre-loaded with a receive count that trips the retry limits.
 *
 *   2. WHAT A QUEUE BLOB MAY INSTANTIATE. A blob is written by this instance
 *      from its own object, so HTTP input cannot name a class -- see
 *      testAHostileStringInAPropertyStaysAString, which pins that. The
 *      allowlist is for when the blob itself is no longer trustworthy: anyone
 *      who can write to owa_queue_item would otherwise have a path from "can
 *      write a row" to "can instantiate arbitrary classes on the next queue
 *      run".
 *
 * The allowlist must not be over-tightened either: pinning the concrete Event
 * class would break an install whose module substitutes its own, by decoding
 * every event into __PHP_Incomplete_Class. testALegitimateEventSurvives guards
 * that direction.
 */
final class EventQueueHardeningTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function event(): object
    {
        return new \OWA\Module\Base\Classes\Event();
    }

    private function queue(): object
    {
        return new \OWA\Core\EventQueue();
    }

    // ---- 1. which properties a remote sender may set -----------------------

    public function testEventTypeAndPropertiesAreAccepted(): void
    {
        $e = $this->event();

        $e->loadFromArray([
            'eventType'  => 'track.action',
            'properties' => ['page_url' => 'https://example.com/x'],
        ]);

        $this->assertSame('track.action', $e->getEventType());
        $this->assertSame('https://example.com/x', $e->get('page_url'),
            'the event payload is the whole point of the endpoint and must still arrive');
    }

    /**
     * The forwarder (HttpEventQueue) relays guid and timestamp, and both matter:
     * the guid is what makes a retried forward idempotent instead of duplicating
     * the queue item, and the timestamp is the real event time from upstream
     * rather than whenever the forward arrived.
     */
    public function testNumericGuidAndTimestampAreAccepted(): void
    {
        $e = $this->event();

        $e->loadFromArray(['guid' => '1753930000123456789', 'timestamp' => '1753930000']);

        $this->assertSame('1753930000123456789', $e->getGuid());
        // Asserted on the property, not get(): the constructor seeds BOTH
        // $this->timestamp and $this->properties['timestamp'], and loadFromArray
        // writes the property. A real forward also relays `properties` (export()
        // returns every var), so both carry the upstream value in practice --
        // see testARelayedEventCarriesTheUpstreamTimestampThroughProperties.
        $this->assertSame('1753930000', $e->timestamp);
    }

    /**
     * The realistic forward: HttpEventQueue sends export(), which includes the
     * `properties` array, so the upstream timestamp arrives by that route too.
     */
    public function testARelayedEventCarriesTheUpstreamTimestampThroughProperties(): void
    {
        $e = $this->event();

        $e->loadFromArray([
            'eventType'  => 'track.action',
            'timestamp'  => '1753930000',
            'properties' => ['timestamp' => '1753930000', 'page_url' => 'https://example.com/x'],
        ]);

        $this->assertSame('1753930000', $e->timestamp);
        $this->assertSame('1753930000', $e->get('timestamp'),
            'the upstream event time must survive the relay, not be replaced by arrival time');
    }

    /**
     * generateRandomUid() returns time().rand(6).serverId(3) and the guid column
     * is a BIGINT, so a non-numeric guid is not a valid identifier -- and it
     * reaches the queue item's primary key.
     *
     * @dataProvider nonNumericValues
     */
    public function testNonNumericGuidIsRejected($hostile): void
    {
        $e = $this->event();
        $original = $e->getGuid();

        $e->loadFromArray(['guid' => $hostile]);

        $this->assertSame($original, $e->getGuid(),
            'a non-numeric guid must not reach the queue item primary key');
    }

    public static function nonNumericValues(): array
    {
        return [
            'sql-ish'        => ["1' OR '1'='1"],
            'serialized'     => ['O:8:"stdClass":0:{}'],
            'array'          => [['nested' => 'x']],
            'negative'       => ['-1'],
            'float'          => ['1.5'],
            'empty string'   => [''],
            'leading spaces' => ['  123'],
        ];
    }

    /**
     * Everything the receiving instance owns. A freshly received event has to
     * start with fresh queue state -- which is what the constructor gives it.
     *
     * @dataProvider queueBookkeepingProperties
     */
    public function testQueueBookkeepingCannotBeSetByTheSender(string $property, $hostile): void
    {
        $e = $this->event();
        $before = $e->$property ?? null;

        $e->loadFromArray([$property => $hostile]);

        $this->assertSame($before, $e->$property ?? null,
            $property . ' is queue state owned by the receiver and must ignore sender input');
    }

    public static function queueBookkeepingProperties(): array
    {
        return [
            // Would let a sender park an event arbitrarily far in the future.
            'do_not_receive_before_timestamp' => ['do_not_receive_before_timestamp', 99999999999],
            // Would let a sender arrive pre-loaded against the retry limits.
            'receive_count'                   => ['receive_count', 9999],
            // Would let a sender declare its own event already handled.
            'status'                          => ['status', 'handled'],
            'last_receive_timestamp'          => ['last_receive_timestamp', 123],
            'first_receive_timestamp'         => ['first_receive_timestamp', 123],
            'last_error_msg'                  => ['last_error_msg', 'spoofed'],
            'old_queue_id'                    => ['old_queue_id', 42],
        ];
    }

    public function testNonArrayInputIsIgnored(): void
    {
        $e = $this->event();
        $type = $e->getEventType();

        $e->loadFromArray('not an array');
        $e->loadFromArray(null);

        $this->assertSame($type, $e->getEventType());
    }

    // ---- 2. what a queue blob may reconstruct ------------------------------

    public function testALegitimateEventSurvivesTheRoundTrip(): void
    {
        $q = $this->queue();
        $e = $this->event();
        $e->setEventType('track.action');
        $e->set('page_url', 'https://example.com/x');

        $back = $q->decodeMessage($q->prepareMessage($e));

        $this->assertNotInstanceOf(__PHP_Incomplete_Class::class, $back,
            'the allowlist is too tight -- real events no longer decode');
        $this->assertInstanceOf(\OWA\Module\Base\Classes\Event::class, $back);
        $this->assertSame('track.action', $back->getEventType());
        $this->assertSame($e->getGuid(), $back->getGuid());
        $this->assertSame('https://example.com/x', $back->get('page_url'));
    }

    public function testAForeignClassInAQueueBlobIsRefused(): void
    {
        $q = $this->queue();

        $decoded = $q->decodeMessage('O:8:"stdClass":1:{s:1:"x";s:3:"pwn";}');

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $decoded,
            'a tampered queue row must not instantiate an arbitrary class');
        $this->assertNotInstanceOf(\stdClass::class, $decoded);
    }

    /**
     * The claim in the original report was that HTTP input reaches unserialize()
     * as an object. It does not: superglobals hold strings and arrays, the value
     * is stored as a property of an object THIS instance constructs, and
     * serialize() length-prefixes it. It comes back as the same string.
     */
    public function testAHostileStringInAPropertyStaysAString(): void
    {
        $q = $this->queue();
        $e = $this->event();

        $hostile = 'O:8:"stdClass":1:{s:1:"x";s:3:"pwn";}';
        $e->loadFromArray(['eventType' => 'track.action', 'properties' => ['evil' => $hostile]]);

        $back = $q->decodeMessage($q->prepareMessage($e));

        $this->assertSame($hostile, $back->get('evil'));
        $this->assertIsString($back->get('evil'),
            'a serialized-looking string is data, not a nested object');
    }
}
