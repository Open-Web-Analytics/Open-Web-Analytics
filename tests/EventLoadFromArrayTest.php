<?php

use PHPUnit\Framework\TestCase;

/**
 * What Event::loadFromArray() may set: the event's name, properties, guid and
 * time, and nothing else.
 *
 * Its caller rebuilds an event from a queued tracker-ingest envelope
 * (TrackerIngest::event()), and a queue's contents are only as trustworthy as
 * whatever can write to it -- a file under owa-data/, or an SQS queue another
 * account may send to. So the rest of the object's state, its status among
 * it, cannot be set this way, and the guid and time must be numeric.
 */
final class EventLoadFromArrayTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function event(): object
    {
        return new \OWA\Module\Base\Classes\Event();
    }

    public function testEventTypeAndPropertiesAreAccepted(): void
    {
        $e = $this->event();

        $e->loadFromArray([
            'eventType'  => 'track.action',
            'properties' => ['page_url' => 'https://example.com/x'],
        ]);

        $this->assertSame('track.action', $e->getEventType());
        $this->assertSame('https://example.com/x', $e->get('page_url'));
    }

    /** The guid makes a redelivery recognisable, and the time is when the beacon happened, not when it was drained. */
    public function testNumericGuidAndTimestampAreAccepted(): void
    {
        $e = $this->event();

        $e->loadFromArray(['guid' => '1753930000123456789', 'timestamp' => '1753930000']);

        $this->assertSame('1753930000123456789', $e->getGuid());
        $this->assertSame('1753930000', $e->timestamp);
    }

    /** @dataProvider nonNumericValues */
    public function testNonNumericGuidIsRejected($hostile): void
    {
        $e = $this->event();
        $original = $e->getGuid();

        $e->loadFromArray(['guid' => $hostile]);

        $this->assertSame($original, $e->getGuid());
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

    /** A queued message cannot declare its own event handled, or set anything else the object owns. */
    public function testTheRestOfTheEventCannotBeSet(): void
    {
        $e = $this->event();

        $e->loadFromArray(['status' => 'handled', 'dispatchName' => 'base.something_else']);

        $this->assertSame('unhandled', $e->getStatus());
        $this->assertNotSame('base.something_else', $e->getDispatchName());
    }

    public function testNonArrayInputIsIgnored(): void
    {
        $e = $this->event();
        $type = $e->getEventType();

        $e->loadFromArray('not an array');
        $e->loadFromArray(null);

        $this->assertSame($type, $e->getEventType());
    }
}
