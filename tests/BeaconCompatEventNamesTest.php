<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Beacon\Compat;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * An older tracker's event names become current at the edge, and routing sees
 * only current names.
 *
 * conf/beacon_compat.php is the one place v1 event names are listed.
 * Compat::apply() renames the event as the first step of logEvent() and of a
 * queue drain, so the admission gate, the dispatch name (tracking.<name>) and
 * the processor router never meet an old spelling -- which is why Base can
 * register its own events by current name and nothing else.
 */
final class BeaconCompatEventNamesTest extends TestCase
{
    /** @return array v1 name => current name */
    public static function renames(): array
    {
        return [
            'page request' => ['base.page_request', 'page_view'],
            'click'        => ['dom.click', 'click'],
            'transaction'  => ['ecommerce.transaction', 'purchase'],
        ];
    }

    /**
     * @dataProvider renames
     */
    public function testAV1NameBecomesCurrent(string $old, string $current): void
    {
        $this->assertSame($current, Compat::eventName($old));
    }

    public function testACurrentNameIsLeftAlone(): void
    {
        foreach (['page_view', 'scroll', 'file_download', 'my_site_signup'] as $name) {
            $this->assertSame($name, Compat::eventName($name));
        }
    }

    /**
     * @dataProvider renames
     */
    public function testApplyRenamesTheEventItself(string $old, string $current): void
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType($old);

        Compat::apply($event);

        $this->assertSame($current, $event->getEventType());
    }

    /**
     * Every rename lands on a name Base routes by exact name -- a rename to
     * a name nothing processes would be accepted as a custom event instead.
     *
     * @dataProvider renames
     */
    public function testEveryRenameTargetIsARegisteredEvent(string $old, string $current): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the processor map is built from the booted modules');
        }

        $this->assertContains($current, owa_coreAPI::trackingEventTypes());
    }

    /**
     * v1 spellings with no rename are not event names at all: the dot fails
     * the custom-name pattern, so they are refused rather than stored.
     */
    public function testAnUnrenamedV1SpellingIsRefused(): void
    {
        foreach (['dom.stream', 'base.feed_request', 'track.action', 'dom.keypress'] as $old) {
            $this->assertSame($old, Compat::eventName($old), "$old has no rename");
            $this->assertFalse(owa_coreAPI::isTrackingEventType($old), "$old must be refused");
        }
    }

    /**
     * NO V1 NAME IS ROUTED. Base registers its own events under their current
     * dispatch names; the old spellings appear in the router nowhere.
     */
    public function testTheRouterHoldsNoV1Name(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the processor map is built from the booted modules');
        }

        $registered = owa_coreAPI::trackingEventTypes();

        foreach (array_keys(Compat::eventNames()) as $old) {
            $this->assertNotContains($old, $registered);
        }

        $this->assertContains('page_view', $registered);
        $this->assertSame('base.processRequest', owa_coreAPI::getEventProcessor('tracking.page_view'));
    }

    /**
     * A module's own type outranks the custom-event wildcard, so it is routed
     * to the module's processor and never reaches Base's.
     */
    public function testAnExactRegistrationOutranksTheWildcard(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the processor map is built from the booted modules');
        }

        $service = owa_coreAPI::serviceSingleton();
        $before  = $service->getMap('event_processors');

        $this->assertSame('base.processRequest',
            owa_coreAPI::getEventProcessor('tracking.acme_recording'),
            'an unregistered legal name is a custom event, for Base');

        $service->setMapValue('event_processors', 'tracking.acme_recording', 'acme.processRecording');

        try {
            $this->assertSame('acme.processRecording',
                owa_coreAPI::getEventProcessor('tracking.acme_recording'));
            $this->assertContains('acme_recording', owa_coreAPI::trackingEventTypes());
        } finally {
            $service->setMap('event_processors', $before);
        }
    }

    /**
     * A tracking event read back off a queue gets its current name and the
     * dispatch key that goes with it; an internal event is left alone.
     */
    public function testAQueuedTrackingEventIsRenamedWithItsDispatchKey(): void
    {
        $queued = new \OWA\Module\Base\Classes\Event();
        $queued->setEventType('base.page_request');
        $queued->setDispatchName('tracking.base.page_request');

        $this->assertSame(1, Compat::applyToQueued($queued));
        $this->assertSame('page_view', $queued->getEventType());
        $this->assertSame('tracking.page_view', $queued->getDispatchName());

        $internal = new \OWA\Module\Base\Classes\Event();
        $internal->setEventType('base.new_session');

        $this->assertSame(0, Compat::applyToQueued($internal));
        $this->assertSame('base.new_session', $internal->getDispatchName());
    }
}
