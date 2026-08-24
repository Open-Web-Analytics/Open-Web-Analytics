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
            $cases[ $id ] = array( $id, $extra );
        }

        return $cases;
    }

    /**
     * @dataProvider reportProvider
     */
    public function testTheReportStillEmitsWhatItEmitted( string $id, array $extra ): void
    {
        $this->requireDb();

        $this->assertArrayHasKey( $id, self::$golden,
            "$id has no recorded render; regenerate deliberately and say why in the commit" );

        $this->assertSame( self::$golden[ $id ], Render::snapshot( $id, $extra ),
            "$id renders different queries or commands than it did. If this is the widget "
            . 'conversion, it is a regression; the point of the conversion is that this '
            . 'does not change.' );
    }

    /**
     * Coverage in the other direction: a report that stopped being covered
     * silently shrinks what this protects.
     */
    public function testTheFixtureCoversExactlyTheReportsThatExist(): void
    {
        $recorded = array_keys( self::$golden );
        $present  = array_keys( Render::coveredReports() );

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

            foreach ( (array) ( $snapshot['explorers'] ?? array() ) as $receiver => $container ) {

                $this->assertNotSame( '', $container,
                    "$id: $receiver is bound to no container" );
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

            foreach ( (array) ( $snapshot['queries'] ?? array() ) as $name => $query ) {

                if ( ! isset( $query['nonce'] ) ) {
                    $missing[] = "$id/$name";
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

        $this->assertArrayHasKey( 'aurl', $queries );
        $this->assertSame( 'visits', $queries['aurl']['metrics'] );
        $this->assertSame( '<nonce>', $queries['aurl']['nonce'],
            'the volatile value must be normalised, not passed through' );

        $this->assertSame( array( 'trend.makeAreaChart -> trend-chart' ),
            Render::commandsIn( $html ) );

        $this->assertSame( array( 'g' => 'dimension-grid' ),
            Render::explorersIn( "var g = new OWA.resultSetExplorer('dimension-grid');" ),
            'the container a result set renders into must be observable' );

        // ...and it must not invent things that are not there.
        $this->assertSame( array(), Render::queriesIn( '<p>no scripts here</p>' ) );
        $this->assertSame( array(), Render::commandsIn( '<p>no scripts here</p>' ) );
    }
}
