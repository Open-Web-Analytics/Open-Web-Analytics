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

    /* ---------------- the migration ---------------- */

    /**
     * Nineteen of twenty slots on a typical install are blank stubs -- they
     * exist because the blob was a fixed-length array, not because anyone made
     * them. Carrying them over would reproduce the thing this table replaces.
     */
    public function testEmptySlotsAreDroppedNotMigratedAsBlankRows(): void
    {
        $blob = array();

        for ( $i = 1; $i <= 20; $i++ ) {
            $blob[ $i ] = array( 'goal_number' => '', 'goal_name' => '',
                                 'goal_group' => '', 'goal_status' => '', 'goal_type' => '' );
        }

        $blob[3] = array(
            'goal_name'   => 'Signup',
            'goal_number' => '3',
            'goal_group'  => '1',
            'goal_status' => 'active',
            'goal_value'  => '2',
            'goal_type'   => 'url_destination',
            'details'     => array( 'match_type' => 'begins', 'goal_url' => '/thanks' ),
        );

        $planned = \OWA\Module\Base\Update\Update025::planForProfile( array(
            'scope_id' => 'OWA-probe', 'value' => serialize( $blob ) ) );

        $this->assertCount( 1, $planned,
            'Blank slots were migrated as rows, which rebuilds the twenty-slot model in '
            . 'a table.' );

        $this->assertSame( 'Signup', $planned[0]['name'] );
        $this->assertSame( 3, $planned[0]['goal_number'] );
        $this->assertSame( 1, $planned[0]['is_active'] );
    }

    /** The condition becomes the property/operator/value triple v2 needs. */
    public function testTheConditionIsStoredAsATriple(): void
    {
        $planned = \OWA\Module\Base\Update\Update025::planForProfile( array(
            'scope_id' => 'OWA-probe',
            'value'    => serialize( array( 1 => array(
                'goal_name'   => 'Thanks',
                'goal_number' => '1',
                'goal_status' => 'active',
                'goal_type'   => 'url_destination',
                'details'     => array( 'match_type' => 'exact', 'goal_url' => '/thanks' ),
            ) ) ),
        ) );

        $this->assertSame( 'page_uri', $planned[0]['condition_property'] );
        $this->assertSame( 'exact', $planned[0]['condition_operator'] );
        $this->assertSame( '/thanks', $planned[0]['condition_value'] );

        $this->assertNotEmpty( $planned[0]['trigger_event_type'],
            'Without a trigger event type, v2 cannot know what to evaluate the condition '
            . 'against and the migration stops being a read.' );
    }

    /** One unreadable blob must not stop every other Profile migrating. */
    public function testAnUnreadableBlobPlansNothingRatherThanThrowing(): void
    {
        $this->assertSame( array(),
            \OWA\Module\Base\Update\Update025::planForProfile(
                array( 'scope_id' => 'OWA-probe', 'value' => 'not-serialized-at-all' ) ) );
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

        $previous = set_error_handler(
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
            set_error_handler( $previous );
        }
    }

    /** And the form refuses it, rather than storing something inert. */
    public function testABrokenRegexIsRefusedOnSave(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/GoalEventSave.php' );

        $this->assertStringContainsString( 'not a valid regular expression', $src,
            'A pattern that cannot compile can be saved, and then matches nothing '
            . 'for ever without saying so.' );
    }

    /** Several conditions, combined with all or any. */
    public function testConditionsCombineWithAllOrAny(): void
    {
        $goalEvent = $this->makeGoalEventWithConditions( array(
            array( 'page_uri', 'begins', '/checkout' ),
            array( 'ct_total', 'gt',     '50' ),
        ) );

        $over  = $this->fakeEvent( array( 'page_uri' => '/checkout/done', 'ct_total' => '60' ) );
        $under = $this->fakeEvent( array( 'page_uri' => '/checkout/done', 'ct_total' => '40' ) );

        $this->assertTrue(  $goalEvent->matchesEvent( $over ) );
        $this->assertFalse( $goalEvent->matchesEvent( $under ),
            'Under ALL, one failing condition must fail the whole rule.' );

        $goalEvent->set( 'condition_match', 'any' );

        $this->assertTrue( $goalEvent->matchesEvent( $under ),
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

        $this->assertFalse( $goalEvent->matchesEvent(
            $this->fakeEvent( array( 'page_uri' => '/anything' ) ) ) );
    }

    /** The migration writes the 1.x triple as a condition row. */
    public function testTheMigratedConditionBecomesARow(): void
    {
        $planned = \OWA\Module\Base\Update\Update025::planForProfile( array(
            'scope_id' => $this->siteId,
            'value'    => serialize( array( 1 => array(
                'goal_name'   => 'Migrated',
                'goal_number' => '1',
                'goal_status' => 'active',
                'goal_type'   => 'url_destination',
                'details'     => array( 'match_type' => 'begins', 'goal_url' => '/thanks' ),
            ) ) ),
        ) );

        $this->assertSame( 'begins', $planned[0]['condition_operator'] );
        $this->assertSame( '/thanks', $planned[0]['condition_value'] );
        $this->assertSame( 'page_uri', $planned[0]['condition_property'] );
    }

    /** A goal event carrying the given conditions, cleaned up afterwards. */
    private function makeGoalEventWithConditions( array $conditions )
    {
        $id = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' )
            ->generateId( 'goal_event:cond-probe:' . uniqid( '', true ) );

        $this->created[] = $id;

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $goalEvent->set( 'id', $id );
        $goalEvent->set( 'property_id', $this->propertyId );
        $goalEvent->set( 'name', 'Condition probe' );
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

    /** Something with ->get(), which is all matchesEvent() asks of an event. */
    private function fakeEvent( array $properties )
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setProperties( $properties );

        return $event;
    }

    /* ---------------- funnels ---------------- */

    /**
     * A funnel is ORDERED conditions, and dropping it would have been silent.
     *
     * 1.x kept it as details.funnel_steps -- inside the goal, inside the goals
     * array, inside a settings blob. This install has no funnel goals, so a
     * migration that dropped them would have passed every test and lost data on
     * somebody else's install.
     */
    public function testFunnelStepsAreMigratedNotDropped(): void
    {
        $planned = \OWA\Module\Base\Update\Update025::planForProfile( array(
            'scope_id' => 'OWA-probe',
            'value'    => serialize( array( 1 => array(
                'goal_name'   => 'Checkout',
                'goal_number' => '1',
                'goal_status' => 'active',
                'goal_type'   => 'url_destination',
                'details'     => array(
                    'match_type'   => 'exact',
                    'goal_url'     => '/done',
                    'funnel_steps' => array(
                        1 => array( 'name' => 'Cart',    'path' => '/cart' ),
                        2 => array( 'name' => 'Payment', 'path' => '/pay' ),
                    ),
                ),
            ) ) ),
        ) );

        $this->assertCount( 2, $planned[0]['steps'],
            'The funnel was dropped, so a funnel goal silently becomes a plain one.' );

        $this->assertSame( 1, $planned[0]['steps'][0]['step_number'] );
        $this->assertSame( 'Cart', $planned[0]['steps'][0]['name'] );

        /*
         * 1.x applies a step path as preg_match( '@<path>@i', $page_uri ), so
         * the operator is regex. Recording it as 'exact' would change what
         * every migrated funnel matches.
         */
        $this->assertSame( 'regex', $planned[0]['steps'][0]['condition_operator'] );
        $this->assertSame( '/cart', $planned[0]['steps'][0]['condition_value'] );
        $this->assertSame( 'page_uri', $planned[0]['steps'][0]['condition_property'] );
    }

    /** A step someone added and left blank is not migrated as a blank step. */
    public function testEmptyFunnelStepsAreDropped(): void
    {
        $steps = \OWA\Module\Base\Update\Update025::planSteps( array(
            'funnel_steps' => array(
                1 => array( 'name' => 'Cart', 'path' => '/cart' ),
                2 => array( 'name' => '',     'path' => '' ),
            ),
        ) );

        $this->assertCount( 1, $steps );
    }

    /**
     * A goal event with no funnel must not report an EMPTY one.
     *
     * checkGoalStart() tests array_key_exists( 'funnel_steps', ... ) and then
     * indexes [1] unconditionally -- so an empty array is a fatal on every
     * event, not "this goal has no funnel".
     */
    public function testAGoalEventWithNoFunnelOmitsTheKeyEntirely(): void
    {
        $siteId = $this->siteId;
        $id = \OWA\Module\Base\Classes\GoalManager::goalEventIdFor( $siteId, 1 );

        $this->created[] = $id;

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $goalEvent->set( 'id', $id );
        $goalEvent->set( 'property_id', $this->propertyId );
        $goalEvent->set( 'name', 'No funnel' );
        $goalEvent->set( 'goal_number', 1 );
        $goalEvent->set( 'is_active', 1 );
        $goalEvent->set( 'condition_operator', 'exact' );
        $goalEvent->set( 'condition_value', '/x' );
        $goalEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $goalEvent->create();

        $goal = $goalEvent->toGoalArray();

        $this->assertArrayNotHasKey( 'funnel_steps', $goal['details'],
            'A goal with no funnel reports an empty funnel_steps, which the conversion '
            . 'evaluator indexes into and fatals on.' );
    }

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

    /* ---------------- the round trip ---------------- */

    /**
     * A goal saved through GoalManager comes back through GoalManager.
     *
     * The point of the whole change: same API, different storage.
     */
    public function testAGoalSavedThroughTheManagerReadsBackFromTheTable(): void
    {
        $siteId = $this->siteId;

        $this->created[] = \OWA\Module\Base\Classes\GoalManager::goalEventIdFor( $siteId, 4 );

        $gm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
        $gm->saveGoal( 4, array(
            'goal_name'   => 'Probe Goal',
            'goal_group'  => '2',
            'goal_status' => 'active',
            'goal_value'  => '3.50',
            'goal_type'   => 'url_destination',
            'details'     => array( 'match_type' => 'begins', 'goal_url' => '/probe' ),
        ) );

        /*
         * Flushed, not unset. supportClassFactory() caches the instance, so
         * unset() does not destruct it and the write would land at shutdown --
         * after every assertion below.
         */
        $gm->flush();

        $goals = \OWA\Module\Base\Classes\GoalManager::loadGoalEventsAsGoals( $siteId );

        $this->assertArrayHasKey( 4, $goals, 'The saved goal did not reach the table.' );
        $this->assertSame( 'Probe Goal', $goals[4]['goal_name'] );
        $this->assertSame( 'active', $goals[4]['goal_status'] );
        $this->assertSame( '3.50', $goals[4]['goal_value'],
            'The value did not survive the trip through cents.' );
        $this->assertSame( 'begins', $goals[4]['details']['match_type'] );
        $this->assertSame( '/probe', $goals[4]['details']['goal_url'] );
    }

    /**
     * Saving one goal must not rewrite the others.
     *
     * This is what the blob could not do: it was written whole, so two people
     * editing different goals lost one of the two edits entirely.
     */
    public function testSavingOneGoalLeavesTheOthersAlone(): void
    {
        $siteId = $this->siteId;

        foreach ( array( 13, 14 ) as $n ) {
            $this->created[] = \OWA\Module\Base\Classes\GoalManager::goalEventIdFor( $siteId, $n );
        }

        $gm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
        $gm->saveGoal( 13, array( 'goal_name' => 'First', 'goal_status' => 'active',
                                 'details' => array( 'match_type' => 'exact', 'goal_url' => '/one' ) ) );
        $gm->saveGoal( 14, array( 'goal_name' => 'Second', 'goal_status' => 'active',
                                 'details' => array( 'match_type' => 'exact', 'goal_url' => '/two' ) ) );
        $gm->flush();

        /*
         * A second manager touching only ONE of them.
         *
         * Slots within numGoals (15 here): saveGoal() silently ignores a number
         * above it, so a test using 17 saves nothing and then measures an empty
         * list.
         */
        $other = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
        $other->saveGoal( 13, array( 'goal_name' => 'First renamed', 'goal_status' => 'active',
                                    'details' => array( 'match_type' => 'exact', 'goal_url' => '/one' ) ) );
        $other->flush();

        $goals = \OWA\Module\Base\Classes\GoalManager::loadGoalEventsAsGoals( $siteId );

        $this->assertSame( 'First renamed', $goals[13]['goal_name'] ?? null );
        $this->assertSame( 'Second', $goals[14]['goal_name'] ?? null,
            'Saving one goal rewrote another, which is the blob behaviour this replaces.' );
    }

    /**
     * A goal event with no slot is a real goal event with no NUMBERED metric.
     *
     * The 45 goal{N} metrics resolve by number, so a goal event beyond the
     * twentieth has nothing to report through in 1.x -- but it must not corrupt
     * the numbered view by appearing under slot 0.
     */
    public function testAGoalEventWithNoSlotIsNotGivenOne(): void
    {
        $siteId = $this->siteId;

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $id = $goalEvent->generateId( 'goal_event:unslotted:' . $siteId );

        $this->created[] = $id;

        $goalEvent->set( 'id', $id );
        $goalEvent->set( 'property_id', $this->propertyId );
        $goalEvent->set( 'name', 'Unslotted' );
        $goalEvent->set( 'is_active', 1 );
        $goalEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $goalEvent->create();

        $goals = \OWA\Module\Base\Classes\GoalManager::loadGoalEventsAsGoals( $siteId );

        $this->assertArrayNotHasKey( 0, $goals,
            'An unnumbered goal event was given slot 0, which the goal metrics would then '
            . 'report under a goal that does not exist.' );

        $this->assertSame( array(), $goals );
    }

    /** The id is derived, so saving twice updates one row rather than making two. */
    public function testSavingTheSameSlotTwiceUpdatesOneRow(): void
    {
        $siteId = $this->siteId;

        $this->created[] = \OWA\Module\Base\Classes\GoalManager::goalEventIdFor( $siteId, 7 );

        foreach ( array( 'One', 'Two' ) as $name ) {

            $gm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
            $gm->saveGoal( 7, array( 'goal_name' => $name, 'goal_status' => 'active',
                                     'details' => array( 'match_type' => 'exact', 'goal_url' => '/x' ) ) );
        $gm->flush();
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( 'id' );
        /*
         * This SLOT, not every row on the Property.
         *
         * Goal events belong to the Property now, so a Property with other goal
         * events -- including whatever the install already had -- shares this
         * table with the probe. Counting all of them would fail for a reason
         * that has nothing to do with what is being tested.
         */
        $db->where( 'property_id', $this->propertyId );
        $db->where( 'goal_number', 7 );

        $this->assertCount( 1, (array) $db->getAllRows(),
            'Editing a goal created a second row, so the numbered slot now names two.' );

        $goals = \OWA\Module\Base\Classes\GoalManager::loadGoalEventsAsGoals( $siteId );
        $this->assertSame( 'Two', $goals[7]['goal_name'] ?? null );
    }
}
