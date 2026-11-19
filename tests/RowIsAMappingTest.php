<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The row builder maps; it does not derive.
 *
 * Every column of owa_event_raw is one property read off a formed event. The
 * registry says how each property is set and a callback sets it, so assembling a
 * row is property name -> column name and a coercion, and nothing else.
 *
 * WHY THIS IS A TEST AND NOT A CONVENTION. A value computed inside row() lets
 * its producer and its column drift apart, in ways a mapping cannot express:
 *
 *   - a column reading a property the registry does not declare writes NULL on
 *     every row (`revenue`, where the wire sends ct_total);
 *   - a helper merged with `+=` silently keeps the key the literal already set;
 *   - goal conditions matched before such a helper merges match nothing.
 *
 * This requires the producer and the column to be the same statement.
 *
 * EXECUTED, from the registry rather than a hand-kept list: one event carries
 * every declared property, each with a value of its own, and the row
 * rowFor() builds from it is read back. A column added tomorrow is covered
 * without anyone remembering this file exists.
 */
final class RowIsAMappingTest extends TestCase
{
    /**
     * Columns no property backs, each for a reason a property could not serve.
     *
     * A short list that has to stay short: every name on it is a column whose
     * value the registry cannot describe.
     */
    private const NOT_A_PROPERTY = [
        // A hash OF the properties. It cannot be one of them.
        'id'            => 'derived from site, visitor, session, ts and the event name',
        // The v2 name of the event's TYPE. The event_type property is what the
        // beacon sent, which a v1 tracker spelled differently.
        'event_type'    => 'the v2 name of the event type, not the property the beacon sent',
        // What could NOT be a column -- site-defined keys and per-event-type
        // values too narrow to earn one. The inverse of this mapping.
        'params'        => 'everything with no column of its own',
    ];

    /**
     * The identity columns, read into locals above the literal.
     *
     * Not an exemption from the mapping -- each IS a declared property and each
     * is asserted below to name its own column -- but the guard that refuses a
     * row missing any of them has to read them first, and the id hash is built
     * from the same locals so the row and its id cannot disagree.
     */
    private const IDENTITY = [ 'site_id', 'visitor_id', 'session_id', 'ts' ];

    /**
     * The row built from an event carrying every declared property that has
     * a column, each set to $value( property, position ).
     *
     * @param  callable(string, int): mixed $value
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the row, and property => value set
     */
    private function rowOfEveryProperty( callable $value ): array
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType( 'page_view' );

        $set = [];
        $i   = 0;

        foreach ( Helpers::allProperties() as $property => $definition ) {

            if ( Helpers::columnFor( $property ) === '' ) {

                continue;
            }

            $set[ $property ] = ( $definition['data_type'] ?? '' ) === 'boolean' ? true : $value( $property, $i++ );
            $event->set( $property, $set[ $property ] );
        }

        $this->assertGreaterThan( 50, count( $set ), 'too few declared properties to be checking anything' );

