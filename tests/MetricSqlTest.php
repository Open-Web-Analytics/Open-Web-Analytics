<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/MetricSqlHarness.php';

use OWA\Tests\MetricSqlHarness as Harness;

/**
 * What each metric computes, as opposed to how it is registered.
 *
 * Pins the resolved SQL so that converting the 40 class-based metrics to
 * configuration can be shown not to move a single number. The catalog recording
 * cannot serve that purpose: it records class names and params, which a
 * conversion changes on purpose.
 */
final class MetricSqlTest extends TestCase
{
    /** @return array<string,mixed> */
    private function recorded(): array
    {
        $this->assertFileExists( Harness::fixturePath() );

        return (array) json_decode(
            (string) file_get_contents( Harness::fixturePath() ), true );
    }

    public function testEveryMetricStillComputesWhatItDid(): void
    {
        $recorded = $this->recorded();
        $actual   = Harness::snapshot();

        $this->assertSame(
            array_keys( $recorded ), array_keys( $actual ),
            'A metric appeared or disappeared.' );

        /* Per metric, so a diff names the one that moved. */
        foreach ( $recorded as $name => $expected ) {

            $this->assertSame(
                $expected, $actual[ $name ],
                "The metric '$name' resolves differently than it did. If a conversion caused "
                . 'this, the conversion changed a number.' );
        }
    }

    public function testNoAggregateMetricHasAnEmptyExpression(): void
    {
        /*
         * Core\Metric::getSelect() switches on the aggregation type and used to
         * have no default, so an unrecognised type returned an undefined
         * statement -- "a SELECT with a hole in it, built and run without
         * complaint", as the comment there puts it. It now reports the error,
         * but a null expression would still reach the query.
         *
         * A conversion is exactly when this would happen: a mistyped
         * metric_type in a config entry produces a metric that registers fine
         * and computes nothing.
         */
        foreach ( Harness::snapshot() as $name => $implementations ) {

            foreach ( $implementations as $implementation ) {

                if ( $implementation['kind'] !== 'aggregate' ) {

                    continue;
                }

                $this->assertNotEmpty(
                    $implementation['expression'],
                    "The metric '$name' resolves to an empty expression, which would be "
                    . 'selected as a hole rather than refused.' );

                $this->assertNotEmpty(
                    $implementation['entity'],
                    "The metric '$name' names no entity, so nothing can say which table "
                    . 'answers it.' );
            }
        }
    }

    public function testCalculatedMetricsCarryNoSqlAtAll(): void
    {
        /*
         * The property that makes them portable for free: a calculated metric
         * is arithmetic over other metrics' RESULTS, so it names no table and
         * no column, and a change of store cannot break it. Worth pinning
         * because it is the load-bearing half of the "six renderers" claim in
         * the plan.
         */
        $calculated = 0;
        $ratios     = 0;

        foreach ( Harness::snapshot() as $name => $implementations ) {

            foreach ( $implementations as $implementation ) {

                if ( $implementation['kind'] === 'ratio' ) {

                    $ratios++;

                    /*
                     * A ratio names its two sides rather than an expression
                     * containing them, so it carries even less than a formula
                     * does: no SQL, and nothing to substitute by name either.
                     */
                    $this->assertArrayNotHasKey( 'expression', $implementation );
                    $this->assertNotEmpty( $implementation['numerator'], "'$name' has no numerator." );
                    $this->assertNotEmpty( $implementation['denominator'], "'$name' has no denominator." );

                    // Sorted, because the recording sorts children so that a
                    // reordering is not a diff.
                    $sides = array( $implementation['numerator'], $implementation['denominator'] );
                    sort( $sides );

                    $this->assertSame(
                        $sides, $implementation['children'],
                        "'$name' must derive its children from its two sides, not declare them." );

                    continue;
                }

                if ( $implementation['kind'] !== 'calculated' ) {

                    continue;
                }

                $calculated++;

                $this->assertArrayNotHasKey( 'expression', $implementation );
                $this->assertNotEmpty( $implementation['formula'], "'$name' has no formula." );
                $this->assertNotEmpty( $implementation['children'], "'$name' has no children." );
            }
        }

        /*
         * NOT asserted greater than zero any more. Every calculated metric
         * became a `ratio` in #1133 -- a formula string cost an eval, a
         * substitution by metric name that collided when one name contained
         * another, and a child list restating what the formula already named.
         * The kind still renders; nothing declares it. Ratios carry the claim
         * now, which is what the count below checks.
         */
        $this->assertSame( 0, $calculated,
            'a metric declared `calculated` should be a `ratio`' );

        $this->assertGreaterThan(
            0, $ratios,
            'No ratio was found, so the branch above proves nothing.' );
    }

    public function testTheRecordingIsSubstantial(): void
    {
        /*
         * Guards the guard: if the catalog failed to load, every comparison
         * above would pass against an equally empty recording.
         */
        $snapshot = Harness::snapshot();

        $this->assertGreaterThan( 10, count( $snapshot ) );

        $expressions = 0;

        foreach ( $snapshot as $implementations ) {

            foreach ( $implementations as $implementation ) {

                if ( $implementation['kind'] === 'aggregate' ) {

                    $expressions++;
                }
            }
        }

        $this->assertGreaterThan( 8, $expressions );
    }
}
