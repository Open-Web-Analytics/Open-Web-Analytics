<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/ReportCharacterizationHarness.php';

use OWA\Tests\ReportCharacterizationHarness as Harness;

/**
 * The converted reports render, and rendering them raises nothing.
 *
 * This began as a recording of what 35 report controllers declared, made before
 * any of them moved, so the conversion could be held to it. That recording is
 * retired along with the gate that read it -- see ReportConfigEquivalenceTest
 * for why -- and what is left is the part that never depended on it: these
 * reports are executed, and executing them must stay silent.
 *
 * That silence is worth its own test. These controllers had never been run by
 * any test until this harness existed, and the first CI run turned up three
 * deprecations and a warning that had been there the whole time.
 */
final class ReportCharacterizationTest extends TestCase
{


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
     * The parameterised reports are the ones a naive harness under-tests: run
     * with no parameter, the constraint interpolation -- the only thing that
     * distinguishes them from a pure config report -- never executes.
     *
     * Asked of the report as it is TODAY, not of the recorded controller. The
     * property was always a live one; reading it out of a frozen fixture only
     * ever proved that a controller interpolated its parameter in August 2026.
     */
    public function testEveryParameterReachesTheConfig(): void
    {
        $checked = 0;

        foreach ( Harness::CONVERTED as $id => $name ) {

            $snapshot = Harness::snapshotConfigured( $id );

            if ( ! $snapshot['params'] ) {
                continue;
            }

            $flat = json_encode( $snapshot['config'] );

            $this->assertStringContainsString( Harness::SENTINEL, $flat,
                "$id reads " . implode( ', ', $snapshot['params'] )
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
