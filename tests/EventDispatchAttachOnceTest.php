<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A module's handler is attached to an event once, however many times the
 * module registers it.
 *
 * A module attaches its handlers when it is constructed. Constructing one a
 * second time -- activating it, or reading what it registers, as
 * SettingsDeclarationContractTest does -- attached every handler again, so
 * each event was handled twice for the rest of the process. In the suite that
 * made SpamScoreIngestTest count each blocked beacon twice.
 */
final class EventDispatchAttachOnceTest extends TestCase
{
    /** @var array<int,string> */
    public static $calls = [];

    private function dispatch(): object
    {
        return \OWA\Core\CoreAPI::supportClassFactory('base', 'eventDispatch');
    }

    private function event(string $type): object
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->setEventType($type);

        return $event;
    }

    protected function setUp(): void
    {
        self::$calls = [];
    }

    public function testTheSameRegistrationTwiceRunsOnce(): void
    {
        $dispatch = $this->dispatch();

        $dispatch->attach('test.once', [new AttachOnceHandler(), 'notify'], 'mod|AttachOnceHandler::notify');
        $dispatch->attach('test.once', [new AttachOnceHandler(), 'notify'], 'mod|AttachOnceHandler::notify');

        $dispatch->notify($this->event('test.once'));

        $this->assertSame(['notify'], self::$calls, 'a second registration handled the event again');
    }

    /** Another key, or another event under the same key, is its own registration. */
    public function testAnotherKeyOrEventIsItsOwnRegistration(): void
    {
        $dispatch = $this->dispatch();

        $dispatch->attach('test.once', [new AttachOnceHandler(), 'notify'], 'mod|AttachOnceHandler::notify');
        $dispatch->attach('test.once', [new AttachOnceHandler(), 'other'], 'mod|AttachOnceHandler::other');
        $dispatch->attach(['test.once', 'test.twice'], [new AttachOnceHandler(), 'notify'], 'mod|AttachOnceHandler::notify');

        $dispatch->notify($this->event('test.once'));
        $dispatch->notify($this->event('test.twice'));

        $this->assertSame(['notify', 'other', 'notify'], self::$calls);
    }

    /** Without a key every attach() is attached: two listeners of one class each hear the event. */
    public function testAnAttachWithoutAKeyIsAlwaysAttached(): void
    {
        $dispatch = $this->dispatch();

        $dispatch->attach('test.once', [new AttachOnceHandler(), 'notify']);
        $dispatch->attach('test.once', [new AttachOnceHandler(), 'notify']);

        $dispatch->notify($this->event('test.once'));

        $this->assertSame(['notify', 'notify'], self::$calls);
    }

    public function testConstructingAModuleAgainAddsNoHandler(): void
    {
        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();
        $count    = function () use ($dispatch): int {
            $p = new ReflectionProperty($dispatch, 'listenersByEventType');

            return array_sum(array_map('count', (array) $p->getValue($dispatch)));
        };

        new \OWA\Module\Base\Module();
        $before = $count();

        new \OWA\Module\Base\Module();

        $this->assertSame($before, $count(), 'a second Base module attached its handlers again');
    }
}

final class AttachOnceHandler
{
    public function notify($event)
    {
        EventDispatchAttachOnceTest::$calls[] = 'notify';

        return OWA_EHS_EVENT_HANDLED;
    }

    public function other($event)
    {
        EventDispatchAttachOnceTest::$calls[] = 'other';

        return OWA_EHS_EVENT_HANDLED;
    }
}
