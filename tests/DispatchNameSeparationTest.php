<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * The dispatch key and the event's NAME are separate, and internal events keep
 * routing on their own name.
 *
 * WHAT THIS PROTECTS. Tracking events dispatch under `tracking.`, set once by
 * CoreAPI::logEvent(). Everything OWA raises internally -- install_complete,
 * base.reset_password, the announcements ingest raises -- goes straight to
 * EventDispatch::notify() without passing through logEvent, so it has no dispatch
 * name and must route on its event type, as it always has.
 *
 * That fallback is the whole compatibility story for every non-tracking event, and
 * nothing tested it. Two attempts at this change broke exactly those events:
 * inferring "is this a tracking event" from the NAME matches install_complete, and
 * Module::installCompleteHandler was replaced by ingest.
 *
 * THE CONTRADICTORY PROPERTY IS THE REASON THE KEY IS NOT IN THE BAG.
 * EventRawHandlers::announce() copies the beacon's properties onto a fresh notice,
 * `event_type` included -- so a base.new_session notice carries an event_type
 * property reading "page_view". getEventType() prefers the field, so routing is
 * right; anything reading the name out of the property bag instead would route a
 * session announcement as a page view. Pinned here so the trap is visible.
 */
final class DispatchNameSeparationTest extends TestCase
{
    private function event( string $type )
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setEventType( $type );

