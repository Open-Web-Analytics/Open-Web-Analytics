<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\CustomReports;

/**
 * User-authored report definitions: what may be stored, and who may see them.
 *
 * A custom report is a report DEFINITION -- the same JSON a shipped report
 * holds -- rendered by the same Core\ConfiguredReport. That is only safe
 * because the format was narrowed for it during the conversion: the renderer is
 * fixed rather than named by the definition, formatters are selected by name
 * and never carried as code, and excludeColumns is a list of names rather than
 * a fragment of script.
 *
 * So the interesting question is not "does it render" but "what does it refuse
 * to store", and most of this file is about that. The names inside a definition
 * reach the query builder, so a name that does not resolve through the registry
 * is the reader choosing what appears in SQL -- the same invariant the REST
 * endpoint has.
 */
final class CustomReportsTest extends TestCase
{
    private const AUTHOR = 'custom-report-author@example.test';
    private const OTHER  = 'custom-report-other@example.test';

    /** @var string[] ids to clean up */
    private static $created = array();

    protected function setUp(): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);
    }

    public static function tearDownAfterClass(): void
    {
        if (!function_exists('owa_test_db_available') || !owa_test_db_available()) {
            return;
        }

        foreach (self::$created as $id) {
            CustomReports::delete($id);
        }

        self::$created = array();
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('storing a custom report needs a database');
        }
    }

    /** A definition that is valid, so a test can break exactly one thing about it. */
    private function definition(): array
    {
        return array(
            'title'   => 'A Custom Report',
            'metrics' => 'visits,uniqueVisitors',
            'widgets' => array(
                array(
                    'type'        => 'trend',
                    'id'          => 'trend',
                    'container'   => 'trend-chart',
                    'chartMetric' => 'visits',
                    'query'       => array('dimensions' => 'date', 'sort' => 'date'),
                ),
                array(
                    'type'      => 'grid',
                    'id'        => 'pages',
                    'container' => 'pages',
                    'title'     => 'Pages',
                    'query'     => array(
                        'metrics'        => 'pageViews',
                        'dimensions'     => 'pagePath',
                        'sort'           => 'pageViews-',
                        'resultsPerPage' => 10,
                    ),
                ),
            ),
        );
    }

    /** Store one and remember it for cleanup. */
    private function store(array $overrides = array(), string $author = self::AUTHOR): array
    {
        $result = CustomReports::save(array(
            'name'       => $overrides['name'] ?? 'A Custom Report',
            'definition' => $overrides['definition'] ?? $this->definition(),
        ) + $overrides, $author);

        if (!empty($result['id'])) {
            self::$created[] = $result['id'];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // What may be stored
    // ------------------------------------------------------------------

    public function testAWellFormedDefinitionIsAccepted(): void
    {
        $this->assertSame('', CustomReports::validate($this->definition()));
    }

    /**
     * The registry check, which is the one that matters.
     *
     * Every name in a definition reaches the query builder. A name that does
     * not resolve is the AUTHOR choosing what appears in SQL, which is the
     * invariant the REST endpoint has and the reason this is checked at save
     * time as well as at query time.
     *
     * @dataProvider unresolvableNameProvider
     */
    public function testANameThatDoesNotResolveIsRefused(callable $break, string $expected): void
    {
        $definition = $this->definition();

        $break($definition);

        $error = CustomReports::validate($definition);

        $this->assertNotSame('', $error, 'an unresolvable name must be refused');
        $this->assertStringContainsString($expected, $error,
            'the message must name what could not be resolved, or it cannot be fixed');
    }

    public static function unresolvableNameProvider(): array
    {
        return array(
            'unknown dimension' => array(
                function (array &$d) { $d['widgets'][1]['query']['dimensions'] = 'notADimension'; },
                'notADimension',
            ),
            'unknown metric' => array(
                function (array &$d) { $d['widgets'][1]['query']['metrics'] = 'notAMetric'; },
                'notAMetric',
            ),
            'unknown sort' => array(
                function (array &$d) { $d['widgets'][1]['query']['sort'] = 'notASort-'; },
                'notASort',
            ),
            'unknown name in the report metric set' => array(
                function (array &$d) { $d['metrics'] = 'visits,notAMetric'; },
                'notAMetric',
            ),
            'one bad name among good ones' => array(
                function (array &$d) { $d['widgets'][1]['query']['metrics'] = 'pageViews,notAMetric'; },
                'notAMetric',
            ),
        );
    }

    /**
     * A sort carries its direction as a trailing '-', which is not part of the
     * name. If that were not stripped, every descending sort would be refused.
     */
    public function testADescendingSortResolvesOnItsNameAlone(): void
    {
        $definition = $this->definition();

        $definition['widgets'][1]['query']['sort'] = 'pageViews-';
        $this->assertSame('', CustomReports::validate($definition));

        $definition['widgets'][1]['query']['sort'] = 'pageViews';
        $this->assertSame('', CustomReports::validate($definition));
    }

    /**
     * Widget types are an allowlist, not a passthrough.
     *
     * The renderer can draw more types than the builder offers -- report-links,
     * heatmap-link, the badge widgets -- but each exists to serve one shipped
     * report and needs inputs the builder has no way to ask for.
     */
    public function testOnlyTheBuildableWidgetTypesAreAccepted(): void
    {
        foreach (array_keys(CustomReports::WIDGET_TYPES) as $type) {

            $definition = $this->definition();
            $definition['widgets'] = array(array(
                'type'        => $type,
                'id'          => 'w',
                'container'   => 'w',
                'chartMetric' => 'visits',
                'query'       => array('metrics' => 'visits', 'dimensions' => 'date'),
            ));

            $this->assertSame('', CustomReports::validate($definition),
                "$type is offered by the builder and must be accepted");
        }

        $definition = $this->definition();
        $definition['widgets'][0]['type'] = 'heatmap-link';

        $this->assertStringContainsString('heatmap-link', CustomReports::validate($definition));
    }

    /**
     * TEN, written out.
     *
     * Not array_fill(0, CustomReports::MAX_WIDGETS, ...): a fixture built from
     * the constant under test follows it anywhere it moves, so raising the cap
     * to 9999 would leave this passing. The number is the requirement, so the
     * number is what the test says.
     */
    // ------------------------------------------------------------------
    // What can be asked for TOGETHER
    // ------------------------------------------------------------------

    /**
     * A query is answered from ONE fact table.
     *
     * Every metric is computed from one or more of them, every dimension is
     * related to some of them, and a constraint contributes its dimension to
     * the same reduction. So a combination is only askable if one table serves
     * all of it -- clicks live in owa_click and visits in owa_session, and no
     * table has both.
     *
     * This was SILENT. The engine detected it ('illegal metric combination')
     * and reported it through addError(), which is where routine misses go and
     * which reports swallow -- so an impossible set came back as an empty or
     * nonsensical report with nothing said. It is a request error now: refused,
     * and named.
     */
    public function testMetricsFromDifferentFactTablesAreRefused(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['query']['metrics'] = 'visits,uniqueVisitors,domClicks';

        $error = CustomReports::validate($definition);

        $this->assertNotSame('', $error, 'clicks and visits cannot be counted together');

        // BOTH SIDES named: which field broke it, and what it clashed with.
        // Listing everything asked for tells an author nothing to act on.
        $this->assertStringContainsString('domClicks', $error);
        $this->assertStringContainsString('visits', $error);
    }

    /** ...and a combination that IS askable is left alone. */
    public function testMetricsSharingAFactTableAreAccepted(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['query']['metrics'] = 'visits,uniqueVisitors,pageViews';

        $this->assertSame('', CustomReports::validate($definition));
    }

    /**
     * A dimension can be as impossible as a metric, for the same reason: the
     * base entity has to be related to it too.
     */
    public function testTheCheckReachesDimensionsAndConstraints(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        // pagePath is on the request but not the session, so it decides which
        // of the two tables answers -- it does not make the query impossible.
        $this->assertSame(array('base.request'),
            $rsm->compatibleEntities(array('visits'), array('pagePath')));

        // ...and a dimension no fact table carries leaves nothing.
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $this->assertSame(array(),
            $rsm->compatibleEntities(array('visits'), array('notARealDimension')));
    }

    /** The offender is the field that emptied the set, not the whole list. */
    public function testTheClashNamesTheFieldThatCausedIt(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $clash = $rsm->firstIncompatible(array('visits', 'uniqueVisitors', 'domClicks'));

        $this->assertNotNull($clash);
        $this->assertSame('domClicks', $clash['name'], 'the LAST one added is what broke it');
        $this->assertSame('metric', $clash['kind']);
        $this->assertContains('visits', $clash['with']);
    }

    public function testACompatibleSetHasNoClash(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $this->assertNull($rsm->firstIncompatible(array('visits', 'uniqueVisitors')));
    }

    // ------------------------------------------------------------------
    // How much can be asked for
    // ------------------------------------------------------------------

    /**
     * FOUR, written out rather than taken from the constant.
     *
     * A fixture built from the constant under test follows it anywhere it
     * moves, so raising the cap would leave the test passing.
     */
    public function testAtMostFourMetricsAndFourDimensions(): void
    {
        $this->assertSame(4, CustomReports::MAX_METRICS);
        $this->assertSame(4, CustomReports::MAX_DIMENSIONS);

        /*
         * The dimension is dropped for the metric-count cases so that only the
         * COUNT is under test. Left in, a four-metric set including bounceRate
         * fails for a different and correct reason -- bounceRate is measured on
         * the session and pagePath lives on the request, so they cannot be
         * grouped together. That is the compatibility rule doing its job, and
         * it would make this test look like a limit failure.
         */
        $definition = $this->definition();
        unset( $definition['widgets'][1]['query']['dimensions'] );
        unset( $definition['widgets'][1]['query']['sort'] );

        $definition['widgets'][1]['query']['metrics'] = 'visits,uniqueVisitors,pageViews,bounceRate';
        $this->assertSame('', CustomReports::validate($definition), 'four metrics is allowed');

        $definition['widgets'][1]['query']['metrics'] =
            'visits,uniqueVisitors,pageViews,bounceRate,visitDuration';
        $this->assertStringContainsString('4 is the most',
            CustomReports::validate($definition), 'five metrics is refused');

        $definition = $this->definition();
        $definition['widgets'][1]['query']['metrics'] = 'pageViews';
        $definition['widgets'][1]['query']['sort']    = 'pageViews-';

        $definition['widgets'][1]['query']['dimensions'] = 'pagePath,browserType,city,country';
        $this->assertSame('', CustomReports::validate($definition), 'four dimensions is allowed');

        $definition['widgets'][1]['query']['dimensions'] =
            'pagePath,browserType,city,country,medium';
        $this->assertStringContainsString('4 is the most',
            CustomReports::validate($definition), 'five dimensions is refused');
    }

    // ------------------------------------------------------------------
    // A table and its controls are one decision
    // ------------------------------------------------------------------

    /**
     * WHY THERE ARE TWO TABLE TYPES.
     *
     * There was one, with a colspan, and the colspan was the bug. A grid draws
     * a control bar above it -- a dimension picker and a filter -- and every
     * control there adds width. Narrowed to a quarter of the row the bar no
     * longer fitted, and .owa_reportGridItem carries overflow-x, so the whole
     * widget grew a horizontal scrollbar.
     *
     * The size and the controls cannot be chosen separately, so the TYPE
     * decides both: a grid is full width and explorable, a grid-card is a
     * quarter wide, takes one metric against one dimension, and has no
     * controls to make room for.
     */
    public function testTheTwoTableTypesAreBothOffered(): void
    {
        $this->assertArrayHasKey('grid', CustomReports::WIDGET_TYPES);
        $this->assertArrayHasKey('grid-card', CustomReports::WIDGET_TYPES);

        $this->assertSame(array('grid'), CustomReports::FULL_WIDTH_TYPES);

        // A pie is here for the other half of the same rule: it draws one
        // metric and has to be told which, so it cannot inherit a metric set
        // either. See testAPieMustNameItsOwnMetric.
        $this->assertSame(array('grid-card', 'pie'), CustomReports::SINGLE_FIELD_TYPES);
    }

    public function testACardTakesOneMetricAgainstOneDimension(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['type'] = 'grid-card';

        // pageViews by pagePath -- exactly one of each.
        $this->assertSame('', CustomReports::validate($definition));
    }

    /**
     * EXACTLY one, not at most one.
     *
     * A card with no dimension is a number that belongs in an info box, and a
     * card with no metric has nothing to rank its rows by. Both are a
     * different widget rather than an incomplete one, so both are refused --
     * and a cap check alone would have been silent about the empty case.
     *
     * @dataProvider badCardProvider
     */
    public function testACardWithTheWrongNumberOfFieldsIsRefused(array $query, string $says): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['type']  = 'grid-card';
        $definition['widgets'][1]['query'] = $query;

        $error = CustomReports::validate($definition);

        $this->assertNotSame('', $error, 'this card should not have been storable');

        $this->assertStringContainsString($says, $error);

        // And it says where to put what does not fit, rather than only saying no.
        $this->assertStringContainsString(CustomReports::WIDGET_TYPES['grid'], $error);
    }

    public static function badCardProvider(): array
    {
        return array(
            'two metrics' => array(
                array('metrics' => 'pageViews,uniquePageViews', 'dimensions' => 'pagePath'),
                '2 metrics',
            ),
            'no metric' => array(
                array('dimensions' => 'pagePath'),
                '0 metrics',
            ),
            'two dimensions' => array(
                array('metrics' => 'pageViews', 'dimensions' => 'pagePath,pageTitle'),
                '2 dimensions',
            ),
            'no dimension' => array(
                array('metrics' => 'pageViews'),
                '0 dimensions',
            ),
        );
    }

    /**
     * ...and the same query is fine on a grid, so the refusal above is the
     * TYPE's rule rather than something wrong with the names.
     */
    public function testTheSameQueryIsAcceptedOnAFullWidthGrid(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['query'] = array(
            'metrics'    => 'pageViews,uniquePageViews',
            'dimensions' => 'pagePath,pageTitle',
        );

        $this->assertSame('', CustomReports::validate($definition));
    }

    /**
     * A full-width type stores no width.
     *
     * Dropped rather than written as 12, so the rule survives a change to what
     * full width means -- a definition that had baked in the number would keep
     * the old layout. And dropped rather than REFUSED: a colspan on a grid is a
     * definition written against an older builder, not a reason to reject
     * somebody's report.
     */
    public function testSavingTakesTheWidthOffAFullWidthWidget(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['colspan'] = 6;
        $definition['widgets'][0]['colspan'] = 6;   // a trend keeps its own

        $normalized = CustomReports::normalize($definition);

        $this->assertArrayNotHasKey('colspan', $normalized['widgets'][1],
            'a grid is full width by type, so it records no width');

        $this->assertSame(6, $normalized['widgets'][0]['colspan'],
            'every other type still chooses its own width');
    }

    public function testACardKeepsAWidthItWasGiven(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['type']    = 'grid-card';
        $definition['widgets'][1]['colspan'] = 4;

        $normalized = CustomReports::normalize($definition);

        $this->assertSame(4, $normalized['widgets'][1]['colspan']);
    }

    /** The normalisation is on the STORED definition, not only in the builder. */
    public function testAStoredGridComesBackWithNoWidth(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['colspan'] = 6;

        $result = $this->store(array('definition' => $definition));

        $this->assertTrue($result['ok'], $result['error']);

        $stored = CustomReports::load($result['id']);

        $this->assertArrayNotHasKey('colspan', $stored['definition']['widgets'][1]);
    }

    // ------------------------------------------------------------------
    // Where a widget can lead
    // ------------------------------------------------------------------

    /**
     * A card's rows lead to the report that details that dimension.
     *
     * DERIVED from what the destination declares, not from a list kept here. A
     * detail report says it is read under a constraint -- browser-detail names
     * `{dimension: browserType, fromParam: browserType}` -- so the report
     * itself already knows what a link into it has to carry.
     */
    public function testLinkTargetsComeFromWhatTheDestinationDeclares(): void
    {
        $targets = CustomReports::linkTargetsByDimension();

        $this->assertArrayHasKey('browserType', $targets);

        $ids = array_column($targets['browserType'], 'id');

        $this->assertContains('browser-detail', $ids);

        $target = $targets['browserType'][array_search('browser-detail', $ids, true)];

        $this->assertSame('browserType', $target['param'],
            'the link carries the parameter the destination is read under');

        // The name without the value it is about: the title is
        // "Browser Detail: {browserType}" and the placeholder is per request.
        $this->assertSame('Browser Detail', $target['label']);
    }

    /**
     * A report reading no parameter is not a link target, and one reading two
     * is not either.
     *
     * The first is not a detail of anything; the second cannot be reached from
     * a single column.
     */
    public function testOnlyReportsReadUnderOneParameterAreTargets(): void
    {
        $targets = CustomReports::linkTargetsByDimension();

        $flat = array();

        foreach ($targets as $list) {
            $flat = array_merge($flat, array_column($list, 'id'));
        }

        $this->assertNotContains('browsers', $flat,
            'the browsers report reads no parameter, so nothing links INTO it this way');

        $this->assertNotContains('dashboard', $flat);
    }

    /**
     * document.json names three constraints and ONE parameter.
     *
     * It constrains pagePath and priorPagePath, both from `pagePath`. Counting
     * constraints would have excluded it; counting distinct parameters is what
     * makes it reachable, and the dimension it is offered under is the one
     * matching the parameter.
     */
    public function testAReportConstrainingSeveralDimensionsFromOneValueIsATarget(): void
    {
        $targets = CustomReports::linkTargetsByDimension();

        $this->assertArrayHasKey('pagePath', $targets);

        $this->assertContains('document', array_column($targets['pagePath'], 'id'));

        $this->assertArrayNotHasKey('priorPagePath', $targets,
            'the link comes from the column the destination is named after');
    }

    /**
     * A stored link is pinned in full, not just by its report id.
     *
     * It becomes makeLink() output on the rendered page, so a definition able
     * to name any action with any parameters would be choosing the URLs the
     * report shows -- with the builder as the only thing stopping it.
     *
     * @dataProvider badLinkProvider
     */
    public function testALinkIsRefusedUnlessItIsALinkToAReport(array $link, string $says): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['type']  = 'grid-card';
        $definition['widgets'][1]['query'] = array('metrics' => 'pageViews', 'dimensions' => 'pagePath');
        $definition['widgets'][1]['link']  = $link;

        $this->assertStringContainsString($says, CustomReports::validate($definition));
    }

    public static function badLinkProvider(): array
    {
        $good = array('do' => 'base.report', 'reportId' => 'document', 'pagePath' => '%s');

        return array(
            'another action' => array(
                array('linkColumn' => 'pagePath', 'valueColumns' => 'pagePath',
                      'template' => array('do' => 'base.optionsGeneral', 'reportId' => 'document', 'pagePath' => '%s')),
                'something other than a report',
            ),
            'a report that is not registered' => array(
                array('linkColumn' => 'pagePath', 'valueColumns' => 'pagePath',
                      'template' => array('do' => 'base.report', 'reportId' => 'no-such-report', 'pagePath' => '%s')),
                'not registered',
            ),
            'a column the widget does not show' => array(
                array('linkColumn' => 'browserType', 'valueColumns' => 'pagePath', 'template' => $good),
                'not a column it shows',
            ),
            'extra parameters' => array(
                array('linkColumn' => 'pagePath', 'valueColumns' => 'pagePath',
                      'template' => $good + array('siteId' => 'somebody-elses-site')),
                'carrying 2 parameters',
            ),
            'a literal value rather than the row' => array(
                array('linkColumn' => 'pagePath', 'valueColumns' => 'pagePath',
                      'template' => array('do' => 'base.report', 'reportId' => 'document', 'pagePath' => '/admin')),
                'filled from the row',
            ),
        );
    }

    public function testAWellFormedLinkIsAccepted(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['type']  = 'grid-card';
        $definition['widgets'][1]['query'] = array('metrics' => 'pageViews', 'dimensions' => 'pagePath');
        $definition['widgets'][1]['link']  = array(
            'linkColumn'   => 'pagePath',
            'valueColumns' => 'pagePath',
            'template'     => array('do' => 'base.report', 'reportId' => 'document', 'pagePath' => '%s'),
        );

        $this->assertSame('', CustomReports::validate($definition));
    }

    // ------------------------------------------------------------------
    // "View Full Report", below the widget
    // ------------------------------------------------------------------

    /**
     * The same rule as the row link, minus the constraint.
     *
     * Scoped to the dimension the same way -- a card of top pages leads to the
     * full Top Pages report, not to a list of every report on the install --
     * but the destination must read NO parameter, because this link carries no
     * value. That is the whole difference between the two lists: a detail
     * report followed from here would answer "is constrained on pagePath,
     * which the request did not supply".
     */
    public function testTheFullReportTargetsAreScopedToTheDimension(): void
    {
        $targets = CustomReports::moreTargetsByDimension();

        $this->assertArrayHasKey('pagePath', $targets);

        $ids = array_column($targets['pagePath'], 'id');

        $this->assertContains('pages', $ids, 'the full report about a page path is Pages');

        // Reads a pagePath, so it belongs to the ROW link, not this one.
        $this->assertNotContains('document', $ids);

        // ...and a report about something else is not a fuller version of this.
        $this->assertNotContains('browsers', $ids);
        $this->assertNotContains('keywords', $ids);
    }

    /**
     * The derivation reproduces every link the shipped summary widgets already
     * make.
     *
     * The real check on it. These twelve were written by hand over years -- top
     * products to Products, top page types to Page Types, latest visits to
     * Latest Visits -- so if the rule offered something different from what a
     * person chose, the rule would be the thing that is wrong.
     */
    public function testTheDerivationReproducesTheShippedFullReportLinks(): void
    {
        $targets = CustomReports::moreTargetsByDimension();
        $missed  = array();

        foreach (glob(OWA_DIR . 'modules/*/reports/*.json') as $file) {

            $definition = json_decode((string) file_get_contents($file), true);

            foreach ((array) ($definition['widgets'] ?? array()) as $widget) {

                if (empty($widget['more']['reportId'])) {
                    continue;
                }

                $offered = array();

                foreach (explode(',', (string) (($widget['query'] ?? array())['dimensions'] ?? '')) as $dimension) {

                    $dimension = trim($dimension);

                    $offered = array_merge($offered,
                        array_column($targets[$dimension] ?? array(), 'id'));
                }

                if (!in_array($widget['more']['reportId'], $offered, true)) {

                    $missed[] = sprintf('%s:%s -> %s', basename($file),
                        $widget['id'] ?? '?', $widget['more']['reportId']);
                }
            }
        }

        $this->assertSame(array(), $missed,
            "The builder would not offer these links, which shipped reports already make:\n  "
            . implode("\n  ", $missed));
    }

    public function testAFullReportLinkIsStoredAndItsDestinationChecked(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['more'] = array(
            'reportId' => 'pages',
            'label'    => 'View Full Report »',
        );

        $this->assertSame('', CustomReports::validate($definition));

        $definition['widgets'][1]['more']['reportId'] = 'no-such-report';

        $this->assertStringContainsString('not a registered report',
            CustomReports::validate($definition));
    }

    /**
     * ...including one that IS a report but cannot be reached without a value.
     *
     * Refused with the reason and with what to do instead, because the author
     * probably wanted the row link.
     */
    public function testAFullReportLinkToADetailReportIsRefused(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['more'] = array( 'reportId' => 'browser-detail' );

        $error = CustomReports::validate($definition);

        $this->assertStringContainsString('browserType', $error);
        $this->assertStringContainsString('Link the rows instead', $error);
    }

    public function testAFullReportLinkCannotCarryAnythingElse(): void
    {
        $definition = $this->definition();
        $definition['widgets'][1]['more'] = array(
            'reportId' => 'pages',
            'do'       => 'base.optionsGeneral',
        );

        $this->assertStringContainsString('unknown key', CustomReports::validate($definition));
    }

    // ------------------------------------------------------------------
    // A widget that draws one metric must name it
    // ------------------------------------------------------------------

    /**
     * A pie cannot inherit a report metric set.
     *
     * The set is several metrics and a pie draws one, so nothing says which --
     * and the renderer's answer was an empty chartMetric, which draws nothing
     * at all. An empty chart looks like a site with no data, which is why this
     * went unnoticed rather than reported.
     *
     * There is deliberately no fallback to "the first metric of the query":
     * half the shipped trends name no chartMetric and draw no chart on purpose.
     */
    public function testAPieMustNameItsOwnMetric(): void
    {
        $this->assertContains('pie', CustomReports::SINGLE_FIELD_TYPES);

        $definition = $this->definition();
        $definition['metrics'] = 'visits,uniqueVisitors';
        $definition['widgets'][1] = array(
            'type' => 'pie', 'id' => 'p', 'container' => 'p',
            'query' => array('dimensions' => 'medium'),
        );

        $this->assertStringContainsString('0 metrics', CustomReports::validate($definition));

        $definition['widgets'][1]['query']['metrics'] = 'visits';

        $this->assertSame('', CustomReports::validate($definition));
    }

    // ------------------------------------------------------------------
    // The trend card
    // ------------------------------------------------------------------

    /**
     * A card names its own metrics, and is refused if it names none.
     *
     * Every other multi-metric widget may leave them out and inherit the
     * report metric set -- which is a property of the SITE, and is how one
     * report shows its dimension measured three ways. A card is the other kind
     * of thing: half a row, showing the figures its author chose, and a set
     * would replace those with three to six whose boxes do not fit that width.
     *
     * So it cannot inherit, and the interesting half is that an empty list has
     * to be REFUSED rather than left empty. Nothing else would notice: an empty
     * metrics list is legal on every type that can inherit, so a card with none
     * would render an empty widget and say nothing.
     */
    public function testATrendCardMustNameItsOwnMetrics(): void
    {
        $this->assertContains('trend-card', CustomReports::OWN_METRIC_TYPES);

        $definition = $this->definition();
        $definition['metrics'] = 'visits,uniqueVisitors';
        $definition['widgets'][1] = array(
            'type' => 'trend-card', 'id' => 'c', 'container' => 'c',
            'query' => array('dimensions' => 'date'),
        );

        $says = CustomReports::validate($definition);

        $this->assertStringContainsString('Trend card', $says);
        $this->assertStringContainsString('does not take the report metric set', $says);

        $definition['widgets'][1]['query']['metrics'] = 'visits';

        $this->assertSame('', CustomReports::validate($definition));
    }

    /**
     * ...and SEVERAL of them, which is what separates it from a card or a pie.
     *
     * Those draw exactly one metric and are refused a second. A trend card
     * draws a box per metric above its chart, so a list is the normal case --
     * the rule it carries is "your own", not "only one".
     */
    public function testATrendCardTakesSeveralMetricsOfItsOwn(): void
    {
        $this->assertNotContains('trend-card', CustomReports::SINGLE_METRIC_TYPES);

        $definition = $this->definition();
        $definition['widgets'][1] = array(
            'type' => 'trend-card', 'id' => 'c', 'container' => 'c',
            'chartMetric' => 'visits',
            'query' => array('metrics' => 'visits,uniqueVisitors', 'dimensions' => 'date'),
        );

        $this->assertSame('', CustomReports::validate($definition));
    }

    /**
     * A card cannot be broken out by a dimension.
     *
     * A trend can: its second dimension becomes a line per value, with a legend
     * under the chart and a grid of those values under that. None of it fits
     * half a row, so a card that could be broken out would be a trend that had
     * been made too small to read.
     */
    public function testATrendCardCannotBeBrokenOut(): void
    {
        $this->assertSame(0, CustomReports::FIXED_DIMENSION_EXTRA['trend-card']);
        $this->assertSame(1, CustomReports::FIXED_DIMENSION_EXTRA['trend']);

        $definition = $this->definition();
        $definition['widgets'][1] = array(
            'type' => 'trend-card', 'id' => 'c', 'container' => 'c',
            'chartMetric' => 'visits',
            'query' => array('metrics' => 'visits', 'dimensions' => 'date,medium'),
        );

        $this->assertStringContainsString('Trend card',
            CustomReports::validate($definition));

        // ...and the same query IS accepted on a full-width trend, so the
        // refusal is about the type rather than about the dimension.
        $definition['widgets'][1]['type'] = 'trend';

        $this->assertSame('', CustomReports::validate($definition));
    }

    /**
     * The chart-metric question is asked on exactly the types that plot one.
     *
     * The builder used to carry its own copy of this list in JavaScript, which
     * is the copy that does not get updated when a type is added -- and the
     * symptom is a chart with no line rather than an error anybody notices.
     */
    public function testTheChartTypesAreOfferedAChartMetric(): void
    {
        $this->assertSame(
            array('trend', 'trend-card', 'pie'),
            CustomReports::CHART_TYPES);

        foreach (CustomReports::CHART_TYPES as $type) {
            $this->assertArrayHasKey($type, CustomReports::WIDGET_TYPES,
                "$type is offered a chart metric but is not a type anyone can build");
        }
    }

    /**
     * Everything the builder's controller sets reaches its template.
     *
     * The builder's body is a template with its OWN scope, so a value the
     * controller set and View\CustomReportEdit does not name is simply absent
     * -- and View::get() answers FALSE for an absent key rather than null or an
     * error. So a forgotten line here does not fail: `(array) false` is
     * `array(false)`, the JavaScript gets `[false]`, and an indexOf against it
     * quietly says no.
     *
     * That is not hypothetical. Adding CHART_TYPES and OWN_METRIC_TYPES to the
     * controller without adding them here left the builder deciding that NO
     * type draws a chart -- so the chart-metric field would have disappeared
     * from a trend and a pie, with nothing anywhere saying why.
     *
     * Read from the source rather than by rendering, because the failure is a
     * MISSING line and the only place a missing line exists is the file.
     */
    public function testTheBuilderViewForwardsEverythingItsControllerSets(): void
    {
        $controller = file_get_contents(
            OWA_DIR . 'modules/Base/Controller/CustomReportEdit.php' );
        $view = file_get_contents(
            OWA_DIR . 'modules/Base/View/CustomReportEdit.php' );

        /*
         * SINGLE-quoted, and it matters. In a double-quoted PHP string
         * `$this->set` interpolates away to nothing, and the pattern would then
         * match something else entirely -- confidently.
         */
        preg_match_all( '~\$this->set\(\s*.([a-z_0-9]+).~', $controller, $set );
        preg_match_all( '~\$this->body->set\(\s*.([a-z_0-9]+).~', $view, $forwarded );

        // The check has to be capable of failing: both sides must have found
        // something to compare.
        $this->assertGreaterThan( 15, count( $set[1] ) );
        $this->assertGreaterThan( 15, count( $forwarded[1] ) );

        /*
         * Two are deliberately not forwarded, and neither is read by the body:
         *
         *   custom_report -- the whole row, set so the controller's own later
         *   steps can read it. The template gets the name and the definition,
         *   which is what it draws.
         *
         *   title -- the PAGE title, read by the outer report view rather than
         *   by the body.
         */
        $exempt = array( 'custom_report', 'title' );

        $missing = array_values( array_diff(
            array_unique( $set[1] ), array_unique( $forwarded[1] ), $exempt ) );

        $this->assertSame( array(), $missing,
            'the builder controller sets these and the view does not forward them, so the '
          . 'template reads false for each: ' . implode( ', ', $missing ) );
    }

    /**
     * The builder is handed the same answer the engine would give.
     *
     * It narrows its pickers with these maps, so if they disagreed with
     * ResultSetManager the builder would offer combinations the save then
     * refuses -- which is the failure this whole check exists to remove.
     */
    public function testTheBuilderIsGivenTheCompatibilityMaps(): void
    {
        $metrics = \OWA\Module\Base\Controller\CustomReportEdit::metricEntities();

        $this->assertNotEmpty($metrics);
        $this->assertContains('base.click', $metrics['domClicks']);
        $this->assertNotContains('base.click', $metrics['visits']);

        $dimensions = \OWA\Module\Base\Controller\CustomReportEdit::dimensionEntities();

        $this->assertNotEmpty($dimensions);
        $this->assertContains('base.request', $dimensions['pagePath']);
        $this->assertNotContains('base.session', $dimensions['pagePath'],
            'pagePath is not on the session, which is why it narrows the choice of table');
    }

    public function testAtMostTenWidgets(): void
    {
        $this->assertSame(10, CustomReports::MAX_WIDGETS,
            'the limit is ten; change the requirement before changing this');

        $definition = $this->definition();
        $widget     = $definition['widgets'][0];

        $definition['widgets'] = array_fill(0, 10, $widget);
        $this->assertSame('', CustomReports::validate($definition), 'ten is allowed');

        $definition['widgets'] = array_fill(0, 11, $widget);
        $this->assertStringContainsString('at most', CustomReports::validate($definition),
            'eleven is refused');
    }

    public function testAReportNeedsAtLeastOneWidget(): void
    {
        $definition = $this->definition();
        $definition['widgets'] = array();

        $this->assertNotSame('', CustomReports::validate($definition));
    }

    /**
     * The narrowings that make user authorship safe are still enforced, by the
     * renderer's own validator which this delegates to. Asserted here because
     * they are the reason this feature can exist at all -- a later relaxation
     * of KNOWN_KEYS would show up as this test failing.
     */
    public function testADefinitionCannotNameItsOwnRenderer(): void
    {
        $definition = $this->definition();
        $definition['subview'] = 'base.someOtherView';

        $error = CustomReports::validate($definition);

        $this->assertNotSame('', $error,
            'a definition that could name a view could point at any view in the tree');
        $this->assertStringContainsString('subview', $error);
    }

    public function testAnUnknownTopLevelKeyIsRefused(): void
    {
        $definition = $this->definition();
        $definition['setings'] = array('foo' => 'bar');

        $this->assertStringContainsString('setings', CustomReports::validate($definition));
    }

    // ------------------------------------------------------------------
    // Storing
    // ------------------------------------------------------------------

    public function testARoundTripKeepsTheDefinition(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Round Trip'));

        $this->assertTrue($saved['ok'], $saved['error']);

        $loaded = CustomReports::load($saved['id']);

        $this->assertNotNull($loaded);
        $this->assertSame('Round Trip', $loaded['name']);
        $this->assertSame(self::AUTHOR, $loaded['user_id']);
        $this->assertCount(2, $loaded['definition']['widgets']);

        // Stored strings are entity-encoded on write, so a definition that was
        // not decoded on the way back out would not parse at all.
        $this->assertSame('pagePath', $loaded['definition']['widgets'][1]['query']['dimensions']);
    }

    /**
     * The name and the report heading are ONE field.
     *
     * Two fields that must agree is a way for the roster and the report itself
     * to disagree, so the title is set from the name rather than asked for
     * twice.
     */
    public function testTheNameBecomesTheReportTitle(): void
    {
        $this->requireDb();

        $definition = $this->definition();
        $definition['title'] = 'something else entirely';

        $saved  = $this->store(array('name' => 'The Real Name', 'definition' => $definition));
        $loaded = CustomReports::load($saved['id']);

        $this->assertSame('The Real Name', $loaded['definition']['title']);
    }

    public function testAnInvalidDefinitionIsNotStored(): void
    {
        $this->requireDb();

        $definition = $this->definition();
        $definition['widgets'][1]['query']['dimensions'] = 'notADimension';

        $before = count(CustomReports::roster('', true));
        $saved  = $this->store(array('name' => 'Should Not Store', 'definition' => $definition));

        $this->assertFalse($saved['ok']);
        $this->assertStringContainsString('notADimension', $saved['error']);
        $this->assertSame($before, count(CustomReports::roster('', true)),
            'a refused report must not leave a row behind');
    }

    public function testAReportNeedsAName(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => '   '));

        $this->assertFalse($saved['ok']);
        $this->assertStringContainsString('name', $saved['error']);
    }

    public function testDefinitionsMayArriveAsJsonText(): void
    {
        $this->requireDb();

        $saved = $this->store(array(
            'name'       => 'From JSON',
            'definition' => json_encode($this->definition()),
        ));

        $this->assertTrue($saved['ok'], $saved['error']);
        $this->assertCount(2, CustomReports::load($saved['id'])['definition']['widgets']);
    }

    public function testMalformedJsonIsRefusedWithItsReason(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Broken', 'definition' => '{not json'));

        $this->assertFalse($saved['ok']);
        $this->assertStringContainsString('JSON', $saved['error']);
    }

    /** An edit must not silently reassign authorship. */
    public function testEditingDoesNotChangeTheCreator(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Originally Mine'));

        $edited = CustomReports::save(array(
            'id'         => $saved['id'],
            'name'       => 'Edited By An Admin',
            'definition' => $this->definition(),
        ), 'an-admin@example.test');

        $this->assertTrue($edited['ok'], $edited['error']);
        $this->assertSame($saved['id'], $edited['id'], 'an edit is not a new report');

        $loaded = CustomReports::load($saved['id']);

        $this->assertSame('Edited By An Admin', $loaded['name']);
        $this->assertSame(self::AUTHOR, $loaded['user_id'],
            'the creator is a fact about the report, not about who last touched it');
    }

    // ------------------------------------------------------------------
    // Who sees what
    // ------------------------------------------------------------------

    public function testTheRosterShowsAUserOnlyTheirOwnReports(): void
    {
        $this->requireDb();

        $mine   = $this->store(array('name' => 'Mine'), self::AUTHOR);
        $theirs = $this->store(array('name' => 'Theirs'), self::OTHER);

        $ids = array_column(CustomReports::roster(self::AUTHOR), 'id');

        $this->assertContains($mine['id'], $ids);
        $this->assertNotContains($theirs['id'], $ids,
            'a non-admin roster must not list somebody else\'s report');
    }

    public function testAUserWhoMaySeeEverythingSeesBothReports(): void
    {
        $this->requireDb();

        $mine   = $this->store(array('name' => 'Mine For Admin'), self::AUTHOR);
        $theirs = $this->store(array('name' => 'Theirs For Admin'), self::OTHER);

        $ids = array_column(CustomReports::roster(self::AUTHOR, true), 'id');

        $this->assertContains($mine['id'], $ids);
        $this->assertContains($theirs['id'], $ids);
    }

    /** The roster has to say who made each report, which means carrying it. */
    public function testTheRosterCarriesTheNameAndTheCreator(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Named And Attributed'));

        $row = null;

        foreach (CustomReports::roster(self::AUTHOR) as $candidate) {
            if ($candidate['id'] === $saved['id']) {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row);
        $this->assertSame('Named And Attributed', $row['name']);
        $this->assertSame(self::AUTHOR, $row['user_id']);
    }

    /**
     * Editing is narrower than viewing.
     *
     * @dataProvider editPermissionProvider
     */
    public function testWhoMayEdit(string $user, bool $seesAll, bool $expected): void
    {
        $report = array('id' => '1', 'user_id' => self::AUTHOR);

        $this->assertSame($expected, CustomReports::mayEdit($report, $user, $seesAll));
    }

    public static function editPermissionProvider(): array
    {
        return array(
            'the creator'        => array(self::AUTHOR, false, true),
            'somebody else'      => array(self::OTHER,  false, false),
            'an admin'           => array(self::OTHER,  true,  true),
            'nobody at all'      => array('',           false, false),
        );
    }

    public function testAMissingReportIsNotEditableByAnyone(): void
    {
        $this->assertFalse(CustomReports::mayEdit(null, self::AUTHOR, true));
    }

    public function testLoadingSomethingThatIsNotThereReturnsNull(): void
    {
        $this->requireDb();

        $this->assertNull(CustomReports::load('9100000000000000999'));
        $this->assertNull(CustomReports::load(''));
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * A custom report renders through the ordinary report dispatcher, which is
     * what gives it the same chrome -- site filter and date picker -- as every
     * other report, without this feature supplying either.
     */
    public function testACustomReportRendersWithTheOrdinaryReportChrome(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Renders'));

        $data = (array) (new \OWA\Module\Base\Controller\Report(array(
            'reportId' => 'custom-' . $saved['id'],
            'period'   => 'last_thirty_days',
        )))->doAction();

        $this->assertNotEmpty($data['subview'] ?? '', 'the report did not render');

        foreach (array('sites', 'currentSiteId', 'period', 'dom_id') as $key) {
            $this->assertArrayHasKey($key, $data,
                "a custom report must come with $key like every other report");
        }

        foreach (array('siteId', 'period', 'startDate', 'endDate') as $key) {
            $this->assertArrayHasKey($key, $data['params'],
                "params.$key is what carries the selection into the report's own links");
        }
    }

    /** The URL is the whole address: the same id renders the same report. */
    public function testTheSameUrlRendersTheSameReport(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Shareable'));

        $render = function () use ($saved) {
            return (array) (new \OWA\Module\Base\Controller\Report(array(
                'reportId' => 'custom-' . $saved['id'],
                'period'   => 'last_thirty_days',
            )))->doAction();
        };

        $first  = $render();
        $second = $render();

        $this->assertSame($first['subview'], $second['subview']);
        $this->assertNotEmpty($first['subview']);
    }

    /**
     * A stored definition is re-validated on the way OUT.
     *
     * Not paranoia: the registry changes under a saved report -- a module
     * deactivated since it was written takes its metrics with it -- and a row
     * can be edited by something that is not the builder. Rendering it with the
     * bad widget dropped would show a report quietly missing a section.
     */
    public function testADefinitionThatStoppedBeingValidIsRefusedRatherThanPartlyRendered(): void
    {
        $this->requireDb();

        $saved = $this->store(array('name' => 'Goes Stale'));

        // Corrupt the stored row directly, which is the situation being modelled.
        $entity = \OWA\Core\CoreAPI::entityFactory('base.custom_report');
        $entity->load($saved['id']);

        $definition = $this->definition();
        $definition['widgets'][1]['query']['dimensions'] = 'aDimensionThatNoLongerExists';

        $entity->set('definition', json_encode($definition));
        $entity->update();

        $data = (array) (new \OWA\Module\Base\Controller\Report(array(
            'reportId' => 'custom-' . $saved['id'],
            'period'   => 'last_thirty_days',
        )))->doAction();

        $this->assertEmpty($data['subview'] ?? '',
            'a report that no longer validates must not render');
    }

    public function testAnUnknownCustomIdIsNotFound(): void
    {
        $this->requireDb();

        $data = (array) (new \OWA\Module\Base\Controller\Report(array(
            'reportId' => 'custom-9100000000000000998',
            'period'   => 'last_thirty_days',
        )))->doAction();

        $this->assertEmpty($data['subview'] ?? '');
    }

    // ------------------------------------------------------------------
    // Who may reach what
    // ------------------------------------------------------------------

    /** Put the current user in a role, for the duration of one assertion. */
    private function asRole(string $role, bool $authenticated = true): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole($role);
        $user->setAuthStatus($authenticated);
    }

    /**
     * Authoring is gated on edit_reports, which is admin-only by default.
     *
     * The point of a separate capability is that an installation can grant it
     * to analysts without granting them everything else an admin has -- so the
     * test that matters is that a role WITHOUT it is refused, not merely that
     * an admin is allowed.
     */
    public function testTheBuilderIsRefusedWithoutEditReports(): void
    {
        $this->requireDb();

        $this->asRole('viewer');

        $refused = (array) (new \OWA\Module\Base\Controller\CustomReportEdit(
            array('do' => 'base.customReportEdit')))->doAction();

        $this->assertArrayNotHasKey('subview', $refused,
            'a role without edit_reports reached the builder');

        // ...and it is allowed with the capability, so the refusal above is
        // authorization rather than a broken screen.
        $this->asRole('admin');

        $allowed = (array) (new \OWA\Module\Base\Controller\CustomReportEdit(
            array('do' => 'base.customReportEdit')))->doAction();

        $this->assertSame('base.customReportEdit', $allowed['subview'] ?? null);
    }

    /**
     * Each screen asks for the capability that matches what it IS.
     *
     * The roster asks for view_site_list -- the capability that means "any
     * signed-in user". Not view_reports, which looks like the obvious choice
     * and is wrong: view_reports sits in capabilitiesThatRequireSiteAccess, so
     * it is only ever satisfied against a particular site, and the roster has
     * no site. Requiring it refused every non-admin with "No access to any
     * site", which the e2e suite caught and no unit test could.
     *
     * ASSERTED AS THE DECLARED CAPABILITY rather than by driving each screen,
     * for the same reason: passing view_reports needs a user with a real grant
     * on a real site, and a test user given only a ROLE has no grants. The
     * behavioural tests -- an analyst opening a report an admin built, and an
     * analyst being refused the builder -- live in the e2e suite, where the
     * fixture users are real.
     *
     * @dataProvider declaredCapabilityProvider
     */
    public function testEachScreenAsksForTheRightCapability(string $class, string $expected): void
    {
        $controller = new $class(array());

        $this->assertSame($expected, $controller->getRequiredCapability());
    }

    public static function declaredCapabilityProvider(): array
    {
        return array(
            'the roster is for any signed-in user' => array(
                '\OWA\Module\Base\Controller\CustomReports', 'view_site_list'),
            'building is for authors' => array(
                '\OWA\Module\Base\Controller\CustomReportEdit', 'edit_reports'),
            'saving is for authors' => array(
                '\OWA\Module\Base\Controller\CustomReportSave', 'edit_reports'),
            'deleting is for authors' => array(
                '\OWA\Module\Base\Controller\CustomReportDelete', 'edit_reports'),
        );
    }

    /**
     * Viewing a custom report is not gated on authorship.
     *
     * The dispatcher declares no capability of its own and delegates, so a
     * custom report is authorised exactly like a shipped one -- by
     * ConfiguredReport's view_reports. Asserted by rendering as a role that
     * HAS edit_reports removed from under it would be the same test as above;
     * what matters here is that the custom branch does not add a check of its
     * own, which is what would silently break sharing.
     */
    public function testViewingACustomReportAddsNoCapabilityOfItsOwn(): void
    {
        $dispatcher = new \OWA\Module\Base\Controller\Report(array());

        $this->assertEmpty($dispatcher->getRequiredCapability(),
            'the report dispatcher must delegate authorisation, not add to it');

        $configured = new \OWA\Core\ConfiguredReport(array());

        $this->assertSame('view_reports', $configured->getRequiredCapability(),
            'a custom report is authorised as a report, not as an edit');
    }

    /** An unauthenticated request reaches neither the roster nor the builder. */
    public function testAnUnauthenticatedRequestIsRefused(): void
    {
        $this->requireDb();

        $this->asRole('everyone', false);

        foreach (array(
            '\OWA\Module\Base\Controller\CustomReports'    => 'base.customReports',
            '\OWA\Module\Base\Controller\CustomReportEdit' => 'base.customReportEdit',
        ) as $class => $action) {

            $refused = (array) (new $class(array('do' => $action)))->doAction();

            $this->assertArrayNotHasKey('subview', $refused,
                "$action was reachable unauthenticated");
        }

        $this->asRole('admin');
    }

        /**
     * The reserved prefix is checked BEFORE the registry, so a module cannot
     * register an id in that space and shadow somebody's saved report.
     */
    public function testTheCustomPrefixIsReservedAgainstTheRegistry(): void
    {
        $registry = (array) \OWA\Core\CoreAPI::getReportRegistry();

        foreach (array_keys($registry) as $id) {

            $this->assertStringStartsNotWith(
                \OWA\Module\Base\Controller\Report::CUSTOM_PREFIX, (string) $id,
                "report id '$id' sits in the id space reserved for custom reports");
        }
    }
}
