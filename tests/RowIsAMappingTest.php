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
 * WHY THIS IS A TEST AND NOT A CONVENTION. Sixteen columns used to be computed
 * inside row(): five readings of the user agent, five campaign tags, six
 * readings of a URL, and the three money conversions. Each one was reasonable
 * where it stood and together they cost three defects that a mapping cannot
 * express:
 *
 *   - `revenue` read a property of that name. The registry declares none -- the
 *     wire sends ct_total -- so every purchase ever stored wrote NULL.
 *   - two of the sixteen came back from helper methods merged with `+=`, which
 *     silently keeps the key the literal already set. browser_version was the
 *     parser's answer in one and the beacon's claim in the other.
 *   - goal conditions were matched before those helpers merged, so a goal
 *     declared on device_type or a tagged_* value matched nothing, silently.
 *
 * All three are the same shape: a value's producer and its column drifted apart
 * because nothing required them to be the same statement. This requires it.
 *
 * DERIVED FROM THE SOURCE, not from a hand-kept list, so a column added
 * tomorrow is covered without anyone remembering this file exists.
 */
final class RowIsAMappingTest extends TestCase
{
    /**
     * Columns no property backs, each for a reason a property could not serve.
     *
     * A short list that has to stay short: every name on it is a column whose
     * value the registry cannot describe.
     */
    private const NOT_A_PROPERTY = array(
        // A hash OF the properties. It cannot be one of them.
        'id'            => 'derived from site, visitor, session, ts and the event name',
        // The expansion decides it: one beacon becomes page_view plus its
        // session_start and first_visit markers, and each row says which it is.
        'event_type'    => 'the expansion names each row, not the beacon',
        // Raised by Classes\GoalMarking at Ingest::STORE_POST, against the
        // complete row. NOT NULL, so the literal carries the default.
        'is_goal_event' => 'decided after the row is assembled',
        // What could NOT be a column -- site-defined keys and per-event-type
        // values too narrow to earn one. The inverse of this mapping.
        'params'        => 'everything with no column of its own',
    );

    /**
     * The identity columns, read into locals above the literal.
     *
     * Not an exemption from the mapping -- each IS a declared property and each
     * is asserted below to name its own column -- but the guard that refuses a
     * row missing any of them has to read them first, and the id hash is built
     * from the same locals so the row and its id cannot disagree.
     */
    private const IDENTITY = array( 'site_id', 'visitor_id', 'session_id', 'ts' );

    /** row()'s body, from the source. */
    private function body(): string
    {
        $source = file_get_contents(
            OWA_DIR . 'modules/Base/Handler/EventRawHandlers.php' );

        $open = strpos( $source, 'protected function row( $event, $name ) {' );

        $this->assertNotFalse( $open, 'row() has been renamed; this test is stale.' );

        // to the next method's docblock at class-body indentation
        $close = strpos( $source, "\n    /**", $open );

        return substr( $source, $open, $close - $open );
    }

    /** @return array column => the expression assigned to it */
    private function entries(): array
    {
        preg_match_all( "/^            '([a-z_0-9]+)'\s*=>\s*(.+?),?\s*$/m",
            $this->body(), $found, PREG_SET_ORDER );

        $entries = array();

        foreach ( $found as $entry ) {

            $entries[ $entry[1] ] = trim( rtrim( $entry[2], ',' ) );
        }

        $this->assertGreaterThan( 50, count( $entries ),
            'the row literal did not parse -- this test would pass vacuously' );

        return $entries;
    }

