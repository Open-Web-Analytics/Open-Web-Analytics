<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What a report definition may say, and what it means.
 *
 * The equivalence test proves the 53 shipped definitions reproduce their
 * controllers. These pin the rules those definitions are written in, which is
 * the part a new report -- or a user-built one later -- depends on.
 *
 * The format deliberately has no expression language. A placeholder substitutes
 * a value and nothing else; the one transformation a parameter can need is
 * declared on the parameter. That is what keeps a definition data rather than
 * something that has to be evaluated.
 */
final class ReportDefinitionFormatTest extends TestCase
{
    /** Render a definition and return the declared bag. */
    private function declared( array $definition, array $params = array() ): array
    {
        $controller = new \OWA\Core\ConfiguredReport( $params );
        $controller->setDefinition( $definition );
        $controller->action();

        return (array) $controller->data;
    }

    private function base( array $extra = array() ): array
    {
        return array_merge( array(
            'title'   => 'A Report',
            'subview' => 'base.reportDimension',
        ), $extra );
    }

    public function testAPlaceholderTakesItsValueFromTheRequest(): void
    {
        $d = $this->declared(
            $this->base( array(
                'title'       => 'Host Detail: ',
                'titleSuffix' => '{hostName}',
                'params'      => array( 'hostName' => array() ),
            ) ),
            array( 'hostName' => 'example.com' )
        );

        $this->assertSame( 'Host Detail: ', $d['title'] );
        $this->assertSame( 'example.com', $d['titleSuffix'] );
    }

    /** Several placeholders, and one used twice. */
    public function testPlaceholdersComposeInOneString(): void
    {
        $d = $this->declared(
            $this->base( array(
                'titleSuffix' => '{a}: {b} ({a})',
                'params'      => array( 'a' => array(), 'b' => array() ),
            ) ),
            array( 'a' => 'group', 'b' => 'name' )
        );

        $this->assertSame( 'group: name (group)', $d['titleSuffix'] );
    }

    /**
     * A placeholder works wherever a value is authored, not only in the title.
     * ReportBrowserDetail put its parameter inside dimension_properties.
     */
    public function testAPlaceholderReachesNestedSettings(): void
    {
        $d = $this->declared(
            $this->base( array(
                'params'   => array( 'browserType' => array() ),
                'settings' => array(
                    'dimension_properties' => array( 'browser_family' => '{browserType}' ),
                ),
            ) ),
            array( 'browserType' => 'Firefox' )
        );

        $this->assertSame( array( 'browser_family' => 'Firefox' ), $d['dimension_properties'] );
    }

    /** Non-strings survive untouched, so resultsPerPage stays an integer. */
    public function testInterpolationDoesNotChangeTypes(): void
    {
        $d = $this->declared(
            $this->base( array( 'settings' => array( 'resultsPerPage' => 30 ) ) ) );

        $this->assertSame( 30, $d['resultsPerPage'] );
    }

    /**
     * Three reports store their dimension lowercased, so the value has to be
     * lowercased before it is constrained on -- otherwise "Google" and "google"
     * are different ads.
     */
    public function testADeclaredParameterCanBeLowercased(): void
    {
        $d = $this->declared(
            $this->base( array(
                'titleSuffix' => '{campaign}',
                'params'      => array( 'campaign' => array( 'lowercase' => true ) ),
                'settings'    => array( 'constraints' => array(
                    array( 'dimension' => 'campaign', 'fromParam' => 'campaign' ) ) ),
            ) ),
            array( 'campaign' => 'SpringSALE' )
        );

        $this->assertSame( 'springsale', $d['titleSuffix'],
            'the normalisation applies to the value, so the title shows what was matched' );
        $this->assertSame( 'campaign==springsale', $d['constraints'] );
    }

    /** ...and a parameter without it keeps its case. */
    public function testAParameterIsNotLowercasedByDefault(): void
    {
        $d = $this->declared(
            $this->base( array(
                'titleSuffix' => '{hostName}',
                'params'      => array( 'hostName' => array() ),
            ) ),
            array( 'hostName' => 'Example.COM' )
        );

        $this->assertSame( 'Example.COM', $d['titleSuffix'] );
    }

