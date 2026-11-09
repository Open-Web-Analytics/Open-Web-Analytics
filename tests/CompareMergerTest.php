<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\CompareMerger;

/**
 * A comparison period's numbers put beside a result set's (CompareMerger),
 * and the `in` constraint the comparison query is restricted with.
 */
final class CompareMergerTest extends TestCase
{
    private static function cell( string $name, $value, string $type = 'metric' ): array
    {
        return array( 'result_type' => $type, 'name' => $name, 'value' => $value,
            'formatted_value' => is_numeric( $value ) ? number_format( (float) $value ) : (string) $value );
    }

    private static function rs( array $aggregates, array $rows = array() ): object
    {
        $rs = new \OWA\Module\Base\Classes\PaginatedResultSet();
        $rs->aggregates  = $aggregates;
        $rs->resultsRows = $rows;

        return $rs;
    }

    public function testTheAggregatesGainTheComparisonAndTheChange(): void
    {
        $rs  = self::rs( array( 'pageViews' => self::cell( 'pageViews', 150 ), 'visits' => self::cell( 'visits', 0 ) ) );
        $crs = self::rs( array( 'pageViews' => self::cell( 'pageViews', 100 ), 'visits' => self::cell( 'visits', 0 ) ) );

        CompareMerger::merge( $rs, $crs, array(), array( '20261013', '20261109' ), array( '20260915', '20261012' ), 'previous_period_dow' );

        $this->assertSame( 100, $rs->aggregates['pageViews']['compare_value'] );
        $this->assertSame( '100', $rs->aggregates['pageViews']['compare_formatted_value'] );
        $this->assertEqualsWithDelta( 0.5, $rs->aggregates['pageViews']['change'], 1e-9 );

        // Nothing to divide by: no change rather than infinity.
        $this->assertNull( $rs->aggregates['visits']['change'] );

        $this->assertSame( array( 'compare' => 'previous_period_dow', 'startDate' => '20260915', 'endDate' => '20261012' ),
            array_intersect_key( $rs->compare, array_flip( array( 'compare', 'startDate', 'endDate' ) ) ) );
        $this->assertSame( 100, $rs->compare['aggregates']['pageViews']['value'] );
    }

    public function testRowsAreMatchedOnTheirDimensionValues(): void
    {
        $row = fn ( $path, $views ) => array( 'pagePath' => self::cell( 'pagePath', $path, 'dimension' ), 'pageViews' => self::cell( 'pageViews', $views ) );

        $rs  = self::rs( array(), array( $row( '/a', 10 ), $row( '/b', 4 ), $row( '/new', 3 ) ) );
        $crs = self::rs( array(), array( $row( '/b', 8 ), $row( '/a', 5 ), $row( '/gone', 9 ) ) );

        CompareMerger::merge( $rs, $crs, array( 'pagePath' ), array( '20261013', '20261109' ), array( '20260915', '20261012' ), 'previous_period' );

        $by = array_column( array_map( fn ( $r ) => array( $r['pagePath']['value'], $r['pageViews'] ), $rs->resultsRows ), 1, 0 );

        $this->assertSame( 5, $by['/a']['compare_value'] );
        $this->assertEqualsWithDelta( 1.0, $by['/a']['change'], 1e-9 );
        $this->assertSame( 8, $by['/b']['compare_value'] );
        $this->assertEqualsWithDelta( -0.5, $by['/b']['change'], 1e-9 );

        // No row in the comparison: unknown, not zero.
        $this->assertNull( $by['/new']['compare_value'] );
        $this->assertNull( $by['/new']['change'] );

        // A dimension cell is left alone.
        $this->assertArrayNotHasKey( 'compare_value', $rs->resultsRows[0]['pagePath'] );
        $this->assertCount( 3, $rs->resultsRows, 'the comparison adds no rows of its own' );
    }

