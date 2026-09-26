<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * Everything the tracker sends must be declared somewhere.
 *
 * An undeclared property is the pass-through: it reaches handlers with no
 * declared type, no default, and nothing the wire filter can have an opinion
 * about. That is how a request could set owa_is_browser=ludhiana, and how
 * campaign and ad rode the beacon for years without appearing in any map.
 *
 * The point of this test is that the enumeration cannot quietly fall behind
 * again. Add a property to the tracker and this fails until it is declared or
 * consciously added to the exceptions below with a reason.
 */
final class WireSurfaceEnumeratedTest extends TestCase
{
    /**
     * Names that ride the beacon but are deliberately NOT tracking properties.
     *
     * Empty, and that is the point: everything the tracker sends is declared.
     * An entry here needs a reason, and the test below checks the reason still
     * holds -- that the name is genuinely still sent and still undeclared --
     * so an exception cannot outlive what justified it.
     */
    private const NOT_TRACKING_PROPERTIES = array();

    /** @return array every name the tracker emits, across all event types */
    /** The wire this branch's tracker emits; OWATracker.BEACON_FORMAT_VERSION. */
    private const CURRENT_BEACON_FORMAT_VERSION = '2';

    private function emitted(): array
    {
        $contracts = json_decode(
            (string) file_get_contents( OWA_DIR . 'tests/fixtures/beacon_contracts.json' ),
            true );

        $this->assertIsArray( $contracts, 'the beacon contract fixture is unreadable' );

        /*
         * VERSION 2 ONLY. The registry is keyed by beacon format version and
         * each version is a standalone record of what that tracker emitted.
         * This asks what the CURRENT wire carries, so an older version's
         * properties are not its business -- they are the compat layer's, and
         * mixing them in is how a retired property looked like part of the
         * current vocabulary in the first place.
         */
        $current = (array) ( $contracts[ self::CURRENT_BEACON_FORMAT_VERSION ] ?? array() );

        $this->assertNotEmpty( $current,
            'no contract for beacon format version ' . self::CURRENT_BEACON_FORMAT_VERSION );

        $names = array();

        foreach ( $current as $event_type => $fields ) {

            if ( $event_type === '_comment' ) {

                continue;
            }

            $names = array_merge( $names, (array) $fields );
        }

        return array_values( array_unique( $names ) );
    }

    private function declared(): array
    {
        return array_merge(
            Helpers::requestProperties(),
            Helpers::clientProperties(),
            Helpers::serverProperties() );
    }

    public function testEveryPropertyOnTheWireIsDeclared(): void
    {
        $declared = $this->declared();

        $undeclared = array();

        /*
         * A BRIDGED NAME COUNTS AS DECLARED, and it has to.
         *
         * The registry is the v2 vocabulary: it declares what v2 CALLS things.
         * A name the tracker sends under an older or shorter spelling is declared
         * by its rename -- `nps` for num_prior_sessions, `page_url` for
         * page_location -- and Compat::apply() puts the current spelling on the
         * event before any reader. Requiring the registry to declare both would
         * put every compat spelling back into the v2 vocabulary, which is the
         * thing the rename map exists to keep out of it.
         *
         * The allowlist at log.php already reads it this way, through the same
         * method, so the two agree about what may arrive.
         */
        $bridged = \OWA\Module\Base\Classes\Beacon\Compat::bridgedNames();

        foreach ( $this->emitted() as $name ) {

            if ( isset( $declared[ $name ] )
                 || in_array( $name, $bridged, true )
                 || array_key_exists( $name, self::NOT_TRACKING_PROPERTIES ) ) {

                continue;
            }

            $undeclared[] = $name;
        }

        $this->assertSame(
            array(), $undeclared,
            "The tracker sends these and nothing declares them:\n  "
            . implode( "\n  ", $undeclared )
            . "\n\nDeclare them in modules/Base/config/tracking_properties.json, or add "
            . 'them to NOT_TRACKING_PROPERTIES with a reason.' );
    }

