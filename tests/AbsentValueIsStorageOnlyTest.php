<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

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

        foreach ( array_merge( Helpers::requestProperties(),
                               Helpers::clientProperties(),
                               Helpers::serverProperties() ) as $name => $definition ) {

            if ( isset( $definition['default_value'] )
                 && $definition['default_value'] === Helpers::ABSENT_VALUE_LABEL ) {

                $out[ $name ] = array( $name );
            }
        }

        return $out;
    }

    /**
     * There are 16 of them; a change to that set should be deliberate.
     *
     * It was 26 while the ten cv{n} halves were declared in the config; they
     * are the compat layer's now, because the tracker emits no cv key. It was
     * 16 until the dead ingest derivations went -- source, medium, page_uri and
     * the rest, computed on every beacon and read only by v1 handlers. 13 until
     * page_url and page_type left the registry with the other compat spellings:
     * the registry holds what v2 CALLS things, and page_url is a rename now.
     */
    public function testTheLabelledSetIsWhatWeThinkItIs(): void
    {
        $this->assertCount( 11, self::labelled() );
    }

    /**
     * The pipeline does not write the label onto the event.
     *
     * @dataProvider labelled
     */
    public function testThePipelineLeavesItAbsent( string $property ): void
    {
        $definitions = array_merge( Helpers::requestProperties(),
                                    Helpers::clientProperties(),
                                    Helpers::serverProperties() );

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
            /*
             * cv1_name was here and is not a case any more. It is a column on
             * base.session -- a v1 entity whose writers are the v1 event
             * chain, none of which is registered -- and keeping it meant
             * Core\Entity reaching into a module's beacon compat layer to
             * preserve a default on a table nothing populates. The label
             * behaviour is still covered by the columns beside it.
             */
            'base.session'  => array( 'host', 'user_name' ),
            'base.document' => array( 'page_title' ),
            /*
             * full_host left this list with the property. It was a
             * reverse-DNS name computed on every beacon and read only by v1
             * handlers, reaching no raw column and no cube pass.
             */
            'base.host'     => array( 'host' ),
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
     * os defaults to '(unknown)' -- that says something, rather than standing in
     * for the lack of a value, so it is still applied before dispatch where every
     * reader sees it.
     *
     * `browser` was the other example and is gone from the registry. It carried
     * the VERSION, resolved through a browscap the argument-less accessor
     * memoises per process, and the row builder read it into browser_version
     * while deviceColumns() computed the same column from the event's own user
     * agent -- which `$row +=` then discarded. One parse, in the handler, is what
     * survives.
     *
     * medium was the headline example here and is gone: it is not a tracking
     * property any more. The cube pass resolves it, and MediumStep is where
     * 'direct' is decided now -- asserted on built rows in CubeBuildTest.
     */
    public function testRealDefaultsStillApplyOnTheEvent(): void
    {
        $definitions = Helpers::serverProperties();

        $teh   = new Helpers();
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        // os is resolved from the user agent, which the runner supplies -- so
        // assert the weaker but still meaningful thing: whatever it ends up with
        // is a real value, never the storage label and never absence.
        foreach ( array( 'os' ) as $name ) {

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
                         Helpers::clientProperties(),
                         Helpers::serverProperties() ),
            'the property definitions must be readable without the service maps' );

        $method = new ReflectionMethod( '\OWA\Core\Entity', 'storageDefaultFor' );
        $method->setAccessible( true );

        $this->assertSame( Helpers::ABSENT_VALUE_LABEL, $method->invoke( null, 'host' ) );
        $this->assertNull( $method->invoke( null, 'medium' ),
            'medium declares a real default and must not be treated as a storage label' );
    }
}
