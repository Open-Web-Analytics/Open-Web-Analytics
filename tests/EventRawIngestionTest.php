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
            // page_url as well as page_location, because Compat::apply() is
            // meant to leave page_location alone when a beacon carries both.
            // landing_url is NOT here: the tracker stopped sending it, and the
            // fixture claiming a property the registry no longer declares is
            // how a deleted field goes on looking alive.
            'page_url'      => 'https://owa-test-site/v2/a?owa_campaign=spring&keep=me',
            'page_location' => 'https://owa-test-site/v2/a?owa_campaign=spring&keep=me',
            'page_title'    => 'V2 A',
            'HTTP_REFERER'  => 'https://www.example.net/x?q=shoes',
            'is_new_session_start'   => true,
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
     * An event with no NAME is refused, like one with no visitor.
     *
     * The id is derived from five things -- site, visitor, session, ts and the
     * event name -- and the guard checked four. The column does not catch it
     * either: event_type is NOT NULL, and '' satisfies that. So every nameless
     * event of one visitor in one microsecond would derive the SAME id and
     * collide onto one row, which is what the guard's own comment says it
     * exists to prevent.
     *
     * DRIVEN AT row() RATHER THAN THROUGH A BEACON, deliberately. Dispatch will
     * not route an event with no type to a handler, so the public path cannot
     * reach this line -- a test that fired a nameless beacon would pass with
     * the guard REMOVED, proving only that dispatch drops it. Measured: it did.
     * This is a backstop against a caller passing an empty name, and the only
     * honest way to test a backstop is to call it.
     */
    public function testAnEventWithNoNameIsRefused(): void
    {
        $handler = new \OWA\Module\Base\Handler\EventRawHandlers;

        $method = new ReflectionMethod($handler, 'row');
        $method->setAccessible(true);

        $event = new \OWA\Module\Base\Classes\Event;
        $event->setProperties([
            'site_id'    => $this->site,
            'visitor_id' => $this->uniqueGuid(),
            'session_id' => $this->uniqueSessionId(),
            'ts'         => (int) (microtime(true) * 1000000),
        ]);

        $this->assertNull($method->invoke($handler, $event, ''),
            'an event that cannot name itself is not an observation');

        // And the same event WITH a name is accepted, so the assertion above
        // is not passing because the fixture is malformed.
        $this->assertNotNull($method->invoke($handler, $event, 'page_view'));
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
     * A SESSION WITH NO PAGE VIEW still gets its marker rows.
     *
     * The tracker has never been coupled to page views: raiseEvent() goes through
     * manageState() like everything else, so a scroll or a form_start that happens
     * to be the first event of a session mints the session id and stamps
     * is_new_session_start on THAT beacon. Ingest used to gate the markers on
     * page_view, which dropped the flag silently -- the session existed in raw with
     * no session_start row, and a genuinely new visitor had no first_visit.
     *
     * It survived because `sessions` is a distinct count over session_id, so the
     * session was still counted. What disagreed was everything reading the marker
     * rows.
     *
     * @dataProvider firstEventProvider
     */
    public function testAnyFirstEventRaisesTheMarkers(string $type, string $stored): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent($type, [
            'site_id'                => $this->site,
            'visitor_id'             => $visitor,
            'session_id'             => $session,
            'page_url'               => 'https://owa-test-site/v2/a',
            'page_location'          => 'https://owa-test-site/v2/a',
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
            'fsts'                   => time(),
            'sts'                    => time(),
            'num_prior_sessions'     => 0,
        ]);

        $rows = $this->rowsFor($this->site, $visitor, $session);

        $this->assertArrayHasKey($stored, $rows, "$type stores its own row");

        $this->assertArrayHasKey('session_start', $rows,
            "a session created by $type must still have a session_start row");
        $this->assertArrayHasKey('first_visit', $rows,
            "and a visitor minted on $type must still have a first_visit row");

        // One instant for every row of one beacon, markers included.
        $this->assertSame($rows[$stored]['ts'], $rows['session_start']['ts']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function firstEventProvider(): array
    {
        return [
            'a scroll'     => ['scroll', 'scroll'],
            'a form_start' => ['form_start', 'form_start'],
            'a click'      => ['dom.click', 'click'],
            'a custom event' => ['my_site_signup', 'my_site_signup'],
        ];
    }

    /**
     * A MATERIALIZED EVENT CARRIES THE CONTEXT, NOT THE CARRIER'S MEASUREMENTS.
     *
     * session_start and first_visit were copies of the ROW of whatever event
     * carried the flags, so a landing click's target, coordinates, outbound flag,
     * element params and engagement time appeared three times. engagement_msec
     * is summed over every row by totalEngagementTime, so five seconds counted as
     * fifteen. Now each is built from the registry: what is declared for its
     * name, less what is declared `"materialize": false`.
     */
    public function testMaterializedEventsCarryTheContextAndNotTheCarriersMeasurements(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('dom.click', [
            'site_id'                => $this->site,
            'visitor_id'             => $visitor,
            'session_id'             => $session,
            'page_url'               => 'https://owa-test-site/v2/landing',
            'page_location'          => 'https://owa-test-site/v2/landing',
            'page_title'             => 'Landing',
            'HTTP_REFERER'           => 'https://www.example.net/from',
            'target_url'             => 'https://elsewhere.example/guide.pdf',
            'click_x'                => 120,
            'click_y'                => 340,
            'is_outbound'            => 1,
            'dom_element_class'      => 'cta',
            'engagement_msec'        => 5000,
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
            'fsts'                   => time(),
            'sts'                    => time(),
            'num_prior_sessions'     => 0,
        ]);

        $rows = $this->rowsFor($this->site, $visitor, $session);

        $this->assertSame(['click', 'first_visit', 'session_start'],
            array_values(array_intersect(['click', 'first_visit', 'session_start'],
                array_keys($rows))), 'one click of a new visitor is three events');

        // The carrier keeps its own measurements, so the absences below are not
        // the fixture failing to send them.
        $this->assertSame('https://elsewhere.example/guide.pdf', $rows['click']['target_url']);
        $this->assertSame(5000, (int) $rows['click']['engagement_msec']);
        $this->assertSame(1, (int) $rows['click']['is_outbound']);

        foreach (['session_start', 'first_visit'] as $name) {

            $row = $rows[$name];

            // The context is shared.
            $this->assertSame($rows['click']['page_location'], $row['page_location'], $name);
            $this->assertSame($rows['click']['referer_url'], $row['referer_url'], $name);
            $this->assertSame($rows['click']['ts'], $row['ts'], $name);

            // The click's measurements are not.
            $this->assertNull($row['target_url'], "$name copied the click's target");
            $this->assertNull($row['target_host'], "$name copied the click's target host");
            $this->assertNull($row['click_x'], "$name copied the click's coordinates");
            $this->assertSame(0, (int) $row['is_outbound'], "$name copied is_outbound");
            $this->assertNull($row['params'], "$name copied the click's params");
            $this->assertNull($row['engagement_msec'],
                "$name copied the click's engagement time, which totalEngagementTime then sums");
        }
    }

    /** A purchase that starts a session does not put its revenue on the session_start. */
    public function testAMaterializedEventCarriesNoRevenue(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('ecommerce.transaction', [
            'site_id'              => $this->site,
            'visitor_id'           => $visitor,
            'session_id'           => $session,
            'page_url'             => 'https://owa-test-site/v2/checkout',
            'page_location'        => 'https://owa-test-site/v2/checkout',
            'ct_order_id'          => 'order-' . $visitor,
            'ct_total'             => 19.99,
            'currency'             => 'USD',
            'is_new_session_start' => true,
            'sts'                  => time(),
            'num_prior_sessions'   => 1,
        ]);

        $rows = $this->rowsFor($this->site, $visitor, $session);

        $this->assertArrayHasKey('purchase', $rows);
        $this->assertSame(1999, (int) $rows['purchase']['revenue'],
            'the purchase keeps its revenue, so the absence below is not the fixture');

        $this->assertArrayHasKey('session_start', $rows);
        $this->assertNull($rows['session_start']['revenue']);
        $this->assertNull($rows['session_start']['transaction_id']);
        $this->assertNull($rows['session_start']['currency']);
    }

    /**
     * A purchase through the whole pipeline: amounts converted by the currency's
     * own decimal places, revenue excluding tax and shipping, and the currency
     * stored canonical.
     *
     * @dataProvider purchases
     */
    public function testAPurchaseStoresRevenueByItsCurrency(array $sent, array $stored): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('ecommerce.transaction', $sent + [
            'site_id'            => $this->site,
            'visitor_id'         => $visitor,
            'session_id'         => $session,
            'page_url'           => 'https://owa-test-site/v2/receipt',
            'page_location'      => 'https://owa-test-site/v2/receipt',
            'ct_order_id'        => 'order-' . $visitor,
            'sts'                => time(),
            'num_prior_sessions' => 1,
        ]);

        $row = $this->rowsFor($this->site, $visitor, $session)['purchase'] ?? null;

        $this->assertNotNull($row, 'the purchase was not stored');

        foreach ($stored as $column => $value) {
            $this->assertSame($value, $row[$column] === null ? null : (string) $row[$column], $column);
        }
    }

    public static function purchases(): array
    {
        return [
            'dollars, tax and shipping out of revenue' => [
                ['ct_total' => 25.00, 'ct_tax' => 2.00, 'ct_shipping' => 3.00, 'currency' => 'usd'],
                ['revenue' => '2000', 'tax' => '200', 'shipping' => '300', 'currency' => 'USD'],
            ],
            'yen has no minor unit' => [
                ['ct_total' => 1500, 'currency' => 'JPY'],
                ['revenue' => '1500', 'currency' => 'JPY'],
            ],
            'three places for the Kuwaiti dinar' => [
                ['ct_total' => 1.234, 'currency' => 'KWD'],
                ['revenue' => '1234', 'currency' => 'KWD'],
            ],
        ];
    }

    /**
     * A tracker cannot send a materialized name. The server is their only
     * source; a beacon naming one would be stored beside the real one.
     */
    public function testATrackerCannotSendAMaterializedName(): void
    {
        $this->assertFalse(owa_coreAPI::isTrackingEventType('session_start'));
        $this->assertFalse(owa_coreAPI::isTrackingEventType('first_visit'));

        // And the gate still admits what it should, so the two refusals above
        // are not the gate refusing everything.
        $this->assertTrue(owa_coreAPI::isTrackingEventType('page_view'));
        $this->assertTrue(owa_coreAPI::isTrackingEventType('my_site_signup'));
    }

    /**
     * Materialized events are raised from the INCOMING event only, once each.
     *
     * A materialized event copies the `*` properties of its carrier, so it holds
     * the flags too. If a materializer read every event in the set, the
     * session_start appended first would raise a second first_visit.
     */
    public function testMaterializedEventsAreRaisedOnceFromTheIncomingEvent(): void
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('page_view');
        $event->setProperties([
            'site_id'                => $this->site,
            'visitor_id'             => $this->uniqueGuid(),
            'session_id'             => $this->uniqueSessionId(),
            'ts'                     => time() * 1000000,
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
        ]);

        $events = \OWA\Module\Base\Classes\Ingest::at(
            \OWA\Module\Base\Classes\Ingest::TRACKING_EVENTS_PRE_SAVE, [$event]);

        $this->assertSame(['page_view', 'session_start', 'first_visit'],
            array_map(fn($e) => $e->getEventType(), $events));
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
        $this->assertSame('owa-test-site', $row['host']);

        // THE READING IS CANONICALISED WHERE THE EVIDENCE IS NOT. owa_campaign
        // is OWA's own control parameter, and a page report that groups on the
        // raw query shows it as a different page every time a campaign changes.
        $this->assertSame('keep=me', $row['page_query'],
            'the site\'s own parameter survives and OWA\'s does not');
        $this->assertStringNotContainsString('owa_campaign', (string) $row['page_query']);
    }

    /**
     * A display name is a custom USER property, and reaches the visitor store.
     *
     * These two tests asserted it reached `params` as a declared property with
     * `log_visitor_pii` gating it. Both premises are gone: user_name and
     * user_email left the release vocabulary to become ordinary custom user
     * properties (PLAN.html §2.26.1 -- v2 offers site authors event and user
     * scope and nothing else, and a value describing the PERSON is the second),
     * and the gate moved to user_id, which is the identity field that still has a
     * column.
     *
     * So the path under test is the `up_` one, which is the same path any other
     * user property takes -- the point being that a name needs no special case
     * once it stops being special.
     */
    public function testADisplayNameIsAUserProperty(): void
    {
        $visitor = $this->uniqueGuid();

        $this->firePageView([
            'visitor_id'   => $visitor,
            'up_user_name' => 'Alice',
        ]);

        $store = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $store->load($visitor, 'visitor_id');

        $this->assertTrue($store->wasPersisted(), 'no visitor record was written');

        $properties = json_decode((string) $store->get('properties'), true) ?: array();

        $this->assertSame('Alice', $properties['user_name']['v'] ?? null,
            'a user property must reach the visitor store under its bare name');

        $this->assertArrayHasKey('ts', (array) ($properties['user_name'] ?? array()),
            'and carry when it was set, which is what makes a temporal value answerable');
    }

    /**
     * And the bare name an OLDER tracker sends still lands there.
     *
     * setUserName() wrote the visitor cookie and the value rode every beacon as
     * `user_name`, with no prefix. That name now declares nothing, so without a
     * bridge admitRequestParams() would drop it -- conf/beacon_compat.php renames
     * it onto up_user_name, which is the shape the current tracker sends directly.
     */
    public function testAnOlderTrackersBareUserNameIsBridged(): void
    {
        $visitor = $this->uniqueGuid();

        $this->firePageView([
            'visitor_id' => $visitor,
            'user_name'  => 'Bob',
        ]);

        $store = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $store->load($visitor, 'visitor_id');

        $properties = json_decode((string) $store->get('properties'), true) ?: array();

        $this->assertSame('Bob', $properties['user_name']['v'] ?? null,
            'the compat rename did not reach the visitor store');
    }

    /**
     * log_visitor_pii gates user_id, which is where it belongs.
     *
     * user_id is the site's own identifier for a person: it has a column, it
     * persists, and it outlives a cookie. An install that turns visitor PII off
     * must be able to stop storing it.
     *
     * gateUserId() DELETES the property rather than returning null. A null is not
     * written back by setTrackerProperties(), so a gate that merely returns
     * nothing leaves the beacon's value on the event for the row builder to read
     * -- which is exactly how the old user_name gate failed.
     */
    public function testThePiiGateStopsUserIdBeingStored(): void
    {
        $before = owa_coreAPI::getSetting('base', 'log_visitor_pii');

        $with = $this->firePageView(['user_id' => 'person-42'])['page_view'];

        $this->assertSame('person-42', $with['user_id'],
            'with PII logging on, user_id is stored');

        owa_coreAPI::setSetting('base', 'log_visitor_pii', false);

        try {
            $without = $this->firePageView(['user_id' => 'person-42'])['page_view'];

            $this->assertNull($without['user_id'],
                'with PII logging off, user_id must not be stored');

        } finally {
            owa_coreAPI::setSetting('base', 'log_visitor_pii', $before);
        }
    }

    /**
     * Line items nest as a real array, so JSON_TABLE can read one row per item.
     *
     * ct_line_items arrives from the wire as JSON TEXT, and Sanitize::cleanJson()
     * validates it and hands the text back. The row builder assigned that straight
     * into the params map, so json_encode() escaped it a second time and the column
     * held {"items": "[{\"sku\": ...}]"} -- the value at $.items being one scalar
     * string. Measured on a seeded purchase.
     *
     * JSON_TABLE over '$.items[*]' then produces nothing usable, which is why the
     * Products report had no line items to report on. Asserted both ways: the
     * document nests, and the server can expand it.
     */
    public function testLineItemsNestAndExpandWithJsonTable(): void
    {
        $order = 'items-' . $this->uniqueGuid();

        $items = array(
            array( 'sku' => 'SKU-A', 'name' => 'Widget', 'price' => 10.20, 'quantity' => 2 ),
            array( 'sku' => 'SKU-B', 'name' => 'Gadget', 'price' => 5.50,  'quantity' => 1 ),
        );

        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('ecommerce.transaction', [
            'site_id'       => $this->site,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'guid'          => $this->uniqueGuid(),
            'page_url'      => 'https://owa-test-site/checkout',
            'page_location' => 'https://owa-test-site/checkout',
            'page_title'    => 'Checkout',
            'ct_order_id'   => $order,
            'ct_total'      => '25.90',
            'currency'      => 'USD',
            'ct_line_items' => json_encode($items),
        ]);

        $db  = owa_coreAPI::dbSingleton();
        $row = (array) $db->get_row(sprintf(
            "SELECT id, params FROM owa_event_raw WHERE site_id = '%s' AND session_id = %d "
            . "AND event_type = 'purchase'", $this->site, (int) $session));

        $this->assertNotEmpty($row, 'the purchase was not stored');
        $this->trackForCleanup('base.event_raw', (string) $row['id'], 'id');

        $params = json_decode((string) $row['params'], true);

        $this->assertIsArray($params['items'] ?? null,
            'items must nest as an array; a JSON string here is the double-encoding bug');

        $this->assertCount(2, $params['items']);
        $this->assertSame('SKU-A', $params['items'][0]['sku']);

        /*
         * AND THE SERVER CAN EXPAND IT. The shape above could be right while the
         * query the Products report needs still fails, so this asks MySQL rather
         * than trusting the document.
         */
        $expanded = (array) $db->get_results(sprintf(
            "SELECT li.sku, li.quantity FROM owa_event_raw e,"
            . " JSON_TABLE(e.params, '$.items[*]' COLUMNS ("
            . "   sku VARCHAR(64) PATH '$.sku',"
            . "   quantity INT PATH '$.quantity')) li"
            . " WHERE e.site_id = '%s' AND e.session_id = %d AND e.event_type = 'purchase'"
            . " ORDER BY li.sku", $this->site, (int) $session));

        $this->assertCount(2, $expanded,
            'JSON_TABLE must yield one row per line item');

        $first = (array) $expanded[0];

        $this->assertSame('SKU-A', $first['sku']);
        $this->assertEquals(2, $first['quantity']);
    }

    /**
     * The referrer is read for its host and its query, and edited no further.
     *
     * The host is what the cube pass classifies source and medium from, so it is
     * the reading that has to be there. Beyond that the URL is SOMEBODY ELSE'S,
     * and canonicalising it against this site's default page or filtering it
     * against this site's parameter list would be a category error -- their
     * ?q=shoes is the search term, not plumbing to strip.
     */
    public function testTheReferrerIsReadForItsHostAndQuery(): void
    {
        $row = $this->firePageView()['page_view'];

        $this->assertSame('https://www.example.net/x?q=shoes', $row['referer_url'],
            'the referrer is stored exactly as the browser sent it');

        $this->assertSame('www.example.net', $row['referer_host']);
        $this->assertSame('q=shoes', $row['referer_query'],
            'their parameter survives -- the dropped list is this site\'s, not theirs');
    }

    /**
     * An off-site click's target host, so outbound is a comparison rather than a
     * string test.
     *
     * target_host is derived AFTER makeUrlCanonical() has been over target_url,
     * which the property scopes settle: target_url is client-set and target_host
     * is event-set, so the filter has run by the time the host is read. The two
     * therefore cannot disagree about which URL they describe.
     */
    public function testAClickReadsItsTargetHost(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('dom.click', [
            'site_id'       => $this->site,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'page_url'      => 'https://owa-test-site/v2/a',
            'page_location' => 'https://owa-test-site/v2/a',
            'target_url'    => 'https://shop.example.org/cart?sku=9',
            'fsts'          => time(),
            'sts'           => time(),
            'num_prior_sessions' => 0,
        ]);

        $row = $this->rowsFor($this->site, $visitor, $session)['click'] ?? null;

        $this->assertNotNull($row, 'dom.click stores a row named click');

        $this->assertSame('shop.example.org', $row['target_host']);
        $this->assertSame('owa-test-site', $row['host'],
            'and the page host is the page\'s, not the target\'s');
    }

    /**
     * The row stores whether the click left the site; THE CLIENT decided it.
     *
     * The comparison was a server derivation, off target_url's host against
     * page_location's. That only serves a sender shaped like a browser -- a non-web
     * client has neither property and still knows whether what it raised left the
     * property -- and only the client can see the DOM, where an href may have been
     * rewritten by script. The host-comparison rules are asserted against
     * OWATracker.isOutboundUrl(), which is where they now live.
     *
     * What is left here is the mapping, and it has a real edge: the column is
     * NOT NULL, so a beacon that says nothing must land as 0 rather than aborting
     * the insert under strict mode.
     *
     * @dataProvider outboundProvider
     */
    public function testTheRowStoresTheOutboundFlagTheBeaconSent(
        $sent, int $expected, string $because): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $properties = [
            'site_id'       => $this->site,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'page_url'      => 'https://owa-test-site/v2/a',
            'page_location' => 'https://owa-test-site/v2/a',
            'target_url'    => 'https://shop.example.org/cart?sku=9',
            'fsts'          => time(),
            'sts'           => time(),
            'num_prior_sessions' => 0,
        ];

        if ($sent !== null) {
            $properties['is_outbound'] = $sent;
        }

        $this->fireEvent('dom.click', $properties);

        $row = $this->rowsFor($this->site, $visitor, $session)['click'] ?? null;

        $this->assertNotNull($row, 'dom.click stores a row named click');
        $this->assertSame($expected, (int) $row['is_outbound'], $because);
    }

    /** @return array<string,array{0:mixed,1:int,2:string}> */
    public static function outboundProvider(): array
    {
        return [
            'the beacon says it left' => [
                1, 1, 'what the client decided is what the row records'],
            'the beacon says it did not' => [
                0, 0, 'and 0 is a reading, not an absence'],
            "'0' off a query string" => [
                '0', 0, 'the wire carries strings, and "0" is not truthy'],
            /*
             * THE EDGE. A tracker too old to send it, or any sender that omits it,
             * must land as 0: the column is NOT NULL and strict mode aborts the
             * whole insert on a NULL. beacon_version on the same row says which
             * generation wrote it, so the 0 is not mistaken for a measurement.
             */
            'the beacon says nothing' => [
                null, 0, 'absence is stored as 0 because the column is NOT NULL'],
        ];
    }

    /**
     * A FILE DOWNLOAD IS NOT OUTBOUND, however far away the file is.
     *
     * classifyClickTarget() raises file_download beside the click and sets
     * target_url on it, so the download row carries a target host like the click
     * does -- and a PDF linked from a CDN stored is_outbound = 1, claiming a
     * departure that never happened. The visitor stayed on the page and a file
     * arrived.
     *
     * `events` in the registry cannot enforce this: setTrackerProperties() walks
     * the whole derived map for every event and does not read it, so the gate has
     * to be in the callback. This is the test that says which.
     *
     * The target host is still recorded, so "which hosts do our downloads come
     * from" survives; only the verdict about leaving is withheld.
     */
    public function testAFileDownloadIsNotAnOutboundClick(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('file_download', [
            'site_id'        => $this->site,
            'visitor_id'     => $visitor,
            'session_id'     => $session,
            'page_url'       => 'https://owa-test-site/v2/docs',
            'page_location'  => 'https://owa-test-site/v2/docs',
            // Another host entirely, which is what a CDN-hosted asset looks like.
            'target_url'     => 'https://cdn.example.org/files/guide.pdf',
            'file_extension' => 'pdf',
            'file_name'      => '/files/guide.pdf',
            'fsts'           => time(),
            'sts'            => time(),
            'num_prior_sessions' => 0,
        ]);

        $row = $this->rowsFor($this->site, $visitor, $session)['file_download'] ?? null;

        $this->assertNotNull($row, 'file_download stores a row');

        $this->assertSame(0, (int) $row['is_outbound'],
            'a download is a file arriving, not the visitor leaving');

        $this->assertSame('cdn.example.org', $row['target_host'],
            'and the target host is still recorded -- the only thing withheld is the '
            . 'verdict about whether the visitor left');
    }

    /**
     * The download's name and extension are COLUMNS, and are not also in params.
     *
     * They were params, and a params key is unreportable until a site registers it
     * as a custom dimension -- so a downloads report cost every install one of its
     * 20 registration slots for a value OWA set itself. Promoted in Update054.
     *
     * NOT ALSO IN THE BAG is the half worth asserting. params() is built from the
     * registry's param destinations, so moving one to `column` should take it out
     * of the JSON -- and a value in both places is two authorities for one fact,
     * which is how the row builder and the registry drifted apart before.
     *
     * The element and form params are NOT promoted, and the same row proves it:
     * element_class is still only in the bag.
     */
    public function testTheDownloadNameAndExtensionAreColumns(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('file_download', [
            'site_id'           => $this->site,
            'visitor_id'        => $visitor,
            'session_id'        => $session,
            'page_url'          => 'https://owa-test-site/v2/docs',
            'page_location'     => 'https://owa-test-site/v2/docs',
            'target_url'        => 'https://owa-test-site/files/guide.pdf',
            'file_name'         => '/files/guide.pdf',
            'file_extension'    => 'pdf',
            'fsts'              => time(),
            'sts'               => time(),
            'num_prior_sessions' => 0,
        ]);

        $row = $this->rowsFor($this->site, $visitor, $session)['file_download'] ?? null;

        $this->assertNotNull($row, 'file_download stores a row');

        // The PATH, which is what the tracker cuts: as a basename, two files of
        // the same name in different folders were one row of a downloads report.
        $this->assertSame('/files/guide.pdf', $row['file_name']);
        $this->assertSame('pdf', $row['file_extension']);

        $params = (array) json_decode((string) $row['params'], true);

        $this->assertArrayNotHasKey('file_name', $params,
            'a promoted property must leave the bag, or one fact has two authorities');
        $this->assertArrayNotHasKey('file_extension', $params);
    }

    /**
     * The search term is a COLUMN, and not also in params.
     *
     * It was a param, so "what do people search this site for" needed a custom
     * dimension registration -- one of a Property's twenty slots spent on a value
     * OWA set itself. Third promotion after file_name and file_extension.
     *
     * The event could not fire at all before this release: siteSearchParams
     * defaulted to empty AND trackSiteSearch() had no caller. So there is nothing
     * to backfill, and this is the first test that could ever have stored one.
     */
    public function testTheSearchTermIsAColumn(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('view_search_results', [
            'site_id'        => $this->site,
            'visitor_id'     => $visitor,
            'session_id'     => $session,
            'page_url'       => 'https://owa-test-site/search?q=table+partitioning',
            'page_location'  => 'https://owa-test-site/search?q=table+partitioning',
            'search_term'    => 'table partitioning',
            'fsts'           => time(),
            'sts'            => time(),
            'num_prior_sessions' => 0,
        ]);

        $row = $this->rowsFor($this->site, $visitor, $session)['view_search_results'] ?? null;

        $this->assertNotNull($row, 'view_search_results stores a row');
        $this->assertSame('table partitioning', $row['search_term']);

        $params = (array) json_decode((string) $row['params'], true);

        $this->assertArrayNotHasKey('search_term', $params,
            'a promoted property must leave the bag, or one fact has two authorities');
    }

    /**
     * AND THE ELEMENT PARAMS STAY IN THE BAG -- the other half of the decision.
     *
     * Only file_name and file_extension were promoted. Most installs will never
     * group by an element class or name, and a column is width on every row of
     * every Property whether anyone reads it or not: promoting nine of these was
     * measured at taking the custom-dimension ceiling from 62 to 37 on MySQL 8.4.
     * A site that does want one registers it, which is the route its own values
     * take.
     *
     * So this asserts a NON-promotion, which is the kind of decision that
     * otherwise erodes one column at a time.
     */
    public function testTheElementParamsStayInTheBag(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('dom.click', [
            'site_id'           => $this->site,
            'visitor_id'        => $visitor,
            'session_id'        => $session,
            'page_url'          => 'https://owa-test-site/v2/a',
            'page_location'     => 'https://owa-test-site/v2/a',
            'target_url'        => 'https://owa-test-site/v2/b',
            'dom_element_class' => 'download-link',
            'dom_element_name'  => 'cta',
            'dom_element_text'  => 'Get the guide',
            'fsts'              => time(),
            'sts'               => time(),
            'num_prior_sessions' => 0,
        ]);

        $row = $this->rowsFor($this->site, $visitor, $session)['click'] ?? null;

        $this->assertNotNull($row, 'dom.click stores a row');

        $params = (array) json_decode((string) $row['params'], true);

        $this->assertSame('download-link', $params['element_class'] ?? null);
        $this->assertSame('cta', $params['element_name'] ?? null);
        $this->assertSame('Get the guide', $params['element_text'] ?? null);

        // And none of the three gained a column on the way.
        $columns = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumns();

        foreach (['element_class', 'element_name', 'element_text'] as $name) {
            $this->assertNotContains($name, $columns,
                "$name is a param by decision, not an oversight -- see Update054.");
        }
    }

    /**
     * And every other event answers 0 rather than NULL.
     *
     * The column is NOT NULL because a boolean holding three values groups as
     * three things, and the boolean formatter renders NULL and 0 both as 'No' --
     * two GROUP BY buckets under one label. A page_view is not an outbound click,
     * so 0 is the true answer rather than a stand-in for one.
     */
    public function testANonClickIsNotAnOutboundClick(): void
    {
        $row = $this->firePageView()['page_view'];

        $this->assertNotNull($row['is_outbound'],
            'is_outbound is NOT NULL, and strict mode aborts an insert that hands it NULL');

        $this->assertSame(0, (int) $row['is_outbound']);
    }

    /**
     * The path collapses, so one page is one row in a page report.
     *
     * /store, /store/ and /store/index.html are the case v1 handles and v2 did
     * not until this. The URL itself is still stored as it arrived.
     */
    public function testThePagePathIsCanonicalisedAndTheUrlIsNot(): void
    {
        $row = $this->firePageView([
            'page_location' => 'https://owa-test-site/v2/store/?keep=me',
        ])['page_view'];

        $this->assertSame('/v2/store', $row['page_path'], 'the trailing slash is collapsed');

        $this->assertStringContainsString('/v2/store/', $row['page_location'],
            'and the URL still says exactly what arrived');
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
                  'currency', 'target_url'] as $column) {
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

        $this->assertNull($row['engagement_msec']);
        $this->assertNull($row['scroll_depth']);
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
        $this->assertSame('https://www.example.net/x?q=shoes', $row['acq_referer_url'],
            'the acquisition keeps the referrer whole, query included');
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

    /**
     * A PURCHASE STORES ITS REVENUE, in minor units.
     *
     * It stored NULL. row() read a property named `revenue`, which the registry
     * does not declare and no tracker sends -- the wire name is ct_total, and has
     * been since 1.x. So every purchase ever ingested by v2 recorded its currency
     * and no amount, and nothing said so because absence is a legitimate value in
     * that column.
     *
     * 12.50 is the value to test with rather than a round number: the column is
     * minor units, so the conversion multiplies by 100, and (int) truncation of
     * the binary float gives 1249 instead of 1250. A round amount would pass
     * either way.
     */
    public function testAPurchaseStoresItsRevenueInMinorUnits(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('ecommerce.transaction', [
            'site_id'    => $this->site,
            'visitor_id' => $visitor,
            'session_id' => $session,
            'page_url'   => 'https://owa-test-site/v2/thanks',
            'ct_order_id' => 'A-1',
            'ct_total'    => '12.50',
            'currency'    => 'USD',
        ]);

        $rows = $this->rowsFor($this->site, $visitor, $session);

        $this->assertArrayHasKey('purchase', $rows, 'the purchase was not stored at all');

        $this->assertSame('1250', (string) $rows['purchase']['revenue'],
            'The purchase stored no revenue. The row reads ct_total -- the name the '
            . 'registry declares for this event -- not a property called revenue.');

        $this->assertSame('USD', $rows['purchase']['currency'],
            'minor units without the currency sum different things together');
    }

    /**
     * THE REST OF THE TRANSACTION LANDS: three columns and three params.
     *
     * It landed nowhere. The per-event param list named `transaction_id, tax,
     * shipping, gateway, items` and the wire sends `ct_order_id, ct_tax,
     * ct_shipping, ct_gateway, ct_line_items`, so every lookup missed, `params`
     * came back NULL, and a NULL params column is indistinguishable from an
     * event that carried none.
     *
     * Tax and shipping are COLUMNS because each is a summed metric and a metric
     * needs a column to sum; transaction_id is a column because it is what makes
     * a purchase countable once. The gateway and the order source are labels
     * nobody adds up, so they are params -- under the names a report reaches,
     * without 1.x's ct_ prefix.
     */
    public function testAPurchaseStoresItsOrderTaxAndShipping(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('ecommerce.transaction', [
            'site_id'    => $this->site,
            'visitor_id' => $visitor,
            'session_id' => $session,
            'page_url'   => 'https://owa-test-site/v2/thanks',
            'ct_order_id'      => 'ORD-1234',
            'ct_total'         => '25.00',
            'ct_tax'           => '2.50',
            'ct_shipping'      => '4.99',
            'ct_gateway'       => 'stripe',
            'ct_order_source'  => 'web',
            'currency'         => 'USD',
        ]);

        $row = $this->rowsFor($this->site, $visitor, $session)['purchase'] ?? null;

        $this->assertNotNull($row, 'the purchase was not stored at all');

        $this->assertSame('ORD-1234', $row['transaction_id']);
        $this->assertSame('250', (string) $row['tax'], 'tax is minor units');
        $this->assertSame('499', (string) $row['shipping'], 'shipping is minor units');

        $params = json_decode((string) $row['params'], true);

        $this->assertIsArray($params, 'params did not arrive as JSON: ' . var_export($row['params'], true));

        $this->assertSame('stripe', $params['gateway'] ?? null,
            'the gateway is reached as params.gateway, without the ct_ prefix');
        $this->assertSame('web', $params['order_source'] ?? null);

        $this->assertArrayNotHasKey('ct_gateway', $params,
            "1.x's wire prefix must not reach the reporting vocabulary");
    }

    /**
     * THE BILLING ADDRESS IS NOT COLLECTED, and the allowlist is what refuses it.
     *
     * city, state and country are the SERVER-DERIVED geolocation readings from
     * the observed IP. A transaction used to send its billing address under those
     * three names, silently replacing the visitor's location on purchase rows
     * only. They were then moved to ct_* prefixes, and now they are not declared
     * at all: nothing reports on a billing address.
     */
    public function testTheBillingAddressIsRefused(): void
    {
        $admitted = \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams([
            'ct_city'    => 'Boston',
            'ct_state'   => 'MA',
            'ct_country' => 'US',
            'ct_total'   => '10.00',
        ]);

        $this->assertSame(['ct_total' => '10.00'], $admitted,
            'a name the registry does not declare must not reach the event');
    }

    /**
     * A purchase that sent NO total stores NULL, not 0.
     *
     * The two have to be distinguishable: 0 is a free order, NULL is a store
     * that did not tell us. Asserted for the ABSENT case because that is the one
     * the pipeline can still express -- ct_total is declared with data_type
     * integer, and that applies `$var + 0`, so a total of 'free' has already
     * become 0 before the row is built. The conversion's own not-a-number branch
     * is therefore unreachable from the wire, and 0 in this column can mean
     * either a free order or a garbled one.
     */
    public function testAPurchaseWithNoTotalStoresNullNotZero(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent('ecommerce.transaction', [
            'site_id'    => $this->site,
            'visitor_id' => $visitor,
            'session_id' => $session,
            'page_url'   => 'https://owa-test-site/v2/thanks',
            'ct_order_id' => 'A-2',
            'currency'    => 'USD',
        ]);

        $rows = $this->rowsFor($this->site, $visitor, $session);

        $this->assertArrayHasKey('purchase', $rows);

        $this->assertNull($rows['purchase']['revenue']);
    }
}
