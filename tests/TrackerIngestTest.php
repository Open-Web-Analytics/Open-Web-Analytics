<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Core\IntakeMessage;
use OWA\Core\IntakeQueue;
use OWA\Module\Base\Classes\Event;
use OWA\Module\Base\Classes\TrackerIngest;

/**
 * The tracker-ingest intake (PLAN 2.30.3, 2.30.4): the envelope, which
 * setting queues, which queue type is used, and what the drain does with each
 * message.
 *
 * The drain runs against an in-memory queue, and its ingest against a
 * processor this test registers, so no beacon is written anywhere.
 */
final class TrackerIngestTest extends TestCase
{
    private const TYPE = 'zz_intake_test';

    /** @var array<string,mixed> settings to restore */
    private array $saved = array();

    protected function setUp(): void
    {
        foreach (array('queue_tracker_ingest', 'queue_incoming_tracking_events', 'queue_events',
                       'tracker_ingest_queue_type', 'tracker_ingest_drain') as $key) {
            $this->saved[$key] = \OWA\Core\CoreAPI::getSetting('base', $key);
        }

        IntakeTestProcessor::$outcome = 'ok';
        IntakeTestProcessor::$seen    = array();

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->setMapValue('actions', 'test.intakeProcessor',
            array('class_name' => IntakeTestProcessor::class, 'file' => __FILE__));
        $s->setMapValue('event_processors', \OWA\Core\CoreAPI::trackingDispatchName(self::TYPE), 'test.intakeProcessor');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            \OWA\Core\CoreAPI::setSetting('base', $key, $value);
        }

