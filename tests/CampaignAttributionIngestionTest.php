<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Ingestion tests for CAMPAIGN ATTRIBUTION — the paths DimensionIngestionTest's
 * single campaign case does not exercise.
 *
 * DimensionIngestionTest already covers a "full" campaign beacon
 * (campaign/source/medium=email/search_terms populate campaign_dim/source_dim/
 * search_term_dim). This class covers the rest of the attribution surface:
 *
 *  - ad / ad_type -> ad_dim (a paid-click campaign), plus name/type lowercasing.
 *  - Server-derived medium from a REFERRER: a search-engine referrer yields
 *    medium 'organic-search' and the extracted search term; a plain referrer
 *    yields medium 'referral'. These prove deriveMedium/extractSearchTerm, which
 *    only run when the beacon did NOT already supply a medium/term.
 *  - The 'direct' medium default: a new-session pageview with neither campaign
 *    params nor a referrer falls to medium 'direct'.
 *
 * Server derivation (deriveSource/deriveMedium/extractSearchTerm) respects any
 * value the tracker already put on the beacon (campaign mode) and only
 * synthesizes from session_referer when the beacon left the field empty
 * (referral mode). Both branches are checked here.
 *
 * Dimension rows are anchored by CONTENT (ad_dim.name, campaign_dim.name), not
 * by the fact FK, for the same reason as DimensionIngestionTest: the fact's *_id
 * columns are derived by chained filters and the row is what a handler actually
 * keys on its content. Derived medium/search_term are read off the fired event
 * (lastEvent) since medium has no dedicated dimension entity — it is a fact
 * column / report dimension only.
 */
