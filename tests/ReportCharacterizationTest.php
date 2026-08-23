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

        $this->assertSame(
            self::$golden[ $name ],
            Harness::snapshot( $name ),
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

        $this->assertSame( 'base.reportSimpleDimensional', $snap['config']['subview'] );
        $this->assertStringContainsString( 'pageViews', $snap['config']['metrics'] );
        $this->assertSame( 30, $snap['config']['resultsPerPage'] );
    }
}
