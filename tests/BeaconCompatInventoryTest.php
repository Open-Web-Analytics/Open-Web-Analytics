<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Beacon\Compat;

/**
 * Every old-beacon bridge is indexed, and every indexed bridge still exists.
 *
 * WHY AN INDEX AND NOT A REGISTRY. The bridges are applied by more than one
 * mechanism; an index enumerates them all, and shows the set is complete,
 * without changing how ingest resolves values on live traffic.
 *
 * WHAT MAKES IT TRUSTWORTHY is that it is checked in BOTH directions. A bridge
 * added to the code without an entry fails; an entry whose code has gone fails.
 * Without the second half an index is a comment that rots.
 *
 * NOT A RUNTIME CONTRACT. Nothing looks a beacon up in here. Whether a single
 * beacon can become a row is `row()`'s identity guard, which knows nothing of
 * versions and is tested with it.
 */
final class BeaconCompatInventoryTest extends TestCase
{
    private function index(): array
    {
        return (array) include \OWA\Core\Env::root() . '/conf/beacon_compat.php';
    }

    /** @return array<string,array> every declared tracking property, by name */
    private function properties(): array
    {
        $declared = json_decode( (string) file_get_contents(
            \OWA\Core\Env::root() . '/modules/Base/config/tracking_properties.json' ), true );

        // The file is flat: every entry declares `set_by` rather than sitting in
        // a group that implied it.
        $flat = (array) $declared;

        return $flat;
    }

    /** @return array<string,string> the renames the layer applies, old => new */
    private function renames(): array
    {
        $out = [];

        foreach ( (array) $this->index()['renames'] as $entry ) {

            $out[ $entry['from'] ] = $entry['to'];
        }

        return $out;
    }

    /**
     * THE REGISTRY CARRIES NO RENAMES.
     *
     * Classes\Beacon\Compat does every rename once, up front, on presence. A
     * rename inside the property loop, gated on the canonical value being FALSY,
     * cannot tell "absent" from "present and false" and cannot serve a boolean.
     *
     * Asserted as an absence: a declaration creeping back would be a second place
     * renames happen.
     */
    public function testTheRegistryDeclaresNoRenames(): void
    {
        $offenders = [];

        foreach ( $this->properties() as $name => $property ) {

            if ( ! empty( $property['alternative_key'] ) ) {

                $offenders[] = $name;
            }
        }

        $this->assertSame( [], $offenders,
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

            $path = \OWA\Core\Env::root() . '/' . $entry['in'];

            $this->assertFileExists( $path, $entry['in'] . ' is indexed as carrying a bridge' );

            $this->assertStringContainsString(
                $entry['needle'], (string) file_get_contents( $path ),
                sprintf( '%s no longer contains the %s bridge %s -> %s. If it was removed '
                       . 'deliberately, remove its entry from conf/beacon_compat.php too.',
                    $entry['in'], $entry['kind'], $entry['from'], $entry['to'] ) );

            $checked++;
        }

        /*
         * TWO: the value-encoding coercions, where a value counting from zero
         * arrived as a string from one generation and a number from the next. Those
         * are the entries a falsy check would misread, so they are the ones most
         * worth holding.
         *
         * A floor rather than an exact count, so moving one more INTO the layer
         * does not fail this -- but dropping the needles silently does.
         */
        $this->assertGreaterThanOrEqual( 2, $checked,
            'too few code bridges checked; the index has probably lost its needles' );
    }

    /** The event-name renames are applied FROM the index, not from a const. */
    public function testEventNamesResolveThroughTheIndex(): void
    {
        $names = (array) $this->index()['event_names'];

        $this->assertNotEmpty( $names );

        foreach ( $names as $old => $new ) {

            $this->assertSame( $new, \OWA\Module\Base\Classes\Beacon\Compat::eventName( $old ),
                $old . ' must resolve through conf/beacon_compat.php' );
        }

        // A name already in the v2 vocabulary is untouched, so the mapping is
        // not swallowing everything.
        $this->assertSame( 'scroll', \OWA\Module\Base\Classes\Beacon\Compat::eventName( 'scroll' ) );
    }

    /**
     * Every indexed rename names something the endpoint will actually admit.
     *
     * A rename to a name nothing accepts is a bridge to nowhere -- the value
     * arrives, resolves, and is dropped.
     *
     * TWO KINDS OF TARGET: a declared property, or a CUSTOM-PREFIX key.
     * user_name and user_email are custom user properties (PLAN.html §2.26.1), so
     * their bridges point at up_user_name and up_user_email, which no registry
     * entry declares and admitRequestParams() admits by prefix.
     *
     * Checked against the same two rules the endpoint uses -- the registry, then
     * CUSTOM_PREFIXES plus CUSTOM_NAME_PATTERN -- rather than a second list here,
     * so a bridge this test accepts is one the gate accepts.
     */
    public function testEveryIndexedRenameTargetsSomethingAdmissible(): void
    {
        $properties = $this->properties();

        foreach ( $this->renames() as $from => $to ) {

            if ( array_key_exists( $to, $properties ) ) {

                continue;
            }

            $this->assertTrue( $this->isAdmissibleCustomKey( $to ),
                $from . ' is bridged to ' . $to . ', which is neither a declared '
                . 'property nor a legal custom-prefix key' );
        }
    }