    /**
     * The exceptions are the part that rots: it is easy to silence a failure by
     * adding a name, and easy for one to outlive the tracker that sent it.
     */
    public function testNothingIsExceptedWithoutStillEarningIt(): void
    {
        $emitted  = $this->emitted();
        $declared = $this->declared();

        foreach ( self::NOT_TRACKING_PROPERTIES as $name => $reason ) {

            $this->assertNotEmpty( $reason, "$name is excepted without a reason." );

            $this->assertContains(
                $name, $emitted,
                "$name is excepted but the tracker no longer sends it -- drop the exception." );

            $this->assertArrayNotHasKey(
                $name, $declared,
                "$name is declared now, so it should not also be an exception." );
        }

        /*
         * Stated rather than left implicit, so the empty list reads as a
         * result and not as a loop nobody noticed does nothing. event_type was
         * the last entry: it is a property like any other -- the tracker sends
         * it, the queue table already stores it, and v2 logs it on the fact
         * row.
         */
        $this->assertSame(
            array(), self::NOT_TRACKING_PROPERTIES,
            'Every property the tracker sends is declared. Adding an exception '
            . 'needs a reason that survives the checks above.' );
    }

    /**
     * A floor, so the test cannot pass by reading an empty fixture or an empty
     * config and finding nothing to complain about.
     */
    public function testBothSidesAreActuallyPopulated(): void
    {
        /*
         * 40 now: the wire lost landing_url, session_referer, the three
         * dom_element_* the click no longer collects and the purchase's three
         * billing-address fields, each because nothing read it. The guard is
         * against the FIXTURE not being read, so it tracks the wire down rather
         * than pinning a number the wire is supposed to be able to shrink.
         */
        $this->assertGreaterThan( 35, count( $this->emitted() ),
            'Far fewer beacon fields than expected -- the fixture is not being read.' );

        /*
         * The floor was 100 while the dead ingest derivations were still
         * declared: the five cube-pass readings, the v1 handler inputs
         * (page_uri, full_host, is_browser, is_robot, latitude, longitude,
         * prior_page), the v1 date parts and the cv halves. It came down to 85
         * when those went.
         *
         * Then eight more: the registry holds only what v2 CALLS things, so the
         * compat spellings left it -- page_url and nps for the two renames, and
         * page_type, ad_type, tagged_ad_type, feed_subscription_id,
         * time_since_last_session and browser, none of which any v2 reader or
         * dimension touches. 80 is the current vocabulary and the guard still
         * catches the config not being read.
         *
         * Then 84, when landing_url and session_referer came off the wire: the
         * tags are parsed from page_location on the session-starting beacon, which
         * is the same URL, and the session's referrer is the referer_host of its
         * first row.
         */
        $this->assertGreaterThan( 70, count( $this->declared() ),
            'Far fewer declared properties than expected -- the config is not being read.' );
    }

    /** The slot itself must not survive the split, or it would ride on as junk. */
    public function testTheRawSlotIsConsumedBySplitting(): void
    {
        $helpers = new Helpers();
        $event   = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        $event->setProperties( array( 'cv1' => 'Color=Blue Widget' ) );

        $helpers->translateCustomVariables( $event );

        $this->assertSame( 'Color', $event->get( 'cv1_name' ) );
        $this->assertSame( 'Blue Widget', $event->get( 'cv1_value' ) );
        $this->assertFalse( $event->get( 'cv1' ), 'the raw slot should be gone' );
    }
    /**
     * A custom variable is a claim the server unpacks, not two values a request
     * may assert.
     *
     * cv{n}_name and cv{n}_value are produced by splitting the cv{n} slot the
     * tracker sent, so they are server scope. While they were merged into the
     * regular map instead, a request could post owa_cv1_name directly: it
     * survived the wire filter, and ProcessEvent's sanitized-properties step
     * then re-applied it OVER the value the split had just produced -- the same
     * shape as the is_browser defect.
     */
    public function testTheSplitHalvesCannotBeSetFromTheWire(): void
    {
        $kept = Helpers::rejectServerOwnedParams( array(
            'cv1'       => 'Color=Blue',
            'cv1_name'  => 'forged',
            'cv1_value' => 'forged',
            'cv5_name'  => 'forged',
        ) );

        $this->assertSame(
            array( 'cv1' => 'Color=Blue' ), $kept,
            'A request set a custom variable half directly, bypassing the split.' );
    }

    public function testTheSlotItselfRemainsSettable(): void
    {
        /* The filter must not overreach: the slot IS what the tracker sends. */
        $slots = array( 'cv1' => 'a=1', 'cv2' => 'b=2', 'cv3' => 'c=3',
                        'cv4' => 'd=4', 'cv5' => 'e=5' );

        $this->assertSame(
            $slots, Helpers::rejectServerOwnedParams( $slots ),
            'The filter rejected the slots the tracker actually sends.' );
    }
}
