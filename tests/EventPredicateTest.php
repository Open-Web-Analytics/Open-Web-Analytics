<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Entity\GoalEventDefinition;
use OWA\Module\Base\Classes\EventPredicate;

/*
 * Booted, not connected: GoalVocabulary answers from the raw entity's columns,
 * which needs the framework, and nothing here needs a database except the one
 * test that reads custom dimension registrations, which skips without one.
 */
require_once __DIR__ . '/bootstrap_owa.php';

/**
 * An event and its conditions compiled into a predicate over the cube: what a
 * funnel step is counted with (Classes\EventPredicate).
 *
 * The comparison has to agree with GoalEventDefinition::compare(), which
 * decides the same condition at ingest. GoalEventDefinitionPredicateTest holds
 * a goal event definition's own rules on top of this.
 */
final class EventPredicateTest extends TestCase
{
    private const ALIAS = \OWA\Module\Base\Controller\VisualizationFunnel::ALIAS;

    /** @param array $conditions list of [property, operator, value] */
    private function compile( string $event, array $conditions, string $match = 'all',
                              ?string $defaultPage = null, array $custom = array() ): array
    {
        $p = new EventPredicate( $defaultPage, $custom );

        $rows = array_map( static function ( $c ) {
            return array( 'property' => $c[0], 'operator' => $c[1], 'value' => $c[2] );
        }, $conditions );

        return array( $p->compile( $event, $match, $rows, self::ALIAS ), $p );
    }

    /** No conditions matches every event of that name. */
    public function testAnEventWithNoConditionsMatchesEveryOneOfThatName(): void
    {
        list( $out ) = $this->compile( 'click', array() );

        $this->assertSame( '( e.event_type = ? )', $out['sql'] );
        $this->assertSame( array( 'click' ), $out['params'] );
    }

    /** A site's own event, by its name, with conditions or without. */
    public function testACustomEventIsMatchedByItsName(): void
    {
        list( $none ) = $this->compile( 'newsletter_signup', array() );

        $this->assertSame( array( 'newsletter_signup' ), $none['params'] );

        list( $some ) = $this->compile( 'newsletter_signup', array(
            array( 'page_path', GoalEventDefinition::MATCH_BEGINS, '/blog/' ) ) );

        $this->assertStringStartsWith( '( e.event_type = ? AND', $some['sql'] );
        $this->assertSame( array( 'newsletter_signup', '/blog/' ), $some['params'] );
    }

    /** No event matches nothing. */
    public function testNoEventMatchesNothing(): void
    {
        list( $out ) = $this->compile( '', array( array( 'page_path', GoalEventDefinition::MATCH_EXACT, '/a' ) ) );

        $this->assertSame( '( 0 = 1 )', $out['sql'] );
        $this->assertSame( array(), $out['params'] );
    }

    /**
     * Each operator compiles to its comparison, the value BOUND and never
     * inlined, and agrees with compare() on a value that matches and one that
     * does not.
     */
    public function testEachOperator(): void
    {
        $E = GoalEventDefinition::class;

        $cases = array(
            // operator => [ value, SQL fragment, a matching row value, a non-matching one ]
            $E::MATCH_EXACT    => array( '/thanks', 'e.page_path = ?', '/thanks', '/other' ),
            $E::MATCH_NOT      => array( '/thanks', 'e.page_path <> ?', '/other', '/thanks' ),
            $E::MATCH_CONTAINS => array( 'than', 'LOCATE', '/thanks', '/other' ),
            $E::MATCH_BEGINS   => array( '/th', '/th', '/thanks', '/other' ),
            $E::MATCH_REGEX    => array( '^/th', 'REGEXP ?', '/thanks', '/other' ),
            $E::MATCH_GT       => array( '5', ') > ?', '6', '4' ),
            $E::MATCH_LT       => array( '5', ') < ?', '4', '6' ),
        );

        $this->assertEqualsCanonicalizing( array_keys( GoalEventDefinition::operators() ), array_keys( $cases ),
            'an operator the form offers has no case here' );

        foreach ( $cases as $operator => list( $value, $fragment, $yes, $no ) ) {

            list( $out ) = $this->compile( 'page_view', array( array( 'page_path', $operator, $value ) ) );

            $this->assertNotNull( $out, $operator );
            $this->assertSame( array( 'page_view', $value ), $out['params'], $operator );

            if ( $operator !== $E::MATCH_BEGINS ) {
                $this->assertStringContainsString( $fragment, $out['sql'], $operator );
                $this->assertStringNotContainsString( $value, str_replace( $fragment, '', $out['sql'] ), $operator );
            }

            $this->assertStringContainsString( 'e.page_path IS NOT NULL AND', $out['sql'], $operator );

            $this->assertTrue( GoalEventDefinition::compare( $yes, $operator, $value ), $operator );
            $this->assertFalse( GoalEventDefinition::compare( $no, $operator, $value ), $operator );
        }

        list( $unknown ) = $this->compile( 'page_view', array( array( 'page_path', 'sideways', '/a' ) ) );

        $this->assertStringContainsString( '0 = 1', $unknown['sql'], 'an unknown operator matches nothing' );
    }

