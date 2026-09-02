<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Goals are rows now, and the row is shaped for v2 key events.
 *
 * Twenty numbered slots lived inside ONE serialized array -- all twenty present
 * whether used or not -- in a single settings row per Profile. A live install
 * here held fifteen entries in 2,135 bytes to describe one real goal. That
 * shape cannot be queried or indexed, loses one of two concurrent edits
 * wholesale, and put a RECORD inside a settings blob.
 *
 * The columns are what v2 needs, not what 1.x had, so the v2 migration reads
 * this table rather than reinterpreting it: an author names a key event, gives
 * it an event type and a condition, and the server materialises a row whose
 * event_type IS that name (PLAN.html §7.14).
 */
final class KeyEventStorageTest extends TestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }
    }

    protected function tearDown(): void
    {
        foreach ( $this->created as $id ) {

            $e = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );
            $e->delete( $id );
        }

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
     * A key event with no funnel must not report an EMPTY one.
     *
     * checkGoalStart() tests array_key_exists( 'funnel_steps', ... ) and then
     * indexes [1] unconditionally -- so an empty array is a fatal on every
     * event, not "this goal has no funnel".
     */
    public function testAKeyEventWithNoFunnelOmitsTheKeyEntirely(): void
    {
        $siteId = 'OWA-keyevent-probe-' . substr( md5( uniqid( '', true ) ), 0, 8 );
        $id = \OWA\Module\Base\Classes\GoalManager::keyEventIdFor( $siteId, 1 );

        $this->created[] = $id;

        $keyEvent = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );
        $keyEvent->set( 'id', $id );
        $keyEvent->set( 'site_id', $siteId );
        $keyEvent->set( 'name', 'No funnel' );
        $keyEvent->set( 'goal_number', 1 );
        $keyEvent->set( 'is_active', 1 );
        $keyEvent->set( 'condition_operator', 'exact' );
        $keyEvent->set( 'condition_value', '/x' );
        $keyEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $keyEvent->create();

        $goal = $keyEvent->toGoalArray();

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
        $ke = '\OWA\Module\Base\Entity\KeyEvent';

        $this->assertSame( 200, $ke::decimalToCents( '2' ) );
        $this->assertSame( 250, $ke::decimalToCents( '2.50' ) );
        $this->assertSame( 29,  $ke::decimalToCents( '0.29' ),
            'A value truncated instead of rounded, which under-reports every time.' );
        $this->assertSame( 0,   $ke::decimalToCents( '' ) );
    }

    /** A value nobody can convert is reported, not silently zeroed. */
    public function testANonNumericValueIsDistinguishableFromZero(): void
    {
        $ke = '\OWA\Module\Base\Entity\KeyEvent';

        $this->assertNull( $ke::decimalToCents( 'about five pounds' ),
            'A non-numeric value is indistinguishable from a real zero, so the migration '
            . 'cannot report that someone typed something it could not keep.' );

        $this->assertSame( 0, $ke::decimalToCents( '0' ) );
    }

    /** And back, because 1.x reporting reads a decimal string. */
    public function testCentsRoundTripBackToTheDecimalFormReportingExpects(): void
    {
        $ke = '\OWA\Module\Base\Entity\KeyEvent';

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
        $siteId = 'OWA-keyevent-probe-' . substr( md5( uniqid( '', true ) ), 0, 8 );

        $this->created[] = \OWA\Module\Base\Classes\GoalManager::keyEventIdFor( $siteId, 4 );

        $gm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
        $gm->saveGoal( 4, array(
            'goal_name'   => 'Probe Goal',
            'goal_group'  => '2',
            'goal_status' => 'active',
            'goal_value'  => '3.50',
            'goal_type'   => 'url_destination',
            'details'     => array( 'match_type' => 'begins', 'goal_url' => '/probe' ),
        ) );

        // The write happens on destruct, which is where the blob write lived too.
        unset( $gm );

        $goals = \OWA\Module\Base\Classes\GoalManager::loadKeyEventsAsGoals( $siteId );

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
        $siteId = 'OWA-keyevent-probe-' . substr( md5( uniqid( '', true ) ), 0, 8 );

        foreach ( array( 1, 2 ) as $n ) {
            $this->created[] = \OWA\Module\Base\Classes\GoalManager::keyEventIdFor( $siteId, $n );
        }

        $gm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
        $gm->saveGoal( 1, array( 'goal_name' => 'First', 'goal_status' => 'active',
                                 'details' => array( 'match_type' => 'exact', 'goal_url' => '/one' ) ) );
        $gm->saveGoal( 2, array( 'goal_name' => 'Second', 'goal_status' => 'active',
                                 'details' => array( 'match_type' => 'exact', 'goal_url' => '/two' ) ) );
        unset( $gm );

        /* A second manager that only touches goal 1. */
        $other = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
        $other->saveGoal( 1, array( 'goal_name' => 'First renamed', 'goal_status' => 'active',
                                    'details' => array( 'match_type' => 'exact', 'goal_url' => '/one' ) ) );
        unset( $other );

        $goals = \OWA\Module\Base\Classes\GoalManager::loadKeyEventsAsGoals( $siteId );

        $this->assertSame( 'First renamed', $goals[1]['goal_name'] ?? null );
        $this->assertSame( 'Second', $goals[2]['goal_name'] ?? null,
            'Saving one goal rewrote another, which is the blob behaviour this replaces.' );
    }

    /**
     * A key event with no slot is a real key event with no NUMBERED metric.
     *
     * The 45 goal{N} metrics resolve by number, so a key event beyond the
     * twentieth has nothing to report through in 1.x -- but it must not corrupt
     * the numbered view by appearing under slot 0.
     */
    public function testAKeyEventWithNoSlotIsNotGivenOne(): void
    {
        $siteId = 'OWA-keyevent-probe-' . substr( md5( uniqid( '', true ) ), 0, 8 );

        $keyEvent = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );
        $id = $keyEvent->generateId( 'key_event:unslotted:' . $siteId );

        $this->created[] = $id;

        $keyEvent->set( 'id', $id );
        $keyEvent->set( 'site_id', $siteId );
        $keyEvent->set( 'name', 'Unslotted' );
        $keyEvent->set( 'is_active', 1 );
        $keyEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $keyEvent->create();

        $goals = \OWA\Module\Base\Classes\GoalManager::loadKeyEventsAsGoals( $siteId );

        $this->assertArrayNotHasKey( 0, $goals,
            'An unnumbered key event was given slot 0, which the goal metrics would then '
            . 'report under a goal that does not exist.' );

        $this->assertSame( array(), $goals );
    }

    /** The id is derived, so saving twice updates one row rather than making two. */
    public function testSavingTheSameSlotTwiceUpdatesOneRow(): void
    {
        $siteId = 'OWA-keyevent-probe-' . substr( md5( uniqid( '', true ) ), 0, 8 );

        $this->created[] = \OWA\Module\Base\Classes\GoalManager::keyEventIdFor( $siteId, 7 );

        foreach ( array( 'One', 'Two' ) as $name ) {

            $gm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );
            $gm->saveGoal( 7, array( 'goal_name' => $name, 'goal_status' => 'active',
                                     'details' => array( 'match_type' => 'exact', 'goal_url' => '/x' ) ) );
            unset( $gm );
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'site_id', $siteId );

        $this->assertCount( 1, (array) $db->getAllRows(),
            'Editing a goal created a second row, so the numbered slot now names two.' );

        $goals = \OWA\Module\Base\Classes\GoalManager::loadKeyEventsAsGoals( $siteId );
        $this->assertSame( 'Two', $goals[7]['goal_name'] );
    }
}
