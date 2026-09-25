<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Beacon\Compat;

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

    /** @return array<string,string> the renames the layer applies, old => new */
    private function renames(): array
    {
        $out = array();

        foreach ( (array) $this->index()['renames'] as $entry ) {

            $out[ $entry['from'] ] = $entry['to'];
        }

        return $out;
    }

    /**
     * THE REGISTRY CARRIES NO RENAMES AT ALL any more.
     *
     * `alternative_key` did this per property, inside the property loop, gated
     * on the canonical value being FALSY -- so it could not tell "absent" from
     * "present and false", needed 0 and "0" carved out by hand, and could never
     * be used for a boolean. Classes\Beacon\Compat does it once, up front, on
     * presence.
     *
     * Asserted as an absence because the mechanism is what was removed. A
     * declaration creeping back would be a second place renames happen, which
     * is the thing this whole exercise was about.
     */
    public function testTheRegistryDeclaresNoRenames(): void
    {
        $offenders = array();

        foreach ( $this->properties() as $name => $property ) {

            if ( ! empty( $property['alternative_key'] ) ) {

                $offenders[] = $name;
            }
        }

        $this->assertSame( array(), $offenders,
            'alternative_key is gone; a rename belongs in conf/beacon_compat.php '
          . 'so that every one of them is in a single place.' );
    }

    /** And the layer actually applies them. */
    public function testTheLayerAppliesARename(): void
    {
        $renames = $this->renames();

        $this->assertNotEmpty( $renames, 'no renames declared; this would be vacuous' );

        $event = new \OWA\Module\Base\Classes\Event;
        $event->set( 'nps', 4 );

        \OWA\Module\Base\Classes\Beacon\Compat::apply( $event );

        $this->assertSame( 4, $event->get( 'num_prior_sessions' ),
            'the current spelling must be set from the old one' );
    }

    /**
     * PRESENCE, NOT TRUTHINESS -- the substantive difference from what it
     * replaced, and the reason a boolean can use this mechanism.
     */
    public function testAFalseCanonicalIsNotTreatedAsAbsent(): void
    {
        $event = new \OWA\Module\Base\Classes\Event;
        $event->set( 'num_prior_sessions', 0 );
        $event->set( 'nps', 9 );

        \OWA\Module\Base\Classes\Beacon\Compat::apply( $event );

        $this->assertSame( 0, $event->get( 'num_prior_sessions' ),
            'a canonical value of 0 is a value; the old spelling must not overwrite it' );
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

        /*
         * Four now, down from eight: the renames and the URL chain moved into
         * the layer, and the flag fallback was removed with the flag. What is
         * left is the two value-encoding coercions and the two callbacks that
         * return an older tracker's value unchanged.
         *
         * A floor rather than an exact count, so moving one more INTO the layer
         * does not fail this -- but dropping the needles silently does.
         */
        $this->assertGreaterThanOrEqual( 4, $checked,
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

        foreach ( $this->renames() as $from => $to ) {

            $this->assertArrayHasKey( $to, $properties,
                $from . ' is bridged to ' . $to . ', which the registry does not declare' );
        }
    }

    /**
     * The shape of what the compat layer contributes.
     *
     * The cv{n} slots are declared HERE and nowhere else: the current tracker
     * emits no cv key at all, so they are not part of the current vocabulary
     * and do not belong in tracking_properties.json. How many there are is the
     * maxCustomVars setting rather than a constant -- FactTable builds its cv
     * columns from the same setting -- and a definition already present is
     * left alone, so a caller's own map is never overwritten.
     */
    public function testTheGeneratedCustomVariablePropertiesKeepTheirShape(): void
    {
        $max     = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );

        $this->assertGreaterThan( 0, $max, 'maxCustomVars is what bounds the loop.' );

        $generated = Compat::contributeDerivedProperties( array() );

        $this->assertCount(
            $max * 2, $generated,
            'Each slot needs a name and a value property.' );

        /* The config is authoritative: a declared slot is left exactly alone. */
        $declared = array( 'cv1_name' => array( 'required' => 'untouched' ) );

        $this->assertSame(
            array( 'required' => 'untouched' ),
            Compat::contributeDerivedProperties( $declared )['cv1_name'],
            'The top-up overwrote a definition the config had already made.' );

        for ( $slot = 1; $slot <= $max; $slot++ ) {

            foreach ( array( 'name', 'value' ) as $half ) {

                $property = $generated[ "cv{$slot}_{$half}" ] ?? null;

                $this->assertIsArray( $property, "cv{$slot}_{$half} is not generated." );

                $this->assertTrue( $property['required'] );
                $this->assertSame( 'string', $property['data_type'] );
                $this->assertSame( '(not set)', $property['default_value'],
                    'An unset slot must read as (not set), not as empty.' );
                $this->assertContains(
                    'owa_trackingEventHelpers::lowercaseString', $property['callbacks'],
                    "cv{$slot}_{$half} is lowercased so the same variable does not "
                    . 'split into two dimensions by case.' );
            }
        }
    }
}
