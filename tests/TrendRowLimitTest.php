<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;

/**
 * A query by time that names no page size gets one row per day of the period,
 * or one per hour by dateHour.
 *
 * A trend card asks for no page size, so this is the limit every trend runs
 * under. It was the day DIFFERENCE: a week of days returned seven rows of
 * eight and dropped the last, which sorted by date was today; and a single day
 * by hour, a difference of zero, fell through to the result set's ten rows and
 * stopped at the tenth hour that had traffic.
 *
 * Every query here sets no limit, and every fixture has more rows than the old
 * limit allowed.
 */
final class TrendRowLimitTest extends TestCase
{
    const SITE     = 'owa-trend-limit-site';
    const PROPERTY = 7777000000000078;

    const VISITOR = 7777100000000078;
    const SESSION = 8887100000000078;

    /** One page view per day, 20260601 to 20260608: eight days. */
    private static function days(): array
    {
        $out = [];

        for ($d = 1; $d <= 8; $d++) {
            $out[] = mktime(12, 0, 0, 6, $d, 2026);
        }

        return $out;
    }

    /** One page view in each of fourteen hours of 20260610. */
    private static function hours(): array
    {
        $out = [];

        for ($h = 6; $h < 20; $h++) {
            $out[] = mktime($h, 15, 0, 6, 10, 2026);
        }

        return $out;
    }

    /** 20260307 to 20260309, across the spring-forward: three days, whose midnights are 47 hours apart. */
    private static function springForward(): array
    {
        return [
            mktime(12, 0, 0, 3, 7, 2026),
            mktime(12, 0, 0, 3, 8, 2026),
            mktime(12, 0, 0, 3, 9, 2026),
        ];
    }

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Trend row limit fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->setProperties([
            'id'          => self::PROPERTY * 10,
            'site_id'     => self::SITE,
            'property_id' => self::PROPERTY,
            'name'        => 'Trend row limit fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        $instants = array_merge(self::days(), self::hours(), self::springForward());

        foreach ($instants as $i => $instant) {

            $event = Cubes::entityFor(self::PROPERTY);

            $event->setProperties([
                'id'         => 930000 + $i,
                'event_type' => 'page_view',
                'site_id'    => self::SITE,
                'visitor_id' => self::VISITOR,
                'session_id' => self::SESSION,
                'ts'         => $instant * 1000000,
                'yyyymmdd'   => (int) date('Ymd', $instant),
                'page_path'  => '/p' . $i,
            ]);

            if (!$event->create()) {
                throw new \RuntimeException(sprintf('seeding row %d failed: %s',
                    $i, \OWA\Core\CoreAPI::dbSingleton()->lastQueryError()));
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
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            \OWA\Core\CoreAPI::entityFactory('base.site')->getTableName(), $db->prepare(self::SITE)));

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

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

    /** The x values a trend's query returns, in order, with no page size set. */
    private function trend(string $dimension, string $start, string $end): array
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray('pageViews');
        $rsm->setDimensions([$dimension]);
        $rsm->setTimePeriod('date_range', $start, $end);
        $rsm->setSiteId(self::SITE);
        $rsm->setSorts($rsm->sortStringToArray($dimension));

        $rs = $rsm->getResults();

        $this->assertSame([], (array) $rs->errors, $dimension . ' did not resolve');

        return array_map(fn($row) => (string) $row[$dimension]['value'], $rs->getResultsRows());
    }

    private static function format(array $instants, string $format): array
    {
        return array_map(fn($t) => date($format, $t), $instants);
    }

    public function testADailyTrendKeepsItsLastDay(): void
    {
        $this->assertSame(self::format(self::days(), 'Ymd'),
            $this->trend('date', '20260601', '20260608'));
    }

    public function testAnHourlyTrendOfOneDayHasEveryHour(): void
    {
        $this->assertSame(self::format(self::hours(), 'YmdH'),
            $this->trend('dateHour', '20260610', '20260610'));
    }

    public function testAnHourlyTrendOverSeveralDaysHasEveryHour(): void
    {
        $this->assertSame(
            array_merge(self::format(self::days(), 'YmdH'), self::format(self::hours(), 'YmdH')),
            $this->trend('dateHour', '20260601', '20260610'));
    }

    /** The day difference is 1.96 across the change, not 2. */
    public function testADailyTrendAcrossTheClockChangeKeepsEveryDay(): void
    {
        $this->assertSame(self::format(self::springForward(), 'Ymd'),
            $this->trend('date', '20260307', '20260309'));
    }
}
