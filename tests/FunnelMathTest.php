<?php

use PHPUnit\Framework\TestCase;

/**
 * The funnel's arithmetic, and the goal-event conditions it counts against.
 *
 * A funnel is a CLOSED, INDIRECTLY-followed sequence counted per subject --
 * GA's defaults. Everybody enters at step 1; other things may happen between
 * steps; a subject enters the funnel once in the period and it is their first
 * run through it that is counted.
 *
 * The tests below are mostly about the last clause, because it is the one an
 * obvious implementation gets wrong.
 */
final class FunnelMathTest extends TestCase
{
    /** One row of the funnel query: a subject, a time, and a flag per step. */
    private function row( $subject, $ts, array $flags ): array
    {
        $row = array( 'subj' => $subject, 'ts' => $ts );

        foreach ( $flags as $i => $flag ) {
            $row[ 's' . $i ] = $flag;
        }

        return $row;
    }

    private function walk( array $rows, $steps ): array
    {
        return \OWA\Module\Base\Controller\VisualizationFunnel::walk( $rows, $steps );
    }

    /** The ordinary case: one subject walks the whole path in order. */
    public function testASubjectWhoWalksThePathCountsInEveryStep(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 1, 0, 0 ) ),
            $this->row( 'v1', 200, array( 0, 1, 0 ) ),
            $this->row( 'v1', 300, array( 0, 0, 1 ) ),
        );

        $this->assertSame( array( 1, 1, 1 ), $this->walk( $rows, 3 ) );
    }

    /**
     * THE ONE THAT MATTERS.
     *
     * A visitor reads the docs, comes back to the home page, goes to pricing,
     * and reads the docs again. They completed the funnel / -> /pricing ->
     * /docs, and GA counts them.
     *
     * The obvious implementation -- MIN(timestamp) per step, then check the
     * timestamps come out in order -- gives 11:00, 12:00, 10:00 and drops them
     * at the last step, because it looked for the first occurrence of /docs
     * OVERALL rather than the first one after /pricing.
     *
     * It is not an edge case. Funnels start on home pages and pricing pages,
     * which are exactly the pages people also visit at other times, and the
     * error is always an undercount at the later steps -- so it reads as a
     * conversion problem rather than as a bug.
     */
    public function testAStepVisitedBeforeTheFunnelStartedDoesNotDropTheSubject(): void
    {
        $rows = array(
            // /docs, before any of this began.
            $this->row( 'v1', 1000, array( 0, 0, 1 ) ),
            // and then the funnel, in order.
            $this->row( 'v1', 1100, array( 1, 0, 0 ) ),
            $this->row( 'v1', 1200, array( 0, 1, 0 ) ),
            $this->row( 'v1', 1300, array( 0, 0, 1 ) ),
        );

        $this->assertSame( array( 1, 1, 1 ), $this->walk( $rows, 3 ),
            'A subject who really did complete the funnel was dropped because the last step\'s '
            . 'page was also visited before the funnel started.' );
    }

    /** A subject who stops halfway counts in the steps they reached, and no further. */
    public function testASubjectWhoStopsCountsOnlyAsFarAsTheyGot(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 1, 0, 0 ) ),
            $this->row( 'v1', 200, array( 0, 1, 0 ) ),
        );

        $this->assertSame( array( 1, 1, 0 ), $this->walk( $rows, 3 ) );
    }

    /**
     * CLOSED: everybody enters at step 1.
     *
     * A subject who arrives straight at step 2 is not in the funnel at all --
     * they are not counted in step 2 and then dropped, they are simply never
     * counted. An open funnel would count them, and OWA does not offer one.
     */
    public function testASubjectWhoNeverReachedTheFirstStepIsNotInTheFunnel(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 0, 1, 0 ) ),
            $this->row( 'v1', 200, array( 0, 0, 1 ) ),
        );

        $this->assertSame( array( 0, 0, 0 ), $this->walk( $rows, 3 ) );
    }

    /**
     * INDIRECT: other things may happen in between.
     *
     * The next step has to come later, not next. A funnel that required the
     * very next event would report almost nobody on any real site.
     */
    public function testEventsBetweenTheStepsDoNotBreakTheSequence(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 1, 0, 0 ) ),
            $this->row( 'v1', 150, array( 0, 0, 0 ) ),
            $this->row( 'v1', 160, array( 0, 0, 0 ) ),
            $this->row( 'v1', 200, array( 0, 1, 0 ) ),
            $this->row( 'v1', 300, array( 0, 0, 1 ) ),
        );

        $this->assertSame( array( 1, 1, 1 ), $this->walk( $rows, 3 ) );
    }

    /**
     * One event is one step.
     *
     * Two steps written with overlapping conditions need two visits, because a
     * step is something the subject DID. A single event advancing two stages
     * would let a one-page funnel report a completed three-stage path.
     */
    public function testOneEventAdvancesAtMostOneStep(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 1, 1, 1 ) ),
        );

        $this->assertSame( array( 1, 0, 0 ), $this->walk( $rows, 3 ) );

        // Two visits, two stages.
        $rows[] = $this->row( 'v1', 200, array( 1, 1, 1 ) );

        $this->assertSame( array( 1, 1, 0 ), $this->walk( $rows, 3 ) );
    }

    /** Each subject is counted once, and every subject is counted. */
    public function testEverySubjectIsCountedIncludingTheLast(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 1, 0 ) ),
            $this->row( 'v1', 200, array( 0, 1 ) ),
            $this->row( 'v2', 100, array( 1, 0 ) ),
            // v3 is last, which is the case a tally that only runs when the
            // subject CHANGES would miss.
            $this->row( 'v3', 100, array( 1, 0 ) ),
            $this->row( 'v3', 200, array( 0, 1 ) ),
        );

        $this->assertSame( array( 3, 2 ), $this->walk( $rows, 2 ) );
    }

    /**
     * A subject who runs the funnel twice is one subject.
     *
     * GA reports only the first sequence within the date range, and so does
     * this: the walk never restarts.
     */
    public function testRunningTheFunnelTwiceCountsOnce(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 1, 0 ) ),
            $this->row( 'v1', 200, array( 0, 1 ) ),
            $this->row( 'v1', 300, array( 1, 0 ) ),
            $this->row( 'v1', 400, array( 0, 1 ) ),
        );

        $this->assertSame( array( 1, 1 ), $this->walk( $rows, 2 ) );
    }

    /**
     * A funnel is monotonic BY CONSTRUCTION.
     *
     * No step can out-count the one before it, because reaching step N now
     * requires having reached N-1. The old implementation counted the steps
     * independently and needed a guard to keep this true.
     */
    public function testNoStepCanOutCountTheStepBeforeIt(): void
    {
        $rows = array();

        // A crowd who all reach the last step and only some of whom reach the
        // first, which is the shape that broke independent counting.
        for ( $i = 0; $i < 20; $i++ ) {

            $rows[] = $this->row( 'v' . $i, 100, array( 0, 0, 1 ) );

            if ( $i % 2 === 0 ) {

                $rows[] = $this->row( 'v' . $i, 200, array( 1, 0, 0 ) );
                $rows[] = $this->row( 'v' . $i, 300, array( 0, 1, 0 ) );
            }
        }

        $counts = $this->walk( $rows, 3 );

        for ( $i = 1; $i < count( $counts ); $i++ ) {

            $this->assertLessThanOrEqual( $counts[ $i - 1 ], $counts[ $i ],
                'A step out-counts the one in front of it, which a funnel cannot do.' );
        }

        // 10 reached / and /pricing; none of them saw /docs afterwards.
        $this->assertSame( array( 10, 10, 0 ), $counts );
    }

    /** Nobody in the funnel is zeroes, not an empty array. */
    public function testAnEmptyResultIsAZeroForEveryStep(): void
    {
        $this->assertSame( array( 0, 0, 0 ), $this->walk( array(), 3 ) );
    }
}
