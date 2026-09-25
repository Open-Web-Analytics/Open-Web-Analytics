<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\DimensionExpression;

/**
 * The date parts, and the timezone question underneath them.
 *
 * WHAT IS ACTUALLY AT RISK. 1.x stored six date parts as columns on every fact
 * row; v2 renders them from one. The interesting part is not the arithmetic,
 * it is WHICH COLUMN they are read from. The cube carries `ts` (epoch
 * microseconds, timezone-free) and `yyyymmdd` (the day, written by PHP in the
 * configured zone). A date function applied to `ts` answers in the DATABASE's
 * timezone -- seven hours away on the installation this was written on -- so a
 * part read from `ts` would disagree with the `date` dimension and, near
 * midnight, with the partition the row is stored in.
 *
 * So the day-level parts read yyyymmdd and the clock parts convert `ts` with
 * the configured zone NAME. The cases below are built to fail if either of
 * those changes: the fixture puts events either side of a daylight-saving
 * transition and at hours whose UTC and local values differ.
 */
final class DatePartDimensionTest extends TestCase
{
    const SITE     = 'owa-date-part-site';
    const PROPERTY = 7777000000000077;

    const VISITOR = 7777100000000077;
    const SESSION = 8887100000000077;

    /** The zone everything here is asserted in. */
    private static function zone(): string
    {
        return date_default_timezone_get();
    }

