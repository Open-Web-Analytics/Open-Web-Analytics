<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/ReportCharacterizationHarness.php';

use OWA\Tests\ReportCharacterizationHarness as Harness;

/**
 * What every config-driven report declares today, pinned before it is rewritten.
 *
 * This is step 0 of moving reports into stored configuration, and it exists
 * because "same behaviour, different plumbing" has no other definition. Fifty-five
 * of the sixty-eight report controllers are configuration wearing a class: their
 * action() sets metrics, dimensions, a sort, a page size, constraints and a
 * subview, and nothing else. Converting one to JSON is only correct if the
 * resulting report declares exactly what the controller declared.
 *
 * So the fixture is not a description of the desired output. It is a recording of
 * the CURRENT output, made before anything moved, and its whole value is that
 * nobody chose its contents.
 *
 * Regenerate deliberately, never to make a red test green:
 *
 *     OWA_REGEN_REPORT_GOLDEN=1 ./vendor/bin/phpunit tests/ReportCharacterizationTest.php
 *
 * A diff in that file during the conversion is the question "is this report
 * meant to change?" -- and for a behaviour-preserving move the answer is no.
 */
final class ReportCharacterizationTest extends TestCase
{
    /** @var array<string, array>|null */
    private static ?array $golden = null;

    public static function setUpBeforeClass(): void
    {
        if ( getenv( 'OWA_REGEN_REPORT_GOLDEN' ) ) {

            file_put_contents(
                Harness::goldenPath(),
                json_encode( Harness::captureAll(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
            );
        }

        self::$golden = json_decode(
            (string) file_get_contents( Harness::goldenPath() ), true );
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function reportProvider(): array
    {
        $cases = array();

        foreach ( Harness::reportNames() as $name ) {
            $cases[ $name ] = array( $name );
        }

        return $cases;
    }

    /**
     * One case per report, so a failure names the report rather than saying
     * "the reports changed".
     *
     * @dataProvider reportProvider
     */
    public function testTheReportStillDeclaresWhatItDeclared( string $name ): void
    {
        $this->assertArrayHasKey( $name, self::$golden,
            "$name has no recorded baseline -- regenerate the fixture deliberately, "
            . 'and say in the commit why a new report appeared' );

        $actual = Harness::snapshot( $name );

        /*
         * `deprecated` is a deliberate addition that no controller ever
         * declared -- see ReportConfigEquivalenceTest, which reads this same
         * fixture. Removed rather than regenerated into the baseline: this
         * file is the pre-conversion record, and rewriting it from current
         * output is what would make that equivalence proof tautological.
         *
         * Removed from $actual only, so every other drift still fails below.
         */
        unset( $actual['config']['deprecated'] );

        /*
         * ...and the widgets deliberately re-typed since the conversion are put
         * back to what the controller declared, so everything else about them
         * is still compared. Same allowance the equivalence test applies, from
         * the same list -- see Harness::RETYPED.
         */
        $retyped = Harness::undoRetyping( $name, $actual['config'] );

        $this->assertSame( array(), $retyped['problems'],
            "the re-typing allowance for $name does not match the definition:\n  "
            . implode( "\n  ", $retyped['problems'] ) );

        $actual['config'] = $retyped['config'];

        /*
         * ...and the same for a report deliberately relaid out: position and
         * span are reconciled with the record, everything else still compared.
         */
        $expected = self::$golden[ $name ];

        $layout = Harness::normaliseLayout( $name, $expected['config'], $actual['config'] );

        $this->assertSame( array(), $layout['problems'],
            "the relayout allowance for $name does not match the definition:\n  "
            . implode( "\n  ", $layout['problems'] ) );

        $expected['config'] = $layout['expected'];
        $actual['config']   = $layout['actual'];

        $this->assertSame(
            $expected,
            $actual,
            "$name declares something different from its recorded baseline. If that is "
            . 'intended, regenerate; if this is a conversion, it is a regression.'
        );
    }

    /**
     * Coverage drift in the other direction: a report deleted, or newly
     * excluded, silently shrinks what the suite protects. The per-report cases
     * above cannot see that, because they only iterate what exists now.
     */
    public function testTheBaselineCoversExactlyTheReportsInScope(): void
    {
        $recorded = array_keys( self::$golden );
        $present  = Harness::reportNames();

        sort( $recorded );
        sort( $present );

        $this->assertSame( $recorded, $present,
            'the fixture and the tree disagree about which reports exist' );
    }

    /**
     * The parameterised reports are the ones a naive harness under-tests: run
     * with no parameter, the constraint interpolation -- the only thing that
     * distinguishes them from a pure config report -- never executes.
     */
    public function testEveryParameterReachesTheConfig(): void
    {
        $checked = 0;

        foreach ( self::$golden as $name => $recorded ) {

            if ( ! $recorded['params'] ) {
                continue;
            }

            $flat = json_encode( $recorded['config'] );

            $this->assertStringContainsString( Harness::SENTINEL, $flat,
                "$name reads " . implode( ', ', $recorded['params'] )
                . ' but the value reaches nothing it declares' );

            $checked++;
        }

        $this->assertGreaterThan( 10, $checked,
            'the parameterised reports are the point of this test; finding almost '
            . 'none means the parameter detection broke, not that they went away' );
    }

    /**
     * No in-scope report may raise a diagnostic.
     *
     * These controllers had never been executed by a test until this harness,
     * and the first CI run turned up three deprecations and a warning that had
     * been there the whole time -- invisible locally, because deprecations are
     * not fatal here and CI makes them so.
     *
     * The specific cause is worth pinning rather than just fixing: three
     * controllers name their request parameter through a variable, so a
     * literal-string scan does not see it, the parameter is never supplied, and
     * urlencode(null) deprecates. Detection now resolves that form -- and this
     * assertion is what catches the next shape it takes.
     */
    public function testNoReportRaisesADiagnostic(): void
    {
        foreach ( Harness::reportNames() as $name ) {

            $snap = Harness::snapshot( $name );

            $this->assertSame( array(), $snap['diagnostics'],
                "$name raised diagnostics while declaring itself: "
                . implode( ' | ', $snap['diagnostics'] ) );
        }
    }

    /**
     * Positive control for the diagnostics guard.
     *
     * "No report raises a diagnostic" is only as strong as the recording behind
     * it. If observe() ever stopped capturing -- or returned an empty list --
     * every report would look clean forever and the guard would be a claim
     * rather than a guard. So prove it fires.
     */
    public function testTheDiagnosticsGuardActuallyCatchesSomething(): void
    {
        $noisy = new class {
            public $data = array( 'subview' => 'test.noisy' );

            public function action(): void
            {
                // Exactly the shape the real reports produced: a parameter that
                // was never supplied, handed to a string function.
                $this->data['constraints'] = 'x==' . urlencode( null );
            }
        };

        $observed = Harness::observe( $noisy );

        $this->assertNotEmpty( $observed['diagnostics'],
            'observe() must record diagnostics, or the no-diagnostics guard proves nothing' );

        $this->assertStringContainsString( 'urlencode', $observed['diagnostics'][0] );

        // ...and it still captures the configuration alongside them.
        $this->assertSame( 'test.noisy', $observed['config']['subview'] );
    }

    /**
     * Vacuity guards.
     *
     * Every assertion above passes trivially against an empty fixture or an
     * empty snapshot, which is exactly how a characterization suite rots into
     * decoration.
     */
    public function testTheBaselineIsSubstantial(): void
    {
        $this->assertGreaterThan( 50, count( self::$golden ),
            'the whole point is breadth; a handful of reports is not a baseline' );

        foreach ( self::$golden as $name => $recorded ) {

            $this->assertNotEmpty( $recorded['config'],
                "$name recorded no configuration at all" );

            $this->assertArrayHasKey( 'subview', $recorded['config'],
                "$name records no subview, so nothing pins how it renders" );
        }
    }

    /**
     * The harness must actually run the controller. If snapshot() ever returned
     * a cached or empty structure the suite would go green forever.
     */
    public function testTheHarnessObservesRealControllerWork(): void
    {
        $snap = Harness::snapshot( 'ReportPages' );

        // pages is laid out as widgets now: the subview is the grid renderer,
        // and what used to be top-level metrics/resultsPerPage is the query of
        // the widget that asks for them. Same values, read where they now live.
        $this->assertSame( 'base.reportWidgets', $snap['config']['subview'] );

        $widgets = $snap['config']['widgets'];

        $this->assertNotEmpty( $widgets, 'the harness recorded no widgets at all' );

        $grid = null;

        foreach ( $widgets as $widget ) {
            if ( ( $widget['type'] ?? '' ) === 'grid' ) {
                $grid = $widget;
            }
        }

        $this->assertNotNull( $grid, 'pages should still declare a grid widget' );

        // Metrics are report-wide now -- every widget queries the same ones, so
        // they are held once and a metric set can replace them in one place.
        // resultsPerPage is genuinely per widget and stays on the grid.
        $this->assertStringContainsString( 'pageViews', (string) $snap['config']['metrics'] );
        $this->assertSame( 30, $grid['query']['resultsPerPage'] );
    }
}
