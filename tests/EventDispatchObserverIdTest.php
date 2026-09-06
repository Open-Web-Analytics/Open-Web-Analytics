<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Observer ids must be unique within the process.
 *
 * WHAT THIS IS ABOUT
 *
 * attach() and attachFilter() share one $listeners map, keyed by an id that
 * came from Lib::generateRandomUid() -- time() . mt_rand(0,999999) . pid. In a
 * single process the time and pid are fixed, so two registrations differed only
 * by a six-digit random. A couple of hundred registrations in one request puts
 * a collision at a percent or two by the birthday bound: rare enough to look
 * like flakiness, common enough to fail CI regularly.
 *
 * A collision overwrites. The loud symptom was a filter callback reached
 * through notify() and called with one argument -- "Too few arguments to
 * ...lowercaseString(), 1 passed and exactly 2 expected" -- because that is
 * what a listener is given. The quiet one is worse: whichever observer lost the
 * collision never runs again and nothing reports it.
 */
final class EventDispatchObserverIdTest extends TestCase
{
    private function dispatch(): object
    {
        return \OWA\Core\CoreAPI::supportClassFactory( 'base', 'eventDispatch' );
    }

    private function ids( object $dispatch ): array
    {
        $property = new \ReflectionProperty( $dispatch, 'listeners' );
        $property->setAccessible( true );

        return array_keys( (array) $property->getValue( $dispatch ) );
    }

    /**
     * Enough registrations that a six-digit random would very likely collide.
     *
     * 2000 ids out of a million values is a collision with probability ~86% --
     * so this fails almost every run against the old scheme and passes always
     * against a counter, which is the difference worth pinning.
     */
    public function testManyRegistrationsProduceNoDuplicateIds(): void
    {
        $dispatch = $this->dispatch();

        for ( $i = 0; $i < 2000; $i++ ) {

            $dispatch->attach( 'test.event.' . $i, array( 'SomeClass', 'method' . $i ) );
        }

        $ids = $this->ids( $dispatch );

        $this->assertCount( 2000, $ids );
        $this->assertSame( count( $ids ), count( array_unique( $ids ) ),
            'two observers were given the same id, so one of them silently replaced '
            . 'the other' );
    }

    /**
     * Listeners and FILTERS share the id space, so the two must not collide
     * with each other either -- that is the crossing that turns a filter into a
     * listener and calls it with the wrong number of arguments.
     */
    public function testListenersAndFiltersDoNotShareIds(): void
    {
        $dispatch = $this->dispatch();

        for ( $i = 0; $i < 1000; $i++ ) {

            $dispatch->attach( 'test.event.' . $i, array( 'SomeClass', 'listener' . $i ) );
            $dispatch->attachFilter( 'test.property.' . $i,
                array( 'SomeClass', 'filter' . $i ), 0 );
        }

        $ids = $this->ids( $dispatch );

        $this->assertCount( 2000, $ids );
        $this->assertSame( count( $ids ), count( array_unique( $ids ) ) );
    }

    /**
     * ...and a filter never lands in the map notify() reads.
     *
     * notify() calls whatever it finds with a single argument. A property
     * filter takes the value AND the event, so being notified is the shape of
     * the crash.
     */
    public function testAFilterIsNotRegisteredAsAnEventListener(): void
    {
        $dispatch = $this->dispatch();

        $dispatch->attachFilter( 'cv1_name',
            array( 'OWA\\Module\\Base\\Classes\\TrackingEventHelpers', 'lowercaseString' ), 0 );

        $byEventType = new \ReflectionProperty( $dispatch, 'listenersByEventType' );
        $byEventType->setAccessible( true );

        $this->assertArrayNotHasKey( 'cv1_name', (array) $byEventType->getValue( $dispatch ),
            'a filter must not be reachable from notify(), which would call it with one '
            . 'argument' );
    }
}