final class CampaignAttributionIngestionTest extends IngestionTestCase
{
    /**
     * A paid-click campaign: owa_ad / owa_ad_type ride the beacon as ad/ad_type
     * and drive the ad_dim dimension (name + type, both lowercased). This is the
     * dimension DimensionIngestionTest's campaign case omits.
     */
    public function testPaidClickPopulatesAdDimension(): void
    {
        $this->assertFieldsInContract('base.page_request.campaign', [
            'page_url', 'campaign', 'source', 'medium',
            'visitor_id', 'is_new_session', 'is_new_visitor',
        ]);

        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();

        $page_url   = 'https://example.com/camp-ad/' . $guid;
        $user_agent = 'OWA-DimTest/1.0 (+ad; run=' . $guid . ')';
        $campaign   = 'camp_ad_' . $guid;
        $source     = 'camp_src_' . $guid;
        // Mixed case proves the ad handler lowercases both name and type.
        $ad         = 'Ad_Creative_' . $guid;
        $ad_type    = 'CPC';

        $this->trackForCleanup('base.request', $guid, 'id');
        $this->trackForCleanup('base.document', $page_url, 'url');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');
        $this->trackForCleanup('base.campaign_dim', $campaign, 'name');
        $this->trackForCleanup('base.source_dim', $source, 'source_domain');
        $this->trackForCleanup('base.ad_dim', strtolower($ad), 'name');

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
            'medium'          => 'cpc',
            'ad'              => $ad,
            'ad_type'         => $ad_type,
        ]);
        $this->assertNotFalse($result, 'paid-click page_request was dropped before persistence.');

        // Beacon-supplied medium passes through deriveMedium untouched.
        $this->assertSame('cpc', (string) $this->lastEvent()->get('medium'), 'beacon-supplied medium was not respected.');

        $this->assertRowPersisted('base.request', $guid, 'id');
        $this->assertRowPersisted('base.campaign_dim', $campaign, 'name');

        // ad_dim: keyed on lowercased ad name; type also lowercased.
        $ad_row = $this->assertRowPersisted('base.ad_dim', strtolower($ad), 'name');
        $this->assertSame(strtolower($ad),      (string) $ad_row->get('name'), 'ad_dim name should be the lowercased ad.');
        $this->assertSame(strtolower($ad_type), (string) $ad_row->get('type'), 'ad_dim type should be the lowercased ad_type.');
    }

    /**
     * A search-engine referrer (no campaign params, no beacon medium): the
     * server derives medium 'organic-search' and extracts the search term from
     * the engine's query param.
     */
    public function testSearchEngineReferrerDerivesOrganicSearchMediumAndTerm(): void
    {
        $this->assertFieldsInContract('base.page_request.referral', [
            'page_url', 'session_referer', 'visitor_id', 'is_new_session', 'is_new_visitor',
        ]);

        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();

        $page_url   = 'https://example.com/camp-organic/' . $guid;
        $user_agent = 'OWA-DimTest/1.0 (+organic; run=' . $guid . ')';
        // A real search-engine host (matches the 'google' entry, query_param 'q')
        // so isSearchEngine() fires and the term is pulled from ?q=.
        $term       = 'owa organic ' . $guid;
        $referer    = 'https://www.google.com/search?q=' . rawurlencode($term);

        $this->trackForCleanup('base.request', $guid, 'id');
        $this->trackForCleanup('base.document', $page_url, 'url');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');
        $this->trackForCleanup('base.referer', $referer, 'url');
        // Derived source is the www-stripped host: 'google.com'. Shared across
        // runs, so assert existence only and do not clean it.

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
        $this->assertNotFalse($result, 'organic-search page_request was dropped before persistence.');

        $event = $this->lastEvent();
        $this->assertSame(
            'organic-search',
            (string) $event->get('medium'),
            'server did not derive medium=organic-search from a search-engine referrer.'
        );
        // The term is urldecoded, trimmed and lowercased by extractSearchTerm.
        $this->assertSame(
            strtolower($term),
            (string) $event->get('search_terms'),
            'server did not extract the search term from the engine query param.'
        );
        // Source derived from the referrer host (www stripped).
        $this->assertSame('google.com', (string) $event->get('source'), 'server did not derive source from the referrer host.');

        $this->assertRowPersisted('base.request', $guid, 'id');
        // The extracted term becomes a search_term_dim row (keyed on lowercased terms).
        $this->assertRowPersisted('base.search_term_dim', strtolower($term), 'terms');
    }

    /**
     * A plain (non-search-engine, non-social) referrer derives medium
     * 'referral' — the deriveMedium default branch when a referrer is present.
     */
    public function testPlainReferrerDerivesReferralMedium(): void
    {
        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();

        $page_url   = 'https://example.com/camp-referral/' . $guid;
        $user_agent = 'OWA-DimTest/1.0 (+referral-medium; run=' . $guid . ')';
        $referer    = 'https://blog' . $guid . '.example.net/post';

        $this->trackForCleanup('base.request', $guid, 'id');
        $this->trackForCleanup('base.document', $page_url, 'url');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');
        $this->trackForCleanup('base.referer', $referer, 'url');

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
        $this->assertNotFalse($result, 'referral-medium page_request was dropped before persistence.');

        $this->assertSame(
            'referral',
            (string) $this->lastEvent()->get('medium'),
            'server did not derive medium=referral from a plain referrer.'
        );
        $this->assertRowPersisted('base.request', $guid, 'id');
    }

    /**
     * A new-session pageview with neither campaign params nor a referrer falls
     * to the 'direct' medium default (deriveMedium returns nothing, so the
     * property default_value applies).
     */
    public function testDirectVisitDefaultsToDirectMedium(): void
    {
        $this->assertFieldsInContract('base.page_request', [
            'page_url', 'visitor_id', 'is_new_session', 'is_new_visitor',
        ]);

        $site_id    = md5('owa-test-site');
        $guid       = $this->uniqueGuid();
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();

        $page_url   = 'https://example.com/camp-direct/' . $guid;
        $user_agent = 'OWA-DimTest/1.0 (+direct; run=' . $guid . ')';

        $this->trackForCleanup('base.request', $guid, 'id');
        $this->trackForCleanup('base.document', $page_url, 'url');
        $this->trackForCleanup('base.ua', $user_agent, 'ua');

        // No session_referer, no campaign params: a direct visit.
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
        $this->assertNotFalse($result, 'direct page_request was dropped before persistence.');

        $this->assertSame(
            'direct',
            (string) $this->lastEvent()->get('medium'),
            'a visit with no campaign and no referrer should default to medium=direct.'
        );
        $this->assertRowPersisted('base.request', $guid, 'id');
    }
}
