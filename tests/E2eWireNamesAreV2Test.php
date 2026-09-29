<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * No e2e spec asserts a v1 event name on the wire.
 *
 * WHY THIS EXISTS. The v2 tracker stopped emitting v1 event names in 4b93b248 --
 * it sends `event_type=page_view` and `event_type=click`, and conf/beacon_compat
 * .php became the v1 -> v2 mapping for beacons from older trackers rather than a
 * translation the live path depends on.
 *
 * Three e2e specs went on matching the OLD names, and the way they failed is the
 * point. session-lifecycle.spec.js waits for a beacon before it reads the
 * database, so every one of its four tests timed out after 20 seconds with
 * "Expected: > 0, Received: 0" -- which reads as "the server stored nothing", not
 * as "the regex never matched". Two full afternoons of this branch's e2e failures
 * were attributed to ingest and the fixture layer on that evidence. It was the
 * pattern.
 *
 * Eight failures across three files, one cause, and nothing connected the specs'
 * wire assertions to the contract the tracker actually emits -- tests/fixtures/
 * beacon_contracts.json records that per beacon version, and the jest suite holds
 * the tracker to it, but a Playwright spec asserting a name is just a string.
 *
 * So this reads the contract and refuses a spec that names anything outside it.
 * A grep would do today; reading the contract means a future rename is caught by
 * the same test instead of needing this list edited.
 */
final class E2eWireNamesAreV2Test extends TestCase
{
    /** Where a v1 name may still legitimately appear in a spec. */
    private const PROSE = '#(^\s*(//|\*|/\*))#';

    /** The current beacon format version, whose names are the live vocabulary. */
    private const CURRENT = '2';

    /** @return string[] event_type values the current tracker emits */
    private function liveEventNames(): array
    {
        $contracts = json_decode( (string) file_get_contents(
            OWA_DIR . 'tests/fixtures/beacon_contracts.json' ), true );

        $this->assertArrayHasKey( self::CURRENT, (array) $contracts,
            'the beacon contract has no entry for the current format version' );

        /*
         * The SHAPE names, not a stored event_type value: a contract is keyed by
         * the event it describes, and a variant is suffixed -- page_view.campaign
         * is a page_view. So the base name before the first dot is the vocabulary.
         */
        $names = array();

        foreach ( array_keys( (array) $contracts[ self::CURRENT ] ) as $shape ) {

            $names[ strtok( (string) $shape, '.' ) ] = true;
        }

        // Raised by the server from flags on a page view; no browser sends them,
        // so they are not in the contract and no spec should wait for one.
        unset( $names[ \OWA\Module\Base\Classes\V2Event::MARKER_SESSION_START ],
               $names[ \OWA\Module\Base\Classes\V2Event::MARKER_FIRST_VISIT ] );

        $this->assertNotEmpty( $names );

        return array_keys( $names );
    }

    /** @return string[] the v1 spellings, read from the compat index */
    private function retiredEventNames(): array
    {
        $compat = require OWA_DIR . 'conf/beacon_compat.php';

        $retired = array_keys( (array) ( $compat['event_names'] ?? array() ) );

        $this->assertNotEmpty( $retired,
            'the compat index lists no event-name renames; this test would pass vacuously' );

        return $retired;
    }

    public function testNoSpecWaitsForARetiredEventName(): void
    {
        $retired = $this->retiredEventNames();
        $live    = $this->liveEventNames();

        $offenders = array();
        $scanned   = 0;

        foreach ( (array) glob( OWA_DIR . 'tests/e2e/*.spec.js' ) as $file ) {

            $scanned++;

            foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $n => $line ) {

                // Prose may still name the old spelling; an assertion may not.
                if ( preg_match( self::PROSE, $line ) ) {

                    continue;
                }

                foreach ( $retired as $name ) {

                    /*
                     * Only where it is being matched AS a wire value. The dispatch
                     * name is still v1 -- EventRawHandlers is registered on
                     * base.page_request -- so a PHP fixture calling
                     * logEvent('base.page_request') is correct and is not what this
                     * looks at.
                     */
                    $pattern = '#event_type=' . preg_quote( $name, '#' )
                             . '|awaitBeacon\([^,]+,\s*[\'"]' . preg_quote( $name, '#' ) . '[\'"]#';

                    if ( preg_match( str_replace( '\\.', '\\\\?\\.', $pattern ), $line ) ) {

                        $offenders[] = sprintf( '%s:%d  %s -> expected one of %s',
                            basename( $file ), $n + 1, $name, implode( ', ', $live ) );
                    }
                }
            }
        }

        $this->assertGreaterThan( 10, $scanned,
            'almost no specs were scanned, so this test is not reading them' );

        $this->assertSame( array(), $offenders,
            "An e2e spec is waiting for an event name the tracker no longer sends. It will "
            . "time out and report that the server stored nothing:\n  "
            . implode( "\n  ", $offenders ) );
    }

    /**
     * And the names they DO wait for are ones the contract carries.
     *
     * The inverse, so a typo fails here rather than as a 20-second timeout.
     */
    public function testEveryAwaitedNameIsInTheContract(): void
    {
        $live    = $this->liveEventNames();
        $unknown = array();

        foreach ( (array) glob( OWA_DIR . 'tests/e2e/*.spec.js' ) as $file ) {

            $source = (string) file_get_contents( $file );

            preg_match_all( '#awaitBeacon\([^,]+,\s*[\'"]([a-z_.]+)[\'"]#', $source, $found );

            foreach ( (array) $found[1] as $name ) {

                if ( ! in_array( $name, $live, true ) ) {

                    $unknown[] = basename( $file ) . ': ' . $name;
                }
            }
        }

        $this->assertSame( array(), $unknown,
            "A spec waits for an event name no beacon contract carries:\n  "
            . implode( "\n  ", $unknown ) );
    }
}
