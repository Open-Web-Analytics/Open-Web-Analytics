<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Entity\GoalEvent;
use OWA\Module\Base\Classes\GoalEventPredicate;

/**
 * A goal event compiled into a funnel step.
 *
 * The pair have to agree. The same goal event decides a conversion at ingest
 * through GoalEvent::compare(), and a funnel stage at read time through this --
 * so a difference between them is a funnel stage that disagrees with the
 * conversion count sitting beside it on the same screen.
 *
 * These tests hold the two against each other operator by operator, and hold
 * the line on the two things that must never happen quietly: a condition the
 * funnel cannot express being dropped, and an empty rule matching everything.
 */
final class GoalEventPredicateTest extends TestCase
{
    /**
     * A goal event carrying the given conditions, without touching a database.
     *
     * Stubs rather than the real entities: an Entity's constructor declares its
     * columns with the OWA_DTD_* constants, which are not defined in the
     * configless run CI uses -- so building one here would make this file pass
     * on this box and error in CI.
     *
     * The compiler asks a goal event for its conditions and its ALL/ANY, and
     * asks a condition for three values. That is the whole surface, and these
     * are exactly it.
     */
    private function goalEvent( array $conditions, $match = GoalEvent::MATCH_ALL )
    {
        $rows = array();

        foreach ( $conditions as $c ) {

            $rows[] = new class( $c ) {

                private $values;

                public function __construct( array $c )
                {
                    $this->values = array(
                        'condition_property' => $c[0],
                        'condition_operator' => $c[1],
                        'condition_value'    => $c[2],
                    );
                }

                public function get( $name, $filter = true )
                {
                    return $this->values[ $name ] ?? null;
                }
            };
        }

        return new class( $rows, $match ) {

            private $rows;
            private $match;

            public function __construct( $rows, $match )
            {
                $this->rows  = $rows;
                $this->match = $match;
            }

            public function loadConditions( $role = GoalEvent::ROLE_MATCH ) { return $this->rows; }

            public function conditionMatch() { return $this->match; }

            public function get( $name, $filter = true ) { return 'Test Goal'; }
        };
    }

    private function compile( array $conditions, $match = GoalEvent::MATCH_ALL )
    {
        $p = new GoalEventPredicate;

        return array( $p->compile( $this->goalEvent( $conditions, $match ) ), $p );
    }

    /** page_uri is the document's uri, and the value is BOUND, never inlined. */
    public function testAnExactPageConditionBecomesAColumnComparison(): void
    {
        list( $out ) = $this->compile( array(
            array( 'page_uri', GoalEvent::MATCH_EXACT, '/thanks' ) ) );

        $this->assertNotNull( $out );
        $this->assertStringContainsString( 'd.uri', $out['sql'] );
        $this->assertStringContainsString( '?', $out['sql'] );
        $this->assertSame( array( '/thanks' ), $out['params'] );

        // The value never appears in the SQL text. A funnel step is author
        // input reaching a query, which is the seam this codebase keeps.
        $this->assertStringNotContainsString( '/thanks', $out['sql'] );
    }

    /**
     * A condition the funnel cannot express REFUSES, and names the property.
     *
     * Dropping it would silently WIDEN the goal event: "purchase over 50 from
     * the pricing page" would become "from the pricing page" and report a
     * bigger number that looks entirely plausible. Silently discarded
     * constraints have produced exactly that kind of wrong answer here before.
     */
    public function testAConditionTheFunnelCannotExpressIsRefusedByName(): void
    {
        list( $out, $p ) = $this->compile( array(
            array( 'page_uri', GoalEvent::MATCH_EXACT, '/basket' ),
            array( 'medium',   GoalEvent::MATCH_EXACT, 'organic-search' ) ) );

        $this->assertNull( $out,
            'A goal event testing a property the funnel cannot reach compiled anyway, so the '
            . 'funnel counts a WIDER condition than the goal event means.' );

        $this->assertSame( 'medium', $p->getError(),
            'The refusal does not name the property, so nobody can tell what to change.' );
    }

    /** ALL and ANY are what the goal event says, not what the compiler prefers. */
    public function testConditionsCombineTheWayTheGoalEventSays(): void
    {
        list( $all ) = $this->compile( array(
            array( 'page_uri',   GoalEvent::MATCH_EXACT, '/a' ),
            array( 'page_title', GoalEvent::MATCH_EXACT, 'A' ) ), GoalEvent::MATCH_ALL );

        $this->assertStringContainsString( ' AND ', $all['sql'] );
        $this->assertStringNotContainsString( ' OR ', $all['sql'] );

        list( $any ) = $this->compile( array(
            array( 'page_uri',   GoalEvent::MATCH_EXACT, '/a' ),
            array( 'page_title', GoalEvent::MATCH_EXACT, 'A' ) ), GoalEvent::MATCH_ANY );

        $this->assertStringContainsString( ' OR ', $any['sql'] );
        $this->assertStringNotContainsString( ' AND ', $any['sql'] );
    }