    /** The endpoint's own rule for a custom key, asked of it rather than restated. */
    private function isAdmissibleCustomKey( string $name ): bool
    {
        $helpers = \OWA\Module\Base\Classes\TrackingEventHelpers::class;

        foreach ( $helpers::CUSTOM_PREFIXES as $prefix ) {

            if ( strpos( $name, $prefix ) !== 0 ) {

                continue;
            }

            return (bool) preg_match( $helpers::CUSTOM_NAME_PATTERN,
                substr( $name, strlen( $prefix ) ) );
        }

        return false;
    }

    /**
     * And a bridge onto the custom namespace really is admitted.
     *
     * The rule above is read off the endpoint's constants; this drives the
     * endpoint itself, so the two cannot agree about a name the gate rejects.
     */
    public function testACustomPrefixBridgeIsAdmitted(): void
    {
        $bridged = [];

        foreach ( $this->renames() as $from => $to ) {

            if ( ! array_key_exists( $to, $this->properties() ) ) {

                $bridged[ $from ] = 'probe-' . $from;
            }
        }

        $this->assertNotEmpty( $bridged,
            'no rename targets the custom namespace; this test would pass vacuously' );

        $admitted = \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams( $bridged );

        $this->assertSame( $bridged, $admitted,
            'the endpoint refused a name the compat index bridges from' );
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

        $generated = Compat::contributeDerivedProperties( [] );

        $this->assertCount(
            $max * 2, $generated,
            'Each slot needs a name and a value property.' );

        /* The config is authoritative: a declared slot is left exactly alone. */
        $declared = [ 'cv1_name' => [ 'required' => 'untouched' ] ];

        $this->assertSame(
            [ 'required' => 'untouched' ],
            Compat::contributeDerivedProperties( $declared )['cv1_name'],
            'The top-up overwrote a definition the config had already made.' );

        for ( $slot = 1; $slot <= $max; $slot++ ) {

            foreach ( [ 'name', 'value' ] as $half ) {

                $property = $generated[ "cv{$slot}_{$half}" ] ?? null;

                $this->assertIsArray( $property, "cv{$slot}_{$half} is not generated." );

                $this->assertTrue( $property['required'] );
                $this->assertSame( 'string', $property['data_type'] );

                /*
                 * NO STORAGE SENTINEL: the reporting layer renders absence as
                 * '(not set)' at read time, and a nullable column is opted out of the
                 * substitution anyway.
                 */
                $this->assertArrayNotHasKey( 'default_value', $property,
                    'a contributed property must not invent a storage label either' );

                $this->assertSame( 'client', $property['set_by'] );
                $this->assertSame( [ "cv{$slot}_{$half}" ], $property['from'] );
                $this->assertContains(
                    'OWA\\Module\\Base\\Classes\\TrackingEventHelpers::lowercaseString', $property['callbacks'],
                    "cv{$slot}_{$half} is lowercased so the same variable does not "
                    . 'split into two dimensions by case.' );
            }
        }
    }

    /**
     * Every indexed reshape is a function that exists, reads only what its
     * entry declares, and makes a legal event.
     */
    public function testEveryIndexedReshapeIsAFunctionOfWhatItReads(): void
    {
        $reshapes = (array) ( $this->index()['event_reshapes'] ?? [] );

        $this->assertNotEmpty( $reshapes, 'no reshapes declared; this would be vacuous' );

        foreach ( $reshapes as $from => $reshape ) {

            $this->assertIsCallable( $reshape['by'], "$from names a function that does not exist" );
            $this->assertNotEmpty( $reshape['reads'], "$from reads nothing" );

            $given  = array_fill_keys( (array) $reshape['reads'], 'probe' );
            $result = call_user_func( $reshape['by'], $given );

            $this->assertIsArray( $result, "$from made nothing of its own properties" );
            $this->assertTrue( \OWA\Core\CoreAPI::isTrackingEventType( $result['event_type'] ),
                "$from becomes {$result['event_type']}, which the name gate refuses" );

            $this->assertSame( $result, call_user_func( $reshape['by'], $given + [ 'not_read' => 'x' ] ),
                "$from depends on a property its entry does not list in `reads`" );

            // The endpoint admits what the reshape reads, or it never reaches it.
            $this->assertSame( $given, \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams( $given ),
                "the beacon endpoint drops a property $from reads" );
        }
    }

