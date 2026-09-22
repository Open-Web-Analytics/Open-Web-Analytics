<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * v2 ingest, end to end: one beacon in, its rows out of owa_event_raw.
 *
 * Fires through the real pipeline -- logEvent -> base.processRequest ->
 * eventDispatch -> Handler\EventRawHandlers -> entity->create() -- so these
 * exercise the property callbacks, the campaign parse and the storage rules
 * together, which is where the first round of defects actually lived.
 */
final class EventRawIngestionTest extends IngestionTestCase
{
    /** @var string */
    private $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = md5('owa-test-site');
        $this->ensureSiteRegistered($this->site);

    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Fire a page view and hand back its raw rows, keyed by event_type.
     */
    /**
     * Fire a first-session page view for a given visitor and return whether the
     * acquisition write reported success.
     *
     * Drives the real handler rather than calling the protected method, so the
     * path under test is the one ingest actually takes.
     */
    private function callWriteAcquisition($visitor): bool
    {
        $this->firePageView([
            'visitor_id' => $visitor,
            'session_id' => $this->uniqueSessionId(),
        ]);

        $check = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $check->load($visitor, 'visitor_id');

        return $check->wasPersisted();
    }

    private function firePageView(array $override = []): array
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $props = $override + [
            'site_id'    => $this->site,
            'visitor_id' => $visitor,
            'session_id' => $session,
            'page_url'   => 'https://owa-test-site/v2/a?owa_campaign=spring&keep=me',
            'page_location' => 'https://owa-test-site/v2/a?owa_campaign=spring&keep=me',
            'landing_url'   => 'https://owa-test-site/v2/a?owa_campaign=spring&keep=me',
            'page_title'    => 'V2 A',
            'HTTP_REFERER'  => 'https://www.example.net/x',
            'is_new_session'         => true,
            'is_new_session_start'   => true,
            'is_new_visitor'         => true,
            'is_new_visitor_created' => true,
            'fsts' => time(),
            'sts'  => time(),
            'nps'  => 0,
            'num_prior_sessions' => 0,
        ];

        $this->fireEvent('base.page_request', $props);

