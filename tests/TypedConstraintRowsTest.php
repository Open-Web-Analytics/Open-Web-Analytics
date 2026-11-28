<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Strings;
use OWA\Module\Base\Classes\Cube\Cubes;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/PropertyFixtures.php';

/**
 * Which rows a typed constraint selects, over a real cube (TypedConstraintTest
 * pins what each constraint becomes).
 *
 * Three page views, one per session, built so the old tests selected the
 * wrong rows: a first session (prior sessions 0) beside one whose count never
 * arrived (NULL), an acquisition that never resolved, and session starts on
 * the days either side of 1 November.
 */
final class TypedConstraintRowsTest extends TestCase
{
    const SITE     = 'owa-typed-constraint-site';
    const PROPERTY = 7777000000000078;

    /** Each row: prior sessions, first source, new vs returning, session start (local). */
    private static function rows(): array
    {
        return [
            [ 0, "\x1A", 'New', '2026-10-31 12:00:00' ],
            [ null, 'google', "\x1A", '2026-11-01 12:00:00' ],
            [ 2, 'bing', 'Returning', '2026-11-02 12:00:00' ],
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
            'name'          => 'Typed constraint fixture',
            'domain'        => owa_test_property_domain(self::PROPERTY),
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
            'name'        => 'Typed constraint fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        foreach (self::rows() as $i => [ $prior, $source, $nvr, $start ]) {

            $ts = (new \DateTimeImmutable($start, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();

            $event = Cubes::entityFor(self::PROPERTY);

            $event->setProperties([
                'id'               => 930000 + $i,
                'event_type'       => 'page_view',
                'site_id'          => self::SITE,
                'visitor_id'       => 7777200000000078 + $i,
                'session_id'       => 8887200000000078 + $i,
                'ts'               => $ts * 1000000,
                'yyyymmdd'         => (int) date('Ymd', $ts),
                'page_path'        => '/p' . $i,
                'prior_sessions'   => $prior,
                'acq_source'       => $source,
                'new_vs_returning' => $nvr,
                'session_start_ts' => $ts,
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

        owa_test_remove_property(self::PROPERTY);

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

    protected function tearDown(): void
    {
        Strings::useTranslations(null);
        Strings::useLocale(null);
    }

    /** The page paths a constraint selects, sorted. */
    private function paths(string $constraints): array
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray('pageViews');
        $rsm->setDimensions($rsm->dimensionsStringToArray('pagePath'));
        $rsm->setTimePeriod('date_range', '20261001', '20261130');
        $rsm->setSiteId(self::SITE);
        $rsm->setConstraints($rsm->parseConstraintsString($constraints));
        $rsm->setLimit(100);

        $rs = $rsm->getResults();

        $this->assertSame([], (array) $rs->errors, $constraints);

        $out = [];

        foreach ($rs->getResultsRows() as $row) {
            $out[] = (string) $row['pagePath']['value'];
        }

        sort($out);

        return $out;
    }

    public function testANumbersNotSetIsTheNullRowAndNotTheZero(): void
    {
        $this->assertSame(['/p1'], $this->paths('priorSessionCount==(not set)'));
        $this->assertSame(['/p0', '/p2'], $this->paths('priorSessionCount!=(not set)'));
    }

    public function testUnknownSelectsTheUnresolvedRows(): void
    {
        $this->assertSame(['/p0'], $this->paths('firstSource==(unknown)'));
        $this->assertSame(['/p1', '/p2'], $this->paths('firstSource!=(unknown)'));
        $this->assertSame(['/p1'], $this->paths('newVsReturning==(unknown)'));
    }

    public function testATranslatedWordSelectsItsStoredRows(): void
    {
        Strings::useLocale('fr_FR');
        Strings::useTranslations(['New' => 'Nouveau', 'Returning' => 'Connu']);

        $this->assertSame(['/p0'], $this->paths('newVsReturning==Nouveau'));
        $this->assertSame(['/p2'], $this->paths('newVsReturning==Connu'));
        $this->assertSame(['/p0'], $this->paths('newVsReturning==New'));
    }

    public function testATypedDateSelectsSessionsByDay(): void
    {
        $this->assertSame(['/p0'], $this->paths('sessionStartTime<20261101'));
        $this->assertSame(['/p1'], $this->paths('sessionStartTime==20261101'));
        $this->assertSame(['/p2'], $this->paths('sessionStartTime>20261101'));
        $this->assertSame(['/p0', '/p2'], $this->paths('sessionStartTime!=20261101'));
    }

    /** And the stored words are shown in the locale. */
    public function testTheStoredWordsAreShownTranslated(): void
    {
        Strings::useLocale('fr_FR');
        Strings::useTranslations(['New' => 'Nouveau', 'Returning' => 'Connu']);

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray('pageViews');
        $rsm->setDimensions($rsm->dimensionsStringToArray('newVsReturning'));
        $rsm->setTimePeriod('date_range', '20261001', '20261130');
        $rsm->setSiteId(self::SITE);
        $rsm->setLimit(100);

        $shown = [];

        foreach ($rsm->getResults()->getResultsRows() as $row) {
            $shown[] = (string) $row['newVsReturning']['formatted_value'];
        }

        sort($shown);

        $this->assertSame(['(unknown)', 'Connu', 'Nouveau'], $shown);
    }
}
