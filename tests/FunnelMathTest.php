<?php

use PHPUnit\Framework\TestCase;

/**
 * The funnel's arithmetic, with no database in sight.
 *
 * A funnel is a CLOSED, INDIRECTLY-followed sequence counted per subject --
 * GA's defaults. Everybody enters at step 1; other things may happen between
 * steps; a subject enters the funnel once in the period and it is their first
 * run through that counts.
 *
 * The query's job is to hand over the events that match some step, grouped by
 * subject and ordered by time. Everything after that is here, which is why
 * these run without a connection -- CI's unit job has no database, and this is
 * the half of the funnel that does not need one.
 */
final class FunnelMathTest extends TestCase
{
    /** One row of the funnel query: a subject, a time, an id, a flag per step. */
    private function row( $subject, $ts, array $flags, $rid = null ): array
    {
        $row = array( 'subj' => $subject, 'ts' => (string) $ts,
                      'rid' => (string) ( $rid ?? ( $ts . $subject ) ) );

        foreach ( $flags as $i => $flag ) {
            $row[ 's' . $i ] = (string) $flag;
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
     * Two steps written with overlapping conditions need two events, because a
     * step is something the subject DID. A single event advancing two stages
     * would let one page view report a completed three-stage path.
     */
    public function testOneEventAdvancesAtMostOneStep(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 1, 1, 1 ) ),
        );

        $this->assertSame( array( 1, 0, 0 ), $this->walk( $rows, 3 ) );

        // Two events, two stages -- and at a LATER time, so this is the
        // ordinary case rather than the tie handled below.
        $rows[] = $this->row( 'v1', 200, array( 1, 1, 1 ) );

        $this->assertSame( array( 1, 1, 0 ), $this->walk( $rows, 3 ) );
    }

    /**
     * TWO EVENTS IN THE SAME SECOND ARE STILL TWO EVENTS.
     *
     * The fact table records whole seconds: msec is declared INT and fed the
     * fractional part of microtime() as a string, so it rounds to 0 or 1 and
     * carries nothing, and request ids are the tracker's random GUID. So within
     * one second there is no evidence about what happened first.
     *
     * Ordering them arbitrarily would settle it by coin flip -- the same pair
     * counting as a sequence for one visitor and not the next, for no reason
     * anybody could point at. They are resolved as a SET instead, in the
     * funnel's own order: if somebody hit /basket and /checkout inside one
     * second, the reading that they did it in that order is the only one worth
     * having.
     *
     * Fixing msec would make this a real comparison rather than an assumption.
     */
    public function testTwoEventsInOneSecondCanSatisfyTwoSteps(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 1, 0 ), 'a' ),
            $this->row( 'v1', 100, array( 0, 1 ), 'b' ),
        );

        $this->assertSame( array( 1, 1 ), $this->walk( $rows, 2 ) );

        // And the same pair in the other order, which is the whole point: the
        // arrival order inside a second must not change the answer.
        $this->assertSame( array( 1, 1 ), $this->walk( array_reverse( $rows ), 2 ) );
    }

    /**
     * But a tie still spends each event once.
     *
     * Otherwise "the same second" would become a licence for one page view to
     * complete an entire funnel, which is the failure the set-resolution has to
     * avoid while fixing the coin flip.
     */
    public function testATieCannotSpendOneEventTwice(): void
    {
        $rows = array(
            $this->row( 'v1', 100, array( 1, 1, 1 ) ),
        );

        $this->assertSame( array( 1, 0, 0 ), $this->walk( $rows, 3 ) );

        // Two events that each satisfy everything advance exactly two stages.
        $rows[] = $this->row( 'v1', 100, array( 1, 1, 1 ), 'second' );

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
     * No step can out-count the one before it, because reaching step N requires
     * having reached N-1. An implementation that counted the steps
     * independently needed a guard to keep this true.
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

    /**
     * It takes an ITERABLE, because the caller streams.
     *
     * The rows are the work, not the answer, so the query hands them over one
     * at a time rather than as an array -- see countFunnel(). A walk that only
     * accepted arrays would quietly undo that at the call site.
     */
    public function testItWalksAGeneratorWithoutMaterialisingIt(): void
    {
        $rows = function () {
            yield $this->row( 'v1', 100, array( 1, 0 ) );
            yield $this->row( 'v1', 200, array( 0, 1 ) );
            yield $this->row( 'v2', 100, array( 1, 0 ) );
        };

        $this->assertSame( array( 2, 1 ),
            \OWA\Module\Base\Controller\VisualizationFunnel::walk( $rows(), 2 ) );
    }
}
