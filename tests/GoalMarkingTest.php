<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\GoalMarking;
use OWA\Module\Base\Classes\Ingest;

/**
 * Goal marking is a callback on the set of events a beacon is saved as.
 *
 * It runs after the materializers, so it is handed session_start and first_visit
 * too, and marks each event on its own values against the row it will become.
 *
 * These tests go through Ingest::TRACKING_EVENTS_PRE_SAVE rather than calling
 * mark() directly, because the wiring is half of what is being claimed. A
 * callback that works and is not registered marks nothing -- and one registered
 * BEFORE the materializers never sees the events they append.
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

    /** A goal matching the event marks it, through the registered callback. */
    public function testTheCallbackIsRegisteredAndMarksAMatchingEvent(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );

        $events = $this->filter( $this->event( array( 'page_path' => '/thanks/you' ) ) );

        $this->assertSame( 1, $events[0]->get( 'is_goal_event' ),
            'Nothing marked the event. Either the callback is not registered at '
            . Ingest::TRACKING_EVENTS_PRE_SAVE . ' or it did not match.' );
    }

    /** ...and an event that matches nothing comes back 0, not absent. */
    public function testAnUnmatchedEventIsMarkedZero(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );

        $events = $this->filter( $this->event( array( 'page_path' => '/pricing' ) ) );

        $this->assertSame( 0, $events[0]->get( 'is_goal_event' ) );
    }

    /**
     * THE CALLBACK IS THE AUTHORITY, so a 1 arriving from anywhere else does not
     * survive a run that disagrees.
     */
    public function testAStaleFlagOnTheEventIsOverwritten(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );

        $event = $this->event( array( 'page_path' => '/pricing', 'is_goal_event' => 1 ) );

        $this->assertSame( 0, $this->filter( $event )[0]->get( 'is_goal_event' ) );
    }

    /* ---------------- materialized events ---------------- */

    /**
     * A page_view goal marks the page view and NOT the session_start and
     * first_visit materialized beside it, although all three carry the page.
     *
     * Before, a goal was matched against every row a beacon became, so a new
     * visitor landing on a goal page counted three conversions.
     */
    public function testAPageViewGoalDoesNotMarkTheMaterializedEvents(): void
    {
        $this->makeGoal( array( array( 'page_path', 'exact', '/thanks' ) ) );

        $events = $this->filter( $this->event( array(
            'page_path'              => '/thanks',
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
        ) ) );

        $marked = array();

        foreach ( $events as $event ) {
            $marked[ $event->getEventType() ] = $event->get( 'is_goal_event' );
        }

        $this->assertSame( array(
            'page_view'     => 1,
            'session_start' => 0,
            'first_visit'   => 0,
        ), $marked, 'marking has to run after the materializers and judge each event' );
    }

    /**
     * A goal whose trigger IS a materialized event marks that event -- evaluated
     * on its own values, not inheriting the page view's verdict.
     */
    public function testAGoalTriggeredByAMaterializedEventMarksIt(): void
    {
        $this->makeGoal( array( array( 'page_path', 'exact', '/landing' ) ),
            array( 'trigger_event_type' => 'first_visit' ) );

        $events = $this->filter( $this->event( array(
            'page_path'              => '/landing',
            'is_new_visitor_created' => true,
        ) ) );

        $marked = array();

        foreach ( $events as $event ) {
            $marked[ $event->getEventType() ] = $event->get( 'is_goal_event' );
        }

        $this->assertSame( array( 'page_view' => 0, 'first_visit' => 1 ), $marked );
    }

    /**
     * AN EMPTY TRIGGER MATCHES NOTHING. It used to mean every event, which on a
     * landing page marked the page view and both materialized events.
     */
    public function testAGoalWithNoTriggerMarksNothing(): void
    {
        $this->makeGoal( array( array( 'page_path', 'exact', '/thanks' ) ),
            array( 'trigger_event_type' => '' ) );

        $events = $this->filter( $this->event( array(
            'page_path'              => '/thanks',
            'is_new_session_start'   => true,
        ) ) );

        foreach ( $events as $event ) {
            $this->assertSame( 0, $event->get( 'is_goal_event' ), $event->getEventType() );
        }
    }

    /* ---------------- what gets read ---------------- */

    /**
     * A GOAL EVENT WITH NO SLOT NUMBER IS MARKED.
     *
     * It was not. Marking walked GoalManager::getActiveGoals(), which answers in
     * the 1.x goal shape keyed by slot number: it seeds slots 1..20 and keeps
     * only goals whose number is one of them. The current screen deliberately
     * creates goal events WITHOUT a slot, so every goal made through it was
     * invisible to ingest. Reading the table by property_id has no opinion
     * about slots.
     */
    public function testAGoalEventWithNoSlotNumberStillMarks(): void
    {
        $goal = $this->makeGoal( array( array( 'page_path', 'exact', '/slotless' ) ) );

        $this->assertSame( '', (string) $goal->get( 'goal_number' ),
            'the fixture gave it a slot, so this would pass without proving anything' );

        $events = $this->filter( $this->event( array( 'page_path' => '/slotless' ) ) );

        $this->assertSame( 1, $events[0]->get( 'is_goal_event' ),
            'A goal event with no slot number marked nothing -- which is every goal '
            . 'the current screen creates.' );
    }

    /** An inactive goal event marks nothing. */
    public function testAnInactiveGoalEventDoesNotMark(): void
    {
        $this->makeGoal( array( array( 'page_path', 'exact', '/off' ) ), array(
            'is_active' => 0 ) );

        $events = $this->filter( $this->event( array( 'page_path' => '/off' ) ) );

        $this->assertSame( 0, $events[0]->get( 'is_goal_event' ),
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

        $events = $this->filter( $this->event( array(
            'page_path' => '/thanks', 'host' => 'example.com' ) ) );

        $this->assertSame( 1, $events[0]->get( 'is_goal_event' ) );
    }

    /**
     * An event with no site_id is not matched against anything.
     *
     * Db::where() drops a clause whose value is empty, so a goal lookup on no
     * site id would not narrow to nothing -- it would widen to every Property's
     * goal events on the installation.
     */
    public function testAnEventWithNoSiteIdIsNotMarked(): void
    {
        $this->makeGoal( array( array( 'page_path', 'exact', '/anything' ) ) );

        $event = $this->event( array( 'page_path' => '/anything' ) );
        $event->delete( 'site_id' );

        $this->assertSame( 0, $this->filter( $event )[0]->get( 'is_goal_event' ),
            'An event with no site id was marked, which means the goal lookup ran '
            . 'unfiltered.' );
    }

    /* ---------------- helpers ---------------- */

    /** Run one incoming event through the real store point. */
    private function filter( $event ): array
    {
        return Ingest::at( Ingest::TRACKING_EVENTS_PRE_SAVE, array( $event ) );
    }

    /** An incoming page_view, identified well enough to become a row. */
    private function event( array $properties )
    {
        $event = new \OWA\Module\Base\Classes\Event;
        $event->setEventType( 'page_view' );
        $event->setProperties( array_merge( array(
            'site_id'    => $this->siteId,
            'visitor_id' => 7775200000000001,
            'session_id' => 8885200000000001,
            'ts'         => (int) ( microtime( true ) * 1000000 ),
            'yyyymmdd'   => (int) date( 'Ymd' ),
        ), $properties ) );

        return $event;
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
        $entity->set( 'trigger_event_type', array_key_exists( 'trigger_event_type', $overrides )
            ? $overrides['trigger_event_type'] : 'page_view' );
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
