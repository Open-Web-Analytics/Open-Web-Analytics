<?php

require_once(__DIR__ . '/RestControllerTestCase.php');

/**
 * Contract + auth tests for the reports REST endpoint:
 *
 *   GET /owa/api/base/v1/reports/{report_name}  -> owa_reportsRestController (view_reports)
 *
 * The controller has two modes:
 *   - no report_name: a generic resultSet query, which REQUIRES `metrics`.
 *   - a report_name:  a canned report, each with its own required params
 *     (e.g. visit/clickstream require sessionId).
 *
 * success() -> 201 (base.reportsRest); errorAction()/validation -> 422 (base.restApi).
 */
final class ReportsRestControllerTest extends RestControllerTestCase
{
    public function testReportsRejectsUnauthenticated(): void
    {
        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today']
        );

        $this->assertNotAuthenticated($resp, 'GET /reports');
    }

    public function testResultSetQueryRequiresMetrics(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['period' => 'today'] // no metrics, no report_name
        );

        $this->assertSame(422, $resp['status'],
            'A resultSet query without metrics should fail validation with 422.');
        $this->assertSame('base.restApi', $resp['view'],
            'A validation failure routes to the restApi error view.');
    }

    public function testResultSetQueryReturnsResults(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today']
        );

        $this->assertSame(201, $resp['status'],
            'A valid metrics query should return 201.');
        $this->assertSame('base.reportsRest', $resp['view']);
        $this->assertIsArray($resp['data'],
            'A resultSet response payload should be an array (the serialized result set).');
    }

    public function testInvalidPeriodIsRejected(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'not-a-real-period-' . $this->tok]
        );

        $this->assertSame(422, $resp['status'],
            'An unknown period should fail inArray validation with 422.');
    }

    public function testReportNameBranchRequiresSessionId(): void
    {
        $this->authenticateAs('admin');

        // The 'visit' report requires a sessionId; omitting it must fail validation.
        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['report_name' => 'visit'] // no sessionId
        );

        $this->assertSame(422, $resp['status'],
            "The 'visit' report requires sessionId; omitting it should return 422.");
    }

    // ------------------------------------------------------------------
    // Canned report_name branches (each is a documented endpoint on the wiki:
    // reports/{visit,clickstream,latest_visits,latest_actions,clicks}).
    //
    // These pin the CONTRACT of each canned report -- required-param validation
    // and that a valid request returns a 201 result set -- without seeding fact
    // rows: an empty result set is a valid success response, and the point here
    // is the request/validation/response envelope each report guarantees, not
    // the row math (that is the ingestion tests' job).
    // ------------------------------------------------------------------

    /**
     * Every canned report rejects an unauthenticated caller before doing any
     * work -- the view_reports capability gate is the same on all of them.
     *
     * @dataProvider cannedReportProvider
     */
    public function testCannedReportRejectsUnauthenticated(string $reportName, array $validParams): void
    {
        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['report_name' => $reportName] + $validParams
        );

        $this->assertNotAuthenticated($resp, "GET /reports/{$reportName}");
    }

    /**
     * A valid request to each canned report returns the 201 success envelope
     * routed through the reportsRest view.
     *
     * @dataProvider cannedReportProvider
     */
    public function testCannedReportReturnsSuccessEnvelope(string $reportName, array $validParams): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['report_name' => $reportName] + $validParams
        );

        $this->assertSame(201, $resp['status'],
            "A valid '{$reportName}' report should return 201.");
        $this->assertSame('base.reportsRest', $resp['view'],
            "A successful '{$reportName}' report routes to the reportsRest view.");
    }

    /**
     * report_name/validParams for each documented canned report. The params are
     * the minimum the controller's validate() switch requires (a bogus siteId /
     * sessionId still satisfies "required" and yields an empty-but-valid set).
     *
     * @return array<string, array{0:string, 1:array<string,string>}>
     */
    public static function cannedReportProvider(): array
    {
        $bogusSite    = 'reports-canned-site';
        $bogusSession = '1700000000000000001';
        $today        = date('Ymd');

        return [
            // report_name        [ report_name, valid minimal params ]
            'visit'          => ['visit',          ['sessionId' => $bogusSession]],
            'clickstream'    => ['clickstream',    ['sessionId' => $bogusSession]],
            'latest_visits'  => ['latest_visits',  ['siteId' => $bogusSite]],
            'latest_actions' => ['latest_actions', ['siteId' => $bogusSite, 'startDate' => $today, 'endDate' => $today]],
            // clicks accepts pageUrl OR document_id (wiki). This row exercises the
            // document_id path; the pageUrl path has its own dedicated test below
            // (testClicksByPageUrlResolvesToCanonicalDocumentId) because it also
            // needs to pin the URL-canonicalization contract, not just the envelope.
            'clicks'         => ['clicks',         ['siteId' => $bogusSite, 'document_id' => '999999999']],
        ];
    }

    public function testLatestActionsRequiresDateRangeAndSite(): void
    {
        $this->authenticateAs('admin');

        // latest_actions requires startDate, endDate AND siteId; omit all three.
        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['report_name' => 'latest_actions']
        );

        $this->assertSame(422, $resp['status'],
            "The 'latest_actions' report requires startDate/endDate/siteId; omitting them should return 422.");
    }

    public function testClickstreamRequiresSessionId(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['report_name' => 'clickstream'] // no sessionId
        );

        $this->assertSame(422, $resp['status'],
            "The 'clickstream' report requires sessionId; omitting it should return 422.");
    }

    /**
     * The clicks report resolves a `pageUrl` param to a document_id the SAME way
     * ingestion does: the URL is run through makeUrlCanonical (which strips OWA
     * tracking params) BEFORE being hashed. This is the contract that makes a
     * pageUrl lookup find the clicks that were logged for that page -- during
     * tracking the stored document_id is a hash of the *canonical* URL, so the
     * report must canonicalize too or it silently matches nothing.
     *
     * We assert it against a real seeded document rather than mocking: seed a
     * document whose id is the hash of the canonical URL, then query the report
     * with the pre-canonical (tracking-param-laden) pageUrl and confirm the
     * request succeeds AND targets that document_id (surfaced as a query-string
     * param in the result set envelope).
     *
     * Regression guard: report_clicks previously passed the bare siteId string
     * where makeUrlCanonical expects an event object -- a fatal getSiteId()-on-
     * string whenever the page_url filter was registered, and a canonicalization
     * no-op (wrong document_id) when it was not.
     */
    public function testClicksByPageUrlResolvesToCanonicalDocumentId(): void
    {
        $site = $this->makeSite();
        $this->authenticateAs('admin');

        // The canonical form of this URL has the owa_* tracking params stripped.
        $canonicalUrl = $site['domain'] . '/pricing';
        $pageUrl      = $canonicalUrl . '?owa_source=news&owa_campaign=launch';

        // Ingestion hashes the canonical URL to derive document_id; mirror that so
        // the report has a real row to resolve to.
        $d = owa_coreAPI::entityFactory('base.document');
        $expectedDocumentId = $d->generateId($canonicalUrl);

        $d->set('id', $expectedDocumentId);
        $d->set('url', $canonicalUrl);
        $d->set('page_type', 'page');
        $d->create();
        $this->trackForCleanup('base.document', $expectedDocumentId, 'id');

        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['report_name' => 'clicks', 'siteId' => $site['site_id'], 'pageUrl' => urlencode($pageUrl)]
        );

        $this->assertSame(201, $resp['status'],
            'A clicks report queried by pageUrl should return 201, not fatal in URL canonicalization.');
        $this->assertSame('base.reportsRest', $resp['view']);

        // report_clicks designates the resolved document_id as a result-set query
        // param; it must be the hash of the CANONICAL url (tracking params stripped),
        // proving the pageUrl was canonicalized before hashing.
        $this->assertStringContainsString((string) $expectedDocumentId, $resp['raw'],
            'The clicks report must resolve pageUrl to the canonical document_id (owa_* params stripped before hashing).');
    }
}
