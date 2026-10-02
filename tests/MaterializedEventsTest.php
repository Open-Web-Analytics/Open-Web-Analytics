<?php

require_once __DIR__ . '/IngestionTestCase.php';

use OWA\Module\Base\Classes\Ingest;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * session_start and first_visit are materialized by callbacks on
 * Ingest::TRACKING_EVENTS_PRE_SAVE, from the registry, and saved with the event
 * that carried their flags in one transaction.
 */
final class MaterializedEventsTest extends IngestionTestCase
{
    /** @var string */
    private $site;

    /** When set, the probe callback appends an event whose insert must fail. */
    public static $appendUnwritable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = md5('owa-test-site');
        $this->ensureSiteRegistered($this->site);
    }

    protected function tearDown(): void
    {
        self::$appendUnwritable = false;

        parent::tearDown();
    }

    /* ---------------- the declaration ---------------- */

    /** The config declares exactly the two, and they are first-class names. */
    public function testTheConfigDeclaresTheMaterializedEvents(): void
    {
        $this->assertSame(['first_visit', 'session_start'], Helpers::materializedEventNames());

        foreach (Helpers::materializedEventNames() as $name) {
            $this->assertContains($name, Helpers::eventNames());
        }
    }

    /**
     * Every materialized event in the config has a callback that produces it.
     * A declared one with no callback would never appear, and nothing would say so.
     */
    public function testEveryMaterializedEventHasACallback(): void
    {
        $events = Ingest::at(Ingest::TRACKING_EVENTS_PRE_SAVE, [$this->carrier()]);

        $produced = array_map(fn($e) => $e->getEventType(), array_slice($events, 1));
        sort($produced);

        $this->assertSame(Helpers::materializedEventNames(), $produced);
    }

    /**
     * `"materialize": false` is only meaningful on a property a materialized
     * event would otherwise carry, and only as false.
     */
    public function testTheMaterializeKeyIsOnlyUsedWhereItHasAnEffect(): void
    {
        $registry = json_decode((string) file_get_contents(
            OWA_DIR . 'modules/Base/config/tracking_properties.json'), true);

        $flagged = [];

        foreach ($registry as $name => $definition) {

            if (! is_array($definition) || ! array_key_exists('materialize', $definition)) {
                continue;
            }

            $flagged[] = $name;

            $this->assertFalse($definition['materialize'],
                "$name: materialize is an opt-out; only false means anything");

            $events = (array) ($definition['events'] ?? []);

            $this->assertTrue(in_array('*', $events, true)
                || array_intersect($events, Helpers::materializedEventNames()),
                "$name: no materialized event would carry it, so the flag does nothing");
        }

        sort($flagged);

        $this->assertSame(['engagement_msec', 'is_goal_event'], $flagged);
    }

    /** The vocabulary of a materialized event leaves the flagged properties out. */
    public function testAMaterializedEventsVocabularyLeavesOutWhatIsNotMaterialized(): void
    {
        foreach (Helpers::materializedEventNames() as $name) {

            $properties = Helpers::propertiesForEvent($name);

            $this->assertNotContains('engagement_msec', $properties, $name);
            $this->assertNotContains('is_goal_event', $properties, $name);
            $this->assertContains('page_location', $properties, $name);
        }

        // Every other event still carries them.
        $this->assertContains('engagement_msec', Helpers::propertiesForEvent('click'));
        $this->assertContains('engagement_msec', Helpers::propertiesForEvent('my_site_signup'));
        $this->assertContains('is_goal_event', Helpers::propertiesForEvent('page_view'));
    }

    /* ---------------- building one ---------------- */

    /** materialize() copies what the registry declares for the name, and nothing else. */
    public function testMaterializeCopiesOnlyWhatIsDeclaredForTheName(): void
    {
        $carrier = $this->carrier([
            'target_url'      => 'https://elsewhere.example/x',
            'scroll_depth'    => 90,
            'engagement_msec' => 4000,
            'is_goal_event'   => 1,
        ]);

        $made = Helpers::materialize($carrier, 'first_visit');

        $this->assertSame('first_visit', $made->getEventType());
        $this->assertSame('first_visit', $made->get('event_type'));
        $this->assertSame($carrier->get('page_location'), $made->get('page_location'));
        $this->assertSame($carrier->get('ts'), $made->get('ts'));

        foreach (['target_url', 'scroll_depth', 'engagement_msec', 'is_goal_event'] as $p) {
            $this->assertFalse($made->get($p), "first_visit copied $p");
        }
    }

    /**
     * THE SAME SET EVERY TIME. A failed write is retried with the incoming event
     * and the filter runs again; the retry's idempotence check reads the first
     * row's id, so the rows have to derive the same ids.
     */
    public function testTheFilterIsDeterministicSoARetryDerivesTheSameIds(): void
    {
        $properties = $this->carrier()->getProperties();

        $ids = function () use ($properties) {

            $event = new \OWA\Module\Base\Classes\Event();
            $event->setEventType('page_view');
            $event->setProperties($properties);

            return array_map(
                fn($e) => \OWA\Module\Base\Handler\EventRawHandlers::rowFor($e)['id'],
                Ingest::at(Ingest::TRACKING_EVENTS_PRE_SAVE, [$event]));
        };

        $first = $ids();

        $this->assertCount(3, $first);
        $this->assertCount(3, array_unique($first), 'each event has its own id');
        $this->assertSame($first, $ids());
    }

    /* ---------------- saving ---------------- */

    /**
     * ALL OR NOTHING. An appended event whose insert fails takes the incoming
     * event and the materialized ones with it.
     */
    public function testAFailedInsertOfAnAppendedEventRollsBackTheWholeSet(): void
    {
        \OWA\Core\CoreAPI::registerFilter(Ingest::TRACKING_EVENTS_PRE_SAVE,
            [self::class, 'probeAppendUnwritable'], 50);

        self::$appendUnwritable = true;

        // The retry goes to the tracker-ingest intake: a scratch one, so no drain sees it.
        $dir = sys_get_temp_dir() . '/owa-rollback-intake-' . bin2hex(random_bytes(4)) . '/';
        \OWA\Module\Base\Classes\TrackerIngest::$queue =
            new \OWA\Module\Base\Classes\FileEventQueue(['path' => $dir]);

        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('base.page_request', [
            'site_id'                => $this->site,
            'visitor_id'             => $visitor,
            'session_id'             => $session,
            'page_url'               => 'https://owa-test-site/v2/rollback',
            'page_location'          => 'https://owa-test-site/v2/rollback',
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
            'fsts'                   => time(),
            'sts'                    => time(),
            'num_prior_sessions'     => 0,
        ]);

        self::$appendUnwritable = false;

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ((array) $db->get_results(sprintf(
            "SELECT id FROM owa_event_raw WHERE site_id = '%s' AND visitor_id = %d AND session_id = %d",
            $this->site, (int) $visitor, (int) $session)) as $row) {
            $this->trackForCleanup('base.event_raw', (string) ((array) $row)['id'], 'id');
        }

        $rows = (array) $db->get_results(sprintf(
            "SELECT event_type FROM owa_event_raw WHERE site_id = '%s' AND visitor_id = %d AND session_id = %d",
            $this->site, (int) $visitor, (int) $session));

        /*
         * The failed write is sent to the intake to be retried, as the beacon
         * arrived (PLAN 2.30.3): one message, delayed by the first back-off step.
         */
        $intake = \OWA\Module\Base\Classes\TrackerIngest::$queue;
        \OWA\Module\Base\Classes\TrackerIngest::$queue = null;

        $queued = array_merge(...array_map(
            fn ($f) => array_map(fn ($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES)),
            glob($dir . 'delayed/*.txt') ?: [[]]));
        $depth = $intake->depth();

        unset($intake);
        exec('rm -rf ' . escapeshellarg($dir));

        $this->assertSame([], $rows,
            'part of the set was written although one of its inserts failed');

        $this->assertSame(1, $depth, 'a failed write is queued once to be retried');
        $this->assertCount(1, $queued, 'and waits out the back-off rather than retrying at once');
        $this->assertSame((string) $visitor, (string) $queued[0]['e']['properties']['visitor_id'],
            'the retry is the incoming event');
    }

    /**
     * Probe callback: appends a second session_start, which derives the same id
     * as the one the materializer appended at priority 10 -- so its insert fails
     * on the primary key, after the others in the set have been inserted. Inert
     * unless the flag is set, since a filter cannot be detached and would
     * otherwise reach every later test.
     */
    public static function probeAppendUnwritable($events)
    {
        if (! self::$appendUnwritable || ! is_array($events) || ! $events) {
            return $events;
        }

        $events[] = Helpers::materialize(reset($events), 'session_start');

        return $events;
    }

    /* ---------------- helpers ---------------- */

    /** An incoming page_view carrying both flags. */
    private function carrier(array $extra = [])
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('page_view');
        $event->setProperties($extra + [
            'site_id'                => $this->site,
            'visitor_id'             => $this->uniqueGuid(),
            'session_id'             => $this->uniqueSessionId(),
            'ts'                     => time() * 1000000,
            'yyyymmdd'               => (int) date('Ymd'),
            'page_location'          => 'https://owa-test-site/v2/landing',
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
        ]);

        return $event;
    }
}
