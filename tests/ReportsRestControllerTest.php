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

    /**
     * A Profile of its own, and optionally a cube for its Property.
     *
     * A query always reaches SOME Profile -- ReportController::pre() fills in
     * the first allowed one when none is named -- and whether it can be
     * answered depends on that Profile's Property having a cube. So each test
     * names the Profile it means and says whether its cube exists, rather than
     * relying on whatever an earlier test left behind.
     */
    private function profile(bool $with_cube): string
    {
        $site     = $this->makeSite($with_cube ? 'cubed' : 'uncubed');
        $property = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite($site['site_id']);

        if ($with_cube) {
            $this->assertTrue(\OWA\Module\Base\Classes\Cube\Cubes::create($property));
            $this->cubes[] = $property;
        }

        \OWA\Module\Base\Classes\Cube\Cubes::forgetExistence();

        return $site['site_id'];
    }

    /** @var string[] Properties whose cubes this test created */
    private array $cubes = [];

    protected function tearDown(): void
    {
        foreach ($this->cubes as $property) {
            foreach (['', '_rebuild', '_computed'] as $suffix) {
                \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s%s',
                    \OWA\Module\Base\Classes\Cube\Cubes::tableFor($property), $suffix));
            }
        }

        $this->cubes = [];
        \OWA\Module\Base\Classes\Cube\Cubes::forgetExistence();

        parent::tearDown();
    }

    public function testResultSetQueryReturnsResults(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today', 'siteId' => $this->profile(true)]
        );

        $this->assertSame(201, $resp['status'],
            'A valid metrics query should return 201.');
        $this->assertSame('base.reportsRest', $resp['view']);
        $this->assertIsArray($resp['data'],
            'A resultSet response payload should be an array (the serialized result set).');
    }

    /**
     * A Profile whose Property has no cube yet is answered 409 with the
     * reason, not 201 with no rows -- which is how a real zero looks.
     */
    public function testAQueryBeforeTheFirstBuildIsNotReady(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today', 'siteId' => $this->profile(false)]
        );

        $this->assertSame(409, $resp['status']);
        $this->assertSame('base.reportsRest', $resp['view']);
        $this->assertNotEmpty($resp['data']['notReady'] ?? null,
            'the response says why there is nothing to answer');
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

    /** Page views written straight into a Profile's cube: [ yyyymmdd, path, count ]. */
    private function seedPageViews(string $siteId, array $rows): void
    {
        $property = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite($siteId);
        $id = 990000;

        foreach ($rows as [$day, $path, $count]) {
            for ($n = 0; $n < $count; $n++) {
                $event = \OWA\Module\Base\Classes\Cube\Cubes::entityFor($property);
                $event->setProperties([
                    'id'         => ++$id,
                    'event_type' => 'page_view',
                    'site_id'    => $siteId,
                    'visitor_id' => 7000 + $id,
                    'session_id' => 8000 + $id,
                    'ts'         => strtotime($day . ' 12:00:00') * 1000000 + $id,
                    'yyyymmdd'   => (int) $day,
                    'page_path'  => $path,
                ]);
                $this->assertTrue((bool) $event->create(), 'seeding the cube failed');
            }
        }
    }

    /**
     * compare puts the comparison period's numbers beside the period's: in
     * the aggregates, and on each row matched by its dimension values. The
     * comparison is restricted to the rows on the page, so /c -- the
     * comparison's own top page, and not on this page -- is not what /a is
     * compared with.
     */
    public function testAComparisonIsAnsweredBesideThePeriod(): void
    {
        $this->authenticateAs('admin');

        $site = $this->profile(true);
        $day  = date('Ymd');
        $week = date('Ymd', strtotime('-7 days'));

        $this->seedPageViews($site, [
            [$day, '/a', 3], [$day, '/b', 1],
            [$week, '/a', 1], [$week, '/b', 2], [$week, '/c', 5],
        ]);

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'dimensions' => 'pagePath', 'sort' => 'pageViews-', 'resultsPerPage' => 1,
             'period' => 'date_range', 'startDate' => $day, 'endDate' => $day,
             'compare' => 'previous_period_dow', 'siteId' => $site]
        );

        $this->assertSame(201, $resp['status'], $resp['raw']);

        $data = $resp['data'];

        $this->assertSame(['previous_period_dow', $week, $week],
            [$data['compare']['compare'], $data['compare']['startDate'], $data['compare']['endDate']]);

        $total = $data['aggregates']['pageViews'];
        $this->assertSame([4, 8], [(int) $total['value'], (int) $total['compare_value']]);
        $this->assertEqualsWithDelta(-0.5, $total['change'], 1e-9);

        $this->assertCount(1, $data['resultsRows']);
        $a = $data['resultsRows'][0];
        $this->assertSame('/a', $a['pagePath']['value']);
        $this->assertSame([3, 1], [(int) $a['pageViews']['value'], (int) $a['pageViews']['compare_value']]);
        $this->assertEqualsWithDelta(2.0, $a['pageViews']['change'], 1e-9);
    }

    public function testATrendIsComparedDayForDay(): void
    {
        $this->authenticateAs('admin');

        $site = $this->profile(true);
        $d = fn (int $ago) => date('Ymd', strtotime("-$ago days"));

        // The last two days against the two before them.
        $this->seedPageViews($site, [
            [$d(1), '/a', 2], [$d(0), '/a', 6],
            [$d(3), '/a', 1], [$d(2), '/a', 3],
        ]);

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'dimensions' => 'date', 'sort' => 'date',
             'period' => 'date_range', 'startDate' => $d(1), 'endDate' => $d(0),
             'compare' => 'previous_period', 'siteId' => $site]
        );

        $this->assertSame(201, $resp['status'], $resp['raw']);

        $this->assertSame([[2, 1], [6, 3]], array_map(
            fn ($r) => [(int) $r['pageViews']['value'], (int) $r['pageViews']['compare_value']],
            $resp['data']['resultsRows']));

        // A refetch from the result set's own link is still compared.
        parse_str((string) parse_url($resp['data']['self'], PHP_URL_QUERY), $self);
        $this->assertSame('previous_period', $self['compare'] ?? null, $resp['data']['self']);
    }

    public function testNoComparisonLeavesTheResultAsItWas(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today', 'siteId' => $this->profile(true)]
        );

        $this->assertSame(201, $resp['status']);
        $this->assertNull($resp['data']['compare']);
        $this->assertArrayNotHasKey('compare_value', $resp['data']['aggregates']['pageViews'] ?? []);
    }

    public function testAnUnknownComparisonIsRefused(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today', 'compare' => 'same_month_last_year', 'siteId' => $this->profile(true)]
        );

        $this->assertSame(422, $resp['status']);
    }
}
