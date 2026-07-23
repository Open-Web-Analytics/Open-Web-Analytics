<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Beacon-contract test for the DIMENSION fan-out a base.page_request triggers.
 *
 * A primary event (base.page_request) persists its owa_request fact row and
 * then re-dispatches base.page_request_logged. That second dispatch runs the
 * $standard_dimension_handlers + documentHandlers registered in
 * modules/base/module.php, each of which upserts an associated dimension
 * entity. This test fires the REALISTIC new-session beacons the tracker JS
 * sends and asserts every dimension that pageview should create actually gets
 * created, so a refactor of the dispatch/handler/entity layer can't silently
 * stop populating dimensions.
 *
 * Two beacons, because a referred first pageview comes in one of two mutually
 * exclusive tracker shapes (see tests/js/BeaconContractReferred.test.js and the
 * base.page_request.referral / base.page_request.campaign fixture entries):
 *
 *  - REFERRAL beacon (has session_referer, no campaign): the server derives
 *    source/medium/referer_id from the referrer, so this drives the referer,
 *    source and (for a search-engine referrer) search_term dimensions, plus
 *    document, ua, visitor.
 *
 *  - CAMPAIGN beacon (owa_* params -> campaign/source/medium/search_terms, no
 *    session_referer): drives the campaign, source and search_term dimensions
 *    directly from the beacon, plus document, ua, visitor.
 *
 * Between them they exercise every dimension a pageview creates: document, ua,
 * referer, source, search_term, campaign, visitor (unique-per-run, asserted by
 * content and cleaned up) and host, location (shared '(not set)' defaults in
 * this environment, asserted for existence and left in place).
 *
 * Anchor by CONTENT, not by the fact's FK column. Each handler keys its row on
 * the content of its source property (document->url, ua->ua, referer->url,
 * source->source_domain, search_term->terms, campaign->name, visitor->id), so
 * loading the dimension back by that content column is a stable, process-
 * independent assertion. We deliberately do NOT read the fact row's *_id FK
 * columns: those are derived by chained property filters OWA re-registers on
 * every logEvent() call, so in a process that logs more than one event (this
 * suite, or a queue/batch worker) the FK is hashed once per event logged so far
 * and no longer equals the id the handler actually wrote the row at. The row is
 * still correct; only the fact's FK drifts. (Production logs one event per
 * process, so the FK is correct there.)
 *
 * Anti-drift: every field each beacon feeds a handler is checked against its
 * matching contract fixture entry via assertFieldsInContract, so a tracker-side
 * rename/drop of a dimension-driving field can't pass silently. (HTTP_USER_AGENT
 * is server-populated from request headers, not the beacon payload, so it is
 * excluded from that check.)
 *
 * The os dimension is exercised by its own case below. os is resolved
 * server-side by browscap (the ua-parser library) from the request's
 * user-agent, so it needs a REAL UA to resolve (a synthetic UA yields no os
 * family and no row) and the UA must be driven on the server param, not just
 * the event property — see testRealUserAgentPopulatesOsDimension.
 */
final class DimensionIngestionTest extends IngestionTestCase
{
    public function testReferralPageviewPopulatesRefererSourceAndCoreDimensions(): void
    {
        // Fields this beacon feeds handlers must be ones the tracker emits for
        // the referral shape.
        $this->assertFieldsInContract('base.page_request.referral', [
            'page_url', 'session_referer', 'visitor_id', 'is_new_session', 'is_new_visitor',
        ]);

        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();

        // Unique referrer host so the server-derived source (the host,
        // www-stripped) and the referer row are unique per run. A NON-search
        // engine referrer => medium 'referral' and search_terms falls back to
        // the shared '(not set)' row.
        $page_url   = 'https://example.com/dim-referral/' . $guid;
        $user_agent = 'OWA-DimTest/1.0 (+referral; run=' . $guid . ')';
        $referer    = 'https://ref' . $guid . '.example.net/landing';

        $this->trackForCleanup('base.request', $guid, 'id');
        $this->trackForCleanup('base.document', $page_url, 'url');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');
        $this->trackForCleanup('base.referer', $referer, 'url');
        $this->trackForCleanup('base.visitor', $visitor_id, 'id');

        $result = $this->fireEvent('base.page_request', [
            'guid'            => $guid,
            'site_id'         => $site_id,
            'session_id'      => $session_id,
            'page_url'        => $page_url,
            'HTTP_USER_AGENT' => $user_agent,
            'is_new_session'  => true,
            'is_new_visitor'  => true,
            'visitor_id'      => $visitor_id,
            'HTTP_REFERER'    => $referer,
            'session_referer' => $referer,
        ]);
        $this->assertNotFalse($result, 'referral page_request was dropped before persistence.');

        // The server derived `source` from the referrer host — register that
        // unique row for cleanup and assert it later.
        $derived_source = (string) $this->lastEvent()->get('source');
        $this->assertNotEmpty($derived_source, 'server did not derive a source from the referrer.');
        $this->assertStringContainsString(
            $guid,
            $derived_source,
            'derived source is not the unique referrer host — beacon/derivation drift.'
        );
        $this->trackForCleanup('base.source_dim', $derived_source, 'source_domain');

        $this->assertRowPersisted('base.request', $guid, 'id');

        // Dimensions this event authored (unique content).
        $this->assertContentRow('base.document', 'url', $page_url, $guid);
        $this->assertContentRow('base.ua', 'ua', $user_agent, $guid);
        $this->assertContentRow('base.referer', 'url', $referer, $guid);
        $this->assertContentRow('base.source_dim', 'source_domain', $derived_source, $guid);

        // visitor: keyed on the tracker-minted visitor_id, passed through verbatim.
        $vis = $this->assertRowPersisted('base.visitor', $visitor_id, 'id');
        $this->assertEquals($session_id, $vis->get('first_session_id'));

        // Content-shared defaults (assert existence, do not clean).
        $this->assertSharedDimensionRowExists('base.host', 'host', '(not set)');
        $this->assertSharedDimensionRowExists('base.location_dim', 'country', '(not set)');
    }

