<?php

require_once(__DIR__ . '/RestControllerTestCase.php');

/**
 * Contract + auth tests for the reports REST endpoint:
 *
 *   GET /owa/api/base/v1/reports  -> owa_reportsRestController (view_reports)
 *
 * A resultSet query, which REQUIRES `metrics`. The named reports
 * (/reports/{report_name}) are refused.
 *
 * success() -> 200 (base.restApi); errorAction()/validation -> 422 (base.restApi).
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
            ['period' => 'today', 'siteId' => $this->profile(false)] // no metrics, no report_name
        );

        $this->assertSame(422, $resp['status'],
            'A resultSet query without metrics should fail validation with 422.');
        $this->assertSame('base.restApi', $resp['view'],
            'A validation failure routes to the restApi error view.');
    }

    /**
     * A Profile of its own, and optionally a cube for its Property.
     *
     * A query names its Profile -- one naming neither propertyId nor siteId
     * is refused -- and whether it can be answered depends on that Profile's
     * Property having a cube. So each test names the Profile it means and says
     * whether its cube exists, rather than relying on whatever an earlier test
     * left behind.
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

        $this->assertSame(200, $resp['status'],
            'A valid metrics query should return 200.');
        $this->assertEnvelope($resp, 200);
        $this->assertIsArray($resp['data'],
            'A resultSet response payload should be an array (the serialized result set).');
    }

    /** The request parameters Auth reads an overlay token's request from. */
    private const OVERLAY_REQUEST = ['overlayToken', 'rest_params', 'constraints', 'siteId', 'metrics',
        'dimensions', 'period', 'segment', 'rowValues'];

    /**
     * A request signed in by a heatmap's token is held to the query on its
     * link: a segment or rowValues, which the token does not bind, is refused.
     */
    public function testAnOverlayTokenMayNotAskForASegmentOrRowValues(): void
    {
        $user  = $this->makeUser('admin', 'overlay');
        $base  = ['metrics' => 'eventCount', 'dimensions' => 'clickX', 'constraints' => 'pagePath==/a',
                  'siteId' => $this->profile(true), 'period' => 'today'];
        $token = \OWA\Core\OverlayToken::mint($user['user_id'], 'reports', 'constraints', 'pagePath==/a',
            \OWA\Core\OverlayToken::DEFAULT_TTL, $base);

        $ask = function (array $extra) use ($base, $token): array {
            $params = $base + $extra + ['overlayToken' => $token, 'rest_params' => 'base/v1/reports'];

            foreach (self::OVERLAY_REQUEST as $name) {
                \OWA\Core\CoreAPI::setRequestParam($name, $params[$name] ?? '');
            }

            $this->resetCurrentUser();

            return $this->callEndpoint(\OWA\Module\Base\Controller\ReportsRest::class, 'reportsRestController.php', $params);
        };

        try {
            $this->assertSame(200, $ask([])['status'], 'the link itself');

            foreach ([
                'segment'   => ['segment' => 'pagePath==/b'],
                'rowValues' => ['rowValues' => json_encode(['clickX' => ['10']])],
            ] as $what => $extra) {
                $resp = $ask($extra);

                $this->assertSame(422, $resp['status'], "$what was answered");
                $this->assertStringContainsString($what, $resp['raw']);
            }
        } finally {
            foreach (self::OVERLAY_REQUEST as $name) {
                \OWA\Core\CoreAPI::setRequestParam($name, '');
            }

            (new \ReflectionProperty(\OWA\Core\Auth::class, 'auth_method'))->setValue(\OWA\Core\Auth::get_instance(), null);
        }
    }

    /**
     * A Profile whose Property has no cube yet is answered 409 with the
     * reason, not 200 with no rows -- which is how a real zero looks.
     */
    public function testAQueryBeforeTheFirstBuildIsNotReady(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today', 'siteId' => $this->profile(false)]
        );

        $this->assertEnvelope($resp, 409);
        $this->assertNotEmpty($resp['data']['notReady'] ?? null,
            'the response says why there is nothing to answer');
    }

    /**
     * A request naming neither propertyId nor siteId is refused: the REST API
     * infers no Property, and does not read the lpr cookie.
     */
    public function testAQueryNamingNoPropertyIsRefused(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today']
        );

        $this->assertSame('base.restApi', $resp['view']);
        $this->assertSame(422, $resp['status'], $resp['raw']);
        $this->assertStringContainsString('Name the Property with propertyId', $resp['raw']);
    }

    public function testInvalidPeriodIsRejected(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'not-a-real-period-' . $this->tok, 'siteId' => $this->profile(false)]
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
            array_merge( ['metrics' => 'pageViews', 'siteId' => $this->profile(false)], $params )
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
            ['metrics' => 'pageViews', 'startDate' => '20260801', 'endDate' => '20260810', 'siteId' => $this->profile(true)]
        );

        $this->assertNotSame(422, $resp['status'],
            'an ordered range must still be accepted' );
    }

    /**
     * A report name is refused, not ignored, so a caller still using one is
     * told.
     */
    public function testANamedReportIsRefused(): void
    {
        $this->authenticateAs('admin');
        $site = $this->profile(false);

        foreach (['visit', 'clickstream', 'latest_visits', 'transactions'] as $name) {
            $resp = $this->callEndpoint(
                \OWA\Module\Base\Controller\ReportsRest::class,
                'reportsRestController.php',
                ['report_name' => $name, 'metrics' => 'pageViews', 'sessionId' => '1700000000000000001', 'siteId' => $site]
            );

            $this->assertSame(422, $resp['status'], "GET /reports/$name");
        }
    }

    /** Page views written straight into a Profile's cube: [ yyyymmdd, path, count ]. */
    private function seedPageViews(string $siteId, array $rows): void
    {
        $this->seedEvents($siteId, array_map(fn ($r) => [$r[0], $r[1], $r[2], 'page_view'], $rows));
    }

    /** Events written straight into a Profile's cube: [ yyyymmdd, path (or null), count, event_type ]. */
    private function seedEvents(string $siteId, array $rows): void
    {
        $property = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite($siteId);

        foreach ($rows as [$day, $path, $count, $type]) {
            for ($n = 0; $n < $count; $n++) {
                $id = ++$this->seeded;
                $event = \OWA\Module\Base\Classes\Cube\Cubes::entityFor($property);
                $event->setProperties([
                    'id'         => $id,
                    'event_type' => $type,
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

    /** @var int the last event id seeded */
    private int $seeded = 990000;

    /**
     * compare puts the comparison period's numbers beside the period's: in
     * the aggregates, and on each row matched by its dimension values. /c --
     * the comparison's own top page, which the period does not have -- is not
     * what /a is compared with; it is counted as a row after the period's two.
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

        $this->assertSame(200, $resp['status'], $resp['raw']);

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
        $this->assertSame(3, (int) $data['resultsTotal']);
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

        $this->assertSame(200, $resp['status'], $resp['raw']);

        $this->assertSame([[2, 1], [6, 3]], array_map(
            fn ($r) => [(int) $r['pageViews']['value'], (int) $r['pageViews']['compare_value']],
            $resp['data']['resultsRows']));

        // A refetch from the result set's own link is still compared.
        parse_str((string) parse_url($resp['data']['self'], PHP_URL_QUERY), $self);
        $this->assertSame('previous_period', $self['compare'] ?? null, $resp['data']['self']);
    }

    /**
     * A trend with nothing in the period still carries the comparison's rows:
     * they are a line of their own. This week on a Sunday with no visits yet
     * still draws last week.
     */
    public function testAnEmptyTrendStillCarriesTheComparisonsRows(): void
    {
        $this->authenticateAs('admin');

        $site = $this->profile(true);
        $d = fn (int $ago) => date('Ymd', strtotime("-$ago days"));

        $this->seedPageViews($site, [[$d(7), '/a', 2], [$d(5), '/a', 4]]);

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'dimensions' => 'date', 'sort' => 'date',
             'period' => 'date_range', 'startDate' => $d(0), 'endDate' => $d(0),
             'compare' => 'date_range', 'compareStartDate' => $d(7), 'compareEndDate' => $d(1), 'siteId' => $site]
        );

        $this->assertSame(200, $resp['status'], $resp['raw']);
        $this->assertSame([], $resp['data']['resultsRows'] ?? []);

        // Moved onto the period's days: the comparison's 1st and 3rd.
        $this->assertSame([[$d(0), $d(7), 2], [date('Ymd', strtotime('+2 days')), $d(5), 4]], array_map(
            fn ($r) => [(string) $r['date']['value'], (string) $r['date']['compare_period_value'], (int) $r['pageViews']['value']],
            $resp['data']['compare']['rows'] ?? []));
        $this->assertSame(6, (int) $resp['data']['aggregates']['pageViews']['compare_value']);
    }

    public function testNoComparisonLeavesTheResultAsItWas(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'period' => 'today', 'siteId' => $this->profile(true)]
        );

        $this->assertSame(200, $resp['status']);
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

    /**
     * rowValues restricts a query to the rows a grid is showing: a trend by
     * date and page, of /a and /b only, with the comparison's rows for both.
     * A page path may contain a comma, so the values travel as JSON.
     */
    public function testRowValuesRestrictATrendToTheGridsRows(): void
    {
        $this->authenticateAs('admin');

        $site = $this->profile(true);
        $day  = date('Ymd');
        $week = date('Ymd', strtotime('-7 days'));

        $this->seedPageViews($site, [
            [$day, '/a', 3], [$day, '/b,c', 1], [$day, '/z', 9],
            [$week, '/a', 1], [$week, '/z', 4],
        ]);

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'dimensions' => 'date,pagePath', 'sort' => 'date', 'resultsPerPage' => 100,
             'period' => 'date_range', 'startDate' => $day, 'endDate' => $day,
             'compare' => 'previous_period_dow', 'siteId' => $site,
             'rowValues' => json_encode(['pagePath' => ['/a', '/b,c']])]
        );

        $this->assertSame(200, $resp['status'], $resp['raw']);

        $paths = array_map(fn ($r) => $r['pagePath']['value'], $resp['data']['resultsRows']);
        sort($paths);
        $this->assertSame(['/a', '/b,c'], $paths);

        $compared = array_map(fn ($r) => [$r['date']['value'], $r['pagePath']['value'], (int) $r['pageViews']['value']],
            $resp['data']['compare']['rows']);
        $this->assertSame([[$day, '/a', 1]], $compared, 'the comparison is of the same rows, on the period\'s dates');
    }

    public function testRowValuesNamingAnotherDimensionAreRefused(): void
    {
        $this->authenticateAs('admin');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            ['metrics' => 'pageViews', 'dimensions' => 'date', 'period' => 'today', 'siteId' => $this->profile(true),
             'rowValues' => json_encode(['pagePath' => ['/a']])]
        );

        $this->assertSame(422, $resp['status']);
    }

    /** A compared grid of page views by $dimensions: today against seven days ago. */
    private function comparedGrid(string $site, array $params = []): array
    {
        $day  = date('Ymd');

        $resp = $this->callEndpoint(
            \OWA\Module\Base\Controller\ReportsRest::class,
            'reportsRestController.php',
            $params + ['metrics' => 'pageViews', 'dimensions' => 'pagePath', 'sort' => 'pageViews-', 'resultsPerPage' => 10,
             'period' => 'date_range', 'startDate' => $day, 'endDate' => $day,
             'compare' => 'previous_period_dow', 'siteId' => $site]
        );

        $this->assertSame(200, $resp['status'], $resp['raw']);

        return $resp['data'];
    }

    /** Each row as [ its dimension values..., metric value, compare value ] of $metric. */
    private static function compared(array $data, string $metric = 'pageViews', array $dims = ['pagePath']): array
    {
        return array_map(function ($r) use ($metric, $dims) {
            $out = [];
            foreach ($dims as $d) {
                $out[] = $r[$d]['value'];
            }
            $out[] = $r[$metric]['value'] === null ? null : (int) $r[$metric]['value'];
            $out[] = $r[$metric]['compare_value'] === null ? null : (int) $r[$metric]['compare_value'];
            return $out;
        }, $data['resultsRows']);
    }

    /**
     * A grid lists the rows of both periods: the period's own first, then the
     * groups only the comparison has, by the sort over its numbers. In those
     * every metric is 0 and changed by -100%, a distinct count too.
     */
    public function testAComparedGridAddsTheRowsOnlyTheComparisonHas(): void
    {
        $this->authenticateAs('admin');

        $site = $this->profile(true);
        $day  = date('Ymd');
        $week = date('Ymd', strtotime('-7 days'));

        $this->seedPageViews($site, [
            [$day, '/a', 3], [$day, '/b', 1],
            [$week, '/a', 1], [$week, '/d', 2], [$week, '/c', 5],
        ]);

        $data = $this->comparedGrid($site, ['metrics' => 'pageViews,totalUsers']);

        $this->assertSame([['/a', 3, 1], ['/b', 1, null], ['/c', 0, 5], ['/d', 0, 2]], self::compared($data));
        $this->assertSame(4, (int) $data['resultsTotal']);

        $c = $data['resultsRows'][2];
        $this->assertSame('0', (string) $c['pageViews']['formatted_value']);
        $this->assertEqualsWithDelta(-1.0, $c['pageViews']['change'], 1e-9);
        $this->assertSame([0, '0', 5], [$c['totalUsers']['value'], (string) $c['totalUsers']['formatted_value'],
            (int) $c['totalUsers']['compare_value']]);
        $this->assertEqualsWithDelta(-1.0, $c['totalUsers']['change'], 1e-9);

        // The totals are the periods' own.
        $this->assertSame([4, 8], [(int) $data['aggregates']['pageViews']['value'], (int) $data['aggregates']['pageViews']['compare_value']]);

        // Sorted by the path, the comparison's rows by the path too.
        $this->assertSame(['/a', '/b', '/c', '/d'], array_map(fn ($r) => $r['pagePath']['value'],
            $this->comparedGrid($site, ['sort' => 'pagePath'])['resultsRows']));
        $this->assertSame(['/b', '/a', '/d', '/c'], array_map(fn ($r) => $r['pagePath']['value'],
            $this->comparedGrid($site, ['sort' => 'pagePath-'])['resultsRows']));
    }

    /**
     * The comparison's rows page on from the period's: a page that runs past
     * the period's rows is filled from them, and a page after it is theirs
     * alone, from the right one. The read-ahead counts both.
     */
    public function testTheComparisonsRowsPageOnFromThePeriods(): void
    {
        $this->authenticateAs('admin');

        $site = $this->profile(true);
        $day  = date('Ymd');
        $week = date('Ymd', strtotime('-7 days'));

        $this->seedPageViews($site, [
            [$day, '/a', 5], [$day, '/b', 4], [$day, '/c', 3],
            [$week, '/b', 1], [$week, '/x', 9], [$week, '/y', 8], [$week, '/z', 7],
        ]);

        $page = function (int $n) use ($site): array {
            $data = $this->comparedGrid($site, ['resultsPerPage' => 2, 'page' => $n]);
            return [self::compared($data), (int) $data['resultsTotal'], (bool) $data['next']];
        };

        $this->assertSame([[['/a', 5, null], ['/b', 4, 1]], 6, true], $page(1));
        $this->assertSame([[['/c', 3, null], ['/x', 0, 9]], 4, true], $page(2));
        $this->assertSame([[['/y', 0, 8], ['/z', 0, 7]], 2, false], $page(3));
        $this->assertSame([[], 0, false], $page(4));

        // A card's Show more: page 1, more rows.
        $this->assertSame(['/a', '/b', '/c', '/x', '/y'], array_map(fn ($r) => $r['pagePath']['value'],
            $this->comparedGrid($site, ['resultsPerPage' => 5])['resultsRows']));
    }

    /**
     * With nothing in the period, the grid is the comparison's rows, each
     * changed to 0, and the total is 0 against the comparison's.
     */
    public function testAnEmptyPeriodListsTheComparisonsRows(): void
    {
        $this->authenticateAs('admin');

        $site = $this->profile(true);
        $week = date('Ymd', strtotime('-7 days'));

        $this->seedPageViews($site, [[$week, '/a', 2], [$week, '/b', 1]]);

        $data = $this->comparedGrid($site);

        $this->assertSame([['/a', 0, 2], ['/b', 0, 1]], self::compared($data));
        $this->assertSame(2, (int) $data['resultsTotal']);
        $this->assertSame([0, 3], [(int) $data['aggregates']['pageViews']['value'], (int) $data['aggregates']['pageViews']['compare_value']]);

        // A second page of an empty period starts where the comparison's rows do.
        $this->assertSame([['/b', 0, 1]], self::compared($this->comparedGrid($site, ['resultsPerPage' => 1, 'page' => 2])));
    }

    /**
     * A row's key is every dimension, and an unset one is a key like any
     * other: the "(not set)" row is compared with the comparison's, and a
     * group the period has under another event is not among the comparison's
     * own.
     */
    public function testUnsetValuesAndTwoDimensionsAreKeysOfTheirOwn(): void
    {
        $this->authenticateAs('admin');

        $site = $this->profile(true);
        $day  = date('Ymd');
        $week = date('Ymd', strtotime('-7 days'));

        $this->seedEvents($site, [
            [$day, null, 3, 'page_view'], [$day, '/a', 2, 'page_view'],
            [$week, null, 1, 'page_view'], [$week, '/a', 6, 'click'], [$week, '/a', 4, 'page_view'], [$week, null, 5, 'click'],
        ]);

        $data = $this->comparedGrid($site, ['metrics' => 'eventCount', 'dimensions' => 'pagePath,eventName', 'sort' => 'eventCount-']);

        $this->assertSame([
            [null, 'page_view', 3, 1], ['/a', 'page_view', 2, 4],
            ['/a', 'click', 0, 6], [null, 'click', 0, 5],
        ], self::compared($data, 'eventCount', ['pagePath', 'eventName']));
    }

    /**
     * A filter on a metric selects rows by the period's numbers, which the
     * comparison's own rows do not have: none are added. A filter on a
     * dimension applies to both periods.
     */
    public function testFiltersOnTheComparisonsRows(): void
    {
        $this->authenticateAs('admin');

        $site = $this->profile(true);
        $day  = date('Ymd');
        $week = date('Ymd', strtotime('-7 days'));

        $this->seedPageViews($site, [
            [$day, '/a', 3], [$day, '/b', 1],
            [$week, '/a', 1], [$week, '/c', 5], [$week, '/d', 2],
        ]);

        $this->assertSame([['/a', 3, 1], ['/b', 1, null]],
            self::compared($this->comparedGrid($site, ['constraints' => 'pageViews>=1'])));
        $this->assertSame([['/a', 3, 1], ['/b', 1, null], ['/c', 0, 5]],
            self::compared($this->comparedGrid($site, ['constraints' => 'pagePath!=/d'])));
    }
}