    /**
     * NO conditions matches NOTHING.
     *
     * matchesEvent() answers the same for the same reason: an empty rule is
     * vacuously true, and a half-written goal event that counted every event on
     * the site would be loudly wrong only after the fact. Compiled rather than
     * refused, because it is not an error -- it is a goal event that genuinely
     * counts nothing.
     */
    public function testAGoalEventWithNoConditionsMatchesNothing(): void
    {
        list( $out ) = $this->compile( array() );

        $this->assertNotNull( $out );
        $this->assertSame( '( 0 = 1 )', $out['sql'] );
        $this->assertSame( array(), $out['params'] );
    }

    /** An operator nobody recognises matches nothing, exactly as compare() does. */
    public function testAnUnknownOperatorMatchesNothing(): void
    {
        list( $out ) = $this->compile( array(
            array( 'page_uri', 'sideways', '/a' ) ) );

        $this->assertStringContainsString( '0 = 1', $out['sql'],
            'An unrecognised operator compiles to something that can match, while compare() '
            . 'answers false for it -- so the funnel and the conversion count disagree.' );

        $this->assertFalse( GoalEvent::compare( '/a', 'sideways', '/a' ),
            'compare() no longer refuses an unknown operator, so this expectation is stale.' );
    }

    /**
     * An empty target matches nothing under contains and begins-with.
     *
     * compare() guards on `$target !== ''` for both, and the SQL would answer
     * the opposite without a guard of its own: LOCATE('', anything) is 1. So
     * every page on the site would satisfy the step.
     */
    public function testAnEmptyTargetMatchesNothingRatherThanEverything(): void
    {
        foreach ( array( GoalEvent::MATCH_CONTAINS, GoalEvent::MATCH_BEGINS,
                         GoalEvent::MATCH_REGEX ) as $operator ) {

            list( $out ) = $this->compile( array( array( 'page_uri', $operator, '' ) ) );

            $this->assertStringContainsString( '0 = 1', $out['sql'],
                "An empty $operator target compiles to something that matches, while compare() "
                . 'answers false for it.' );

            $this->assertFalse( GoalEvent::compare( '/anything', $operator, '' ) );
        }
    }

    /**
     * CONTAINS uses LOCATE, not LIKE.
     *
     * A value carrying % or _ is a wildcard to LIKE, so a goal event on
     * "50% off" would quietly match far more than it says. LOCATE has no
     * pattern language at all, so there is nothing to escape and nothing to
     * forget to escape.
     */
    public function testContainsHasNoPatternLanguage(): void
    {
        list( $out ) = $this->compile( array(
            array( 'page_uri', GoalEvent::MATCH_CONTAINS, '50%_off' ) ) );

        $this->assertStringContainsString( 'LOCATE', $out['sql'] );
        $this->assertStringNotContainsString( 'LIKE', $out['sql'] );
        $this->assertSame( array( '50%_off' ), $out['params'] );
    }

    /**
     * A NULL column reads as the empty string, because compare() casts to
     * string before comparing. Without COALESCE, `NOT` on a NULL column answers
     * NULL -- which is not a match -- while compare() answers true.
     */
    public function testANullColumnIsComparedAsTheEmptyStringLikeCompareDoes(): void
    {
        list( $out ) = $this->compile( array(
            array( 'page_title', GoalEvent::MATCH_NOT, 'Checkout' ) ) );

        $this->assertStringContainsString( 'COALESCE', $out['sql'] );

        // The behaviour COALESCE is there to mirror.
        $this->assertTrue( GoalEvent::compare( null, GoalEvent::MATCH_NOT, 'Checkout' ) );
    }

    /** Every property the compiler accepts is one a funnel step can be written on. */
    public function testEveryAcceptedPropertyCompiles(): void
    {
        foreach ( array_keys( GoalEventPredicate::COLUMNS ) as $property ) {

            list( $out ) = $this->compile( array(
                array( $property, GoalEvent::MATCH_EXACT, 'x' ) ) );

            $this->assertNotNull( $out, "$property is listed as accepted but does not compile." );
        }
    }
}