    /**
     * Instants chosen so that UTC and local disagree, including both 2026 US
     * daylight-saving transitions.
     *
     * A fixture at, say, 09:00 local in a zone that happened to be UTC+0 would
     * pass whichever column the parts were read from, which is the whole thing
     * being checked.
     */
    private static function instants(): array
    {
        return [
            gmmktime(9, 59, 0, 3, 8, 2026),   // just before spring-forward
            gmmktime(10, 1, 0, 3, 8, 2026),   // just after
            gmmktime(8, 59, 0, 11, 1, 2026),  // just before fall-back
            gmmktime(9, 1, 0, 11, 1, 2026),   // just after
            gmmktime(19, 0, 0, 7, 4, 2026),   // a summer evening, UTC
            gmmktime(19, 0, 0, 1, 4, 2026),   // a winter evening, UTC
            gmmktime(3, 30, 0, 6, 15, 2026),  // UTC morning, previous day locally
        ];
    }

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = owa_coreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Date part fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        $site = owa_coreAPI::entityFactory('base.site');
        $site->setProperties([
            'id'          => self::PROPERTY * 10,
            'site_id'     => self::SITE,
            'property_id' => self::PROPERTY,
            'name'        => 'Date part fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        foreach (self::instants() as $i => $instant) {

            $event = Cubes::entityFor(self::PROPERTY);

            $event->setProperties([
                'id'         => 920000 + $i,
                'event_type' => 'page_view',
                'site_id'    => self::SITE,
                'visitor_id' => self::VISITOR,
                'session_id' => self::SESSION,
                'ts'         => $instant * 1000000,
                // Written the way ingest writes it: PHP, configured zone.
                'yyyymmdd'   => (int) date('Ymd', $instant),
                'page_path'  => '/p' . $i,
            ]);

            if (!$event->create()) {
                throw new \RuntimeException(sprintf('seeding row %d failed: %s',
                    $i, owa_coreAPI::dbSingleton()->lastQueryError()));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (owa_test_db_available()) {
            self::dropFixture();
        }
    }

    private static function dropFixture(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            owa_coreAPI::entityFactory('base.site')->getTableName(), $db->prepare(self::SITE)));

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            owa_coreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this reads a real cube.');
        }
    }

    /** dimension value => pageViews, from a real query. */
    private function group(string $dimension, bool $sorted = false): array
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray('pageViews');
        $rsm->setDimensions($rsm->dimensionsStringToArray($dimension));
        $rsm->setTimePeriod('date_range', '20260101', '20261231');
        $rsm->setSiteId(self::SITE);
        $rsm->setLimit(100);

        if ($sorted) {
            $rsm->setSorts($rsm->sortStringToArray($dimension));
        }

        $rs = $rsm->getResults();

        $this->assertSame([], (array) $rs->errors, $dimension . ' did not resolve');

        $out = [];

        foreach ($rs->getResultsRows() as $row) {
            $out[(string) $row[$dimension]['value']] = (int) $row['pageViews']['value'];
        }

        return $out;
    }

    // ---- the expressions themselves --------------------------------------

    public function testTheDayLevelPartsReadYyyymmddAndNotTs(): void
    {
        foreach (['year', 'month', 'day', 'yearMonth',
                  'dayOfWeek', 'dayOfYear', 'weekOfYear'] as $part) {

            $sql = DimensionExpression::datePart('yyyymmdd', $part);

            $this->assertStringContainsString('yyyymmdd', $sql, $part);

            $this->assertStringNotContainsString('CONVERT_TZ', $sql,
                $part . ' converts a timezone, so it is reading a timestamp -- '
              . 'yyyymmdd already has the zone baked in and needs none.');

            $this->assertStringNotContainsString('FROM_UNIXTIME', $sql, $part);
        }
    }

    /**
     * The clock parts carry the zone as a PLACEHOLDER, filled at query time.
     *
     * Not baked in at registration, for two reasons that both bit: the emitted
     * SQL would carry the generating machine's zone, which made the catalog
     * recording unmatchable anywhere else; and an installation that changed
     * its timezone would keep querying with the old one until something
     * re-registered.
     */
    public function testTheClockPartsCarryTheZoneAsAPlaceholder(): void
    {
        foreach (['hour', 'minute', 'dateHour'] as $part) {

            $sql = DimensionExpression::datePart('ts', $part);

            $this->assertStringContainsString("'%2\$s'", $sql,
                $part . ' has no slot for the timezone');

            $this->assertStringNotContainsString(date_default_timezone_get(), $sql,
                $part . ' baked this machine\'s zone into the template');

            $filled = sprintf($sql, 'evt', 'America/Los_Angeles');

            $this->assertStringContainsString("'America/Los_Angeles'", $filled, $part);

            /*
             * A fixed offset is the tempting shortcut and it is wrong: one
             * computed in summer answers every winter timestamp an hour out.
             * The zone name makes the SERVER resolve it per row.
             */
            $this->assertStringNotContainsString('-07:00', $filled, $part);
            $this->assertStringNotContainsString('-08:00', $filled, $part);
        }
    }

    /** And the zone it is filled with is validated, not taken on trust. */
    public function testTheZoneIsAValidatedIanaName(): void
    {
        $zone = DimensionExpression::timezone();

        $this->assertSame(date_default_timezone_get(), $zone);
        $this->assertMatchesRegularExpression('#^[A-Za-z][A-Za-z0-9_+/-]*$#', $zone);

        // An empty default would quietly mean UTC, which is hours of silent
        // error rather than a failure anybody would notice.
        $this->assertNotSame('', $zone);
    }

    public function testAnUnknownPartIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DimensionExpression::datePart('yyyymmdd', 'fortnight');
    }

    /** The emitted SQL survives the seam's own sprintf, format strings included. */
    public function testEveryPartSurvivesTheAliasSubstitution(): void
    {
        $parts = array_merge(
            array_keys(DimensionExpression::PARTS), DimensionExpression::CLOCK_PARTS);

        foreach ($parts as $part) {

            $sql = in_array($part, DimensionExpression::CLOCK_PARTS, true)
                ? DimensionExpression::datePart('ts', $part)
                : DimensionExpression::datePart('yyyymmdd', $part);

            $substituted = sprintf($sql, 'evt', self::zone());

            $this->assertStringContainsString('evt.', $substituted, $part);
            $this->assertStringNotContainsString('%1$s', $substituted, $part);
            $this->assertStringNotContainsString('%%', $substituted,
                $part . ' left a doubled percent in the SQL');
        }
    }

    // ---- what they compute over real rows --------------------------------

    /**
     * THE CASE THAT MATTERS. Every fixture instant is asserted against PHP in
     * the configured zone, and the fixture is built so UTC would give different
     * answers.
     */
    public function testEveryPartAgreesWithPhpInTheConfiguredZone(): void
    {
        $expected = [
            'year' => [], 'month' => [], 'day' => [], 'yearMonth' => [],
            'dayOfWeek' => [], 'dayOfYear' => [], 'weekOfYear' => [],
            'hour' => [], 'minute' => [], 'dateHour' => [],
        ];

        foreach (self::instants() as $instant) {

            $expected['year'][]       = date('Y', $instant);
            $expected['month'][]      = date('n', $instant);
            $expected['day'][]        = date('j', $instant);
            $expected['yearMonth'][]  = date('Ym', $instant);
            $expected['dayOfWeek'][]  = (string) (date('w', $instant) + 1);
            $expected['dayOfYear'][]  = (string) (date('z', $instant) + 1);
            $expected['weekOfYear'][] = (string) (int) date('W', $instant);
            $expected['hour'][]       = date('G', $instant);
            $expected['minute'][]     = (string) (int) date('i', $instant);
            $expected['dateHour'][]   = date('YmdH', $instant);
        }

        foreach ($expected as $dimension => $values) {

            $want = array_count_values($values);
            $got  = $this->group($dimension);

            // Compared by key, not by position: a GROUP BY makes no promise
            // about the order rows come back in, and this case is about the
            // values. Ordering has a case of its own.
            ksort($want);
            ksort($got);

            $this->assertSame($want, $got,
                $dimension . ' disagrees with PHP in ' . self::zone());
        }
    }

    /**
     * And it would NOT pass if the parts were read in UTC.
     *
     * Without this the case above proves only self-consistency: a fixture whose
     * local and UTC values happened to match would satisfy it either way.
     */
    public function testTheFixtureWouldFailIfTheZoneWereIgnored(): void
    {
        $differ = 0;

        foreach (self::instants() as $instant) {

            if (date('G', $instant) !== gmdate('G', $instant)) {
                $differ++;
            }
        }

        $this->assertGreaterThanOrEqual(6, $differ,
            'the fixture must contain instants whose local hour differs from UTC, '
          . 'or the agreement test above cannot tell the two bases apart');
    }

    /** Daylight saving is resolved per row, not once for the query. */
    public function testBothSidesOfADaylightSavingTransitionAreCorrect(): void
    {
        $hours = $this->group('hour');

        foreach ([gmmktime(9, 59, 0, 3, 8, 2026), gmmktime(10, 1, 0, 3, 8, 2026),
                  gmmktime(8, 59, 0, 11, 1, 2026), gmmktime(9, 1, 0, 11, 1, 2026)] as $instant) {

            $this->assertArrayHasKey(date('G', $instant), $hours,
                'no row at local hour ' . date('G', $instant)
              . ', so the offset was applied from the wrong side of the transition');
        }
    }

    /** Grouping, not filtering: the total is the fixture's row count. */
    public function testNoPartChangesTheTotal(): void
    {
        $expected = count(self::instants());

        foreach (['date', 'year', 'month', 'yearMonth', 'day', 'dayOfWeek',
                  'dayOfYear', 'weekOfYear', 'hour', 'minute', 'dateHour'] as $dimension) {

            $this->assertSame($expected, array_sum($this->group($dimension)),
                $dimension . ' lost or duplicated rows');
        }
    }

    /**
     * A date part can be ORDERED BY, which is most of what one is for -- an
     * hour-of-day report is meaningless in group order.
     */
    public function testADatePartCanBeSortedOn(): void
    {
        foreach (['hour', 'dateHour', 'dayOfYear'] as $dimension) {

            $values = array_map('intval', array_keys($this->group($dimension, true)));

            $sorted = $values;
            sort($sorted, SORT_NUMERIC);

            $this->assertSame($sorted, $values, $dimension . ' came back unordered');
            $this->assertGreaterThan(2, count($values), $dimension . ': too few groups to prove order');
        }
    }

    /** The day-level parts agree with the `date` dimension they are cut from. */
    public function testThePartsAgreeWithTheDateDimension(): void
    {
        // Cast because PHP turns a numeric array key into an int, so the keys
        // coming back from group() are ints while substr() yields strings.
        $years  = array_map('intval', array_keys($this->group('year')));
        $months = array_map('intval', array_keys($this->group('month')));

        foreach (array_keys($this->group('date')) as $yyyymmdd) {

            $this->assertContains((int) substr((string) $yyyymmdd, 0, 4), $years);
            $this->assertContains((int) substr((string) $yyyymmdd, 4, 2), $months);
        }
    }
}
