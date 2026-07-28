<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Behavior tests for the database event queue and the processEventQueue drain.
 *
 * OWA can defer event handling by writing events to a queue and processing them
 * later (owa_coreAPI::logEvent -> sendMessage, drained by
 * owa_processEventQueueController). The `processing` db queue is also where the
 * event dispatcher parks any event a handler FAILED on, for a later retry
 * (owa_eventDispatch::notify re-queues on OWA_EHS_EVENT_FAILED).
 *
 * These tests lock the enqueue -> receive -> handled/retry/broken contract of
 * owa_dbEventQueue and the retry-exhaustion caps the drain enforces, so the
 * class of "events pile up in owa_queue_item forever" bug this suite was written
 * for cannot silently return. They were prompted by a live incident where
 * unregistered-site base.new_session events re-queued indefinitely.
 *
 * Cleanup: every queue_item row a test creates is tracked and deleted in
 * tearDown regardless of assertion outcome, so residue is bounded to the rows
 * the test itself created. Tests skip cleanly when the OWA DB is unreachable.
 */
final class EventQueueProcessingTest extends TestCase
{
    /** @var array<int, string> queue_item ids to delete in tearDown */
    private $cleanup = [];

    /** @var array<int, array{0:string,1:mixed}> [module,name] settings to restore */
    private $settingsToRestore = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping event-queue test.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $id) {
            try {
                owa_coreAPI::entityFactory('base.queue_item')->delete($id, 'id');
            } catch (\Throwable $ex) {
                // best-effort
            }
        }
        $this->cleanup = [];

        // Restore any settings a test overrode (in-memory only; setSetting with
        // persist=false does not touch the stored config).
        foreach ($this->settingsToRestore as [$module, $name, $value]) {
            owa_coreAPI::setSetting($module, $name, $value);
        }
        $this->settingsToRestore = [];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function queue(): owa_dbEventQueue
    {
        // The 'processing' named queue is the database-backed queue. connect()
        // wires up its db handle (the drain does this before receiving); it is
        // idempotent, so calling it per test is safe.
        $q = owa_coreAPI::getEventQueue('processing');
        $q->connect();
        return $q;
    }

    /**
     * Build a synthetic event with a unique numeric guid (queue_item.id is
     * BIGINT) and register that guid for cleanup.
     */
    private function makeEvent(string $type, array $props = []): object
    {
        $event = owa_coreAPI::supportClassFactory('base', 'event');
        $event->setEventType($type);
        $event->setProperties($props);
        $this->cleanup[] = $event->getGuid();
        return $event;
    }

    private function overrideSetting(string $module, string $name, $value): void
    {
        $this->settingsToRestore[] = [$module, $name, owa_coreAPI::getSetting($module, $name)];
        owa_coreAPI::setSetting($module, $name, $value);
    }

    private function loadItem(string $id): object
    {
        $qi = owa_coreAPI::entityFactory('base.queue_item');
        $qi->load($id, 'id');
        return $qi;
    }

    // ---------------------------------------------------------------------
    // Enqueue / receive contract
    // ---------------------------------------------------------------------

    public function testSendMessagePersistsUnhandledItem(): void
    {
        $q = $this->queue();
        $event = $this->makeEvent('base.new_session', ['site_id' => 'queue-test']);

        $q->sendMessage($event);

        $qi = $this->loadItem($event->getGuid());
        $this->assertTrue($qi->wasPersisted(), 'sendMessage should persist a queue_item row.');
        $this->assertSame('unhandled', $qi->get('status'),
            'A newly queued event should be unhandled.');
        $this->assertSame('base.new_session', $qi->get('event_type'));
    }

    public function testGetNextItemsReturnsUnhandledItem(): void
    {
        $q = $this->queue();
        $event = $this->makeEvent('base.new_session', ['site_id' => 'queue-test']);
        $q->sendMessage($event);

        // receiveMessage decodes and returns the next due unhandled event.
        $received = $q->receiveMessage();
        $this->assertNotNull($received, 'receiveMessage should return a due unhandled event.');
    }

    public function testReceiveMessageCountsOneAttemptPerReceive(): void
    {
        // Regression: receiveMessage() called wasReceived() twice, double-counting
        // every attempt and throwing off the retry caps. One receive = one attempt.
        $q = $this->queue();
        $event = $this->makeEvent('base.new_session', ['site_id' => 'queue-test']);
        $q->sendMessage($event);

        $received = $q->receiveMessage();
        $this->assertSame(1, $received->getReceiveCount(),
            'A single receiveMessage() must increment the receive count by exactly one.');
    }

    public function testMarkAsHandledRemovesItemFromWorkingSet(): void
    {
        $q = $this->queue();
        $event = $this->makeEvent('base.new_session', ['site_id' => 'queue-test']);
        $q->sendMessage($event);

        $q->markAsHandled($event->getGuid());

        $qi = $this->loadItem($event->getGuid());
        $this->assertSame('handled', $qi->get('status'));
        $this->assertNotEmpty($qi->get('handled_timestamp'),
            'A handled item should record when it was handled.');
    }

    // ---------------------------------------------------------------------
    // Retry-exhaustion caps (the poison-pill guard)
    // ---------------------------------------------------------------------

    public function testMarkAsBrokenRetainsRowButLeavesWorkingSet(): void
    {
        $q = $this->queue();
        $event = $this->makeEvent('base.new_session', ['site_id' => 'queue-test']);
        $q->sendMessage($event);

        $q->markAsBroken($event->getGuid(), 'Retries exhausted.');

        $qi = $this->loadItem($event->getGuid());
        $this->assertTrue($qi->wasPersisted(),
            'A broken item is retained for inspection, not deleted.');
        $this->assertSame('broken', $qi->get('status'));
        $this->assertSame('Retries exhausted.', $qi->get('last_error_msg'));

        // ...and it is no longer returned as a due unhandled item.
        $due = $q->receiveMessage();
        if ($due) {
            $this->assertNotSame($event->getGuid(), (string) $due->getQueueGuid(),
                'A broken item must not be returned to the working set.');
        }
        $this->assertTrue(true);
    }

    public function testHasExhaustedRetriesTripsOnAttemptCount(): void
    {
        $this->overrideSetting('base', 'queue_max_retry_count', 3);
        $this->overrideSetting('base', 'queue_max_retry_age', 0); // disable age cap
        $q = $this->queue();

        $event = $this->makeEvent('base.new_session', ['site_id' => 'queue-test']);
        $q->sendMessage($event);

        // Simulate having been received up to and past the cap.
        $event->receive_count = 3;
        $this->assertFalse($q->hasExhaustedRetries($event),
            'At exactly the attempt cap the event should still get its final try.');

        $event->receive_count = 4;
        $this->assertTrue($q->hasExhaustedRetries($event),
            'Past the attempt cap the event should be considered exhausted.');
    }

    public function testHasExhaustedRetriesTripsOnAge(): void
    {
        $this->overrideSetting('base', 'queue_max_retry_count', 0); // disable count cap
        $this->overrideSetting('base', 'queue_max_retry_age', 3600); // 1h
        $q = $this->queue();

        $event = $this->makeEvent('base.new_session', ['site_id' => 'queue-test']);
        $q->sendMessage($event);

        // Backdate the persisted row's insertion timestamp beyond the age cap.
        $qi = $this->loadItem($event->getGuid());
        $qi->set('insertion_timestamp', time() - 7200); // 2h ago
        $qi->save();

        $this->assertTrue($q->hasExhaustedRetries($event),
            'An event older than the age cap should be considered exhausted.');
    }

    public function testHasExhaustedRetriesRespectsDisabledCaps(): void
    {
        $this->overrideSetting('base', 'queue_max_retry_count', 0);
        $this->overrideSetting('base', 'queue_max_retry_age', 0);
        $q = $this->queue();

        $event = $this->makeEvent('base.new_session', ['site_id' => 'queue-test']);
        $q->sendMessage($event);
        $event->receive_count = 9999;

        $this->assertFalse($q->hasExhaustedRetries($event),
            'With both caps disabled an event should never be considered exhausted.');
    }

    // ---------------------------------------------------------------------
    // Poison-pill source: the new-session notify handler must not FAIL (and so
    // must not cause a re-queue) for an event whose site is not registered.
    // This is the root cause the live queue-accumulation incident traced to.
    // ---------------------------------------------------------------------

    public function testNotifyHandlerDoesNotFailForUnregisteredSite(): void
    {
        require_once OWA_BASE_MODULE_DIR . 'handlers/notifyHandlers.php';
        $handler = new owa_notifyHandlers();

        // A site_id that is guaranteed not to resolve to a persisted site.
        $event = $this->makeEvent('base.new_session', [
            'site_id' => 'unregistered-' . md5(uniqid('owaq', true)),
        ]);

        $ret = $handler->notify($event);

        // Returning OWA_EHS_EVENT_FAILED here is what previously made
        // owa_eventDispatch::notify re-queue the event forever (an unregistered
        // site is a permanent condition, so it never drained). It must report
        // handled instead.
        $this->assertSame(OWA_EHS_EVENT_HANDLED, $ret,
            'A new_session for an unregistered site must be handled, not FAILED '
            . '(FAILED re-queues the event and it accumulates in owa_queue_item forever).');
    }

    public function testNotifyHandlerHandlesEventWithNoSiteId(): void
    {
        require_once OWA_BASE_MODULE_DIR . 'handlers/notifyHandlers.php';
        $handler = new owa_notifyHandlers();

        $event = $this->makeEvent('base.new_session', []); // no site_id

        $this->assertSame(OWA_EHS_EVENT_HANDLED, $handler->notify($event),
            'A new_session with no site_id has nobody to notify and must be handled.');
    }
}
