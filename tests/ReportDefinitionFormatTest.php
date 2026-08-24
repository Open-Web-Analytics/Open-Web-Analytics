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
        $base = array( 'title' => 'A Report' );

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

            /*
             * A deprecation notice with nothing to say is the same failure at
             * report scale: the banner renders, takes the top of the report,
             * and tells the reader neither what changed nor what to read now.
             */
            'deprecated is not an object' => array(
                $base + array( 'deprecated' => 'this report is going away' ),
                '"deprecated" must be an object' ),

            'deprecated with no message' => array(
                $base + array( 'deprecated' => array() ),
                'needs a "message"' ),

            'deprecated with an empty message' => array(
                $base + array( 'deprecated' => array( 'message' => '' ) ),
                'needs a "message"' ),
        );
    }

    /** A well-formed parameterised definition is not caught by any of that. */
    public function testAWellFormedParameterisedDefinitionIsAccepted(): void
    {
        $this->assertSame( '', \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title'       => 'Host Detail: ',
            'titleSuffix' => '{hostName}',
            'params'      => array( 'hostName' => array( 'lowercase' => false ) ),
            'settings'    => array(
                'metrics'     => 'visits',
                'constraints' => array(
                    array( 'dimension' => 'hostName', 'fromParam' => 'hostName' ) ),
            ),
        ) ) );
    }

    /**
     * A deprecation notice says a report is still here but no longer filling.
     *
     * Generic on purpose: the renderer neither knows nor cares why. Pinned here
     * rather than against the two reports that carry one today, because the key
     * is part of the format -- those two are just its first users.
     */
    public function testADeprecatedReportIsAccepted(): void
    {
        $this->assertSame( '', \OWA\Core\ConfiguredReport::getDefinitionError(
            $this->base( array( 'deprecated' => array(
                'message' => 'This report is for historical data only.' ) ) ) ) );
    }

    /** The notice reaches the view, which is what draws the banner. */
    public function testADeprecationNoticeReachesTheView(): void
    {
        $d = $this->declared( $this->base( array( 'deprecated' => array(
            'message' => 'This report is for historical data only.' ) ) ) );

        $this->assertSame( 'This report is for historical data only.',
            $d['deprecated']['message'] );
    }

    /**
     * The template draws the banner on `! empty( $view->deprecated['message'] )`,
     * so an ordinary report has to leave the key off entirely. Setting it empty
     * would be the same bug in the other direction: an empty band above every
     * report in the install.
     */
    public function testAnOrdinaryReportCarriesNoDeprecationNotice(): void
    {
        $d = $this->declared( $this->base() );

        $this->assertArrayNotHasKey( 'deprecated', $d );
    }

    /** A notice is interpolated like any other authored string. */
    public function testADeprecationNoticeInterpolates(): void
    {
        $d = $this->declared(
            $this->base( array(
                'params'     => array( 'hostName' => array() ),
                'deprecated' => array(
                    'message' => 'Link text for {hostName} is no longer collected.' ),
            ) ),
            array( 'hostName' => 'example.com' )
        );

        $this->assertSame( 'Link text for example.com is no longer collected.',
            $d['deprecated']['message'] );
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
            'title' => 'X', 'widgets' => array( $widget ) ) );

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

        /*
         * EVERY report a definition points at, not just the report-links ones.
         *
         * This used to skip any widget that was not report-links, which left
         * two thirds of the targets unguarded: a grid's "View Full Report"
         * link (`more`) and the per-row link a grid puts on a column
         * (`link.template.reportId`). Those are the same kind of thing and fail
         * the same way -- an anchor to the report dispatcher naming a report
         * that does not exist, which answers 400 where the reader expected a
         * report.
         *
         * It is also the exact defect that made this worth testing: two links
         * on the Content report pointed at the wrong report for years, because
         * as hand-written markup nobody could check them.
         */
        foreach ( (array) glob( OWA_DIR . 'modules/*/reports/*.json' ) as $file ) {

            $definition = json_decode( (string) file_get_contents( $file ), true );

            foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

                $targets = array();

                foreach ( (array) ( $widget['links'] ?? array() ) as $link ) {

                    $targets['report-links'][] = $link['reportId'] ?? '';
                }

                if ( ! empty( $widget['more']['reportId'] ) ) {

                    $targets['more'][] = $widget['more']['reportId'];
                }

                if ( ! empty( $widget['link']['template']['reportId'] ) ) {

                    $targets['column link'][] = $widget['link']['template']['reportId'];
                }

                foreach ( $targets as $kind => $ids ) {

                    foreach ( $ids as $id ) {

                        $checked++;

                        if ( ! isset( $registry[ $id ] ) ) {

                            $dangling[] = sprintf( '%s %s -> %s', basename( $file ), $kind, $id );
                        }
                    }
                }
            }
        }

        /*
         * Derived from the definitions rather than remembered, so deleting a
         * report lowers it and a parser that stopped matching does not.
         */
        $declared = 0;

        foreach ( (array) glob( OWA_DIR . 'modules/*/reports/*.json' ) as $file ) {

            $declared += substr_count( (string) file_get_contents( $file ), '"reportId"' );
        }

        $this->assertSame( $declared, $checked,
            'some declared reportId was not checked -- the walk has stopped seeing a kind of link' );

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

    /**
     * A widget's title is its name; showing it is a separate choice.
     *
     * A widget can be named without the name being drawn -- what a report
     * builder listing widgets needs, and what a report wants when the
     * surrounding layout already says what the thing is.
     */
    public function testAWidgetTitleIsShownByDefault(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'R',
            'metrics' => 'visits',
            'widgets' => array( array( 'type' => 'grid', 'id' => 'g', 'container' => 'g-grid',
                'title' => 'Transaction Roster', 'query' => array( 'dimensions' => 'pagePath' ) ) ),
        ), array() );

        $this->assertStringContainsString( 'Transaction Roster', $html );
    }

    public function testAWidgetTitleCanBeHiddenWithoutLosingIt(): void
    {
        $this->requireDbAsAdmin();

        $definition = array(
            'title'   => 'R',
            'metrics' => 'visits',
            'widgets' => array( array( 'type' => 'grid', 'id' => 'g', 'container' => 'g-grid',
                'title' => 'Transaction Roster', 'showTitle' => false,
                'query' => array( 'dimensions' => 'pagePath' ) ) ),
        );

        $html = $this->renderedWith( $definition, array() );

        $this->assertStringNotContainsString( 'Transaction Roster', $html,
            'showTitle false must stop it being drawn' );

        // ...and the widget is still NAMED -- the definition keeps it, so
        // anything listing widgets still has something to call this one.
        $this->assertSame( 'Transaction Roster',
            $definition['widgets'][0]['title'],
            'hiding a title must not mean discarding it' );
    }

    /** A widget with no title draws no header at all. */
    public function testAWidgetWithoutATitleDrawsNoHeader(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'R',
            'metrics' => 'visits',
            'widgets' => array( array( 'type' => 'grid', 'id' => 'g', 'container' => 'g-grid',
                'query' => array( 'dimensions' => 'pagePath' ) ) ),
        ), array() );

        $this->assertStringNotContainsString( 'owa_reportSectionHeader', $html );
    }

    /**
     * Read one widget's emitted query back out of the rendered page.
     *
     * The merge that matters happens in the template, not in the declaration,
     * so an assertion against the declared bag would pass with the precedence
     * reversed. Reading the query the browser will actually request is the
     * only check that cannot go vacuous.
     */
    private function queryFor( string $html, string $var ): array
    {
        require_once __DIR__ . '/ReportRenderHarness.php';

        foreach ( \OWA\Tests\ReportRenderHarness::queriesIn( $html ) as $entry ) {

            if ( $entry['var'] === $var ) {

                return (array) $entry['query'];
            }
        }

        $this->fail( "no query named '$var' was emitted" );
    }

    private function constraintReport( array $widgetExtra, $reportConstraints = null ): array
    {
        $definition = array(
            'title'   => 'Constrained',
            'metrics' => 'visits',
            'widgets' => array(
                array( 'type' => 'grid', 'id' => 'dim', 'container' => 'dimension-grid',
                       'query' => array( 'dimensions' => 'medium', 'sort' => 'visits-' ) ) + $widgetExtra,
            ),
        );

        if ( $reportConstraints !== null ) {

            $definition['settings'] = array( 'constraints' => $reportConstraints );
        }

        return $this->queryFor(
            $this->renderedWith( $definition, array(), array() ), 'dimurl' );
    }

    /**
     * A widget narrows the report further; it does not start again.
     *
     * `traffic` is the case: three metric boxes, each measuring a different
     * medium. If a widget's constraint REPLACED the report's, a widget on a
     * detail report would quietly widen from "this one host" to every host --
     * a data bug that reads as a reporting error, not a definition one.
     */
    public function testAWidgetConstraintIsAddedToTheReports(): void
    {
        $this->requireDbAsAdmin();

        $query = $this->constraintReport(
            array( 'constraints' => array( array( 'dimension' => 'medium', 'value' => 'organic-search' ) ) ),
            array( array( 'dimension' => 'host', 'value' => 'example.com' ) ) );

        $this->assertSame( 'host==example.com,medium==organic-search', $query['constraints'] ?? null,
            "a widget's constraint must narrow the report's, not replace it" );
    }

    /**
     * The report's constraint still applies to a widget that adds none.
     */
    public function testAWidgetWithoutAConstraintKeepsTheReports(): void
    {
        $this->requireDbAsAdmin();

        $query = $this->constraintReport( array(),
            array( array( 'dimension' => 'host', 'value' => 'example.com' ) ) );

        $this->assertSame( 'host==example.com', $query['constraints'] ?? null );
    }

    /**
     * An empty side is dropped rather than joined.
     *
     * The template this replaces built one of `traffic`'s three constraints by
     * concatenating onto a report-wide part that resolved to nothing, and
     * emitted `,medium==organic-search` -- an empty first clause. The other two
     * were built slightly differently and were fine, which is exactly why this
     * survived: it was one malformed string among three that looked alike.
     */
    public function testAWidgetConstraintDoesNotLeadWithACommaWhenTheReportHasNone(): void
    {
        $this->requireDbAsAdmin();

        $query = $this->constraintReport(
            array( 'constraints' => array( array( 'dimension' => 'medium', 'value' => 'organic-search' ) ) ) );

        $this->assertSame( 'medium==organic-search', $query['constraints'] ?? null,
            'an absent report constraint must not contribute an empty clause' );
    }

    /** A widget's constraint encodes a request value, like the report's does. */
    public function testAWidgetConstraintEncodesARequestValue(): void
    {
        $this->requireDbAsAdmin();

        $definition = array(
            'title'   => 'Constrained',
            'metrics' => 'visits',
            'params'  => array( 'host' => array() ),
            'widgets' => array(
                array( 'type' => 'grid', 'id' => 'dim', 'container' => 'dimension-grid',
                       'query' => array( 'dimensions' => 'medium', 'sort' => 'visits-' ),
                       'constraints' => array( array( 'dimension' => 'host', 'fromParam' => 'host' ) ) ),
            ),
        );

        $viaWidget = $this->queryFor(
            $this->renderedWith( $definition, array(), array( 'host' => 'a b&c' ) ), 'dimurl' );

        // The same constraint, declared report-wide instead. Asserting the two
        // agree checks the encoding without restating it: a literal written
        // here has to account for the encode on the way out and the decode on
        // the way back in, and getting that wrong looks like a passing test.
        $reportWide = $definition;
        $reportWide['settings'] = array( 'constraints' => $reportWide['widgets'][0]['constraints'] );
        unset( $reportWide['widgets'][0]['constraints'] );

        $viaReport = $this->queryFor(
            $this->renderedWith( $reportWide, array(), array( 'host' => 'a b&c' ) ), 'dimurl' );

        $this->assertSame( $viaReport['constraints'], $viaWidget['constraints'] ?? null,
            'a widget constraint must encode a request value exactly as a report one does' );

        $this->assertStringContainsString( '&', (string) $viaReport['constraints'],
            'the value must survive the round trip intact, ampersand and all' );
    }

    /**
     * A widget's constraint is checked exactly as the report's is.
     *
     * @dataProvider badWidgetConstraintProvider
     */
    public function testABadWidgetConstraintIsRefused( array $constraints, string $because ): void
    {
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title'   => 'Constrained',
            'widgets' => array(
                array( 'type' => 'grid', 'constraints' => $constraints ),
            ),
        ) );

        $this->assertNotSame( '', $error, $because );
        $this->assertStringContainsString( 'widget 0', $error,
            'the error must name the widget, or an author cannot find it' );
    }

    public static function badWidgetConstraintProvider(): array
    {
        return array(
            'no dimension' => array(
                array( array( 'value' => 'organic-search' ) ),
                'a constraint with nothing to constrain matches everything',
            ),
            'neither value nor fromParam' => array(
                array( array( 'dimension' => 'medium' ) ),
                'the constraint would become `medium==`, matching nothing',
            ),
            'undeclared parameter' => array(
                array( array( 'dimension' => 'host', 'fromParam' => 'host' ) ),
                'a parameter the report does not declare is never read',
            ),
        );
    }

    /**
     * A metric box draws its own label, and is not also given a header.
     *
     * The label belongs inside the box, above the number. Rendering the
     * widget's title as a section header as well would print the same words
     * twice, one above the other.
     */
    public function testAMetricBoxesWidgetLabelsItselfWithoutADuplicateHeader(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'Boxes',
            'metrics' => 'visits',
            'widgets' => array(
                array( 'type' => 'metric-boxes', 'id' => 'fromsearch',
                       'container' => 'trend-metrics-search',
                       'title' => 'Visits From Search Engines',
                       'query' => array( 'dimensions' => 'date', 'sort' => 'date' ) ),
            ),
        ), array() );

        // Three arguments, not four: makeMetricBoxes' second parameter used to be
        // a jqote template id, which it forwarded to a kpiBox option that
        // generate() never read. The label now sits in the slot it vacated.
        $this->assertStringContainsString(
            '\'makeMetricBoxes\', \'\', "Visits From Search Engines"', $html,
            'the title must reach the box as its label' );

        $this->assertStringNotContainsString(
            '<div class="owa_reportSectionHeader">Visits From Search Engines</div>', $html,
            'a widget that draws its own label must not also get the generic header' );
    }

    /**
     * A pie charts the dimension it queries.
     *
     * Naming the dimension a second time in the widget would be a way for the
     * chart and the query it charts to disagree.
     */
    public function testAPieChartsTheDimensionItQueries(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'Pie',
            'metrics' => 'visits',
            'widgets' => array(
                array( 'type' => 'pie', 'id' => 'medium', 'container' => 'traffic-sources',
                       'chartMetric' => 'visits',
                       'query' => array( 'dimensions' => 'medium', 'sort' => 'visits-' ) ),
            ),
        ), array() );

        $this->assertStringContainsString( "medium.options.pieChart.dimension = 'medium';", $html );
        $this->assertStringContainsString( "medium.options.pieChart.metric = 'visits';", $html );
        $this->assertStringContainsString( "'makePieChart'", $html );

        $this->assertSame( 'medium', $this->queryFor( $html, 'mediumurl' )['dimensions'] ?? null,
            'the charted dimension must be the queried one' );
    }

    /**
     * A trend can be drawn without the boxes underneath it.
     *
     * On by default -- that is how a metric set makes itself visible. `traffic`
     * turns them off: it measures one metric and draws three boxes of its own
     * beside the chart, so a fourth repeating the total is noise.
     */
    public function testATrendCanSuppressItsMetricBoxes(): void
    {
        $this->requireDbAsAdmin();

        $trend = array( 'type' => 'trend', 'id' => 'trend', 'container' => 'trend-chart',
                        'chartMetric' => 'visits',
                        'query' => array( 'dimensions' => 'date', 'sort' => 'date' ) );

        $definition = array(
            'title'   => 'Trend',
            'metrics' => 'visits',
        );

        $with = $this->renderedWith( $definition + array( 'widgets' => array( $trend ) ), array() );

        $this->assertStringContainsString( "'makeMetricBoxes' , 'trend-metrics'", $with,
            'the boxes are drawn by default' );
        $this->assertStringContainsString( 'id="trend-metrics"', $with );

        $without = $this->renderedWith(
            $definition + array( 'widgets' => array( $trend + array( 'showMetricBoxes' => false ) ) ),
            array() );

        $this->assertStringNotContainsString( 'makeMetricBoxes', $without,
            'showMetricBoxes false must suppress the command' );

        $this->assertStringNotContainsString( 'id="trend-metrics"', $without,
            'and the element it would have drawn into, which is otherwise left empty' );

        // The chart itself is untouched by the switch.
        $this->assertStringContainsString( 'makeAreaChart', $without );
    }

    private function reportLinksHtml( array $extra = array() ): string
    {
        return $this->renderedWith( array(
            'title'   => 'Links',
            'metrics' => 'visits',
            'widgets' => array(
                array( 'type' => 'report-links', 'title' => 'Related Reports',
                       'links' => array(
                           array( 'reportId' => 'keywords', 'label' => 'Keywords' ),
                       ) ) + $extra,
            ),
        ), array() );
    }

    /**
     * A title is drawn once, by whichever part of the renderer owns it.
     *
     * report-links drew its own header AND was given the shared one, so every
     * such block printed its name twice -- "Content Reports" above "Content
     * Reports". Two identical lines read as a styling accident rather than a
     * duplicated element, which is why it survived.
     */
    public function testAReportLinksTitleIsDrawnExactlyOnce(): void
    {
        $this->requireDbAsAdmin();

        $this->assertSame( 1,
            substr_count( $this->reportLinksHtml(),
                '<div class="owa_reportSectionHeader">Related Reports</div>' ),
            'the title must be rendered once, not once per renderer that knows about it' );
    }

    /**
     * ...and the switch reaches it, which drawing its own header prevented.
     */
    public function testAReportLinksTitleCanBeHidden(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->reportLinksHtml( array( 'showTitle' => false ) );

        $this->assertStringNotContainsString( 'owa_reportSectionHeader">Related Reports', $html,
            'showTitle must hide a report-links title like any other' );

        // Hidden, not lost: the links it names are still rendered.
        $this->assertStringContainsString( 'Keywords</a>', $html );
    }

    /**
     * No widget draws a title the shared header has already drawn.
     *
     * Written against every type rather than the two found by hand, so a new
     * widget that renders its own header is caught when it is added rather
     * than when someone notices the doubling on a rendered page.
     */
    public function testNoWidgetTypeDrawsADuplicateTitle(): void
    {
        $this->requireDbAsAdmin();

        $widgets = array(
            'trend'        => array( 'type' => 'trend', 'id' => 'trend', 'container' => 'trend-chart',
                                     'query' => array( 'dimensions' => 'date', 'sort' => 'date' ) ),
            'grid'         => array( 'type' => 'grid', 'id' => 'dim', 'container' => 'dimension-grid',
                                     'query' => array( 'dimensions' => 'medium', 'sort' => 'visits-' ) ),
            'pie'          => array( 'type' => 'pie', 'id' => 'pie', 'container' => 'pie-chart',
                                     'chartMetric' => 'visits',
                                     'query' => array( 'dimensions' => 'medium', 'sort' => 'visits-' ) ),
            'metric-boxes' => array( 'type' => 'metric-boxes', 'id' => 'boxes',
                                     'container' => 'boxes-metrics',
                                     'query' => array( 'dimensions' => 'date', 'sort' => 'date' ) ),
            'report-links' => array( 'type' => 'report-links',
                                     'links' => array( array( 'reportId' => 'keywords', 'label' => 'Keywords' ) ) ),
        );

        foreach ( $widgets as $type => $widget ) {

            $html = $this->renderedWith( array(
                'title'   => 'One Title',
                'metrics' => 'visits',
                'widgets' => array( $widget + array( 'title' => 'A Widget Title' ) ),
            ), array() );

            $this->assertSame( 1, substr_count( $html, 'A Widget Title' ),
                "the $type widget renders its title more than once" );
        }
    }

    /**
     * The renderer is fixed, not configured.
     *
     * `subview` was a definition key while the conversion was in progress and
     * reports still rendered through a dozen different views. By the end all 53
     * named the same one, so it was a required field with a single legal value.
     *
     * It is removed rather than defaulted because a definition is meant to
     * become something a user can author, and a key naming a view can name any
     * view in the tree. Widgets own their own rendering, which is what makes
     * fixing it safe.
     */
    public function testAReportRendersThroughTheWidgetRendererWithoutSayingSo(): void
    {
        $this->requireDbAsAdmin();

        $controller = new \OWA\Core\ConfiguredReport(
            array( 'siteId' => '1', 'period' => 'last_thirty_days' ) );

        $controller->setDefinition( array(
            'title'   => 'No Renderer Named',
            'metrics' => 'visits',
            'widgets' => array(
                array( 'type' => 'grid', 'id' => 'dim', 'container' => 'dimension-grid',
                       'query' => array( 'dimensions' => 'medium', 'sort' => 'visits-' ) ),
            ),
        ) );

        $data = (array) $controller->doAction();

        $this->assertSame( \OWA\Core\ConfiguredReport::SUBVIEW, $data['subview'] ?? null,
            'a definition that names no renderer must still reach the widget renderer' );
    }

    /**
     * ...and a definition that tries to name either view is refused.
     *
     * `view` is the outer one -- the page chrome. ReportController::pre()
     * already sets it to base.report, and four converted reports restated it,
     * kept during the conversion so a definition could produce a
     * byte-identical result to the controller it replaced. Restating a default
     * is only a way for the two to disagree later.
     *
     * @dataProvider rendererKeyProvider
     */
    public function testADefinitionMayNotNameItsRenderer( string $key, string $value ): void
    {
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title' => 'Names A Renderer',
            $key    => $value,
        ) );

        $this->assertStringContainsString( $key, $error );
    }

    public static function rendererKeyProvider(): array
    {
        return array(
            'the widget renderer' => array( 'subview', 'base.reportWidgets' ),
            'the outer view'      => array( 'view', 'base.report' ),
        );
    }

    /**
     * No definition on disk names one either.
     *
     * The refusal above covers a definition handed in at runtime; this covers
     * the 53 in the tree, so a copied-and-pasted report cannot reintroduce the
     * key and sit there refused until someone opens that report.
     */
    public function testNoShippedDefinitionNamesARenderer(): void
    {
        $named = array();

        foreach ( glob( OWA_DIR . 'modules/*/reports/*.json' ) as $file ) {

            $definition = json_decode( (string) file_get_contents( $file ), true );

            foreach ( array( 'subview', 'view' ) as $key ) {

                if ( isset( $definition[ $key ] ) ) {

                    $named[] = basename( $file ) . " ($key)";
                }
            }
        }

        $this->assertSame( array(), $named,
            'these definitions name a renderer, which the format no longer allows' );
    }

    /** Every shipped definition is one the format accepts. */
    public function testEveryShippedDefinitionIsWellFormed(): void
    {
        $bad = array();

        foreach ( glob( OWA_DIR . 'modules/*/reports/*.json' ) as $file ) {

            $error = \OWA\Core\ConfiguredReport::getDefinitionError(
                (array) json_decode( (string) file_get_contents( $file ), true ) );

            if ( $error !== '' ) {

                $bad[ basename( $file ) ] = $error;
            }
        }

        $this->assertSame( array(), $bad );
    }

    /**
     * A widget narrows; it never widens.
     *
     * Stated as a table because the question "does a widget constraint add to
     * the report's or replace it?" has to have exactly one answer, and the
     * answer is visible only in the emitted query.
     */
    public function testAWidgetConstraintAlwaysNarrows(): void
    {
        $this->requireDbAsAdmin();

        $report = array( array( 'dimension' => 'host', 'value' => 'example.com' ) );
        $own    = array( array( 'dimension' => 'medium', 'value' => 'organic-search' ) );

        $this->assertSame( 'host==example.com',
            $this->constraintReport( array(), $report )['constraints'] ?? null,
            'a widget that adds nothing keeps the report constraint' );

        $this->assertSame( 'medium==organic-search',
            $this->constraintReport( array( 'constraints' => $own ), null )['constraints'] ?? null,
            'a widget constraint stands alone when the report has none' );

        $this->assertSame( 'host==example.com,medium==organic-search',
            $this->constraintReport( array( 'constraints' => $own ), $report )['constraints'] ?? null,
            'and is ADDED to the report constraint, never substituted for it' );
    }

    /**
     * The other spelling is refused rather than given the opposite meaning.
     *
     * A widget's `query` is merged over the report-wide defaults with a union,
     * so `constraints` written inside it wins outright and drops the report's.
     * That is the same word, in almost the same place, doing the opposite
     * thing -- and the failure is silent and widening.
     */
    public function testConstraintsInsideAWidgetQueryAreRefused(): void
    {
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title'   => 'Overrides',
            'widgets' => array(
                array( 'type' => 'grid',
                       'query' => array( 'dimensions' => 'medium',
                                         'constraints' => 'medium==organic-search' ) ),
            ),
        ) );

        $this->assertStringContainsString( 'widget 0', $error );
        $this->assertStringContainsString( 'constraints', $error );
    }

    /** No shipped definition uses the refused spelling. */
    public function testNoShippedWidgetPutsConstraintsInItsQuery(): void
    {
        $found = array();

        foreach ( glob( OWA_DIR . 'modules/*/reports/*.json' ) as $file ) {

            $definition = json_decode( (string) file_get_contents( $file ), true );

            foreach ( (array) ( $definition['widgets'] ?? array() ) as $i => $widget ) {

                if ( isset( $widget['query']['constraints'] ) ) {

                    $found[] = basename( $file ) . " widget $i";
                }
            }
        }

        $this->assertSame( array(), $found );
    }

    /**
     * excludeColumns is data, not a fragment of script.
     *
     * It was the one value in the widget template echoed raw into the page,
     * and the seven definitions using it carried their own JavaScript quoting
     * to suit -- "'pageUrl'". That let a report definition emit arbitrary
     * script, in the file format that is meant to become user-authorable.
     */
    public function testExcludedColumnsAreEncodedNotInterpolated(): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'Excluding',
            'metrics' => 'visits',
            'widgets' => array(
                array( 'type' => 'grid', 'id' => 'dim', 'container' => 'dimension-grid',
                       'query' => array( 'dimensions' => 'pageUrl', 'sort' => 'visits-' ),
                       'excludeColumns' => array( "pageUrl'];alert(1);//" ) ),
            ),
        ), array() );

        $this->assertStringNotContainsString( 'alert(1);//];', $html,
            'a column name must not be able to close the array and run' );

        $this->assertStringContainsString( 'excludeColumns = ["pageUrl\'];alert(1);\/\/"]', $html,
            'it must arrive as an encoded string inside the list' );
    }

    /** A string is refused, since a string is what used to be interpolated. */
    public function testAStringOfExcludedColumnsIsRefused(): void
    {
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title'   => 'Excluding',
            'widgets' => array(
                array( 'type' => 'grid', 'excludeColumns' => "'pageUrl'" ),
            ),
        ) );

        $this->assertStringContainsString( 'excludeColumns', $error );
    }

    /** No shipped definition uses the interpolated form. */
    public function testNoShippedDefinitionInterpolatesExcludedColumns(): void
    {
        $bad = array();

        foreach ( glob( OWA_DIR . 'modules/*/reports/*.json' ) as $file ) {

            $definition = json_decode( (string) file_get_contents( $file ), true );

            foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

                if ( isset( $widget['excludeColumns'] ) && ! is_array( $widget['excludeColumns'] ) ) {

                    $bad[] = basename( $file );
                }
            }
        }

        $this->assertSame( array(), $bad );
    }

    /**
     * "View Full Report" belongs to any widget, not just a grid.
     *
     * All seven that declare one today are grids, so the block sitting inside
     * the grid branch would look correct and pass every existing test -- while
     * quietly making a `more` on a trend or a pie validate and then render
     * nothing. That is the same "configuration that reads as nothing" that got
     * gridTitle and settings.dimension deleted.
     *
     * @dataProvider widgetTypeProvider
     */
    public function testAnyWidgetCanCarryAMoreLink( array $widget ): void
    {
        $this->requireDbAsAdmin();

        $html = $this->renderedWith( array(
            'title'   => 'More',
            'metrics' => 'visits',
            'widgets' => array( $widget + array(
                'more' => array( 'reportId' => 'pages', 'label' => 'View Full Report' ) ) ),
        ), array() );

        $this->assertStringContainsString( 'owa_moreLinks', $html,
            'this widget type dropped its "more" link' );
    }

    public static function widgetTypeProvider(): array
    {
        return array(
            'trend' => array( array( 'type' => 'trend', 'id' => 't', 'container' => 'trend-chart',
                'chartMetric' => 'visits',
                'query' => array( 'dimensions' => 'date', 'sort' => 'date' ) ) ),
            'grid' => array( array( 'type' => 'grid', 'id' => 'g', 'container' => 'dimension-grid',
                'query' => array( 'dimensions' => 'medium', 'sort' => 'visits-' ) ) ),
            'pie' => array( array( 'type' => 'pie', 'id' => 'p', 'container' => 'pie',
                'chartMetric' => 'visits',
                'query' => array( 'dimensions' => 'medium', 'sort' => 'visits-' ) ) ),
            'metric-boxes' => array( array( 'type' => 'metric-boxes', 'id' => 'm', 'container' => 'mb',
                'title' => 'Boxes',
                'query' => array( 'dimensions' => 'date', 'sort' => 'date' ) ) ),
            'report-links' => array( array( 'type' => 'report-links', 'title' => 'R',
                'links' => array( array( 'reportId' => 'pages', 'label' => 'Pages' ) ) ) ),
        );
    }
}
