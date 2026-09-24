<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every old-beacon bridge is indexed, and every indexed bridge still exists.
 *
 * WHY AN INDEX AND NOT A REGISTRY. The bridges were spread over seven
 * mechanisms -- a JSON registry, a const map, callbacks, open-coded `||`
 * fallbacks, encoding coercions, a URL chain, and one that was dead and
 * unnoticed. Consolidating them in one move would mean changing how ingest
 * resolves values on live traffic, all at once, with no way to tell whether the
 * set was complete to begin with. So the set is enumerated FIRST, here, and
 * entries migrate to being applied from the index one at a time.
 *
 * WHAT MAKES IT TRUSTWORTHY is that it is checked in BOTH directions. A bridge
 * added to the code without an entry fails; an entry whose code has gone fails.
 * Without the second half an index is a comment that rots, which is what the
 * dead `translateKeys()` was.
 *
 * NOT A RUNTIME CONTRACT. Nothing looks a beacon up in here. Whether a single
 * beacon can become a row is `row()`'s identity guard, which knows nothing of
 * versions and is tested with it.
 */
final class BeaconCompatInventoryTest extends TestCase
{
    private function index(): array
    {
        return (array) include OWA_DIR . 'conf/beacon_compat.php';
    }

    /** @return array<string,array> every declared tracking property, by name */
    private function properties(): array
    {
        $declared = json_decode( (string) file_get_contents(
            OWA_DIR . 'modules/Base/config/tracking_properties.json' ), true );

        $flat = array();

        foreach ( (array) $declared as $scope => $properties ) {

            foreach ( (array) $properties as $name => $property ) {

                $flat[ $name ] = $property;
            }
        }

        return $flat;
    }

    /** @return array<string,string> the indexed renames, old => new */
    private function indexedRenames(): array
    {
        $out = array();

        foreach ( (array) $this->index()['indexed'] as $entry ) {

            if ( $entry['kind'] === 'rename' ) {

                $out[ $entry['from'] ] = $entry['to'];
            }
        }

        return $out;
    }

    /**
     * Every alternative_key in the registry is indexed here.
     *
     * This is the half that catches a bridge being ADDED without being
     * recorded -- the common case, because adding one is a single line in a
     * JSON file and nothing else prompts you.
     */
    public function testEveryRegistryRenameIsIndexed(): void
    {
        $actual = array();

        foreach ( $this->properties() as $name => $property ) {

            if ( ! empty( $property['alternative_key'] ) ) {

                $actual[ $property['alternative_key'] ] = $name;
            }
        }

        $this->assertNotEmpty( $actual,
            'no alternative_key found at all; this assertion would be vacuous' );

        ksort( $actual );

        $indexed = $this->indexedRenames();
        ksort( $indexed );

        $this->assertSame( $indexed, $actual,
            'conf/beacon_compat.php must list exactly the alternative_key renames '
          . 'the property registry declares -- in both directions.' );
    }

    /**
     * And every indexed bridge that lives in code is still in that code.
     *
     * The other half: a bridge removed without its entry going too. Matched on
     * a needle rather than by parsing, because what is being asserted is that
     * somebody looked -- a needle that stops matching is a prompt to check, and
     * that is the whole job.
     */
    public function testEveryIndexedCodeBridgeStillExists(): void
    {
        $checked = 0;

        foreach ( (array) $this->index()['indexed'] as $entry ) {

            if ( empty( $entry['needle'] ) ) {

                continue;
            }

            $path = OWA_DIR . $entry['in'];

            $this->assertFileExists( $path, $entry['in'] . ' is indexed as carrying a bridge' );

            $this->assertStringContainsString(
                $entry['needle'], (string) file_get_contents( $path ),
                sprintf( '%s no longer contains the %s bridge %s -> %s. If it was removed '
                       . 'deliberately, remove its entry from conf/beacon_compat.php too.',
                    $entry['in'], $entry['kind'], $entry['from'], $entry['to'] ) );

            $checked++;
        }

        $this->assertGreaterThan( 5, $checked,
            'too few code bridges checked; the index has probably lost its needles' );
    }

    /** The event-name renames are applied FROM the index, not from a const. */
    public function testEventNamesResolveThroughTheIndex(): void
    {
        $names = (array) $this->index()['event_names'];

        $this->assertNotEmpty( $names );

        foreach ( $names as $old => $new ) {

            $this->assertSame( $new, \OWA\Module\Base\Classes\V2Event::name( $old ),
                $old . ' must resolve through conf/beacon_compat.php' );
        }

        // A name already in the v2 vocabulary is untouched, so the mapping is
        // not swallowing everything.
        $this->assertSame( 'scroll', \OWA\Module\Base\Classes\V2Event::name( 'scroll' ) );
    }

    /**
     * Every indexed rename names a property the registry actually declares.
     *
     * A rename to a property that no longer exists is a bridge to nowhere --
     * the value arrives, resolves, and is dropped for want of a column.
     */
    public function testEveryIndexedRenameTargetsALiveProperty(): void
    {
        $properties = $this->properties();

        foreach ( $this->indexedRenames() as $from => $to ) {

            $this->assertArrayHasKey( $to, $properties,
                $from . ' is bridged to ' . $to . ', which the registry does not declare' );
        }
    }
}