    /**
     * Every column is a property read, a local holding one, or a listed
     * exception.
     */
    public function testEveryColumnIsOnePropertyRead(): void
    {
        $offenders = array();

        foreach ( $this->entries() as $column => $expression ) {

            if ( isset( self::NOT_A_PROPERTY[ $column ] ) ) {

                continue;
            }

            // $this->text( $event->get( 'x' ) ) / ->number( ... ), a bare
            // $event->get( 'x' ), or one of the identity locals.
            $read = '/^(?:\$this->(?:text|number)\(\s*)?\$event->get\(\s*\'\w+\'\s*\)/';

            if ( preg_match( $read, $expression )
                 || in_array( ltrim( $expression, '$' ), self::IDENTITY, true ) ) {

                continue;
            }

            $offenders[ $column ] = $expression;
        }

        $this->assertSame( array(), $offenders,
            "A column is being worked out in the row builder. Whatever computes it "
            . "belongs in a callback named by the property registry, so the value and "
            . "its column are one statement:\n"
            . var_export( $offenders, true ) );
    }

    /**
     * And the property it reads declares that column.
     *
     * The assertion the `revenue` bug failed: reading a property no registry
     * entry declares, or one whose column is a different name, both pass a
     * read-shaped check and store nothing.
     */
    public function testEveryColumnAgreesWithTheRegistry(): void
    {
        $checked = 0;
        $wrong   = array();

        foreach ( $this->entries() as $column => $expression ) {

            if ( isset( self::NOT_A_PROPERTY[ $column ] ) ) {

                continue;
            }

            if ( ! preg_match( "/\\\$event->get\(\s*'(\w+)'/", $expression, $m ) ) {

                // an identity local; its property is asserted below
                continue;
            }

            $property = $m[1];
            $declared = Helpers::columnFor( $property );

            $checked++;

            if ( $declared === $column ) {

                continue;
            }

            $wrong[ $column ] = $property . ' declares column '
                . ( $declared === '' ? '<none>' : $declared );
        }

        $this->assertGreaterThan( 40, $checked,
            'too few columns matched to be checking anything' );

        $this->assertSame( array(), $wrong,
            "A column is written from a property the registry maps somewhere else "
            . "-- which is how `revenue` came to read a property no entry declares "
            . "and store NULL on every purchase:\n" . var_export( $wrong, true ) );
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
     * Nothing in row() works a value out.
     *
     * A token list rather than a shape check, because a derivation can be
     * written to look like anything: what it cannot do is avoid calling
     * something. Each of these was in row() and is now in a callback.
     */
    public function testRowComputesNothing(): void
    {
        $forbidden = array(
            'parseUrl', 'canonicalPath', 'filterQuery', 'minorUnits',
            'parse_url', 'preg_', 'explode(', 'substr(', 'strtolower(',
            'str_replace(', 'getSetting(', 'droppedQueryParams',
        );

        $body  = $this->body();
        $found = array();

        foreach ( $forbidden as $token ) {

            if ( strpos( $body, $token ) !== false ) {

                $found[] = $token;
            }
        }

        $this->assertSame( array(), $found,
            'row() is deriving a value: ' . implode( ', ', $found )
            . '. It belongs in a callback the registry names.' );
    }

    /**
     * And every column the registry declares is actually written.
     *
     * The other direction. A property can name a column that no row entry reads,
     * and then the registry describes a value nothing stores -- which is what
     * page_path, page_query, host, referer_host, referer_query and target_host
     * were before their callbacks existed: declared, and filled by the row
     * builder behind the registry's back.
     */
    public function testEveryDeclaredColumnIsWritten(): void
    {
        $columns = $this->entries();
        $missing = array();

        foreach ( Helpers::allProperties() as $property => $definition ) {

            $column = Helpers::columnFor( $property );

            if ( $column !== '' && ! array_key_exists( $column, $columns ) ) {

                $missing[ $column ] = $property;
            }
        }

        $this->assertSame( array(), $missing,
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
        $backed = array();

        foreach ( Helpers::allProperties() as $property => $definition ) {

            $column = Helpers::columnFor( $property );

            if ( $column !== '' ) {

                $backed[ $column ] = true;
            }
        }

        $unbacked = array_values( array_diff( array_keys( $this->entries() ),
            array_keys( $backed ), array_keys( self::NOT_A_PROPERTY ) ) );

        $this->assertSame( array(), $unbacked,
            'a column appeared that no property declares: '
            . implode( ', ', $unbacked ) );
    }
}
