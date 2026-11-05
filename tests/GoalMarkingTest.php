<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\GoalMarking;
use OWA\Module\Base\Classes\Ingest;

/**
 * Goal marking is a callback on the set of events a beacon is saved as.
 *
 * It runs after the materializers, so it is handed session_start and first_visit
 * too, judges each event on its own values against the row it will become, and
 * appends a goal event -- named by the definition, is_goal_event = 1 -- for
 * each goal event definition met. Every other row, the one that met it
 * included, is 0.
 *
 * A definition counting once per session adds none where the session already
 * has one in raw, so those tests store a beacon's rows and send the next.
 *
 * These tests go through Ingest::TRACKING_EVENTS_PRE_SAVE rather than calling
 * mark() directly, because the wiring is half of what is being claimed. A
 * callback that works and is not registered marks nothing -- and one registered
 * BEFORE the materializers never sees the events they append.
 */
final class GoalMarkingTest extends TestCase
{
    /** The visitor of the beacons these tests STORE, so tearDown() can find their rows. */
    private const STORED_VISITOR = 7775200000000002;

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

            \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->delete( $id );
        }

        $this->created = [];

        if ( $this->siteId !== '' ) {

            \OWA\Core\CoreAPI::dbSingleton()->query( 'DELETE FROM owa_event_raw WHERE site_id = ? AND visitor_id = ?',
                array( $this->siteId, self::STORED_VISITOR ) );
        }

        /*
         * The memo is per process and these tests add goal events to a real
         * Property, so leaving it populated would hand the next test -- in this
         * file or any other -- a goal that no longer exists.
         */
        GoalMarking::forget();
    }

    /* ---------------- the wiring ---------------- */

    /**
     * A definition matching the event adds its goal event, through the
     * registered callback: the event under the definition's name, flagged,
     * while the event itself stays 0 so the goal event is counted once.
     */
    public function testTheCallbackIsRegisteredAndAddsAGoalEvent(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );

        $events = $this->filter( $this->event( array( 'page_path' => '/thanks/you', 'engagement_msec' => 4000 ) ) );

        $this->assertSame( array( 'page_view' => 0, 'Marking_probe' => 1 ), $this->flags( $events ),
            'No goal event. Either the callback is not registered at '
            . Ingest::TRACKING_EVENTS_PRE_SAVE . ' or it did not match.' );

        $goalEvent = $events[1];

        foreach ( array( 'site_id', 'visitor_id', 'session_id', 'ts', 'page_path' ) as $copied ) {
            $this->assertSame( $events[0]->get( $copied ), $goalEvent->get( $copied ), $copied );
        }

        $this->assertArrayNotHasKey( 'engagement_msec', $goalEvent->getProperties(),
            'copied, the page view\'s engagement time would count twice in the session' );

        $rows = array_map( array( \OWA\Module\Base\Handler\EventRawHandlers::class, 'rowFor' ), $events );
        $this->assertNotSame( $rows[0]['id'], $rows[1]['id'], 'one beacon, two rows, two ids' );
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
     * visitor landing on a goal page counted three goal events.
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
            'page_view'     => 0,
            'session_start' => 0,
            'first_visit'   => 0,
            'Marking_probe' => 1,
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

        $this->assertSame( array( 'page_view' => 0, 'first_visit' => 0, 'Marking_probe' => 1 ), $marked );
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
    public function testAGoalEventDefinitionWithNoSlotNumberStillMarks(): void
    {
        $goal = $this->makeGoal( array( array( 'page_path', 'exact', '/slotless' ) ) );

        $this->assertSame( '', (string) $goal->get( 'goal_number' ),
            'the fixture gave it a slot, so this would pass without proving anything' );

        $events = $this->filter( $this->event( array( 'page_path' => '/slotless' ) ) );

        $this->assertSame( array( 'page_view' => 0, 'Marking_probe' => 1 ), $this->flags( $events ),
            'A definition with no slot number marked nothing -- which is every one '
            . 'the current screen creates.' );
    }

    /** An inactive goal event marks nothing. */
    public function testAnInactiveGoalEventDefinitionDoesNotMark(): void
    {
        $this->makeGoal( array( array( 'page_path', 'exact', '/off' ) ), array(
            'is_active' => 0 ) );

        $events = $this->filter( $this->event( array( 'page_path' => '/off' ) ) );

        $this->assertSame( array( 'page_view' => 0 ), $this->flags( $events ),
            'A definition switched off still added goal events.' );
    }

    /** An event meeting two definitions adds a goal event for each. */
    public function testTwoMatchingGoalsAddTwoRows(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ), array( 'name' => 'Thanks_page' ) );
        $this->makeGoal( array( array( 'host', 'exact', 'example.com' ) ), array( 'name' => 'Example_host' ) );

        $events = $this->filter( $this->event( array(
            'page_path' => '/thanks', 'host' => 'example.com' ) ) );

        $flags = $this->flags( $events );
        ksort( $flags );

        $this->assertSame( array( 'Example_host' => 1, 'Thanks_page' => 1, 'page_view' => 0 ), $flags );
    }

    /**
     * Two goal events with ONE name add one row. The save refuses a second
     * one, but a beacon must not depend on that: every row of a beacon shares
     * its ts, so two would derive one id and its insert would fail on its own key.
     */
    public function testTwoGoalsWithOneNameAddOneRow(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );
        $this->makeGoal( array( array( 'host', 'exact', 'example.com' ) ) );

        $events = $this->filter( $this->event( array(
            'page_path' => '/thanks', 'host' => 'example.com' ) ) );

        $this->assertSame( array( 'page_view' => 0, 'Marking_probe' => 1 ), $this->flags( $events ) );
        $this->assertCount( 2, $events );
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

    /** A definition on a site's own event, with no condition, is met by every one of them. */
    public function testACustomEventGoalWithNoConditionMarksEveryOne(): void
    {
        $this->makeGoal( array(), array( 'trigger_event_type' => 'newsletter_signup', 'name' => 'Signed_up_probe' ) );

        $signup = $this->event( array( 'page_path' => '/anything' ) );
        $signup->setEventType( 'newsletter_signup' );

        $this->assertSame( array( 'newsletter_signup' => 0, 'Signed_up_probe' => 1 ),
            $this->flags( $this->filter( $signup ) ) );

        $this->assertSame( array( 'page_view' => 0 ),
            $this->flags( $this->filter( $this->event( array( 'page_path' => '/anything' ) ) ) ),
            'the trigger gates' );
    }

    /* ---------------- how often ---------------- */

    /** Once per event: every match adds one, whatever the session already has. */
    public function testOncePerEventAddsAGoalEventForEveryMatch(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ),
            array( 'count_mode' => \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_EVENT ) );

        $start = time() - 120;

        $this->store( $this->filter( $this->beacon( 8885200000000101, $start, $start + 10, '/thanks' ) ) );

        $this->assertSame( array( 'page_view' => 0, 'Marking_probe' => 1 ),
            $this->flags( $this->filter( $this->beacon( 8885200000000101, $start, $start + 60, '/thanks/again' ) ) ),
            'the session already has one, and this definition counts every match' );
    }

    /**
     * Once per session: the first match adds one, and a later beacon of the
     * session finds it in raw and adds none. A definition migrated from 1.x
     * stores no count mode at all, which reads as once per session.
     */
    public function testOncePerSessionAddsOneAcrossTheSessionsBeacons(): void
    {
        foreach ( array( \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_SESSION, null ) as $i => $mode ) {

            // A path of its own, so the definition made for the other mode does not match.
            $this->makeGoal( array( array( 'page_path', 'begins', '/thanks-' . $i ) ),
                array( 'count_mode' => $mode, 'name' => 'Session_probe_' . $i ) );

            $session = 8885200000000201 + $i;
            $start   = time() - 120;

            $first = $this->filter( $this->beacon( $session, $start, $start + 10, '/thanks-' . $i ) );

            $this->assertSame( array( 'page_view' => 0, 'Session_probe_' . $i => 1 ), $this->flags( $first ) );

            $this->store( $first );

            $this->assertSame( array( 'page_view' => 0 ),
                $this->flags( $this->filter( $this->beacon( $session, $start, $start + 60, '/thanks-' . $i . '/again' ) ) ),
                var_export( $mode, true ) . ': the session already has its goal event' );
        }
    }

    /**
     * ...including when the session started before midnight and this beacon is
     * after it: the goal event is in yesterday's partition, and the lookup reads
     * from the day the session started.
     */
    public function testOncePerSessionFindsTheGoalEventOfASessionThatStartedTheDayBefore(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ),
            array( 'count_mode' => \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_SESSION ) );

        $midnight = mktime( 0, 0, 0 );
        $start    = $midnight - 600;

        $first = $this->filter( $this->beacon( 8885200000000301, $start, $midnight - 300, '/thanks' ) );

        $this->assertSame( (int) date( 'Ymd', $midnight - 300 ), (int) $first[0]->get( 'yyyymmdd' ) );
        $this->assertSame( array( 'page_view' => 0, 'Marking_probe' => 1 ), $this->flags( $first ) );

        $this->store( $first );

        $after = $this->beacon( 8885200000000301, $start, $midnight + 300, '/thanks/again' );

        $this->assertNotSame( $first[0]->get( 'yyyymmdd' ), $after->get( 'yyyymmdd' ), 'the next day' );
        $this->assertSame( array( 'page_view' => 0 ), $this->flags( $this->filter( $after ) ),
            'the goal event stored before midnight was not found' );
    }

    /** ...and another session of the same visitor adds its own. */
    public function testOncePerSessionAddsAgainInASecondSession(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ),
            array( 'count_mode' => \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_SESSION ) );

        $start = time() - 7200;

        $this->store( $this->filter( $this->beacon( 8885200000000401, $start, $start + 10, '/thanks' ) ) );

        $this->assertSame( array( 'page_view' => 0, 'Marking_probe' => 1 ),
            $this->flags( $this->filter( $this->beacon( 8885200000000402, time() - 60, time() - 50, '/thanks' ) ) ) );
    }

    /** sessionDays(): from the session's start, to this event's day, and no further back than a day. */
    public function testTheLookupIsBoundedToTheSessionsDays(): void
    {
        $midnight = mktime( 0, 0, 0 );
        $row      = array( 'yyyymmdd' => (int) date( 'Ymd', $midnight + 300 ), 'ts' => ( $midnight + 300 ) * 1000000 );

        $this->assertSame( array( (int) date( 'Ymd', $midnight - 600 ), $row['yyyymmdd'] ),
            GoalMarking::sessionDays( $row + array( 'session_start_ts' => $midnight - 600 ) ), 'started yesterday' );

        $this->assertSame( array( $row['yyyymmdd'], $row['yyyymmdd'] ),
            GoalMarking::sessionDays( $row + array( 'session_start_ts' => $midnight + 60 ) ), 'started today' );

        $this->assertSame( array( (int) date( 'Ymd', $midnight + 300 - 86400 ), $row['yyyymmdd'] ),
            GoalMarking::sessionDays( $row + array( 'session_start_ts' => $midnight - 86400 * 365 ) ),
            'a visitor clock a year behind reads no more than the day before' );

        $this->assertSame( array( $row['yyyymmdd'], $row['yyyymmdd'] ),
            GoalMarking::sessionDays( $row + array( 'session_start_ts' => null ) ), 'no start: this day' );

        $this->assertSame( array( $row['yyyymmdd'], $row['yyyymmdd'] ),
            GoalMarking::sessionDays( $row + array( 'session_start_ts' => $midnight + 86400 ) ),
            'a start after the event is a clock ahead' );
    }

    /* ---------------- what it is worth ---------------- */

    /**
     * A definition's value goes onto its goal event, in minor units of the
     * currency the Property's reports use -- with that currency, so the value
     * is never minor units of nothing in particular.
     */
    public function testADefinitionsValueIsCarriedWithTheReportingCurrency(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ),
            array( 'value' => 250, 'count_mode' => \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_EVENT ) );

        $events = $this->filter( $this->event( array( 'page_path' => '/thanks' ) ) );

        $currency = \OWA\Module\Base\Classes\Currency::normalize(
            \OWA\Module\Base\Classes\Currency::ofProperty( $this->propertyId ) ) ?: 'USD';

        $row = \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $events[1] );

        $this->assertSame( 1, $row['is_goal_event'] );
        $this->assertSame( \OWA\Module\Base\Classes\Currency::toMinorUnits( 2.50, $currency ), $row['value'] );
        $this->assertSame( $currency, $row['currency'] );

        $this->assertNull( \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $events[0] )['value'],
            'the event that met it carries no value' );
    }

    /** A purchase meeting a definition with no value of its own lends it the purchase's revenue. */
    public function testAPurchaseMeetingADefinitionWithNoValueCarriesItsRevenue(): void
    {
        $this->makeGoal( array( array( 'revenue', 'gt', '0' ) ),
            array( 'trigger_event_type' => 'purchase', 'name' => 'Bought_probe',
                   'count_mode' => \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_EVENT ) );

        $purchase = $this->event( array( 'page_path' => '/checkout', 'revenue' => 1999, 'currency' => 'EUR',
            'ct_order_id' => 'marking-probe-1' ) );
        $purchase->setEventType( 'purchase' );

        $events = $this->filter( $purchase );

        $this->assertSame( array( 'purchase' => 0, 'Bought_probe' => 1 ), $this->flags( $events ) );

        $row = \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $events[1] );

        $this->assertSame( 1999, $row['value'] );
        $this->assertSame( 'EUR', $row['currency'] );
    }

    /** A definition with no value, met by anything but a purchase, adds a goal event worth nothing. */
    public function testAGoalEventWithNothingToCarryHasNoValue(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );

        $events = $this->filter( $this->event( array( 'page_path' => '/thanks' ) ) );
        $row    = \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $events[1] );

        $this->assertSame( 1, $row['is_goal_event'] );
        $this->assertNull( $row['value'] );
        $this->assertNull( $row['currency'] );
    }

    /** THE MARKING IS THE AUTHORITY on value too: an event carrying one into it comes out with none. */
    public function testAValueOnAnIncomingEventIsCleared(): void
    {
        $this->makeGoal( array( array( 'page_path', 'begins', '/thanks' ) ) );

        $events = $this->filter( $this->event( array( 'page_path' => '/pricing', 'value' => 5000 ) ) );

        $this->assertNull( \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $events[0] )['value'] );
    }

    /* ---------------- helpers ---------------- */

    /** event name => is_goal_event, in the order the set holds them. */
    private function flags( array $events ): array
    {
        $out = array();

        foreach ( $events as $event ) {
            $out[ $event->getEventType() ] = $event->get( 'is_goal_event' );
        }

        return $out;
    }

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
     * A page_view of STORED_VISITOR in one session, at $ts, the session having
     * started at $start (both in seconds).
     */
    private function beacon( int $session, int $start, int $ts, string $path )
    {
        return $this->event( array(
            'visitor_id' => self::STORED_VISITOR,
            'session_id' => $session,
            'ts'         => $ts * 1000000,
            'yyyymmdd'   => (int) date( 'Ymd', $ts ),
            'sts'        => $start,
            'page_path'  => $path,
        ) );
    }

    /** Store a marked set in raw, as ingest would, so the next beacon can find it. */
    private function store( array $events ): void
    {
        foreach ( $events as $event ) {

            $row = \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $event );

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );
            $entity->setProperties( $row );

            $this->assertTrue( (bool) $entity->create(), 'storing ' . $row['event_type'] );
        }
    }

    /**
     * One active goal event definition on this Profile's Property, with
     * conditions.
     *
     * No goal_number, deliberately: that is what the current screen creates, and
     * it is the case marking used to miss.
     */
    private function makeGoal( array $conditions, array $overrides = array() )
    {
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
        $id     = $entity->generateId( 'goal_event:marking-probe:' . uniqid( '', true ) );

        $this->created[] = $id;

        $entity->set( 'id', $id );
        $entity->set( 'property_id', $this->propertyId );
        $entity->set( 'name', $overrides['name'] ?? 'Marking_probe' );
        $entity->set( 'trigger_event_type', array_key_exists( 'trigger_event_type', $overrides )
            ? $overrides['trigger_event_type'] : 'page_view' );
        $entity->set( 'is_active', array_key_exists( 'is_active', $overrides )
            ? $overrides['is_active'] : 1 );
        $entity->set( 'count_mode', $overrides['count_mode'] ?? null );
        $entity->set( 'value', $overrides['value'] ?? null );
        $entity->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $entity->create();

        $n = 0;

        foreach ( $conditions as $c ) {

            $n++;

            $row = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' );
            $row->set( 'id', $row->generateId( 'goal_event_condition:' . $id . ':' . $n ) );
            $row->set( 'goal_event_definition_id', $id );
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