    public function testCampaignPageviewPopulatesCampaignSourceAndSearchDimensions(): void
    {
        $this->assertFieldsInContract('base.page_request.campaign', [
            'page_url', 'campaign', 'source', 'medium', 'search_terms',
            'visitor_id', 'is_new_session', 'is_new_visitor',
        ]);

        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();

        // Campaign attribution: the beacon carries campaign/source/medium/
        // search_terms directly (no referrer inference), so these dimensions are
        // driven straight from the beacon. Unique-per-run values so each row is
        // authored by THIS event.
        $page_url    = 'https://example.com/dim-campaign/' . $guid;
        $user_agent  = 'OWA-DimTest/1.0 (+campaign; run=' . $guid . ')';
        $campaign    = 'dim_campaign_' . $guid;
        $source      = 'dim_source_' . $guid;
        $search_term = 'dim terms ' . $guid;

        $this->trackForCleanup('base.request', $guid, 'id');
        $this->trackForCleanup('base.document', $page_url, 'url');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');
        $this->trackForCleanup('base.campaign_dim', $campaign, 'name');
        $this->trackForCleanup('base.source_dim', $source, 'source_domain');
        $this->trackForCleanup('base.search_term_dim', $search_term, 'terms');
        $this->trackForCleanup('base.visitor', $visitor_id, 'id');

        $result = $this->fireEvent('base.page_request', [
            'guid'            => $guid,
            'site_id'         => $site_id,
            'session_id'      => $session_id,
            'page_url'        => $page_url,
            'HTTP_USER_AGENT' => $user_agent,
            'is_new_session'  => true,
            'is_new_visitor'  => true,
            'visitor_id'      => $visitor_id,
            'campaign'        => $campaign,
            'source'          => $source,
            'medium'          => 'email',
            'search_terms'    => $search_term,
        ]);
        $this->assertNotFalse($result, 'campaign page_request was dropped before persistence.');

        $this->assertRowPersisted('base.request', $guid, 'id');

        // Dimensions this event authored (unique content).
        $this->assertContentRow('base.document', 'url', $page_url, $guid);
        $this->assertContentRow('base.ua', 'ua', $user_agent, $guid);
        $this->assertContentRow('base.campaign_dim', 'name', $campaign, $guid);
        $this->assertContentRow('base.source_dim', 'source_domain', $source, $guid);
        $this->assertContentRow('base.search_term_dim', 'terms', $search_term, $guid);

        $vis = $this->assertRowPersisted('base.visitor', $visitor_id, 'id');
        $this->assertEquals($session_id, $vis->get('first_session_id'));
    }

    public function testRealUserAgentPopulatesOsDimension(): void
    {
        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();
        $page_url   = 'https://example.com/dim-os/' . $guid;

        // A REAL user-agent, so browscap resolves an os family. The unique
        // trailing comment keeps the ua string (and thus the owa_ua row) unique
        // per run without disturbing the "Windows NT 10.0" token os detection
        // keys on — so we still get exactly one shared os row: 'Windows'.
        $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OWAtest/' . $guid;

        // Drive the SERVER UA — resolveOs reads the request server param, not the
        // event property. tearDown restores it and resets the memoized browscap.
        $this->setServerUserAgent($user_agent);

        $this->trackForCleanup('base.request', $guid, 'id');
        $this->trackForCleanup('base.document', $page_url, 'url');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');
        $this->trackForCleanup('base.visitor', $visitor_id, 'id');

        $result = $this->fireEvent('base.page_request', [
            'guid'            => $guid,
            'site_id'         => $site_id,
            'session_id'      => $session_id,
            'page_url'        => $page_url,
            'HTTP_USER_AGENT' => $user_agent,
            'is_new_session'  => true,
            'is_new_visitor'  => true,
            'visitor_id'      => $visitor_id,
        ]);
        $this->assertNotFalse($result, 'os page_request was dropped before persistence.');

        // The server derived the os family from the UA.
        $this->assertSame(
            'Windows',
            (string) $this->lastEvent()->get('os'),
            'browscap did not resolve os=Windows from a real Windows UA — is the ua-parser data present?'
        );

        $this->assertRowPersisted('base.request', $guid, 'id');
        // The unique ua row this event authored.
        $this->assertContentRow('base.ua', 'ua', $user_agent, $guid);

        // os is a shared, content-keyed dimension (a small fixed set of real OS
        // families, like host/location '(not set)') — assert existence, leave it.
        $this->assertSharedDimensionRowExists('base.os', 'name', 'Windows');
    }

    /**
     * Assert a dimension row exists for the given unique content value and that
     * the value round-tripped intact (carries the per-run guid that proves this
     * event authored it).
     */
    private function assertContentRow(string $entity, string $col, string $value, string $guid): void
    {
        $row = $this->assertRowPersisted($entity, $value, $col);
        $this->assertStringContainsString(
            $guid,
            (string) $row->get($col),
            "{$entity}.{$col} does not carry the unique guid from our beacon."
        );
    }

    /**
     * Assert that a dimension row with the given content value exists (existence
     * only — used for content-shared rows we must not delete).
     */
    private function assertSharedDimensionRowExists(string $entity, string $col, string $value): void
    {
        $row = owa_coreAPI::entityFactory($entity);
        $row->load($value, $col);
        $this->assertTrue(
            $row->wasPersisted(),
            "Expected the pageview fan-out to leave a {$entity} row with {$col}='{$value}', but none exists."
        );
    }
}