        $row = \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $event );

        $this->assertIsArray( $row, 'the identity guard refused an event carrying every property' );

        return [ $row, $set ];
    }

    /**
     * Every column holds the value of the one property that declares it: each
     * property is given a number of its own, so a column reading another
     * property, or working a value out, holds a different number.
     *
     * The assertion the `revenue` bug failed: reading a property no registry
     * entry declares, or one whose column is a different name, stores nothing.
     */
    public function testEveryColumnIsOnePropertyRead(): void
    {
        list( $row, $set ) = $this->rowOfEveryProperty( static fn ( $property, $i ) => (string) ( 41000 + $i ) );

        $wrong = [];

        foreach ( $set as $property => $value ) {

            $column = Helpers::columnFor( $property );

            if ( isset( self::NOT_A_PROPERTY[ $column ] ) ) {

                continue;
            }

            $expected = $value === true ? 1 : $value;

            if ( ! array_key_exists( $column, $row ) || $row[ $column ] != $expected ) {

                $wrong[ $column ] = $property . ' was ' . var_export( $value, true ) . ', the column holds '
                    . var_export( $row[ $column ] ?? '<not written>', true );
            }
        }

        $this->assertSame( [], $wrong,
            "A column does not hold the value of the property the registry maps to it. "
            . "Whatever computes it belongs in a callback named by the registry:\n"
            . var_export( $wrong, true ) );
    }

    /**
     * Nothing in row() works a text value out: a value a derivation would
     * change -- case, a query, a fragment, an encoded space -- reaches every
     * text column as it was set. The derivations are callbacks the registry
     * names, and run before row().
     */
    public function testRowComputesNothing(): void
    {
        list( $numbered ) = $this->rowOfEveryProperty( static fn ( $property, $i ) => (string) ( 41000 + $i ) );

        list( $row, $set ) = $this->rowOfEveryProperty(
            static fn ( $property, $i ) => "Mixed Case $i/Path?Q=1&utm_source=x#Frag%20" );

        $checked = 0;
        $changed = [];

        foreach ( $set as $property => $value ) {

            $column = Helpers::columnFor( $property );

            // The text columns: those that kept the number as a string.
            if ( isset( self::NOT_A_PROPERTY[ $column ] ) || ! is_string( $numbered[ $column ] ?? null ) ) {

                continue;
            }

            $checked++;

            if ( ( $row[ $column ] ?? null ) !== $value ) {

                $changed[ $column ] = $row[ $column ] ?? '<not written>';
            }
        }

        $this->assertGreaterThan( 40, $checked, 'too few text columns to be checking anything' );

        $this->assertSame( [], $changed,
            'row() is deriving a value. It belongs in a callback the registry names:' . "\n"
            . var_export( $changed, true ) );
    }

    /** The identity locals are declared properties naming their own columns. */
    public function testTheIdentityColumnsAreDeclaredToo(): void
    {
        foreach ( self::IDENTITY as $property ) {

            $this->assertSame( $property, Helpers::columnFor( $property ),
                $property . ' is read into a local and must still be declared' );
        }
    }

    /**
     * A NOT NULL column never receives NULL, even from an event the property
     * pass never touched.
     *
     * From an event with only the identity properties, where the tests above
     * set every property.
     *
     * The path this guards is real: row() is reached by events built in process
     * and by queued ones, not only by a beacon that went through
     * ProcessEvent's three stages. On a beacon, deriveIsOutbound() runs for every
     * event and always sets a value, so an is_outbound read through number() --
     * which answers null for absence -- looks correct and is caught by nothing.
     * Off that path the property is absent, number() answers null, and a NOT NULL
     * column under STRICT_ALL_TABLES aborts the whole insert.
     */
    public function testANotNullColumnNeverReceivesNullFromRow(): void
    {
        $handlers = new ReflectionClass( \OWA\Module\Base\Handler\EventRawHandlers::class );
        $row      = $handlers->getMethod( 'row' );

        // The four the guard refuses a row without, and nothing else: an event
        // assembled in process, with no derived property on it at all.
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType( 'page_view' );
        $event->set( 'site_id', 'a-site' );
        $event->set( 'visitor_id', '1234567890' );
        $event->set( 'session_id', '9876543210' );
        $event->set( 'ts', time() );

        $built = $row->invoke( $handlers->newInstanceWithoutConstructor(), $event, 'page_view' );

        $this->assertIsArray( $built, 'the identity guard refused an event it should accept' );

        $entity  = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );
        $offends = [];

        foreach ( $entity->getColumns() as $column ) {

            $property = $entity->properties[ $column ] ?? null;

            if ( ! $property || ! array_key_exists( $column, $built ) ) {

                continue;
            }

            if ( strpos( strtoupper( (string) $property->getDefinition() ), 'NOT NULL' ) !== false
                 && $built[ $column ] === null ) {

                $offends[] = $column;
            }
        }

        $this->assertSame( [], $offends,
            'row() handed NULL to a NOT NULL column: ' . implode( ', ', $offends )
            . '. Strict mode aborts the insert, so absence has to resolve to a '
            . 'value -- see flag().' );
    }

    /**
     * And every column the registry declares is actually written.
     *
     * The other direction. A property can name a column that no row entry reads,
     * and then the registry describes a value nothing stores.
     */
    public function testEveryDeclaredColumnIsWritten(): void
    {
        list( $columns ) = $this->rowOfEveryProperty( static fn ( $property, $i ) => (string) ( 41000 + $i ) );
        $missing = [];

        foreach ( Helpers::allProperties() as $property => $definition ) {

            $column = Helpers::columnFor( $property );

            if ( $column !== '' && ! array_key_exists( $column, $columns ) ) {

                $missing[ $column ] = $property;
            }
        }

        $this->assertSame( [], $missing,
            "The registry declares a column the row builder never writes:\n"
            . var_export( $missing, true ) );
    }

    /**
     * NO column is unbacked, beyond the four listed with a reason.
     *
     * `browser` was the exception here: a VARCHAR(128) copy of browser_type that
     * no dimension read. Update052 dropped it, so the rule is unconditional and
     * a new duplicate cannot be added quietly.
     */
    public function testNoColumnIsUnbacked(): void
    {
        $backed = [];

        foreach ( Helpers::allProperties() as $property => $definition ) {

            $column = Helpers::columnFor( $property );

            if ( $column !== '' ) {

                $backed[ $column ] = true;
            }
        }

        list( $row ) = $this->rowOfEveryProperty( static fn ( $property, $i ) => (string) ( 41000 + $i ) );

        $unbacked = array_values( array_diff( array_keys( $row ),
            array_keys( $backed ), array_keys( self::NOT_A_PROPERTY ) ) );

        $this->assertSame( [], $unbacked,
            'a column appeared that no property declares: '
            . implode( ', ', $unbacked ) );
    }
}