    /**
     * The reason constraints are structured rather than a string with
     * placeholders: the two kinds of value are encoded differently.
     */
    public function testARequestValueIsEncodedAndALiteralIsNot(): void
    {
        $d = $this->declared(
            $this->base( array(
                'params'   => array( 'referralWebSite' => array() ),
                'settings' => array( 'constraints' => array(
                    array( 'dimension' => 'medium', 'value' => 'organic-search' ),
                    array( 'dimension' => 'referralWebSite', 'fromParam' => 'referralWebSite' ),
                ) ),
            ) ),
            array( 'referralWebSite' => 'a b&c' )
        );

        $this->assertSame( 'medium==organic-search,referralWebSite==a+b%26c', $d['constraints'],
            'a value from the request must be encoded; a literal must be left alone' );
    }

    public function testAnOperatorOtherThanEqualsIsHonoured(): void
    {
        $d = $this->declared(
            $this->base( array( 'settings' => array( 'constraints' => array(
                array( 'dimension' => 'ad', 'operator' => '!=', 'value' => 'null' ) ) ) ) ) );

        $this->assertSame( 'ad!=null', $d['constraints'] );
    }

    /** A plain string is still a constraint, which is what most reports use. */
    public function testAStringConstraintIsUsedAsIs(): void
    {
        $d = $this->declared(
            $this->base( array( 'settings' => array( 'constraints' => 'medium==referral' ) ) ) );

        $this->assertSame( 'medium==referral', $d['constraints'] );
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testAnUnusableDefinitionIsRefused( array $definition, string $because ): void
    {
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( $definition );

        $this->assertNotSame( '', $error, 'this definition should not be accepted' );
        $this->assertStringContainsString( $because, $error );
    }

    public static function refusedProvider(): array
    {
        $base = array( 'title' => 'A Report', 'subview' => 'base.reportDimension' );

        return array(
            'params not an object' => array(
                $base + array( 'params' => 'hostName' ), '"params" must be an object' ),

            'constraint with no dimension' => array(
                $base + array( 'settings' => array( 'constraints' => array( array( 'value' => 'x' ) ) ) ),
                'needs a "dimension"' ),

            'constraint with no value' => array(
                $base + array( 'settings' => array( 'constraints' => array( array( 'dimension' => 'ad' ) ) ) ),
                'needs either a "value" or a "fromParam"' ),

            /*
             * The one worth refusing loudest. An undeclared parameter reads as
             * empty, so the constraint becomes `hostName==` -- which matches
             * nothing and looks like the report has no data rather than like a
             * typo in its definition.
             */
            'constraint on an undeclared parameter' => array(
                $base + array( 'settings' => array( 'constraints' => array(
                    array( 'dimension' => 'hostName', 'fromParam' => 'hosName' ) ) ) ),
                'undeclared parameter' ),
        );
    }

    /** A well-formed parameterised definition is not caught by any of that. */
    public function testAWellFormedParameterisedDefinitionIsAccepted(): void
    {
        $this->assertSame( '', \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title'       => 'Host Detail: ',
            'titleSuffix' => '{hostName}',
            'subview'     => 'base.reportDimensionDetail',
            'params'      => array( 'hostName' => array( 'lowercase' => false ) ),
            'settings'    => array(
                'metrics'     => 'visits',
                'constraints' => array(
                    array( 'dimension' => 'hostName', 'fromParam' => 'hostName' ) ),
            ),
        ) ) );
    }

    /**
     * A missing request parameter must not produce a constraint that silently
     * matches everything.
     */
    public function testAnAbsentParameterStillConstrains(): void
    {
        $d = $this->declared(
            $this->base( array(
                'params'   => array( 'hostName' => array() ),
                'settings' => array( 'constraints' => array(
                    array( 'dimension' => 'hostName', 'fromParam' => 'hostName' ) ) ),
            ) ),
            array()
        );

        $this->assertSame( 'hostName==', $d['constraints'],
            'an absent parameter constrains on empty -- which returns nothing, rather '
            . 'than dropping the constraint and returning every row' );
    }

    /**
     * Metrics are report-wide, and reach every widget's query.
     *
     * Every widget in every multi-widget report asks for the same metrics --
     * measured across all 13, without exception. Holding the value once is
     * also what will let a metric set replace it in ONE place instead of
     * rewriting each widget's query, which is the loop-with-override this
     * format is trying not to grow.
     */
    public function testReportMetricsReachEveryWidget(): void
    {
        $d = $this->declared( $this->base( array(
            'metrics' => 'visits,pageViews',
            'widgets' => array(
                array( 'type' => 'trend', 'id' => 't', 'query' => array( 'dimensions' => 'date' ) ),
                array( 'type' => 'grid',  'id' => 'g', 'query' => array( 'dimensions' => 'pagePath' ) ),
            ),
        ) ) );

        $this->assertSame( 'visits,pageViews', $d['metrics'] );

        foreach ( $d['widgets'] as $widget ) {
            $this->assertArrayNotHasKey( 'metrics', $widget['query'],
                'a widget should not carry a copy of a report-wide value' );
        }
    }

    /** A placeholder works in the report-wide metrics too. */
    public function testReportMetricsAreInterpolated(): void
    {
        $d = $this->declared(
            $this->base( array(
                'metrics' => 'visits,{extra}',
                'params'  => array( 'extra' => array() ),
            ) ),
            array( 'extra' => 'bounces' ) );

        $this->assertSame( 'visits,bounces', $d['metrics'] );
    }

    /**
     * A widget may override the report's metrics with its own.
     *
     * Deliberate and supported, not a side effect of how the two arrays happen
     * to be merged. An author who wants one widget to always show revenue
     * regardless of which metric set is being viewed says so on that widget,
     * and it must keep saying it: when metric sets arrive, the ambient set
     * replaces the REPORT's metrics, and a widget that named its own is
     * expressing that it does not want to be switched.
     *
     * That is also why no widget needs to name a metric set. Not naming one
     * means "whatever is being viewed"; naming metrics means "these, always".
     */
    public function testAnOverridingWidgetKeepsItsOwnMetricsInTheDefinition(): void
    {
        $d = $this->declared( $this->base( array(
            'metrics' => 'visits,pageViews',
            'widgets' => array(
                array( 'type' => 'trend', 'id' => 't', 'query' => array( 'dimensions' => 'date' ) ),
                array( 'type' => 'grid',  'id' => 'g', 'query' => array(
                    'metrics' => 'transactionRevenue', 'dimensions' => 'pagePath' ) ),
            ),
        ) ) );

        $this->assertSame( 'visits,pageViews', $d['metrics'],
            'the report-wide value is untouched by a widget overriding it' );

        $widgets = $d['widgets'];

        $this->assertArrayNotHasKey( 'metrics', $widgets[0]['query'],
            'a widget that does not override carries no copy, so it follows the report' );

        $this->assertSame( 'transactionRevenue', $widgets[1]['query']['metrics'],
            'a widget that overrides keeps exactly what it asked for' );
    }

    /** An overriding widget's metrics are interpolated like anything else. */
    public function testAnOverridingWidgetIsStillInterpolated(): void
    {
        $d = $this->declared(
            $this->base( array(
                'metrics' => 'visits',
                'params'  => array( 'm' => array() ),
                'widgets' => array( array( 'type' => 'grid', 'id' => 'g',
                    'query' => array( 'metrics' => '{m}', 'dimensions' => 'pagePath' ) ) ),
            ) ),
            array( 'm' => 'bounces' ) );

        $this->assertSame( 'bounces', $d['widgets'][0]['query']['metrics'] );
    }

    /**
     * ...and the override actually wins in the query that gets issued.
     *
     * The test above reads the DECLARED bag, so it passes whichever way the
     * template merges the two -- confirmed by reversing the merge, which failed
     * nothing. Precedence is a rendering fact and has to be read from the
     * rendered query.
     */
    public function testAnOverridingWidgetWinsInTheEmittedQuery(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'rendering a report loads the site list and the period' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        require_once __DIR__ . '/ReportCharacterizationHarness.php';
        require_once __DIR__ . '/ReportRenderHarness.php';

        $controller = new \OWA\Core\ConfiguredReport(
            array( 'siteId' => '1', 'period' => 'last_thirty_days' ) );

        $controller->setDefinition( array(
            'title'   => 'Override',
            'subview' => 'base.reportWidgets',
            'metrics' => 'visits,pageViews',
            'widgets' => array(
                array( 'type' => 'trend', 'id' => 'trend', 'container' => 'trend-chart',
                       'query' => array( 'dimensions' => 'date', 'sort' => 'date' ) ),
                array( 'type' => 'grid', 'id' => 'dim', 'container' => 'dimension-grid',
                       'query' => array( 'metrics' => 'transactionRevenue',
                                         'dimensions' => 'pagePath', 'sort' => 'transactionRevenue-' ) ),
            ),
        ) );

        $data = (array) $controller->doAction();
        $html = (string) \OWA\Core\CoreAPI::displayView( $data );

        $byVar = array();

        foreach ( \OWA\Tests\ReportRenderHarness::queriesIn( $html ) as $entry ) {
            $byVar[ $entry['var'] ] = $entry['query']['metrics'] ?? '';
        }

        $this->assertSame( 'visits,pageViews', $byVar['trendurl'] ?? null,
            'a widget that overrides nothing must query the report metrics' );

        $this->assertSame( 'transactionRevenue', $byVar['dimurl'] ?? null,
            "a widget's own metrics must win over the report's, or an author cannot "
            . 'pin one widget while the rest follow the metric set' );
    }

    /**
     * A report-links widget renders its links, and nothing else.
     *
     * Several reports are bespoke largely because they hand-write a block of
     * these in HTML. As markup they cannot be checked -- two links on the
     * Content report have pointed at the wrong report for years ("Feeds" goes
     * to Referral Link Text, "Entry & Exits" goes to Referrals). As data, the
     * targets are checkable, which is the point of the widget.
     */
    public function testAReportLinksWidgetRendersItsLinks(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'rendering a report loads the site list and the period' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $controller = new \OWA\Core\ConfiguredReport(
            array( 'siteId' => '1', 'period' => 'last_thirty_days' ) );

        $controller->setDefinition( array(
            'title'   => 'Content',
            'subview' => 'base.reportWidgets',
            'widgets' => array( array(
                'type'  => 'report-links',
                'title' => 'Content Reports',
                'links' => array(
                    array( 'reportId' => 'feeds', 'label' => 'Feeds',
                           'description' => 'Feed subscribers and usage.' ),
                    array( 'reportId' => 'entry-pages', 'label' => 'Entry Pages' ),
                ),
            ) ),
        ) );

        $html = (string) \OWA\Core\CoreAPI::displayView( (array) $controller->doAction() );

        $this->assertStringContainsString( 'Content Reports', $html );

        foreach ( array( 'feeds', 'entry-pages' ) as $id ) {

            $this->assertMatchesRegularExpression(
                '/reportId=' . preg_quote( $id, '/' ) . '\b/', $html,
                "the widget did not link to '$id'" );
        }

        $this->assertStringContainsString( 'Feed subscribers and usage.', $html,
            'a description should render beside its link' );

        // It renders from its own declaration: no query, nothing to load.
        $this->assertStringNotContainsString( 'report-linksurl', $html );
    }

    /**
     * @dataProvider badLinkProvider
     */
    public function testABadReportLinkIsRefused( array $widget, string $because ): void
    {
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title' => 'X', 'subview' => 'base.reportWidgets', 'widgets' => array( $widget ) ) );

        $this->assertNotSame( '', $error );
        $this->assertStringContainsString( $because, $error );
    }

    public static function badLinkProvider(): array
    {
        return array(
            // A link with no target renders an anchor to the dispatcher with no
            // id, which answers 400 where the author expected a report.
            'no target' => array(
                array( 'type' => 'report-links', 'links' => array( array( 'label' => 'Feeds' ) ) ),
                'needs a "reportId"' ),

            'no label' => array(
                array( 'type' => 'report-links', 'links' => array( array( 'reportId' => 'feeds' ) ) ),
                'needs a "label"' ),

            'no links at all' => array(
                array( 'type' => 'report-links', 'links' => array() ),
                'renders an empty list' ),

            'widget with no type' => array(
                array( 'links' => array() ), 'needs a "type"' ),
        );
    }

    /**
     * Every report a definition links to must exist.
     *
     * The check hand-written HTML never had. No definition declares
     * report-links yet -- this is what makes the bespoke conversions safe to
     * do, and it fails the moment one names a report that is not registered.
     */
    public function testEveryDeclaredReportLinkResolves(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the registry loads modules' );
        }

        $registry = (array) \OWA\Core\CoreAPI::getReportRegistry();

        $this->assertNotEmpty( $registry, 'no reports registered, so this proves nothing' );

        $dangling = array();
        $checked  = 0;

        foreach ( (array) glob( OWA_DIR . 'modules/Base/reports/*.json' ) as $file ) {

            $definition = json_decode( (string) file_get_contents( $file ), true );

            foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

                if ( ( $widget['type'] ?? '' ) !== 'report-links' ) {
                    continue;
                }

                foreach ( (array) ( $widget['links'] ?? array() ) as $link ) {

                    $checked++;

                    if ( ! isset( $registry[ $link['reportId'] ] ) ) {
                        $dangling[] = basename( $file ) . ' -> ' . $link['reportId'];
                    }
                }
            }
        }

        $this->assertSame( array(), $dangling,
            'these links point at reports that are not registered: ' . implode( ', ', $dangling ) );

        // Positive control: the lookup must be capable of failing.
        $this->assertArrayNotHasKey( 'definitely-not-a-report', $registry );
    }

    /** Render a definition against a given set of metric sets. */
    private function renderedWith( array $definition, array $metricSets, array $params = array() ): string
    {
        require_once __DIR__ . '/ReportCharacterizationHarness.php';
        require_once __DIR__ . '/ReportRenderHarness.php';

        $controller = new \OWA\Core\ConfiguredReport(
            $params + array( 'siteId' => '1', 'period' => 'last_thirty_days' ) );

        $controller->setDefinition( $definition );

        $data = (array) $controller->doAction();

        $data['metricSets'] = $metricSets;
        $data['tabs']       = \OWA\Core\MetricSets::toLegacyTabs( $metricSets );
        $data['tabs_json']  = json_encode( $data['tabs'] );

        return (string) \OWA\Core\CoreAPI::displayView( $data );
    }

    private function requireDbAsAdmin(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'rendering a report loads the site list and the period' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    private static function threeSets(): array
    {
        return array(
            'site_usage' => array( 'label' => 'Site Usage', 'metrics' => 'visits', 'chartMetric' => 'visits' ),
            'ecommerce'  => array( 'label' => 'e-commerce', 'metrics' => 'transactions', 'chartMetric' => 'transactions' ),
            'goals'      => array( 'label' => 'Goals', 'metrics' => 'goalValueAll', 'chartMetric' => 'visits' ),
        );
    }

    /**
     * A report that declares no metrics is measured every way the site offers.
     */
    public function testAReportWithoutMetricsRendersOncePerSet(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'Browsers',
            'subview' => 'base.reportWidgets',
            'widgets' => array( array( 'type' => 'grid', 'id' => 'dim',
                'container' => 'dimension-grid', 'query' => array( 'dimensions' => 'browserType' ) ) ),
        ), self::threeSets() );

        $containers = array();

        foreach ( \OWA\Tests\ReportRenderHarness::explorersIn( $html ) as $e ) {
            $containers[] = $e['container'];
        }

        $this->assertSame(
            array( 'site_usage_dimension-grid', 'ecommerce_dimension-grid', 'goals_dimension-grid' ),
            $containers,
            'one widget per set, each rendering into its own container' );

        // ...each asking for its own set's metrics.
        $metrics = array();

        foreach ( \OWA\Tests\ReportRenderHarness::queriesIn( $html ) as $q ) {
            $metrics[] = $q['query']['metrics'];
        }

        $this->assertSame( array( 'visits', 'transactions', 'goalValueAll' ), $metrics );
    }

    /**
     * A report that DOES declare metrics has said how it measures, and renders
     * once regardless of what the site offers.
     *
     * Web Pages is page views and visits; it is not "page views, measured by
     * e-commerce". This distinction used to be implicit in which subview a
     * report used, and it is why 21 of the multi-set reports declare metrics
     * that never reach a query -- they are the other kind, carrying a
     * declaration that belongs to this one.
     */
    public function testAReportWithMetricsRendersOnce(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'Web Pages',
            'subview' => 'base.reportWidgets',
            'metrics' => 'pageViews,visits',
            'widgets' => array( array( 'type' => 'grid', 'id' => 'dim',
                'container' => 'dimension-grid', 'query' => array( 'dimensions' => 'pagePath' ) ) ),
        ), self::threeSets() );

        $queries = \OWA\Tests\ReportRenderHarness::queriesIn( $html );

        $this->assertCount( 1, $queries, 'a report that measures one way renders one widget' );
        $this->assertSame( 'pageViews,visits', $queries[0]['query']['metrics'],
            "the site's sets must not override what the report declared" );
    }

    /**
     * A widget renders only for the sets it names.
     */
    public function testAWidgetCanBeLimitedToCertainSets(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'Browsers',
            'subview' => 'base.reportWidgets',
            'widgets' => array(
                array( 'type' => 'grid', 'id' => 'all', 'container' => 'all-grid',
                       'query' => array( 'dimensions' => 'browserType' ) ),
                array( 'type' => 'grid', 'id' => 'rev', 'container' => 'rev-grid',
                       'metricSets' => array( 'ecommerce' ),
                       'query' => array( 'dimensions' => 'browserType' ) ),
            ),
        ), self::threeSets() );

        $containers = array();

        foreach ( \OWA\Tests\ReportRenderHarness::explorersIn( $html ) as $e ) {
            $containers[] = $e['container'];
        }

        $this->assertSame( array(
            'site_usage_all-grid',
            'ecommerce_all-grid', 'ecommerce_rev-grid',
            'goals_all-grid',
        ), $containers,
            'the unnamed widget renders for every set; the named one only for its own' );
    }

    /**
     * Two sets must never render into one element.
     *
     * They would overwrite each other, and only one would ever be visible --
     * with an identical command list and identical queries, so nothing in the
     * recording would show it.
     */
    public function testNoTwoSetsShareAContainer(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'Browsers',
            'subview' => 'base.reportWidgets',
            'widgets' => array(
                array( 'type' => 'trend', 'id' => 'trend', 'container' => 'trend-chart',
                       'query' => array( 'dimensions' => 'date' ) ),
                array( 'type' => 'grid', 'id' => 'dim', 'container' => 'dimension-grid',
                       'query' => array( 'dimensions' => 'browserType' ) ),
            ),
        ), self::threeSets() );

        $containers = array();

        foreach ( \OWA\Tests\ReportRenderHarness::explorersIn( $html ) as $e ) {
            $containers[] = $e['container'];
        }

        $this->assertSame( $containers, array_unique( $containers ),
            'a container is used twice, so one set is rendering over another: '
            . implode( ', ', $containers ) );

        $this->assertCount( 6, $containers, 'two widgets across three sets' );
    }
}
