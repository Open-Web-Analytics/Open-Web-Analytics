<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Beacon-contract test for the session fan-out that a base.page_request
 * triggers: base.page_request -> owa_requestHandlers (writes owa_request) ->
 * base.page_request_logged -> owa_sessionHandlers -> base.session
 * (table owa_session), loaded back by the session_id PK (column id).
 *
 * Two cases, mirroring exactly what the tracker JS puts on the wire (verified
 * against tests/fixtures/beacon_contracts.json / tests/js/BeaconContract.test.js):
 *
 *  - New session: the FIRST page_request of a session carries is_new_session=true
 *    and a freshly generated session_id (the tracker sets these in
 *    setSessionId()). The handler's logSession() then writes the session row
 *    with num_pageviews=1 / is_bounce=true.
 *
 *  - Session update: a LATER page_request in the same, still-active session. The
 *    tracker reuses the stored session id (setSessionId else-branch) and does
 *    NOT set is_new_session, which routes the handler to logSessionUpdate().
 *    That path recounts page views from the request rows sharing the session_id
 *    and flips is_bounce off.
 *
 * Time note: OWA does not trust the client `timestamp` — the environmental
 * timestampDefault filter overwrites it with the server receive time. So to
 * order the two requests (the update only fires when the later request's time
 * exceeds the session's last_req) we advance the server clock via
 * setServerTime(), NOT via the event's timestamp property.
 *
 * The session_id is a unique NUMERIC id in the tracker's generateRandomGuid()
 * format (see IngestionTestCase::uniqueGuid) — it must be numeric because the
 * id/session_id columns are BIGINT; a non-numeric value is silently cast to 0.
 */
final class SessionIngestionTest extends IngestionTestCase
{
    /**
     * Fire one page_request through the pipeline (creating an owa_request row)
     * and register that row for cleanup. Returns the request guid.
     *
     * @param array<string, mixed> $extra additional/overriding event properties
     */
    private function firePageRequest(string $site_id, string $session_id, array $extra): string
    {
        $guid = $this->uniqueGuid();
        $this->trackForCleanup('base.request', $guid, 'id');

        $props = array_merge([
            'guid'       => $guid,
            'site_id'    => $site_id,
            'session_id' => $session_id,
            'page_url'   => 'https://example.com/session-test',
        ], $extra);

        $result = $this->fireEvent('base.page_request', $props);
        $this->assertNotFalse($result, 'page_request was dropped before persistence.');
        return $guid;
    }

    public function testNewSessionPersistsSessionRow(): void
    {
        // Every field logSession() relies on must be one the tracker emits.
        $this->assertFieldsInContract(
            'base.page_request',
            ['session_id', 'is_new_session', 'page_url']
        );

        $site_id    = md5('owa-test-site');
        $session_id = $this->uniqueSessionId();
        $this->trackForCleanup('base.session', $session_id, 'id');

        $this->setServerTime(1700000000);

        // First beacon of a new session (matches the tracker's new-session wire shape).
        $this->firePageRequest($site_id, $session_id, [
            'is_new_session' => true,
        ]);

        $s = $this->assertRowPersisted('base.session', $session_id, 'id');
        $this->assertSame($site_id, $s->get('site_id'));
        // logSession() seeds a fresh session as a single-pageview bounce.
        $this->assertEquals(1, $s->get('num_pageviews'));
        // last_req is the server-assigned time of the opening request.
        $this->assertEquals(1700000000, $s->get('last_req'));
        // first_page_id is derived from page_url.
        $this->assertEquals(
            owa_lib::setStringGuid('https://example.com/session-test'),
            $s->get('first_page_id')
        );
    }

    public function testSecondRequestUpdatesSession(): void
    {
        $this->assertFieldsInContract(
            'base.page_request',
            ['session_id', 'is_new_session']
        );

        $site_id    = md5('owa-test-site');
        $session_id = $this->uniqueSessionId();
        $this->trackForCleanup('base.session', $session_id, 'id');

        // Request 1 at T0: opens the session (is_bounce=true, num_pageviews=1).
        $this->setServerTime(1700000000);
        $this->firePageRequest($site_id, $session_id, [
            'is_new_session' => true,
        ]);

        $opened = $this->assertRowPersisted('base.session', $session_id, 'id');
        $this->assertEquals(1, $opened->get('num_pageviews'));

        // Request 2 at T0+60: later beacon in the SAME active session. Per the
        // tracker, an active session reuses the stored session id and does NOT
        // set is_new_session, routing the handler to logSessionUpdate(). The
        // server clock must advance past the session's last_req for the update
        // to apply.
        $this->setServerTime(1700000060);
        $this->firePageRequest($site_id, $session_id, []);

        // Reload the session and assert the update took effect.
        $updated = owa_coreAPI::entityFactory('base.session');
        $updated->load($session_id, 'id');
        $this->assertTrue($updated->wasPersisted());
        // Two request rows now share the session, so the recount is 2 ...
        $this->assertEquals(2, $updated->get('num_pageviews'));
        // ... and a multi-pageview session is no longer a bounce. The handler
        // sets is_bounce='false', which the TINYINT column stores as 0.
        $this->assertEquals(0, $updated->get('is_bounce'));
        // last_req advanced to the second request's server time.
        $this->assertEquals(1700000060, $updated->get('last_req'));
    }

    /**
     * Campaign attribution is denormalized onto the SESSION fact, not just the
     * request fact. On a new-session campaign pageview, logSession() sweeps the
     * event's properties onto base.session (setProperties), so the derived
     * medium and the campaign_id FK land there, and it explicitly copies the
     * `attribs` touch-history JSON into the latest_attributions column. This
     * proves the session row is attributed to the campaign that opened it.
     */
    /**
     * A second pageview in the SAME page load was silently dropped.
     *
     * is_new_session is PAGE scoped: every event from the page the session
     * started on carries it, including a second trackPageView() in one page
     * load, which is ordinary for a single-page app. That routed the hit to
     * logSession(), which found the row already there, logged 'Not persisting
     * new session' and returned HANDLED -- so num_pageviews never counted it
     * and the session never stopped being a bounce.
     *
     * An existing session means this request did not create it, whatever the
     * flag says, so it is an ordinary hit and must be counted.
     */
    public function testSecondPageviewCarryingTheNewSessionFlagIsStillCounted(): void
    {
        $site_id    = md5('owa-test-site');
        $session_id = $this->uniqueSessionId();
        $this->trackForCleanup('base.session', $session_id, 'id');

        $this->setServerTime(1700000000);
        $this->firePageRequest($site_id, $session_id, [
            'is_new_session'       => true,
            'is_new_session_start' => true,
        ]);

        $opened = $this->assertRowPersisted('base.session', $session_id, 'id');
        $this->assertEquals(1, $opened->get('num_pageviews'));

        // Second pageview, same page load: still page-scoped is_new_session,
        // but NOT is_new_session_start -- that one belongs to the request that
        // created the session and rides a single beacon.
        $this->setServerTime(1700000060);
        $this->firePageRequest($site_id, $session_id, [
            'is_new_session' => true,
        ]);

        $updated = owa_coreAPI::entityFactory('base.session');
        $updated->load($session_id, 'id');
        $this->assertTrue($updated->wasPersisted());
        $this->assertEquals(2, $updated->get('num_pageviews'));
        $this->assertEquals(0, $updated->get('is_bounce'));
        $this->assertEquals(1700000060, $updated->get('last_req'));
    }

    /**
     * A tracker cached from before the flags were split sends only the
     * page-scoped one. It must still be able to open a session.
     */
    public function testOlderTrackerWithoutTheStartFlagStillOpensASession(): void
    {
        $site_id    = md5('owa-test-site');
        $session_id = $this->uniqueSessionId();
        $this->trackForCleanup('base.session', $session_id, 'id');

        $this->setServerTime(1700000000);
        $this->firePageRequest($site_id, $session_id, [
            'is_new_session' => true,
        ]);

        $s = $this->assertRowPersisted('base.session', $session_id, 'id');
        $this->assertEquals(1, $s->get('num_pageviews'));
    }

    /**
     * days_since_prior_session is derived on the server by subtracting two
     * DATES the tracker sends: when the previous session began, and when this
     * one did.
     *
     * Both are YYYYMMDD in the visitor's own calendar, so the subtraction stays
     * inside one calendar -- measuring to the server's date instead mixes two
     * and can be a day out at the edges. Coarsening is what limits the damage
     * an uncontrolled clock can do: only an error crossing midnight costs
     * anything, and only ever a day.
     *
     * Measuring to the SESSION's date, not the event's, is what keeps the value
     * identical on every event sharing a session_id -- see the test below.
     */
    private function fireWithAnchors(string $session_id, int $at, ?int $prior, ?int $session, array $extra = []): string
    {
        $props = array_merge([
            'is_new_session'       => true,
            'is_new_session_start' => true,
        ], $extra);

        // The RAW anchors, as the tracker stores them. The server converts each
        // to YYYYMMDD and does the arithmetic at day level.
        if ($prior !== null)   { $props['psts'] = $prior; }
        if ($session !== null) { $props['sts']  = $session; }

        $this->trackForCleanup('base.session', $session_id, 'id');
        $this->setServerTime($at);

        return $this->firePageRequest(md5('owa-test-site'), $session_id, $props);
    }

    public function testDaysSincePriorSessionIsTheDifferenceOfTheTwoDates(): void
    {
        $now = 1700000000;

        $guid = $this->fireWithAnchors(
            $this->uniqueSessionId(),
            $now,
            $now - (5 * 86400),
            $now
        );

        $r = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertEquals(5, $r->get('days_since_prior_session'));
    }

    public function testAReturnVisitTheSameDayIsZeroDays(): void
    {
        // Two hours apart inside one day. An elapsed calculation rounding to
        // days would also give 0 here, but a visit at 23:00 returning at 01:00
        // is 1 -- boundaries, not duration.
        $now = 1700000000;

        $guid = $this->fireWithAnchors($this->uniqueSessionId(), $now, $now - 7200, $now);

        $r = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertEquals(0, $r->get('days_since_prior_session'));
    }

    public function testTwoHoursSpanningMidnightIsOneDay(): void
    {
        // The case the whole day-level conversion exists for, and the only one
        // here that tells calendar days from elapsed days: an elapsed
        // calculation returns round(7200/86400) = 0.
        $midnight = strtotime(date('Y-m-d', 1700000000));

        $guid = $this->fireWithAnchors(
            $this->uniqueSessionId(),
            $midnight + 3600,
            $midnight - 3600,      // 23:00 the day before
            $midnight + 3600       // 01:00 today
        );

        $r = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertEquals(1, $r->get('days_since_prior_session'));
    }

    public function testTwentyTwoHoursInsideOneDayIsNoDays(): void
    {
        // The converse, where an elapsed calculation returns 1.
        $midnight = strtotime(date('Y-m-d', 1700000000));

        $guid = $this->fireWithAnchors(
            $this->uniqueSessionId(),
            $midnight + (23 * 3600),
            $midnight + 3600,          // 01:00
            $midnight + (23 * 3600)    // 23:00 the same day
        );

        $r = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertEquals(0, $r->get('days_since_prior_session'));
    }

    public function testEveryEventSharingASessionAgrees(): void
    {
        // The invariant. The day count is measured to the SESSION's date, which
        // every event of the session carries, so two hits either side of a
        // midnight still report the same number. Measuring to each event's own
        // date would not.
        $site_id    = md5('owa-test-site');
        $session_id = $this->uniqueSessionId();
        $this->trackForCleanup('base.session', $session_id, 'id');

        $midnight     = strtotime(date('Y-m-d', 1700000000));
        $sessionStart = $midnight - 3600;                      // session began at 23:00
        $priorStart   = $midnight - (3 * 86400);

        $this->setServerTime($sessionStart);
        $first = $this->firePageRequest($site_id, $session_id, [
            'is_new_session'       => true,
            'is_new_session_start' => true,
            'psts'                 => $priorStart,
            'sts'                  => $sessionStart,
        ]);

        $this->setServerTime($midnight + 3600);                // 01:00, past midnight
        $second = $this->firePageRequest($site_id, $session_id, [
            'psts' => $priorStart,
            'sts'  => $sessionStart,
        ]);

        $a = $this->assertRowPersisted('base.request', $first, 'id');
        $b = $this->assertRowPersisted('base.request', $second, 'id');

        $this->assertEquals(
            $a->get('days_since_prior_session'),
            $b->get('days_since_prior_session'),
            'every event sharing a session_id must report the same value'
        );
    }

    /**
     * days_since_first_session works the same way: two dates, subtracted at day
     * level. The anchor is the visitor's first-visit date, coarse because it is
     * stamped by their clock; the other end is this session's date, so the value
     * is fixed for the visit rather than ticking over at midnight.
     */
    public function testDaysSinceFirstSessionIsCountedFromTheDateSent(): void
    {
        $now = 1700000000;

        $guid = $this->fireWithAnchors($this->uniqueSessionId(), $now, null, $now, [
            'fsts' => $now - (10 * 86400),
        ]);

        $r = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertEquals(10, $r->get('days_since_first_session'));
    }

    public function testAFirstVisitTodayIsZeroDays(): void
    {
        $now = 1700000000;

        $guid = $this->fireWithAnchors($this->uniqueSessionId(), $now, null, $now, [
            'fsts' => $now,
        ]);

        $r = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertEquals(0, $r->get('days_since_first_session'));
    }

    public function testAnOlderTrackerSendingDsfsStillHasItHonoured(): void
    {
        // No date, so the value it sent as 'dsfs' stands.
        $guid = $this->fireWithAnchors($this->uniqueSessionId(), 1700000000, null, null, ['dsfs' => 7]);

        $r = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertEquals(7, $r->get('days_since_first_session'));
    }

    public function testAMalformedDateFallsBackRatherThanGuessing(): void
    {
        // Also the regression test for setDataType()'s integer branch, which
        // threw a TypeError on non-numeric input from an unauthenticated beacon.
        $guid = $this->fireWithAnchors($this->uniqueSessionId(), 1700000000, null, null, [
            'fsts' => 'not-a-timestamp',
            'dsfs' => 3,
        ]);

        $r = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertEquals(3, $r->get('days_since_first_session'));
    }

    public function testAnOlderTrackerSendingDspsStillHasItHonoured(): void
    {
        // No interval, so the value it sent as 'dsps' stands.
        $guid = $this->fireWithAnchors($this->uniqueSessionId(), 1700000000, null, null, ['dsps' => 4]);

        $r = $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertEquals(4, $r->get('days_since_prior_session'));
    }

    public function testNewSessionRecordsCampaignAttribution(): void
    {
        $this->assertFieldsInContract('base.page_request.campaign', [
            'session_id', 'is_new_session', 'tagged_campaign', 'tagged_source', 'tagged_medium', 'attribs',
        ]);

        $site_id    = md5('owa-test-site');
        $session_id = $this->uniqueSessionId();
        $this->trackForCleanup('base.session', $session_id, 'id');

        $campaign = 'sess_campaign_' . $session_id;
        $source   = 'sess_source_' . $session_id;
        $this->trackForCleanup('base.campaign_dim', $campaign, 'name');
        $this->trackForCleanup('base.source_dim', $source, 'source_domain');

        // The tracker emits `attribs` as a JSON array of campaign touches; the
        // handler stores it verbatim in latest_attributions.
        $attribs = '[{"campaign":"' . $campaign . '","source":"' . $source . '","medium":"cpc"}]';

        $this->setServerTime(1700000000);
        $this->firePageRequest($site_id, $session_id, [
            'is_new_session' => true,
            'tagged_campaign' => $campaign,
            'tagged_source'  => $source,
            'tagged_medium'  => 'cpc',
            'attribs'        => $attribs,
        ]);

        $s = $this->assertRowPersisted('base.session', $session_id, 'id');

        // Beacon-supplied medium is denormalized onto the session row.
        $this->assertSame('cpc', (string) $s->get('medium'), 'session medium not set from the campaign beacon.');

        // The touch-history JSON is stored verbatim.
        $this->assertSame(
            $attribs,
            (string) $s->get('latest_attributions'),
            'session latest_attributions did not capture the campaign touch history.'
        );

        // Follow the campaign_id FK to the campaign_dim row it references and
        // assert that row is the campaign this session was opened with. (Resolve
        // by FK id, not content: the association-test pattern, robust to the
        // derived-FK machinery and any duplicate content rows.)
        $campaign_fk = (string) $s->get('campaign_id');
        $this->assertNotSame('', $campaign_fk, 'session campaign_id FK is empty — not linked to a campaign.');
        $camp = owa_coreAPI::entityFactory('base.campaign_dim');
        $camp->load($campaign_fk, 'id');
        $this->assertTrue($camp->wasPersisted(), 'session campaign_id points at a campaign_dim row that does not exist.');
        $this->assertSame($campaign, (string) $camp->get('name'), 'session is linked to the wrong campaign.');
    }

    /**
     * Last-touch re-attribution: in the default 'direct' attribution mode a
     * later request in an active session that carries NEW campaign params
     * re-attributes the session. logSessionUpdate() overwrites medium /
     * source_id / campaign_id and refreshes latest_attributions (only when the
     * event actually supplies them), so the session reflects the most recent
     * campaign touch rather than the one that opened it.
     */
    public function testSessionUpdateReattributesToNewCampaign(): void
    {
        $site_id    = md5('owa-test-site');
        $session_id = $this->uniqueSessionId();
        $this->trackForCleanup('base.session', $session_id, 'id');

        // Request 1 at T0: opens the session under an initial campaign.
        $campaign1 = 'sess_first_' . $session_id;
        $source1   = 'src_first_' . $session_id;
        $this->trackForCleanup('base.campaign_dim', $campaign1, 'name');
        $this->trackForCleanup('base.source_dim', $source1, 'source_domain');

        $this->setServerTime(1700000000);
        $this->firePageRequest($site_id, $session_id, [
            'is_new_session' => true,
            'tagged_campaign' => $campaign1,
            'tagged_source'  => $source1,
            'tagged_medium'  => 'email',
        ]);

        $opened = $this->assertRowPersisted('base.session', $session_id, 'id');
        $this->assertSame('email', (string) $opened->get('medium'), 'opening session medium not set.');

        // Request 2 at T0+60: same active session (no is_new_session), but a NEW
        // campaign touch. Last-touch attribution should overwrite the session.
        $campaign2 = 'sess_second_' . $session_id;
        $source2   = 'src_second_' . $session_id;
        $this->trackForCleanup('base.campaign_dim', $campaign2, 'name');
        $this->trackForCleanup('base.source_dim', $source2, 'source_domain');
        $attribs2 = '[{"campaign":"' . $campaign2 . '","source":"' . $source2 . '","medium":"cpc"}]';

        $this->setServerTime(1700000060);
        $this->firePageRequest($site_id, $session_id, [
            'tagged_campaign' => $campaign2,
            'tagged_source' => $source2,
            'tagged_medium' => 'cpc',
            'attribs'  => $attribs2,
        ]);

        $updated = owa_coreAPI::entityFactory('base.session');
        $updated->load($session_id, 'id');
        $this->assertTrue($updated->wasPersisted());

        // Medium re-attributed to the latest touch.
        $this->assertSame('cpc', (string) $updated->get('medium'), 'session medium was not re-attributed to the newer campaign.');

        // latest_attributions refreshed to the newer touch history.
        $this->assertSame(
            $attribs2,
            (string) $updated->get('latest_attributions'),
            'session latest_attributions was not refreshed on re-attribution.'
        );

        // campaign_id FK now points at the SECOND campaign.
        $campaign_fk = (string) $updated->get('campaign_id');
        $camp = owa_coreAPI::entityFactory('base.campaign_dim');
        $camp->load($campaign_fk, 'id');
        $this->assertTrue($camp->wasPersisted(), 're-attributed campaign_id points at a missing campaign_dim row.');
        $this->assertSame($campaign2, (string) $camp->get('name'), 'session was not re-attributed to the newer campaign.');
    }
}
