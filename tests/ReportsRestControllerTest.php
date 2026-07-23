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
}