        return $event;
    }

    /** An event nothing marked routes on its own type. */
    public function testAnInternalEventRoutesOnItsOwnName(): void
    {
        foreach ( array( 'install_complete', 'base.reset_password', 'init' ) as $type ) {

            $event = $this->event( $type );

            $this->assertFalse( $event->isTrackingEvent(),
                $type . ' did not come from a tracker and must not claim to' );

            $this->assertSame( $type, $event->getDispatchName(),
                $type . ' must route on its own name' );
        }
    }

    /** And its handler is still the one registered for it. */
    public function testTheInternalHandlerIsStillReached(): void
    {
        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();

        $listeners = $dispatch->listenersFor(
            $this->event( 'install_complete' )->getDispatchName() );

        $this->assertNotEmpty( $listeners,
            'install_complete reaches no listener at all' );

        $ingest = $dispatch->listenersFor( \OWA\Core\CoreAPI::anyTrackingEvent() );

        $this->assertNotEmpty( $ingest, 'nothing is registered for the tracking namespace' );

        $this->assertSame( array(), array_intersect( $listeners, $ingest ),
            'an internal event is reaching the tracking handlers' );
    }

    /** A marked event routes under the namespace, and says so. */
    public function testATrackingEventRoutesUnderTheNamespace(): void
    {
        $event = $this->event( 'page_view' );
        $event->setDispatchName( \OWA\Core\CoreAPI::TRACKING_DISPATCH_NAMESPACE . '.page_view' );

        $this->assertTrue( $event->isTrackingEvent() );
        $this->assertSame( 'tracking.page_view', $event->getDispatchName() );

        // A site-named event routes the same way; nothing enumerates it.
        $custom = $this->event( 'my_site_signup' );
        $custom->setDispatchName( \OWA\Core\CoreAPI::TRACKING_DISPATCH_NAMESPACE . '.my_site_signup' );

        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();

        $this->assertNotEmpty( $dispatch->listenersFor( $custom->getDispatchName() ),
            'a site-named event reaches no handler' );
    }

    /**
     * The announcement trap: a fresh notice carrying a beacon's event_type
     * property still routes by its own type.
     */
    public function testAnAnnouncementRoutesByItsTypeNotItsCopiedProperty(): void
    {
        $notice = \OWA\Core\CoreAPI::getEventDispatch()->makeEvent( 'base.new_session' );

        // exactly what announce() does
        $notice->setProperties( array( 'event_type' => 'page_view', 'site_id' => 'x' ) );

        $this->assertSame( 'page_view', $notice->get( 'event_type' ),
            'the contradictory property is really there -- that is the trap' );

        $this->assertSame( 'base.new_session', $notice->getEventType() );
        $this->assertSame( 'base.new_session', $notice->getDispatchName() );
        $this->assertFalse( $notice->isTrackingEvent() );
    }

    /**
     * A NON-TRACKING EVENT DOES NOT INHERIT ITS TYPE FROM COPIED PROPERTIES.
     *
     * The convention is fine for a tracking event: the beacon carries event_type,
     * the endpoint admits it, and owa_event_raw.event_type stores it, so the bag
     * legitimately holds it and the registry declares it.
     *
     * It is wrong for everything else, and announce() is how that happened -- it
     * copies a beacon's whole property bag onto a fresh notice, event_type
     * included. With getEventType() falling back to the bag, an event whose member
     * was never set would take its identity from whatever beacon it inherited from.
     *
     * Asserted on an event that has ONLY the property, which is the case the
     * fallback used to answer and now must not.
     */
    public function testATypeInThePropertyBagDoesNotNameTheEvent(): void
    {
        $inherited = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        // Never setEventType(): only the copied property, as announce() leaves it.
        $inherited->setProperties( array( 'event_type' => 'page_view', 'site_id' => 'x' ) );

        $this->assertSame( 'page_view', $inherited->get( 'event_type' ),
            'the property is there -- that is the whole point' );

        $this->assertNotSame( 'page_view', $inherited->getEventType(),
            'the bag must not name the event; that is how a session announcement '
            . 'came to route as a page view' );

        $this->assertSame( 'unknown_event_type', $inherited->getEventType(),
            'an event nobody named says so, rather than borrowing a name' );

        $this->assertFalse( $inherited->isTrackingEvent() );
    }

    /**
     * And a tracking event keeps carrying it as DATA, because the column records it.
     */
    public function testATrackingEventStillCarriesTypeAsAProperty(): void
    {
        $declared = \OWA\Module\Base\Classes\TrackingEventHelpers::allProperties();

        $this->assertArrayHasKey( 'event_type', $declared,
            'event_type is a real tracking property: the beacon sends it and '
            . 'owa_event_raw.event_type stores it' );

        $this->assertSame( 'event_type',
            \OWA\Module\Base\Classes\TrackingEventHelpers::columnFor( 'event_type' ) );
    }

    /**
     * The dispatch key is NOT in the property bag.
     *
     * The bag is the wire surface: admitRequestParams() vets it and params() may
     * store what it holds. OWA's routing state in there would be a property a
     * beacon could claim and a value params() could write to a row.
     */
    public function testTheDispatchKeyIsNotAProperty(): void
    {
        $event = $this->event( 'page_view' );
        $event->setDispatchName( 'tracking.page_view' );

        $properties = (array) $event->getProperties();

        foreach ( array( 'dispatch_name', 'dispatchName', 'event_name' ) as $key ) {

            $this->assertArrayNotHasKey( $key, $properties,
                $key . ' must not be in the property bag' );
        }

        $this->assertNotContains( 'tracking.page_view', $properties,
            'the dispatch key leaked into the wire surface' );
    }

    /**
     * NOTHING REGISTERS FOR A TRACKING EVENT UNDER ITS BARE NAME.
     *
     * The failure this catches, which cost a red isolation sweep: an ingestion
     * test attached its fallback handler to the bare event name while the module
     * attached to `tracking.<name>`. listenersFor() walks the dotted segments of
     * the DISPATCH name and never reaches the bare one, so the handler was
     * registered and unreachable.
     *
     * It was invisible wherever the module is ON, because the fallback is skipped
     * there. It only failed on a fresh install, which is the isolation sweep: the
     * one CI job that installs from scratch AND has a database, since the unit jobs
     * are configless and skip every test that needs one.
     *
     * READS THE SOURCE, because the bug is a registration that never fires and a
     * runtime assertion would need something to fire it. Same shape as
     * ServerOwnedPropertyTest::testLogPhpUsesTheFilter.
     */
    public function testNoRegistrationUsesABareTrackingEventName(): void
    {
        $names = \OWA\Core\CoreAPI::trackingEventTypes();

        $this->assertContains( 'page_view', $names,
            'the registered names were not read -- this asserts nothing without them' );

        $offenders = array();

        $files = array_merge(
            (array) glob( OWA_DIR . 'modules/*/Module.php' ),
            (array) glob( OWA_DIR . 'modules/*/Classes/*.php' ),
            (array) glob( __DIR__ . '/*Test.php' ) );

        foreach ( $files as $file ) {

            $source = (string) file_get_contents( $file );

            if ( ! preg_match_all(
                    "/(?:attach|registerEventHandler)\(\s*'([^']+)'/", $source, $found ) ) {

                continue;
            }

            foreach ( $found[1] as $key ) {

                if ( in_array( $key, $names, true ) ) {

                    $offenders[] = basename( $file ) . " => '$key'";
                }
            }
        }

        $this->assertSame( array(), $offenders,
            "A tracking event is dispatched under the `tracking.` namespace, so a "
          . "handler registered under its bare name is never reached:\n  "
          . implode( "\n  ", $offenders )
          . "\nUse CoreAPI::TRACKING_DISPATCH_NAMESPACE . '.<name>', or "
          . "CoreAPI::anyTrackingEvent() for all of them." );
    }
}