        return $this->rowsFor($props['site_id'], $props['visitor_id'], $props['session_id']);
    }

    /**
     * Read back straight through the driver: the rows are keyed by a derived
     * id this test does not want to re-derive, which would only prove the test
     * and the handler share a bug.
     */
    private function rowsFor(string $site, string $visitor, string $session): array
    {
        // site_id is an md5 and the two ids are BIGINTs, all asserted here
        // rather than escaped: this is a fixture reading back what it wrote,
        // and a value that is not those shapes is a broken test, not input.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $site);

        $db   = owa_coreAPI::dbSingleton();
        $rows = (array) $db->get_results(sprintf(
            "SELECT * FROM owa_event_raw WHERE site_id = '%s' AND visitor_id = %d AND session_id = %d",
            $site, (int) $visitor, (int) $session));

        $byType = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $byType[$row['event_type']] = $row;
            $this->trackForCleanup('base.event_raw', (string) $row['id'], 'id');
        }

        $this->trackForCleanup('base.visitor_acquisition', $visitor, 'visitor_id');

        return $byType;
    }

    /**
     * One beacon carrying both flags becomes three rows, written together.
     *
     * The client sends one page_view; the server raises session_start and
     * first_visit from flags already on that beacon. Both are pure functions of
     * ONE beacon, which is the test for whether a derivation belongs at ingest
     * or in the pass.
     */
    public function testABeaconExpandsIntoItsMarkers(): void
    {
        $rows = $this->firePageView();

        $this->assertSame(['page_view', 'session_start', 'first_visit'],
            array_values(array_intersect(
                ['page_view', 'session_start', 'first_visit'], array_keys($rows))),
            'A first page view of a new visitor is three rows.');

        $ids = array_column($rows, 'id');
        $this->assertCount(3, array_unique($ids), 'Each row gets its own derived id.');

        foreach ($rows as $type => $row) {
            $this->assertSame($rows['page_view']['ts'], $row['ts'],
                'Every row of one beacon shares the instant the beacon arrived.');
        }
    }

    /**
     * The markers key off the REQUEST-scoped flags. is_new_session and
     * is_new_visitor are page- and session-scoped: they ride every event of a
     * page or a session, so raising a marker from them would raise one per
     * event rather than one per session.
     */
    public function testNoMarkersWithoutTheRequestScopedFlags(): void
    {
        $rows = $this->firePageView([
            'is_new_session_start'   => false,
            'is_new_visitor_created' => false,
        ]);

        $this->assertArrayHasKey('page_view', $rows);
        $this->assertArrayNotHasKey('session_start', $rows);
        $this->assertArrayNotHasKey('first_visit', $rows);
    }

    /**
     * page_location keeps the whole URL. page_url has had the campaign params
     * and the site's query_string_filters stripped from it by the time a
     * handler sees it -- right for v1's document identity, and useless as the
     * evidence the tags are parsed back out of.
     */
    public function testRawKeepsTheCompleteUrlAndItsReadings(): void
    {
        $row = $this->firePageView()['page_view'];

        $this->assertStringContainsString('owa_campaign=spring', $row['page_location'],
            'The campaign parameter is the evidence; stripping it makes the answer unreproducible.');

        $this->assertSame('/v2/a', $row['page_path'], 'No scheme, host or query.');
        $this->assertStringContainsString('keep=me', $row['page_query']);
        $this->assertSame('owa-test-site', $row['host']);
    }

    /**
     * Ingest transcribes what the URL claimed and stops. The reading over it --
     * organic search, social, referral, direct -- is a table that gets
     * corrected, so it belongs on the denormalised row where a fix can be
     * re-applied.
     */
    public function testTaggedValuesAreTranscribedAndNotClassified(): void
    {
        $row = $this->firePageView()['page_view'];

        $this->assertSame('spring', $row['tagged_campaign']);
        $this->assertNull($row['tagged_medium'],
            'The URL carried no medium, so nothing is written -- not a guess from the referrer.');

        $this->assertArrayNotHasKey('source', $row);
        $this->assertArrayNotHasKey('medium', $row);
    }

    /**
     * The regression this found: applyStorageDefault() looks the sentinel up by
     * PROPERTY NAME across every registered map, with no notion of which entity
     * is being written -- so language, host and page_title arrived in a v2 row
     * holding the literal '(not set)'.
     */
    public function testAbsenceIsStoredAsNull(): void
    {
        $row = $this->firePageView(['language' => ''])['page_view'];

        foreach (['language', 'user_id', 'content_group', 'consent_state',
                  'element_path', 'currency', 'target_url'] as $column) {
            $this->assertNull($row[$column],
                "$column was absent, and absence is NULL -- never '(not set)' and never ''.");
        }
    }

    /**
     * A numeric column that cannot store NULL has no way to say "absent",
     * because 0 is a real number. A beacon carrying no client clock is not a
     * beacon reporting zero skew.
     */
    public function testAnAbsentNumberIsNullAndNotZero(): void
    {
        $row = $this->firePageView()['page_view'];

        $this->assertNull($row['clock_offset_usec']);
        $this->assertNull($row['engagement_msec']);
        $this->assertNull($row['scroll_depth']);
    }

    public function testClockSkewIsRecordedWhenTheBeaconCarriesAClientClock(): void
    {
        $rows = $this->firePageView(['client_ts_usec' => 1000000]);

        $this->assertGreaterThan(0, (int) $rows['page_view']['clock_offset_usec'],
            'Server receipt minus a 1970 client clock is a large positive offset.');
    }

    /**
     * params holds only what cannot be a column, and only what the event type
     * actually carries. A flat list of declared names collected numeric_value
     * -- registered with a default of 0 -- onto every page view.
     */
    public function testParamsCarryNothingTheEventDoesNotHave(): void
    {
        $this->assertNull($this->firePageView()['page_view']['params']);
    }

    public function testCustomVariablesAreCarriedByName(): void
    {
        $row = $this->firePageView(['cv1' => 'author=ada'])['page_view'];

        $this->assertNotNull($row['params']);
        $this->assertSame(['author' => 'ada'], json_decode($row['params'], true),
            'Keyed by name, not by the numbered slot 1.x reports as a dimension.');
    }

    public function testTheVisitorStoreGetsTheFirstSessionsAcquisition(): void
    {
        $rows = $this->firePageView();

        $db  = owa_coreAPI::dbSingleton();
        $row = (array) $db->get_row(sprintf(
            'SELECT * FROM owa_visitor_acquisition WHERE visitor_id = %d',
            (int) $rows['page_view']['visitor_id']));

        $this->assertSame('spring', $row['acq_campaign']);
        $this->assertSame('https://www.example.net/x', $row['acq_referer_url']);
        /*
         * Derived from the ROW's own date, not from today's. An earlier test in
         * the suite moves the request container's clock -- which is what
         * yyyymmdd is stamped from -- so asserting date('Ym') passes alone and
         * fails in a full run, for a reason that has nothing to do with this
         * code. The invariant is that last_seen is the period of the event that
         * created the row.
         */
        $this->assertSame(
            (int) substr((string) $rows['page_view']['yyyymmdd'], 0, 6),
            (int) $row['last_seen'],
            'last_seen stores a PERIOD, so it changes at most once per visitor per month.');
    }

    /**
     * A visitor whose acquisition is unknown gets NO row. A placeholder would
     * be found present when the real first_visit arrived late on a queue drain
     * and would block the real value permanently, and silently.
     */
    public function testNoAcquisitionMeansNoVisitorRow(): void
    {
        $rows = $this->firePageView([
            'page_url'      => 'https://owa-test-site/v2/plain',
            'page_location' => 'https://owa-test-site/v2/plain',
            'landing_url'   => 'https://owa-test-site/v2/plain',
            'HTTP_REFERER'  => '',
        ]);

        $db    = owa_coreAPI::dbSingleton();
        $count = (array) $db->get_row(sprintf(
            'SELECT COUNT(*) AS n FROM owa_visitor_acquisition WHERE visitor_id = %d',
            (int) $rows['page_view']['visitor_id']));

        $this->assertSame(0, (int) $count['n']);
    }

    /**
     * An `ep_` property lands in params, by name, with the prefix stripped.
     *
     * The prefix is how the beacon says which scope a value belongs to, so this
     * needs no allowlist -- unlike the per-event-type params, which are names
     * the release knows.
     */
    public function testAnEventPropertyLandsInParamsUnderItsBareName(): void
    {
        $rows = $this->firePageView(['ep_coupon_code' => 'SPRING']);

        $params = json_decode($rows['page_view']['params'], true);

        $this->assertSame('SPRING', $params['coupon_code'] ?? null);
        $this->assertArrayNotHasKey('ep_coupon_code', $params,
            'the prefix is routing, not part of the name');
    }

    /** A `up_` property goes to the visitor store, not to params. */
    public function testAUserPropertyGoesToTheVisitorStoreAndNotToParams(): void
    {
        $visitor = $this->uniqueGuid();

        $rows = $this->firePageView([
            'visitor_id' => $visitor,
            'up_plan'    => 'enterprise',
        ]);

        $params = json_decode((string) $rows['page_view']['params'], true) ?: [];

        $this->assertArrayNotHasKey('plan', $params, 'a user property is not an event param');
        $this->assertArrayNotHasKey('up_plan', $params);

        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->load($visitor, 'visitor_id');

        $stored = json_decode((string) $entity->get('properties'), true);

        $this->assertSame('enterprise', $stored['plan']['v'] ?? null);
        $this->assertSame(
            (int) $rows['page_view']['ts'],
            (int) ($stored['plan']['ts'] ?? 0),
            'and it carries when it was set'
        );
    }

    /**
     * Last value wins, but an OLDER beacon never displaces a newer value.
     *
     * A queue drain can deliver events out of order; without the timestamp
     * guard the last one WRITTEN would win rather than the last one SET.
     */
    public function testAnOlderBeaconNeverOverwritesANewerProperty(): void
    {
        $visitor = $this->uniqueGuid();

        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->setProperties([
            'visitor_id' => $visitor,
            'site_id'    => $this->site,
            'properties' => json_encode([
                'plan' => ['v' => 'newer', 'ts' => (int) (microtime(true) * 1000000) + 60000000],
            ]),
        ]);
        $this->assertTrue($entity->create());

        $this->firePageView(['visitor_id' => $visitor, 'up_plan' => 'older']);

        $check = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $check->load($visitor, 'visitor_id');
        $stored = json_decode((string) $check->get('properties'), true);

        $this->assertSame('newer', $stored['plan']['v'] ?? null,
            'the value set later stands, whichever beacon arrived last');
    }

    /** A second property merges rather than replacing the first. */
    public function testASecondPropertyMergesWithTheFirst(): void
    {
        $visitor = $this->uniqueGuid();

        $this->firePageView(['visitor_id' => $visitor, 'up_plan' => 'pro']);
        $this->firePageView(['visitor_id' => $visitor, 'up_tier' => 'gold']);

        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->load($visitor, 'visitor_id');
        $stored = json_decode((string) $entity->get('properties'), true);

        $this->assertSame('pro', $stored['plan']['v'] ?? null);
        $this->assertSame('gold', $stored['tier']['v'] ?? null);
    }

    /**
     * A late first_visit still lands on a row that a property created.
     *
     * The write used to skip whenever the row existed, which was safe only
     * while nothing but acquisition ever wrote one. A user property can now
     * create a row for a visitor whose acquisition is unknown, and a queue
     * drain can deliver the real first_visit afterwards -- so skipping on row
     * presence would lose the acquisition permanently and silently, which is
     * exactly the placeholder hazard 2.9 refuses.
     */
    public function testALateFirstVisitFillsARowThatHasNoAcquisitionYet(): void
    {
        // A fresh id each run: this seeds a row directly, and a fixed id would
        // make create() fail the second time the suite is run against the same
        // database.
        $visitor = $this->uniqueGuid();

        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->setProperties([
            'visitor_id' => $visitor,
            'site_id'    => $this->site,
            'acq_ts'     => null,
            'properties' => json_encode(['plan' => ['v' => 'pro', 'ts' => 1790000000000000]]),
        ]);
        $this->assertTrue($entity->create());

        $written = $this->callWriteAcquisition($visitor);

        $this->assertTrue($written, 'the write should fill rather than skip');

        $check = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $check->load($visitor, 'visitor_id');

        $this->assertNotEmpty($check->get('acq_ts'), 'the acquisition landed');
        $this->assertNotEmpty($check->get('properties'), 'and the property survived it');
    }

    /** Once acq_ts is set, write-once still holds. */
    public function testASecondAcquisitionNeverOverwritesTheFirst(): void
    {
        $visitor = $this->uniqueGuid();

        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->setProperties([
            'visitor_id' => $visitor,
            'site_id'    => $this->site,
            'acq_source' => 'first-source',
            'acq_ts'     => 1790000000000000,
        ]);
        $this->assertTrue($entity->create());

        $this->callWriteAcquisition($visitor);

        $check = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $check->load($visitor, 'visitor_id');

        $this->assertSame('first-source', $check->get('acq_source'),
            'write-once: an acquisition already captured is never moved');
        $this->assertSame('1790000000000000', (string) $check->get('acq_ts'));
    }

    /**
     * Every site collects, with nothing to opt into.
     *
     * The inverse of the test this replaces, which asserted that a site had to
     * turn `v2_raw_collection` on first. The gate was development scaffolding
     * for exercising ingest against one site; the tracker now sends v2-shaped
     * events, so there is nothing left to gate on.
     */
    public function testEverySiteCollectsWithNoSettingToTurnOn(): void
    {
        $this->assertNotSame([], $this->firePageView(),
            'a site that was never configured for v2 still writes to owa_event_raw');
    }

    /**
     * A recording chunk is an attachment to a page view. Promoting chunks --
     * or worse, their samples -- would swamp the table: one measured corpus
     * held 229,663 chunks carrying 5,767,986 samples.
     */
    public function testDomstreamIsNotStoredAsAnEvent(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('dom.stream', [
            'site_id'    => $this->site,
            'visitor_id' => $visitor,
            'session_id' => $session,
            'page_url'   => 'https://owa-test-site/v2/stream',
            'domstream_guid' => $this->uniqueGuid(),
            'stream_events'  => '[]',
            'duration'       => 1,
            'stream_length'  => 0,
        ]);

        $this->assertSame([], $this->rowsFor($this->site, $visitor, $session));
    }
}
