<?php

require_once(__DIR__ . '/RestControllerTestCase.php');

/**
 * Contract + auth tests for the reports REST endpoint:
 *
 *   GET /owa/api/base/v1/reports  -> owa_reportsRestController (view_reports)
 *
 * A resultSet query, which REQUIRES `metrics`. The named reports
 * (/reports/{report_name}) went with v1 in 2.0 and are refused.
 *
 * success() -> 201 (base.reportsRest); errorAction()/validation -> 422 (base.restApi).
 */
final class ReportsRestControllerTest extends RestControllerTestCase
{
    public function testReportsRejectsUnauthenticated(): void
    {
        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today']
        );

        $this->assertNotAuthenticated($resp, 'GET /reports');
    }

    public function testResultSetQueryRequiresMetrics(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
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
            \OWA\Module\Base\Controller\ReportsRest::class,
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
            \OWA\Module\Base\Controller\ReportsRest::class,
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
            \OWA\Module\Base\Controller\ReportsRest::class,
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
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'startDate' => '20260801', 'endDate' => '20260810']
        );

        $this->assertNotSame(422, $resp['status'],
            'an ordered range must still be accepted' );
    }

    /**
     * The named reports went with v1's tables in 2.0. A name is refused, not
     * ignored, so a caller still using one is told.
     */
    public function testANamedReportIsRefused(): void
    {
        $this->authenticateAs('admin');

        foreach (['visit', 'clickstream', 'latest_visits', 'transactions'] as $name) {
            $resp = $this->callEndpoint(
                \OWA\Module\Base\Controller\ReportsRest::class,
                'reportsRestController.php',
                ['report_name' => $name, 'metrics' => 'pageViews', 'sessionId' => '1700000000000000001']
            );

            $this->assertSame(422, $resp['status'], "GET /reports/$name");
        }
    }
}
