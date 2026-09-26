<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The tracker's reserved event names are the server's first-class ones.
 *
 * WHAT MAKES A NAME FIRST-CLASS is that some property in the registry declares
 * it -- CoreAPI::trackingEventTypes() derives the set rather than listing it, so
 * the vocabulary and the gate cannot drift. The TRACKER has to know the same set
 * for a different reason: trackCustomEvent() must refuse a site's event that
 * would collide with one of OWA's own, because once a beacon arrives the server
 * cannot tell a site's `click` from its own.
 *
 * GA does exactly this, and its reserved list IS its first-class list -- page_view,
 * click, scroll, file_download, form_start, form_submit, session_start,
 * first_visit, user_engagement, view_search_results. Reusing one puts a site's
 * counts into a report measuring something else.
 *
 * THE TWO LISTS CANNOT BE DERIVED FROM EACH OTHER: one is PHP reading a JSON file
 * at runtime, the other is a constant compiled into a bundle served from a CDN.
 * So they are kept in step by this test, which fails the moment a first-class
 * event is added on the server and not in the tracker -- the only direction that
 * can go wrong silently, because the server would then accept a site's event
 * under a name it has just claimed.
 *
 * Read from the tracker SOURCE rather than a built bundle: the bundle is a build
 * artifact and may be stale, which is its own problem and not one this should
 * depend on.
 */
final class TrackerReservedEventNamesTest extends TestCase
{
    /** @return string[] the names OWATracker refuses for a custom event */
    private function trackerReserved(): array
    {
        $source = (string) file_get_contents(
            OWA_DIR . 'modules/Base/src/tracker/Tracker.js' );

        $open = strpos( $source, 'static get RESERVED_EVENT_NAMES()' );

        $this->assertNotFalse( $open,
            'OWATracker.RESERVED_EVENT_NAMES is gone; this test is stale.' );

        $body = substr( $source, $open, strpos( $source, '];', $open ) - $open );

        preg_match_all( "/'([a-z_]+)'/", $body, $found );

        $names = (array) $found[1];

        $this->assertNotEmpty( $names, 'the reserved list parsed empty' );

        sort( $names );

        return $names;
    }

    /** @return string[] every event name the property registry declares */
    private function serverFirstClass(): array
    {
        $names = Helpers::eventNames();

        $this->assertNotEmpty( $names,
            'the registry declares no event names; this test would pass vacuously' );

        sort( $names );

        return $names;
    }

    public function testTheTwoListsAgree(): void
    {
        $this->assertSame( $this->serverFirstClass(), $this->trackerReserved(),
            "The tracker's reserved event names and the server's first-class ones have "
            . 'drifted. A name the server treats as first-class but the tracker does not '
            . "reserve can be taken by a site's own custom event." );
    }

    /**
     * And the markers are in it, though no browser sends them.
     *
     * session_start and first_visit are materialised by the server from flags on a
     * page view, so they never arrive on a beacon -- but they ARE event_type values
     * on stored rows, so a custom event using one would be indistinguishable from
     * the real thing in every report.
     */
    public function testTheMarkersAreReserved(): void
    {
        $reserved = $this->trackerReserved();

        foreach ( array( \OWA\Module\Base\Classes\V2Event::MARKER_SESSION_START,
                         \OWA\Module\Base\Classes\V2Event::MARKER_FIRST_VISIT ) as $marker ) {

            $this->assertContains( $marker, $reserved,
                $marker . ' is a stored event type and must be reserved' );
        }
    }

    /**
     * A reserved name is refused as a custom event; a legal one is admitted.
     *
     * Asserted through the ENDPOINT's rule, not a restatement of it, so this
     * cannot agree with a gate that would reject the name.
     */
    public function testTheServerAdmitsCustomNamesButNotNonsense(): void
    {
        foreach ( array( 'my_site_signup', 'newsletter_opt_in', 'A', 'a_b_9' ) as $legal ) {

            $this->assertTrue( owa_coreAPI::isTrackingEventType( $legal ),
                $legal . ' is a legal custom event name and must be admitted' );
        }

        foreach ( array(
            '',                 // nothing
            '_leading',         // GA reserves a leading underscore
            '9starts_numeric',  // must start with a letter
            'has space',
            'has-hyphen',
            'gtag.config',      // a dot is not legal, which excludes v1 spellings
            str_repeat( 'a', 41 ),
        ) as $illegal ) {

            $this->assertFalse( owa_coreAPI::isTrackingEventType( $illegal ),
                var_export( $illegal, true ) . ' must not be admitted as an event name' );
        }

        // 40 is the cap, so 40 passes and 41 does not.
        $this->assertTrue( owa_coreAPI::isTrackingEventType( str_repeat( 'a', 40 ) ) );
    }
}
