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
}
