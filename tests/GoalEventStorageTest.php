<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Goals are rows now, and the row is shaped for v2 goal events.
 *
 * Twenty numbered slots lived inside ONE serialized array -- all twenty present
 * whether used or not -- in a single settings row per Profile. A live install
 * here held fifteen entries in 2,135 bytes to describe one real goal. That
 * shape cannot be queried or indexed, loses one of two concurrent edits
 * wholesale, and put a RECORD inside a settings blob.
 *
 * The columns are what v2 needs, not what 1.x had, so the v2 migration reads
 * this table rather than reinterpreting it: an author names a goal event, gives
 * it an event type and a condition, and the server materialises a row whose
 * event_type IS that name (PLAN.html §7.14).
 */
final class GoalEventStorageTest extends TestCase
{
    private array $created = [];

    /** A real Profile with a Property -- goal events hang off the Property. */
    private string $siteId = '';

    /** Its Property. Resolved once, and asserted, for the reason below. */
    private string $propertyId = '';

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        /*
         * A REAL Profile, not an invented id.
         *
         * Goal events belong to the Property, so a made-up site id resolves to
         * no Property and the manager correctly answers with nothing -- these
         * tests would then pass by measuring an empty list.
         */
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'site_id, property_id' );

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( ! empty( $row['property_id'] ) ) {

                $this->siteId     = $row['site_id'];
                $this->propertyId = $row['property_id'];
                break;
            }
        }

        if ( ! $this->siteId ) {
            $this->markTestSkipped( 'Needs a Profile with a Property.' );
        }

        /*
         * Held and asserted rather than re-resolved per query.
         *
         * Db::where() DROPS a clause whose value is empty instead of matching
         * nothing, so a query filtered on an empty property id silently widens
         * to every Property on the install. A test that does that does not fail
         * -- it counts other people's rows and reports a number that looks like
         * a bug in the code under test.
         */
        $this->assertNotSame( '', $this->propertyId );
    }

    protected function tearDown(): void
    {
        foreach ( $this->created as $id ) {

            $e = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
            $e->delete( $id );
        }

        foreach ( $this->createdConditions as $id ) {

            $e = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );
            $e->delete( $id );
        }

        $this->createdConditions = [];

        $this->created = [];
    }

    /* ---------------- conditions ---------------- */

    /**
     * The comparisons, including the ones 1.x could not express.
     *
     * 1.x offered exact, begins and regex against one URL. There was no way to
     * say "not this page", "contains", or anything numeric -- so a goal event
     * describing "a purchase over 50" could not be written at all.
     */
    public function testTheComparisons(): void
    {
        $ge = '\OWA\Module\Base\Entity\GoalEvent';

        $this->assertTrue(  $ge::compare( '/thanks', 'exact', '/thanks' ) );
        $this->assertFalse( $ge::compare( '/thanks', 'exact', '/other' ) );

        $this->assertTrue(  $ge::compare( '/thanks', 'not', '/other' ) );
        $this->assertFalse( $ge::compare( '/thanks', 'not', '/thanks' ) );

        $this->assertTrue(  $ge::compare( '/a/thanks', 'contains', 'thanks' ) );
        $this->assertTrue(  $ge::compare( '/thanks', 'begins', '/thanks' ) );
        $this->assertFalse( $ge::compare( '/a/thanks', 'begins', 'thanks' ) );

        $this->assertTrue(  $ge::compare( '/thanks/2', 'regex', 'thanks' ) );

        $this->assertTrue(  $ge::compare( '60', 'gt', '50' ) );
        $this->assertFalse( $ge::compare( '40', 'gt', '50' ) );
        $this->assertTrue(  $ge::compare( '40', 'lt', '50' ) );
    }

    /**
     * A match at position zero is a match.
     *
     * strpos() answers 0 there, which is falsy -- so a truthy test reads
     * "/thanks contains /" as no match. That exact trap has been found in this
     * codebase before.
     */
    public function testContainsMatchesAtPositionZero(): void
    {
        $this->assertTrue(
            \OWA\Module\Base\Entity\GoalEvent::compare( '/thanks', 'contains', '/' ) );
    }

    /**
     * Greater/less than are NUMERIC, and answer no when either side is not.
     *
     * PHP would happily compare two strings and return something, but "greater
     * than" on a page URL is not a question anyone asked, and a silent
     * lexicographic answer is worse than no match.
     */
    public function testNumericComparisonsRefuseNonNumbers(): void
    {
        $ge = '\OWA\Module\Base\Entity\GoalEvent';

        $this->assertFalse( $ge::compare( '/pricing', 'gt', '50' ) );
        $this->assertFalse( $ge::compare( '60', 'gt', 'fifty' ) );
    }

    /**
     * A malformed pattern does not match, and is suppressed at compare time.
     *
     * Suppressed there because the alternative is a warning per tracked event.
     * It is REFUSED at save time instead -- see GoalEventSave -- so nobody
     * stores a pattern that can never match.
     *
     * The handler respects error_reporting(), which @ sets to 0: a handler that
     * throws regardless would report the suppression working as a failure.
     */
    public function testABrokenRegexDoesNotMatchAndIsSuppressed(): void
    {
        $seen = false;

        set_error_handler(
            static function ( $no, $str ) use ( &$seen ) {

                if ( ! ( error_reporting() & $no ) ) {

                    return true;   // suppressed at the call site
                }

                $seen = true;

                return true;
            } );

        try {
            $this->assertFalse(
                \OWA\Module\Base\Entity\GoalEvent::compare( '/thanks', 'regex', '(' ) );

            $this->assertFalse( $seen,
                'A broken pattern warns on every tracked event.' );

        } finally {
            restore_error_handler();
        }
    }

    /** And the form refuses it, rather than storing something inert. */
    public function testABrokenRegexIsRefusedOnSave(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/GoalEventSave.php' );

        $this->assertStringContainsString( 'The regular expression is not valid.', $src,
            'A pattern that cannot compile can be saved, and then matches nothing '
            . 'for ever without saying so.' );
    }

    /** Several conditions, combined with all or any. */
    public function testConditionsCombineWithAllOrAny(): void
    {
        $goalEvent = $this->makeGoalEventWithConditions( array(
            array( 'page_path', 'begins', '/checkout' ),
            array( 'revenue',   'gt',     '50' ),
        ) );

        $over  = $this->row( array( 'page_path' => '/checkout/done', 'revenue' => '60' ) );
        $under = $this->row( array( 'page_path' => '/checkout/done', 'revenue' => '40' ) );

        $this->assertTrue(  $goalEvent->matchesRow( $over ) );
        $this->assertFalse( $goalEvent->matchesRow( $under ),
            'Under ALL, one failing condition must fail the whole rule.' );

        $goalEvent->set( 'condition_match', 'any' );

        $this->assertTrue( $goalEvent->matchesRow( $under ),
            'Under ANY, one matching condition is enough.' );
    }

    /**
     * A goal event with NO conditions matches nothing.
     *
     * An empty rule is vacuously true, and treating it that way would count
     * every event on the site. This install already had a goal that could never
     * fire; one that fires for everything is the worse direction to be wrong in.
     */
    public function testAGoalEventWithNoConditionsMatchesNothing(): void
    {
        $goalEvent = $this->makeGoalEventWithConditions( array() );

        $this->assertFalse( $goalEvent->matchesRow(
            $this->row( array( 'page_path' => '/anything' ) ) ) );
    }

    /**
     * THE TRIGGER GATES THE MATCH.
     *
     * trigger_event_type has been stored since Update025 and was read by
     * NOTHING, so a goal declared on a page view was evaluated against every
     * event on the site. It went unnoticed because a condition on a page column
     * finds that column NULL on a click -- but a condition on host, or on
     * device_type, would have fired on scrolls, clicks and session_start alike,
     * counting several conversions for one visit to one page.
     */
    public function testAGoalOnlyMatchesItsTriggerEventType(): void
    {
        $goalEvent = $this->makeGoalEventWithConditions( array(
            array( 'host', 'exact', 'example.com' ),
        ) );

        $goalEvent->set( 'trigger_event_type', 'page_view' );

        $this->assertTrue( $goalEvent->matchesRow( $this->row( array(
            'event_type' => 'page_view', 'host' => 'example.com' ) ) ) );

        $this->assertFalse( $goalEvent->matchesRow( $this->row( array(
            'event_type' => 'click', 'host' => 'example.com' ) ) ),
            'A goal triggered on a page view counted a click as a conversion.' );
    }

    /**
     * AN EMPTY TRIGGER MATCHES NOTHING.
     *
     * It used to mean every event type. No writer produces one -- Update025
     * gives every migrated goal event a trigger and GoalEventSave defaults one --
     * and "every event" would mark a page view and the session_start and
     * first_visit materialized beside it: three conversions for one visit.
     */
    public function testAnEmptyTriggerMatchesNothing(): void
    {
        $conditions = array( array( 'host', 'exact', 'example.com' ) );

        $triggered = $this->makeGoalEventWithConditions( $conditions );

        $this->assertTrue( $triggered->matchesRow( $this->row( array(
            'event_type' => 'page_view', 'host' => 'example.com' ) ) ),
            'with a trigger the same conditions match, so the refusals below are the empty trigger' );

        // Never set, since an entity ignores '' on a string column.
        $untriggered = $this->makeGoalEventWithConditions( $conditions, null );

        $this->assertSame( '', (string) $untriggered->get( 'trigger_event_type' ) );

        foreach ( array( 'page_view', 'click', 'session_start' ) as $type ) {

            $this->assertFalse( $untriggered->matchesRow( $this->row( array(
                'event_type' => $type, 'host' => 'example.com' ) ) ),
                "An empty trigger matched $type." );
        }
    }

    /**
     * A COLUMN THE ROW DOES NOT CARRY CANNOT ANSWER, so it does not match --
     * whatever the operator.
     *
     * The operator is the point. Handing NULL to compare() makes `not` TRUE for
     * every row that simply has no value there: a condition meant to exclude one
     * medium would mark every event with no medium at all, which is most of
     * them. Both spellings of absence are checked because the row has both -- a
     * key that is not there at all, and a column that is there and NULL, which
     * is what tagged_medium is on every event but the landing one.
     */
    public function testAnAbsentOrNullColumnDoesNotMatchEvenUnderNot(): void
    {
        $goalEvent = $this->makeGoalEventWithConditions( array(
            array( 'tagged_medium', 'not', 'organic' ),
        ) );

        $this->assertFalse( $goalEvent->matchesRow( $this->row( array(
            'event_type' => 'page_view' ) ) ),
            'A row with no tagged_medium key matched "medium is not organic".' );

        $this->assertFalse( $goalEvent->matchesRow( $this->row( array(
            'event_type' => 'page_view', 'tagged_medium' => null ) ) ),
            'A row whose tagged_medium is NULL matched "medium is not organic".' );

        $this->assertTrue( $goalEvent->matchesRow( $this->row( array(
            'event_type' => 'page_view', 'tagged_medium' => 'cpc' ) ) ),
            'and a row that does carry a different medium must still match' );
    }

    /**
     * THE ROW-ONLY COLUMNS ARE MATCHABLE, which is the reason marking moved.
     *
     * device_type is derived in the handler from the user-agent parse and the
     * tagged_* columns are transcribed there; neither exists as an event
     * property. Matching from the event could not see them at all, so "a signup
     * from mobile" was not expressible -- and a goal declared on it matched
     * nothing without saying so.
     */
    public function testAConditionCanTestAColumnThatOnlyExistsOnTheRow(): void
    {
        $goalEvent = $this->makeGoalEventWithConditions( array(
            array( 'device_type',   'exact', 'mobile' ),
            array( 'tagged_medium', 'exact', 'organic' ),
        ) );

        $this->assertTrue( $goalEvent->matchesRow( $this->row( array(
            'device_type' => 'mobile', 'tagged_medium' => 'organic' ) ) ) );

        $this->assertFalse( $goalEvent->matchesRow( $this->row( array(
            'device_type' => 'desktop', 'tagged_medium' => 'organic' ) ) ) );
    }

    /** A raw row, with the keys a condition is allowed to name. */
    private function row( array $columns )
    {
        return array_merge( array(
            'event_type' => 'page_view',
            'site_id'    => $this->siteId,
        ), $columns );
    }

    /** A goal event carrying the given conditions, cleaned up afterwards. */
    private function makeGoalEventWithConditions( array $conditions, ?string $trigger = 'page_view' )
    {
        $id = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' )
            ->generateId( 'goal_event:cond-probe:' . uniqid( '', true ) );

        $this->created[] = $id;

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $goalEvent->set( 'id', $id );
        $goalEvent->set( 'property_id', $this->propertyId );
        $goalEvent->set( 'name', 'Condition probe' );
        if ( $trigger !== null ) {
            $goalEvent->set( 'trigger_event_type', $trigger );
        }

        $goalEvent->set( 'is_active', 1 );
        $goalEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $goalEvent->create();

        $n = 0;

        foreach ( $conditions as $c ) {

            $n++;

            $row = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );
            $row->set( 'id', $row->generateId( 'goal_event_condition:' . $id . ':' . $n ) );
            $row->set( 'goal_event_id', $id );
            $row->set( 'sort_order', $n );
            $row->set( 'condition_property', $c[0] );
            $row->set( 'condition_operator', $c[1] );
            $row->set( 'condition_value', $c[2] );
            $row->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $row->create();

            $this->createdConditions[] = $row->get( 'id' );
        }

        return $goalEvent;
    }

    /** @var array ids to clean up */
    private array $createdConditions = [];

    /* ---------------- deleting ---------------- */

    /**
     * DELETING A GOAL EVENT DELETES ITS CONDITIONS.
     *
     * It did not. Entity::delete() removes one row from one table and nothing
     * overrode it, so every goal event ever deleted left its conditions behind
     * with nothing able to reach them: a condition row carries no site_id and no
     * property_id, and is reachable only through its goal event.
     *
     * The count on the test install when it was found: 31 of 40 condition rows
     * pointed at a goal event that no longer existed, left by e2e fixtures that
     * create a goal and delete it. The reason nobody noticed is that an
     * unreachable row is also an invisible one -- until one of those 64-bit ids
     * collides with a new goal event's and a condition somebody deleted starts
     * deciding what converts.
     */
    public function testDeletingAGoalEventDeletesItsConditions(): void
    {
        $goalEvent = $this->makeGoalEventWithConditions( array(
            array( 'page_uri', 'begins', '/thanks' ),
            array( 'medium', 'exact', 'organic-search' ),
        ) );

        $id = $goalEvent->get( 'id' );

        $this->assertCount( 2, $this->conditionRowsFor( $id ),
            'the fixture did not store its conditions, so this would pass on nothing' );

        $goalEvent->delete( $id );

        $this->assertSame( array(), $this->conditionRowsFor( $id ),
            'The goal event is gone and its conditions are still there -- unreachable, '
            . 'because nothing but the goal event points at them.' );
    }

    /**
     * DELETE BY A COLUMN OTHER THAN id CASCADES AS WELL.
     *
     * Entity::delete( $value, $col ) accepts any column, so a caller can remove
     * every goal event belonging to a Property in one call. A cascade that only
     * understood the id would leak on exactly the delete that removes the most
     * rows.
     */
    public function testDeletingByPropertyIdCascades(): void
    {
        $goalEvent = $this->makeGoalEventWithConditions( array(
            array( 'page_uri', 'exact', '/one' ),
        ) );

        $id = $goalEvent->get( 'id' );

        $this->assertCount( 1, $this->conditionRowsFor( $id ) );

        /*
         * The Property really does own it -- makeGoalEventWithConditions sets
         * property_id -- so this is the live shape and not an invented column.
         */
        $goalEvent->delete( $this->propertyId, 'property_id' );

        $this->assertSame( array(), $this->conditionRowsFor( $id ),
            'Deleting by property_id left the conditions behind.' );
    }

    /**
     * Update048 deletes the orphans that accumulated before the cascade existed,
     * and ONLY those.
     *
     * The second half is the part worth asserting: a cleanup that also removed a
     * live goal event's conditions would silently stop that goal converting, and
     * the install would look tidy.
     */
    public function testTheCleanupTakesOrphansAndLeavesLiveConditions(): void
    {
        $live = $this->makeGoalEventWithConditions( array(
            array( 'page_uri', 'exact', '/live' ),
        ) );

        $liveId = $live->get( 'id' );

        // An orphan: a condition whose goal_event_id names nothing.
        $orphan = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );
        $orphanId = $orphan->generateId( 'goal_event_condition:orphan-probe:' . uniqid( '', true ) );
        $orphan->set( 'id', $orphanId );
        $orphan->set( 'goal_event_id', '9' . substr( (string) $orphanId, 1 ) );
        $orphan->set( 'sort_order', 1 );
        $orphan->set( 'condition_property', 'page_uri' );
        $orphan->set( 'condition_operator', 'exact' );
        $orphan->set( 'condition_value', '/orphan' );
        $orphan->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $orphan->create();

        $this->createdConditions[] = $orphanId;

        $update = new \OWA\Module\Base\Update\Update048;

        $this->assertTrue( $update->up(), 'the cleanup reported failure' );

        $this->assertCount( 1, $this->conditionRowsFor( $liveId ),
            'The cleanup deleted a live goal event\'s condition, which stops it converting.' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory(
            'base.goal_event_condition' )->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'id', $orphanId );

        $this->assertSame( array(), (array) $db->getAllRows(),
            'The orphan survived the cleanup.' );

        // Idempotent: a second run finds nothing left to do.
        $this->assertTrue( $update->up(), 'a second run of the cleanup failed' );

        $this->assertCount( 1, $this->conditionRowsFor( $liveId ),
            'The second run deleted the live condition.' );
    }

    /** The condition rows pointing at one goal event, by query, not by cache. */
    private function conditionRowsFor( $goalEventId )
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory(
            'base.goal_event_condition' )->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'goal_event_id', $goalEventId );

        return (array) $db->getAllRows();
    }

    /* ---------------- funnels ---------------- */
    /* ---------------- money ---------------- */

    /**
     * Cents, matching v2's revenue column, so the value is not converted twice.
     *
     * round() before the cast: (int) truncates, so 0.29 through float
     * representation becomes 28 -- the classic money-in-floats error, and one
     * that under-reports every time rather than averaging out.
     */
    public function testDecimalValuesBecomeWholeCents(): void
    {
        $ke = '\OWA\Module\Base\Entity\GoalEvent';

        $this->assertSame( 200, $ke::decimalToCents( '2' ) );
        $this->assertSame( 250, $ke::decimalToCents( '2.50' ) );
        $this->assertSame( 29,  $ke::decimalToCents( '0.29' ),
            'A value truncated instead of rounded, which under-reports every time.' );
        $this->assertSame( 0,   $ke::decimalToCents( '' ) );
    }

    /** A value nobody can convert is reported, not silently zeroed. */
    public function testANonNumericValueIsDistinguishableFromZero(): void
    {
        $ke = '\OWA\Module\Base\Entity\GoalEvent';

        $this->assertNull( $ke::decimalToCents( 'about five pounds' ),
            'A non-numeric value is indistinguishable from a real zero, so the migration '
            . 'cannot report that someone typed something it could not keep.' );

        $this->assertSame( 0, $ke::decimalToCents( '0' ) );
    }

    /** And back, because 1.x reporting reads a decimal string. */
    public function testCentsRoundTripBackToTheDecimalFormReportingExpects(): void
    {
        $ke = '\OWA\Module\Base\Entity\GoalEvent';

        $this->assertSame( '2.00', $ke::centsToDecimal( 200 ) );
        $this->assertSame( '0.29', $ke::centsToDecimal( 29 ) );
    }

    /* ---------------- counting ---------------- */

    /**
     * Counting is once per session, whatever was asked for.
     *
     * A conversion is a COLUMN on the session row -- goal_N -- so a second
     * match in the same session has nowhere to go. Once per event is a setting
     * the storage can hold and the handler cannot honour, which makes it the
     * quietest kind of wrong: the row says one thing, the number means another,
     * and nothing looks broken.
     *
     * So the form offers one choice and the save clamps to it. Asserted on the
     * SAVE rather than the form, because the form is not the only way in.
     */
    public function testTheCountModeIsClampedToOncePerSession(): void
    {
        $body = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/GoalEventSave.php' );

        $write = substr( $body, (int) strpos( $body, "set( 'count_mode'" ) );
        $write = substr( $write, 0, (int) strpos( $write, ';' ) );

        $this->assertStringContainsString( 'COUNT_PER_SESSION', $write,
            'The save does not write once-per-session.' );

        $this->assertStringNotContainsString( 'COUNT_PER_EVENT', $write,
            'The save still writes once-per-event on request, so a hand-made post can store a '
            . 'counting rule the handler cannot honour.' );

        $this->assertStringNotContainsString( "getParam( 'countMode' )", $write,
            'The save reads countMode from the request, so what is stored is not what is '
            . 'counted.' );
    }

    /**
     * And the form offers exactly the one choice.
     *
     * The field stays rather than disappearing: what a goal event counts is
     * part of reading it, and a screen silent about it reads as though the
     * question does not exist.
     */
    public function testTheFormOffersOnlyOncePerSession(): void
    {
        $body = (string) file_get_contents(
            OWA_DIR . 'modules/Base/templates/goal_event_edit.php' );

        $this->assertStringContainsString( 'countMode', $body,
            'The form does not ask about counting at all, so a goal event does not say how '
            . 'often it counts.' );

        $this->assertSame( 1, substr_count( $body, 'value="once_per_session"' ) );

        $this->assertStringNotContainsString( 'once_per_event', $body,
            'The form still offers once per event, which the handler cannot honour.' );
    }
}
