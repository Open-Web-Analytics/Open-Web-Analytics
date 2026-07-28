<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Ingestion test for the VISITOR fan-out a first pageview triggers.
 *
 * base.page_request -> owa_requestHandlers -> base.page_request_logged ->
 * (session fan-out) -> base.new_session -> owa_visitorHandlers, which upserts a
 * base.visitor row keyed on the tracker-minted visitor_id. The visitor is
 * anchored by first-touch: visitorHandlers seeds first_session_id /
 * first_session_timestamp from the opening session and is create-if-absent, so
 * later sessions for the same visitor must NOT rewrite those first-touch fields.
 *
 * visitor_id is passed through verbatim (not a content-hashed dimension), so it
 * must be a unique NUMERIC id in the tracker's generateRandomGuid() format — the
 * id column is BIGINT (see IngestionTestCase::uniqueGuid).
 */
final class VisitorIngestionTest extends IngestionTestCase
{
    /**
     * Fire one new-session page_request (which drives the visitor fan-out) and
     * register the request row for cleanup. Returns the request guid.
     *
     * @param array<string, mixed> $extra additional/overriding event properties
     */
    private function fireNewSessionPageRequest(string $site_id, string $session_id, string $visitor_id, array $extra = []): string
    {
        $guid = $this->uniqueGuid();
        $this->trackForCleanup('base.request', $guid, 'id');

        $props = array_merge([
            'guid'           => $guid,
            'site_id'        => $site_id,
            'session_id'     => $session_id,
            'visitor_id'     => $visitor_id,
            'page_url'       => 'https://example.com/visitor-test',
            'is_new_session' => true,
            'is_new_visitor' => true,
        ], $extra);

        $result = $this->fireEvent('base.page_request', $props);
        $this->assertNotFalse($result, 'page_request was dropped before persistence.');
        return $guid;
    }

    public function testFirstPageviewCreatesVisitorRow(): void
    {
        $this->assertFieldsInContract(
            'base.page_request',
            ['session_id', 'visitor_id', 'is_new_session', 'is_new_visitor']
        );

        $site_id    = md5('owa-test-site');
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();
        $this->trackForCleanup('base.session', $session_id, 'id');
        $this->trackForCleanup('base.visitor', $visitor_id, 'id');

        $this->setServerTime(1700000000);
        $this->fireNewSessionPageRequest($site_id, $session_id, $visitor_id);

        $v = $this->assertRowPersisted('base.visitor', $visitor_id, 'id');
        // visitor_id is passed through verbatim as the PK.
        $this->assertEquals($visitor_id, $v->get('id'), 'visitor id was not the tracker-minted visitor_id.');
        // First-touch: the visitor is anchored to the opening session.
        $this->assertEquals($session_id, $v->get('first_session_id'), 'visitor first_session_id not seeded from the opening session.');
        $this->assertEquals(1700000000, $v->get('first_session_timestamp'), 'visitor first_session_timestamp not seeded from the opening request.');
    }

    /**
     * visitorHandlers is create-if-absent: a later session for the SAME visitor
     * must not rewrite the first-touch fields. This proves the visitor's
     * first_session_id/timestamp remain anchored to the ORIGINAL session even
     * after a second, later session comes in.
     */
    public function testSecondSessionDoesNotOverwriteFirstTouch(): void
    {
        $site_id    = md5('owa-test-site');
        $visitor_id = $this->uniqueGuid();
        $this->trackForCleanup('base.visitor', $visitor_id, 'id');

        // Session 1 at T0: creates the visitor, anchoring first-touch here.
        $session1 = $this->uniqueSessionId();
        $this->trackForCleanup('base.session', $session1, 'id');
        $this->setServerTime(1700000000);
        $this->fireNewSessionPageRequest($site_id, $session1, $visitor_id);

        $first = $this->assertRowPersisted('base.visitor', $visitor_id, 'id');
        $this->assertEquals($session1, $first->get('first_session_id'));
        $this->assertEquals(1700000000, $first->get('first_session_timestamp'));

        // Session 2 much later, same visitor. The visitor already exists, so
        // visitorHandlers takes the "already exists" branch and leaves the
        // first-touch fields untouched.
        $session2 = $this->uniqueSessionId();
        $this->trackForCleanup('base.session', $session2, 'id');
        $this->setServerTime(1700086400); // +1 day
        $this->fireNewSessionPageRequest($site_id, $session2, $visitor_id);

        $reloaded = owa_coreAPI::entityFactory('base.visitor');
        $reloaded->load($visitor_id, 'id');
        $this->assertTrue($reloaded->wasPersisted());
        // First-touch STILL points at the original session, not the later one.
        $this->assertEquals(
            $session1,
            $reloaded->get('first_session_id'),
            'a returning visitor should keep its ORIGINAL first_session_id.'
        );
        $this->assertEquals(
            1700000000,
            $reloaded->get('first_session_timestamp'),
            'a returning visitor should keep its ORIGINAL first_session_timestamp.'
        );
    }
}
