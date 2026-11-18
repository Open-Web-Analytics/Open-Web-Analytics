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
        return [ 'result_type' => $type, 'name' => $name, 'value' => $value,
            'formatted_value' => is_numeric( $value ) ? number_format( (float) $value ) : (string) $value ];
    }

    private static function rs( array $aggregates, array $rows = [] ): object
    {
        $rs = new \OWA\Module\Base\Classes\PaginatedResultSet();
        $rs->aggregates  = $aggregates;
        $rs->resultsRows = $rows;

        return $rs;
    }

    public function testTheAggregatesGainTheComparisonAndTheChange(): void
    {
        $rs  = self::rs( [ 'pageViews' => self::cell( 'pageViews', 150 ), 'visits' => self::cell( 'visits', 0 ) ] );
        $crs = self::rs( [ 'pageViews' => self::cell( 'pageViews', 100 ), 'visits' => self::cell( 'visits', 0 ) ] );

        CompareMerger::merge( $rs, $crs, [], [ '20261013', '20261109' ], [ '20260915', '20261012' ], 'previous_period_dow' );

        $this->assertSame( 100, $rs->aggregates['pageViews']['compare_value'] );
        $this->assertSame( '100', $rs->aggregates['pageViews']['compare_formatted_value'] );
        $this->assertEqualsWithDelta( 0.5, $rs->aggregates['pageViews']['change'], 1e-9 );

        // Nothing to divide by: no change rather than infinity.
        $this->assertNull( $rs->aggregates['visits']['change'] );

        $this->assertSame( [ 'compare' => 'previous_period_dow', 'startDate' => '20260915', 'endDate' => '20261012' ],
            array_intersect_key( $rs->compare, array_flip( [ 'compare', 'startDate', 'endDate' ] ) ) );
        $this->assertSame( 100, $rs->compare['aggregates']['pageViews']['value'] );
    }

    public function testRowsAreMatchedOnTheirDimensionValues(): void
    {
        $row = fn ( $path, $views ) => [ 'pagePath' => self::cell( 'pagePath', $path, 'dimension' ), 'pageViews' => self::cell( 'pageViews', $views ) ];

        $rs  = self::rs( [], [ $row( '/a', 10 ), $row( '/b', 4 ), $row( '/new', 3 ) ] );
        $crs = self::rs( [], [ $row( '/b', 8 ), $row( '/a', 5 ), $row( '/gone', 9 ) ] );

        CompareMerger::merge( $rs, $crs, [ 'pagePath' ], [ '20261013', '20261109' ], [ '20260915', '20261012' ], 'previous_period' );

        $by = array_column( array_map( fn ( $r ) => [ $r['pagePath']['value'], $r['pageViews'] ], $rs->resultsRows ), 1, 0 );

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
        $row = fn ( $date, $views ) => [ 'date' => self::cell( 'date', $date, 'dimension' ), 'pageViews' => self::cell( 'pageViews', $views ) ];

        // Nov 2-4 against the week before, Oct 26-28.
        $rs  = self::rs( [], [ $row( '20261102', 10 ), $row( '20261103', 20 ), $row( '20261104', 30 ) ] );
        $crs = self::rs( [], [ $row( '20261026', 1 ), $row( '20261027', 2 ), $row( '20261028', 3 ) ] );

        CompareMerger::merge( $rs, $crs, [ 'date' ], [ '20261102', '20261104' ], [ '20261026', '20261028' ], 'previous_period_dow' );

        $this->assertSame( [ 1, 2, 3 ], array_map( fn ( $r ) => $r['pageViews']['compare_value'], $rs->resultsRows ) );
    }

    /**
     * A trend also carries the comparison's own rows on the period's dates,
     * so a day the period has no row for keeps its comparison value.
     */
    public function testATrendCarriesTheComparisonsRowsOnThePeriodsDates(): void
    {
        $row = fn ( $date, $views ) => [ 'date' => self::cell( 'date', $date, 'dimension' ), 'pageViews' => self::cell( 'pageViews', $views ) ];

        // The period has nothing on Nov 3; the week before had 7.
        $rs  = self::rs( [], [ $row( '20261102', 10 ), $row( '20261104', 30 ) ] );
        $crs = self::rs( [], [ $row( '20261026', 1 ), $row( '20261027', 7 ), $row( '20261028', 3 ) ] );

        CompareMerger::merge( $rs, $crs, [ 'date' ], [ '20261102', '20261104' ], [ '20261026', '20261028' ], 'previous_period_dow' );

        $this->assertSame(
            [ [ '20261102', '20261026', 1 ], [ '20261103', '20261027', 7 ], [ '20261104', '20261028', 3 ] ],
            array_map( fn ( $r ) => [ $r['date']['value'], $r['date']['compare_period_value'], $r['pageViews']['value'] ], $rs->compare['rows'] ) );
    }

    /** A trend broken out by a dimension: a line per value, each with its comparison. */
    public function testABrokenOutTrendCarriesTheComparisonsRowsToo(): void
    {
        $row = fn ( $date, $browser, $v ) => [ 'date' => self::cell( 'date', $date, 'dimension' ),
            'browserType' => self::cell( 'browserType', $browser, 'dimension' ), 'visits' => self::cell( 'visits', $v ) ];

        $rs  = self::rs( [], [ $row( '20261102', 'Chrome', 10 ) ] );
        $crs = self::rs( [], [ $row( '20261026', 'Chrome', 4 ), $row( '20261027', 'Safari', 2 ) ] );

        CompareMerger::merge( $rs, $crs, [ 'date', 'browserType' ], [ '20261102', '20261104' ], [ '20261026', '20261028' ], 'previous_period_dow' );

        $this->assertSame(
            [ [ '20261102', 'Chrome', 4 ], [ '20261103', 'Safari', 2 ] ],
            array_map( fn ( $r ) => [ $r['date']['value'], $r['browserType']['value'], $r['visits']['value'] ], $rs->compare['rows'] ) );
    }

    public function testAGridCarriesNoComparisonRows(): void
    {
        $row = fn ( $path, $v ) => [ 'pagePath' => self::cell( 'pagePath', $path, 'dimension' ), 'pageViews' => self::cell( 'pageViews', $v ) ];

        $rs = self::rs( [], [ $row( '/a', 1 ) ] );
        CompareMerger::merge( $rs, self::rs( [], [ $row( '/a', 2 ) ] ), [ 'pagePath' ],
            [ '20261102', '20261104' ], [ '20261026', '20261028' ], 'previous_period' );

        $this->assertArrayNotHasKey( 'rows', $rs->compare );
    }

    public function testATimeAxisWithACategoryIsMatchedOnBoth(): void
    {
        $row = fn ( $hour, $medium, $v ) => [ 'dateHour' => self::cell( 'dateHour', $hour, 'dimension' ),
            'medium' => self::cell( 'medium', $medium, 'dimension' ), 'visits' => self::cell( 'visits', $v ) ];

        $rs  = self::rs( [], [ $row( '2026110914', 'organic', 6 ), $row( '2026110914', 'referral', 2 ) ] );
        $crs = self::rs( [], [ $row( '2026110814', 'referral', 1 ), $row( '2026110814', 'organic', 3 ) ] );

        CompareMerger::merge( $rs, $crs, [ 'dateHour', 'medium' ], [ '20261109', '20261109' ], [ '20261108', '20261108' ], 'previous_period' );

        $this->assertSame( [ 3, 1 ], array_map( fn ( $r ) => $r['visits']['compare_value'], $rs->resultsRows ) );
    }

    public static function shifts(): array
    {
        return [
            'a day, a week back'      => [ 'day', '20261026', '20261026', '20261102', '20261102' ],
            'a day across a month'    => [ 'day', '20260930', '20260915', '20261013', '20261028' ],
            'an hour keeps its hour'  => [ 'hour', '2026110814', '20261108', '20261109', '2026110914' ],
            'a month, a year on'      => [ 'month', '202510', '20251001', '20261001', '202610' ],
            'a month across a year'   => [ 'month', '202512', '20251201', '20260101', '202601' ],
            'a year'                  => [ 'year', '2025', '20250101', '20260101', '2026' ],
        ];
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
        $this->assertSame( [ 'medium', 'dayOfWeek' ],
            CompareMerger::restrictable( [ 'date', 'medium', 'dateHour', 'dayOfWeek', 'yearMonth', 'year' ] ) );
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
        $db->where( 'referer', [ 'a', "b'); DROP TABLE x; --" ], 'in' );

        $sql = $db->generateSelectQuerySql();

        $this->assertStringContainsString( 'IN (?, ?)', $sql );
        $this->assertStringNotContainsString( 'DROP TABLE', $sql, 'the values are bound, not written into the SQL' );
    }

    public function testInIsNotPartOfTheRestConstraintSyntax(): void
    {
        $this->assertArrayNotHasKey( 'in', \OWA\Module\Base\Classes\ResultSetManager::CONSTRAINT_OPERATORS );
        $this->assertContains( 'in', \OWA\Core\Db::ALLOWED_OPERATORS );
    }

    /**
     * A row only the comparison has: every metric is 0 and changed by -100%,
     * a distinct count and a ratio too. Dimensions stay.
     */
    public function testARowOnlyTheComparisonHasIsZero(): void
    {
        $row = [
            'pagePath'   => self::cell( 'pagePath', '/gone', 'dimension' ),
            'pageViews'  => self::cell( 'pageViews', 12 ) + [ 'data_type' => 'integer' ],
            'bounceRate' => self::cell( 'bounceRate', 0.25 ) + [ 'data_type' => 'percentage' ],
            'revenue'    => self::cell( 'revenue', 0 ) + [ 'data_type' => 'currency' ],
            'totalUsers' => self::cell( 'totalUsers', 4 ) + [ 'data_type' => 'integer' ],
        ];

        $out = CompareMerger::compareOnlyRow( $row, fn ( $type, $value ) => $type . ':' . $value );

        $this->assertSame( $row['pagePath'], $out['pagePath'] );

        $this->assertSame( [ 0, 'integer:0', 12, '12' ], [ $out['pageViews']['value'], $out['pageViews']['formatted_value'],
            $out['pageViews']['compare_value'], $out['pageViews']['compare_formatted_value'] ] );
        $this->assertEqualsWithDelta( -1.0, $out['pageViews']['change'], 1e-9 );

        $this->assertSame( [ 0, 'percentage:0', 0.25 ], [ $out['bounceRate']['value'], $out['bounceRate']['formatted_value'],
            $out['bounceRate']['compare_value'] ] );
        $this->assertEqualsWithDelta( -1.0, $out['bounceRate']['change'], 1e-9 );

        $this->assertSame( [ 0, 'integer:0', 4 ], [ $out['totalUsers']['value'], $out['totalUsers']['formatted_value'],
            $out['totalUsers']['compare_value'] ] );
        $this->assertEqualsWithDelta( -1.0, $out['totalUsers']['change'], 1e-9 );

        // Zero against zero: no change.
        $this->assertSame( [ 0, null ], [ $out['revenue']['value'], $out['revenue']['change'] ] );
    }

    /** An unset value and '' are two groups in SQL, and are matched as two. */
    public function testAnUnsetValueIsNotMatchedWithAnEmptyOne(): void
    {
        $row = fn ( $path, $views ) => [ 'pagePath' => self::cell( 'pagePath', $path, 'dimension' ), 'pageViews' => self::cell( 'pageViews', $views ) ];

        $rs  = self::rs( [], [ $row( null, 3 ), $row( '', 4 ) ] );
        $crs = self::rs( [], [ $row( '', 8 ), $row( null, 1 ) ] );

        CompareMerger::merge( $rs, $crs, [ 'pagePath' ], [ '20261013', '20261109' ], [ '20260915', '20261012' ], 'previous_period' );

        $this->assertSame( [ 1, 8 ], [ $rs->resultsRows[0]['pageViews']['compare_value'], $rs->resultsRows[1]['pageViews']['compare_value'] ] );
    }

    /** A row's key in PHP is the key the SQL computes: each value marked, an unset one as 'n'. */
    public function testARowsGroupKey(): void
    {
        $row = [ 'pagePath' => self::cell( 'pagePath', null, 'dimension' ), 'eventName' => self::cell( 'eventName', 'click', 'dimension' ),
            'n' => self::cell( 'n', 5, 'dimension' ) ];

        $this->assertSame( "n\x1fvclick\x1fv5",
            \OWA\Module\Base\Classes\ResultSetManager::groupKeyOf( $row, [ 'pagePath', 'eventName', 'n' ] ) );
        $this->assertSame( 'v', \OWA\Module\Base\Classes\ResultSetManager::groupKeyOf(
            [ 'pagePath' => self::cell( 'pagePath', '', 'dimension' ) ], [ 'pagePath' ] ) );
    }
}
