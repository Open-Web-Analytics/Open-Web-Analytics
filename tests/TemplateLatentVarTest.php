<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Regression guard for three latent bugs the $view migration surfaced.
 *
 * The migration to ViewScope (see ViewScopeCompatTest) rewrote OWA's own
 * templates to read view data as `$view->name`. Twenty names were left on the
 * bare-variable path because migrating them on inference would have risked a
 * 500 -- and three of those turned out to be reads of a variable that NO
 * controller, view or includer sets anywhere in the tree:
 *
 *   report_dimensionalTrend.php:57  $tag['sort']   -- a typo for $tab['sort']
 *   generic_table.php:20            $th_scope      -- rendered scope=""
 *   report_visitors_roster.php:2    $date_label    -- rendered an empty label
 *
 * PHPStan reported all three as variable.undefined. They could not simply be
 * renamed to `$view->...`: an undefined bare variable is a warning that yields
 * null, whereas `$view->neverSet` throws, so the fix had to establish where the
 * value legitimately comes from. These tests pin those answers by rendering the
 * ACTUAL template files.
 */
final class TemplateLatentVarTest extends TestCase
{
    /**
     * A Template with makeApiLink() stubbed to echo back the params it was
     * handed. report_dimensionalTrend.php builds its REST URLs through that
     * method, and the real one reaches getCurrentUser() for a nonce, which
     * needs a DB. Stubbing it keeps the test DB-free while still exercising the
     * template's own logic -- the sort value it chooses is what is under test.
     */
    private function templateSpy(): object
    {
        return new class('base') extends \OWA\Core\Template {

            /** @var array<int, array<string, mixed>> every makeApiLink() param set, in order */
            public array $apiCalls = [];

            function makeApiLink($params = array(), $add_state = false, $add_apiKey = false) {

                $this->apiCalls[] = $params;
                return 'API?sort=' . ($params['sort'] ?? '<none>');
            }
        };
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(object $t, string $file, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }
        $this->assertTrue($t->set_template($file), "could not locate template $file");
        return $t->fetch();
    }

    /**
     * The dimension grid's sort falls back to the tab's own sort when the view
     * sets no sort of its own.
     *
     * WHY THIS MATTERS: `$view->sort ?: $tag['sort']` could never fall back --
     * $tag is never set, so the fallback yielded null and the grid requested the
     * REST API with NO sort at all. All 22 shipped controllers that use
     * base.reportDimension do set 'sort', so this is latent for OWA's own
     * reports; it fires for a third-party module (or a future controller) that
     * relies on the per-tab sort instead. $tab is the foreach value at line 29
     * of the same template, and every tab array built in ReportController has a
     * 'sort' key.
     */
    public function testDimensionGridFallsBackToTheTabSortWhenTheViewSetsNone(): void
    {
        $t = $this->templateSpy();

        $out = $this->render($t, 'report_dimensionalTrend.php', [
            'tabs' => [
                'site_usage' => [
                    'tab_label'          => 'Site Usage',
                    'metrics'            => 'visits',
                    'sort'               => 'visits-',
                    'trendchartmetric'   => 'visits',
                ],
            ],
            'metrics'          => 'visits',
            'dimensions'       => 'browser',
            'sort'             => '',   // the view sets no sort -> fall back to the tab's
            'resultsPerPage'   => 25,
            'dimensionLink'    => '',
            'trendChartMetric' => 'visits',
            'trendTitle'       => '',
            'constraints'      => '',
            'gridTitle'        => '',
            'gridFormatters'   => '',
            'excludeColumns'   => '',
            'dom_id'           => 'base-reportBrowsers',
            'pie'              => false,
            'hideGrid'         => false,
        ]);

        // Two API links per tab: the trend (always sorted by date) then the grid.
        $this->assertSame(['date', 'visits-'], array_column($t->apiCalls, 'sort'));
        $this->assertStringContainsString("var dimurl = 'API?sort=visits-'", $out);
        // Against the $tag typo this read 'API?sort=<none>'.
        $this->assertStringNotContainsString('sort=<none>', $out);
    }

    /**
     * An explicit sort on the view still wins over the tab's.
     *
     * This one passes against the buggy template too, deliberately: `?:`
     * short-circuits, so $tag was never evaluated on this path. That is exactly
     * why the typo survived -- every shipped report sets a sort. The test is
     * here to keep the precedence rule from being "fixed" in the other
     * direction.
     */
    public function testAnExplicitViewSortWinsOverTheTabSort(): void
    {
        $t = $this->templateSpy();

        $out = $this->render($t, 'report_dimensionalTrend.php', [
            'tabs' => [
                'site_usage' => ['tab_label' => 'Site Usage', 'metrics' => 'visits', 'sort' => 'visits-'],
            ],
            'metrics'          => 'visits',
            'dimensions'       => 'browser',
            'sort'             => 'pageViews-',
            'resultsPerPage'   => 25,
            'dimensionLink'    => '',
            'trendChartMetric' => 'visits',
            'trendTitle'       => '',
            'constraints'      => '',
            'gridTitle'        => '',
            'gridFormatters'   => '',
            'excludeColumns'   => '',
            'dom_id'           => 'base-reportBrowsers',
            'pie'              => false,
            'hideGrid'         => false,
        ]);

        $this->assertSame(['date', 'pageViews-'], array_column($t->apiCalls, 'sort'));
        $this->assertStringContainsString("var dimurl = 'API?sort=pageViews-'", $out);
    }

    /**
     * Column headers carry scope="col".
     *
     * The <TH> elements are the single header row inside <thead>, so "col" is
     * the only correct value -- and nothing ever set $th_scope, so no caller can
     * want anything else. Previously every header rendered scope="", which is
     * invalid HTML and tells a screen reader nothing.
     */
    public function testGenericTableHeadersDeclareColumnScope(): void
    {
        $out = $this->render(new \OWA\Core\Template('base'), 'generic_table.php', [
            'rows'               => [['Firefox', '42']],
            'labels'             => ['Browser', 'Visits'],
            'table_id'           => 'owa-test-table',
            'table_class'        => 'owa_table',
            'sort_table_class'   => 'owa_sortable',
            'table_footer'       => '',
            'col_count'          => 2,
            'table_row_template' => '',
            'show_error'         => false,
        ]);

        $this->assertStringContainsString('<TH scope="col">Browser</TH>', $out);
        $this->assertStringNotContainsString('scope=""', $out);
    }

    /**
     * The visitor roster's headline shows the reporting period.
     *
     * `$date_label` was never set by anything, so the <H2> rendered as
     * "Visitors: " with a dangling colon. Report::setPeriod() already pushes
     * 'period_label' onto the subview body unconditionally (Report.php:140),
     * which is where the label was always meant to come from.
     */
    public function testVisitorsRosterHeadlineShowsThePeriodLabel(): void
    {
        $out = $this->render(new \OWA\Core\Template('base'), 'report_visitors_roster.php', [
            'headline'     => 'Visitors',
            'period_label' => 'Last 30 Days',
            'visitors'     => [],
        ]);

        $this->assertStringContainsString('Visitors: Last 30 Days', $out);
        // Guard the dangling-colon regression specifically.
        $this->assertDoesNotMatchRegularExpression('/Visitors:\s*<\/H2>/i', $out);
    }
}