        TrackerIngest::$queue = null;

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->setMapValue('event_processors', \OWA\Core\CoreAPI::trackingDispatchName(self::TYPE), null);
        $s->setMapValue('actions', 'test.intakeProcessor', null);
    }

    private function event(array $properties = array()): Event
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->setEventType(self::TYPE);
        $event->setProperties($properties + array('page_url' => 'https://example.test/'));

        return $event;
    }

    private function message(array $envelope = null, int $count = 1): IntakeMessage
    {
        return new IntakeMessage('r1', $envelope ?? TrackerIngest::envelope($this->event()), $count, 'raw line');
    }

    // ---------------------------------------------------------------------
    // The envelope
    // ---------------------------------------------------------------------

    public function testAnEventRoundTripsThroughItsEnvelope(): void
    {
        $sent     = $this->event(array('site_id' => 'abc'));
        $envelope = TrackerIngest::envelope($sent);

        $this->assertSame(array('v', 'type', 'properties', 'queued_at'), array_keys($envelope));
        $this->assertSame(1, $envelope['v']);
        $this->assertNotFalse(json_encode($envelope), 'it is JSON, not a PHP object');

        $back = TrackerIngest::event(json_decode(json_encode($envelope), true));

        $this->assertSame(self::TYPE, $back->getEventType());
        $this->assertSame((string) $sent->getGuid(), (string) $back->getGuid());
        $this->assertSame('abc', $back->get('site_id'));
        $this->assertSame(\OWA\Core\CoreAPI::trackingDispatchName(self::TYPE), $back->getDispatchName());
    }

    public function testAnEnvelopeThisVersionDoesNotReadIsRefused(): void
    {
        $good = TrackerIngest::envelope($this->event());

        foreach (array(
            'a later version'       => array('v' => 2) + $good,
            'no type'               => array_diff_key($good, array('type' => 1)),
            'properties not a list' => array('properties' => 'x') + $good,
        ) as $why => $envelope) {
            $this->assertNull(TrackerIngest::event($envelope), $why);
        }
    }

    /** guid and timestamp are numeric (Event::loadFromArray()); anything else is not taken from the properties. */
    public function testANonNumericGuidInTheEnvelopeIsNotTaken(): void
    {
        $envelope = TrackerIngest::envelope($this->event());
        $envelope['properties']['guid'] = '1 OR 1=1';

        $this->assertNotSame('1 OR 1=1', (string) TrackerIngest::event($envelope)->getGuid());
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    /** queue_tracker_ingest decides; unset, the 1.x names do. */
    public function testTheOldNamesAreReadOnlyWhenTheNewOneIsUnset(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'queue_events', false);
        \OWA\Core\CoreAPI::setSetting('base', 'queue_incoming_tracking_events', true);

        \OWA\Core\CoreAPI::setSetting('base', 'queue_tracker_ingest', null);
        $this->assertTrue(TrackerIngest::isQueued(), 'unset: the old name queues');

        \OWA\Core\CoreAPI::setSetting('base', 'queue_tracker_ingest', false);
        $this->assertFalse(TrackerIngest::isQueued(), 'set to false: the old name is not read');

        \OWA\Core\CoreAPI::setSetting('base', 'queue_incoming_tracking_events', false);
        \OWA\Core\CoreAPI::setSetting('base', 'queue_tracker_ingest', true);
        $this->assertTrue(TrackerIngest::isQueued());

        \OWA\Core\CoreAPI::setSetting('base', 'queue_tracker_ingest', null);
        \OWA\Core\CoreAPI::setSetting('base', 'queue_events', true);
        $this->assertTrue(TrackerIngest::isQueued(), 'OWA_QUEUE_EVENTS too');
    }

    public function testTheDefaultsAreAFileQueueDrainedByTheScheduler(): void
    {
        $settings = require dirname(__DIR__) . '/modules/Base/settings.php';

        $this->assertSame('file', $settings['settings']['tracker_ingest_queue_type']['default']);
        $this->assertSame('scheduler', $settings['settings']['tracker_ingest_drain']['default']);
        $this->assertArrayHasKey('default', $settings['settings']['queue_tracker_ingest']);
        $this->assertNull($settings['settings']['queue_tracker_ingest']['default'], 'null, so unset is distinguishable from false');
    }

    public function testTheIntakeIsTheFileQueueByDefault(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'tracker_ingest_queue_type', 'file');

        $this->assertInstanceOf(\OWA\Module\Base\Classes\FileEventQueue::class, TrackerIngest::queue());
    }

    public function testAnUnknownQueueTypeIsAnError(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'tracker_ingest_queue_type', 'zz-no-such-type');

        $this->expectExceptionMessage('no module registers that queue type');
        TrackerIngest::queue();
    }

    /** A registered type that does not meet the contract is refused, not used. */
    public function testATypeThatIsNotAnIntakeIsAnError(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'tracker_ingest_queue_type', 'database');

        $this->expectExceptionMessage('does not implement the tracking intake contract');
        TrackerIngest::queue();
    }

    // ---------------------------------------------------------------------
    // The drain, one message at a time
    // ---------------------------------------------------------------------

    public function testAnIngestedMessageIsAcked(): void
    {
        $q = new MemoryIntake();

        $this->assertSame('ingested', TrackerIngest::ingestMessage($q, $this->message()));
        $this->assertSame(array('ack'), array_column($q->calls, 0));
        $this->assertSame(array(self::TYPE), IntakeTestProcessor::$seen);
    }

    /** A handler failure is a retry after the back-off step for its receive count. */
    public function testAFailedIngestIsReleasedWithBackOff(): void
    {
        IntakeTestProcessor::$outcome = 'failed';
        $q = new MemoryIntake();

        foreach (array(1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 3600) as $count => $delay) {
            $q->calls = array();
            $this->assertSame('released', TrackerIngest::ingestMessage($q, $this->message(null, $count)));
            $this->assertSame(array(array('release', $delay)), $q->calls, "receive $count");
        }
    }

    public function testAnIngestThatThrowsIsReleased(): void
    {
        IntakeTestProcessor::$outcome = 'throw';
        $q = new MemoryIntake();

        $this->assertSame('released', TrackerIngest::ingestMessage($q, $this->message()));
    }

    public function testAMessageReceivedTooOftenIsDeadLetteredWithoutIngest(): void
    {
        $q = new MemoryIntake();

        $this->assertSame('dead', TrackerIngest::ingestMessage($q, $this->message(null, TrackerIngest::RECEIVE_LIMIT + 1)));
        $this->assertSame('dead', $q->calls[0][0]);
        $this->assertStringContainsString('Received 5 times', $q->calls[0][1]);
        $this->assertSame(array(), IntakeTestProcessor::$seen);
    }

    public function testAMessageThatIsNotAnEnvelopeIsDeadLettered(): void
    {
        $q = new MemoryIntake();

        $this->assertSame('dead', TrackerIngest::ingestMessage($q, new IntakeMessage('r', null, 1, 'O:9:"owa_event"')));
        $this->assertSame('dead', TrackerIngest::ingestMessage($q, $this->message(array('v' => 9))));
        $this->assertSame(array(), IntakeTestProcessor::$seen);
    }

    public function testTheDrainStopsWhenTheQueueIsEmpty(): void
    {
        $q = new MemoryIntake();
        $q->pending = array($this->message(), $this->message(), $this->message());
        TrackerIngest::$queue = $q;

        $this->assertSame(array('ingested' => 3, 'released' => 0, 'dead' => 0), TrackerIngest::drain(time() + 30));
    }

    public function testAQuietQueueIsNotReceivedFrom(): void
    {
        $q = new MemoryIntake();
        TrackerIngest::$queue = $q;

        TrackerIngest::drain(time() + 30);

        $this->assertSame(0, $q->receives);
    }

    // ---------------------------------------------------------------------
    // The producers
    // ---------------------------------------------------------------------

    public function testSendPutsTheEnvelopeOnTheIntake(): void
    {
        $q = new MemoryIntake();
        TrackerIngest::$queue = $q;

        $event = $this->event();
        $this->assertTrue(TrackerIngest::send($event));

        $this->assertSame('send', $q->calls[0][0]);
        $this->assertSame(TrackerIngest::envelope($event)['properties'], $q->calls[0][1]['properties']);
        $this->assertSame(0, $q->calls[0][2]);
    }

    /** The retry is the beacon as it arrived, after the first back-off step. */
    public function testARetryIsSentDelayed(): void
    {
        $q = new MemoryIntake();
        TrackerIngest::$queue = $q;

        $arrived = TrackerIngest::envelope($this->event(array('n' => 'as-arrived')));
        TrackerIngest::retryLater($this->event(), $arrived);

        $this->assertSame(array('send', $arrived, 60), $q->calls[0]);
    }

    /** notify() marks the event failed rather than queueing it anywhere. */
    public function testAHandlerFailureMarksTheEventFailed(): void
    {
        $name  = 'zz_intake_dispatch_test';
        $event = $this->event();
        $event->setEventType($name);

        $d = \OWA\Core\CoreAPI::getEventDispatch();
        $d->attach($name, static fn () => OWA_EHS_EVENT_FAILED);

        $this->assertSame(OWA_EHS_EVENT_FAILED, $d->notify($event));
        $this->assertSame(Event::failed, $event->getStatus());
    }
}