    /** A greater/less-than on a value that is not a number matches nothing, as compare() answers. */
    public function testANumericComparisonOnTextMatchesNothing(): void
    {
        list( $out ) = $this->compile( 'purchase', array( array( 'revenue', GoalEventDefinition::MATCH_GT, 'lots' ) ) );

        $this->assertStringContainsString( '0 = 1', $out['sql'] );
    }

    /** All and any are the step's combinator. */
    public function testAllAndAny(): void
    {
        $two = array(
            array( 'page_path', GoalEventDefinition::MATCH_EXACT, '/a' ),
            array( 'page_title', GoalEventDefinition::MATCH_EXACT, 'A' ),
        );

        list( $all ) = $this->compile( 'page_view', $two, 'all' );
        list( $any ) = $this->compile( 'page_view', $two, 'any' );
        list( $odd ) = $this->compile( 'page_view', $two, 'sideways' );

        $this->assertStringContainsString( ') AND ( e.page_title', $all['sql'] );
        $this->assertStringNotContainsString( ' OR ', $all['sql'] );
        $this->assertStringContainsString( ') OR ( e.page_title', $any['sql'] );
        $this->assertSame( $all['sql'], $odd['sql'], 'anything but any reads as all' );
        $this->assertSame( array( 'page_view', '/a', 'A' ), $any['params'] );
    }

    /**
     * A name that is not a cube column the vocabulary knows is REFUSED BY NAME:
     * dropping it would widen the step. `params` is the JSON document, and an
     * ad-hoc key in it is not a column at all.
     */
    public function testAnUnknownPropertyIsRefusedByName(): void
    {
        foreach ( array( 'page_type', 'params', 'plan', 'page_path; DROP TABLE x', 'cd_plan' ) as $property ) {

            list( $out, $p ) = $this->compile( 'page_view', array(
                array( 'page_path', GoalEventDefinition::MATCH_EXACT, '/a' ),
                array( $property, GoalEventDefinition::MATCH_EXACT, 'x' ) ) );

            $this->assertNull( $out, $property );
            $this->assertSame( $property, $p->getError(), $property );
        }
    }

    /**
     * A custom dimension of the funnel's Property is a cube column a step can
     * test, by its column. Only the ones handed in: another Property's, or one
     * not applied, is not among them (testCustomColumnsAreTheAppliedOnesOfTheProperty).
     */
    public function testAnAppliedCustomDimensionOfThePropertyCompiles(): void
    {
        $custom = array( 'cd_plan' => 'Plan' );

        list( $out ) = $this->compile( 'purchase', array( array( 'cd_plan', GoalEventDefinition::MATCH_EXACT, 'gold' ) ),
            'all', null, $custom );

        $this->assertNotNull( $out );
        $this->assertStringContainsString( 'e.cd_plan IS NOT NULL AND e.cd_plan = ?', $out['sql'] );
        $this->assertSame( array( 'purchase', 'gold' ), $out['params'] );

        list( $other, $p ) = $this->compile( 'purchase', array( array( 'cd_tier', GoalEventDefinition::MATCH_EXACT, 'x' ) ),
            'all', null, $custom );

        $this->assertNull( $other );
        $this->assertSame( 'cd_tier', $p->getError() );
    }

