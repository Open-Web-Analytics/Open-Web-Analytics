<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;
use OWA\Module\Base\Classes\Beacon\Compat;

/**
 * "(not set)" is how a v1 column stores absence, not how an event carries it.
 *
 * The label used to be applied before dispatch, so the literal was the value
 * every reader saw and nothing could tell "no value" from a value that happens
 * to be that string. It is applied at the column now: v1 rows are byte-identical
 * to before -- there is nothing to backfill -- while the event carries absence
 * as absence, which is what v2 needs to read off the same pipeline.
 */
final class AbsentValueIsStorageOnlyTest extends TestCase
{
    /** The properties that declare the label. */
    public static function labelled(): array
    {
        $out = array();

        /*
         * The compat layer is consulted beside the config: it declares what an
         * older beacon generation carries and the current format does not --
         * the cv{n} slots today. They are still real properties on that
         * beacon's path, so a set gathered without them is incomplete.
         */
        foreach ( array_merge( Helpers::requestProperties(),
                               Compat::contributeClientProperties( Helpers::clientProperties() ),
                               Compat::contributeDerivedProperties( Helpers::serverProperties() ) ) as $name => $definition ) {

            if ( isset( $definition['default_value'] )
                 && $definition['default_value'] === Helpers::ABSENT_VALUE_LABEL ) {

                $out[ $name ] = array( $name );
            }
        }

        return $out;
    }

    /** There are 26 of them; a change to that set should be deliberate. */
    public function testTheLabelledSetIsWhatWeThinkItIs(): void
    {
        $this->assertCount( 26, self::labelled() );
    }

    /**
     * The pipeline does not write the label onto the event.
     *
     * @dataProvider labelled
     */
    public function testThePipelineLeavesItAbsent( string $property ): void
    {
        $definitions = array_merge( Helpers::requestProperties(),
                                    Compat::contributeClientProperties( Helpers::clientProperties() ),
                                    Compat::contributeDerivedProperties( Helpers::serverProperties() ) );

        $teh   = new Helpers();
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        $teh->setTrackerProperties( $event, array( $property => $definitions[ $property ] ) );

        $this->assertNotSame( Helpers::ABSENT_VALUE_LABEL, $event->get( $property ),
            "$property arrived on the event carrying the storage label" );
    }

    /** But a v1 text column still receives it, so nothing on disk moves. */
    public function testATextColumnStillStoresTheLabel(): void
    {
        $cases = array(
            'base.session'  => array( 'host', 'cv1_name', 'user_name' ),
            'base.document' => array( 'page_title' ),
            'base.host'     => array( 'host', 'full_host' ),
        );

        foreach ( $cases as $entity_name => $columns ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $entity_name );

            // every flavour of absence an event can hand over
            $entity->setProperties( array(
                'host' => null, 'full_host' => '', 'cv1_name' => false,
                'user_name' => false, 'page_title' => false ) );

            foreach ( $columns as $column ) {
                $this->assertSame( Helpers::ABSENT_VALUE_LABEL, $entity->get( $column ),
                    "$entity_name.$column should still store the label" );
            }
        }
    }

    /**
     * A numeric column never takes it.
     *
     * timestamp declares the label like the rest, but with strict mode off
     * MySQL would coerce a non-numeric string to 0 without complaint -- the same
     * silent coercion that once let a city name reach a boolean column.
     */
    public function testANumericColumnNeverTakesTheLabel(): void
    {
        $request = \OWA\Core\CoreAPI::entityFactory( 'base.request' );
        $request->setProperties( array( 'timestamp' => false ) );

        $this->assertNotSame( Helpers::ABSENT_VALUE_LABEL, $request->get( 'timestamp' ) );
    }

    /**
     * Defaults that are real values still belong to the event.
     *
     * medium defaults to 'direct' and browser/os to '(unknown)' -- those say
     * something, rather than standing in for the lack of a value, so they are
     * still applied before dispatch where every reader sees them.
     */
    public function testRealDefaultsStillApplyOnTheEvent(): void
    {
        $definitions = Compat::contributeDerivedProperties( Helpers::serverProperties() );

        $teh   = new Helpers();
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        // medium resolves from the referer, and there is none here, so its
        // declared default is what lands.
        $teh->setTrackerProperties( $event, array( 'medium' => $definitions['medium'] ) );

        $this->assertSame( 'direct', $event->get( 'medium' ),
            'medium lost its default when the storage label moved' );

        // os and browser are resolved from the user agent, which the runner
        // supplies -- so assert the weaker but still meaningful thing: whatever
        // they end up with is a real value, never the storage label and never
        // absence.
        foreach ( array( 'os', 'browser' ) as $name ) {

            $teh->setTrackerProperties( $event, array( $name => $definitions[ $name ] ) );

            $this->assertNotSame( Helpers::ABSENT_VALUE_LABEL, $event->get( $name ) );
            $this->assertNotEmpty( $event->get( $name ),
                "$name should carry a resolved value or its own '(unknown)' default" );
        }
    }

    /**
     * The lookup does not depend on module registration.
     *
     * It first read the registered tracking-property service maps, which are
     * populated by module registration and came back EMPTY in a CLI context --
     * so every column would have stored NULL where existing rows hold the label,
     * and the test suite would have gone on passing. It reads the property
     * definitions directly instead, which answer the same everywhere.
     */
    public function testTheLookupDoesNotDependOnRegisteredMaps(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $this->assertNotEmpty(
            array_merge( Helpers::requestProperties(),
                         Compat::contributeClientProperties( Helpers::clientProperties() ),
                         Compat::contributeDerivedProperties( Helpers::serverProperties() ) ),
            'the property definitions must be readable without the service maps' );

        $method = new ReflectionMethod( '\OWA\Core\Entity', 'storageDefaultFor' );
        $method->setAccessible( true );

        $this->assertSame( Helpers::ABSENT_VALUE_LABEL, $method->invoke( null, 'host' ) );
        $this->assertNull( $method->invoke( null, 'medium' ),
            'medium declares a real default and must not be treated as a storage label' );
    }
}