    public function testATrendIsMatchedByPlaceInThePeriodNotByDate(): void
    {
        $row = fn ( $date, $views ) => array( 'date' => self::cell( 'date', $date, 'dimension' ), 'pageViews' => self::cell( 'pageViews', $views ) );

        // Nov 2-4 against the week before, Oct 26-28.
        $rs  = self::rs( array(), array( $row( '20261102', 10 ), $row( '20261103', 20 ), $row( '20261104', 30 ) ) );
        $crs = self::rs( array(), array( $row( '20261026', 1 ), $row( '20261027', 2 ), $row( '20261028', 3 ) ) );

        CompareMerger::merge( $rs, $crs, array( 'date' ), array( '20261102', '20261104' ), array( '20261026', '20261028' ), 'previous_period_dow' );

        $this->assertSame( array( 1, 2, 3 ), array_map( fn ( $r ) => $r['pageViews']['compare_value'], $rs->resultsRows ) );
    }

    public function testATimeAxisWithACategoryIsMatchedOnBoth(): void
    {
        $row = fn ( $hour, $medium, $v ) => array( 'dateHour' => self::cell( 'dateHour', $hour, 'dimension' ),
            'medium' => self::cell( 'medium', $medium, 'dimension' ), 'visits' => self::cell( 'visits', $v ) );

        $rs  = self::rs( array(), array( $row( '2026110914', 'organic', 6 ), $row( '2026110914', 'referral', 2 ) ) );
        $crs = self::rs( array(), array( $row( '2026110814', 'referral', 1 ), $row( '2026110814', 'organic', 3 ) ) );

        CompareMerger::merge( $rs, $crs, array( 'dateHour', 'medium' ), array( '20261109', '20261109' ), array( '20261108', '20261108' ), 'previous_period' );

        $this->assertSame( array( 3, 1 ), array_map( fn ( $r ) => $r['visits']['compare_value'], $rs->resultsRows ) );
    }

    public static function shifts(): array
    {
        return array(
            'a day, a week back'      => array( 'day', '20261026', '20261026', '20261102', '20261102' ),
            'a day across a month'    => array( 'day', '20260930', '20260915', '20261013', '20261028' ),
            'an hour keeps its hour'  => array( 'hour', '2026110814', '20261108', '20261109', '2026110914' ),
            'a month, a year on'      => array( 'month', '202510', '20251001', '20261001', '202610' ),
            'a month across a year'   => array( 'month', '202512', '20251201', '20260101', '202601' ),
            'a year'                  => array( 'year', '2025', '20250101', '20260101', '2026' ),
        );
    }

    /**
     * @dataProvider shifts
     */
    public function testATimeAxisValueMovesToTheSamePlace( string $unit, string $value, string $from, string $to, string $want ): void
    {
        $this->assertSame( $want, CompareMerger::shift( $unit, $value, $from, $to ) );
    }

    public function testOnlyCategoryDimensionsAreRestrictedToThePage(): void
    {
        $this->assertSame( array( 'medium', 'dayOfWeek' ),
            CompareMerger::restrictable( array( 'date', 'medium', 'dateHour', 'dayOfWeek', 'yearMonth', 'year' ) ) );
    }

    public function testTheInConstraintBindsEachValue(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'Building SQL needs the configured driver.' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->resetBindings();
        $db->selectFrom( 'owa_session' );
        $db->selectColumn( 'id' );
        $db->where( 'referer', array( 'a', "b'); DROP TABLE x; --" ), 'in' );

        $sql = $db->generateSelectQuerySql();

        $this->assertStringContainsString( 'IN (?, ?)', $sql );
        $this->assertStringNotContainsString( 'DROP TABLE', $sql, 'the values are bound, not written into the SQL' );
    }

    public function testInIsNotPartOfTheRestConstraintSyntax(): void
    {
        $this->assertArrayNotHasKey( 'in', \OWA\Module\Base\Classes\ResultSetManager::CONSTRAINT_OPERATORS );
        $this->assertContains( 'in', \OWA\Core\Db::ALLOWED_OPERATORS );
    }
}
