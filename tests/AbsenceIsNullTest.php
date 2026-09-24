<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Geolocation;
use OWA\Module\Base\Classes\TrackingEventHelpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Absence is NULL, and "(not set)" is a label applied at render time.
 *
 * Four changes that only make sense together, which is why they are tested
 * together:
 *
 *   1. a negating constraint tolerates NULL, so a LEFT JOINed dimension the row
 *      does not have stops being silently discarded
 *   2. an absent dimension renders as "(not set)", so nothing on screen changes
 *      when a value stops being stored
 *   3. the two write paths that stored the literal string stop
 *   4. a command clears what earlier versions already stored
 *
 * Order matters and the tests record why. Doing (4) before (1) would convert
 * rows that currently appear as an odd bucket into rows that silently vanish
 * from any negating filter. Doing (4) before (2) would turn a labelled cell
 * into an empty one.
 */
final class AbsenceIsNullTest extends TestCase
{
    private function clause( $type, $operator, $name = 'source', $value = 'google' )
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $m = new \ReflectionMethod( $db, '_makeConstraintClause' );
        $m->setAccessible( true );

        return preg_replace( '/\s+/', ' ', trim( $m->invoke( $db, $type,
            array( array( 'name' => $name, 'operator' => $operator, 'value' => $value ) ) ) ) );
    }

    /** @dataProvider negatingOperators */
    public function testANegatingComparisonAlsoMatchesAbsence( string $operator ): void
    {
        $this->assertStringContainsString( 'IS NULL', $this->clause( 'WHERE', $operator ),
            "$operator must not discard a row merely because it holds no value" );
    }

    public static function negatingOperators(): array
    {
        return array( 'not equal' => array( '!=' ), 'not regexp' => array( '!~' ),
                      'not contains' => array( '!@' ) );
    }

    /** @dataProvider plainOperators */
    public function testAPositiveOrOrderingComparisonIsUnchanged( string $operator ): void
    {
        $this->assertStringNotContainsString( 'IS NULL', $this->clause( 'WHERE', $operator ),
            "$operator is already correct: a row with no value does not equal, "
            . 'exceed or precede anything' );
    }

    public static function plainOperators(): array
    {
        return array( array( '==' ), array( '>' ), array( '<' ), array( '>=' ), array( '<=' ), array( '=~' ), array( '=@' ) );
    }

    /**
     * HAVING filters an aggregate, not a column. COUNT never returns NULL and
     * SUM does so only over no rows, so widening it there would change metric
     * filtering for no reason.
     */
    public function testHavingIsLeftAlone(): void
    {
        $this->assertStringNotContainsString( 'IS NULL', $this->clause( 'HAVING', '!=', 'visits', 5 ) );
    }

    /**
     * The whole point, end to end: the row with no matching dimension comes back.
     */
    public function testTheRowWithNoDimensionSurvivesANegatingFilter(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the server decides what NULL comparisons return' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $left  = '(SELECT 1 AS id, "s1" AS tag UNION ALL SELECT 2, "s2" UNION ALL SELECT 3, "absent") s';
        $right = '(SELECT 1 AS id, "google" AS src UNION ALL SELECT 2, "bing") d';

        $tags = function ( $where ) use ( $db, $left, $right ) {
            $rows = $db->get_results(
                "SELECT s.tag FROM $left LEFT JOIN $right ON s.id = d.id WHERE $where" );
            return array_column( (array) $rows, 'tag' );
        };

        $this->assertSame( array( 's2' ), $tags( 'd.src != "google"' ),
            'the behaviour being fixed: the absent row is dropped' );

        $this->assertSame( array( 's2', 'absent' ), $tags( '( d.src != "google" OR d.src IS NULL )' ),
            'the clause the constraint compiler now emits keeps it' );
    }

    /** An absent dimension is labelled at render time, not in the row. */
    public function testAnAbsentDimensionRendersAsNotSet(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $this->assertSame( '(not set)', $rsm->formatDimensionValue( 'string', null ) );
        $this->assertSame( '(not set)', $rsm->formatDimensionValue( 'string', '' ) );
        $this->assertSame( 'london',    $rsm->formatDimensionValue( 'string', 'london' ) );
    }

    /**
     * A value the PIPELINE could not resolve is "(unknown)", not "(not set)".
     *
     * Two different statements, so two different words: absence means the row
     * carried nothing, while V2Event::UNRESOLVED means a build had something to
     * read and could not reach an answer. The sentinel is a control byte, so
     * before this it rendered as an EMPTY label -- a blank pie slice, on every
     * cube dimension that can resolve.
     */
    public function testAnUnresolvedDimensionRendersAsUnknown(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $this->assertSame( '(unknown)', $rsm->formatDimensionValue(
            'string', \OWA\Module\Base\Classes\V2Event::UNRESOLVED ) );

        // And it is NOT folded onto absence, which would lose the distinction
        // the sentinel exists to record.
        $this->assertNotSame(
            $rsm->formatDimensionValue( 'string', null ),
            $rsm->formatDimensionValue( 'string', \OWA\Module\Base\Classes\V2Event::UNRESOLVED ) );

        // An ordinary value is untouched, so the branch above cannot be
        // swallowing everything.
        $this->assertSame( 'New', $rsm->formatDimensionValue( 'string', 'New' ) );
    }

    /**
     * A metric of zero is a measurement, not an absence. Guarded because the
     * label is applied on the dimension branch only, and moving it into
     * formatValue() would quietly relabel every zero in every report.
     */
    public function testAMetricOfZeroIsStillZero(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        // formatValue() stringifies, which is pre-existing and not this
        // change's business. What matters is that zero is not relabelled.
        $this->assertNotSame( '(not set)', $rsm->formatValue( 'integer', 0 ) );
        $this->assertEquals( 0, $rsm->formatValue( 'integer', 0 ) );
    }

    /** @dataProvider writers */
    public function testTheWritePathNoLongerStoresTheLiteral( string $file ): void
    {
        $src = (string) file_get_contents( OWA_DIR . $file );

        $this->assertStringNotContainsString( "set('page_title', '(not set)')", $src,
            "$file still stores the label instead of leaving the column unset" );

        /*
         * Deliberately narrow: an ARRAY-ELEMENT assignment, which is the shape
         * the geolocation filter used ($geo[$k] = '(not set)'). A bare
         * "= '(not set)'" would also match Geolocation::UNRESOLVED_KEY_PART,
         * which is a hash input and never reaches a column.
         */
        $this->assertStringNotContainsString( "] = '(not set)'", $src,
            "$file still assigns the label into a value it is about to store" );
    }

    public static function writers(): array
    {
        return array(
            array( 'modules/Base/Classes/Geolocation.php' ),
            array( 'modules/Base/Handler/RefererHandlers.php' ),
        );
    }

    /**
     * An unresolved location still gets a dimension id, and always the same one.
     *
     * This is the regression the e2e suite caught. Reports join a geo dimension
     * to the fact table with a plain inner join, so a fact whose location_id
     * matches no dimension row is not grouped under "(not set)" -- it drops out
     * of the report entirely. Returning nothing here put 0 in the column, and 0
     * is an id no row carries.
     */
    public function testAnUnresolvedLocationStillGetsAnId(): void
    {
        $id = Geolocation::idFor( '', '', '' );

        $this->assertNotSame( 0, $id );
        $this->assertNotSame( '0', (string) $id );
        $this->assertNotNull( $id );
    }

    /**
     * Every way of saying "nothing resolved" lands on the same row.
     *
     * The lookup returns '' for a field it could not fill and null for one it
     * never attempted, and whitespace has been seen from the CSV reader. If
     * these hashed differently the geo reports would show several identical
     * "(not set)" rows.
     */
    public function testEveryFlavourOfAbsenceSharesOneRow(): void
    {
        $canonical = Geolocation::idFor( '', '', '' );

        $this->assertSame( $canonical, Geolocation::idFor( null, null, null ) );
        $this->assertSame( $canonical, Geolocation::idFor( '  ', '', ' ' ) );
        $this->assertSame( $canonical, Geolocation::idFor( '', null, '   ' ) );
    }

    /**
     * The id is the one every existing install already stores.
     *
     * Before the sentinel was removed the filter wrote '(not set)' into each
     * empty field, so an unresolved location hashed to those three literals.
     * Deriving a different key now would split one bucket into two that render
     * identically. This asserts the compatibility, not the constant: change the
     * key and old data silently stops joining to new data.
     */
    public function testTheUnresolvedIdMatchesWhatExistingInstallsStore(): void
    {
        $this->assertSame(
            \OWA\Core\Lib::setStringGuid( '(not set)(not set)(not set)' ),
            Geolocation::idFor( '', '', '' ) );
    }

    /**
     * The fact table and the dimension handler derive the same id.
     *
     * They used to disagree -- the handler keyed on country.city and the fact
     * callback on country.state.city -- so for any location carrying a state
     * the handler wrote a row nothing pointed at. There is now one derivation,
     * LocationDim::deriveId(), and both go through it.
     */
    public function testTheFactAndTheDimensionAgreeOnTheId(): void
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->set( 'country', 'United States' );
        $event->set( 'state',   'Virginia' );
        $event->set( 'city',    'Ashburn' );

        $this->assertSame(
            Geolocation::idFor( 'United States', 'Virginia', 'Ashburn' ),
            \OWA\Module\Base\Entity\LocationDim::deriveId( $event->getProperties() ) );
    }

    /**
     * And they agree when there is no geography at all.
     */
    public function testTheFactAndTheDimensionAgreeWhenNothingResolved(): void
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        $this->assertSame(
            Geolocation::idFor( '', '', '' ),
            \OWA\Module\Base\Entity\LocationDim::deriveId( $event->getProperties() ) );
    }

    /**
     * The backfill is an Update, and reapplying it is safe.
     *
     * It runs unattended on every upgrade, which is the point -- with the write
     * paths stopped, a site that never ran a cleanup would accumulate new NULLs
     * beside old "(not set)" strings in one column, and a GROUP BY would draw
     * two buckets meaning the same thing.
     *
     * Running twice has to be a no-op rather than a failure. The hazard is not
     * the UPDATE, which matches nothing the second time; it is the return value.
     * If query() answered false for "zero rows affected", up() would report
     * failure on every re-run and the schema version would never settle.
     */
    public function testTheUpdateCanBeReappliedSafely(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the update rewrites stored rows' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        // Seed one row, then let the update clear it. The end state is the one
        // the update exists to produce, so nothing needs restoring afterwards.
        $db->query( 'UPDATE owa_referer SET page_title = ? WHERE page_title IS NULL LIMIT 1',
            array( '(not set)' ) );

        $update = new \OWA\Module\Base\Update\Update031;

        $this->assertTrue( $update->up(), 'the first run must succeed' );

        $this->assertSame( '0',
            (string) $db->get_row(
                'SELECT COUNT(*) n FROM owa_referer WHERE page_title = "(not set)"' )['n'],
            'the first run must actually clear the column' );

        $this->assertTrue( $update->up(),
            'reapplying must be a no-op, not a failure -- an UPDATE matching no '
            . 'rows still succeeds, and up() must report that as success' );

        $this->assertTrue( $update->up(), 'and again' );
    }

    /**
     * It declares its own schema version, which is what makes it discoverable.
     *
     * This used to also assert that Module.php requires exactly 31, which was
     * true only while Update031 was the newest one on disk -- so every later
     * update broke a test about this one. The invariant it was reaching for
     * ("required_schema_version must cover the highest update present, or
     * Module::update() skips it and it never runs") belongs to no single
     * update, and UpdateDiscoveryTest already asserts it against whatever the
     * highest actually is.
     */
    public function testTheUpdateDeclaresItsSchemaVersion(): void
    {
        $update = new \OWA\Module\Base\Update\Update031;

        $this->assertSame( 31, $update->schema_version );
    }
}
