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
    public function testNewSessionRecordsCampaignAttribution(): void
    {
        $this->assertFieldsInContract('base.page_request.campaign', [
            'session_id', 'is_new_session', 'campaign', 'source', 'medium', 'attribs',
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
            'campaign'       => $campaign,
            'source'         => $source,
            'medium'         => 'cpc',
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
            'campaign'       => $campaign1,
            'source'         => $source1,
            'medium'         => 'email',
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
            'campaign' => $campaign2,
            'source'   => $source2,
            'medium'   => 'cpc',
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
