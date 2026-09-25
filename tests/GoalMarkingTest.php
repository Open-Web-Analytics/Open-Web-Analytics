<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\GoalMarking;
use OWA\Module\Base\Classes\Ingest;

/**
 * Goal marking is a listener on the complete row.
 *
 * It used to be a step inside EventRawHandlers::row(), computed from the EVENT
 * while the row literal was still being assembled -- before deviceColumns() and
 * taggedColumns() merged in. So the two families of column an author most wants
 * to test, device_type and tagged_*, were not in scope when conditions were
 * matched: a goal on "mobile" or "organic" matched nothing and said nothing.
 *
 * These tests go through Ingest::STORE_POST rather than calling mark()
 * directly, because the wiring is half of what is being claimed. A listener
 * that works and is not registered marks nothing.
 */
final class GoalMarkingTest extends TestCase
{
    private array $created = [];

    private string $siteId = '';

    private string $propertyId = '';

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        /*
         * A REAL Profile with a Property. Goal events hang off the Property, so
         * an invented site id resolves to none and every assertion below would
         * pass by measuring an empty goal list.
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

        GoalMarking::forget();
    }

    protected function tearDown(): void
    {
        foreach ( $this->created as $id ) {

            \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' )->delete( $id );
        }

        $this->created = [];

        /*
         * The memo is per process and these tests add goal events to a real
         * Property, so leaving it populated would hand the next test -- in this
         * file or any other -- a goal that no longer exists.
         */
        GoalMarking::forget();
    }

    /* ---------------- the wiring ---------------- */

    /** A goal matching the row marks it, through the registered listener. */
    public function testTheListenerIsRegisteredAndMarksAMatchingRow(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );

        $row = $this->filter( $this->row( array( 'page_path' => '/thanks/you' ) ) );

        $this->assertSame( 1, $row['is_goal_event'],
            'Nothing marked the row. Either the listener is not registered at '
            . Ingest::STORE_POST . ' or it did not match.' );
    }

    /** ...and a row that matches nothing comes back 0, not absent. */
    public function testAnUnmatchedRowIsMarkedZero(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );

        $row = $this->filter( $this->row( array( 'page_path' => '/pricing' ) ) );

        $this->assertSame( 0, $row['is_goal_event'] );
    }

    /**
     * THE LISTENER IS THE AUTHORITY, so a 1 arriving from anywhere else does not
     * survive a run that disagrees.
     *
     * is_goal_event is NOT NULL, so the handler writes 0 into the row literal to
     * keep it out of a strict-mode insert with no value. If marking only ever
     * RAISED the flag, that default would be the only thing keeping a stale 1
     * out of the table.
     */
    public function testAStaleFlagOnTheRowIsOverwritten(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );

        $row = $this->row( array( 'page_path' => '/pricing' ) );
        $row['is_goal_event'] = 1;

        $this->assertSame( 0, $this->filter( $row )['is_goal_event'] );
    }

    /* ---------------- what gets read ---------------- */

    /**
     * A GOAL EVENT WITH NO SLOT NUMBER IS MARKED.
     *
     * It was not. Marking walked GoalManager::getActiveGoals(), which answers in
     * the 1.x goal shape keyed by slot number: it seeds slots 1..20 and keeps
     * only goals whose number is one of them. The current screen deliberately
     * creates goal events WITHOUT a slot -- there is a test next door asserting
     * it does not invent one -- so every goal made through it was invisible to
     * ingest. Reading the table by property_id has no opinion about slots.
     */
    public function testAGoalEventWithNoSlotNumberStillMarks(): void
    {
        $goal = $this->makeGoal( array( array( 'page_path', 'exact', '/slotless' ) ) );

        $this->assertSame( '', (string) $goal->get( 'goal_number' ),
            'the fixture gave it a slot, so this would pass without proving anything' );

        $row = $this->filter( $this->row( array( 'page_path' => '/slotless' ) ) );

        $this->assertSame( 1, $row['is_goal_event'],
            'A goal event with no slot number marked nothing -- which is every goal '
            . 'the current screen creates.' );
    }

    /** An inactive goal event marks nothing. */
    public function testAnInactiveGoalEventDoesNotMark(): void
    {
        $this->makeGoal( array( array( 'page_path', 'exact', '/off' ) ), array(
            'is_active' => 0 ) );

        $row = $this->filter( $this->row( array( 'page_path' => '/off' ) ) );

        $this->assertSame( 0, $row['is_goal_event'],
            'A goal event switched off still counted conversions.' );
    }

    /**
     * ONE FLAG. An event meeting two goals is still one event, and
     * goalConversions counts events -- so two matching goals must not produce a
     * 2, and must not be counted twice.
     */
    public function testTwoMatchingGoalsSetOneFlag(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );
        $this->makeGoal( array( array( 'host', 'exact', 'example.com' ) ) );

        $row = $this->filter( $this->row( array(
            'page_path' => '/thanks', 'host' => 'example.com' ) ) );

        $this->assertSame( 1, $row['is_goal_event'] );
    }

    /**
     * A row with no site_id is left alone rather than matched against something.
     *
     * Db::where() drops a clause whose value is empty, so a goal lookup on no
     * site id would not narrow to nothing -- it would widen to every Property's
     * goal events on the installation.
     */
    public function testARowWithNoSiteIdIsNotMarked(): void
    {
        $this->makeGoal( array( array( 'page_path', 'exact', '/anything' ) ) );

        $row = $this->row( array( 'page_path' => '/anything' ) );
        unset( $row['site_id'] );

        $filtered = $this->filter( $row );

        $this->assertArrayNotHasKey( 'is_goal_event', $filtered,
            'A row with no site id was marked, which means the goal lookup ran '
            . 'unfiltered.' );
    }

    /* ---------------- helpers ---------------- */

    /** Run the row through the real ingest point. */
    private function filter( array $row )
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setProperties( array( 'site_id' => $this->siteId ) );

        return Ingest::at( Ingest::STORE_POST, $row, $event );
    }

    /** A raw row of the shape store() hands to the point. */
    private function row( array $columns )
    {
        return array_merge( array(
            'event_type' => 'page_view',
            'site_id'    => $this->siteId,
        ), $columns );
    }

    /**
     * One active goal event on this Profile's Property, with conditions.
     *
     * No goal_number, deliberately: that is what the current screen creates, and
     * it is the case marking used to miss.
     */
    private function makeGoal( array $conditions, array $overrides = array() )
    {
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $id     = $entity->generateId( 'goal_event:marking-probe:' . uniqid( '', true ) );

        $this->created[] = $id;

        $entity->set( 'id', $id );
        $entity->set( 'property_id', $this->propertyId );
        $entity->set( 'name', 'Marking probe' );
        $entity->set( 'trigger_event_type', 'page_view' );
        $entity->set( 'is_active', array_key_exists( 'is_active', $overrides )
            ? $overrides['is_active'] : 1 );
        $entity->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $entity->create();

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
        }

        GoalMarking::forget();

        return $entity;
    }
}
