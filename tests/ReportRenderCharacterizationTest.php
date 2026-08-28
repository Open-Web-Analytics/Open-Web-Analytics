<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/ReportCharacterizationHarness.php';
require_once __DIR__ . '/ReportRenderHarness.php';

use OWA\Tests\ReportRenderHarness as Render;

/**
 * What every report EMITS, recorded before the widget conversion moves it.
 *
 * The declaration fixture was enough to move reports off controllers, because
 * the subview and its template were not changing. Rendering them as widgets
 * changes the template, and what a report declares says nothing about what a
 * template does with it -- a rewritten template could drop a query, reorder the
 * render commands, or draw into a container that no longer exists, and every
 * declaration test would still pass.
 *
 * So this records the two things that ARE the report: the API queries its
 * widgets will issue, and the commands they run on the results.
 */
final class ReportRenderCharacterizationTest extends TestCase
{
    /** @var array<string, array> */
    private static $golden = array();

    public static function setUpBeforeClass(): void
    {
        /*
         *     OWA_REGEN_RENDER_GOLDEN=1 ./vendor/bin/phpunit tests/ReportRenderCharacterizationTest.php
         *
         * A diff in that file is the question "is this report meant to change?"
         * -- and for a behaviour-preserving conversion the answer is no. Run it
         * and expect NO diff; a conversion adds its own entry and moves nothing.
         *
         * The authentication is load-bearing and is why an earlier attempt at
         * this gate was wrong. Rendering a report resolves a view, which needs
         * an authenticated admin; without it every report records
         * "did not render: (no view)" and the file is quietly replaced with 55
         * non-renders. requireDb() does the same thing for the tests, but that
         * runs per-test, after this.
         */
        if ( getenv( 'OWA_REGEN_RENDER_GOLDEN' ) && owa_test_db_available() ) {

            $user = \OWA\Core\CoreAPI::getCurrentUser();
            $user->setRole( 'admin' );
            $user->setAuthStatus( true );

            file_put_contents(
                Render::goldenPath(),
                json_encode( Render::captureAll(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
            );
        }

        self::$golden = (array) json_decode(
            (string) file_get_contents( Render::goldenPath() ), true );
    }

    private function requireDb(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'rendering a report loads the site list and the period' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    public static function reportProvider(): array
    {
        $cases = array();

        foreach ( Render::coveredReports() as $id => $extra ) {
            $cases[ $id ] = array( $id, $extra, null );
        }

        // ...and the templates that repeat per metric set, with all three.
        // A site with one metric set never reaches the repeat at all.
        foreach ( array_keys( Render::MULTI_METRIC_SET ) as $id ) {

            $cases[ $id . Render::MULTI_SUFFIX ] = array(
                $id, Render::coveredReports()[ $id ] ?? array(), Render::METRIC_SETS );
        }

        return $cases;
    }

    /**
     * @dataProvider reportProvider
     */
    public function testTheReportStillEmitsWhatItEmitted( string $id, array $extra, ?array $metricSets ): void
    {
        $this->requireDb();

        $key = $id . ( $metricSets === null ? '' : Render::MULTI_SUFFIX );

        $this->assertArrayHasKey( $key, self::$golden,
            "$id has no recorded render; regenerate deliberately and say why in the commit" );

        $expected = self::$golden[ $key ];
        $actual   = Render::snapshot( $id, $extra, $metricSets );

        /*
         * ORDER IS PART OF THIS, and there is no longer an exemption from it.
         *
         * Reports whose layout was deliberately redone used to be compared by
         * widget rather than by position, because the recorded order was the
         * pre-conversion controller's. This fixture records CURRENT behaviour
         * and is regenerated deliberately, so a moved widget is simply a diff
         * to read and approve -- not a case needing a standing allowance.
         */
        $this->assertSame( $expected, $actual,
            "$id renders different queries or commands than it did. Regenerate only "
            . 'if the change is one you meant: OWA_REGEN_RENDER_GOLDEN=1.' );
    }



    /**
     * Coverage in the other direction: a report that stopped being covered
     * silently shrinks what this protects.
     */
    public function testTheFixtureCoversExactlyTheReportsThatExist(): void
    {
        $recorded = array_keys( self::$golden );
        $present  = array_keys( Render::coveredReports() );

        foreach ( array_keys( Render::MULTI_METRIC_SET ) as $id ) {
            $present[] = $id . Render::MULTI_SUFFIX;
        }

        sort( $recorded );
        sort( $present );

        $this->assertSame( $recorded, $present,
            'the render fixture and the reports directory disagree about which reports exist' );
    }

    /**
     * The fixture has to be worth comparing against.
     *
     * A recording of 53 empty snapshots would satisfy every assertion above.
     */
    public function testTheRecordingIsSubstantial(): void
    {
        $queries = 0;
        $commands = 0;
        $withoutQuery = array();

        foreach ( self::$golden as $id => $snapshot ) {

            $queries  += count( $snapshot['queries'] ?? array() );
            $commands += count( $snapshot['commands'] ?? array() );

            if ( empty( $snapshot['queries'] ) ) {
                $withoutQuery[] = $id;
            }

            $this->assertArrayNotHasKey( 'error', $snapshot,
                "$id did not render when the fixture was taken" );
        }

        $this->assertSame( array(), $withoutQuery,
            'these reports emit no API query at all, so nothing about them is pinned: '
            . implode( ', ', $withoutQuery ) );

        $this->assertGreaterThan( 90, $queries );
        $this->assertGreaterThan( 180, $commands );
    }

    /**
     * Every query's results are bound to a container.
     *
     * A resultSetExplorer renders into the element it was constructed with,
     * and refreshGrid takes no target of its own -- so a grid moved to the
     * wrong container emits an identical command list and an identical query
     * and simply renders nothing. One binding per query, or something is
     * loading data it will never show.
     */
    public function testEveryQueryHasSomewhereToRender(): void
    {
        $unbound = array();

        foreach ( self::$golden as $id => $snapshot ) {

            $queries   = count( $snapshot['queries'] ?? array() );
            $explorers = count( $snapshot['explorers'] ?? array() );

            if ( $queries !== $explorers ) {
                $unbound[] = "$id ($queries queries, $explorers explorers)";
            }

            foreach ( (array) ( $snapshot['explorers'] ?? array() ) as $entry ) {

                $this->assertNotSame( '', $entry['container'] ?? '',
                    "$id: " . ( $entry['var'] ?? '?' ) . ' is bound to no container' );
            }
        }

        $this->assertSame( array(), $unbound,
            'these reports load data they do not render, or render from data they '
            . 'do not load: ' . implode( ', ', $unbound ) );
    }

    /**
     * Every query carries a nonce.
     *
     * The value is normalised out of the fixture because it depends on the
     * user, which would otherwise make the recording machine-specific. That
     * normalisation must not be able to hide a query that has no nonce at all,
     * because such a request is refused.
     */
    public function testEveryQueryIsNonced(): void
    {
        $missing = array();

        foreach ( self::$golden as $id => $snapshot ) {

            foreach ( (array) ( $snapshot['queries'] ?? array() ) as $entry ) {

                if ( ! isset( $entry['query']['nonce'] ) ) {
                    $missing[] = $id . '/' . ( $entry['var'] ?? '?' );
                }
            }
        }

        $this->assertSame( array(), $missing,
            'these API links carry no nonce and would be refused: ' . implode( ', ', $missing ) );
    }

    /**
     * Positive control: the harness must be able to see a difference.
     *
     * Without this, "every report still emits what it emitted" is only as true
     * as the extraction is honest -- and an extractor that returned nothing
     * would agree with a fixture of nothings.
     */
    public function testTheHarnessCanSeeAChange(): void
    {
        $html = "<script>var aurl = 'https://x/owa/api/index.php?do=reports&metrics=visits&nonce=abc';\n"
              . "trend.asyncQueue.push(['makeAreaChart', [{x:'date'}], 'trend-chart']);</script>";

        $queries = Render::queriesIn( $html );

        $this->assertCount( 1, $queries );
        $this->assertSame( 'aurl', $queries[0]['var'] );
        $this->assertSame( 'visits', $queries[0]['query']['metrics'] );
        $this->assertSame( '<nonce>', $queries[0]['query']['nonce'],
            'the volatile value must be normalised, not passed through' );

        $this->assertSame( array( 'trend.makeAreaChart -> trend-chart' ),
            Render::commandsIn( $html ) );

        $this->assertSame( array( array( 'var' => 'g', 'container' => 'dimension-grid' ) ),
            Render::explorersIn( "var g = new OWA.resultSetExplorer('dimension-grid');" ),
            'the container a result set renders into must be observable' );

        /*
         * Repeated names must NOT collapse. The templates rebind the same
         * variable once per metric set, and keying by name recorded only the
         * last -- two of six with three metric sets, and the fixture looked
         * complete.
         */
        $twice = "var d = new OWA.resultSetExplorer('a-grid');\nvar d = new OWA.resultSetExplorer('b-grid');";

        $this->assertCount( 2, Render::explorersIn( $twice ),
            'two constructions of the same variable are two widgets, not one' );

        // ...and it must not invent things that are not there.
        $this->assertSame( array(), Render::queriesIn( '<p>no scripts here</p>' ) );
        $this->assertSame( array(), Render::commandsIn( '<p>no scripts here</p>' ) );
    }

    /**
     * A report with metric sets must offer a way to move between them.
     *
     * The check this recording did not have. Converting the multi-set reports
     * to widgets dropped the control entirely and every query, command and
     * binding stayed byte-identical -- because a control issues no query. The
     * reports rendered every set stacked on the page with no way to switch,
     * 1413 unit tests passed, and eight browser tests caught it.
     */
    public function testAMultiSetReportOffersAControl(): void
    {
        $missing = array();

        foreach ( self::$golden as $id => $snapshot ) {

            $control = $snapshot['control'] ?? array();

            // One panel per set is what makes a report multi-set here.
            if ( count( $control['panels'] ?? array() ) === 0 ) {
                continue;
            }

            if ( empty( $control['container'] ) || empty( $control['built'] ) ) {
                $missing[] = $id;
            }

            $this->assertSame( count( $control['panels'] ), $control['registered'],
                "$id registers a different number of sets than it renders panels for" );
        }

        $this->assertSame( array(), $missing,
            'these reports render metric sets with no way to move between them: '
            . implode( ', ', $missing ) );
    }

    /**
     * Exactly one thing loads a widget's data.
     *
     * A set registered with the control is loaded BY it, only when looked at.
     * A report that both registers sets and loads directly would query every
     * set on page load -- the same widgets, the same queries, all fired at
     * once, which nothing else here would show.
     */
    public function testSetsAreLoadedByTheControlOrDirectlyButNotBoth(): void
    {
        foreach ( self::$golden as $id => $snapshot ) {

            $control = $snapshot['control'] ?? array();

            if ( ! empty( $control['registered'] ) ) {

                $this->assertSame( 0, $control['loads'],
                    "$id registers its sets with the control AND loads them directly, "
                    . 'so every set queries on page load' );

            } elseif ( ! empty( $snapshot['queries'] ) ) {

                $this->assertGreaterThan( 0,
                    ( $control['loads'] ?? 0 ) + ( $control['loadsWithUrl'] ?? 0 ),
                    "$id loads nothing and has no control to load it, so its widgets "
                    . 'never fetch anything' );
            }
        }
    }

    /**
     * Every result-set explorer is told where to load from.
     *
     * The failure this exists for: an explorer with no URL is constructed,
     * registered with the control, and its query appears in the page -- and it
     * loads nothing. The grid is simply empty. Queries, commands, bindings and
     * the control record were all correct while the reports showed no data,
     * and only the browser tests noticed.
     */
    public function testEveryExplorerKnowsWhereToLoadFrom(): void
    {
        $bad = array();

        foreach ( self::$golden as $id => $snapshot ) {

            $explorers = count( $snapshot['explorers'] ?? array() );

            /*
             * Two conventions, both fine. The widget template tells an explorer
             * its url up front and whoever loads it calls load() with nothing;
             * the older bespoke templates pass the url to load() directly.
             * What matters is that every explorer has one or the other.
             */
            $sources = ( $snapshot['control']['urls'] ?? 0 )
                     + ( $snapshot['control']['loadsWithUrl'] ?? 0 );

            if ( $explorers > $sources ) {
                $bad[] = "$id ($explorers explorers, $sources with somewhere to load from)";
            }
        }

        $this->assertSame( array(), $bad,
            'these reports build result sets that were never told where to load from, '
            . 'so they render empty: ' . implode( ', ', $bad ) );
    }
}
