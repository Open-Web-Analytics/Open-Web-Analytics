<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A listener may be registered in more than one shape, and notify() has to
 * survive all of them.
 *
 * filter() has always accepted a class NAME where an instance would do, and
 * says so in a comment. notify() called get_class() on it unconditionally, so
 * the moment any handler was registered statically the whole dispatch died on
 * a TypeError -- not that one handler, the entire event.
 *
 * It reached CI as tests/DimensionIngestionTest failing in the isolation
 * sweep, intermittently, because whether such a handler is registered depends
 * on which modules and settings a given run has.
 */
final class EventDispatchListenerShapeTest extends TestCase
{
    private function dispatch(): object
    {
        return \OWA\Core\CoreAPI::supportClassFactory( 'base', 'eventDispatch' );
    }

    private function event( string $type ): object
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setEventType( $type );

        return $event;
    }

    /** The ordinary shape: an object and a method name. */
    public function testAnObjectListenerIsNotified(): void
    {
        $d = $this->dispatch();

        $d->attach( 'shape.object', array( new EventDispatchShapeSpy(), 'handle' ) );
        $d->notify( $this->event( 'shape.object' ) );

        $this->assertTrue( EventDispatchShapeSpy::$ran, 'the handler never ran' );
    }

    /**
     * The shape that broke it: a class name rather than an instance.
     */
    public function testAStaticListenerIsNotified(): void
    {
        EventDispatchShapeSpy::$ranStatic = false;

        $d = $this->dispatch();

        $d->attach( 'shape.static', array( 'EventDispatchShapeSpy', 'handleStatic' ) );

        $d->notify( $this->event( 'shape.static' ) );

        $this->assertTrue( EventDispatchShapeSpy::$ranStatic,
            'a statically registered handler must be notified, not crash the dispatch' );
    }

    /**
     * And one handler's shape must not decide whether the OTHERS run.
     *
     * This is what made the failure severe rather than cosmetic: the TypeError
     * escaped notify(), so a single statically registered handler stopped every
     * handler after it -- session creation included.
     */
    public function testOneListenerShapeDoesNotStopTheRest(): void
    {
        EventDispatchShapeSpy::$ran       = false;
        EventDispatchShapeSpy::$ranStatic = false;

        $d = $this->dispatch();

        $d->attach( 'shape.mixed', array( 'EventDispatchShapeSpy', 'handleStatic' ) );
        $d->attach( 'shape.mixed', array( new EventDispatchShapeSpy(), 'handle' ) );

        $d->notify( $this->event( 'shape.mixed' ) );

        $this->assertTrue( EventDispatchShapeSpy::$ranStatic, 'the static handler did not run' );
        $this->assertTrue( EventDispatchShapeSpy::$ran,
            'the handler registered AFTER the static one did not run, so the dispatch '
            . 'was stopped by the shape of an earlier listener' );
    }
}

class EventDispatchShapeSpy
{
    public static $ran = false;
    public static $ranStatic = false;

    public function handle( $event )
    {
        self::$ran = true;

        return OWA_EHS_EVENT_HANDLED;
    }

    public static function handleStatic( $event )
    {
        self::$ranStatic = true;

        return OWA_EHS_EVENT_HANDLED;
    }
}
