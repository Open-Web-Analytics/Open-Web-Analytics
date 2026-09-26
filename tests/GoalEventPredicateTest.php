<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Entity\GoalEvent;
use OWA\Module\Base\Classes\GoalEventPredicate;

/*
 * BOOTED, BUT NOT CONNECTED, and the two are different things.
 *
 * This file used to touch no framework at all: the compiler took a stubbed goal
 * event and read a const map of four property names, so nothing had to exist.
 * The check is GoalVocabulary now -- the same one the builder, the save and
 * marking use -- and that answers from the raw entity's own columns, which needs
 * the framework up. The alternative was to keep a second hand-written list of
 * columns here, which is the drift the vocabulary exists to remove.
 *
 * No DATABASE is needed, which is what testItCompilesWithoutAConnection below
 * still pins: bootstrap_owa.php boots configless when there is no owa-config.php,
 * which is the shape of CI's unit job.
 */
require_once __DIR__ . '/bootstrap_owa.php';

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
     * Stubs rather than the real entities, because the compiler's whole surface
     * is three getters and a list -- and because a goal event built for real
     * would need a row, a Property and a condition table to read back, none of
     * which any of these assertions is about.
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

        /*
         * The funnel's own alias, passed in rather than defaulted: the predicate
         * is compiled into someone else's query and the two agreeing about what
         * the alias means is the contract.
         */
        return array( $p->compile( $this->goalEvent( $conditions, $match ),
            \OWA\Module\Base\Controller\VisualizationFunnel::ALIAS ), $p );
    }

    /**
     * A condition column IS a cube column, and the value is BOUND, never inlined.
     *
     * Nothing translates: the cube carries every raw column under its own name,
     * so `page_path` is `page_path`. There used to be a map of four v1 property
     * names to four owa_document columns here, which is the table the funnel
     * joined and which v2 ingest does not write.
     */
    public function testAnExactPageConditionBecomesAColumnComparison(): void
    {
        list( $out ) = $this->compile( array(
            array( 'page_path', GoalEvent::MATCH_EXACT, '/thanks' ) ) );

        $this->assertNotNull( $out );
        $this->assertStringContainsString( 'e.page_path', $out['sql'] );
        $this->assertStringContainsString( '?', $out['sql'] );
        $this->assertSame( array( '/thanks' ), $out['params'] );

        // The value never appears in the SQL text. A funnel step is author
        // input reaching a query, which is the seam this codebase keeps.
        $this->assertStringNotContainsString( '/thanks', $out['sql'] );
    }

    /**
     * A condition naming something that is not a column REFUSES, by name.
     *
     * Dropping it would silently WIDEN the goal event: "purchase over 50 from
     * the pricing page" would become "from the pricing page" and report a
     * bigger number that looks entirely plausible. Silently discarded
     * constraints have produced exactly that kind of wrong answer here before.
     *
     * page_type is the case that exists in the field: a v1 document
     * classification with no column on the v2 row, which Update049 leaves in
     * place -- switching its goal off -- precisely so the name survives to be
     * shown to whoever has to rewrite it.
     *
     * It is also the registry check standing between a stored name and a column
     * name interpolated into SQL.
     */
    public function testAConditionThatIsNotAColumnIsRefusedByName(): void
    {
        list( $out, $p ) = $this->compile( array(
            array( 'page_path', GoalEvent::MATCH_EXACT, '/basket' ),
            array( 'page_type', GoalEvent::MATCH_EXACT, 'attachment' ) ) );

        $this->assertNull( $out,
            'A goal event testing something that is not a column compiled anyway, so the '
            . 'funnel counts a WIDER condition than the goal event means.' );

        $this->assertSame( 'page_type', $p->getError(),
            'The refusal does not name the property, so nobody can tell what to change.' );
    }

    /**
     * ...and the columns that used to be refused now compile.
     *
     * tagged_medium and device_type are the two that make the point: neither was
     * expressible before, one because the map held four names and the other
     * because it is derived in the row handler and never existed as a property at
     * all. Both are cube columns.
     */
    public function testTheColumnsTheOldMapCouldNotReachNowCompile(): void
    {
        foreach ( array( 'tagged_medium', 'device_type', 'revenue', 'element_id' )
                  as $column ) {

            list( $out ) = $this->compile( array(
                array( $column, GoalEvent::MATCH_EXACT, 'x' ) ) );

            $this->assertNotNull( $out, "$column is a cube column and did not compile." );

            $this->assertStringContainsString( 'e.' . $column, $out['sql'] );
        }
    }

    /** ALL and ANY are what the goal event says, not what the compiler prefers. */
    public function testConditionsCombineTheWayTheGoalEventSays(): void
    {
        list( $all ) = $this->compile( array(
            array( 'page_path',   GoalEvent::MATCH_EXACT, '/a' ),
            array( 'page_title', GoalEvent::MATCH_EXACT, 'A' ) ), GoalEvent::MATCH_ALL );

        $this->assertStringContainsString( ' AND ', $all['sql'] );
        $this->assertStringNotContainsString( ' OR ', $all['sql'] );

        list( $any ) = $this->compile( array(
            array( 'page_path',   GoalEvent::MATCH_EXACT, '/a' ),
            array( 'page_title', GoalEvent::MATCH_EXACT, 'A' ) ), GoalEvent::MATCH_ANY );

        $this->assertStringContainsString( ' OR ', $any['sql'] );
        $this->assertStringNotContainsString( ' AND ', $any['sql'] );
    }

    /**
     * NO conditions matches NOTHING.
     *
     * matchesRow() answers the same for the same reason: an empty rule is
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
            array( 'page_path', 'sideways', '/a' ) ) );

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

            list( $out ) = $this->compile( array( array( 'page_path', $operator, '' ) ) );

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
            array( 'page_path', GoalEvent::MATCH_CONTAINS, '50%_off' ) ) );

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

    /**
     * Every column the vocabulary offers compiles.
     *
     * The builder, the save validation, marking and this all read
     * GoalVocabulary, so a column any of them accepts has to be one a funnel step
     * can be written on -- otherwise a goal saved through the form refuses to
     * draw, and the author is told to change something the form offered them.
     */
    public function testEveryColumnTheVocabularyOffersCompiles(): void
    {
        $columns = \OWA\Module\Base\Classes\GoalVocabulary::columns();

        $this->assertGreaterThan( 20, count( $columns ),
            'the vocabulary came back nearly empty, so this would prove nothing' );

        foreach ( $columns as $column ) {

            list( $out ) = $this->compile( array(
                array( $column, GoalEvent::MATCH_EXACT, 'x' ) ) );

            $this->assertNotNull( $out, "$column is offered but does not compile." );
        }
    }

    /**
     * The compiler works with NO DATABASE CONNECTION.
     *
     * OWA_SQL_CONTAINS and friends are defined at file scope in the driver's
     * dialect, so they exist once a driver has been autoloaded and not before.
     * Every path that reaches this in a running installation has a connection,
     * which is why the gap only showed up when this file was run on its own --
     * CI's isolation sweep runs every file that way, and its unit job has no
     * database at all.
     *
     * Asserted rather than left to the other tests: they would all fail
     * together and the reason would be a fatal about an undefined constant, in
     * whichever test happened to run first.
     */
    public function testItCompilesWithoutAConnection(): void
    {
        list( $out ) = $this->compile( array(
            array( 'page_path', GoalEvent::MATCH_CONTAINS, '/checkout' ) ) );

        $this->assertNotNull( $out );
        $this->assertNotSame( '', $out['sql'] );
        $this->assertSame( array( '/checkout' ), $out['params'] );
    }
}