    /**
     * PAGE PATHS ARE CANONICALISED when a default page is given, the way the
     * row's was, for exact and not-exact: `/store/` and `/store/index.php` both
     * equal the stored `/store`. The root stays '/'.
     */
    public function testAPagePathIsCanonicalisedWithTheDefaultPage(): void
    {
        $E = GoalEventDefinition::class;

        foreach ( array( '/store/' => '/store', '/store/index.php' => '/store', '/' => '/', '/store' => '/store' )
                  as $typed => $stored ) {

            foreach ( array( $E::MATCH_EXACT, $E::MATCH_NOT ) as $operator ) {

                list( $out ) = $this->compile( 'page_view', array( array( 'page_path', $operator, $typed ) ),
                    'all', 'index.php' );

                $this->assertSame( array( 'page_view', $stored ), $out['params'], "$operator $typed" );
            }
        }

        // Other operators match part of the value, and keep it as typed.
        list( $begins ) = $this->compile( 'page_view', array( array( 'page_path', $E::MATCH_BEGINS, '/store/' ) ),
            'all', 'index.php' );
        $this->assertSame( array( 'page_view', '/store/' ), $begins['params'] );

        // Only page_path is a path.
        list( $title ) = $this->compile( 'page_view', array( array( 'page_title', $E::MATCH_EXACT, 'Store/' ) ),
            'all', 'index.php' );
        $this->assertSame( array( 'page_view', 'Store/' ), $title['params'] );

        // With no default page given -- a goal event definition's predicate -- the value is as typed.
        list( $raw ) = $this->compile( 'page_view', array( array( 'page_path', $E::MATCH_EXACT, '/store/' ) ) );
        $this->assertSame( array( 'page_view', '/store/' ), $raw['params'] );

        // An empty default page still collapses the trailing slash, as the row's canonicalPath() does.
        list( $empty ) = $this->compile( 'page_view', array( array( 'page_path', $E::MATCH_EXACT, '/store/' ) ), 'all', '' );
        $this->assertSame( array( 'page_view', '/store' ), $empty['params'] );
    }

    /** It works with no database connection: the SQL vocabulary loads with the driver class. */
    public function testItCompilesWithoutAConnection(): void
    {
        list( $out ) = $this->compile( 'page_view', array( array( 'page_path', GoalEventDefinition::MATCH_CONTAINS, '/checkout' ) ) );

        $this->assertNotNull( $out );
        $this->assertSame( array( 'page_view', '/checkout' ), $out['params'] );
    }

    /**
     * The custom dimensions a step may test are the APPLIED registrations of
     * the funnel's own Property: not another Property's, and not a pending one.
     */
    public function testCustomColumnsAreTheAppliedOnesOfTheProperty(): void
    {
        if ( ! function_exists( 'owa_test_db_available' ) || ! owa_test_db_available() ) {
            $this->markTestSkipped( 'reads custom dimension registrations' );
        }

        $mine   = '7779000000000001';
        $theirs = '7779000000000002';
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $table  = \OWA\Core\CoreAPI::entityFactory( 'base.custom_dimension' )->getTableName();

        $insert = function ( $id, $property, $key, $state ) use ( $db, $table ) {
            $db->query( sprintf( 'INSERT INTO %s (id, property_id, scope, dimension_key, column_name, data_type, max_length,'
                . ' label, creation_date, state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $table ),
                array( $id, $property, 'event', $key, 'cd_' . $key, 'string', 64, ucfirst( $key ), time(), $state ) );
        };

        $clean = function () use ( $db, $table, $mine, $theirs ) {
            $db->query( sprintf( 'DELETE FROM %s WHERE property_id IN (?, ?)', $table ), array( $mine, $theirs ) );
        };

        $clean();

        try {
            $insert( '7779000000000101', $mine, 'plan', \OWA\Module\Base\Entity\CustomDimension::STATE_APPLIED );
            $insert( '7779000000000102', $mine, 'tier', \OWA\Module\Base\Entity\CustomDimension::STATE_PENDING );
            $insert( '7779000000000103', $theirs, 'seat', \OWA\Module\Base\Entity\CustomDimension::STATE_APPLIED );
            // Written under the registry, so it reads them afresh.
            \OWA\Module\Base\Classes\DimensionRegistry::forget();

            $this->assertSame( array( 'cd_plan' => 'Plan' ), EventPredicate::customColumnsFor( $mine ) );
            $this->assertSame( array( 'cd_seat' => 'Seat' ), EventPredicate::customColumnsFor( $theirs ) );
            $this->assertSame( array(), EventPredicate::customColumnsFor( '' ) );

            $p = new EventPredicate( null, EventPredicate::customColumnsFor( $mine ) );

            $this->assertTrue( $p->allows( 'cd_plan' ) );
            $this->assertFalse( $p->allows( 'cd_tier' ), 'a pending registration' );
            $this->assertFalse( $p->allows( 'cd_seat' ), "another Property's" );

        } finally {
            $clean();
            \OWA\Module\Base\Classes\DimensionRegistry::forget();
        }
    }
}
