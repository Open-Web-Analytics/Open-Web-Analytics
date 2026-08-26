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

    /**
     * REST refuses an unusable range for the same reasons the web does, using
     * the same rule -- the two had already drifted once on what a period is.
     *
     * @dataProvider unusableRestRangeProvider
     */
    public function testUnusableDateRangeIsRejected( array $params ): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            array_merge( ['metrics' => 'pageViews'], $params )
        );

        $this->assertSame(422, $resp['status'],
            'an unusable range should fail validation: ' . json_encode( $params ) );
    }

    public static function unusableRestRangeProvider(): array
    {
        return [
            'end date alone'   => [ ['endDate' => '20260810'] ],
            'start date alone' => [ ['startDate' => '20260801'] ],
            'inverted range'   => [ ['startDate' => '20260810', 'endDate' => '20260801'] ],
            'no bounds'        => [ ['period' => 'date_range'] ],
        ];
    }

    /**
     * A well-formed range is still served, so the guard above has not simply
     * closed the endpoint to date ranges.
     */
    public function testAnOrderedDateRangeIsStillServed(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            'owa_reportsRestController',
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'startDate' => '20260801', 'endDate' => '20260810']
        );

        $this->assertNotSame(422, $resp['status'],
            'an ordered range must still be accepted' );
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

    /*
     * The clicks report is GONE -- a heatmap is an ordinary dimensional query
     * now (domClicks by clickX,clickY constrained on pagePath), so there is no
     * report_clicks to test and no pageUrl-to-document_id resolution inside it.
     *
     * What that test really pinned -- that a url is canonicalised BEFORE it is
     * hashed into a document id -- is an ingestion contract, and it stays
     * covered by ContentDerivedIdCoverageTest and RederiveDimensionIdsTest.
     */
}