/** A processor whose outcome the test sets. */
class IntakeTestProcessor extends \OWA\Core\Controller
{
    public static string $outcome = 'ok';

    /** @var string[] event types it was handed */
    public static array $seen = array();

    public function action()
    {
        $event = $this->getParam('event');
        self::$seen[] = $event->getEventType();

        if (self::$outcome === 'throw') {
            throw new \RuntimeException('ingest broke');
        }

        if (self::$outcome === 'failed') {
            $event->setStatusAsFailed();
        }
    }
}

/** The contract, in memory, recording what the drain asked of it. */
class MemoryIntake implements IntakeQueue
{
    /** @var IntakeMessage[] */
    public array $pending = array();

    public array $calls = array();

    public int $receives = 0;

    public function send(array $envelope, $delay = 0)
    {
        $this->calls[] = array('send', $envelope, $delay);

        return true;
    }

    public function receive($max, $visibility)
    {
        $this->receives++;

        return array_splice($this->pending, 0, $max);
    }

    public function ack(IntakeMessage $message)
    {
        $this->calls[] = array('ack');

        return true;
    }

    public function release(IntakeMessage $message, $delay)
    {
        $this->calls[] = array('release', $delay);

        return true;
    }

    public function deadLetter(IntakeMessage $message, $reason)
    {
        $this->calls[] = array('dead', $reason);

        return true;
    }

    public function isProbablyEmpty()
    {
        return !$this->pending;
    }
}