    /**
     * And the other direction: Compat reshapes only what the index lists, with
     * the function the index names. With the reshapes replaced, a track.action
     * event is left as it came, and one listed with another function is
     * reshaped by that function.
     */
    public function testNoReshapeIsAppliedThatTheIndexDoesNotList(): void
    {
        $cached = new ReflectionProperty( Compat::class, 'event_reshapes' );
        $was    = $cached->getValue();

        $action = static function (): \OWA\Module\Base\Classes\Event {

            $event = new \OWA\Module\Base\Classes\Event();
            $event->setEventType( 'track.action' );
            $event->setProperties( [ 'action_name' => 'Signup', 'action_group' => 'forms' ] );

            return $event;
        };

        try {
            $cached->setValue( null, [] );

            $left = $action();
            Compat::apply( $left );

            $this->assertSame( 'track.action', $left->getEventType(), 'reshaped with no entry in the index' );
            $this->assertSame( 'forms', $left->get( 'action_group' ) );

            $cached->setValue( null, [ 'track.action' => [
                'reads' => [ 'action_name' ],
                'by'    => [ self::class, 'probeReshape' ],
            ] ] );

            $probed = $action();
            Compat::apply( $probed );

            $this->assertSame( 'probe_signup', $probed->getEventType(), 'not reshaped by the function the index names' );
        } finally {
            $cached->setValue( null, $was );
        }
    }

    /**
     * @param  array<string, mixed> $read
     * @return array{event_type: string, properties: array<string, mixed>}
     */
    public static function probeReshape( array $read ): array
    {
        return [ 'event_type' => 'probe_' . strtolower( (string) $read['action_name'] ), 'properties' => [] ];
    }

    /**
     * The migration makes a 1.x action into the event the live reshape makes
     * of a track.action beacon carrying the same values, so a migrated action
     * and one sent today are one event.
     */
    public function testTheMigrationMakesTheEventTheReshapeMakes(): void
    {
        $this->assertSame(
            [ \OWA\Module\Base\Classes\Migration\ActionMigrator::class, 'customEvent' ],
            $this->index()['event_reshapes']['track.action']['by'] );

        $values = [ 'action_name' => 'Signup Form Submit', 'action_group' => 'forms',
            'action_label' => 'footer', 'numeric_value' => '3' ];

        $live = new \OWA\Module\Base\Classes\Event();
        $live->setEventType( 'track.action' );
        $live->setProperties( $values );
        Compat::apply( $live );

        $migrator = new class extends \OWA\Module\Base\Classes\Migration\ActionMigrator {

            /** @param array<string, mixed> $r */
            public function one( array $r ): array
            {
                return $this->events( $r, [] );
            }
        };

        $migrated = $migrator->one( $values + [ 'id' => 1, 'site_id' => 'owatest-site', 'visitor_id' => '11',
            'session_id' => '12', 'timestamp' => 1700000000, 'yyyymmdd' => 20231114 ] );

        $this->assertCount( 1, $migrated );
        $this->assertSame( 'signup_form_submit', $live->getEventType() );
        $this->assertSame( $live->getEventType(), $migrated[0]->getEventType() );
        $this->assertSame( 'forms', $live->get( 'eps_group' ) );

        foreach ( [ 'eps_group', 'eps_label', 'epn_value', 'eps_action_name' ] as $name ) {

            $this->assertSame( $live->get( $name ), $migrated[0]->get( $name ), $name );
        }
    }

    /**
     * The second decode is applied from the index: the version it names is the
     * one Compat reads, and the current tracker is at or above it, so its
     * beacons are decoded once.
     */
    public function testTheSecondDecodeIsAppliedFromTheIndex(): void
    {
        $from = (int) ( $this->index()['decoded_once_from_version'] ?? 0 );

        $this->assertGreaterThan( 0, $from );
        $this->assertSame( $from, Compat::decodedOnceFromVersion() );

        $tracker = (string) file_get_contents( \OWA\Core\Env::root() . '/modules/Base/src/tracker/Tracker.js' );

        $this->assertSame( 1, preg_match( '/static get BEACON_FORMAT_VERSION\(\) \{\s*return (\d+);/', $tracker, $m ),
            'OWATracker.BEACON_FORMAT_VERSION is gone; this test is stale' );
        $this->assertGreaterThanOrEqual( $from, (int) $m[1], "the tracker's beacons would be decoded twice" );

        $this->assertFalse( Compat::decodesOnce( null ), 'a beacon with no version is decoded twice' );
        $this->assertFalse( Compat::decodesOnce( $from - 1 ) );
        $this->assertTrue( Compat::decodesOnce( (string) $from ) );
    }

    /** And nothing applies it but Compat: decodeRequestParams() has no other caller. */
    public function testOnlyCompatDecodesABeaconASecondTime(): void
    {
        $root    = \OWA\Core\Env::root();
        $callers = [];

        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/modules', FilesystemIterator::SKIP_DOTS ) );

        foreach ( array_merge( iterator_to_array( $files ), glob( $root . '/Core/{,*/}*.php', GLOB_BRACE ), glob( $root . '/*.php' ) ) as $file ) {

            $file = (string) $file;

            if ( substr( $file, -4 ) === '.php' && strpos( (string) file_get_contents( $file ), '->decodeRequestParams()' ) !== false ) {

                $callers[] = substr( $file, strlen( $root ) + 1 );
            }
        }

        $this->assertSame( [ 'modules/Base/Classes/Beacon/Compat.php' ], $callers );
    }
}
