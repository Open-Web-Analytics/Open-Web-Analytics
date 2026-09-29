<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Migration\CustomReportRewriter;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A 1.14 custom report rewritten into v2's names, per the rules decided in
 * PLAN.html 2.21: rename what has an equivalent, drop what has none, drop a
 * widget only when what is left cannot render, and say every time.
 */
final class CustomReportRewriterTest extends TestCase
{
    private function rewrite(array $widgets, array $report = []): array
    {
        return (new CustomReportRewriter())->rewrite(['title' => 'Weekly', 'widgets' => $widgets] + $report);
    }

    public function testNamesAreRenamedDroppedAndTheSortFollows(): void
    {
        $out = $this->rewrite([[
            'type'  => 'grid',
            'query' => ['metrics' => 'visits,pageViews,actions', 'dimensions' => 'pagePath', 'sort' => 'actions-'],
        ]]);

        $query = $out['definition']['widgets'][0]['query'];

        $this->assertSame('sessions,pageViews', $query['metrics']);
        $this->assertSame('pagePathPlusQuery', $query['dimensions']);
        $this->assertArrayNotHasKey('sort', $query, 'the sort named a dropped metric');

        $this->assertContains('widget 1: visits -> sessions', $out['changes']);
        $this->assertContains('widget 1: dropped actions, which v2 does not have', $out['changes']);
        $this->assertContains('widget 1: pagePath -> pagePathPlusQuery', $out['changes']);
    }

    public function testASortOnARenamedNameKeepsItsDirection(): void
    {
        $out = $this->rewrite([['type' => 'grid',
            'query' => ['metrics' => 'visits', 'dimensions' => 'source', 'sort' => 'visits-']]]);

        $this->assertSame('sessions-', $out['definition']['widgets'][0]['query']['sort']);
    }

    public function testAWidgetWithNoMetricLeftIsDropped(): void
    {
        $out = $this->rewrite([
            ['type' => 'grid', 'query' => ['metrics' => 'actions,uniqueActions', 'dimensions' => 'actionName']],
            ['type' => 'metric-boxes', 'query' => ['metrics' => 'visits']],
        ]);

        $this->assertCount(1, $out['definition']['widgets']);
        $this->assertSame('sessions', $out['definition']['widgets'][0]['query']['metrics']);
        $this->assertStringContainsString('widget 1 dropped', implode("\n", $out['changes']));
    }

    public function testAGridThatLostEveryDimensionIsDropped(): void
    {
        $out = $this->rewrite([['type' => 'grid', 'query' => ['metrics' => 'visits', 'dimensions' => 'exitPageUrl']]]);

        $this->assertSame([], $out['definition']['widgets']);
        $this->assertStringContainsString('collapse into a single total', implode("\n", $out['changes']));
    }

    public function testConstraintsAreRenamedTranslatedOrDroppedAndADropSaysItWidens(): void
    {
        $out = $this->rewrite([['type' => 'grid', 'query' => ['metrics' => 'visits', 'dimensions' => 'source'],
            'constraints' => 'medium==organic-search,isNewVisitor==1,actionName==signup,browserType=@Chrome']]);

        $this->assertSame('sessionMedium==organic-search,newVsReturning==New,browserType=@Chrome',
            $out['definition']['widgets'][0]['constraints']);
        $this->assertStringContainsString('now counts more than it did', implode("\n", $out['changes']));
    }

    public function testARedefinedNameIsKeptAndSaidToBeRedefined(): void
    {
        $out = $this->rewrite([['type' => 'metric-boxes', 'query' => ['metrics' => 'bounceRate']]]);

        $this->assertSame('bounceRate', $out['definition']['widgets'][0]['query']['metrics']);
        $this->assertContains('widget 1: bounceRate is now the share of sessions that were not engaged', $out['changes']);
    }

    public function testChartMetricsAndTheReportMetricSetAreMapped(): void
    {
        $out = $this->rewrite(
            [['type' => 'trend', 'query' => ['metrics' => 'visits,uniqueVisitors'], 'chartMetric' => 'visits,feedReaders']],
            ['metrics' => 'visits,feedRequests']);

        $this->assertSame('sessions', $out['definition']['widgets'][0]['chartMetric']);
        $this->assertSame('sessions', $out['definition']['metrics']);
    }

    public function testALinkToAGoneReportOrFromAGoneColumnIsRemoved(): void
    {
        $out = $this->rewrite([
            ['type' => 'grid', 'query' => ['metrics' => 'visits', 'dimensions' => 'pagePath'],
             'link' => ['linkColumn' => 'pagePath', 'valueColumns' => 'pagePath',
                        'template' => ['do' => 'base.report', 'reportId' => 'action-detail', 'pagePath' => '%s']],
             'more' => ['reportId' => 'feeds']],
            ['type' => 'grid', 'query' => ['metrics' => 'visits', 'dimensions' => 'pagePath'],
             'link' => ['linkColumn' => 'pagePath', 'valueColumns' => 'pagePath',
                        'template' => ['do' => 'base.report', 'reportId' => 'document', 'pagePath' => '%s']]],
        ]);

        $this->assertArrayNotHasKey('link', $out['definition']['widgets'][0]);
        $this->assertArrayNotHasKey('more', $out['definition']['widgets'][0]);

        // Page Detail is read by pagePath; the rename to pagePathPlusQuery
        // leaves the link pointing at a report that does not read it.
        $this->assertArrayNotHasKey('link', $out['definition']['widgets'][1]);
        $this->assertStringContainsString('is not read by pagePathPlusQuery', implode("\n", $out['changes']));
    }

    /** A full-report link to Pages fits pagePath, not the pagePathPlusQuery it becomes. */
    public function testAMoreLinkThatNoLongerFitsTheRenamedDimensionIsRemoved(): void
    {
        $out = $this->rewrite([['type' => 'grid', 'query' => ['metrics' => 'visits', 'dimensions' => 'pagePath'],
            'more' => ['reportId' => 'pages']]]);

        $this->assertArrayNotHasKey('more', $out['definition']['widgets'][0]);
        $this->assertSame('', \OWA\Module\Base\Classes\CustomReports::validate($out['definition']), 'it renders');
    }

    public function testAWidgetWhoseDimensionsDidNotChangeKeepsItsLinks(): void
    {
        $out = $this->rewrite([['type' => 'grid', 'query' => ['metrics' => 'visits', 'dimensions' => 'browserType'],
            'more' => ['reportId' => 'browsers']]]);

        $this->assertSame('browsers', $out['definition']['widgets'][0]['more']['reportId']);
    }

    public function testAReportInV2NamesIsLeftAlone(): void
    {
        $widgets = [['type' => 'grid', 'query' => ['metrics' => 'sessions', 'dimensions' => 'sessionSource']]];

        $out = $this->rewrite($widgets);

        $this->assertSame([], $out['changes']);
        $this->assertSame($widgets, $out['definition']['widgets']);
    }
}
